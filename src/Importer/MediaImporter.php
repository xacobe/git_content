<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates media entities from Markdown frontmatter.
 */
class MediaImporter extends BaseImporter {

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception(t("The media frontmatter is missing 'bundle'."));
    }

    $existing = $short_uuid ? $this->findByUuid($short_uuid, 'media', $bundle) : NULL;

    if ($existing) {
      [$media, $operation] = $this->resolveTranslation($existing, $langcode);
    }
    else {
      $media = $this->entityTypeManager->getStorage('media')->create([
        'bundle'   => $bundle,
        'langcode' => $langcode,
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $media->set('name', $frontmatter['name'] ?? 'Unnamed');
    $media->set('status', $this->resolveStatus($frontmatter, 'published', 'draft'));

    $definitions = $this->fieldDiscovery->getFields('media', $bundle);
    $this->populateDynamicFields($media, $frontmatter, $definitions);

    $media->save();

    return $operation;
  }

}
