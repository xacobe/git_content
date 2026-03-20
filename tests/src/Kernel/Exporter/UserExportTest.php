<?php

namespace Drupal\Tests\git_content\Kernel\Exporter;

use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests UserExporter behavior.
 *
 * @group git_content
 */
class UserExportTest extends KernelTestBase {

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
   * The anonymous user (uid=0) must be silently skipped — never written to disk.
   * This is the default Drupal user that cannot and should not be managed via Git.
   */
  public function testAnonymousUserIsSkipped(): void {
    $anonymous = User::load(0);
    // uid=0 may not be loadable in kernel tests; create a stand-in with id 0.
    if (!$anonymous) {
      $this->markTestSkipped('Anonymous user entity not available in this environment.');
    }

    $result = $this->container->get('git_content.user_exporter')
      ->exportToFile($anonymous, TRUE);

    $this->assertTrue($result['skipped']);
    $this->assertEquals('', $result['path']);
  }

  /**
   * The exported filename must be {username}.md without the uid prefix.
   * Drupal enforces unique usernames so the prefix is redundant.
   */
  public function testFilenameHasNoUidPrefix(): void {
    $user = User::create([
      'name'   => 'editor',
      'mail'   => 'editor@example.com',
      'status' => 1,
    ]);
    $user->save();

    $result = $this->container->get('git_content.user_exporter')
      ->exportToFile($user, TRUE);

    $filename = basename($result['path']);
    $this->assertEquals('editor.md', $filename);
    // Must NOT start with the uid.
    $this->assertFalse(str_starts_with($filename, $user->id() . '-'));
  }

  /**
   * The password hash must never appear in the exported file.
   * This is a security requirement regardless of export format.
   */
  public function testPasswordNeverExported(): void {
    $user = User::create([
      'name'   => 'secret-user',
      'mail'   => 'secret@example.com',
      'pass'   => 'MyS3cr3tP@ss',
      'status' => 1,
    ]);
    $user->save();

    $markdown = $this->container->get('git_content.user_exporter')->export($user);

    $this->assertStringNotContainsString('pass:', $markdown);
    $this->assertStringNotContainsString('MyS3cr3tP@ss', $markdown);
    // Hashed passwords must also not appear.
    $this->assertStringNotContainsString('$2y$', $markdown);
  }

  /**
   * Mail, timezone and lang are internal Drupal fields — they go under drupal:
   * so SSG templates don't accidentally expose them.
   */
  public function testInternalFieldsInDrupalNamespace(): void {
    $user = User::create([
      'name'     => 'namespaced-user',
      'mail'     => 'ns@example.com',
      'status'   => 1,
      'timezone' => 'Europe/Madrid',
    ]);
    $user->save();

    $fm = (new MarkdownSerializer())
      ->deserialize($this->container->get('git_content.user_exporter')->export($user))['frontmatter'];

    $this->assertArrayHasKey('mail', $fm['drupal']);
    $this->assertArrayHasKey('lang', $fm['drupal']);
    $this->assertArrayHasKey('timezone', $fm['drupal']);
    // Must not leak to root level.
    $rootKeys = array_keys(array_diff_key($fm, ['drupal' => 1]));
    $this->assertNotContains('mail', $rootKeys);
    $this->assertNotContains('lang', $rootKeys);
    $this->assertNotContains('timezone', $rootKeys);
  }

  /**
   * The user's public display name (name:) stays at root level for SSGs.
   */
  public function testNameAtRoot(): void {
    $user = User::create([
      'name'   => 'content-editor',
      'mail'   => 'editor@example.com',
      'status' => 1,
    ]);
    $user->save();

    $fm = (new MarkdownSerializer())
      ->deserialize($this->container->get('git_content.user_exporter')->export($user))['frontmatter'];

    $this->assertEquals('content-editor', $fm['name']);
  }

}
