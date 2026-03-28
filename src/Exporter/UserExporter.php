<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\git_content\Utility\ManagedFields;

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
 *       {username}.md
 *
 * Example frontmatter:
 *   ---
 *   type: user
 *   status: active
 *
 *   name: editor
 *   created: 2026-01-10
 *   avatar: photo.webp
 *
 *   roles:
 *     - editor
 *     - content_manager
 *
 *   # Drupal
 *   uuid: a1b2c3d4
 *   lang: en
 *   mail: editor@example.com
 *   timezone: Europe/Madrid
 *   checksum: …
 *   ---
 */
class UserExporter extends BaseExporter {

  /**
   * user_picture is handled explicitly as 'avatar'.
   * preferred_langcode is a Drupal UI pref set from 'lang' on import — skip it.
   */
  protected array $managedFields = [
    ...ManagedFields::CORE,
    'body', 'uid', 'revision_uid', 'metatag',
    'user_picture',
    'preferred_langcode',
  ];

  public function getEntityType(): string {
    return 'user';
  }

  protected function typeDir(): string {
    return 'users';
  }

  /**
   * {@inheritdoc}
   *
   * @return array{path: string, skipped: bool}
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array {
    if ((int) $entity->id() === 0) {
      return ['path' => '', 'skipped' => TRUE];
    }

    $markdown = $this->export($entity);

    $dir = $this->contentExportDir() . '/' . $this->typeDir();
    $this->ensureDir($dir, $dryRun);

    $username = $this->slugify($entity->getAccountName());
    $filepath = $dir . '/' . $username . '.md';

    $written = $this->writeIfChanged($filepath, $markdown, $dryRun);
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
    $frontmatter['status'] = $entity->isActive() ? 'active' : 'blocked';

    $frontmatter['name']    = $entity->getAccountName();
    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());

    if ($entity->hasField('user_picture') && !$entity->get('user_picture')->isEmpty()) {
      $picture = $entity->get('user_picture')->entity;
      if ($picture) {
        $frontmatter['avatar'] = basename($picture->getFileUri());
      }
    }

    // Roles as a readable list (actual role configuration comes from drush cex)
    if (!empty($roles)) {
      $frontmatter['roles'] = $roles;
    }

    // Extra profile fields (bio, avatar, etc.)
    $this->applyDynamicGroups($frontmatter, $entity, 'user');

    // Drupal-internal: not useful for SSG. NEVER export the password.
    $frontmatter['uid']      = (int) $entity->id();
    $frontmatter['lang']     = $entity->language()->getId();
    $frontmatter['mail']     = $entity->getEmail();
    $frontmatter['timezone'] = $entity->getTimeZone() ?: 'UTC';

    $frontmatter = $this->wrapDrupalNamespace($frontmatter, '', ['lang', 'mail', 'timezone']);
    return $this->serializer->serialize($frontmatter);
  }

}
