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

    $output .= $nodes['html'];
    $output .= $taxonomy['html'];
    $output .= $media['html'];
    $output .= $blocks['html'];
    $output .= $files['html'];
    $output .= $users['html'];
    $output .= $menus['html'];

    // Registrar en watchlog la cantidad de entidades exportadas y saltadas.
    \Drupal::logger('git_content')->notice(
      'Export finished: nodes: @nodes exported (@nodes_skipped skipped), taxonomy: @taxonomy exported (@taxonomy_skipped skipped), media: @media exported (@media_skipped skipped), blocks: @blocks exported (@blocks_skipped skipped), files: @files exported (@files_skipped skipped), users: @users exported (@users_skipped skipped), menus: @menus exported (@menus_skipped skipped).',
      [
        '@nodes' => $nodes['exported'],
        '@nodes_skipped' => $nodes['skipped'],
        '@taxonomy' => $taxonomy['exported'],
        '@taxonomy_skipped' => $taxonomy['skipped'],
        '@media' => $media['exported'],
        '@media_skipped' => $media['skipped'],
        '@blocks' => $blocks['exported'],
        '@blocks_skipped' => $blocks['skipped'],
        '@files' => $files['exported'],
        '@files_skipped' => $files['skipped'],
        '@users' => $users['exported'],
        '@users_skipped' => $users['skipped'],
        '@menus' => $menus['exported'],
        '@menus_skipped' => $menus['skipped'],
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

    if (!empty($result['imported'])) {
      $output .= '<strong>Creados (' . count($result['imported']) . '):</strong><br>';
      foreach ($result['imported'] as $file) {
        $output .= '✔ ' . $file . '<br>';
      }
      $output .= '<br>';
    }

    if (!empty($result['updated'])) {
      $output .= '<strong>Actualizados (' . count($result['updated']) . '):</strong><br>';
      foreach ($result['updated'] as $file) {
        $output .= '↻ ' . $file . '<br>';
      }
      $output .= '<br>';
    }

    if (!empty($result['skipped'])) {
      $output .= '<strong>Sin cambios (' . count($result['skipped']) . '):</strong><br>';
      foreach ($result['skipped'] as $file) {
        $output .= '→ ' . $file . '<br>';
      }
      $output .= '<br>';
    }

    if (!empty($result['errors'])) {
      $output .= '<strong>Errores (' . count($result['errors']) . '):</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . $error . '<br>';
      }
    }

    if (empty($result['imported']) && empty($result['updated']) && empty($result['skipped']) && empty($result['errors'])) {
      $output .= 'No se encontraron archivos para importar en content_export/.';
    }

    return ['#markup' => $output];
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Exporta todas las entidades de un tipo y devuelve el HTML de resultado
   * junto con un resumen de exportadas/saltadas.
   *
   * @return array{
   *   html: string,
   *   exported: int,
   *   skipped: int,
   *   total: int,
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
      ];
    }

    if (empty($ids)) {
      return [
        'html' => "<em>$label: ninguna entidad encontrada.</em><br><br>",
        'exported' => 0,
        'skipped' => 0,
        'total' => 0,
      ];
    }

    $entities = $storage->loadMultiple($ids);
    $lines = "<strong>$label (" . count($entities) . "):</strong><br>";
    $skipped = 0;
    $exported = 0;

    foreach ($entities as $entity) {
      try {
        $result = $exporter->exportToFile($entity);
        $filepath = is_array($result) ? $result['path'] : $result;
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $lines .= '→ ' . $entity->id() . ' (' . $entity->bundle() . '): ' . $filepath . '<br>';
        }
        else {
          $exported++;
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
    ];
  }

}