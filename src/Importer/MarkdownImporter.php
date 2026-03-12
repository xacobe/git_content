<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\SummaryTrait;
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
  use ContentExportTrait;
  use SummaryTrait;

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
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * Import all .md files from content_export/.
   *
   * @return array{imported: string[], updated: string[], skipped: string[], deleted: string[], errors: string[]}
   */
  public function importAll(): array {
    return $this->runAll(FALSE);
  }

  /**
   * Preview what importAll() would do without modifying any data.
   *
   * Returns the same array shape as importAll() but no entities are created,
   * updated, or deleted. Uses checksum comparison and UUID lookups only.
   *
   * @return array{imported: string[], updated: string[], skipped: string[], deleted: string[], errors: string[]}
   */
  public function previewAll(): array {
    return $this->runAll(TRUE);
  }

  /**
   * Import (or preview) a single Markdown file.
   *
   * @param bool $dryRun
   *   When TRUE, determines the operation without saving anything.
   *
   * @return array{op: string, entity_type: string, type: string, uuid?: string|null, bundle?: string|null}
   *   'op' is one of 'imported', 'updated', 'skipped'.
   *
   * @throws \Exception
   */
  public function importFile(string $filepath, bool $dryRun = FALSE): array {
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

    // Check whether the entity already exists in Drupal.
    $exists = $short_uuid && !empty(
      $this->entityTypeManager->getStorage($entity_type)
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $short_uuid . '%', 'LIKE')
        ->range(0, 1)
        ->execute()
    );

    // Checksum match AND entity exists in DB → nothing to do.
    $checksum = $frontmatter['checksum'] ?? NULL;
    if ($exists && $checksum && $this->computeChecksum($frontmatter, $body) === $checksum) {
      return ['op' => 'skipped', 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $short_uuid, 'bundle' => $bundle];
    }

    if ($dryRun) {
      $op = $exists ? 'updated' : 'imported';
    }
    else {
      $op = match ($type) {
        'file'              => $this->fileEntityImporter->import($frontmatter, $body),
        'user'              => $this->userImporter->import($frontmatter, $body),
        'taxonomy_term'     => $this->taxonomyImporter->import($frontmatter, $body),
        'media'             => $this->mediaImporter->import($frontmatter, $body),
        'block_content'     => $this->blockContentImporter->import($frontmatter, $body),
        'menu_link_content' => $this->menuLinkImporter->import($frontmatter, $body),
        default             => $this->nodeImporter->import($frontmatter, $body),
      };
    }

    return ['op' => $op, 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $short_uuid, 'bundle' => $bundle];
  }

  // ---------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------

  /**
   * Core loop shared by importAll() and previewAll().
   *
   * @param bool $dryRun
   *   When TRUE, no entities are saved or deleted, and no log entry is written.
   *
   * @return array{imported: string[], updated: string[], skipped: string[], deleted: string[], errors: string[]}
   */
  private function runAll(bool $dryRun): array {
    $result = ['imported' => [], 'updated' => [], 'deleted' => [], 'skipped' => [], 'errors' => []];

    if (!is_dir($this->contentExportDir())) {
      $result['errors'][] = t('The content_export directory does not exist.');
      return $result;
    }

    $files = $this->scanMarkdownFiles();
    usort($files, fn($a, $b) => $this->compareImportFiles($a, $b));

    $typeCounts = [];
    $seenUuids  = [
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
        $import      = $this->importFile($filepath, $dryRun);
        $op          = $import['op'];
        $type        = $import['type'];
        $entity_type = $import['entity_type'] ?? NULL;
        $uuid        = $import['uuid'] ?? NULL;
        $bundle      = $import['bundle'] ?? '__all';

        $result[$op][] = str_replace($this->contentExportDir() . '/', '', $filepath);

        if (!$dryRun) {
          if (!isset($typeCounts[$type])) {
            $typeCounts[$type] = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
          }
          // Map op 'imported' → 'created' to match the display label.
          $countKey = $op === 'imported' ? 'created' : $op;
          if (isset($typeCounts[$type][$countKey])) {
            $typeCounts[$type][$countKey]++;
          }
        }

        if ($entity_type && $uuid) {
          $seenUuids[$entity_type][$bundle][$uuid] = TRUE;
        }
      }
      catch (\Exception $e) {
        $result['errors'][] = basename($filepath) . ': ' . $e->getMessage();
      }
    }

    foreach ($this->syncDeletedEntities($seenUuids, !$dryRun) as $item) {
      $result['deleted'][] = $item;

      if (!$dryRun) {
        $parts = explode(':', $item, 2);
        if (count($parts) === 2) {
          $deletedType = trim($parts[0]);
          if (!isset($typeCounts[$deletedType])) {
            $typeCounts[$deletedType] = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
          }
          $typeCounts[$deletedType]['deleted']++;
        }
      }
    }

    if (!$dryRun) {
      $this->logger->notice(
        'Import finished: @summary. Total: @created created, @updated updated, @skipped skipped, @deleted deleted, @errors errors.',
        [
          '@summary' => $this->buildTypeSummary($typeCounts),
          '@created' => (string) count($result['imported']),
          '@updated' => (string) count($result['updated']),
          '@skipped' => (string) count($result['skipped']),
          '@deleted' => (string) count($result['deleted']),
          '@errors'  => (string) count($result['errors']),
        ]
      );
    }

    return $result;
  }

  /**
   * Find entities not present in seenUuids and optionally delete them.
   *
   * @param bool $delete
   *   When FALSE (dry run) returns the list without deleting anything.
   *
   * @return string[] Human-readable list of affected entities.
   */
  private function syncDeletedEntities(array $seenUuids, bool $delete): array {
    $affected = [];

    foreach ($seenUuids as $entity_type => $bundles) {
      // Only sync entity types we deliberately manage.
      if (!in_array($entity_type, ['node', 'taxonomy_term', 'media', 'block_content', 'menu_link_content', 'file', 'user'], TRUE)) {
        continue;
      }
      // If no files were seen for this type, skip deletion to avoid wiping
      // everything when a type is simply not in the export.
      if (empty($bundles)) {
        continue;
      }

      $storage = $this->entityTypeManager->getStorage($entity_type);

      foreach ($bundles as $bundle => $uuids) {
        $query = $storage->getQuery()->accessCheck(FALSE);

        switch ($entity_type) {
          case 'node':             $query->condition('type', $bundle); break;
          case 'taxonomy_term':    $query->condition('vid', $bundle); break;
          case 'media':            $query->condition('bundle', $bundle); break;
          case 'block_content':    $query->condition('type', $bundle); break;
          case 'menu_link_content': $query->condition('menu_name', $bundle); break;
        }

        foreach ($storage->loadMultiple($query->execute()) as $entity) {
          $uuid = substr(str_replace('-', '', $entity->uuid()), 0, 8);
          if (isset($uuids[$uuid])) {
            continue;
          }
          // Never touch the admin user or the currently logged-in user.
          if ($entity_type === 'user' && ($entity->id() === 1 || $entity->id() === $this->currentUser->id())) {
            continue;
          }

          $label      = method_exists($entity, 'label') ? $entity->label() : $entity->id();
          $affected[] = "$entity_type:$bundle: $label ($uuid)";

          if ($delete) {
            $entity->delete();
          }
        }
      }
    }

    return $affected;
  }

  // ---------------------------------------------------------------------------
  // Import sort
  // ---------------------------------------------------------------------------

  /**
   * Dependency order for import: earlier = imported first.
   *
   * Dependencies:
   *   media        → file
   *   node         → taxonomy_term, block_content, media, file
   *   menu_link_content → node (links point to node paths)
   */
  private const IMPORT_ORDER = [
    'file'              => 1,
    'user'              => 2,
    'taxonomy_term'     => 3,
    'block_content'     => 4,
    'media'             => 5,
    'node'              => 6,
    'menu_link_content' => 7,
  ];

  /**
   * Compare two import files to guarantee a stable, dependency-aware order.
   *
   * Entity types are sorted by IMPORT_ORDER so dependencies are always present
   * before the entities that reference them. Within menu_link_content, items
   * are further sorted by menu name and weight (parents before children).
   * Within the same entity type, files are sorted alphabetically.
   */
  protected function compareImportFiles(string $a, string $b): int {
    $metaA = $this->getImportFileMeta($a);
    $metaB = $this->getImportFileMeta($b);

    $orderA = self::IMPORT_ORDER[$metaA['entity_type']] ?? 6;
    $orderB = self::IMPORT_ORDER[$metaB['entity_type']] ?? 6;

    if ($orderA !== $orderB) {
      return $orderA <=> $orderB;
    }

    // Within menu_link_content: sort by menu, then by weight (parents first).
    if ($metaA['entity_type'] === 'menu_link_content') {
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

    $empty = ['type' => '', 'entity_type' => 'node', 'menu' => '', 'weight' => 0];

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
    $type        = $frontmatter['type'] ?? '';
    $entity_type = match ($type) {
      'taxonomy_term', 'file', 'user', 'media', 'block_content', 'menu_link_content' => $type,
      default => 'node',
    };

    return $cache[$filepath] = [
      'type'        => $type,
      'entity_type' => $entity_type,
      'menu'        => $frontmatter['menu'] ?? '',
      'weight'      => (int) ($frontmatter['weight'] ?? 0),
    ];
  }

}
