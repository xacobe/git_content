<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Export taxonomy terms to Markdown files with YAML frontmatter.
 *
 * Output structure:
 *   content_export/
 *     taxonomy/
 *       {vocabulary}/
 *         {slug}.{lang}.md
 */
class TaxonomyExporter extends BaseExporter {

  public function getEntityType(): string {
    return 'taxonomy_term';
  }

  public function getCliName(): string {
    return 'taxonomy';
  }

  protected function typeDir(): string {
    return 'taxonomy';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir      = $this->contentExportDir() . '/taxonomy/' . $entity->bundle();
    $this->ensureDir($dir, $dryRun);

    $slug     = $this->getTermSlug($entity);
    $filepath = $dir . '/' . $this->buildFilename($slug, $langcode);

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $frontmatter = [];
    $frontmatter['tid']        = (int) $entity->id();
    $frontmatter['type']       = 'taxonomy_term';
    $frontmatter['vocabulary'] = $entity->bundle();
    $frontmatter['lang']       = $entity->language()->getId();

    $frontmatter['draft'] = $this->isDraft($entity);
    $frontmatter['name']   = $entity->label();
    $frontmatter['slug']   = $this->getTermSlug($entity);
    $frontmatter['weight'] = (int) ($entity->get('weight')->value ?? 0);

    $frontmatter['parent'] = $this->getParentTid($entity);

    // Extra dynamic fields for the vocabulary
    $groups = $this->buildDynamicGroups($entity, 'taxonomy_term');

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    // If the description is stored in the 'extra' group, avoid duplicating it
    // in frontmatter; represent it solely as the Markdown body.
    unset($frontmatter['description']);

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Description as the body
    $body = '';
    if ($entity->hasField('description') && !$entity->get('description')->isEmpty()) {
      $body = $this->serializer->htmlToMarkdown($entity->get('description')->value ?? '');
    }

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Generate a slug for the term: lowercase name with hyphens.
   */
  private function getTermSlug(EntityInterface $entity): string {
    return $this->slugify($entity->label() ?? 'term-' . $entity->id());
  }

  /**
   * Get the parent term tid, or NULL for root terms.
   */
  private function getParentTid(EntityInterface $entity): ?int {
    if (!$entity->hasField('parent')) {
      return NULL;
    }
    $target_id = (int) ($entity->get('parent')->getValue()[0]['target_id'] ?? 0);
    return $target_id !== 0 ? $target_id : NULL;
  }

}