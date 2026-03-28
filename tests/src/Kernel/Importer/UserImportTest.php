<?php

namespace Drupal\Tests\git_content\Kernel\Importer;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests UserImporter behavior.
 *
 * @group git_content
 */
class UserImportTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'git_content',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
  }

  /**
   * Importing a user with a name that resolves to uid=0 must return 'skipped'.
   *
   * The anonymous user is a Drupal system entity and must never be overwritten
   * by an import — it has no meaningful content to sync.
   */
  public function testAnonymousUserIsSkipped(): void {
    // Create a dummy user named 'Anonymous' that resolves to an existing uid=0.
    // We simulate this by ensuring a user with uid=0 is found during lookup.
    // In practice the anonymous user's name is '' but we test the guard on uid.
    $anonymous = User::load(0);
    if (!$anonymous) {
      $this->markTestSkipped('Anonymous user not available in this environment.');
    }

    // Build frontmatter that would resolve to uid=0 via uid lookup.
    $frontmatter = [
      'uid'  => (int) $anonymous->id(),
      'name' => $anonymous->getAccountName() ?: 'anonymous',
      'mail' => '',
      'lang' => 'en',
    ];

    $result = $this->container->get('git_content.user_importer')
      ->import($frontmatter, '');

    $this->assertEquals('skipped', $result);
  }

  /**
   * A new user is created when no matching entity exists.
   */
  public function testNewUserIsImported(): void {
    $frontmatter = [
      'name'   => 'new-editor',
      'mail'   => 'new-editor@example.com',
      'status' => 'active',
      'lang'   => 'en',
      'drupal' => [
        'uid'      => 100,
        'lang'     => 'en',
        'mail'     => 'new-editor@example.com',
        'timezone' => 'UTC',
        'checksum' => 'abc123',
      ],
    ];

    $result = $this->container->get('git_content.user_importer')
      ->import($frontmatter, '');

    $this->assertEquals('imported', $result);

    $users = $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->loadByProperties(['name' => 'new-editor']);
    $this->assertNotEmpty($users);
  }

  /**
   * An existing user matched by uid is updated, not duplicated.
   */
  public function testExistingUserIsUpdated(): void {
    $user = User::create([
      'name'   => 'existing-user',
      'mail'   => 'existing@example.com',
      'status' => 1,
    ]);
    $user->save();

    $frontmatter = [
      'uid'    => (int) $user->id(),
      'name'   => 'existing-user',
      'status' => 'active',
      'lang'   => 'en',
      'drupal' => [
        'uid'      => (int) $user->id(),
        'lang'     => 'en',
        'mail'     => 'existing@example.com',
        'timezone' => 'Europe/Madrid',
        'checksum' => 'abc',
      ],
    ];

    $result = $this->container->get('git_content.user_importer')
      ->import($frontmatter, '');

    $this->assertEquals('updated', $result);

    // Only one user with this name must exist.
    $users = $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->loadByProperties(['name' => 'existing-user']);
    $this->assertCount(1, $users);
  }

  /**
   * User 1 (superadmin) is never fully overwritten — only timezone is updated.
   * This prevents production credentials from being replaced by a Git import.
   */
  public function testUser1IsOnlyPartiallyUpdated(): void {
    // User 1 is installed during entity schema setup.
    $user1 = User::load(1);
    if (!$user1) {
      $this->markTestSkipped('User 1 not available in this environment.');
    }

    $originalName = $user1->getAccountName();

    $frontmatter = [
      'uid'    => 1,
      'name'   => 'should-not-change',
      'status' => 'active',
      'lang'   => 'en',
      'drupal' => [
        'uid'      => 1,
        'lang'     => 'en',
        'mail'     => 'admin@example.com',
        'timezone' => 'America/New_York',
        'checksum' => 'abc',
      ],
    ];

    $result = $this->container->get('git_content.user_importer')
      ->import($frontmatter, '');

    $this->assertEquals('updated', $result);

    // Name must not have changed.
    $user1 = User::load(1);
    $this->assertEquals($originalName, $user1->getAccountName());
  }

  /**
   * Importing a user without a name must throw an exception.
   */
  public function testMissingNameThrowsException(): void {
    $this->expectException(\Exception::class);
    $this->container->get('git_content.user_importer')
      ->import(['lang' => 'en'], '');
  }

}
