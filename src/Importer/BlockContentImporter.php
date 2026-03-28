<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates custom block (block_content) entities from Markdown.
 */
class BlockContentImporter extends BaseImporter {

  public function getEntityType(): ?string {
    return 'block_content';
  }

  public function getImportWeight(): int {
    return 50;
  }

  public function extractEntityId(array $frontmatter): ?int {
    return !empty($frontmatter['block_id']) ? (int) $frontmatter['block_id'] : NULL;
  }

  public function resolveBundle(array $frontmatter): ?string {
    return $frontmatter['bundle'] ?? NULL;
  }

  public function getBundleQueryField(): ?string {
    return 'type';
  }

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $block_id   = !empty($frontmatter['block_id']) ? (int) $frontmatter['block_id'] : NULL;

    if (!$bundle) {
      throw new \Exception($this->t("The block_content frontmatter is missing 'bundle'."));
    }

    // UUID is preserved for block_content because Layout Builder references
    // inline blocks by UUID (block_uuid in component configuration).
    $create_values = ['type' => $bundle, 'langcode' => $langcode, 'default_langcode' => 1];
    if (!empty($frontmatter['uuid'])) {
      $create_values['uuid'] = $frontmatter['uuid'];
    }
    $this->preserveEntityId('block_content', 'id', 'block_id', $create_values, $frontmatter);

    [$block, $operation] = $this->resolveOrCreate('block_content', $block_id, $langcode, $create_values);

    $block->set('info', $frontmatter['title'] ?? 'Untitled');
    $block->set('status', $this->resolveStatus($frontmatter));

    $this->setBody($block, $body, $frontmatter['body_format'] ?? 'basic_html');

    $definitions = $this->fieldDiscovery->getFields('block_content', $bundle);
    $this->populateDynamicFields($block, $frontmatter, $definitions);

    // Force reusable=true before save. Layout Builder's
    // SetInlineBlockDependency throws on non-reusable blocks that lack an
    // inline_block_usage record (empty after DB reset). Layout Builder will
    // set reusable=false when the parent node's layout_section is saved.
    if ($block->hasField('reusable')) {
      $block->set('reusable', TRUE);
    }

    $block->save();

    return $operation;
  }

}
