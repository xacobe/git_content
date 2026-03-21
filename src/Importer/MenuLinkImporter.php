<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates menu_link_content entities from Markdown frontmatter.
 *
 * Links are imported respecting the hierarchy: parents before children.
 * MarkdownImporter already sorts files by weight; hierarchy is resolved here
 * using a uuid → real plugin_id map that is populated during import.
 */
class MenuLinkImporter extends BaseImporter {

  /**
   * UUID → real plugin_id map, populated as links are saved.
   */
  protected array $menuLinkUuidMap = [];

  public function import(array $frontmatter, string $body): string {
    $langcode   = $frontmatter['lang'] ?? 'und';
    $uuid = $frontmatter['uuid'] ?? NULL;
    $menu_name  = $frontmatter['menu'] ?? 'main';

    [$link, $operation] = $this->resolveOrCreate('menu_link_content', $uuid, $langcode, [
      'langcode'  => $langcode,
      'menu_name' => $menu_name,
    ], FALSE, $menu_name);

    $link->set('title', $frontmatter['title'] ?? '');
    $link->set('weight', (int) ($frontmatter['weight'] ?? 0));
    $link->set('expanded', (bool) ($frontmatter['expanded'] ?? FALSE));
    $link->set('enabled', (bool) ($frontmatter['enabled'] ?? TRUE));

    // Resolve the parent: the frontmatter stores the UUID of the parent link.
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
    $this->populateDynamicFields($link, $frontmatter, $definitions, [
      'link', 'menu_name', 'bundle', 'enabled', 'title', 'weight', 'expanded',
      'external', 'rediscover',
      'content_translation_uid', 'content_translation_status', 'content_translation_created',
      'metatag',
    ]);

    // Set link AFTER populateDynamicFields so link_options are never
    // overwritten by the dynamic field loop processing the 'link' field.
    $url = $frontmatter['url'] ?? '/';
    $uri = str_starts_with($url, '/') ? 'internal:' . $url : $url;
    $link_value = ['uri' => $uri];
    if (!empty($frontmatter['link_options'])) {
      $link_value['options'] = $frontmatter['link_options'];
    }
    $link->set('link', $link_value);

    $link->save();

    // Register the real plugin_id so children can resolve it later.
    if ($uuid) {
      $this->menuLinkUuidMap[$uuid] = 'menu_link_content:' . $link->uuid();
    }

    return $operation;
  }

  /**
   * Resolve the parent plugin_id from its UUID.
   * If the parent is a plugin from another module, return it as-is.
   */
  protected function resolveMenuLinkParent(string $parent_ref, string $menu_name): ?string {
    // Already in the map (imported in this session).
    if (isset($this->menuLinkUuidMap[$parent_ref])) {
      return $this->menuLinkUuidMap[$parent_ref];
    }

    // External plugin (not menu_link_content): return as-is.
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $parent_ref)) {
      return $parent_ref;
    }

    // Look up in the database by UUID.
    $existing = $this->findByUuid($parent_ref, 'menu_link_content', $menu_name);
    if ($existing) {
      return 'menu_link_content:' . $existing->uuid();
    }

    return NULL;
  }

}
