<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates taxonomy term entities from Markdown frontmatter.
 */
class TaxonomyImporter extends BaseImporter {

  public function import(array $frontmatter, string $body): string {
    $vid        = $frontmatter['vocabulary'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$vid) {
      throw new \Exception(t("The term frontmatter is missing 'vocabulary'."));
    }

    $existing = $short_uuid ? $this->findByShortUuid($short_uuid, 'taxonomy_term', $vid) : NULL;

    if ($existing) {
      $term = $existing->hasTranslation($langcode)
        ? $existing->getTranslation($langcode)
        : $existing->addTranslation($langcode);
      $operation = 'updated';
    }
    else {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid'              => $vid,
        'langcode'         => $langcode,
        'default_langcode' => 1,
        'uuid'             => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    // 'name' is required; fall back to slug if empty.
    $name = !empty($frontmatter['name'])
      ? $frontmatter['name']
      : ucfirst(str_replace('-', ' ', $frontmatter['slug'] ?? 'term'));
    $term->set('name', $name);
    $term->set('status', ($frontmatter['status'] ?? 'published') === 'published' ? 1 : 0);
    // default_langcode must be set explicitly; Drupal does not initialise it
    // for taxonomy_term.
    $term->set('default_langcode', 1);

    if (isset($frontmatter['weight'])) {
      $term->set('weight', (int) $frontmatter['weight']);
    }

    if (!empty($frontmatter['parent'])) {
      $term->set('parent', [(int) $frontmatter['parent']]);
    }

    if ($term->hasField('description') && !empty($body)) {
      $term->set('description', [
        'value'  => $this->serializer->markdownToHtml($body),
        'format' => 'basic_html',
      ]);
    }

    $definitions = $this->fieldDiscovery->getFields('taxonomy_term', $vid);
    $this->populateDynamicFields($term, $frontmatter, $definitions);

    $term->save();

    return $operation;
  }

}
