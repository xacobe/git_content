<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates media entities from Markdown frontmatter.
 */
class MediaImporter extends BaseImporter {

  public function handles(string $entity_type): bool {
    return $entity_type === 'media';
  }

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception(t("The media frontmatter is missing 'bundle'."));
    }

    [$media, $operation] = $this->resolveOrCreate('media', $uuid, $langcode, [
      'bundle'   => $bundle,
      'langcode' => $langcode,
    ]);

    $media->set('name', $frontmatter['name'] ?? 'Unnamed');
    $media->set('status', $this->resolveStatus($frontmatter));
    $this->setAuthor($media, $frontmatter);

    $definitions = $this->fieldDiscovery->getFields('media', $bundle);
    $this->populateDynamicFields($media, $frontmatter, $definitions);

    $media->save();

    return $operation;
  }

}
