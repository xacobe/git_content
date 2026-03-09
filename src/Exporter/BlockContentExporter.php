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
 *         {uuid-slug}-{langcode}.md
 */
class BlockContentExporter extends BaseExporter {

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir = DRUPAL_ROOT . '/content_export/blocks/' . $entity->bundle() . '/' . $langcode;
    $this->ensureDir($dir);

    $slug     = $this->getBlockSlug($entity);
    $filepath = $dir . '/' . $slug . '.md';

    $written = $this->writeIfChanged($filepath, $markdown);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();

    $frontmatter = [];
    $frontmatter['uuid']   = $this->shortenUuid($entity->uuid());
    $frontmatter['type']   = 'block_content';
    $frontmatter['bundle'] = $entity->bundle();
    $frontmatter['lang']   = $langcode;
    $frontmatter['status'] = $entity->get('status')->value ? 'published' : 'draft';
    $frontmatter['_']      = NULL;

    $frontmatter['title']  = $entity->label();
    $frontmatter['slug']   = $this->getBlockSlug($entity);
    $frontmatter['__']     = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->get('changed')->value ?? time());
    $frontmatter['___']     = NULL;

    // Dynamic bundle fields (same approach as NodeExporter)
    $groups = $this->buildDynamicGroups($entity, 'block_content');

    if (!empty($groups['taxonomy'])) {
      $frontmatter['taxonomy'] = $groups['taxonomy'];
      $frontmatter['____']     = NULL;
    }

    if (!empty($groups['media'])) {
      $frontmatter['media']  = $groups['media'];
      $frontmatter['_____']  = NULL;
    }

    if (!empty($groups['references'])) {
      $frontmatter['references'] = $groups['references'];
      $frontmatter['______']     = NULL;
    }

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Body: block_content may have a body field
    $body = '';
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body = $this->serializer->htmlToMarkdown($entity->get('body')->value);
    }

    $frontmatter = $this->addChecksum($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Generate a slug for the block from its title/label.
   * Falls back to the internal ID if no readable name is available.
   */
  protected function getBlockSlug(EntityInterface $entity): string {
    $label = $entity->label() ?? 'block';
    $slug  = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($label));
    $slug  = trim($slug, '-');

    // Añadimos el ID al final para evitar colisiones entre bloques con mismo título.
    return $slug ? $slug . '-' . $entity->id() : 'block-' . $entity->id();
  }

}
