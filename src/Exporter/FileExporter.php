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
 * Output structure:
 *   content_export/
 *     files/
 *       {fid}-{filename}.md
 *
 * Example frontmatter:
 *   ---
 *   uuid: a1b2c3d4
 *   type: file
 *   lang: en
 *   status: permanent
 *
 *   filename: drupal.jpg
 *   uri: public://images/drupal.jpg
 *   mime: image/jpeg
 *   size: 45231
 *
 *   created: 2026-01-15
 *   owner: admin
 *   ---
 */
class FileExporter extends BaseExporter {

  protected function typeDir(): string {
    return 'files';
  }

  /**
   * Export all managed files.
   *
   * @return string[] Generated file paths.
   */
  public function exportAll(): array {
    $storage = $this->entityTypeManager->getStorage('file');
    $fids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $files = [];

    foreach ($storage->loadMultiple($fids) as $file) {
      try {
        $result = $this->exportToFile($file);
        $files[] = is_array($result) ? $result['path'] : $result;
      }
      catch (\Exception $e) {
        $this->logger->error('FileExporter: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    $markdown = $this->export($entity);

    $dir = $this->contentExportDir() . '/' . $this->typeDir();
    $this->ensureDir($dir, $dryRun);

    $filename = $this->sanitizeFilename($entity->getFilename());
    $filepath = $dir . '/' . $entity->id() . '-' . $filename . '.md';

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);

    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    // Resolve owner name
    $owner_name = NULL;
    $owner_id = $entity->getOwnerId();
    if ($owner_id) {
      $owner = $this->entityTypeManager->getStorage('user')->load($owner_id);
      $owner_name = $owner ? $owner->getAccountName() : NULL;
    }

    $frontmatter = [];
    $frontmatter['uuid']   = $entity->uuid();
    $frontmatter['type']   = 'file';
    $frontmatter['lang']   = $entity->language()->getId();
    $frontmatter['status'] = $entity->isPermanent() ? 'permanent' : 'temporary';
    $frontmatter['_']      = NULL;

    $frontmatter['filename'] = $entity->getFilename();
    $frontmatter['uri']      = $entity->getFileUri();
    $frontmatter['mime']     = $entity->getMimeType();
    $frontmatter['size']     = (int) $entity->getSize();
    $frontmatter['__']       = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['owner']   = $owner_name;

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, '');
    return $this->serializer->serialize($frontmatter);
  }

  /**
   * Sanitize the file name for use as part of the .md file name.
   */
  protected function sanitizeFilename(string $filename): string {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    return preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
  }

}