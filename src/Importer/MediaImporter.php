<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates media entities from Markdown frontmatter.
 */
class MediaImporter extends BaseImporter {

  /**
   * Source fields checked in the same order as MediaExporter::getSourceFile().
   */
  private const SOURCE_FIELDS = [
    'field_media_image',
    'field_media_file',
    'field_media_video_file',
    'field_media_audio_file',
  ];

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception(t("The media frontmatter is missing 'bundle'."));
    }

    $existing = $uuid ? $this->findByUuid($uuid, 'media', $bundle) : NULL;

    if ($existing) {
      [$media, $operation] = $this->resolveTranslation($existing, $langcode);
    }
    else {
      $media = $this->entityTypeManager->getStorage('media')->create([
        'bundle'   => $bundle,
        'langcode' => $langcode,
        'uuid'     => $uuid ?? $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $media->set('name', $frontmatter['name'] ?? $this->t('Unnamed'));
    $media->set('status', $this->resolveStatus($frontmatter));
    $this->setAuthor($media, $frontmatter);

    $definitions = $this->fieldDiscovery->getFields('media', $bundle);
    $this->populateDynamicFields($media, $frontmatter, $definitions);

    // The exporter captures the source file as 'file:' to avoid redundancy
    // with the media: group. Resolve it back to the bundle's source field.
    if (!empty($frontmatter['file'])) {
      $this->setSourceFile($media, $frontmatter['file']);
    }

    $media->save();

    return $operation;
  }

  /**
   * Set the bundle's source field from a filename string.
   */
  private function setSourceFile($media, string $filename): void {
    $fid = $this->fieldNormalizer->findFileByName($filename);
    if (!$fid) {
      return;
    }
    foreach (self::SOURCE_FIELDS as $field_name) {
      if ($media->hasField($field_name)) {
        $media->set($field_name, ['target_id' => $fid]);
        return;
      }
    }
  }

}
