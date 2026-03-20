<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Export nodes to Markdown files with YAML frontmatter.
 *
 * Output structure:
 *   content_export/
 *     content/
 *       {bundle_plural}/
 *         {slug}.{lang}.md
 */
class NodeExporter extends BaseExporter {

  /** metatag is SSG-relevant for nodes; allow it through the dynamic loop. */
  protected array $allowedFields = ['metatag'];

  protected function typeDir(): string {
    return 'content';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir      = $this->contentExportDir() . '/content/' . $this->pluralBundle($entity->bundle());
    $this->ensureDir($dir, $dryRun);

    $slug     = $this->getSlug($entity);
    $filepath = $dir . '/' . $this->buildFilename($slug, $langcode);

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);

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
    $frontmatter['draft'] = !$entity->isPublished();
    $frontmatter['_']     = NULL; // blank line

    $frontmatter['title']  = $entity->label();
    $frontmatter['slug']   = $this->getSlug($entity);
    $frontmatter['__']     = NULL;

    $frontmatter['date']   = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['author'] = $this->getAuthorName($entity);
    $frontmatter['___']    = NULL;

    $frontmatter['path']    = $this->getPathAlias($entity);
    $frontmatter['____']    = NULL;

    // --- Grouped dynamic fields ---
    $this->applyDynamicGroups($frontmatter, $entity, 'node');

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // --- Body ---
    $body = $this->exportBodyField($entity, $frontmatter);

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

}
