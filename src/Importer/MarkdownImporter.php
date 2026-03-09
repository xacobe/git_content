<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the import of all .md files from content_export/.
 *
 * Parses each file, detects its entity type from the frontmatter and delegates
 * to the appropriate concrete importer (NodeImporter, TaxonomyImporter, etc.).
 *
 * Supported types:
 *   - Nodes:             type: {bundle}  (article, page, project…)
 *   - Taxonomy terms:    type: taxonomy_term
 *   - Media:             type: media
 *   - Files:             type: file
 *   - Users:             type: user
 *   - Custom blocks:     type: block_content
 *   - Menu links:        type: menu_link_content
 */
class MarkdownImporter {

  use ChecksumTrait;

  protected LoggerInterface $logger;

  public function __construct(
    protected MarkdownSerializer $serializer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
    protected NodeImporter $nodeImporter,
    protected TaxonomyImporter $taxonomyImporter,
    protected MediaImporter $mediaImporter,
    protected FileEntityImporter $fileEntityImporter,
    protected UserImporter $userImporter,
    protected BlockContentImporter $blockContentImporter,
    protected MenuLinkImporter $menuLinkImporter,
  ) {
    $this->logger = $loggerFactory->get('git_content');
  }

  // ---------------------------------------------------------------------------
  // Bulk import
  // ---------------------------------------------------------------------------

  /**
   * Import all .md files from content_export/.
   *
   * @return array{imported: string[], updated: string[], skipped: string[], deleted: string[], errors: string[]}
   */
  public function importAll(): array {
    $import_dir = DRUPAL_ROOT . '/content_export';
    $result = ['imported' => [], 'updated' => [], 'deleted' => [], 'skipped' => [], 'errors' => []];

    if (!is_dir($import_dir)) {
      $result['errors'][] = t('The content_export directory does not exist.');
      return $result;
    }

    // Collect all .md files recursively.
    $files = $this->findMarkdownFiles($import_dir);

    // Ensure menu links are processed in weight order (parents before children).
    usort($files, fn($a, $b) => $this->compareImportFiles($a, $b));

    $typeCounts = [];
    $seenUuids = [
      'node'              => [],
      'taxonomy_term'     => [],
      'media'             => [],
      'block_content'     => [],
      'file'              => [],
      'user'              => [],
      'menu_link_content' => [],
    ];

    foreach ($files as $filepath) {
      try {
        $import = $this->importFile($filepath);
        $op          = $import['op'];
        $type        = $import['type'];
        $entity_type = $import['entity_type'] ?? NULL;
        $uuid        = $import['uuid'] ?? NULL;
        $bundle      = $import['bundle'] ?? '__all';

        $result[$op][] = str_replace(DRUPAL_ROOT . '/content_export/', '', $filepath);

        if (!isset($typeCounts[$type])) {
          $typeCounts[$type] = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
        }
        if (isset($typeCounts[$type][$op])) {
          $typeCounts[$type][$op]++;
        }

        if ($entity_type && $uuid) {
          $seenUuids[$entity_type][$bundle][$uuid] = TRUE;
        }
      }
      catch (\Exception $e) {
        $result['errors'][] = basename($filepath) . ': ' . $e->getMessage();
      }
    }

    // Remove Drupal entities that no longer have a corresponding .md file.
    $deleted = $this->cleanupDeletedEntities($seenUuids);
    foreach ($deleted as $deletedItem) {
      $result['deleted'][] = $deletedItem;
      $parts = explode(':', $deletedItem, 2);
      if (count($parts) === 2) {
        $deletedType = trim($parts[0]);
        if (!isset($typeCounts[$deletedType])) {
          $typeCounts[$deletedType] = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
        }
        $typeCounts[$deletedType]['deleted']++;
      }
    }

    // Log a summary of the import to watchdog.
    $created = count($result['imported']);
    $updated = count($result['updated']);
    $skipped = count($result['skipped']);
    $deleted = count($result['deleted']);
    $errors  = count($result['errors']);

    $parts = [];
    foreach ($typeCounts as $type => $counts) {
      $parts[] = "$type: {$counts['imported']} created, {$counts['updated']} updated, {$counts['skipped']} skipped";
    }

    $this->logger->notice(
      'Import finished: @summary. Total: @created created, @updated updated, @skipped skipped, @deleted deleted, @errors errors.',
      [
        '@summary' => implode('; ', $parts),
        '@created' => (string) $created,
        '@updated' => (string) $updated,
        '@skipped' => (string) $skipped,
        '@deleted' => (string) $deleted,
        '@errors'  => (string) $errors,
      ]
    );

    return $result;
  }

  /**
   * Import a single Markdown file.
   *
   * @return array{op: string, entity_type: string, type: string, uuid?: string|null, bundle?: string|null}
   *   'op' is one of 'imported', 'updated', 'skipped'.
   *
   * @throws \Exception
   */
  public function importFile(string $filepath): array {
    if (!file_exists($filepath)) {
      throw new \Exception(t('File not found: @file', ['@file' => $filepath]));
    }

    $raw         = file_get_contents($filepath);
    $parsed      = $this->serializer->deserialize($raw);
    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);
    $body        = $parsed['body'];

    $type = $frontmatter['type'] ?? NULL;
    if (!$type) {
      throw new \Exception(t("The frontmatter is missing the 'type' field."));
    }

    $short_uuid  = $frontmatter['uuid'] ?? NULL;
    $entity_type = match ($type) {
      'taxonomy_term'     => 'taxonomy_term',
      'file'              => 'file',
      'user'              => 'user',
      'media'             => 'media',
      'block_content'     => 'block_content',
      'menu_link_content' => 'menu_link_content',
      default             => 'node',
    };

    $bundle = match ($entity_type) {
      'taxonomy_term'     => $frontmatter['vocabulary'] ?? NULL,
      'node'              => $type,
      'media',
      'block_content'     => $frontmatter['bundle'] ?? NULL,
      'menu_link_content' => $frontmatter['menu_name'] ?? NULL,
      default             => NULL,
    };

    // If the file contains a checksum, compare it to skip unchanged files.
    $checksum = $frontmatter['checksum'] ?? NULL;
    if ($checksum) {
      $computed = $this->computeChecksum($frontmatter, $body);
      if ($computed === $checksum) {
        return ['op' => 'skipped', 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $short_uuid, 'bundle' => $bundle];
      }
    }

    $op = match ($type) {
      'file'              => $this->fileEntityImporter->import($frontmatter, $body),
      'user'              => $this->userImporter->import($frontmatter, $body),
      'taxonomy_term'     => $this->taxonomyImporter->import($frontmatter, $body),
      'media'             => $this->mediaImporter->import($frontmatter, $body),
      'block_content'     => $this->blockContentImporter->import($frontmatter, $body),
      'menu_link_content' => $this->menuLinkImporter->import($frontmatter, $body),
      default             => $this->nodeImporter->import($frontmatter, $body),
    };

    return ['op' => $op, 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $short_uuid, 'bundle' => $bundle];
  }

  // ---------------------------------------------------------------------------
  // Cleanup
  // ---------------------------------------------------------------------------

  /**
   * Delete Drupal entities that no longer have a corresponding .md file.
   *
   * @param array $seenUuids
   *   Map of entity_type -> bundle -> short_uuid => TRUE representing the
   *   UUIDs of entities imported/updated in this run.
   *
   * @return string[] List of deleted items (for display in the UI).
   */
  protected function cleanupDeletedEntities(array $seenUuids): array {
    $deleted = [];

    foreach ($seenUuids as $entity_type => $bundles) {
      // We safely sync the following entity types:
      // - nodes/taxonomy/media/block_content/menu_link_content: yes.
      // - file: yes (those referenced by the export).
      // - user: only if not the admin or the current user.
      if (!in_array($entity_type, ['node', 'taxonomy_term', 'media', 'block_content', 'menu_link_content', 'file', 'user'], TRUE)) {
        continue;
      }

      // If there are no files for this type, do not delete anything.
      if (empty($bundles)) {
        continue;
      }

      $storage = $this->entityTypeManager->getStorage($entity_type);

      foreach ($bundles as $bundle => $uuids) {
        $query = $storage->getQuery()->accessCheck(FALSE);

        switch ($entity_type) {
          case 'node':
            $query->condition('type', $bundle);
            break;
          case 'taxonomy_term':
            $query->condition('vid', $bundle);
            break;
          case 'media':
            $query->condition('bundle', $bundle);
            break;
          case 'block_content':
            $query->condition('type', $bundle);
            break;
          case 'menu_link_content':
            $query->condition('menu_name', $bundle);
            break;
        }

        $ids = $query->execute();
        foreach ($storage->loadMultiple($ids) as $entity) {
          $uuid = substr(str_replace('-', '', $entity->uuid()), 0, 8);
          if (isset($uuids[$uuid])) {
            continue;
          }

          // Do not delete the admin user or the currently logged-in user.
          if ($entity_type === 'user') {
            if ($entity->id() === 1 || $entity->id() === $this->currentUser->id()) {
              continue;
            }
          }

          $label = method_exists($entity, 'label') ? $entity->label() : $entity->id();
          $deleted[] = "$entity_type:$bundle: $label ($uuid)";
          $entity->delete();
        }
      }
    }

    return $deleted;
  }

  // ---------------------------------------------------------------------------
  // Checksum
  // ---------------------------------------------------------------------------

  /**
   * Compute the canonical checksum used to detect changes.
   *
   * Based on the logical data structure (frontmatter + body), not on the
   * specific YAML representation. This allows stable change detection even
   * when YAML formatting changes.
   */
  protected function computeChecksum(array $frontmatter, string $body): string {
    $fm = $frontmatter;
    unset($fm['checksum']);
    $fm = array_filter($fm, fn($key) => !preg_match('/^_+$/', (string) $key), ARRAY_FILTER_USE_KEY);
    $data = $this->canonicalizeForHash(['frontmatter' => $fm, 'body' => $body]);

    return sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
  }

  // ---------------------------------------------------------------------------
  // File utilities
  // ---------------------------------------------------------------------------

  /**
   * Recursively find all .md files in a directory.
   *
   * @return string[]
   */
  protected function findMarkdownFiles(string $dir): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'md') {
        $files[] = $file->getRealPath();
      }
    }
    return $files;
  }

  /**
   * Compare two import files to guarantee a stable sort order.
   *
   * For menu_link_content sorts by menu and weight (parents before children).
   * For all other types sorts by filename.
   */
  protected function compareImportFiles(string $a, string $b): int {
    $metaA = $this->getImportFileMeta($a);
    $metaB = $this->getImportFileMeta($b);

    if ($metaA['type'] === 'menu_link_content' && $metaB['type'] === 'menu_link_content') {
      if ($metaA['menu'] !== $metaB['menu']) {
        return $metaA['menu'] <=> $metaB['menu'];
      }
      return $metaA['weight'] <=> $metaB['weight'];
    }

    return $a <=> $b;
  }

  protected function getImportFileMeta(string $filepath): array {
    // Cache results so each file is read and parsed at most once.
    // usort calls the comparator O(N log N) times; without this cache every
    // file would be deserialized on each comparison.
    static $cache = [];

    if (array_key_exists($filepath, $cache)) {
      return $cache[$filepath];
    }

    $empty = ['type' => '', 'menu' => '', 'weight' => 0];

    $raw = @file_get_contents($filepath);
    if ($raw === FALSE) {
      return $cache[$filepath] = $empty;
    }

    try {
      $parsed = $this->serializer->deserialize($raw);
    }
    catch (\Exception $e) {
      return $cache[$filepath] = $empty;
    }

    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);

    return $cache[$filepath] = [
      'type'   => $frontmatter['type'] ?? '',
      'menu'   => $frontmatter['menu'] ?? '',
      'weight' => (int) ($frontmatter['weight'] ?? 0),
    ];
  }

}
