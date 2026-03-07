<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Exporta bloques de contenido personalizados (block_content) a Markdown.
 *
 * Los block_content son entidades de contenido con bundles y campos propios,
 * equivalentes a nodos en estructura. NO exporta los bloques de configuración
 * (bloque colocados en regiones, visibilidad, etc.) ya que eso lo gestiona
 * `drush config:export` (cex).
 *
 * Estructura de salida:
 *   content_export/
 *     blocks/
 *       {bundle}/
 *         {uuid-slug}-{langcode}.md
 */
class BlockContentExporter extends BaseExporter {

  /**
   * {@inheritdoc}
   */
  public function exportToFile(EntityInterface $entity): string {
    $markdown = $this->export($entity);

    $langcode = $entity->language()->getId();
    $dir = DRUPAL_ROOT . '/content_export/blocks/' . $entity->bundle() . '/' . $langcode;
    $this->ensureDir($dir);

    $slug     = $this->getBlockSlug($entity);
    $filepath = $dir . '/' . $slug . '.md';

    file_put_contents($filepath, $markdown);

    return $filepath;
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();

    $frontmatter = [];
    $frontmatter['uuid']   = $this->shortenUuid($entity->uuid());
    $frontmatter['type']   = 'block_content';
    $frontmatter['bundle'] = $entity->bundle();
    $frontmatter['lang']   = $langcode;
    $frontmatter['status'] = $entity->get('status')->value ? 'published' : 'draft';
    $frontmatter['_']      = NULL;

    $frontmatter['title']  = $entity->label();
    $frontmatter['slug']   = $this->getBlockSlug($entity);
    $frontmatter['__']     = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->get('changed')->value ?? time());
    $frontmatter['___']     = NULL;

    // Campos dinámicos del bundle (igual que NodeExporter)
    $groups = $this->buildDynamicGroups($entity, 'block_content');

    if (!empty($groups['taxonomy'])) {
      $frontmatter['taxonomy'] = $groups['taxonomy'];
      $frontmatter['____']     = NULL;
    }

    if (!empty($groups['media'])) {
      $frontmatter['media']  = $groups['media'];
      $frontmatter['_____']  = NULL;
    }

    if (!empty($groups['references'])) {
      $frontmatter['references'] = $groups['references'];
      $frontmatter['______']     = NULL;
    }

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Cuerpo: block_content puede tener campo body
    $body = '';
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body = $this->serializer->htmlToMarkdown($entity->get('body')->value);
    }

    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Genera un slug para el bloque a partir de su info/label.
   * Usa el ID interno si no hay un nombre legible.
   */
  protected function getBlockSlug(EntityInterface $entity): string {
    $label = $entity->label() ?? 'block';
    $slug  = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($label));
    $slug  = trim($slug, '-');

    // Añadimos el ID al final para evitar colisiones entre bloques con mismo título.
    return $slug ? $slug . '-' . $entity->id() : 'block-' . $entity->id();
  }

}
