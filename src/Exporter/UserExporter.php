<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Export user accounts to Markdown files.
 *
 * Security considerations:
 *   - Hashed passwords are NEVER exported.
 *   - User 1 (superadmin) is exported but skipped on import if it already exists
 *     to avoid overwriting production credentials.
 *   - Roles are configuration (drush cex manages them); here we only export a
 *     list of role names for reference.
 *
 * Output structure:
 *   content_export/
 *     users/
 *       {uid}-{username}.md
 *
 * Example frontmatter:
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

  protected function typeDir(): string {
    return 'users';
  }

  /**
   * Export all users (except anonymous uid=0).
   *
   * @return string[] Generated file paths.
   */
  public function exportAll(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')  // exclude anonymous user
      ->execute();

    $files = [];
    foreach ($storage->loadMultiple($uids) as $user) {
      try {
        $result = $this->exportToFile($user);
        $files[] = is_array($result) ? $result['path'] : $result;
      }
      catch (\Exception $e) {
        $this->logger->error('UserExporter: @msg', ['@msg' => $e->getMessage()]);
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

    $dir = $this->contentExportDir() . '/' . $this->typeDir();
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
    // Roles (exclude 'authenticated' which is implicit)
    $roles = array_values(array_filter(
      $entity->getRoles(),
      fn($r) => !in_array($r, ['authenticated', 'anonymous'])
    ));

    $frontmatter = [];
    $frontmatter['uuid']   = $entity->uuid();
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

    // Roles as a readable list (actual role configuration comes from drush cex)
    if (!empty($roles)) {
      $frontmatter['roles'] = $roles;
      $frontmatter['____'] = NULL;
    }

    // Extra profile fields (bio, avatar, etc.)
    $this->applyDynamicGroups($frontmatter, $entity, 'user');

    // NEVER export the password
    // $frontmatter['pass'] is intentionally omitted

    $frontmatter = $this->addChecksum($frontmatter, '');
    return $this->serializer->serialize($frontmatter);
  }

}
