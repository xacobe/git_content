<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates custom block (block_content) entities from Markdown.
 */
class BlockContentImporter extends BaseImporter {

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception(t("The block_content frontmatter is missing 'bundle'."));
    }

    $existing = $short_uuid ? $this->findByShortUuidGlobal($short_uuid, 'block_content') : NULL;

    if ($existing) {
      if ($existing->hasTranslation($langcode)) {
        $block     = $existing->getTranslation($langcode);
        $operation = 'updated';
      }
      else {
        $block     = $existing->addTranslation($langcode);
        $operation = 'imported';
      }
    }
    else {
      $block = $this->entityTypeManager->getStorage('block_content')->create([
        'type'             => $bundle,
        'langcode'         => $langcode,
        'default_langcode' => 1,
        'uuid'             => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $block->set('info', $frontmatter['title'] ?? 'Untitled');
    $block->set('status', ($frontmatter['status'] ?? 'draft') === 'published' ? 1 : 0);

    if ($block->hasField('body') && !empty($body)) {
      $block->set('body', [
        'value'  => $this->serializer->markdownToHtml($body),
        'format' => 'basic_html',
      ]);
    }

    $definitions = $this->fieldDiscovery->getFields('block_content', $bundle);
    $this->populateDynamicFields($block, $frontmatter, $definitions);

    $block->save();

    return $operation;
  }

}
