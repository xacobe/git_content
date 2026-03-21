<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates node entities from Markdown frontmatter.
 */
class NodeImporter extends BaseImporter {

  public function import(array $frontmatter, string $body): string {
    $bundle   = $frontmatter['type'];
    $langcode = $frontmatter['lang'] ?? 'und';
    $uuid     = $frontmatter['uuid'] ?? NULL;

    [$node, $operation] = $this->resolveOrCreate('node', $uuid, $langcode, [
      'type'     => $bundle,
      'langcode' => $langcode,
    ]);

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

  private function savePathAlias($node, string $alias, string $langcode): void {
    $path    = '/node/' . $node->id();
    $storage = $this->entityTypeManager->getStorage('path_alias');

    $existing = $storage->loadByProperties(['path' => $path, 'langcode' => $langcode]);

    if (!empty($existing)) {
      $alias_entity = reset($existing);
      $alias_entity->set('alias', $alias);
      $alias_entity->save();
    }
    else {
      $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => $langcode])->save();
    }
  }

}
