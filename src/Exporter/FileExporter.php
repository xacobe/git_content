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
 *   uuid: a1b2c3d4
 *   uri: public://images/drupal.jpg
 *   status: permanent
 *   lang: en
 *   checksum: …
 *   ---
 */
class FileExporter extends BaseExporter {

  public function getEntityType(): string {
    return 'file';
  }

  protected function typeDir(): string {
    return 'files';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $markdown = $this->export($entity);

    $uri     = $entity->getFileUri();
    $path    = $this->stripStreamWrapper($uri);
    $subdir  = dirname($path);
    $dir     = $this->contentExportDir() . '/files' . ($subdir !== '.' ? '/' . $subdir : '');
    $this->ensureDir($dir, $dryRun);

    $filename = $this->sanitizeFilename($entity->getFilename());
    $filepath = $dir . '/' . $filename . '.md';

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);

    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $frontmatter = [];
    $frontmatter['uuid']     = $entity->uuid();
    $frontmatter['type']     = 'file';

    $frontmatter['filename'] = $entity->getFilename();
    $frontmatter['path']     = $this->stripStreamWrapper($entity->getFileUri());
    $frontmatter['mime']     = $entity->getMimeType();
    $frontmatter['size']     = (int) $entity->getSize();

    $frontmatter['created']  = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['owner']    = $this->getAuthorName($entity);

    // Drupal-internal: full URI (with stream wrapper), file status and language.
    $frontmatter['uri']    = $entity->getFileUri();
    $frontmatter['status'] = $entity->isPermanent() ? 'permanent' : 'temporary';
    $frontmatter['lang']   = $entity->language()->getId();

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, '', ['uri', 'status', 'lang']);
    return $this->serializer->serialize($frontmatter);
  }

  /**
   * Sanitize the file name for use as part of the .md file name.
   */
  private function sanitizeFilename(string $filename): string {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    return preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
  }

}