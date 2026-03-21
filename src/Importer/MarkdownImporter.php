<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Exporter\MarkdownExporter;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\SummaryTrait;
use Drupal\Core\Cache\CacheBackendInterface;
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
    protected MarkdownExporter $exporter,
    protected CacheBackendInterface $defaultCache,
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
    $rawFrontmatter = $parsed['frontmatter'];
    $frontmatter = $this->serializer->flattenGroups($rawFrontmatter);
    $body        = $parsed['body'];

    $type = $frontmatter['type'] ?? NULL;
    if (!$type) {
      throw new \Exception(t("The frontmatter is missing the 'type' field."));
    }

    $uuid        = $frontmatter['uuid'] ?? NULL;
    $entity_type = $this->resolveEntityType($type);

    $bundle = match ($entity_type) {
      'taxonomy_term'     => $frontmatter['vocabulary'] ?? NULL,
      'node'              => $type,
      'media',
      'block_content'     => $frontmatter['bundle'] ?? NULL,
      'menu_link_content' => $frontmatter['menu'] ?? NULL,
      default             => NULL,
    };

    // Check whether this specific language version of the entity already exists.
    // The langcode condition prevents treating an existing English node as a
    // match when importing its Spanish translation (same UUID, different lang).
    $langcode   = $frontmatter['lang'] ?? NULL;
    $existsQuery = $this->entityTypeManager->getStorage($entity_type)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid);
    if ($langcode && $langcode !== 'und') {
      $existsQuery->condition('langcode', $langcode);
    }
    $exists = $uuid && !empty($existsQuery->range(0, 1)->execute());

    // For file entities, look up ALL entities sharing the same URI. Drupal can
    // create multiple file entities for the same physical file (e.g. Umami demo
    // creates two per image). We track every sibling UUID so syncDeletedEntities
    // does not delete entities that we simply did not have a .md file for.
    // When the UUID is not found, we also use the first result as the entity to
    // update (URI fallback for fresh installs with different UUIDs).
    $actual_uuid    = $uuid;
    $sibling_uuids  = [];
    $found_by_uri   = FALSE;
    if ($entity_type === 'file') {
      $uri = $frontmatter['uri'] ?? NULL;
      if ($uri) {
        $existing_files = $this->entityTypeManager->getStorage('file')
          ->loadByProperties(['uri' => $uri]);
        if (!empty($existing_files)) {
          $sibling_uuids = array_map(fn($f) => $f->uuid(), array_values($existing_files));
          if (!$exists) {
            $actual_uuid  = reset($existing_files)->uuid();
            $found_by_uri = TRUE;
          }
        }
      }
    }

    // Checksum match AND entity exists in DB → skip, unless any referenced
    // entity has been deleted (e.g. media re-imported with a new entity ID).
    $checksum = $frontmatter['checksum'] ?? NULL;
    if ($exists && $checksum && $this->computeChecksum($frontmatter, $body) === $checksum) {
      if (!$this->hasStaleReferences($rawFrontmatter)) {
        return ['op' => 'skipped', 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $uuid, 'actual_uuid' => $uuid, 'sibling_uuids' => $sibling_uuids, 'bundle' => $bundle];
      }
    }

    if ($dryRun) {
      $op = ($exists || $found_by_uri) ? 'updated' : 'imported';
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

    return ['op' => $op, 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $uuid, 'actual_uuid' => $actual_uuid, 'sibling_uuids' => $sibling_uuids, 'bundle' => $bundle];
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
        // actual_uuid may differ from uuid when a file entity was found by URI
        // rather than by UUID (e.g. after a fresh Drupal install with demo content).
        // Using the actual DB uuid prevents syncDeletedEntities from deleting it.
        $actual_uuid = $import['actual_uuid'] ?? $uuid;
        $bundle      = $import['bundle'] ?? '__all';

        $result[$op][] = str_replace($this->contentExportDir() . '/', '', $filepath);

        // Newly imported entities may have a different entity ID than the one
        // encoded in the source file's slug (e.g. media-22-... vs media-64-...).
        // Re-export to the canonical path and delete the stale source file.
        // Only needed for 'imported': updated/skipped entities already have
        // the correct ID and path.
        if ($op === 'imported' && $actual_uuid && $entity_type && !$dryRun) {
          $canonicalPaths = $this->exporter->exportEntityByUuid($actual_uuid, $entity_type);
          if (!empty($canonicalPaths) && !in_array($filepath, $canonicalPaths)) {
            @unlink($filepath);
            $result['deleted'][] = str_replace($this->contentExportDir() . '/', '', $filepath);
          }
        }

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

        // Track the primary entity UUID plus any file entity siblings sharing
        // the same URI (e.g. Umami creates multiple file entities per image).
        $tracked = array_filter(array_unique(array_merge([$actual_uuid], $import['sibling_uuids'] ?? [])));
        foreach ($tracked as $tracked_uuid) {
          if ($entity_type && $tracked_uuid) {
            $seenUuids[$entity_type][$bundle][$tracked_uuid] = TRUE;
          }
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
      // BlockContentUuidLookup is a CacheCollector that stores a persistent
      // cache entry ('block_content_uuid') without cache tags, so it is NOT
      // automatically invalidated when block_content entities are deleted or
      // recreated (e.g. after a fresh install with different entity IDs).
      // We clear it explicitly whenever any block_content entity was touched
      // so the next page request re-resolves UUIDs → entity IDs from the DB.
      if (!empty($seenUuids['block_content'])) {
        $this->defaultCache->delete('block_content_uuid');
      }

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
          if (isset($uuids[$entity->uuid()])) {
            continue;
          }
          // Never touch the admin user or the currently logged-in user.
          if ($entity_type === 'user' && ($entity->id() === 1 || $entity->id() === $this->currentUser->id())) {
            continue;
          }

          $label      = method_exists($entity, 'label') ? $entity->label() : $entity->id();
          $affected[] = "$entity_type:$bundle: $label ({$entity->uuid()})";

          if ($delete) {
            $entity->delete();
          }
        }
      }
    }

    return $affected;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function resolveEntityType(string $type): string {
    return match ($type) {
      'taxonomy_term', 'file', 'user', 'media', 'block_content', 'menu_link_content' => $type,
      default => 'node',
    };
  }

  // ---------------------------------------------------------------------------
  // Import sort
  // ---------------------------------------------------------------------------

  /**
   * Dependency order for import: earlier = imported first.
   *
   * Dependencies:
   *   media        → file
   *   block_content → media (blocks can reference media entities)
   *   node         → taxonomy_term, block_content, media, file
   *   menu_link_content → node (links point to node paths)
   */
  private const IMPORT_ORDER = [
    'file'              => 1,
    'user'              => 2,
    'taxonomy_term'     => 3,
    'media'             => 4,
    'block_content'     => 5,
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

    // Default translations (no translation_of) before non-default translations,
    // so the entity is created with the correct original language first.
    if ($metaA['is_translation'] !== $metaB['is_translation']) {
      return $metaA['is_translation'] <=> $metaB['is_translation'];
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

    if (!is_file($filepath) || !is_readable($filepath)) {
      return $cache[$filepath] = $empty;
    }
    $raw = file_get_contents($filepath);

    try {
      $parsed = $this->serializer->deserialize($raw);
    }
    catch (\Exception $e) {
      return $cache[$filepath] = $empty;
    }

    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);
    $type        = $frontmatter['type'] ?? '';
    $entity_type = $this->resolveEntityType($type);

    return $cache[$filepath] = [
      'type'           => $type,
      'entity_type'    => $entity_type,
      'menu'           => $frontmatter['menu'] ?? '',
      'weight'         => (int) ($frontmatter['weight'] ?? 0),
      'is_translation' => !empty($frontmatter['translation_of']) ? 1 : 0,
    ];
  }

  /**
   * Check whether any entity reference in the frontmatter points to a deleted
   * entity, making the stored checksum stale even though the file is unchanged.
   *
   * Only inspects the `references` group (media and node UUIDs).
   * A stale reference means the cached entity ID on disk no longer exists in
   * the database, so the entity must be re-imported to update the reference.
   */
  private function hasStaleReferences(array $frontmatter): bool {
    $references = $frontmatter['references'] ?? [];
    if (empty($references)) {
      return FALSE;
    }

    // Collect all UUIDs from references (scalar or array values).
    $uuids = [];
    foreach ($references as $value) {
      foreach ((array) $value as $candidate) {
        if (is_string($candidate) && strlen($candidate) === 36) {
          $uuids[$candidate] = TRUE;
        }
      }
    }

    if (empty($uuids)) {
      return FALSE;
    }

    // Two IN queries (one per entity type) instead of 2×N individual queries.
    // Drupal UUIDs are globally unique, so each UUID appears in at most one
    // storage. If the total found count equals the number of UUIDs, all
    // references are intact.
    $all     = array_keys($uuids);
    $found   = 0;
    foreach (['media', 'node'] as $entity_type) {
      $found += count(
        $this->entityTypeManager->getStorage($entity_type)
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('uuid', $all, 'IN')
          ->execute()
      );
    }

    return $found < count($uuids);
  }

}
