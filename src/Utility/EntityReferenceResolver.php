<?php

namespace Drupal\git_content\Utility;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Resolves human-readable frontmatter references to Drupal entity IDs.
 *
 * Centralises the import-time entity lookups that convert the portable
 * identifiers stored in .md files (term labels, node slugs, file names,
 * media UUIDs) back into the integer IDs Drupal field values require.
 *
 * This is a separate service from FieldNormalizer because reference resolution
 * is import-specific business logic (and may write to the database, e.g.
 * creating missing taxonomy terms), while FieldNormalizer's job is format
 * conversion only.
 */
class EntityReferenceResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Dispatch a frontmatter reference value to the right resolver by target type.
   */
  public function resolveEntityReference(mixed $value, string $target_type, FieldDefinitionInterface $definition): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if ($target_type === 'taxonomy_term') {
      return $this->resolveOrCreateTermByLabel((string) $value, $definition);
    }
    if ($target_type === 'node') {
      return is_numeric($value) ? (int) $value : $this->findNodeBySlug((string) $value);
    }
    if ($target_type === 'media') {
      return is_numeric($value) ? (int) $value : NULL;
    }
    return is_numeric($value) ? (int) $value : NULL;
  }

  /**
   * Find a taxonomy term by label within the allowed vocabularies.
   *
   * Creates the term if it does not exist, so content can be imported
   * without requiring all terms to be pre-seeded.
   */
  public function resolveOrCreateTermByLabel(string $label, FieldDefinitionInterface $definition): ?int {
    $vocab_bundles = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $query = $storage->getQuery()->accessCheck(FALSE)->condition('name', $label);
    if (!empty($vocab_bundles)) {
      $query->condition('vid', array_keys($vocab_bundles), 'IN');
    }
    $tids = $query->execute();

    if (!empty($tids)) {
      return (int) reset($tids);
    }

    // Auto-create the term so imported content is never broken by a missing tag.
    if (!empty($vocab_bundles)) {
      $term = $storage->create(['vid' => array_key_first($vocab_bundles), 'name' => $label]);
      $term->save();
      return (int) $term->id();
    }

    return NULL;
  }

  /**
   * Find a node by its path alias slug.
   */
  public function findNodeBySlug(string $slug): ?int {
    $aliases = $this->entityTypeManager->getStorage('path_alias')
      ->loadByProperties(['alias' => '/' . ltrim($slug, '/')]);

    foreach ($aliases as $alias) {
      if (preg_match('/^\/node\/(\d+)$/', $alias->getPath(), $m)) {
        return (int) $m[1];
      }
    }
    return NULL;
  }

  /**
   * Find a managed file entity by its filename.
   */
  public function findFileByName(string $filename): ?int {
    $ids = $this->entityTypeManager->getStorage('file')
      ->getQuery()->accessCheck(FALSE)->condition('filename', $filename)->execute();
    return !empty($ids) ? (int) reset($ids) : NULL;
  }

}
