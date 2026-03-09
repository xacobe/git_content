<?php

namespace Drupal\git_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\git_content\Exporter\NodeExporter;
use Drupal\git_content\Exporter\TaxonomyExporter;
use Drupal\git_content\Exporter\MediaExporter;
use Drupal\git_content\Exporter\BlockContentExporter;
use Drupal\git_content\Exporter\FileExporter;
use Drupal\git_content\Exporter\UserExporter;
use Drupal\git_content\Exporter\MenuLinkExporter;
use Drupal\git_content\Importer\MarkdownImporter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Web controller for exporting and importing content via the Drupal UI.
 *
 * Available routes:
 *   /git-content/export  → exports nodes, taxonomy and media
 *   /git-content/import  → imports from content_export/
 */
class GitContentController extends ControllerBase {

  protected NodeExporter $nodeExporter;
  protected TaxonomyExporter $taxonomyExporter;
  protected MediaExporter $mediaExporter;
  protected MarkdownImporter $importer;
  protected BlockContentExporter $blockContentExporter;
  protected FileExporter $fileExporter;
  protected UserExporter $userExporter;
  protected MenuLinkExporter $menuLinkExporter;
  protected LoggerInterface $logger;

  public function __construct(
    NodeExporter $nodeExporter,
    TaxonomyExporter $taxonomyExporter,
    MediaExporter $mediaExporter,
    MarkdownImporter $importer,
    BlockContentExporter $blockContentExporter,
    FileExporter $fileExporter,
    UserExporter $userExporter,
    MenuLinkExporter $menuLinkExporter,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->nodeExporter         = $nodeExporter;
    $this->taxonomyExporter     = $taxonomyExporter;
    $this->mediaExporter        = $mediaExporter;
    $this->importer             = $importer;
    $this->blockContentExporter = $blockContentExporter;
    $this->fileExporter         = $fileExporter;
    $this->userExporter         = $userExporter;
    $this->menuLinkExporter     = $menuLinkExporter;
    $this->entityTypeManager    = $entityTypeManager;
    $this->logger               = $loggerFactory->get('git_content');
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('git_content.node_exporter'),
      $container->get('git_content.taxonomy_exporter'),
      $container->get('git_content.media_exporter'),
      $container->get('git_content.importer'),
      $container->get('git_content.block_content_exporter'),
      $container->get('git_content.file_exporter'),
      $container->get('git_content.user_exporter'),
      $container->get('git_content.menu_link_exporter'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Export nodes, taxonomy terms and media to content_export/.
   */
  public function exportGit(): array {
    $output = '<strong>' . $this->t('Git Content Export') . '</strong><br><br>';

    $nodes = $this->exportEntities('node', $this->nodeExporter, $this->t('Nodes'));
    $taxonomy = $this->exportEntities('taxonomy_term', $this->taxonomyExporter, $this->t('Taxonomy'));
    $media = $this->exportEntities('media', $this->mediaExporter, $this->t('Media'));
    $blocks = $this->exportEntities('block_content', $this->blockContentExporter, $this->t('Block content'));
    $files = $this->exportEntities('file', $this->fileExporter, $this->t('Files'));
    $users = $this->exportEntities('user', $this->userExporter, $this->t('Users'));
    $menus = $this->exportEntities('menu_link_content', $this->menuLinkExporter, $this->t('Menu links'));

    // Primarily use grouped output for the UI, but keep the log format.
    $grouped = [
      'exported' => [],
      'skipped'  => [],
      'deleted'  => [],
    ];

    $entityResults = [
      'nodes' => $nodes,
      'taxonomy' => $taxonomy,
      'media' => $media,
      'blocks' => $blocks,
      'files' => $files,
      'users' => $users,
      'menus' => $menus,
    ];

    $exportedFiles = [];
    $skippedFiles = [];

    foreach ($entityResults as $type => $result) {
      foreach ($result['exported_files'] as $file) {
        $rel = str_replace(DRUPAL_ROOT . '/content_export/', '', $file);
        $exportedFiles[] = $rel;
        $grouped['exported'][$type][] = $rel;
      }
      foreach ($result['skipped_files'] as $file) {
        $rel = str_replace(DRUPAL_ROOT . '/content_export/', '', $file);
        $skippedFiles[] = $rel;
        $grouped['skipped'][$type][] = $rel;
      }
    }

    // Include files that were already on disk but not touched by this export
    // run (e.g. leftovers from previous runs).
    $allFiles = $this->scanContentExportFiles();
    $untouched = array_diff($allFiles, array_merge($exportedFiles, $skippedFiles));
    foreach ($untouched as $file) {
      $type = $this->detectImportTypeFromPath($file);
      $grouped['deleted'][$type][] = $file;

      $path = DRUPAL_ROOT . '/content_export/' . $file;
      if (is_file($path)) {
        @unlink($path);
      }
    }

    $opInfo = [
      'exported' => ['label' => $this->t('Exported'), 'icon' => '✔'],
      'skipped'  => ['label' => $this->t('Unchanged'), 'icon' => '→'],
      'deleted'  => ['label' => $this->t('Deleted'), 'icon' => '✖'],
    ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $this->t('@label (@count):', ['@label' => $info['label'], '@count' => $total]) . '</strong><br>';

      foreach ($grouped[$op] as $type => $files) {
        $label = $this->labelForImportType($type);
        $output .= "<details><summary>$label (" . count($files) . "):</summary>";
        foreach ($files as $file) {
          $output .= $info['icon'] . ' ' . $file . '<br>';
        }
        $output .= '</details>';
      }

      $output .= '<br>';
    }

    // Log the number of entities exported and skipped.
    $this->logger->notice(
      $this->t('Export finished: nodes: @nodes exported (@nodes_skipped skipped), taxonomy: @taxonomy exported (@taxonomy_skipped skipped), media: @media exported (@media_skipped skipped), blocks: @blocks exported (@blocks_skipped skipped), files: @files exported (@files_skipped skipped), users: @users exported (@users_skipped skipped), menus: @menus exported (@menus_skipped skipped).', [
        '@nodes' => $nodes['exported'] ?? 0,
        '@nodes_skipped' => $nodes['skipped'] ?? 0,
        '@taxonomy' => $taxonomy['exported'] ?? 0,
        '@taxonomy_skipped' => $taxonomy['skipped'] ?? 0,
        '@media' => $media['exported'] ?? 0,
        '@media_skipped' => $media['skipped'] ?? 0,
        '@blocks' => $blocks['exported'] ?? 0,
        '@blocks_skipped' => $blocks['skipped'] ?? 0,
        '@files' => $files['exported'] ?? 0,
        '@files_skipped' => $files['skipped'] ?? 0,
        '@users' => $users['exported'] ?? 0,
        '@users_skipped' => $users['skipped'] ?? 0,
        '@menus' => $menus['exported'] ?? 0,
        '@menus_skipped' => $menus['skipped'] ?? 0,
      ])
    );

    return ['#markup' => $output];
  }

  private function countEntities(string $entity_type): int {
    $storage = $this->entityTypeManager()->getStorage($entity_type);
    $query = $storage->getQuery()->accessCheck(FALSE);
    return (int) $query->count()->execute();
  }

  /**
   * Import all .md files from content_export/ into Drupal.
   */
  public function importGit(): array {
    $result = $this->importer->importAll();

    $output = '<strong>' . $this->t('Git Content Import') . '</strong><br><br>';

    // Group results by operation type and entity type.
    $grouped = [
      'imported' => [],
      'updated'  => [],
      'skipped'  => [],
      'deleted'  => [],
    ];

    foreach (['imported', 'updated', 'skipped', 'deleted'] as $op) {
      foreach ($result[$op] as $file) {
        $rel = str_replace(DRUPAL_ROOT . '/content_export/', '', $file);
        $type = $this->detectImportTypeFromPath($rel);
        $grouped[$op][$type][] = $rel;
      }
    }

    $opInfo = [
      'imported' => ['label' => $this->t('Created'), 'icon' => '✔'],
      'updated'  => ['label' => $this->t('Updated'), 'icon' => '↻'],
      'skipped'  => ['label' => $this->t('Unchanged'), 'icon' => '→'],
      'deleted'  => ['label' => $this->t('Deleted'), 'icon' => '✖'],
    ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $this->t('@label (@count):', ['@label' => $info['label'], '@count' => $total]) . '</strong><br>';

      foreach ($grouped[$op] as $type => $files) {
        $label = $this->labelForImportType($type);
        $output .= "<details><summary>$label (" . count($files) . "):</summary>";
        foreach ($files as $file) {
          $output .= $info['icon'] . ' ' . $file . '<br>';
        }
        $output .= '</details>';
      }

      $output .= '<br>';
    }

    if (!empty($result['deleted'])) {
      $output .= '<em>' . $this->t('Some entities were deleted in Drupal because no corresponding .md files were found.') . '</em><br><br>';
    }

    if (!empty($result['errors'])) {
      $output .= '<strong>' . $this->t('Errors (@count):', ['@count' => count($result['errors'])]) . '</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . $error . '<br>';
      }
    }

    if (empty($result['imported']) && empty($result['updated']) && empty($result['skipped']) && empty($result['deleted']) && empty($result['errors'])) {
      $output .= $this->t('No files found to import in content_export/.');
    }

    return ['#markup' => $output];
  }

  private function detectImportTypeFromPath(string $relativePath): string {
    $parts = explode('/', $relativePath);
    $first = $parts[0] ?? '';

    return match ($first) {
      'content_types' => 'nodes',
      'taxonomy' => 'taxonomy',
      'media' => 'media',
      'blocks' => 'blocks',
      'files' => 'files',
      'users' => 'users',
      'menus' => 'menus',
      default => 'other',
    };
  }

  private function scanContentExportFiles(): array {
    $dir = DRUPAL_ROOT . '/content_export';
    if (!is_dir($dir)) {
      return [];
    }

    $files = [];
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
      if (!$file->isFile()) {
        continue;
      }
      if ($file->getExtension() !== 'md') {
        continue;
      }
      $path = $file->getPathname();
      $rel = str_replace(DRUPAL_ROOT . '/content_export/', '', $path);
      $files[] = $rel;
    }

    sort($files);
    return $files;
  }

  private function labelForImportType(string $type): string {
    return match ($type) {
      'nodes' => $this->t('Nodes'),
      'taxonomy' => $this->t('Taxonomy'),
      'media' => $this->t('Media'),
      'blocks' => $this->t('Block content'),
      'files' => $this->t('Files'),
      'users' => $this->t('Users'),
      'menus' => $this->t('Menu links'),
      default => $this->t('Other'),
    };
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Export all entities of a given type and return a result summary.
   *
   * @return array{
   *   html: string,
   *   exported: int,
   *   skipped: int,
   *   total: int,
   *   exported_files: string[],
   *   skipped_files: string[],
   * }
   */
  private function exportEntities(string $entity_type, $exporter, string $label): array {
    $storage = $this->entityTypeManager->getStorage($entity_type);

    // Some entity types may not be installed (e.g. media).
    try {
      $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    }
    catch (\Exception $e) {
      return [
        'html' => "<em>$label: not available ({$e->getMessage()})</em><br><br>",
        'exported' => 0,
        'skipped' => 0,
        'total' => 0,
        'exported_files' => [],
        'skipped_files' => [],
      ];
    }

    if (empty($ids)) {
      return [
        'html' => "<em>$label: no entities found.</em><br><br>",
        'exported' => 0,
        'skipped' => 0,
        'total' => 0,
        'exported_files' => [],
        'skipped_files' => [],
      ];
    }

    $entities = $storage->loadMultiple($ids);
    $lines = "<strong>$label (" . count($entities) . "):</strong><br>";
    $skipped = 0;
    $exported = 0;
    $exportedFiles = [];
    $skippedFiles = [];

    foreach ($entities as $entity) {
      try {
        $result = $exporter->exportToFile($entity);
        $filepath = is_array($result) ? $result['path'] : $result;
        $relpath = str_replace(DRUPAL_ROOT . '/content_export/', '', $filepath);
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $skippedFiles[] = $relpath;
          $lines .= '→ ' . $entity->id() . ' (' . $entity->bundle() . '): ' . $filepath . '<br>';
        }
        else {
          $exported++;
          $exportedFiles[] = $relpath;
          $lines .= '✔ ' . $entity->id() . ' (' . $entity->bundle() . '): ' . $filepath . '<br>';
        }
      }
      catch (\Exception $e) {
        $lines .= '✘ ' . $entity->id() . ': ' . $e->getMessage() . '<br>';
      }
    }

    if ($skipped > 0) {
      $lines .= '<em>' . $this->t('Skipped (@count unchanged)', ['@count' => $skipped]) . '</em><br>';
    }

    return [
      'html' => $lines . '<br>',
      'exported' => $exported,
      'skipped' => $skipped,
      'total' => count($entities),
      'exported_files' => $exportedFiles,
      'skipped_files' => $skippedFiles,
    ];
  }

}