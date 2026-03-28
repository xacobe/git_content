<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\SummaryTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Orchestrates the export of all Drupal content to content_export/.
 *
 * Iterates every entity type, delegates to the appropriate concrete exporter,
 * detects orphaned .md files left over from previous runs and removes them.
 * Mirrors MarkdownImporter::importAll() on the export side.
 */
class MarkdownExporter {

  use ContentExportTrait;
  use SummaryTrait;

  private const FORMAT_VERSION = 1;

  protected LoggerInterface $logger;

  /** @var array<string, ExporterInterface> */
  private array $exporterMap;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected LanguageManagerInterface $languageManager,
    iterable $exporters,
    protected ConfigFactoryInterface $configFactory,
    protected AliasManagerInterface $aliasManager,
  ) {
    $this->logger = $loggerFactory->get('git_content');
    $this->exporterMap = [];
    foreach ($exporters as $exporter) {
      $this->exporterMap[$exporter->getEntityType()] = $exporter;
    }
  }

  /**
   * Build the CLI name → entity type map from registered exporters.
   *
   * Used by GitContentCommands to auto-discover valid export types
   * without hardcoding the list.
   *
   * @return array<string, string>
   *   CLI name => entity type machine name (e.g. 'nodes' => 'node').
   */
  public function getCliTypeMap(): array {
    $map = [];
    foreach ($this->exporterMap as $exporter) {
      $map[$exporter->getCliName()] = $exporter->getEntityType();
    }
    return $map;
  }

  /**
   * Return the resolved absolute path to the content export directory.
   *
   * Exposed publicly so the UI can display the active path to the user.
   */
  public function getExportDir(): string {
    return $this->contentExportDir();
  }

  // ---------------------------------------------------------------------------
  // Bulk export
  // ---------------------------------------------------------------------------

  /**
   * Preview what exportAll() would do without writing anything.
   *
   * Runs the full export pipeline in dry-run mode (same cost as exportAll
   * minus actual disk writes) and returns the same array shape so callers
   * can treat preview and real results identically.
   *
   * @return array{exported: string[], skipped: string[], deleted: string[], errors: string[], warnings: string[]}
   */
  public function previewAll(): array {
    $result       = ['exported' => [], 'skipped' => [], 'deleted' => [], 'errors' => [], 'warnings' => []];
    $touchedSet = [];

    foreach ($this->exporterMap as $entity_type => $exporter) {
      $typeResult = $this->exportEntityType($entity_type, $exporter, TRUE);

      foreach ($typeResult['exported_files'] as $file) {
        $result['exported'][] = $file;
        $touchedSet[$file]    = TRUE;
      }
      foreach ($typeResult['skipped_files'] as $file) {
        $result['skipped'][] = $file;
        $touchedSet[$file]   = TRUE;
      }
      foreach ($typeResult['errors'] as $error) {
        $result['errors'][] = $error;
      }
      foreach ($typeResult['warnings'] as $warning) {
        $result['warnings'][] = $warning;
      }
    }

    // Files on disk that were not touched = would be deleted as orphans.
    foreach ($this->scanContentExportFiles() as $relpath) {
      if (!isset($touchedSet[$relpath])) {
        $result['deleted'][] = $relpath;
      }
    }

    return $result;
  }

  /**
   * Export a subset of entity types without orphan cleanup.
   *
   * Useful for partial exports (e.g. "only nodes"). For a full content sync
   * including removal of stale files, use exportAll() instead.
   *
   * @param string[] $entityTypes
   *   Entity type machine names, e.g. ['node', 'taxonomy_term'].
   *
   * @return array{exported: string[], skipped: string[], deleted: string[], errors: string[]}
   */
  public function exportTypes(array $entityTypes): array {
    $result = ['exported' => [], 'skipped' => [], 'deleted' => [], 'errors' => []];

    $map = array_intersect_key($this->exporterMap, array_flip($entityTypes));
    foreach ($map as $entity_type => $exporter) {
      $typeResult = $this->exportEntityType($entity_type, $exporter);
      array_push($result['exported'], ...$typeResult['exported_files']);
      array_push($result['skipped'], ...$typeResult['skipped_files']);
      array_push($result['errors'], ...$typeResult['errors']);
    }

    return $result;
  }

  /**
   * Export all content to content_export/.
   *
   * @return array{exported: string[], skipped: string[], deleted: string[], errors: string[], warnings: string[]}
   *   Relative paths of files written, unchanged, removed, any errors, and any warnings.
   */
  public function exportAll(): array {
    $result = ['exported' => [], 'skipped' => [], 'deleted' => [], 'errors' => [], 'warnings' => []];

    $touchedFiles = [];
    $typeCounts   = [];

    foreach ($this->exporterMap as $entity_type => $exporter) {
      $typeResult = $this->exportEntityType($entity_type, $exporter);

      $exp = count($typeResult['exported_files']);
      $skp = count($typeResult['skipped_files']);
      $typeCounts[$entity_type] = ['exported' => $exp, 'skipped' => $skp];

      foreach ($typeResult['exported_files'] as $file) {
        $result['exported'][] = $file;
        $touchedFiles[] = $file;
      }
      foreach ($typeResult['skipped_files'] as $file) {
        $result['skipped'][] = $file;
        $touchedFiles[] = $file;
      }
      foreach ($typeResult['errors'] as $error) {
        $result['errors'][] = $error;
      }
      foreach ($typeResult['warnings'] as $warning) {
        $result['warnings'][] = $warning;
      }
    }

    // Remove .md files that are on disk but were not touched in this run
    // (entities deleted from Drupal since the last export).
    $touchedSet = array_flip($touchedFiles);
    $allFiles   = $this->scanContentExportFiles();
    $orphans    = array_filter($allFiles, fn($f) => !isset($touchedSet[$f]));
    foreach ($orphans as $file) {
      $result['deleted'][] = $file;
      $path = $this->contentExportDir() . '/' . $file;
      is_file($path) && unlink($path);
    }

    $this->writeSiteYaml();

    $this->logger->notice(
      'Export finished: @summary. Total: @exported exported, @skipped unchanged, @deleted deleted, @errors errors.',
      [
        '@summary'  => $this->buildTypeSummary($typeCounts),
        '@exported' => (string) count($result['exported']),
        '@skipped'  => (string) count($result['skipped']),
        '@deleted'  => (string) count($result['deleted']),
        '@errors'   => (string) count($result['errors']),
      ]
    );

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------

  /**
   * Re-export a single entity (all translations) identified by entity ID.
   *
   * Used by the importer after a real import to normalise the .md file to the
   * canonical exporter format, ensuring the next export preview shows the file
   * as unchanged.
   */
  public function exportEntityById(int $entityId, string $entityType, bool $dryRun = FALSE, ?string $langcode = NULL): array {
    $paths    = [];
    $exporter = $this->exporterMap[$entityType] ?? NULL;
    if (!$exporter) {
      return $paths;
    }

    $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
    if (!$entity) {
      return $paths;
    }

    foreach ($this->getEntityTranslations($entity) as $translation) {
      // When a specific language is requested, skip all other translations.
      // This prevents overwriting sibling .md files that are still pending
      // import in the current run (which would cause them to be skipped).
      // Use the raw langcode field value (not language()->getId()) to avoid
      // the Drupal fallback that returns the site default for 'und' entities.
      $translationLangcode = $translation->get('langcode')->value ?? $translation->language()->getId();
      if ($langcode !== NULL && $translationLangcode !== $langcode) {
        continue;
      }
      try {
        $result  = $exporter->exportToFile($translation, $dryRun);
        $paths[] = $result['path'];
      }
      catch (\Exception $e) {
        // Re-export after import is best-effort; log but do not fail.
        $this->logger->warning('Re-export of @type @id failed: @msg', [
          '@type' => $entityType,
          '@id'   => (string) $entityId,
          '@msg'  => $e->getMessage(),
        ]);
      }
    }

    return $paths;
  }

  /**
   * Write content_export/site.yaml with site-wide metadata for SSG tooling.
   *
   * Contains the format version (so SSG tools can detect breaking changes),
   * the list of enabled content languages, and the default language.
   * This file is idempotent: running export multiple times produces the same
   * output and only rewrites the file when something has changed.
   */
  private function writeSiteYaml(): void {
    $languages  = array_keys($this->languageManager->getLanguages());
    $default    = $this->languageManager->getDefaultLanguage()->getId();
    $raw_front  = $this->configFactory->get('system.site')->get('page.front') ?? '/';
    // Resolve to the path alias so the stored value survives a DB reset where
    // node IDs change (e.g. /node/26 → /pagina-basica/inicio).
    $front_page = $this->aliasManager->getAliasByPath($raw_front) ?: $raw_front;

    $data = [
      'git_content_format' => self::FORMAT_VERSION,
      'languages'          => $languages,
      'default_language'   => $default,
      'front_page'         => $front_page,
    ];

    $yaml    = Yaml::dump($data, 2, 2);
    $path    = $this->contentExportDir() . '/site.yaml';
    $current = file_exists($path) ? file_get_contents($path) : '';

    if ($current !== $yaml) {
      file_put_contents($path, $yaml);
    }
  }

  /**
   * Export all entities of a single type using the given exporter.
   *
   * @return array{exported_files: string[], skipped_files: string[], errors: string[], warnings: string[]}
   */
  private function exportEntityType(string $entity_type, ExporterInterface $exporter, bool $dryRun = FALSE): array {
    $exportedFiles = [];
    $skippedFiles  = [];
    $errors        = [];
    $warnings      = [];

    $storage = $this->entityTypeManager->getStorage($entity_type);

    // Some entity types may not be installed (e.g. media, block_content).
    try {
      $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    }
    catch (\Exception $e) {
      return ['exported_files' => [], 'skipped_files' => [], 'errors' => [], 'warnings' => []];
    }

    if (empty($ids)) {
      return ['exported_files' => [], 'skipped_files' => [], 'errors' => [], 'warnings' => []];
    }

    // For file entities, track exported URIs to skip duplicate entities that
    // share the same physical file (e.g. Umami creates two file entities per
    // image). Only the first entity per URI is exported; siblings are silently
    // skipped. The importer's sibling-UUID tracking ensures they are never
    // deleted by syncDeletedEntities.
    $exportedFileUris = [];

    // Track relative paths written in this run to detect filename collisions.
    // A collision occurs when two entities produce the same output path (e.g.
    // two nodes sharing the same path alias). exportToFile() runs before we can
    // check, so entity 2 has already overwritten entity 1 on disk. We restore
    // entity 1's content and skip entity 2, then warn in the result.
    // Key: relpath → entity translation (stored to allow restore on collision).
    $writtenPaths = [];

    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity_type === 'file' && $entity->hasField('uri')) {
        $uri = $entity->get('uri')->value;
        if ($uri && isset($exportedFileUris[$uri])) {
          continue;
        }
        if ($uri) {
          $exportedFileUris[$uri] = TRUE;
        }
      }

      foreach ($this->getEntityTranslations($entity) as $translation) {
        try {
          $fileResult = $exporter->exportToFile($translation, $dryRun);
          $filepath   = $fileResult['path'];
          $relpath    = str_replace($this->contentExportDir() . '/', '', $filepath);
          $skipped    = $fileResult['skipped'] ?? FALSE;

          // Collision: entity 2 just overwrote entity 1's file.
          // Restore entity 1's content so the file stays stable, then skip
          // entity 2 (it will have no .md file until the alias is fixed).
          if ($relpath && !$skipped && isset($writtenPaths[$relpath])) {
            $entity1Translation = $writtenPaths[$relpath];
            if (!$dryRun) {
              file_put_contents($filepath, $exporter->export($entity1Translation));
            }
            $label1     = $entity1Translation->label() ?? $entity1Translation->uuid();
            $label2     = $translation->label() ?? $translation->uuid();
            $warnings[] = "Path collision on {$relpath}: {$label2} skipped — path already used by {$label1}. Fix the duplicate name in Drupal.";
            $this->logger->warning(
              'Export path collision on @path: @type @id (@label2) skipped — path already used by @label1. Fix duplicate path aliases.',
              ['@path' => $relpath, '@type' => $entity_type, '@id' => (string) $translation->id(), '@label2' => $label2, '@label1' => $label1]
            );
            continue;
          }

          if ($relpath && !$skipped) {
            $writtenPaths[$relpath] = $translation;
          }

          if ($skipped) {
            $skippedFiles[] = $relpath;
          }
          else {
            $exportedFiles[] = $relpath;
          }
        }
        catch (\Exception $e) {
          $errors[] = $entity_type . ':' . $translation->id() . ':' . $translation->language()->getId() . ': ' . $e->getMessage();
          $this->logger->error(
            'Failed to export @type @id (@lang): @message',
            ['@type' => $entity_type, '@id' => (string) $translation->id(), '@lang' => $translation->language()->getId(), '@message' => $e->getMessage()]
          );
        }
      }
    }

    return ['exported_files' => $exportedFiles, 'skipped_files' => $skippedFiles, 'errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Returns all translations of an entity, default translation first.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  private function getEntityTranslations(EntityInterface $entity): array {
    $translations = [$entity];
    if ($entity instanceof TranslatableInterface) {
      // FALSE = exclude the default translation; prepended above so it comes first.
      foreach ($entity->getTranslationLanguages(FALSE) as $langcode => $_) {
        $translations[] = $entity->getTranslation($langcode);
      }
    }
    return $translations;
  }

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  /**
   * Return all .md files currently on disk under content_export/, relative paths.
   *
   * @return string[]
   */
  private function scanContentExportFiles(): array {
    $base  = $this->contentExportDir() . '/';
    $files = array_map(fn($p) => str_replace($base, '', $p), $this->scanMarkdownFiles());
    sort($files);
    return $files;
  }

}
