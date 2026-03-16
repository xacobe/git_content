<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates user accounts from Markdown frontmatter.
 *
 * If the user already exists (by UUID, name, or email) it is updated.
 * User 1 is only partially updated to avoid overwriting production credentials.
 * Passwords are never imported; new users receive a random password that must
 * be reset manually.
 */
class UserImporter extends BaseImporter {

  public function import(array $frontmatter, string $body): string {
    $uuid = $frontmatter['uuid'] ?? NULL;
    $name       = $frontmatter['name'] ?? NULL;
    $mail       = $frontmatter['mail'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$name) {
      throw new \Exception(t("The user frontmatter is missing 'name'."));
    }

    // Look up by UUID first, then by name, then by email.
    $existing = $uuid ? $this->findByUuidGlobal($uuid, 'user') : NULL;

    if (!$existing && $name) {
      $users = $this->entityTypeManager->getStorage('user')
        ->loadByProperties(['name' => $name]);
      $existing = !empty($users) ? reset($users) : NULL;
    }

    if (!$existing && $mail) {
      $users = $this->entityTypeManager->getStorage('user')
        ->loadByProperties(['mail' => $mail]);
      $existing = !empty($users) ? reset($users) : NULL;
    }

    // If user 1 already exists, only update non-critical data.
    if ($existing && (int) $existing->id() === 1) {
      $existing->set('timezone', $frontmatter['timezone'] ?? 'UTC');
      $existing->save();
      return 'updated';
    }

    if ($existing) {
      $user = $existing;
      $operation = 'updated';
    }
    else {
      $user = $this->entityTypeManager->getStorage('user')->create([
        'langcode' => $langcode,
        'uuid'     => $uuid ?? $this->uuid->generate(),
        // Secure random password; must be reset manually.
        'pass'     => $this->passwordGenerator->generate(20),
      ]);
      $operation = 'imported';
    }

    $user->set('name', $name);
    $user->set('status', ($frontmatter['status'] ?? 'active') === 'active' ? 1 : 0);
    $user->set('langcode', $langcode);
    $user->set('preferred_langcode', $langcode);
    $user->set('timezone', $frontmatter['timezone'] ?? 'UTC');

    if ($mail) {
      $user->set('mail', $mail);
      $user->set('init', $mail);
    }

    if (!empty($frontmatter['created'])) {
      $user->set('created', $this->parseDate($frontmatter['created']));
    }

    // Assign roles (they must already exist in config).
    if (!empty($frontmatter['roles']) && is_array($frontmatter['roles'])) {
      foreach ($frontmatter['roles'] as $role) {
        $user->addRole($role);
      }
    }

    // Extra profile fields.
    $definitions = $this->fieldDiscovery->getFields('user', 'user');
    $this->populateDynamicFields($user, $frontmatter, $definitions);

    $user->save();

    return $operation;
  }

}
