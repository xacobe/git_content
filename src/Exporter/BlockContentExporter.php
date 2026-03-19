<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Export custom block content entities (block_content) to Markdown.
 *
 * Block content entities are fieldable content entities similar to nodes.
 * This exporter does NOT export block placement/configuration (regions,
 * visibility, etc.) since that is handled via `drush config:export`.
 *
 * Output structure:
 *   content_export/
 *     blocks/
 *       {bundle}/
 *         {slug}.{lang}.md
 */
class BlockContentExporter extends BaseExporter {

  protected function typeDir(): string {
    return 'blocks';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir      = $this->contentExportDir() . '/blocks/' . $entity->bundle();
    $this->ensureDir($dir, $dryRun);

    $slug     = $this->getBlockSlug($entity);
    $filepath = $dir . '/' . $this->buildFilename($slug, $langcode);

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();

    $frontmatter = [];
    $frontmatter['uuid']   = $entity->uuid();
    $frontmatter['type']   = 'block_content';
    $frontmatter['bundle'] = $entity->bundle();
    $frontmatter['lang']   = $langcode;
    $frontmatter['status'] = $entity->get('status')->value ? 'published' : 'draft';
    $frontmatter['_']      = NULL;

    $frontmatter['title']  = $entity->label();
    $frontmatter['slug']   = $this->getBlockSlug($entity);
    $frontmatter['__']     = NULL;

    // Dynamic bundle fields (same approach as NodeExporter)
    $this->applyDynamicGroups($frontmatter, $entity, 'block_content');

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Body: block_content may have a body field.
    $body = $this->exportBodyField($entity, $frontmatter);

    $frontmatter = $this->addChecksum($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Generate a slug for the block from its title/label.
   * Falls back to the internal ID if no readable name is available.
   */
  protected function getBlockSlug(EntityInterface $entity): string {
    $slug = $this->slugify($entity->label() ?? '');
    return $slug ? $slug . '-' . $entity->id() : 'block-' . $entity->id();
  }

}
