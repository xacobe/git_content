<?php

namespace Drupal\git_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\git_content\Exporter\MarkdownExporter;
use Drupal\git_content\Importer\MarkdownImporter;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Web controller for exporting and importing content via the Drupal UI.
 *
 * Available routes:
 *   /git-content/export  → exports all content to content_export/
 *   /git-content/import  → imports from content_export/
 */
class GitContentController extends ControllerBase {

  protected LoggerInterface $logger;

  public function __construct(
    protected MarkdownExporter $exporter,
    protected MarkdownImporter $importer,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger            = $loggerFactory->get('git_content');
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('git_content.exporter'),
      $container->get('git_content.importer'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
    );
  }

  // ---------------------------------------------------------------------------
  // Export
  // ---------------------------------------------------------------------------

  /**
   * Export all content to content_export/.
   */
  public function exportGit(): array {
    $result  = $this->exporter->exportAll();
    $output  = '<strong>' . $this->t('Git Content Export') . '</strong><br><br>';
    $grouped = ['exported' => [], 'skipped' => [], 'deleted' => []];

    foreach (['exported', 'skipped', 'deleted'] as $op) {
      foreach ($result[$op] as $file) {
        $grouped[$op][$this->detectTypeFromPath($file)][] = $file;
      }
    }

    $opInfo = [
      'exported' => ['label' => $this->t('Exported'),  'icon' => '✔'],
      'skipped'  => ['label' => $this->t('Unchanged'), 'icon' => '→'],
      'deleted'  => ['label' => $this->t('Deleted'),   'icon' => '✖'],
    ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $this->t('@label (@count):', ['@label' => $info['label'], '@count' => (string) $total]) . '</strong><br>';

      foreach ($grouped[$op] as $type => $files) {
        $output .= '<details><summary>' . Html::escape((string) $this->labelForType($type)) . ' (' . count($files) . '):</summary>';
        foreach ($files as $file) {
          $output .= Html::escape($info['icon']) . ' ' . Html::escape($file) . '<br>';
        }
        $output .= '</details>';
      }

      $output .= '<br>';
    }

    if (!empty($result['errors'])) {
      $output .= '<strong>' . $this->t('Errors (@count):', ['@count' => (string) count($result['errors'])]) . '</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . Html::escape($error) . '<br>';
      }
    }

    if (empty($result['exported']) && empty($result['skipped']) && empty($result['deleted']) && empty($result['errors'])) {
      $output .= $this->t('Nothing to export.');
    }

    return ['#markup' => Markup::create($output)];
  }

  // ---------------------------------------------------------------------------
  // Import
  // ---------------------------------------------------------------------------

  /**
   * Import all .md files from content_export/ into Drupal.
   */
  public function importGit(): array {
    $result  = $this->importer->importAll();
    $output  = '<strong>' . $this->t('Git Content Import') . '</strong><br><br>';
    $grouped = ['imported' => [], 'updated' => [], 'skipped' => [], 'deleted' => []];

    foreach (['imported', 'updated', 'skipped', 'deleted'] as $op) {
      foreach ($result[$op] as $file) {
        $grouped[$op][$this->detectTypeFromPath($file)][] = $file;
      }
    }

    $opInfo = [
      'imported' => ['label' => $this->t('Created'),   'icon' => '✔'],
      'updated'  => ['label' => $this->t('Updated'),   'icon' => '↻'],
      'skipped'  => ['label' => $this->t('Unchanged'), 'icon' => '→'],
      'deleted'  => ['label' => $this->t('Deleted'),   'icon' => '✖'],
    ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $this->t('@label (@count):', ['@label' => $info['label'], '@count' => (string) $total]) . '</strong><br>';

      foreach ($grouped[$op] as $type => $files) {
        $output .= '<details><summary>' . Html::escape((string) $this->labelForType($type)) . ' (' . count($files) . '):</summary>';
        foreach ($files as $file) {
          $output .= Html::escape($info['icon']) . ' ' . Html::escape($file) . '<br>';
        }
        $output .= '</details>';
      }

      $output .= '<br>';
    }

    if (!empty($result['deleted'])) {
      $output .= '<em>' . $this->t('Some entities were deleted in Drupal because no corresponding .md files were found.') . '</em><br><br>';
    }

    if (!empty($result['errors'])) {
      $output .= '<strong>' . $this->t('Errors (@count):', ['@count' => (string) count($result['errors'])]) . '</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . Html::escape($error) . '<br>';
      }
    }

    if (empty($result['imported']) && empty($result['updated']) && empty($result['skipped']) && empty($result['deleted']) && empty($result['errors'])) {
      $output .= $this->t('No files found to import in content_export/.');
    }

    return ['#markup' => Markup::create($output)];
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function detectTypeFromPath(string $relativePath): string {
    $first = explode('/', $relativePath)[0] ?? '';

    return match ($first) {
      'content_types' => 'nodes',
      'taxonomy'      => 'taxonomy',
      'media'         => 'media',
      'blocks'        => 'blocks',
      'files'         => 'files',
      'users'         => 'users',
      'menus'         => 'menus',
      default         => 'other',
    };
  }

  private function labelForType(string $type): string {
    return match ($type) {
      'nodes'    => $this->t('Nodes'),
      'taxonomy' => $this->t('Taxonomy'),
      'media'    => $this->t('Media'),
      'blocks'   => $this->t('Block content'),
      'files'    => $this->t('Files'),
      'users'    => $this->t('Users'),
      'menus'    => $this->t('Menu links'),
      default    => $this->t('Other'),
    };
  }

}
