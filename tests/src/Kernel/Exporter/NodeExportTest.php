<?php

namespace Drupal\Tests\git_content\Kernel\Exporter;

use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Tests NodeExporter frontmatter structure.
 *
 * @group git_content
 */
class NodeExportTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'filter',
    'text',
    'git_content',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node']);
    $this->installSchema('node', ['node_access']);

    // Create a minimal node type.
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    node_add_body_field(NodeType::load('article'));
  }

  /**
   * Basic frontmatter keys must be present after export.
   */
  public function testNodeExportFrontmatterStructure(): void {
    $node = Node::create([
      'type'   => 'article',
      'title'  => 'Test Article',
      'status' => 1,
    ]);
    $node->save();

    $markdown = $this->container->get('git_content.node_exporter')->export($node);
    $serializer = new MarkdownSerializer();
    $parsed = $serializer->deserialize($markdown);
    $fm = $parsed['frontmatter'];

    // SSG-visible fields at root.
    $this->assertArrayHasKey('type', $fm);
    $this->assertArrayHasKey('lang', $fm);
    $this->assertArrayHasKey('draft', $fm);
    $this->assertArrayHasKey('title', $fm);
    $this->assertArrayHasKey('slug', $fm);
    $this->assertArrayHasKey('date', $fm);
    // Drupal-internal fields under drupal:.
    $this->assertArrayHasKey('drupal', $fm);
    $this->assertArrayHasKey('uuid', $fm['drupal']);
    $this->assertArrayHasKey('checksum', $fm['drupal']);
  }

  /**
   * draft: false when node is published.
   */
  public function testPublishedNodeHasDraftFalse(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Published', 'status' => 1]);
    $node->save();

    $markdown = $this->container->get('git_content.node_exporter')->export($node);
    $fm = (new MarkdownSerializer())->deserialize($markdown)['frontmatter'];

    $this->assertFalse($fm['draft']);
  }

  /**
   * draft: true when node is unpublished.
   */
  public function testUnpublishedNodeHasDraftTrue(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Draft', 'status' => 0]);
    $node->save();

    $markdown = $this->container->get('git_content.node_exporter')->export($node);
    $fm = (new MarkdownSerializer())->deserialize($markdown)['frontmatter'];

    $this->assertTrue($fm['draft']);
  }

  /**
   * uuid and translation_of go under drupal: namespace, not at root.
   */
  public function testDrupalNamespaceContainsInternalFields(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Namespaced', 'status' => 1]);
    $node->save();

    $markdown = $this->container->get('git_content.node_exporter')->export($node);
    $fm = (new MarkdownSerializer())->deserialize($markdown)['frontmatter'];

    // uuid and checksum live under drupal:.
    $this->assertArrayHasKey('uuid', $fm['drupal']);
    $this->assertArrayHasKey('checksum', $fm['drupal']);
    // translation_of must not appear at root (it's in drupal: when set).
    $this->assertArrayNotHasKey('translation_of', $fm);
  }

  /**
   * Body content is preserved in the Markdown section.
   */
  public function testBodyIsExportedAsMarkdownBody(): void {
    $node = Node::create([
      'type'  => 'article',
      'title' => 'With Body',
      'body'  => ['value' => '<p>Hello world</p>', 'format' => 'basic_html'],
      'status' => 1,
    ]);
    $node->save();

    $markdown = $this->container->get('git_content.node_exporter')->export($node);
    $parsed = (new MarkdownSerializer())->deserialize($markdown);

    $this->assertNotEmpty($parsed['body']);
    $this->assertStringContainsString('Hello world', $parsed['body']);
  }

  /**
   * Exporting twice produces the same checksum (determinism).
   */
  public function testDoubleExportProducesSameChecksum(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Stable', 'status' => 1]);
    $node->save();

    $exporter   = $this->container->get('git_content.node_exporter');
    $serializer = new MarkdownSerializer();

    $fm1 = $serializer->deserialize($exporter->export($node))['frontmatter'];
    $fm2 = $serializer->deserialize($exporter->export($node))['frontmatter'];

    $this->assertEquals($fm1['drupal']['checksum'], $fm2['drupal']['checksum']);
  }

  /**
   * exportToFile in dry-run returns the expected path structure without
   * writing to disk.
   */
  public function testExportToFileDryRunReturnsPath(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Path Test', 'status' => 1]);
    $node->save();

    $result = $this->container->get('git_content.node_exporter')
      ->exportToFile($node, TRUE);

    $this->assertArrayHasKey('path', $result);
    $this->assertArrayHasKey('skipped', $result);
    $this->assertStringContainsString('/content/articles/', $result['path']);
    $this->assertStringEndsWith('.en.md', $result['path']);
    // File must not have been written.
    $this->assertFileDoesNotExist($result['path']);
  }

}
