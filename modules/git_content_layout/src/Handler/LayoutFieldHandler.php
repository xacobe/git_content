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
 *   - Serializes each Section via Section::toArray().
 *   - Inline blocks (plugin_id starts with 'inline_block:'): replaces the
 *     environment-specific block_id / block_revision_id with the block_content
 *     UUID so the reference is portable across environments.
 *   - Library blocks (plugin_id 'block_content:{uuid}'): UUID is already in
 *     the plugin ID — no transformation needed.
 *
 * Import strategy:
 *   - Reconstructs Section objects via Section::fromArray().
 *   - Inline blocks: resolves block_uuid back to the local block_id and
 *     block_revision_id before handing the sections to Drupal.
 *
 * Block content entities (inline blocks) must be imported before the nodes
 * that reference them so the UUIDs can be resolved on import.
 *
 * Registered via the git_content.field_handler tag so the FieldHandlerRegistry
 * picks it up automatically when this sub-module is enabled.
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
   * Returns an array of section arrays suitable for YAML frontmatter.
   * Inline block references are normalised to UUIDs.
   */
  public function normalize(FieldItemListInterface $field, FieldDefinitionInterface $definition): mixed {
    if (!($field instanceof LayoutSectionItemList) || $field->isEmpty()) {
      return NULL;
    }

    $sections = [];
    foreach ($field->getSections() as $section) {
      $data = $section->toArray();
      $data['components'] = $this->normalizeComponents($data['components'] ?? []);
      $sections[] = $data;
    }

    return $sections ?: NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Returns the value in the format expected by FieldItemList::setValue():
   * [['section' => Section], ['section' => Section], ...]
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
      $section_data['components'] = $this->denormalizeComponents($section_data['components'] ?? []);
      $items[] = ['section' => Section::fromArray($section_data)];
    }

    return $items ?: NULL;
  }

  // ---------------------------------------------------------------------------
  // Inline block UUID ↔ block_id normalisation
  // ---------------------------------------------------------------------------

  /**
   * Replace environment-specific IDs with portable UUIDs for inline blocks.
   */
  private function normalizeComponents(array $components): array {
    foreach ($components as $uuid => &$component) {
      if (!str_starts_with($component['configuration']['id'] ?? '', 'inline_block:')) {
        continue;
      }

      $block_id = $component['configuration']['block_id'] ?? NULL;
      if ($block_id) {
        $block = $this->entityTypeManager->getStorage('block_content')->load($block_id);
        if ($block) {
          $component['configuration']['block_uuid'] = $block->uuid();
        }
      }

      // Remove local IDs — they are meaningless across environments.
      unset(
        $component['configuration']['block_id'],
        $component['configuration']['block_revision_id'],
        $component['configuration']['block_serialized'],
      );
    }

    return $components;
  }

  /**
   * Resolve portable UUIDs back to local block_id / block_revision_id.
   */
  private function denormalizeComponents(array $components): array {
    foreach ($components as $uuid => &$component) {
      if (!str_starts_with($component['configuration']['id'] ?? '', 'inline_block:')) {
        continue;
      }

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

    return $components;
  }

}
