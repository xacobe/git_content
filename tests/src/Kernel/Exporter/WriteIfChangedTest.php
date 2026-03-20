<?php

namespace Drupal\Tests\git_content\Kernel\Exporter;

use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests the writeIfChanged / dry-run logic in BaseExporter.
 *
 * The dry-run mode compares checksums rather than raw file content.
 * This is the key invariant: a file that was manually edited in Git
 * (import concern) must NOT appear as "needs export" if the entity
 * itself hasn't changed (export concern).
 *
 * @group git_content
 */
class WriteIfChangedTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'filter',
    'text',
    'git_content',
  ];

  private string $tempDir;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node']);
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // Use a temp directory so tests don't touch the real codebase.
    $this->tempDir = sys_get_temp_dir() . '/git_content_test_' . uniqid();
    mkdir($this->tempDir, 0775, TRUE);
  }

  protected function tearDown(): void {
    parent::tearDown();
    // Clean up temp files.
    if (is_dir($this->tempDir)) {
      array_map('unlink', glob($this->tempDir . '/*') ?: []);
      rmdir($this->tempDir);
    }
  }

  /**
   * A file that does not yet exist is always considered "changed" (new).
   * In real mode it is written; in dry-run mode it is reported as pending.
   */
  public function testNewFileIsAlwaysWritten(): void {
    $node = Node::create(['type' => 'page', 'title' => 'New', 'status' => 1]);
    $node->save();

    $exporter = $this->container->get('git_content.node_exporter');
    $markdown = $exporter->export($node);
    $filepath = $this->tempDir . '/test-new.md';

    // Real mode: file is created.
    $this->invokeWriteIfChanged($exporter, $filepath, $markdown, FALSE);
    $this->assertFileExists($filepath);
  }

  /**
   * Writing the same content twice skips the second write.
   */
  public function testUnchangedContentIsSkipped(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Stable', 'status' => 1]);
    $node->save();

    $exporter = $this->container->get('git_content.node_exporter');
    $markdown = $exporter->export($node);
    $filepath = $this->tempDir . '/test-unchanged.md';

    // First write.
    $written1 = $this->invokeWriteIfChanged($exporter, $filepath, $markdown, FALSE);
    $this->assertTrue($written1);

    // Second write with identical content.
    $written2 = $this->invokeWriteIfChanged($exporter, $filepath, $markdown, FALSE);
    $this->assertFalse($written2);
  }

  /**
   * Dry-run returns FALSE when the checksum in the existing file matches the
   * generated content — even if the rest of the file was manually edited.
   *
   * This is the key scenario: an editor tweaks a .md file directly in Git
   * (changes a field, fixes a typo). The entity hasn't changed, so the
   * checksum in the file still matches. dry-run must return "no export needed".
   */
  public function testDryRunSkipsManuallyEditedFileWithSameEntityChecksum(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Editable', 'status' => 1]);
    $node->save();

    $exporter   = $this->container->get('git_content.node_exporter');
    $serializer = new MarkdownSerializer();
    $markdown   = $exporter->export($node);
    $filepath   = $this->tempDir . '/test-manual.md';

    // Write the original export to disk.
    file_put_contents($filepath, $markdown);

    // Simulate a manual edit: modify the title in the YAML but keep the
    // same checksum (entity hasn't changed, only the file was hand-edited).
    $parsed = $serializer->deserialize($markdown);
    $parsed['frontmatter']['title'] = 'Manually Edited Title';
    // Keep the drupal.checksum intact — it still reflects the entity state.
    $editedMarkdown = $serializer->serialize($parsed['frontmatter'], $parsed['body']);
    file_put_contents($filepath, $editedMarkdown);

    // Dry-run must report "no export needed" because entity checksum matches.
    $needsExport = $this->invokeWriteIfChanged($exporter, $filepath, $markdown, TRUE);
    $this->assertFalse($needsExport, 'Manually edited file with same entity checksum must not trigger re-export.');
  }

  /**
   * Dry-run returns TRUE when the entity has actually changed (different checksum).
   */
  public function testDryRunDetectsChangedEntity(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original Title', 'status' => 1]);
    $node->save();

    $exporter = $this->container->get('git_content.node_exporter');
    $filepath = $this->tempDir . '/test-changed.md';

    // Export original version to disk.
    file_put_contents($filepath, $exporter->export($node));

    // Now change the entity.
    $node->set('title', 'Updated Title');
    $node->save();

    $newMarkdown = $exporter->export($node);

    // Dry-run must detect the change.
    $needsExport = $this->invokeWriteIfChanged($exporter, $filepath, $newMarkdown, TRUE);
    $this->assertTrue($needsExport, 'Changed entity must be detected as needing re-export.');
  }

  // ---------------------------------------------------------------------------
  // Helper: call protected writeIfChanged via reflection.
  // ---------------------------------------------------------------------------

  private function invokeWriteIfChanged(object $exporter, string $filepath, string $content, bool $dryRun): bool {
    $method = new \ReflectionMethod($exporter, 'writeIfChanged');
    $method->setAccessible(TRUE);
    return $method->invoke($exporter, $filepath, $content, $dryRun);
  }

}
