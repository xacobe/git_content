<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates media entities from Markdown frontmatter.
 */
class MediaImporter extends BaseImporter {

  public function getEntityType(): ?string {
    return 'media';
  }

  public function getImportWeight(): int {
    return 40;
  }

  public function extractEntityId(array $frontmatter): ?int {
    return !empty($frontmatter['mid']) ? (int) $frontmatter['mid'] : NULL;
  }

  public function resolveBundle(array $frontmatter): ?string {
    return $frontmatter['bundle'] ?? NULL;
  }

  public function getBundleQueryField(): ?string {
    return 'bundle';
  }

  public function import(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $mid        = !empty($frontmatter['mid']) ? (int) $frontmatter['mid'] : NULL;

    if (!$bundle) {
      throw new \Exception($this->t("The media frontmatter is missing 'bundle'."));
    }

    $create_values = ['bundle' => $bundle, 'langcode' => $langcode];
    $this->preserveEntityId('media', 'mid', 'mid', $create_values, $frontmatter);

    [$media, $operation] = $this->resolveOrCreate('media', $mid, $langcode, $create_values);

    $media->set('name', $frontmatter['name'] ?? 'Unnamed');
    $media->set('status', $this->resolveStatus($frontmatter));
    $this->setAuthor($media, $frontmatter);

    $definitions = $this->fieldDiscovery->getFields('media', $bundle);
    $this->populateDynamicFields($media, $frontmatter, $definitions);

    $media->save();

    return $operation;
  }

}
