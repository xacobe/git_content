<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Exporter\MarkdownExporter;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\SummaryTrait;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

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
  use StringTranslationTrait;

  protected LoggerInterface $logger;

  private array $importFileMetaCache = [];

  /** @var array<string, ImporterInterface> entity_type => importer */
  private array $importerMap = [];

  /** @var array<string, int> entity_type => weight */
  private array $importWeights = [];

  private ?ImporterInterface $catchAllImporter = NULL;

  public function __construct(
    protected MarkdownSerializer $serializer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
    iterable $importers,
    protected MarkdownExporter $exporter,
    protected CacheBackendInterface $defaultCache,
    protected ConfigFactoryInterface $configFactory,
    protected AliasManagerInterface $aliasManager,
  ) {
    $this->logger = $loggerFactory->get('git_content');

    foreach ($importers as $importer) {
      $type = $importer->getEntityType();
      if ($type === NULL) {
        $this->catchAllImporter = $importer;
      }
      else {
        $this->importerMap[$type] = $importer;
        $this->importWeights[$type] = $importer->getImportWeight();
      }
    }

    // The catch-all handles 'node' (and any unrecognised type).
    if ($this->catchAllImporter) {
      $this->importWeights['node'] = $this->catchAllImporter->getImportWeight();
    }
  }

  private function getImporterForType(string $entity_type): ImporterInterface {
    if (isset($this->importerMap[$entity_type])) {
      return $this->importerMap[$entity_type];
    }
    if ($this->catchAllImporter) {
      return $this->catchAllImporter;
    }
    throw new \RuntimeException("No importer registered for entity type: $entity_type");
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
   * updated, or deleted. Uses checksum comparison and entity ID lookups only.
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
   * @return array{op: string, entity_type: string, type: string, entity_id?: int|null, bundle?: string|null}
   *   'op' is one of 'imported', 'updated', 'skipped'.
   *
   * @throws \Exception
   */
  public function importFile(string $filepath, bool $dryRun = FALSE): array {
    if (!file_exists($filepath)) {
      throw new \Exception($this->t('File not found: @file', ['@file' => $filepath]));
    }

    $raw         = file_get_contents($filepath);
    $parsed      = $this->serializer->deserialize($raw);
    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);
    $body        = $parsed['body'];

    $type = $frontmatter['type'] ?? NULL;
    if (!$type) {
      throw new \Exception($this->t("The frontmatter is missing the 'type' field."));
    }

    $entity_type = $this->resolveEntityType($type);
    $entity_id   = $this->extractEntityId($frontmatter, $entity_type);

    $importer = $this->getImporterForType($entity_type);
    $bundle   = $importer->resolveBundle($frontmatter);

    // Check whether this specific language version of the entity already exists.
    $langcode   = $frontmatter['lang'] ?? NULL;
    $exists     = FALSE;
    $actual_id  = $entity_id;
    if ($entity_id) {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if ($entity) {
        $exists = ($langcode && $langcode !== 'und')
          ? $entity->hasTranslation($langcode)
          : TRUE;
      }
    }

    // URI fallback for file entities: a file may already exist with the same
    // URI but a different fid (e.g. a leftover from demo content).
    $found_by_uri = FALSE;
    if ($entity_type === 'file' && !$exists) {
      $uri = $frontmatter['uri'] ?? NULL;
      if ($uri) {
        $existing = $this->entityTypeManager->getStorage('file')
          ->loadByProperties(['uri' => $uri]);
        if (!empty($existing)) {
          $actual_id    = (int) reset($existing)->id();
          $found_by_uri = TRUE;
        }
      }
    }

    // Checksum match AND entity exists in DB → skip.
    $checksum = $frontmatter['checksum'] ?? NULL;
    if ($exists && $checksum && $this->computeChecksum($frontmatter, $body) === $checksum) {
      return ['op' => 'skipped', 'entity_type' => $entity_type, 'type' => $type, 'entity_id' => $entity_id, 'actual_id' => $entity_id, 'bundle' => $bundle];
    }

    if ($dryRun) {
      $op = ($exists || $found_by_uri) ? 'updated' : 'imported';
    }
    else {
      $op = $this->getImporterForType($entity_type)->import($frontmatter, $body);
    }

    return ['op' => $op, 'entity_type' => $entity_type, 'type' => $type, 'entity_id' => $entity_id, 'actual_id' => $actual_id, 'bundle' => $bundle, 'lang' => $langcode];
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
      $result['errors'][] = $this->t('The content_export directory does not exist.');
      return $result;
    }

    $files = $this->scanMarkdownFiles();
    usort($files, fn($a, $b) => $this->compareImportFiles($a, $b));

    $typeCounts = [];
    $seenIds    = array_fill_keys(array_keys($this->importWeights), []);

    foreach ($files as $filepath) {
      try {
        $import      = $this->importFile($filepath, $dryRun);
        $op          = $import['op'];
        $type        = $import['type'];
        $entity_type = $import['entity_type'] ?? NULL;
        $entity_id   = $import['entity_id'] ?? NULL;
        // actual_id may differ from entity_id when a file entity was found by
        // URI rather than by fid (e.g. after a fresh install with demo content).
        $actual_id   = $import['actual_id'] ?? $entity_id;
        $bundle      = $import['bundle'] ?? '__all';
        $lang        = $import['lang'] ?? NULL;

        $result[$op][] = str_replace($this->contentExportDir() . '/', '', $filepath);

        // Re-export only this specific translation after import to normalise
        // the .md file on disk. Exporting all translations here would overwrite
        // sibling translation files (which are still pending import in this run)
        // with stale Drupal content, causing those translations to be skipped.
        // - 'imported': entity ID may differ from slug; delete stale source if path changed.
        // - 'updated': file was manually edited; re-export to update the checksum.
        if (in_array($op, ['imported', 'updated']) && $actual_id && $entity_type && !$dryRun) {
          $canonicalPaths = $this->exporter->exportEntityById($actual_id, $entity_type, FALSE, $lang);
          if ($op === 'imported' && !empty($canonicalPaths) && !in_array($filepath, $canonicalPaths)) {
            is_file($filepath) && unlink($filepath);
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

        if ($entity_type && $actual_id) {
          $seenIds[$entity_type][$bundle][$actual_id] = TRUE;
        }
      }
      catch (\Exception $e) {
        $result['errors'][] = basename($filepath) . ': ' . $e->getMessage();
      }
    }

    foreach ($this->syncDeletedEntities($seenIds, !$dryRun) as $item) {
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
      if (!empty($seenIds['block_content'])) {
        $this->defaultCache->delete('block_content_uuid');
      }

      // Restore the site front page from site.yaml.
      // site.yaml stores the path alias for portability (the node ID may change
      // after a DB reset). At this point all nodes and their aliases have been
      // imported, so we can resolve the alias back to the current internal path.
      // Drupal requires an internal path for page.front — using an alias causes
      // Drupal to redirect the visitor from / to the alias URL, which breaks the
      // <front> route and prevents page--front.html.twig from loading.
      $siteYamlPath = $this->contentExportDir() . '/site.yaml';
      if (is_file($siteYamlPath)) {
        $siteData   = Yaml::parseFile($siteYamlPath);
        $alias      = $siteData['front_page'] ?? NULL;
        if ($alias) {
          $internal = $this->aliasManager->getPathByAlias($alias);
          $this->configFactory->getEditable('system.site')
            ->set('page.front', $internal ?: $alias)
            ->save();
        }
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
   * Find entities not present in seenIds and optionally delete them.
   *
   * @param bool $delete
   *   When FALSE (dry run) returns the list without deleting anything.
   *
   * @return string[] Human-readable list of affected entities.
   */
  private function syncDeletedEntities(array $seenIds, bool $delete): array {
    $affected = [];

    foreach ($seenIds as $entity_type => $bundles) {
      // Only sync entity types we deliberately manage.
      if (!isset($this->importWeights[$entity_type])) {
        continue;
      }
      // If no files were seen for this type, skip deletion to avoid wiping
      // everything when a type is simply not in the export.
      if (empty($bundles)) {
        continue;
      }

      $storage  = $this->entityTypeManager->getStorage($entity_type);
      $importer = $this->getImporterForType($entity_type);

      foreach ($bundles as $bundle => $ids) {
        $query = $storage->getQuery()->accessCheck(FALSE);

        $bundle_field = $importer->getBundleQueryField();
        if ($bundle_field && $bundle !== '__all') {
          $query->condition($bundle_field, $bundle);
        }

        foreach ($storage->loadMultiple($query->execute()) as $entity) {
          if (isset($ids[(int) $entity->id()])) {
            continue;
          }
          // Never touch the anonymous user (uid=0), the admin (uid=1),
          // or the currently logged-in user.
          if ($entity_type === 'user') {
            $uid = (int) $entity->id();
            if ($uid === 0 || $uid === 1 || $uid === (int) $this->currentUser->id()) {
              continue;
            }
          }

          // Never delete permanent file entities that are not tracked in
          // seenIds. Drupal creates file entities dynamically (e.g. oembed
          // thumbnails) that have no .md file but are still referenced.
          // Deleting them leaves broken thumbnail__target_id references.
          if ($entity_type === 'file' && $entity->isPermanent()) {
            continue;
          }

          $label      = $entity->label() ?? (string) $entity->id();
          $affected[] = "$entity_type:$bundle: $label ({$entity->id()})";

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

  /**
   * Extract the entity's primary ID from frontmatter via the importer.
   */
  private function extractEntityId(array $frontmatter, string $entity_type): ?int {
    return $this->getImporterForType($entity_type)->extractEntityId($frontmatter);
  }

  /**
   * Resolve the frontmatter 'type' value to a Drupal entity type machine name.
   *
   * Non-node types use their frontmatter type directly (e.g. 'taxonomy_term').
   * Node bundle names ('article', 'page', ...) all map to 'node'.
   */
  private function resolveEntityType(string $type): string {
    return isset($this->importerMap[$type]) ? $type : 'node';
  }

  // ---------------------------------------------------------------------------
  // Import sort
  // ---------------------------------------------------------------------------

  /**
   * Compare two import files to guarantee a stable, dependency-aware order.
   *
   * Entity types are sorted by import weight so dependencies are always present
   * before the entities that reference them. Within menu_link_content, items
   * are further sorted by menu name and weight (parents before children).
   * Within the same entity type, files are sorted alphabetically.
   */
  private function compareImportFiles(string $a, string $b): int {
    $metaA = $this->getImportFileMeta($a);
    $metaB = $this->getImportFileMeta($b);

    return $this->compareByEntityWeight($metaA, $metaB)
        ?: $this->compareByTranslation($metaA, $metaB)
        ?: $this->compareByMenuHierarchy($metaA, $metaB)
        ?: $a <=> $b;
  }

  private function compareByEntityWeight(array $a, array $b): int {
    $default = $this->catchAllImporter?->getImportWeight() ?? 60;
    return ($this->importWeights[$a['entity_type']] ?? $default)
       <=> ($this->importWeights[$b['entity_type']] ?? $default);
  }

  private function compareByTranslation(array $a, array $b): int {
    return $a['is_translation'] <=> $b['is_translation'];
  }

  private function compareByMenuHierarchy(array $a, array $b): int {
    if ($a['entity_type'] !== 'menu_link_content') {
      return 0;
    }
    return ($a['menu'] <=> $b['menu']) ?: ($a['weight'] <=> $b['weight']);
  }

  private function getImportFileMeta(string $filepath): array {
    if (array_key_exists($filepath, $this->importFileMetaCache)) {
      return $this->importFileMetaCache[$filepath];
    }

    $empty = ['type' => '', 'entity_type' => 'node', 'menu' => '', 'weight' => 0, 'is_translation' => 0];

    if (!is_file($filepath) || !is_readable($filepath)) {
      return $this->importFileMetaCache[$filepath] = $empty;
    }
    $raw = file_get_contents($filepath);

    try {
      $parsed = $this->serializer->deserialize($raw);
    }
    catch (\Exception) {
      return $this->importFileMetaCache[$filepath] = $empty;
    }

    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);
    $type        = $frontmatter['type'] ?? '';
    $entity_type = $this->resolveEntityType($type);

    return $this->importFileMetaCache[$filepath] = [
      'type'           => $type,
      'entity_type'    => $entity_type,
      'menu'           => $frontmatter['menu'] ?? '',
      'weight'         => (int) ($frontmatter['weight'] ?? 0),
      'is_translation' => !empty($frontmatter['translation_of']) ? 1 : 0,
    ];
  }

}
