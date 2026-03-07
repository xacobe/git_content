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
 *         {langcode}/
 *           {slug}[__{parent-slug}...].md
 *
 * El orden y la jerarquía se reconstruyen a partir del frontmatter (`weight`, `parent`).
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
        $result = $this->exportToFile($link);
        $files[] = is_array($result) ? $result['path'] : $result;
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
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity): array {
    $markdown = $this->export($entity);

    $menu_id  = $entity->getMenuName();
    $langcode = $entity->language()->getId();
    $dir = DRUPAL_ROOT . '/content_export/menus/' . $menu_id . '/' . $langcode;
    $this->ensureDir($dir);

    // Construimos el nombre de archivo con la jerarquía de padres:
    // padre__hijo__nieto.md
    $filename = $this->getLinkPath($entity) . '.md';
    $filepath = $dir . '/' . $filename;

    $written = $this->writeIfChanged($filepath, $markdown);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * Construye el nombre de archivo de un enlace de menú incluyendo su
   * cadena de padres separados por doble guión bajo (__).
   */
  protected function getLinkPath(EntityInterface $entity): string {
    $slug = $this->getLinkSlug($entity);
    $parent_id = $entity->getParentId();

    // Si no tiene padre, retornamos solo el slug.
    if (empty($parent_id) || !str_starts_with($parent_id, 'menu_link_content:')) {
      return $slug;
    }

    // Extraemos UUID del plugin_id.
    $parent_uuid = substr($parent_id, strlen('menu_link_content:'));

    // Intentamos cargar la entidad padre por UUID.
    $parent = $this->loadMenuLinkByUuid($parent_uuid);
    if (!$parent) {
      return $slug;
    }

    $parent_path = $this->getLinkPath($parent);
    return $parent_path . '__' . $slug;
  }

  /**
   * Carga un menu_link_content por su UUID (completo o corto).
   */
  protected function loadMenuLinkByUuid(string $uuid): ?\Drupal\Core\Entity\EntityInterface {
    $storage = $this->entityTypeManager->getStorage('menu_link_content');

    // Intentar cargar por UUID completo.
    $links = $storage->loadByProperties(['uuid' => $uuid]);
    if (!empty($links)) {
      return reset($links);
    }

    // Si no existe, intentar con uuid corto (8 chars sin guiones).
    $clean = str_replace('-', '', $uuid);
    if (strlen($clean) === 8) {
      foreach ($storage->loadMultiple() as $link) {
        if (substr(str_replace('-', '', $link->uuid()), 0, 8) === $clean) {
          return $link;
        }
      }
    }

    return NULL;
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

    $frontmatter = $this->addChecksum($frontmatter, $body);
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
