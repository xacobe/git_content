<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Base class for all entity exporters.
 *
 * Contains shared logic for normalizing fields and building frontmatter.
 * Each concrete exporter (NodeExporter, TaxonomyExporter, etc.) extends
 * this class and implements entity-specific behavior.
 */
abstract class BaseExporter {

  protected FieldDiscovery $fieldDiscovery;
  protected MarkdownSerializer $serializer;

  /**
   * Base fields handled manually; excluded from the dynamic field loop.
   */
  protected array $managedFields = [
    'nid', 'vid', 'uuid', 'langcode', 'status', 'created', 'changed',
    'uid', 'title', 'body', 'path', 'type',
    'revision_timestamp', 'revision_uid', 'revision_log', 'revision_log_message', 'revision_default',
    'revision_translation_affected', 'default_langcode',
    'content_translation_source', 'content_translation_outdated',
    // Users (do not export sensitive or irrelevant fields)
    'pass', 'access', 'login', 'init',
    // Comments – not exported for static content workflows
    'comment', 'comment_count', 'comment_status', 'last_comment_timestamp',
    'last_comment_name', 'last_comment_uid',
    // block_content system fields
    'id', 'revision_id', 'revision_created', 'revision_user', 'info', 'reusable',
  ];

  public function __construct(FieldDiscovery $fieldDiscovery, MarkdownSerializer $serializer) {
    $this->fieldDiscovery = $fieldDiscovery;
    $this->serializer = $serializer;
  }

  /**
   * Export the entity to a Markdown file on disk.
   *
   * @return array{path: string, skipped: bool}
   *   Generated file information.
   */
  abstract public function exportToFile(EntityInterface $entity): array;

  /**
   * Generate the full Markdown contents for the entity.
   *
   * @return string
   *   The .md file contents.
   */
  abstract public function export(EntityInterface $entity): string;

  // ---------------------------------------------------------------------------
  // Shared helpers
  // ---------------------------------------------------------------------------

  /**
   * Build the block of dynamic fields grouped by type
   * (taxonomy, media, references, extra).
   */
  protected function buildDynamicGroups(EntityInterface $entity, string $entity_type): array {
    $bundle = $entity->bundle();
    $fields = $this->fieldDiscovery->getFields($entity_type, $bundle);

    $taxonomy   = [];
    $media      = [];
    $references = [];
    $extra      = [];

    foreach ($fields as $field_name => $definition) {
      if (in_array($field_name, $this->managedFields)) {
        continue;
      }
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      $field_type = $definition->getType();
      $normalized = $this->normalizeField($field, $definition);

      if ($field_type === 'entity_reference') {
        $target_type = $definition->getSetting('target_type');
        if ($target_type === 'taxonomy_term') {
          $vocab = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
          $key = !empty($vocab) ? implode('_', array_keys($vocab)) : $field_name;
          $taxonomy[$key] = $normalized;
        }
        elseif (in_array($target_type, ['node', 'media'])) {
          $references[$field_name] = $normalized;
        }
        else {
          $extra[$field_name] = $normalized;
        }
      }
      elseif (in_array($field_type, ['image', 'file'])) {
        $media[$field_name] = $normalized;
      }
      else {
        $extra[$field_name] = $normalized;
      }
    }

    return compact('taxonomy', 'media', 'references', 'extra');
  }

  /**
   * Normalize a field value into a clean format for frontmatter.
   */
  protected function normalizeField($field, FieldDefinitionInterface $definition): mixed {
    $field_type  = $definition->getType();
    $items       = $field->getValue();
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
    $is_multiple = $cardinality !== 1;

    $normalized = [];

    foreach ($items as $item) {
      switch ($field_type) {
        case 'string':
        case 'string_long':
        case 'list_string':
          $normalized[] = $item['value'] ?? NULL;
          break;

        case 'boolean':
          $normalized[] = (bool) ($item['value'] ?? FALSE);
          break;

        case 'integer':
        case 'list_integer':
        case 'list_float':
        case 'decimal':
        case 'float':
          $normalized[] = $item['value'] ?? NULL;
          break;

        case 'datetime':
          $normalized[] = isset($item['value']) ? substr($item['value'], 0, 10) : NULL;
          break;

        case 'timestamp':
          $normalized[] = isset($item['value']) ? date('Y-m-d', $item['value']) : NULL;
          break;

        case 'link':
          $normalized[] = [
            'url'   => $item['uri'] ?? NULL,
            'title' => $item['title'] ?? NULL,
          ];
          break;

        case 'image':
        case 'file':
          $file = \Drupal::service('entity_type.manager')
            ->getStorage('file')
            ->load($item['target_id'] ?? 0);
          $normalized[] = $file ? basename($file->getFileUri()) : NULL;
          break;

        case 'entity_reference':
          $target_type = $definition->getSetting('target_type');
          $target_id   = $item['target_id'] ?? NULL;
          if ($target_id && $target_type === 'taxonomy_term') {
            $term = \Drupal::service('entity_type.manager')
              ->getStorage('taxonomy_term')->load($target_id);
            $normalized[] = $term ? $term->label() : $target_id;
          }
          elseif ($target_id && $target_type === 'node') {
            $node = \Drupal::service('entity_type.manager')
              ->getStorage('node')->load($target_id);
            $normalized[] = $node ? $this->getSlug($node) : $target_id;
          }
          else {
            $normalized[] = $target_id;
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $normalized[] = $item['value'] ?? NULL;
          break;

        default:
          $normalized[] = $item['value'] ?? (count($item) === 1 ? reset($item) : $item);
      }
    }

    if (!$is_multiple && count($normalized) === 1) {
      return $normalized[0];
    }

    return $normalized ?: NULL;
  }

  /**
   * Get a slug based on the entity's path alias.
   */
  protected function getSlug(EntityInterface $entity): string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        return ltrim(basename($alias), '/');
      }
    }
    return $entity->getEntityTypeId() . '-' . $entity->id();
  }

  /**
   * Get the full path alias for the entity.
   */
  protected function getPathAlias(EntityInterface $entity): string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        return $alias;
      }
    }
    return '/' . $entity->getEntityTypeId() . '/' . $entity->id();
  }

  /**
   * Add a SHA1 checksum to frontmatter to detect changes to the file.
   *
   * The checksum is calculated over a canonical representation of the
   * frontmatter + body (JSON with sorted keys) so it remains stable even when
   * YAML key order or serialization formatting changes.
   */
  protected function addChecksum(array $frontmatter, string $body): array {
    // Ensure the checksum is calculated over the same structure the
    // importer uses for comparison (flattened groups).
    $fm = $this->serializer->flattenGroups($frontmatter);
    unset($fm['checksum']);
    $fm = array_filter($fm, fn($key) => !preg_match('/^_+$/', (string) $key), ARRAY_FILTER_USE_KEY);

    $canonical = $this->canonicalizeForHash(['frontmatter' => $fm, 'body' => $body]);
    $hash = sha1(json_encode($canonical, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));

    $frontmatter['checksum'] = $hash;

    return $frontmatter;
  }

  /**
   * Shorten a UUID to 8 characters for readability in frontmatter.
   */
  protected function shortenUuid(string $uuid): string {
    return substr(str_replace('-', '', $uuid), 0, 8);
  }

  /**
   * Canonicalize a data structure for hashing.
   *
   * Ensures a stable key order and applies the same transformation to nested
   * arrays (recursive sorting) so the hash is deterministic regardless of
   * JSON encoding details.
   */
  protected function canonicalizeForHash(mixed $data): mixed {
    if (is_array($data)) {
      $keys = array_keys($data);
      $is_sequential = $keys === range(0, count($data) - 1);

      if ($is_sequential) {
        $data = array_map(fn($item) => $this->canonicalizeForHash($item), $data);

        // For scalar values, sort so order changes don’t affect the checksum.
        $all_scalars = array_reduce($data, fn($carry, $item) => $carry && (is_null($item) || is_scalar($item)), TRUE);
        if ($all_scalars) {
          sort($data);
        }
        else {
          // For arrays of objects/arrays, sort by their JSON representation.
          usort($data, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
        }

        return $data;
      }

      // Associative: sort keys and canonicalize recursively.
      ksort($data);
      foreach ($data as $key => $value) {
        $data[$key] = $this->canonicalizeForHash($value);
      }
    }
    return $data;
  }

  /**
   * Return the short UUID of the original entity when this is a translation.
   */
  protected function getTranslationOf(EntityInterface $entity): ?string {
    if (!$entity->isDefaultTranslation()) {
      return $this->shortenUuid($entity->getUntranslated()->uuid());
    }
    return NULL;
  }

  /**
   * Write a file only if its contents have changed.
   *
   * @return bool
   *   TRUE if the file was written (new or updated), FALSE if skipped.
   */
  protected function writeIfChanged(string $filepath, string $content): bool {
    if (file_exists($filepath)) {
      $existing = file_get_contents($filepath);
      if ($existing === $content) {
        return false;
      }
    }

    file_put_contents($filepath, $content);
    return true;
  }

  /**
   * Create the export directory if it does not exist.
   */
  protected function ensureDir(string $dir): void {
    if (!file_exists($dir)) {
      mkdir($dir, 0775, TRUE);
    }
  }

}