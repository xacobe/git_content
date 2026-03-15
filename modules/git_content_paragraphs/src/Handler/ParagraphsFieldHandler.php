<?php

namespace Drupal\git_content_paragraphs\Handler;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Handler\FieldHandlerInterface;
use Drupal\git_content\Normalizer\FieldNormalizer;
use Drupal\git_content\Utility\ManagedFields;
use Drupal\git_content\Utility\UuidTrait;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Field handler for Paragraphs (entity_reference_revisions → paragraph).
 *
 * Paragraphs are exported inline inside their parent entity's frontmatter —
 * no separate .md files. Each paragraph item is serialized as an associative
 * array with a 'type' key (bundle) and one key per field.
 *
 * Nested paragraphs (paragraphs inside paragraphs) work automatically because
 * FieldNormalizer delegates entity_reference_revisions fields back to this
 * handler via the FieldHandlerRegistry.
 *
 * Export example:
 * @code
 * field_content:
 *   - type: text_block
 *     uuid: a1b2c3d4
 *     field_title: Hello world
 *     field_body: Some text here
 *   - type: image_hero
 *     uuid: e5f6g7h8
 *     field_image: hero.jpg
 *     field_caption: Caption text
 * @endcode
 */
class ParagraphsFieldHandler implements FieldHandlerInterface {

  use UuidTrait;

  /**
   * Paragraph-specific fields beyond ManagedFields::CORE.
   *
   * ManagedFields::CORE already covers entity identity, revision bookkeeping,
   * and common translation fields. Only paragraph-specific extras are listed.
   */
  private const PARAGRAPH_MANAGED = [
    // Parent back-reference — reconstructed by Drupal on save.
    'parent_id', 'parent_field_name', 'parent_type',
    // Behavior plugin data — environment-specific, not portable.
    'behavior_settings',
    // Translation fields not covered by ManagedFields::CORE.
    'content_translation_uid', 'content_translation_changed',
  ];

  /** Cached FieldNormalizer instance (lazy-loaded to break circular DI). */
  private ?FieldNormalizer $fieldNormalizerInstance = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FieldDiscovery $fieldDiscovery,
    protected UuidInterface $uuid,
  ) {}

  /**
   * Runtime lookup to break the circular DI reference:
   * FieldNormalizer → FieldHandlerRegistry → this handler → FieldNormalizer.
   *
   * No compile-time dependency is declared, so Symfony never sees the cycle.
   * The instance is cached after first access to avoid repeated lookups.
   */
  private function fieldNormalizer(): FieldNormalizer {
    return $this->fieldNormalizerInstance ??= \Drupal::service('git_content.field_normalizer');
  }

  // ---------------------------------------------------------------------------
  // FieldHandlerInterface
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function supports(string $field_type, FieldDefinitionInterface $definition): bool {
    return $field_type === 'entity_reference_revisions'
      && $definition->getSetting('target_type') === 'paragraph';
  }

  /**
   * {@inheritdoc}
   *
   * Returns a sequential list of paragraph arrays, one per item.
   * Each array contains 'type', 'uuid', and one key per non-empty field.
   */
  public function normalize(FieldItemListInterface $field, FieldDefinitionInterface $definition): mixed {
    $result = [];

    foreach ($field as $item) {
      /** @var \Drupal\paragraphs\Entity\Paragraph|null $paragraph */
      $paragraph = $item->entity;
      if (!$paragraph) {
        continue;
      }

      $bundle = $paragraph->bundle();
      $data   = [
        'type' => $bundle,
        'uuid' => $paragraph->uuid(),
      ];

      $fields = $this->fieldDiscovery->getFields('paragraph', $bundle);
      foreach ($fields as $field_name => $field_def) {
        if (in_array($field_name, ManagedFields::CORE) || in_array($field_name, self::PARAGRAPH_MANAGED)) {
          continue;
        }
        if (!$paragraph->hasField($field_name)) {
          continue;
        }
        $pfield = $paragraph->get($field_name);
        if ($pfield->isEmpty()) {
          continue;
        }
        $data[$field_name] = $this->fieldNormalizer()->normalize($pfield, $field_def);
      }

      $result[] = $data;
    }

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Accepts a sequential list of paragraph arrays (or a single array for
   * cardinality-1 fields). Creates or updates each paragraph entity and
   * returns the target_id / target_revision_id pairs Drupal expects.
   */
  public function denormalize(mixed $value, FieldDefinitionInterface $definition): mixed {
    if (empty($value)) {
      return NULL;
    }

    // Normalise to a list — handle both single-item assoc and sequential array.
    $items = is_array($value) && array_is_list($value) ? $value : [$value];

    $result = [];

    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $bundle     = $item['type'] ?? NULL;
      $uuid = $item['uuid'] ?? NULL;

      if (!$bundle) {
        continue;
      }

      $paragraph = $uuid
        ? $this->findByUuid($uuid, $bundle)
        : NULL;

      if ($paragraph) {
        // Existing paragraph: update in place.
        $paragraph->setNewRevision(FALSE);
      }
      else {
        $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
          'type'     => $bundle,
          'uuid'     => $uuid ? $this->expandShortUuid($uuid) : $this->uuid->generate(),
          'langcode' => 'und',
        ]);
      }

      // Populate fields.
      $fields = $this->fieldDiscovery->getFields('paragraph', $bundle);
      foreach ($fields as $field_name => $field_def) {
        if (in_array($field_name, ManagedFields::CORE) || in_array($field_name, self::PARAGRAPH_MANAGED)) {
          continue;
        }
        if (!isset($item[$field_name])) {
          continue;
        }
        if (!$paragraph->hasField($field_name)) {
          continue;
        }
        $denormalized = $this->fieldNormalizer()->denormalize($item[$field_name], $field_def);
        if ($denormalized !== NULL) {
          $paragraph->set($field_name, $denormalized);
        }
      }

      $paragraph->save();

      $result[] = [
        'target_id'          => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    return $result ?: NULL;
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  private function findByUuid(string $uuid, string $bundle): mixed {
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('uuid', $uuid . '%', 'LIKE')
      ->range(0, 1)
      ->execute();

    return !empty($ids) ? $storage->load(reset($ids)) : NULL;
  }

}

