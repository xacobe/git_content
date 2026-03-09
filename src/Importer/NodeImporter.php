<?php

namespace Drupal\git_content\Importer;

use Drupal\node\Entity\Node;

/**
 * Imports or updates node entities from Markdown frontmatter.
 */
class NodeImporter extends BaseImporter {

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['type'];
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    $existing = $short_uuid ? $this->findByShortUuid($short_uuid, 'node', $bundle) : NULL;

    if ($existing) {
      $node = $existing->hasTranslation($langcode)
        ? $existing->getTranslation($langcode)
        : $existing->addTranslation($langcode);
      $operation = 'updated';
    }
    else {
      $node = Node::create([
        'type'     => $bundle,
        'langcode' => $langcode,
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $node->set('title', $frontmatter['title'] ?? 'Untitled');
    $node->set('status', ($frontmatter['status'] ?? 'draft') === 'published' ? 1 : 0);

    if (!empty($frontmatter['created'])) {
      $node->set('created', $this->parseDate($frontmatter['created']));
    }
    if (!empty($frontmatter['changed'])) {
      $node->set('changed', $this->parseDate($frontmatter['changed']));
    }

    if ($node->hasField('body') && !empty($body)) {
      $node->set('body', [
        'value'  => $this->serializer->markdownToHtml($body),
        'format' => 'basic_html',
      ]);
    }

    $definitions = $this->fieldDiscovery->getFields('node', $bundle);
    $this->populateDynamicFields($node, $frontmatter, $definitions);

    $node->save();

    if (!empty($frontmatter['path'])) {
      $this->savePathAlias($node, $frontmatter['path'], $langcode);
    }

    return $operation;
  }

}
