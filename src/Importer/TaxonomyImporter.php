<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates taxonomy term entities from Markdown frontmatter.
 */
class TaxonomyImporter extends BaseImporter {

  public function getEntityType(): ?string {
    return 'taxonomy_term';
  }

  public function getImportWeight(): int {
    return 30;
  }

  public function extractEntityId(array $frontmatter): ?int {
    return !empty($frontmatter['tid']) ? (int) $frontmatter['tid'] : NULL;
  }

  public function resolveBundle(array $frontmatter): ?string {
    return $frontmatter['vocabulary'] ?? NULL;
  }

  public function getBundleQueryField(): ?string {
    return 'vid';
  }

  public function import(array $frontmatter, string $body): string {
    $vid        = $frontmatter['vocabulary'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $tid        = !empty($frontmatter['tid']) ? (int) $frontmatter['tid'] : NULL;

    if (!$vid) {
      throw new \Exception($this->t("The term frontmatter is missing 'vocabulary'."));
    }

    // Ensure the vocabulary config entity exists before creating terms.
    $vocab_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if (!$vocab_storage->load($vid)) {
      $vocab_storage->create([
        'vid'  => $vid,
        'name' => ucwords(str_replace('_', ' ', $vid)),
      ])->save();
    }

    $create_values = ['vid' => $vid, 'langcode' => $langcode, 'default_langcode' => 1];
    $this->preserveEntityId('taxonomy_term', 'tid', 'tid', $create_values, $frontmatter);

    [$term, $operation] = $this->resolveOrCreate('taxonomy_term', $tid, $langcode, $create_values);

    // 'name' is required; fall back to slug if empty.
    $name = !empty($frontmatter['name'])
      ? $frontmatter['name']
      : ucfirst(str_replace('-', ' ', $frontmatter['slug'] ?? 'term'));
    $term->set('name', $name);
    $term->set('status', $this->resolveStatus($frontmatter));

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
