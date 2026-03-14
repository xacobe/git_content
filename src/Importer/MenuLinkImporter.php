<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates menu_link_content entities from Markdown frontmatter.
 *
 * Links are imported respecting the hierarchy: parents before children.
 * MarkdownImporter already sorts files by weight; hierarchy is resolved here
 * using a short-uuid → real plugin_id map that is populated during import.
 */
class MenuLinkImporter extends BaseImporter {

  /**
   * Short-uuid → real plugin_id map, populated as links are saved.
   */
  protected array $menuLinkUuidMap = [];

  public function import(array $frontmatter, string $body): string {
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;
    $menu_name  = $frontmatter['menu'] ?? 'main';

    $existing = $short_uuid
      ? $this->findByShortUuid($short_uuid, 'menu_link_content', $menu_name)
      : NULL;

    if ($existing) {
      if ($existing->hasTranslation($langcode)) {
        $link      = $existing->getTranslation($langcode);
        $operation = 'updated';
      }
      else {
        $link      = $existing->addTranslation($langcode);
        $operation = 'imported';
      }
    }
    else {
      $link = $this->entityTypeManager->getStorage('menu_link_content')->create([
        'langcode'  => $langcode,
        'menu_name' => $menu_name,
        'uuid'      => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $link->set('title', $frontmatter['title'] ?? '');
    $link->set('weight', (int) ($frontmatter['weight'] ?? 0));
    $link->set('expanded', (bool) ($frontmatter['expanded'] ?? FALSE));
    $link->set('enabled', (bool) ($frontmatter['enabled'] ?? TRUE));

    // Resolve the parent: the frontmatter stores the short UUID of the parent.
    // We resolve it against the map built during this import run.
    $parent_ref = $frontmatter['parent'] ?? NULL;
    if ($parent_ref) {
      $parent_plugin_id = $this->resolveMenuLinkParent($parent_ref, $menu_name);
      if ($parent_plugin_id) {
        $link->set('parent', $parent_plugin_id);
      }
    }

    if (!empty($body) && $link->hasField('description')) {
      $link->set('description', trim($body));
    }

    // Populate any extra custom fields (field_* added via UI).
    // The 'link' field is excluded here and set explicitly below with options.
    $definitions = $this->fieldDiscovery->getFields('menu_link_content', $menu_name);
    $this->populateMenuFields($link, $frontmatter, $definitions);

    // Set link AFTER populateDynamicFields so link_options are never
    // overwritten by the dynamic field loop processing the 'link' field.
    $link_value = ['uri' => $frontmatter['url'] ?? 'internal:/'];
    if (!empty($frontmatter['link_options'])) {
      $link_value['options'] = $frontmatter['link_options'];
    }
    $link->set('link', $link_value);

    $link->save();

    // Register the real plugin_id so children can resolve it later.
    if ($short_uuid) {
      $this->menuLinkUuidMap[$short_uuid] = 'menu_link_content:' . $link->uuid();
    }

    return $operation;
  }

  /**
   * Like populateDynamicFields() but skips fields handled explicitly above.
   */
  protected function populateMenuFields($entity, array $frontmatter, array $definitions): void {
    // These fields are either handled explicitly or are internal/computed.
    $extra_skip = [
      'link', 'menu_name', 'bundle', 'enabled', 'title', 'weight', 'expanded',
      'external', 'rediscover',
      'content_translation_uid', 'content_translation_status', 'content_translation_created',
      'metatag',
    ];
    // Temporarily inject the extra skip list by pre-removing those definitions.
    $filtered = array_diff_key($definitions, array_flip($extra_skip));
    $this->populateDynamicFields($entity, $frontmatter, $filtered);
  }

  /**
   * Resolve the parent plugin_id from its short UUID.
   * If the parent is a plugin from another module, return it as-is.
   */
  protected function resolveMenuLinkParent(string $parent_ref, string $menu_name): ?string {
    // Already in the map (imported in this session).
    if (isset($this->menuLinkUuidMap[$parent_ref])) {
      return $this->menuLinkUuidMap[$parent_ref];
    }

    // External plugin (not menu_link_content): return as-is.
    if (!preg_match('/^[a-f0-9]{8}$/', $parent_ref)) {
      return $parent_ref;
    }

    // Look up in the database by short UUID.
    $existing = $this->findByShortUuid($parent_ref, 'menu_link_content', $menu_name);
    if ($existing) {
      return 'menu_link_content:' . $existing->uuid();
    }

    return NULL;
  }

}
