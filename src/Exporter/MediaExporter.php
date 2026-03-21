<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\git_content\Utility\ManagedFields;

/**
 * Export media entities to Markdown files with YAML frontmatter.
 *
 * The exported file describes media metadata (title, type, text/alt fields).
 * The physical file (image, video, etc.) is not copied; it is referenced by
 * filename so it can be versioned separately if desired.
 *
 * Output structure:
 *   content_export/
 *     media/
 *       {bundle}/
 *         {slug}.{lang}.md
 */
class MediaExporter extends BaseExporter {

  /**
   * thumbnail is Drupal-computed from the source field — redundant with
   * field_media_image / field_media_file. Exclude it from the dynamic loop
   * so it does not appear alongside the real source field in media: group.
   */
  protected array $managedFields = [
    ...ManagedFields::CORE,
    'body', 'uid', 'revision_uid', 'metatag',
    // Drupal-computed thumbnail — redundant with the real source field.
    'thumbnail',
  ];

  protected function typeDir(): string {
    return 'media';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir      = $this->contentExportDir() . '/media/' . $entity->bundle();
    $this->ensureDir($dir, $dryRun);

    $slug     = $this->getMediaSlug($entity);
    $filepath = $dir . '/' . $this->buildFilename($slug, $langcode);

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $frontmatter = [];
    $frontmatter['uuid']   = $entity->uuid();
    $frontmatter['type']   = 'media';
    $frontmatter['bundle'] = $entity->bundle();
    $frontmatter['lang']   = $entity->language()->getId();
    $frontmatter['draft']  = !(bool) $entity->get('status')->value;

    $frontmatter['name'] = $entity->label();
    $frontmatter['date'] = date('Y-m-d', $entity->get('created')->value ?? time());

    // Source file (thumbnail field or bundle source file field)
    $source_file = $this->getSourceFile($entity);
    if ($source_file) {
      $frontmatter['file'] = $source_file;
    }

    // Dynamic fields (source image, alt text, caption, taxonomy, etc.)
    $this->applyDynamicGroups($frontmatter, $entity, 'media');

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Drupal-internal: bundle (encoded in file path), uploader, slug with entity ID.
    $frontmatter['bundle'] = $entity->bundle();
    $frontmatter['author'] = $this->getAuthorName($entity);
    $frontmatter['slug']   = $this->getMediaSlug($entity);

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, '', ['bundle', 'author', 'slug']);
    return $this->serializer->serialize($frontmatter);
  }

  /**
   * Try to determine the media source filename.
   */
  protected function getSourceFile(EntityInterface $entity): ?string {
    // Common source fields by media bundle type
    $source_fields = ['field_media_image', 'field_media_file', 'field_media_video_file',
                      'field_media_audio_file', 'thumbnail'];

    foreach ($source_fields as $field_name) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $target_id = $entity->get($field_name)->target_id;
        if ($target_id) {
          $file = $this->entityTypeManager
            ->getStorage('file')
            ->load($target_id);
          if ($file) {
            return basename($file->getFileUri());
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Generate a slug for the media based on its name.
   */
  protected function getMediaSlug(EntityInterface $entity): string {
    $name = $entity->label() ?? 'media-' . $entity->id();
    return 'media-' . $entity->id() . '-' . $this->slugify($name);
  }

}
