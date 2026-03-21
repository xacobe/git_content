<?php

namespace Drupal\git_content\Normalizer;

use Drupal\git_content\Handler\FieldHandlerRegistry;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\DateParseTrait;
use Drupal\git_content\Utility\EntityLinkRewriteTrait;
use Drupal\git_content\Utility\SlugTrait;
use Drupal\git_content\Utility\EntityReferenceResolver;
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

  use DateParseTrait;
  use EntityLinkRewriteTrait;
  use SlugTrait;


  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FieldHandlerRegistry $fieldHandlerRegistry,
    protected MarkdownSerializer $serializer,
    protected TimeInterface $time,
    protected EntityReferenceResolver $entityReferenceResolver,
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
            'url'   => $this->rewriteEntityIdsToUuids($item['uri'] ?? ''),
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
            if ($term) {
              $langcode = $field->getEntity()->language()->getId();
              if ($langcode !== 'und' && $term->hasTranslation($langcode)) {
                $term = $term->getTranslation($langcode);
              }
              $normalized[] = $term->label();
            }
            else {
              $normalized[] = $target_id;
            }
          }
          elseif ($target_id && $target_type === 'node') {
            $node = $this->entityTypeManager
              ->getStorage('node')->load($target_id);
            $normalized[] = $node ? $this->getSlug($node) : $target_id;
          }
          elseif ($target_id && $target_type === 'media') {
            $media = $this->entityTypeManager
              ->getStorage('media')->load($target_id);
            $normalized[] = $media ? $media->uuid() : NULL;
          }
          else {
            $normalized[] = $target_id;
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $value  = $item['value'] ?? NULL;
          $format = $item['format'] ?? 'basic_html';
          if ($format === 'full_html') {
            $normalized[] = ['value' => $this->serializer->prettyHtml($this->rewriteEntityIdsToUuids($value ?? '')), 'format' => 'full_html'];
          }
          else {
            $normalized[] = $value !== NULL ? $this->serializer->htmlToMarkdown($this->rewriteEntityIdsToUuids($value)) : NULL;
          }
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
          $uri = is_array($item) ? ($item['url'] ?? '') : (string) $item;
          $result[] = [
            'uri'   => $this->rewriteEntityUuidsToIds($uri),
            'title' => is_array($item) ? ($item['title'] ?? '') : '',
          ];
          break;

        case 'image':
        case 'file':
          $fid = $this->entityReferenceResolver->findFileByName((string) $item);
          if ($fid) {
            $result[] = ['target_id' => $fid];
          }
          break;

        case 'entity_reference':
          $target_type = $definition->getSetting('target_type');
          $tid = $this->entityReferenceResolver->resolveEntityReference($item, $target_type, $definition);
          if ($tid !== NULL) {
            $result[] = ['target_id' => $tid];
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          // Explicit format stored as array (exported from a full_html field).
          if (is_array($item) && isset($item['format'])) {
            $result[] = ['value' => $this->rewriteEntityUuidsToIds($item['value'] ?? ''), 'format' => $item['format']];
            break;
          }
          // Plain string — treat as Markdown and convert to HTML.
          $html = is_string($item) ? $this->serializer->markdownToHtml($item) : (string) $item;
          $result[] = [
            'value'  => $this->rewriteEntityUuidsToIds($html),
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
}



