<?php

namespace Drupal\Tests\git_content\Unit\Serializer;

use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\git_content\Serializer\MarkdownSerializer
 * @group git_content
 */
class MarkdownSerializerTest extends UnitTestCase {

  private MarkdownSerializer $serializer;

  protected function setUp(): void {
    parent::setUp();
    $this->serializer = new MarkdownSerializer();
  }

  /**
   * @covers ::serialize
   * @covers ::deserialize
   */
  public function testRoundTrip(): void {
    $frontmatter = [
      'uuid'   => 'abc-123',
      'type'   => 'article',
      'lang'   => 'en',
      'draft'  => FALSE,
      'title'  => 'Hello World',
      'slug'   => 'hello-world',
    ];
    $body = "Some **markdown** body.";

    $md = $this->serializer->serialize($frontmatter, $body);
    $result = $this->serializer->deserialize($md);

    $this->assertEquals('abc-123', $result['frontmatter']['uuid']);
    $this->assertEquals('article', $result['frontmatter']['type']);
    $this->assertEquals('en', $result['frontmatter']['lang']);
    $this->assertFalse($result['frontmatter']['draft']);
    $this->assertEquals('Hello World', $result['frontmatter']['title']);
    $this->assertEquals($body, $result['body']);
  }

  /**
   * @covers ::serialize
   */
  public function testPlaceholderKeysBecomeBlanks(): void {
    $frontmatter = [
      'uuid'  => 'abc-123',
      '_'     => NULL,
      'title' => 'Hello',
      '__'    => NULL,
      'slug'  => 'hello',
    ];

    $md = $this->serializer->serialize($frontmatter);

    // The frontmatter block should contain blank lines where placeholders were.
    $this->assertMatchesRegularExpression('/uuid: abc-123\n\ntitle: Hello\n\nslug: hello/', $md);
  }

  /**
   * @covers ::deserialize
   */
  public function testPlaceholderKeysStrippedOnDeserialize(): void {
    $frontmatter = [
      'uuid'  => 'abc-123',
      '_'     => NULL,
      'title' => 'Hello',
      '___'   => NULL,
    ];

    $md = $this->serializer->serialize($frontmatter);
    $result = $this->serializer->deserialize($md);

    $this->assertArrayNotHasKey('_', $result['frontmatter']);
    $this->assertArrayNotHasKey('___', $result['frontmatter']);
    $this->assertArrayHasKey('uuid', $result['frontmatter']);
    $this->assertArrayHasKey('title', $result['frontmatter']);
  }

  /**
   * @covers ::deserialize
   */
  public function testDeserializeThrowsOnMissingFrontmatter(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->serializer->deserialize("No frontmatter here.\nJust body content.");
  }

  /**
   * @covers ::deserialize
   */
  public function testDeserializeThrowsOnMalformedFrontmatter(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->serializer->deserialize("---\nno closing delimiter\njust text");
  }

  /**
   * @covers ::serialize
   */
  public function testSerializeBoolean(): void {
    $md = $this->serializer->serialize(['draft' => TRUE, 'active' => FALSE]);
    $this->assertStringContainsString('draft: true', $md);
    $this->assertStringContainsString('active: false', $md);
  }

  /**
   * @covers ::serialize
   * @covers ::deserialize
   */
  public function testSerializeNullValue(): void {
    $md = $this->serializer->serialize(['translation_of' => NULL]);
    $result = $this->serializer->deserialize($md);
    $this->assertNull($result['frontmatter']['translation_of']);
  }

  /**
   * @covers ::serialize
   * @covers ::deserialize
   */
  public function testSerializeNestedArray(): void {
    $frontmatter = [
      'drupal' => [
        'uuid'     => 'abc-123',
        'checksum' => 'deadbeef',
      ],
    ];

    $md = $this->serializer->serialize($frontmatter);
    $result = $this->serializer->deserialize($md);

    $this->assertEquals('abc-123', $result['frontmatter']['drupal']['uuid']);
    $this->assertEquals('deadbeef', $result['frontmatter']['drupal']['checksum']);
  }

  /**
   * @covers ::serialize
   * @covers ::deserialize
   */
  public function testSerializeSequentialArray(): void {
    $frontmatter = ['roles' => ['editor', 'manager']];

    $md = $this->serializer->serialize($frontmatter);
    $result = $this->serializer->deserialize($md);

    $this->assertEquals(['editor', 'manager'], $result['frontmatter']['roles']);
  }

  /**
   * @covers ::deserialize
   */
  public function testBodyPreservedThroughRoundTrip(): void {
    $body = "# Heading\n\nParagraph with **bold** and _italic_.";
    $md = $this->serializer->serialize(['title' => 'Test'], $body);
    $result = $this->serializer->deserialize($md);
    $this->assertEquals($body, $result['body']);
  }

  /**
   * @covers ::deserialize
   */
  public function testEmptyBodyIsString(): void {
    $md = $this->serializer->serialize(['title' => 'No body']);
    $result = $this->serializer->deserialize($md);
    $this->assertIsString($result['body']);
    $this->assertEquals('', $result['body']);
  }

  /**
   * @covers ::flattenGroups
   */
  public function testFlattenGroupsMergesTaxonomyMediaReferences(): void {
    $frontmatter = [
      'title'      => 'Hello',
      'taxonomy'   => ['tags' => ['php', 'drupal']],
      'media'      => ['field_image' => 'photo.jpg'],
      'references' => ['field_related' => 'some-slug'],
    ];

    $flat = $this->serializer->flattenGroups($frontmatter);

    $this->assertArrayNotHasKey('taxonomy', $flat);
    $this->assertArrayNotHasKey('media', $flat);
    $this->assertArrayNotHasKey('references', $flat);
    $this->assertEquals(['php', 'drupal'], $flat['tags']);
    $this->assertEquals('photo.jpg', $flat['field_image']);
    $this->assertEquals('some-slug', $flat['field_related']);
  }

  /**
   * @covers ::flattenGroups
   */
  public function testFlattenGroupsMergesDrupalNamespace(): void {
    $frontmatter = [
      'title'  => 'Hello',
      'drupal' => ['uuid' => 'abc-123', 'checksum' => 'deadbeef'],
    ];

    $flat = $this->serializer->flattenGroups($frontmatter);

    $this->assertArrayNotHasKey('drupal', $flat);
    $this->assertEquals('abc-123', $flat['uuid']);
    $this->assertEquals('deadbeef', $flat['checksum']);
  }

  /**
   * @covers ::flattenGroups
   */
  public function testFlattenGroupsDoesNotOverwriteExistingRootKeys(): void {
    $frontmatter = [
      'uuid'   => 'root-uuid',
      'drupal' => ['uuid' => 'drupal-uuid', 'checksum' => 'abc'],
    ];

    $flat = $this->serializer->flattenGroups($frontmatter);

    // Root key 'uuid' must not be overwritten by drupal.uuid.
    $this->assertEquals('root-uuid', $flat['uuid']);
    $this->assertEquals('abc', $flat['checksum']);
  }

}
