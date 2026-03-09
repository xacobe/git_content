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

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir = DRUPAL_ROOT . '/content_export/content_types/' . $entity->bundle() . '/' . $langcode;
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
    $frontmatter['uuid']   = $this->shortenUuid($entity->uuid());
    $frontmatter['type']   = $entity->bundle();
    $frontmatter['lang']   = $langcode;
    $frontmatter['status'] = $entity->isPublished() ? 'published' : 'draft';
    $frontmatter['_']      = NULL; // blank line

    $frontmatter['title']  = $entity->label();
    $frontmatter['slug']   = $this->getSlug($entity);
    $frontmatter['__']     = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['changed'] = date('Y-m-d', $entity->getChangedTime());
    $frontmatter['___']     = NULL;

    $frontmatter['path']    = $this->getPathAlias($entity);
    $frontmatter['____']    = NULL;

    // --- Grouped dynamic fields ---
    $groups = $this->buildDynamicGroups($entity, 'node');

    if (!empty($groups['taxonomy'])) {
      $frontmatter['taxonomy'] = $groups['taxonomy'];
      $frontmatter['_____']    = NULL;
    }

    if (!empty($groups['media'])) {
      $frontmatter['media']  = $groups['media'];
      $frontmatter['______'] = NULL;
    }

    if (!empty($groups['references'])) {
      $frontmatter['references'] = $groups['references'];
      $frontmatter['_______']    = NULL;
    }

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // --- Body ---
    $body = '';
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body = $this->serializer->htmlToMarkdown($entity->get('body')->value);
    }

    $frontmatter = $this->addChecksum($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

}
