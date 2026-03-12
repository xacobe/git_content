<?php

namespace Drupal\git_content\Normalizer;

use Drupal\git_content\Handler\FieldHandlerRegistry;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Converts field values between Drupal representation and frontmatter format.
 *
 * This service centralises all field normalization/denormalization logic so
 * that both exporters and field handlers (e.g. ParagraphsFieldHandler) can
 * share the same implementation without inheritance.
 *
 * Exporters call normalize() to produce YAML-safe values.
 * Importers call denormalize() to produce Drupal field values.
 * Field handlers (submodules) can inject this service to recursively
 * normalize/denormalize the sub-fields of complex entities (e.g. paragraphs).
 */
class FieldNormalizer {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FieldHandlerRegistry $fieldHandlerRegistry,
    protected MarkdownSerializer $serializer,
    protected TimeInterface $time,
  ) {}

  // ---------------------------------------------------------------------------
  // Normalize (export direction)
  // ---------------------------------------------------------------------------

  /**
   * Normalize a Drupal field value to a frontmatter-safe scalar/array.
   */
  public function normalize(FieldItemListInterface $field, FieldDefinitionInterface $definition): mixed {
    $field_type = $definition->getType();

    $handler = $this->fieldHandlerRegistry->find($field_type, $definition);
    if ($handler !== NULL) {
      return $handler->normalize($field, $definition);
    }

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
            $normalized[] = $node ? $this->getSlugFromEntity($node) : $target_id;
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

  // ---------------------------------------------------------------------------
  // Denormalize (import direction)
  // ---------------------------------------------------------------------------

  /**
   * Denormalize a frontmatter value into the format Drupal expects for a field.
   */
  public function denormalize(mixed $value, FieldDefinitionInterface $definition): mixed {
    $field_type = $definition->getType();

    $handler = $this->fieldHandlerRegistry->find($field_type, $definition);
    if ($handler !== NULL) {
      return $handler->denormalize($value, $definition);
    }

    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    // Always normalise to a list for uniform processing.
    $values = is_array($value) && array_is_list($value) ? $value : [$value];
    $result = [];

    foreach ($values as $item) {
      switch ($field_type) {
        case 'string':
        case 'string_long':
        case 'list_string':
          $result[] = ['value' => (string) $item];
          break;

        case 'boolean':
          $result[] = ['value' => (int) (bool) $item];
          break;

        case 'integer':
        case 'list_integer':
          $result[] = ['value' => (int) $item];
          break;

        case 'decimal':
        case 'float':
        case 'list_float':
          $result[] = ['value' => (float) $item];
          break;

        case 'datetime':
          $date = is_string($item) ? $item : NULL;
          if ($date && strlen($date) === 10) {
            $date .= 'T00:00:00';
          }
          $result[] = ['value' => $date];
          break;

        case 'timestamp':
          $result[] = ['value' => $this->parseDate($item)];
          break;

        case 'link':
          $result[] = is_array($item)
            ? ['uri' => $item['url'] ?? '', 'title' => $item['title'] ?? '']
            : ['uri' => (string) $item, 'title' => ''];
          break;

        case 'image':
        case 'file':
          $fid = $this->findFileByName((string) $item);
          if ($fid) {
            $result[] = ['target_id' => $fid];
          }
          break;

        case 'entity_reference':
          $target_type = $definition->getSetting('target_type');
          $tid = $this->resolveEntityReference($item, $target_type, $definition);
          if ($tid !== NULL) {
            $result[] = ['target_id' => $tid];
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $result[] = [
            'value'  => is_string($item) ? $this->serializer->markdownToHtml($item) : (string) $item,
            'format' => 'basic_html',
          ];
          break;

        default:
          $result[] = is_scalar($item) ? ['value' => $item] : $item;
      }
    }

    if (empty($result)) {
      return NULL;
    }

    return $cardinality === 1 ? $result[0] : $result;
  }

  // ---------------------------------------------------------------------------
  // Reference resolution — public so field handlers can reuse them
  // ---------------------------------------------------------------------------

  public function resolveEntityReference(mixed $value, string $target_type, FieldDefinitionInterface $definition): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if ($target_type === 'taxonomy_term') {
      return $this->findTermByLabel((string) $value, $definition);
    }
    if ($target_type === 'node') {
      return is_numeric($value) ? (int) $value : $this->findNodeBySlug((string) $value);
    }
    return is_numeric($value) ? (int) $value : NULL;
  }

  public function findTermByLabel(string $label, FieldDefinitionInterface $definition): ?int {
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

    // Create the term if it does not exist.
    if (!empty($vocab_bundles)) {
      $term = $storage->create(['vid' => array_key_first($vocab_bundles), 'name' => $label]);
      $term->save();
      return (int) $term->id();
    }

    return NULL;
  }

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

  public function findFileByName(string $filename): ?int {
    $files = $this->entityTypeManager->getStorage('file')
      ->getQuery()->accessCheck(FALSE)->condition('filename', $filename)->execute();
    return !empty($files) ? (int) reset($files) : NULL;
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  private function getSlugFromEntity(EntityInterface $entity): string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        return ltrim(basename($alias), '/');
      }
    }
    return $entity->getEntityTypeId() . '-' . $entity->id();
  }

  private function parseDate(mixed $date): int {
    if (is_int($date) || is_numeric($date)) {
      return (int) $date;
    }
    if (is_string($date)) {
      $ts = strtotime($date);
      return $ts !== FALSE ? $ts : $this->time->getCurrentTime();
    }
    return $this->time->getCurrentTime();
  }

}

