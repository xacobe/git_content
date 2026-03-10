<?php

namespace Drupal\git_content_paragraphs\Handler;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Handler\FieldHandlerInterface;
use Drupal\git_content\Normalizer\FieldNormalizer;
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

  /**
   * Paragraph base fields excluded from the dynamic field loop.
   */
  private const MANAGED = [
    // Entity identity — handled explicitly.
    'id', 'uuid', 'type', 'langcode', 'status',
    // Revision tracking — not portable across environments.
    'revision_id', 'revision_uid', 'revision_log', 'revision_timestamp', 'revision_default',
    // Parent back-reference — reconstructed by Drupal on save.
    'parent_id', 'parent_field_name', 'parent_type',
    // Behavior plugin data — skipped for now (rarely used, environment-specific).
    'behavior_settings',
    // Translation.
    'default_langcode', 'content_translation_source', 'content_translation_uid',
    'content_translation_outdated', 'content_translation_changed',
  ];

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
   */
  private function fieldNormalizer(): FieldNormalizer {
    return \Drupal::service('git_content.field_normalizer');
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
        'uuid' => $this->shortenUuid($paragraph->uuid()),
      ];

      $fields = $this->fieldDiscovery->getFields('paragraph', $bundle);
      foreach ($fields as $field_name => $field_def) {
        if (in_array($field_name, self::MANAGED)) {
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
    $items = $this->isList($value) ? $value : [$value];

    $result = [];

    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $bundle     = $item['type'] ?? NULL;
      $short_uuid = $item['uuid'] ?? NULL;

      if (!$bundle) {
        continue;
      }

      $paragraph = $short_uuid
        ? $this->findByShortUuid($short_uuid, $bundle)
        : NULL;

      if ($paragraph) {
        // Existing paragraph: update in place.
        $paragraph->setNewRevision(FALSE);
      }
      else {
        $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
          'type'     => $bundle,
          'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
          'langcode' => 'und',
        ]);
      }

      // Populate fields.
      $fields = $this->fieldDiscovery->getFields('paragraph', $bundle);
      foreach ($fields as $field_name => $field_def) {
        if (in_array($field_name, self::MANAGED)) {
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

  private function findByShortUuid(string $short_uuid, string $bundle): mixed {
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('uuid', $short_uuid . '%', 'LIKE')
      ->range(0, 1)
      ->execute();

    return !empty($ids) ? $storage->load(reset($ids)) : NULL;
  }

  private function shortenUuid(string $uuid): string {
    return substr(str_replace('-', '', $uuid), 0, 8);
  }

  private function expandShortUuid(string $short): string {
    $clean = str_replace('-', '', $short);
    if (strlen($clean) === 32) {
      return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($clean, 4));
    }
    $padded = str_pad($clean, 32, '0');
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($padded, 4));
  }

  /**
   * Returns TRUE if the array is a sequential list (not associative).
   */
  private function isList(array $arr): bool {
    return array_keys($arr) === range(0, count($arr) - 1);
  }

}
