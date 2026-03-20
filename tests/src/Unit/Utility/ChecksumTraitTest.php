<?php

namespace Drupal\Tests\git_content\Unit\Utility;

use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\git_content\Utility\ChecksumTrait
 * @group git_content
 */
class ChecksumTraitTest extends UnitTestCase {

  /**
   * Concrete test double that exposes protected methods.
   */
  private object $subject;

  protected function setUp(): void {
    parent::setUp();

    $this->subject = new class {
      use ChecksumTrait;

      public function checksum(array $frontmatter, string $body): string {
        return $this->computeChecksum($frontmatter, $body);
      }

      public function canonicalize(mixed $data): mixed {
        return $this->canonicalizeForHash($data);
      }
    };
  }

  /**
   * @covers ::computeChecksum
   */
  public function testChecksumIsDeterministic(): void {
    $fm = ['uuid' => 'abc', 'title' => 'Hello', 'draft' => FALSE];
    $a = $this->subject->checksum($fm, 'body text');
    $b = $this->subject->checksum($fm, 'body text');
    $this->assertEquals($a, $b);
  }

  /**
   * @covers ::computeChecksum
   */
  public function testChecksumChangesWhenFrontmatterChanges(): void {
    $fm1 = ['title' => 'Hello'];
    $fm2 = ['title' => 'Hello World'];
    $this->assertNotEquals(
      $this->subject->checksum($fm1, ''),
      $this->subject->checksum($fm2, '')
    );
  }

  /**
   * @covers ::computeChecksum
   */
  public function testChecksumChangesWhenBodyChanges(): void {
    $fm = ['title' => 'Hello'];
    $this->assertNotEquals(
      $this->subject->checksum($fm, 'original body'),
      $this->subject->checksum($fm, 'different body')
    );
  }

  /**
   * @covers ::computeChecksum
   *
   * The 'checksum' key itself must be stripped before hashing to avoid
   * circular dependency — exporting twice must produce the same hash.
   */
  public function testChecksumKeyIsExcludedFromHash(): void {
    $fm_without = ['title' => 'Hello', 'uuid' => 'abc'];
    $fm_with    = ['title' => 'Hello', 'uuid' => 'abc', 'checksum' => 'old-hash'];

    $this->assertEquals(
      $this->subject->checksum($fm_without, ''),
      $this->subject->checksum($fm_with, '')
    );
  }

  /**
   * @covers ::computeChecksum
   *
   * Separator placeholder keys (_ __ ___) are visual-only and must not affect
   * the hash, so re-exporting with different spacing produces the same checksum.
   */
  public function testSeparatorKeysExcludedFromHash(): void {
    $fm_plain = ['title' => 'Hello', 'slug' => 'hello'];
    $fm_separated = ['title' => 'Hello', '_' => NULL, 'slug' => 'hello', '__' => NULL];

    $this->assertEquals(
      $this->subject->checksum($fm_plain, ''),
      $this->subject->checksum($fm_separated, '')
    );
  }

  /**
   * @covers ::canonicalizeForHash
   *
   * Associative arrays must produce the same hash regardless of key insertion
   * order — critical since YAML and PHP arrays may differ in ordering.
   */
  public function testChecksumIsKeyOrderIndependent(): void {
    $fm_a = ['uuid' => 'abc', 'title' => 'Hello', 'lang' => 'en'];
    $fm_b = ['lang' => 'en', 'uuid' => 'abc', 'title' => 'Hello'];

    $this->assertEquals(
      $this->subject->checksum($fm_a, ''),
      $this->subject->checksum($fm_b, '')
    );
  }

  /**
   * @covers ::canonicalizeForHash
   *
   * Sequential arrays of scalars (e.g. tags) must produce the same hash
   * regardless of value order — taxonomy terms may come back in any order
   * from the DB.
   */
  public function testChecksumIsValueOrderIndependentForScalarArrays(): void {
    $fm_a = ['tags' => ['php', 'drupal', 'web']];
    $fm_b = ['tags' => ['drupal', 'web', 'php']];

    $this->assertEquals(
      $this->subject->checksum($fm_a, ''),
      $this->subject->checksum($fm_b, '')
    );
  }

  /**
   * @covers ::canonicalizeForHash
   */
  public function testCanonicalizeHandlesNestedStructures(): void {
    $data_a = ['drupal' => ['uuid' => 'abc', 'checksum' => 'x'], 'title' => 'T'];
    $data_b = ['title' => 'T', 'drupal' => ['checksum' => 'x', 'uuid' => 'abc']];

    $this->assertEquals(
      $this->subject->canonicalize($data_a),
      $this->subject->canonicalize($data_b)
    );
  }

  /**
   * @covers ::computeChecksum
   */
  public function testChecksumReturns40CharHex(): void {
    $hash = $this->subject->checksum(['title' => 'test'], 'body');
    $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);
  }

}
