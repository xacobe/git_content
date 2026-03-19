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
    $uuid = $frontmatter['uuid'] ?? NULL;

    $existing = $uuid ? $this->findByUuid($uuid, 'node', $bundle) : NULL;

    if ($existing) {
      [$node, $operation] = $this->resolveTranslation($existing, $langcode);
    }
    else {
      $node = Node::create([
        'type'     => $bundle,
        'langcode' => $langcode,
        'uuid'     => $uuid ?? $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $node->set('title', $frontmatter['title'] ?? 'Untitled');
    $node->set('status', $this->resolveStatus($frontmatter));
    $this->setAuthor($node, $frontmatter);

    $dateVal = $frontmatter['date'] ?? $frontmatter['created'] ?? NULL;
    if (!empty($dateVal)) {
      $node->set('created', $this->parseDate($dateVal));
    }

    $this->setBody($node, $body, $frontmatter['body_format'] ?? 'basic_html');

    $definitions = $this->fieldDiscovery->getFields('node', $bundle);
    $this->populateDynamicFields($node, $frontmatter, $definitions);

    $node->save();

    if (!empty($frontmatter['path'])) {
      $this->savePathAlias($node, $frontmatter['path'], $langcode);
    }

    return $operation;
  }

}
