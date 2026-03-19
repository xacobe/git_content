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
    $frontmatter['uuid']       = $entity->uuid();
    $frontmatter['tid']        = (int) $entity->id();
    $frontmatter['type']       = 'taxonomy_term';
    $frontmatter['vocabulary'] = $entity->bundle();
    $frontmatter['lang']       = $entity->language()->getId();
    $frontmatter['_']          = NULL;

    $frontmatter['draft'] = !(bool) $entity->get('status')->value;
    $frontmatter['name']   = $entity->label();
    $frontmatter['slug']   = $this->getTermSlug($entity);
    $frontmatter['weight'] = (int) ($entity->get('weight')->value ?? 0);
    $frontmatter['__']     = NULL;

    // Parent term (UUID for portability across environments)
    $frontmatter['parent'] = $this->getParentUuid($entity);
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

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, $body);
    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Generate a slug for the term: lowercase name with hyphens.
   */
  protected function getTermSlug(EntityInterface $entity): string {
    return $this->slugify($entity->label() ?? 'term-' . $entity->id());
  }

  /**
   * Get the parent term UUID, or NULL for root terms.
   *
   * UUIDs are portable across environments (unlike tids).
   */
  protected function getParentUuid(EntityInterface $entity): ?string {
    if ($entity->hasField('parent')) {
      $parents = $entity->get('parent')->getValue();
      if (!empty($parents[0]['target_id']) && (int) $parents[0]['target_id'] !== 0) {
        $parent = $this->entityTypeManager
          ->getStorage('taxonomy_term')
          ->load((int) $parents[0]['target_id']);
        if ($parent) {
          return $parent->uuid();
        }
      }
    }
    return NULL;
  }

}