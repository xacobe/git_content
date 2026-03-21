<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates custom block (block_content) entities from Markdown.
 */
class BlockContentImporter extends BaseImporter {

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception(t("The block_content frontmatter is missing 'bundle'."));
    }

    [$block, $operation] = $this->resolveOrCreate('block_content', $uuid, $langcode, [
      'type'             => $bundle,
      'langcode'         => $langcode,
      'default_langcode' => 1,
    ]);

    $block->set('info', $frontmatter['title'] ?? $this->t('Untitled'));
    $block->set('status', $this->resolveStatus($frontmatter));

    $this->setBody($block, $body, $frontmatter['body_format'] ?? 'basic_html');

    $definitions = $this->fieldDiscovery->getFields('block_content', $bundle);
    $this->populateDynamicFields($block, $frontmatter, $definitions);

    $block->save();

    return $operation;
  }

}
