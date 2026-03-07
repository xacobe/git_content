<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Exporta entidades de tipo Media a archivos Markdown con frontmatter YAML.
 *
 * El archivo exportado describe los metadatos del media (título, tipo, campos
 * de texto/alt). El archivo físico (imagen, vídeo…) no se copia; se referencia
 * por su nombre de archivo para que pueda versionarse por separado si se desea.
 *
 * Estructura de salida:
 *   content_export/
 *     media/
 *       {bundle}/
 *         {slug}-{langcode}.md
 */
class MediaExporter extends BaseExporter {

  /**
   * {@inheritdoc}
   */
  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity): array {
    $markdown = $this->export($entity);

    $dir = DRUPAL_ROOT . '/content_export/media/' . $entity->bundle();
    $this->ensureDir($dir);

    $slug     = $this->getMediaSlug($entity);
    $langcode = $entity->language()->getId();
    $filepath = $dir . '/' . $slug . '-' . $langcode . '.md';

    $written = $this->writeIfChanged($filepath, $markdown);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $frontmatter = [];
    $frontmatter['uuid']   = $this->shortenUuid($entity->uuid());
    $frontmatter['type']   = 'media';
    $frontmatter['bundle'] = $entity->bundle();
    $frontmatter['lang']   = $entity->language()->getId();
    $frontmatter['status'] = $entity->get('status')->value ? 'published' : 'draft';
    $frontmatter['_']      = NULL;

    $frontmatter['name']    = $entity->label();
    $frontmatter['slug']    = $this->getMediaSlug($entity);
    $frontmatter['__']      = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->get('created')->value ?? time());
    $frontmatter['changed'] = date('Y-m-d', $entity->get('changed')->value ?? time());
    $frontmatter['___']     = NULL;

    // Archivo fuente (campo thumbnail o campo de source del bundle)
    $source_file = $this->getSourceFile($entity);
    if ($source_file) {
      $frontmatter['file'] = $source_file;
      $frontmatter['____'] = NULL;
    }

    // Campos dinámicos (alt text, caption, etc.)
    $groups = $this->buildDynamicGroups($entity, 'media');

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    $frontmatter = $this->addChecksum($frontmatter, '');
    return $this->serializer->serialize($frontmatter);
  }

  /**
   * Intenta obtener el nombre del archivo fuente del media.
   */
  protected function getSourceFile(EntityInterface $entity): ?string {
    // Campos comunes según el tipo de media bundle
    $source_fields = ['field_media_image', 'field_media_file', 'field_media_video_file',
                      'field_media_audio_file', 'thumbnail'];

    foreach ($source_fields as $field_name) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $target_id = $entity->get($field_name)->target_id;
        if ($target_id) {
          $file = \Drupal::service('entity_type.manager')
            ->getStorage('file')
            ->load($target_id);
          if ($file) {
            return basename($file->getFileUri());
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Genera un slug para el media a partir de su nombre.
   */
  protected function getMediaSlug(EntityInterface $entity): string {
    $name = $entity->label() ?? 'media-' . $entity->id();
    return 'media-' . $entity->id() . '-' . preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
  }

}
