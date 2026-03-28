<?php

namespace Drupal\git_content\Importer;

/**
 * Imports or updates user accounts from Markdown frontmatter.
 *
 * If the user already exists (by uid, name, or email) it is updated.
 * User 1 is only partially updated to avoid overwriting production credentials.
 * Passwords are never imported; new users receive a random password that must
 * be reset manually.
 */
class UserImporter extends BaseImporter {

  public function handles(string $entity_type): bool {
    return $entity_type === 'user';
  }

  public function import(array $frontmatter, string $body): string {
    $uid        = !empty($frontmatter['uid']) ? (int) $frontmatter['uid'] : NULL;
    $name       = $frontmatter['name'] ?? NULL;
    $mail       = $frontmatter['mail'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$name) {
      throw new \Exception($this->t("The user frontmatter is missing 'name'."));
    }

    // Look up by uid first, then by name, then by email.
    $existing = $uid ? $this->entityTypeManager->getStorage('user')->load($uid) : NULL;
    $existing ??= $name ? $this->loadOneByProperty('user', 'name', $name) : NULL;
    $existing ??= $mail ? $this->loadOneByProperty('user', 'mail', $mail) : NULL;

    // Never import the anonymous user (uid=0).
    if ($existing && (int) $existing->id() === 0) {
      return 'skipped';
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
      $create = [
        'langcode' => $langcode,
        // Secure random password; must be reset manually.
        'pass'     => $this->passwordGenerator->generate(20),
      ];
      // min_id=2: never force uid=1 (handled by the early-return above).
      $this->preserveEntityId('user', 'uid', 'uid', $create, $frontmatter, 2);
      $user = $this->entityTypeManager->getStorage('user')->create($create);
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
