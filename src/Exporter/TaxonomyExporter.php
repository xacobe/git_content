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
 *         {slug}-{langcode}.md
 */
class TaxonomyExporter extends BaseExporter {

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir = DRUPAL_ROOT . '/content_export/taxonomy/' . $entity->bundle() . '/' . $langcode;
    $this->ensureDir($dir);

    $slug     = $this->getTermSlug($entity);
    $filepath = $dir . '/' . $slug . '.md';

    $written = $this->writeIfChanged($filepath, $markdown);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $frontmatter = [];
    $frontmatter['uuid']       = $this->shortenUuid($entity->uuid());
    $frontmatter['type']       = 'taxonomy_term';
    $frontmatter['vocabulary'] = $entity->bundle();
    $frontmatter['lang']       = $entity->language()->getId();
    $frontmatter['_']          = NULL;

    $frontmatter['status'] = $entity->get('status')->value ? 'published' : 'draft';
    $frontmatter['name']   = $entity->label();
    $frontmatter['slug']   = $this->getTermSlug($entity);
    $frontmatter['weight'] = (int) ($entity->get('weight')->value ?? 0);
    $frontmatter['__']     = NULL;

    // Parent term
    $parent_tid = $this->getParentTid($entity);
    $frontmatter['parent'] = $parent_tid;
    $frontmatter['___']    = NULL;

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

    $frontmatter = $this->addChecksum($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Generate a slug for the term: lowercase name with hyphens.
   */
  protected function getTermSlug(EntityInterface $entity): string {
    $name = $entity->label() ?? 'term-' . $entity->id();
    return preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
  }

  /**
   * Get the parent term ID, or NULL for root terms.
   */
  protected function getParentTid(EntityInterface $entity): ?int {
    if ($entity->hasField('parent')) {
      $parents = $entity->get('parent')->getValue();
      if (!empty($parents[0]['target_id']) && (int) $parents[0]['target_id'] !== 0) {
        return (int) $parents[0]['target_id'];
      }
    }
    return NULL;
  }

}