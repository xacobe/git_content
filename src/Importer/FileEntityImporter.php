<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates file entities (metadata only) from Markdown frontmatter.
 *
 * The physical file must already exist in sites/default/files/.
 * This class registers or updates the entity in the managed_file table.
 */
class FileEntityImporter extends BaseImporter {

  public function getEntityType(): ?string {
    return 'file';
  }

  public function getImportWeight(): int {
    return 10;
  }

  public function extractEntityId(array $frontmatter): ?int {
    return !empty($frontmatter['fid']) ? (int) $frontmatter['fid'] : NULL;
  }

  public function resolveBundle(array $frontmatter): ?string {
    return NULL;
  }

  public function getBundleQueryField(): ?string {
    return NULL;
  }

  public function import(array $frontmatter, string $body): string {
    $fid        = !empty($frontmatter['fid']) ? (int) $frontmatter['fid'] : NULL;
    $uri        = $frontmatter['uri'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$uri) {
      throw new \Exception($this->t("The file frontmatter is missing 'uri'."));
    }

    // Look up by fid first, then fall back to URI.
    $existing = $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
    $existing ??= $this->loadOneByProperty('file', 'uri', $uri);

    if ($existing) {
      $file = $existing;
      $operation = 'updated';
    }
    else {
      $create = ['langcode' => $langcode];
      $this->preserveEntityId('file', 'fid', 'fid', $create, $frontmatter);
      $file = $this->entityTypeManager->getStorage('file')->create($create);
      $operation = 'imported';
    }

    $file->set('filename', $frontmatter['filename'] ?? basename($uri));
    $file->set('uri', $uri);
    $file->set('filemime', $frontmatter['mime'] ?? 'application/octet-stream');
    $file->set('filesize', (int) ($frontmatter['size'] ?? 0));
    $file->set('status', ($frontmatter['status'] ?? 'permanent') === 'permanent' ? 1 : 0);

    if (!empty($frontmatter['created'])) {
      $file->set('created', $this->parseDate($frontmatter['created']));
    }

    // Resolve owner by username.
    if (!empty($frontmatter['owner'])) {
      $uid = $this->findUserByName($frontmatter['owner']);
      if ($uid) {
        $file->set('uid', $uid);
      }
    }

    $file->save();

    return $operation;
  }

}
