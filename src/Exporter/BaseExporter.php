<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\git_content\Utility\ManagedFields;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for all entity exporters.
 *
 * Contains shared logic for normalizing fields and building frontmatter.
 * Each concrete exporter (NodeExporter, TaxonomyExporter, etc.) extends
 * this class and implements entity-specific behavior.
 */
abstract class BaseExporter {

  use ChecksumTrait;

  protected FieldDiscovery $fieldDiscovery;
  protected MarkdownSerializer $serializer;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerInterface $logger;

  /**
   * Base fields handled manually; excluded from the dynamic field loop.
   *
   * Extends ManagedFields::CORE with exporter-specific fields that are
   * serialized individually (body, uid) or intentionally omitted.
   */
  protected array $managedFields = [
    ...ManagedFields::CORE,
    // Exported individually by each exporter, not via the dynamic loop.
    'body', 'uid',
    // Revision owner — not meaningful for static content.
    'revision_uid',
  ];

  public function __construct(
    FieldDiscovery $fieldDiscovery,
    MarkdownSerializer $serializer,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->fieldDiscovery = $fieldDiscovery;
    $this->serializer = $serializer;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $loggerFactory->get('git_content');
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
          $file = $this->entityTypeManager
            ->getStorage('file')
            ->load($item['target_id'] ?? 0);
          $normalized[] = $file ? basename($file->getFileUri()) : NULL;
          break;

        case 'entity_reference':
          $target_type = $definition->getSetting('target_type');
          $target_id   = $item['target_id'] ?? NULL;
          if ($target_id && $target_type === 'taxonomy_term') {
            $term = $this->entityTypeManager
              ->getStorage('taxonomy_term')->load($target_id);
            $normalized[] = $term ? $term->label() : $target_id;
          }
          elseif ($target_id && $target_type === 'node') {
            $node = $this->entityTypeManager
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
    // Flatten groups so the hash matches what MarkdownImporter computes.
    $fm = $this->serializer->flattenGroups($frontmatter);
    $frontmatter['checksum'] = $this->computeChecksum($fm, $body);
    return $frontmatter;
  }

  /**
   * Shorten a UUID to 8 characters for readability in frontmatter.
   */
  protected function shortenUuid(string $uuid): string {
    return substr(str_replace('-', '', $uuid), 0, 8);
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