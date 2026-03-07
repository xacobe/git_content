<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;

/**
 * Exporta cuentas de usuario a archivos Markdown.
 *
 * Consideraciones de seguridad:
 *   - Las contraseñas hasheadas NO se exportan nunca.
 *   - El usuario 1 (superadmin) se exporta pero al importar se omite si ya
 *     existe, para no sobreescribir credenciales de producción.
 *   - Los roles son config (drush cex los gestiona), aquí solo se exporta
 *     la lista de nombres de rol como referencia informativa.
 *
 * Estructura de salida:
 *   content_export/
 *     users/
 *       {uid}-{username}.md
 *
 * Ejemplo de frontmatter:
 *   ---
 *   uuid: a1b2c3d4
 *   type: user
 *   lang: en
 *   status: active
 *
 *   name: editor
 *   mail: editor@example.com
 *   timezone: Europe/Madrid
 *
 *   created: 2026-01-10
 *   changed: 2026-03-01
 *
 *   roles:
 *     - editor
 *     - content_manager
 *   ---
 */
class UserExporter extends BaseExporter {

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
   * Exporta todos los usuarios (excepto el anónimo uid=0).
   *
   * @return string[] Rutas de archivos generados.
   */
  public function exportAll(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')  // excluir usuario anónimo
      ->execute();

    $files = [];
    foreach ($storage->loadMultiple($uids) as $user) {
      try {
        $result = $this->exportToFile($user);
        $files[] = is_array($result) ? $result['path'] : $result;
      }
      catch (\Exception $e) {
        \Drupal::logger('git_content')->error(
          'UserExporter: @msg', ['@msg' => $e->getMessage()]
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

    $dir = DRUPAL_ROOT . '/content_export/users';
    $this->ensureDir($dir);

    $username = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($entity->getAccountName()));
    $filepath = $dir . '/' . $entity->id() . '-' . $username . '.md';

    $written = $this->writeIfChanged($filepath, $markdown);
    return ['path' => $filepath, 'skipped' => !$written];
  }

  /**
   * {@inheritdoc}
   */
  public function export(EntityInterface $entity): string {
    // Roles (excluir 'authenticated' que es implícito)
    $roles = array_values(array_filter(
      $entity->getRoles(),
      fn($r) => !in_array($r, ['authenticated', 'anonymous'])
    ));

    $frontmatter = [];
    $frontmatter['uuid']   = $this->shortenUuid($entity->uuid());
    $frontmatter['type']   = 'user';
    $frontmatter['lang']   = $entity->language()->getId();
    $frontmatter['status'] = $entity->isActive() ? 'active' : 'blocked';
    $frontmatter['_']      = NULL;

    $frontmatter['name']     = $entity->getAccountName();
    $frontmatter['mail']     = $entity->getEmail();
    $frontmatter['timezone'] = $entity->getTimeZone() ?: 'UTC';
    $frontmatter['__']       = NULL;

    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['changed'] = date('Y-m-d', $entity->getChangedTime());
    $frontmatter['___']     = NULL;

    // Roles como lista legible (la configuración real viene de drush cex)
    if (!empty($roles)) {
      $frontmatter['roles'] = $roles;
      $frontmatter['____'] = NULL;
    }

    // Campos extra del perfil de usuario (bio, avatar, etc.)
    $groups = $this->buildDynamicGroups($entity, 'user');

    if (!empty($groups['media'])) {
      $frontmatter['media'] = $groups['media'];
    }

    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }

    // NUNCA exportar la contraseña
    // $frontmatter['pass'] se omite intencionalmente

    $frontmatter = $this->addChecksum($frontmatter, '');
    return $this->serializer->serialize($frontmatter);
  }

}
