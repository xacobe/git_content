<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Export Drupal file entities to Markdown.
 *
 * Only metadata is exported for the file entity (URI, name, mime type,
 * owner, timestamps). The binary file itself must be managed separately
 * (git-lfs, rsync, etc.) since it can be too large for a Git repository.
 *
 * Output structure mirrors sites/default/files/:
 *   content_export/
 *     files/
 *       {subdir}/
 *         {filename}.md
 *
 * Example frontmatter:
 *   ---
 *   type: file
 *
 *   filename: drupal.jpg
 *   path: images/drupal.jpg
 *   mime: image/jpeg
 *   size: 45231
 *
 *   created: 2026-01-15
 *   owner: admin
 *
 *   # Drupal
 *   fid: 42
 *   uri: public://images/drupal.jpg
 *   status: permanent
 *   checksum: …
 *   ---
 */
class FileExporter extends BaseExporter {

  public function getEntityType(): string {
    return 'file';
  }

  public function getCliName(): string {
    return 'files';
  }

  protected function typeDir(): string {
    return 'files';
  }

  /**
   * URI prefixes for Drupal-generated cache files that must never be exported.
   *
   * These files are regenerated automatically by Drupal on page render and
   * are not real content — exporting them causes permanent "Would write" noise
   * in the sync form because Drupal recreates them after every import.
   */
  private const SKIP_URI_PREFIXES = [
    'public://styles/',          // Image style derivatives — regenerated on render.
    'public://oembed_thumbnails/', // oEmbed thumbnails — fetched/cached by Drupal.
    'public://media-icons/',     // Media type icons — bundled with Drupal core.
  ];

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $uri = $entity->getFileUri();

    foreach (self::SKIP_URI_PREFIXES as $prefix) {
      if (str_starts_with($uri, $prefix)) {
        return ['path' => '', 'skipped' => TRUE];
      }
    }

    $markdown = $this->export($entity);

    $uri     = $entity->getFileUri();
    $path    = $this->stripStreamWrapper($uri);
    $subdir  = dirname($path);
    $dir     = $this->contentExportDir() . '/files' . ($subdir !== '.' ? '/' . $subdir : '');
    $this->ensureDir($dir, $dryRun);

    // Use the full URI-path basename (including extension, sanitized) to
    // guarantee uniqueness even when two files share the same stem but differ
    // by extension (e.g. photo.jpg vs photo.jpeg → photo-jpg.md vs photo-jpeg.md).
    $filename = $this->sanitizeFilename(pathinfo($path, PATHINFO_BASENAME));
    $filepath = $dir . '/' . $filename . '.md';

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);

    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $frontmatter = [];
    $frontmatter['type']     = 'file';

    $frontmatter['filename'] = $entity->getFilename();
    $frontmatter['path']     = $this->stripStreamWrapper($entity->getFileUri());
    $frontmatter['mime']     = $entity->getMimeType();
    $frontmatter['size']     = (int) $entity->getSize();

    $frontmatter['created']  = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['owner']    = $this->getAuthorName($entity);

    // Drupal-internal: full URI (with stream wrapper) and file status.
    // Language is omitted: file entities are language-neutral ('und') and
    // $entity->language()->getId() falls back to the site default on multilingual
    // sites, which would produce a wrong langcode in the frontmatter.
    $frontmatter['fid']    = (int) $entity->id();
    $frontmatter['uri']    = $entity->getFileUri();
    $frontmatter['status'] = $entity->isPermanent() ? 'permanent' : 'temporary';

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, '', ['uri', 'status']);
    return $this->serializer->serialize($frontmatter);
  }

  /**
   * Sanitize a full filename (including extension) for use as a .md stem.
   *
   * Non-alphanumeric characters (including dots) become hyphens, so
   * photo.jpg → photo-jpg and photo.jpeg → photo-jpeg.
   */
  private function sanitizeFilename(string $filename): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($filename)), '-');
  }

}