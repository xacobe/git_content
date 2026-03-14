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

    // Ensure the vocabulary config entity exists before creating terms.
    $vocab_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if (!$vocab_storage->load($vid)) {
      $vocab_storage->create([
        'vid'  => $vid,
        'name' => ucwords(str_replace('_', ' ', $vid)),
      ])->save();
    }

    $existing = $short_uuid ? $this->findByShortUuid($short_uuid, 'taxonomy_term', $vid) : NULL;

    if ($existing) {
      if ($existing->hasTranslation($langcode)) {
        $term      = $existing->getTranslation($langcode);
        $operation = 'updated';
      }
      else {
        $term      = $existing->addTranslation($langcode);
        $operation = 'imported';
      }
    }
    else {
      $create_values = [
        'vid'              => $vid,
        'langcode'         => $langcode,
        'default_langcode' => 1,
        'uuid'             => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ];
      // Preserve the original tid so Views filters referencing terms by ID
      // keep working after a fresh import. Only applied if the slot is free.
      if (!empty($frontmatter['tid'])) {
        $requested_tid = (int) $frontmatter['tid'];
        if (!$this->entityTypeManager->getStorage('taxonomy_term')->load($requested_tid)) {
          $create_values['tid'] = $requested_tid;
        }
      }
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create($create_values);
      $operation = 'imported';
    }

    // 'name' is required; fall back to slug if empty.
    $name = !empty($frontmatter['name'])
      ? $frontmatter['name']
      : ucfirst(str_replace('-', ' ', $frontmatter['slug'] ?? 'term'));
    $term->set('name', $name);
    $term->set('status', ($frontmatter['status'] ?? 'published') === 'published' ? 1 : 0);

    if (isset($frontmatter['weight'])) {
      $term->set('weight', (int) $frontmatter['weight']);
    }

    if (!empty($frontmatter['parent'])) {
      $parent_val = $frontmatter['parent'];
      if (is_numeric($parent_val)) {
        // Legacy format: direct tid stored in old .md files.
        $term->set('parent', [(int) $parent_val]);
      }
      else {
        // New format: short UUID — resolve to current tid.
        $parent = $this->findByShortUuidGlobal((string) $parent_val, 'taxonomy_term');
        if ($parent) {
          $term->set('parent', [(int) $parent->id()]);
        }
      }
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
