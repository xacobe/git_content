<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Export nodes to Markdown files with YAML frontmatter.
 *
 * Output structure:
 *   content_export/
 *     {bundle}/
 *       {slug}-{langcode}.md
 */
class NodeExporter extends BaseExporter {

  protected function typeDir(): string {
    return 'content_types';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir = $this->contentExportDir() . '/' . $this->typeDir() . '/' . $entity->bundle() . '/' . $langcode;
    $this->ensureDir($dir);

    $slug     = $this->getSlug($entity);
    $filepath = $dir . '/' . $slug . '.md';

    $written = $this->writeIfChanged($filepath, $markdown);

    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();

    // --- Base fields ---
    $frontmatter = [];
    $frontmatter['uuid']   = $entity->uuid();
    $frontmatter['type']   = $entity->bundle();
    $frontmatter['lang']   = $langcode;
    $frontmatter['status'] = $entity->isPublished() ? 'published' : 'draft';
    $frontmatter['_']      = NULL; // blank line

    $frontmatter['title']  = $entity->label();
    $frontmatter['slug']   = $this->getSlug($entity);
    $frontmatter['__']     = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['changed'] = date('Y-m-d', $entity->getChangedTime());
    $owner = $entity->get('uid')->entity;
    $frontmatter['author'] = $owner ? $owner->getAccountName() : NULL;
    $frontmatter['___']    = NULL;

    $frontmatter['path']    = $this->getPathAlias($entity);
    $frontmatter['____']    = NULL;

    // --- Grouped dynamic fields ---
    $this->applyDynamicGroups($frontmatter, $entity, 'node');

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // --- Body ---
    $body = '';
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body_field  = $entity->get('body');
      $body_format = $body_field->format ?? 'basic_html';
      if ($body_format === 'full_html') {
        $body = $this->serializer->prettyHtml($body_field->value) ?? '';
        $frontmatter['body_format'] = 'full_html';
      }
      else {
        $body = $this->serializer->htmlToMarkdown($body_field->value);
      }
    }

    $frontmatter = $this->addChecksum($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

}
