<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;

/**
 * Exporta entidades file (archivos gestionados por Drupal) a Markdown.
 *
 * Exporta SOLO los metadatos de la entidad file (URI, nombre, mime type,
 * propietario, fechas). El archivo físico debe gestionarse por separado
 * (git-lfs, rsync, etc.) ya que puede ser muy grande para un repositorio git.
 *
 * Estructura de salida:
 *   content_export/
 *     files/
 *       {fid}-{filename}.md
 *
 * Ejemplo de frontmatter:
 *   ---
 *   uuid: a1b2c3d4
 *   type: file
 *   lang: en
 *   status: permanent
 *
 *   filename: drupal.jpg
 *   uri: public://images/drupal.jpg
 *   mime: image/jpeg
 *   size: 45231
 *
 *   created: 2026-01-15
 *   owner: admin
 *   ---
 */
class FileExporter extends BaseExporter {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    FieldDiscovery $fieldDiscovery,
    MarkdownSerializer $serializer,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct($fieldDiscovery, $serializer);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Exporta todos los archivos gestionados.
   *
   * @return string[] Rutas de archivos generados.
   */
  public function exportAll(): array {
    $storage = $this->entityTypeManager->getStorage('file');
    $fids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $files = [];

    foreach ($storage->loadMultiple($fids) as $file) {
      try {
        $result = $this->exportToFile($file);
        $files[] = is_array($result) ? $result['path'] : $result;
      }
      catch (\Exception $e) {
        \Drupal::logger('git_content')->error(
          'FileExporter: @msg', ['@msg' => $e->getMessage()]
        );
      }
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity): array {
    $markdown = $this->export($entity);

    $dir = DRUPAL_ROOT . '/content_export/files';
    $this->ensureDir($dir);

    $filename = $this->sanitizeFilename($entity->getFilename());
    $filepath = $dir . '/' . $entity->id() . '-' . $filename . '.md';

    $written = $this->writeIfChanged($filepath, $markdown);

    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    // Resolver el nombre del propietario
    $owner_name = NULL;
    $owner_id = $entity->getOwnerId();
    if ($owner_id) {
      $owner = $this->entityTypeManager->getStorage('user')->load($owner_id);
      $owner_name = $owner ? $owner->getAccountName() : NULL;
    }

    $frontmatter = [];
    $frontmatter['uuid']   = $this->shortenUuid($entity->uuid());
    $frontmatter['type']   = 'file';
    $frontmatter['lang']   = $entity->language()->getId();
    $frontmatter['status'] = $entity->isPermanent() ? 'permanent' : 'temporary';
    $frontmatter['_']      = NULL;

    $frontmatter['filename'] = $entity->getFilename();
    $frontmatter['uri']      = $entity->getFileUri();
    $frontmatter['mime']     = $entity->getMimeType();
    $frontmatter['size']     = (int) $entity->getSize();
    $frontmatter['__']       = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['owner']   = $owner_name;

    $frontmatter = $this->addChecksum($frontmatter, '');
    return $this->serializer->serialize($frontmatter);
  }

  /**
   * Sanitiza el nombre de archivo para usarlo como parte del nombre del .md.
   */
  protected function sanitizeFilename(string $filename): string {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    return preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
  }

}