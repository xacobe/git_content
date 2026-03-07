<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Exporta términos de taxonomía a archivos Markdown con frontmatter YAML.
 *
 * Estructura de salida:
 *   content_export/
 *     taxonomy/
 *       {vocabulary}/
 *         {slug}-{langcode}.md
 */
class TaxonomyExporter extends BaseExporter {

  /**
   * {@inheritdoc}
   */
  public function exportToFile(EntityInterface $entity): string {
    $markdown = $this->export($entity);

    $dir = DRUPAL_ROOT . '/content_export/taxonomy/' . $entity->bundle();
    $this->ensureDir($dir);

    $slug     = $this->getTermSlug($entity);
    $langcode = $entity->language()->getId();
    $filepath = $dir . '/' . $slug . '-' . $langcode . '.md';

    file_put_contents($filepath, $markdown);

    return $filepath;
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $frontmatter = [];
    $frontmatter['uuid']       = $this->shortenUuid($entity->uuid());
    $frontmatter['type']       = 'taxonomy_term';
    $frontmatter['vocabulary'] = $entity->bundle();
    $frontmatter['lang']       = $entity->language()->getId();
    $frontmatter['_']          = NULL;

    $frontmatter['name']   = $entity->label();
    $frontmatter['slug']   = $this->getTermSlug($entity);
    $frontmatter['weight'] = $entity->get('weight')->value ?? 0;
    $frontmatter['__']     = NULL;

    // Término padre
    $parent_tid = $this->getParentTid($entity);
    $frontmatter['parent'] = $parent_tid;
    $frontmatter['___']    = NULL;

    // Campos dinámicos extra del vocabulario
    $groups = $this->buildDynamicGroups($entity, 'taxonomy_term');

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Descripción como cuerpo
    $body = '';
    if ($entity->hasField('description') && !$entity->get('description')->isEmpty()) {
      $body = $this->serializer->htmlToMarkdown($entity->get('description')->value ?? '');
    }

    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Genera un slug para el término: nombre en minúsculas con guiones.
   */
  protected function getTermSlug(EntityInterface $entity): string {
    $name = $entity->label() ?? 'term-' . $entity->id();
    return preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
  }

  /**
   * Obtiene el TID del término padre, o NULL si es raíz.
   */
  protected function getParentTid(EntityInterface $entity): ?int {
    if ($entity->hasField('parent')) {
      $parents = $entity->get('parent')->getValue();
      if (!empty($parents[0]['target_id']) && (int) $parents[0]['target_id'] !== 0) {
        return (int) $parents[0]['target_id'];
      }
    }
    return NULL;
  }

}
