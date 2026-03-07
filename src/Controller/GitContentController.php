<?php

namespace Drupal\git_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\git_content\Exporter\NodeExporter;
use Drupal\git_content\Exporter\TaxonomyExporter;
use Drupal\git_content\Exporter\MediaExporter;
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

  public function __construct(
    NodeExporter $nodeExporter,
    TaxonomyExporter $taxonomyExporter,
    MediaExporter $mediaExporter,
    MarkdownImporter $importer,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->nodeExporter     = $nodeExporter;
    $this->taxonomyExporter = $taxonomyExporter;
    $this->mediaExporter    = $mediaExporter;
    $this->importer         = $importer;
    // Asignar a la propiedad de ControllerBase para reutilizar entityTypeManager()
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('git_content.node_exporter'),
      $container->get('git_content.taxonomy_exporter'),
      $container->get('git_content.media_exporter'),
      $container->get('git_content.importer'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Exporta nodos, términos de taxonomía y media a content_export/.
   */
  public function exportGit(): array {
    $output = '<strong>Git Content Export</strong><br><br>';

    $output .= $this->exportEntities('node', $this->nodeExporter, 'Nodos');
    $output .= $this->exportEntities('taxonomy_term', $this->taxonomyExporter, 'Taxonomía');
    $output .= $this->exportEntities('media', $this->mediaExporter, 'Media');

    return ['#markup' => $output];
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

    if (!empty($result['errors'])) {
      $output .= '<strong>Errores (' . count($result['errors']) . '):</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . $error . '<br>';
      }
    }

    if (empty($result['imported']) && empty($result['updated']) && empty($result['errors'])) {
      $output .= 'No se encontraron archivos para importar en content_export/.';
    }

    return ['#markup' => $output];
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Exporta todas las entidades de un tipo y devuelve el HTML de resultado.
   */
  private function exportEntities(string $entity_type, $exporter, string $label): string {
    $storage = $this->entityTypeManager()->getStorage($entity_type);

    // Algunos entity types pueden no estar instalados (ej. media)
    try {
      $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    }
    catch (\Exception $e) {
      return "<em>$label: no disponible ({$e->getMessage()})</em><br><br>";
    }

    if (empty($ids)) {
      return "<em>$label: ninguna entidad encontrada.</em><br><br>";
    }

    $entities = $storage->loadMultiple($ids);
    $lines = "<strong>$label (" . count($entities) . "):</strong><br>";

    foreach ($entities as $entity) {
      try {
        $filepath = $exporter->exportToFile($entity);
        $lines .= '✔ ' . $entity->id() . ' (' . $entity->bundle() . '): ' . $filepath . '<br>';
      }
      catch (\Exception $e) {
        $lines .= '✘ ' . $entity->id() . ': ' . $e->getMessage() . '<br>';
      }
    }

    return $lines . '<br>';
  }

}
