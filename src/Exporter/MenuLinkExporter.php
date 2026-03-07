<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;

/**
 * Exporta los enlaces de menú personalizados (menu_link_content) a Markdown.
 *
 * La configuración del menú (nombre, descripción) la gestiona `drush cex`.
 * Este exportador se ocupa de los enlaces que el editor ha creado, que son
 * entidades de contenido con UUID propio y traducibles.
 *
 * Estructura de salida:
 *   content_export/
 *     menus/
 *       {menu-id}/
 *         {weight}-{slug}-{langcode}.md
 *
 * El prefijo de peso ({weight}) en el nombre de archivo permite reconstruir
 * el orden correcto al importar sin necesidad de parsear el contenido.
 *
 * Ejemplo de frontmatter generado:
 *   ---
 *   uuid: a1b2c3d4
 *   type: menu_link_content
 *   menu: main
 *   lang: es
 *   enabled: true
 *
 *   title: Inicio
 *   url: internal:/
 *   weight: 0
 *   expanded: false
 *
 *   parent: null
 *   translation_of: null
 *   ---
 */
class MenuLinkExporter extends BaseExporter {

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
   * Exporta todos los menu_link_content agrupados por menú.
   *
   * @return string[] Rutas de archivos generados.
   */
  public function exportAll(): array {
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $files = [];

    foreach ($storage->loadMultiple($ids) as $link) {
      try {
        $files[] = $this->exportToFile($link);
      }
      catch (\Exception $e) {
        \Drupal::logger('git_content')->error(
          'MenuLinkExporter: @msg', ['@msg' => $e->getMessage()]
        );
      }
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function exportToFile(EntityInterface $entity): string {
    $markdown = $this->export($entity);

    $menu_id = $entity->getMenuName();
    $dir = DRUPAL_ROOT . '/content_export/menus/' . $menu_id;
    $this->ensureDir($dir);

    // Prefijo de peso para preservar orden al listar por nombre de archivo
    $weight   = (int) $entity->getWeight();
    $prefix   = sprintf('%+05d', $weight); // ej. +0000, -0003, +0010
    $slug     = $this->getLinkSlug($entity);
    $langcode = $entity->language()->getId();
    $filename = $prefix . '-' . $slug . '-' . $langcode . '.md';
    $filepath = $dir . '/' . $filename;

    file_put_contents($filepath, $markdown);

    return $filepath;
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    $langcode = $entity->language()->getId();

    $frontmatter = [];
    $frontmatter['uuid']    = $this->shortenUuid($entity->uuid());
    $frontmatter['type']    = 'menu_link_content';
    $frontmatter['menu']    = $entity->getMenuName();
    $frontmatter['lang']    = $langcode;
    $frontmatter['enabled'] = (bool) $entity->isEnabled();
    $frontmatter['_']       = NULL;

    $frontmatter['title']    = $entity->getTitle();
    $frontmatter['url']      = $entity->get('link')->uri ?? '';
    $frontmatter['weight']   = (int) $entity->getWeight();
    $frontmatter['expanded'] = (bool) $entity->isExpanded();
    $frontmatter['__']       = NULL;

    // Referencia al enlace padre (UUID corto del menu_link_content padre)
    $frontmatter['parent'] = $this->getParentUuid($entity);

    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // Descripción como cuerpo si existe
    $body = '';
    if ($entity->hasField('description') && !$entity->get('description')->isEmpty()) {
      $body = $entity->get('description')->value ?? '';
    }

    return $this->serializer->serialize($frontmatter, $body);
  }

  /**
   * Devuelve el UUID corto del enlace padre, o null si es raíz.
   *
   * El campo 'parent' de menu_link_content almacena el plugin ID completo
   * en formato "menu_link_content:{uuid}". Extraemos solo el UUID corto.
   */
  protected function getParentUuid(EntityInterface $entity): ?string {
    $parent_plugin_id = $entity->getParentId();

    if (empty($parent_plugin_id)) {
      return NULL;
    }

    // Formato: "menu_link_content:{full-uuid}"
    if (str_starts_with($parent_plugin_id, 'menu_link_content:')) {
      $uuid = explode(':', $parent_plugin_id, 2)[1];
      return $this->shortenUuid($uuid);
    }

    // Si el padre es un enlace de otro plugin (ruta de módulo, etc.)
    // lo guardamos tal cual para no perder la referencia.
    return $parent_plugin_id;
  }

  /**
   * Genera un slug legible a partir del título del enlace.
   */
  protected function getLinkSlug(EntityInterface $entity): string {
    $title = $entity->getTitle() ?? 'link-' . $entity->id();
    return preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title));
  }

}
