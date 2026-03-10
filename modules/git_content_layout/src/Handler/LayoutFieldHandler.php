<?php

namespace Drupal\git_content_layout\Handler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\git_content\Handler\FieldHandlerInterface;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\layout_builder\Section;

/**
 * Handles export and import of Layout Builder per-entity overrides.
 *
 * Supports the `layout_section` field type used by `layout_builder__layout`.
 *
 * Export strategy:
 *   - Serializes each Section via Section::toArray(), then strips noise:
 *     · `uuid` inside each component (already the map key — restored on import).
 *     · `additional: {}` (always empty unless a plugin adds data).
 *     · `third_party_settings: {}` at section level and inside formatter config.
 *     · `layout_settings` block when it contains only an empty label.
 *   - Inline blocks: replaces environment-specific block_id / block_revision_id
 *     with the block_content UUID for portability across environments.
 *   - Library blocks (plugin_id 'block_content:{uuid}'): UUID is already in the
 *     plugin ID — no transformation needed.
 *
 * Import strategy:
 *   - Restores stripped fields to their defaults before calling Section::fromArray().
 *   - Inline blocks: resolves block_uuid back to the local block_id and
 *     block_revision_id.
 *
 * Block content entities must be imported before nodes that reference them.
 * The MarkdownImporter import order (IMPORT_ORDER constant) guarantees this.
 */
class LayoutFieldHandler implements FieldHandlerInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  // ---------------------------------------------------------------------------
  // FieldHandlerInterface
  // ---------------------------------------------------------------------------

  public function supports(string $field_type, FieldDefinitionInterface $definition): bool {
    return $field_type === 'layout_section';
  }

  /**
   * {@inheritdoc}
   *
   * Returns a clean array of section arrays for YAML frontmatter.
   */
  public function normalize(FieldItemListInterface $field, FieldDefinitionInterface $definition): mixed {
    if (!($field instanceof LayoutSectionItemList) || $field->isEmpty()) {
      return NULL;
    }

    $sections = [];
    foreach ($field->getSections() as $section) {
      $data = $section->toArray();
      $data = $this->cleanSection($data);
      $sections[] = $data;
    }

    return $sections ?: NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Returns [['section' => Section], ...] as expected by FieldItemList::setValue().
   */
  public function denormalize(mixed $value, FieldDefinitionInterface $definition): mixed {
    if (!is_array($value) || empty($value)) {
      return NULL;
    }

    $items = [];
    foreach ($value as $section_data) {
      if (!is_array($section_data)) {
        continue;
      }
      $section_data = $this->restoreSection($section_data);
      $items[] = ['section' => Section::fromArray($section_data)];
    }

    return $items ?: NULL;
  }

  // ---------------------------------------------------------------------------
  // Export: strip noise from section/component data
  // ---------------------------------------------------------------------------

  private function cleanSection(array $data): array {
    // layout_settings: always keep it — even when empty it signals to editors
    // that label, column widths, etc. can be set here by editing the .md file.

    // third_party_settings at section level: always empty in practice.
    if (isset($data['third_party_settings']) && empty($data['third_party_settings'])) {
      unset($data['third_party_settings']);
    }

    $data['components'] = $this->normalizeComponents($data['components'] ?? []);

    return $data;
  }

  /**
   * Strip redundant/empty component fields and normalise inline block refs.
   */
  private function normalizeComponents(array $components): array {
    $result = [];

    foreach ($components as $uuid => $component) {
      // UUID is the map key — no need to repeat it inside the object.
      unset($component['uuid']);

      // additional is always {} unless an exotic plugin stores something here.
      if (isset($component['additional']) && empty($component['additional'])) {
        unset($component['additional']);
      }

      // Strip empty third_party_settings from the formatter sub-config.
      if (isset($component['configuration']['formatter']['third_party_settings'])
          && empty($component['configuration']['formatter']['third_party_settings'])
      ) {
        unset($component['configuration']['formatter']['third_party_settings']);
      }

      // Inline block: replace local block_id with portable UUID.
      if (str_starts_with($component['configuration']['id'] ?? '', 'inline_block:')) {
        $block_id = $component['configuration']['block_id'] ?? NULL;
        if ($block_id) {
          $block = $this->entityTypeManager->getStorage('block_content')->load($block_id);
          if ($block) {
            $component['configuration']['block_uuid'] = $block->uuid();
          }
        }
        unset(
          $component['configuration']['block_id'],
          $component['configuration']['block_revision_id'],
          $component['configuration']['block_serialized'],
        );
      }

      $result[$uuid] = $component;
    }

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Import: restore stripped fields and resolve inline block UUIDs
  // ---------------------------------------------------------------------------

  private function restoreSection(array $data): array {
    // Section::fromArray() already defaults third_party_settings to [],
    // but be explicit in case the implementation changes.
    $data += ['layout_settings' => [], 'third_party_settings' => []];

    $data['components'] = $this->denormalizeComponents($data['components'] ?? []);

    return $data;
  }

  /**
   * Re-add stripped fields and resolve inline block UUID → block_id.
   */
  private function denormalizeComponents(array $components): array {
    foreach ($components as $uuid => &$component) {
      // Restore uuid inside the component (SectionComponent::fromArray() needs it).
      $component['uuid'] = $uuid;

      // Restore omitted defaults (SectionComponent::fromArray() also adds these,
      // but be explicit so the array is always well-formed).
      $component += ['additional' => []];

      // Inline block: resolve block_uuid → local block_id / block_revision_id.
      if (str_starts_with($component['configuration']['id'] ?? '', 'inline_block:')) {
        $block_uuid = $component['configuration']['block_uuid'] ?? NULL;
        unset($component['configuration']['block_uuid']);

        if ($block_uuid) {
          $blocks = $this->entityTypeManager
            ->getStorage('block_content')
            ->loadByProperties(['uuid' => $block_uuid]);

          if (!empty($blocks)) {
            $block = reset($blocks);
            $component['configuration']['block_id'] = (int) $block->id();
            $component['configuration']['block_revision_id'] = (int) $block->getRevisionId();
          }
        }
      }
    }

    return $components;
  }

}
