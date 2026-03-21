<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates file entities (metadata only) from Markdown frontmatter.
 *
 * The physical file must already exist in sites/default/files/.
 * This class registers or updates the entity in the managed_file table.
 */
class FileEntityImporter extends BaseImporter {

  public function handles(string $entity_type): bool {
    return $entity_type === 'file';
  }

  public function import(array $frontmatter, string $body): string {
    $uuid = $frontmatter['uuid'] ?? NULL;
    $uri        = $frontmatter['uri'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$uri) {
      throw new \Exception(t("The file frontmatter is missing 'uri'."));
    }

    // Look up by UUID first, then fall back to URI.
    $existing = $uuid ? $this->findByUuid($uuid, 'file') : NULL;

    if (!$existing) {
      $existing_files = $this->entityTypeManager->getStorage('file')
        ->loadByProperties(['uri' => $uri]);
      $existing = !empty($existing_files) ? reset($existing_files) : NULL;
    }

    if ($existing) {
      $file = $existing;
      $operation = 'updated';
    }
    else {
      $file = $this->entityTypeManager->getStorage('file')->create([
        'langcode' => $langcode,
        'uuid'     => $uuid ?? $this->uuid->generate(),
      ]);
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
