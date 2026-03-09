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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controlador web para exportar e importar contenido desde la UI de Drupal.
 *
 * Rutas disponibles:
 *   /git-content/export  → exporta nodos, taxonomía y media
 *   /git-content/import  → importa desde content_export/
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

  public function __construct(
    NodeExporter $nodeExporter,
    TaxonomyExporter $taxonomyExporter,
    MediaExporter $mediaExporter,
    MarkdownImporter $importer,
    BlockContentExporter $blockContentExporter,
    FileExporter $fileExporter,
    UserExporter $userExporter,
    MenuLinkExporter $menuLinkExporter,
    EntityTypeManagerInterface $entityTypeManager
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
    );
  }

  /**
   * Exporta nodos, términos de taxonomía y media a content_export/.
   */
  public function exportGit(): array {
    $output = '<strong>Git Content Export</strong><br><br>';

    $nodes = $this->exportEntities('node', $this->nodeExporter, 'Nodos');
    $taxonomy = $this->exportEntities('taxonomy_term', $this->taxonomyExporter, 'Taxonomía');
    $media = $this->exportEntities('media', $this->mediaExporter, 'Media');
    $blocks = $this->exportEntities('block_content', $this->blockContentExporter, 'Bloques de contenido');
    $files = $this->exportEntities('file', $this->fileExporter, 'Archivos');
    $users = $this->exportEntities('user', $this->userExporter, 'Usuarios');
    $menus = $this->exportEntities('menu_link_content', $this->menuLinkExporter, 'Enlaces de menú');

    // Primarily use grouped output for the UI, but keep the log format.
    $grouped = [
      'exported' => [],
      'skipped'  => [],
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

    // Asegurarnos de que el reporte incluye también los archivos que ya estaban
    // en disco y que el export no tocó (por ejemplo, restos de runs anteriores).
    $allFiles = $this->scanContentExportFiles();
    $untouched = array_diff($allFiles, array_merge($exportedFiles, $skippedFiles));
    foreach ($untouched as $file) {
      $type = $this->detectImportTypeFromPath($file);
      $grouped['skipped'][$type][] = $file;
    }

    $opInfo = [
      'exported' => ['label' => 'Exportados', 'icon' => '✔'],
      'skipped'  => ['label' => 'Sin cambios', 'icon' => '→'],
    ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $info['label'] . ' (' . $total . '):</strong><br>';

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

    // Registrar en watchlog la cantidad de entidades exportadas y saltadas.
    \Drupal::logger('git_content')->notice(
      'Export finished: nodes: @nodes exported (@nodes_skipped skipped), taxonomy: @taxonomy exported (@taxonomy_skipped skipped), media: @media exported (@media_skipped skipped), blocks: @blocks exported (@blocks_skipped skipped), files: @files exported (@files_skipped skipped), users: @users exported (@users_skipped skipped), menus: @menus exported (@menus_skipped skipped).',
      [
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
      ]
    );

    return ['#markup' => $output];
  }

  private function countEntities(string $entity_type): int {
    $storage = $this->entityTypeManager()->getStorage($entity_type);
    $query = $storage->getQuery()->accessCheck(FALSE);
    return (int) $query->count()->execute();
  }

  /**
   * Importa todos los archivos .md de content_export/ a Drupal.
   */
  public function importGit(): array {
    $result = $this->importer->importAll();

    $output = '<strong>Git Content Import</strong><br><br>';

    // Agrupar resultados por tipo de operación y por tipo de entidad.
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
      'imported' => ['label' => 'Creados', 'icon' => '✔'],
      'updated'  => ['label' => 'Actualizados', 'icon' => '↻'],
      'skipped'  => ['label' => 'Sin cambios', 'icon' => '→'],
      'deleted'  => ['label' => 'Borrados', 'icon' => '✖'],
    ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $info['label'] . ' (' . $total . '):</strong><br>';

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

    if (!empty($result['errors'])) {
      $output .= '<strong>Errores (' . count($result['errors']) . '):</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . $error . '<br>';
      }
    }

    if (empty($result['imported']) && empty($result['updated']) && empty($result['skipped']) && empty($result['deleted']) && empty($result['errors'])) {
      $output .= 'No se encontraron archivos para importar en content_export/.';
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
      'nodes' => 'Nodos',
      'taxonomy' => 'Taxonomía',
      'media' => 'Media',
      'blocks' => 'Bloques de contenido',
      'files' => 'Archivos',
      'users' => 'Usuarios',
      'menus' => 'Enlaces de menú',
      default => 'Otros',
    };
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Exporta todas las entidades de un tipo y devuelve un resumen de resultados.
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

    // Algunos entity types pueden no estar instalados (ej. media)
    try {
      $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    }
    catch (\Exception $e) {
      return [
        'html' => "<em>$label: no disponible ({$e->getMessage()})</em><br><br>",
        'exported' => 0,
        'skipped' => 0,
        'total' => 0,
        'exported_files' => [],
        'skipped_files' => [],
      ];
    }

    if (empty($ids)) {
      return [
        'html' => "<em>$label: ninguna entidad encontrada.</em><br><br>",
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
      $lines .= '<em>Saltados (' . $skipped . ' sin cambios)</em><br>';
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