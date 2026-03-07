<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Exporta nodos a archivos Markdown con frontmatter YAML.
 *
 * Estructura de salida:
 *   content_export/
 *     {bundle}/
 *       {slug}-{langcode}.md
 */
class NodeExporter extends BaseExporter {

  /**
   * {@inheritdoc}
   */
  public function exportToFile(EntityInterface $entity): string {
    $markdown = $this->export($entity);

    $dir = DRUPAL_ROOT . '/content_export/' . $entity->bundle();
    $this->ensureDir($dir);

    $slug     = $this->getSlug($entity);
    $langcode = $entity->language()->getId();
    $filepath = $dir . '/' . $slug . '-' . $langcode . '.md';

    file_put_contents($filepath, $markdown);

    return $filepath;
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();

    // --- Campos base ---
    $frontmatter = [];
    $frontmatter['uuid']   = $this->shortenUuid($entity->uuid());
    $frontmatter['type']   = $entity->bundle();
    $frontmatter['lang']   = $langcode;
    $frontmatter['status'] = $entity->isPublished() ? 'published' : 'draft';
    $frontmatter['_']      = NULL; // línea en blanco

    $frontmatter['title']  = $entity->label();
    $frontmatter['slug']   = $this->getSlug($entity);
    $frontmatter['__']     = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['changed'] = date('Y-m-d', $entity->getChangedTime());
    $frontmatter['___']     = NULL;

    $frontmatter['path']    = $this->getPathAlias($entity);
    $frontmatter['____']    = NULL;

    // --- Campos dinámicos agrupados ---
    $groups = $this->buildDynamicGroups($entity, 'node');

    if (!empty($groups['taxonomy'])) {
      $frontmatter['taxonomy'] = $groups['taxonomy'];
      $frontmatter['_____']    = NULL;
    }

    if (!empty($groups['media'])) {
      $frontmatter['media']  = $groups['media'];
      $frontmatter['______'] = NULL;
    }

    if (!empty($groups['references'])) {
      $frontmatter['references'] = $groups['references'];
      $frontmatter['_______']    = NULL;
    }

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // --- Cuerpo ---
    $body = '';
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body = $this->serializer->htmlToMarkdown($entity->get('body')->value);
    }

    return $this->serializer->serialize($frontmatter, $body);
  }

}
