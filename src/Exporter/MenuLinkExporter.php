<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Export custom menu links (menu_link_content) to Markdown.
 *
 * Menu configuration (name, description) is handled by `drush cex`.
 * This exporter is responsible for the menu link entities created by editors,
 * which are translatable. UUID is preserved because Drupal's menu system uses
 * 'menu_link_content:{uuid}' as plugin IDs for parent hierarchy.
 *
 * Output structure:
 *   content_export/
 *     menus/
 *       {menu-id}/
 *         {slug}[__{parent-slug}...].{lang}.md
 *
 * Order and hierarchy are reconstructed from frontmatter fields (`weight`, `parent`).
 *
 * Example frontmatter produced:
 *   ---
 *   uuid: a1b2c3d4
 *   type: menu_link_content
 *   menu: main
 *   lang: es
 *   enabled: true
 *
 *   title: Inicio
 *   url: internal:/
 *   weight: 0
 *   expanded: false
 *
 *   parent: null
 *   translation_of: null
 *   ---
 */
class MenuLinkExporter extends BaseExporter {

  public function getEntityType(): string {
    return 'menu_link_content';
  }

  public function getCliName(): string {
    return 'menus';
  }

  protected function typeDir(): string {
    return 'menus';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $markdown = $this->export($entity);

    $menu_id  = $entity->getMenuName();
    $langcode = $entity->language()->getId();
    $dir = $this->contentExportDir() . '/menus/' . $menu_id;
    $this->ensureDir($dir, $dryRun);

    $filename = $this->buildFilename($this->getLinkPath($entity), $langcode);
    $filepath = $dir . '/' . $filename;

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * Build the file name for a menu link including its parent chain separated by
   * double underscores (__).
   */
  private function getLinkPath(EntityInterface $entity): string {
    $slug = $this->getLinkSlug($entity);
    $parent_id = $entity->getParentId();

    // If there is no parent, just return the slug.
    if (empty($parent_id) || !str_starts_with($parent_id, 'menu_link_content:')) {
      return $slug;
    }

    // Extract the UUID from the plugin ID.
    $parent_uuid = substr($parent_id, strlen('menu_link_content:'));

    // Try to load the parent entity by UUID.
    $parent = $this->loadMenuLinkByUuid($parent_uuid);
    if (!$parent) {
      return $slug;
    }

    $parent_path = $this->getLinkPath($parent);
    return $parent_path . '__' . $slug;
  }

  /**
   * Load a menu_link_content by its UUID.
   */
  private function loadMenuLinkByUuid(string $uuid): ?\Drupal\Core\Entity\EntityInterface {
    $links = $this->entityTypeManager
      ->getStorage('menu_link_content')
      ->loadByProperties(['uuid' => $uuid]);
    return !empty($links) ? reset($links) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();

    $frontmatter = [];
    $frontmatter['uuid']    = $entity->uuid();
    $frontmatter['type']    = 'menu_link_content';
    $frontmatter['menu']    = $entity->getMenuName();
    $frontmatter['lang']    = $langcode;
    $frontmatter['enabled'] = (bool) $entity->isEnabled();

    $frontmatter['title']    = $entity->getTitle();
    $frontmatter['url']      = $this->getPortableUri($entity);
    $frontmatter['weight']   = (int) $entity->getWeight();
    $frontmatter['expanded'] = (bool) $entity->isExpanded();

    // Preserve link options (attributes, target, data-* etc. from modules like
    // menu_link_attributes that store extra data in link.options).
    $link_options = $entity->get('link')->options ?? [];
    if (!empty($link_options)) {
      $frontmatter['link_options'] = $link_options;
    }

    // Extra custom fields added to menu_link_content (e.g. field_* via UI).
    // Excludes fields already handled explicitly above and internal/computed
    // fields that are noise (menu_name, bundle, link, external, rediscover,
    // content_translation_*, etc.).
    $skip_extra = [
      'link', 'bundle', 'menu_name', 'enabled', 'title', 'weight', 'expanded',
      'external', 'rediscover',
      'content_translation_uid', 'content_translation_status', 'content_translation_created',
    ];
    $groups = $this->buildDynamicGroups($entity, 'menu_link_content');
    foreach ($groups['extra'] as $key => $val) {
      if (!in_array($key, $skip_extra)) {
        $frontmatter[$key] = $val;
      }
    }

    // Reference to the parent link (UUID of the parent menu_link_content)
    $frontmatter['parent']  = $this->getParentUuid($entity);
    $frontmatter['link_id'] = (int) $entity->id();

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Description as body if it exists
    $body = '';
    if ($entity->hasField('description') && !$entity->get('description')->isEmpty()) {
      $body = $entity->get('description')->value ?? '';
    }

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Return the UUID of the parent link, or null for root links.
   *
   * The 'parent' field of menu_link_content stores the full plugin ID as
   * "menu_link_content:{uuid}". We extract the UUID for portability.
   */
  private function getParentUuid(EntityInterface $entity): ?string {
    $parent_plugin_id = $entity->getParentId();

    if (empty($parent_plugin_id)) {
      return NULL;
    }

    // Format: "menu_link_content:{full-uuid}"
    if (str_starts_with($parent_plugin_id, 'menu_link_content:')) {
      return substr($parent_plugin_id, strlen('menu_link_content:'));
    }

    // If the parent is a link from another plugin (module route, etc.),
    // keep it as-is to preserve the reference.
    return $parent_plugin_id;
  }

  /**
   * Return a portable URI for the link.
   *
   * Converts environment-specific entity URIs (entity:node/{nid}) to
   * internal path aliases (internal:/{alias}) so the link survives import
   * into environments where entity IDs may differ.
   */
  private function getPortableUri(EntityInterface $entity): string {
    $uri = $entity->get('link')->uri ?? '';

    if (preg_match('/^entity:node\/(\d+)$/', $uri, $m)) {
      $node = $this->entityTypeManager->getStorage('node')->load((int) $m[1]);
      if ($node) {
        $alias = $node->hasField('path') ? ($node->get('path')->alias ?? NULL) : NULL;
        return $alias ?: '/node/' . $m[1];
      }
    }

    // Strip the 'internal:' scheme for SSG-friendly clean paths.
    if (str_starts_with($uri, 'internal:')) {
      return substr($uri, strlen('internal:'));
    }

    return $uri;
  }

  /**
   * Generate a readable slug from the link title.
   */
  private function getLinkSlug(EntityInterface $entity): string {
    return $this->slugify($entity->getTitle() ?? 'link-' . $entity->id());
  }

}
