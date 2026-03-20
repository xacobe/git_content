<?php

namespace Drupal\Tests\git_content\Kernel\Exporter;

use Drupal\file\Entity\File;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests FileExporter path structure and frontmatter.
 *
 * @group git_content
 */
class FileExportTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'git_content',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * The export path must mirror the directory structure under sites/default/files/.
   *
   * public://images/photo.jpg → content_export/files/images/photo.md
   *
   * This avoids filename collisions that would occur if all files were flat,
   * and matches what Drupal itself guarantees (unique filenames per directory).
   */
  public function testDirectoryStructureMirrorsFilesSubdir(): void {
    $file = File::create([
      'uri'      => 'public://images/photo.jpg',
      'filename' => 'photo.jpg',
      'filemime' => 'image/jpeg',
      'status'   => 1,
    ]);
    $file->save();

    $result = $this->container->get('git_content.file_exporter')
      ->exportToFile($file, TRUE);

    $this->assertStringContainsString('/files/images/', $result['path']);
    $this->assertStringEndsWith('photo.md', $result['path']);
  }

  /**
   * Files at the root of public:// (no subdirectory) go directly into files/.
   */
  public function testRootLevelFileHasNoExtraSubdir(): void {
    $file = File::create([
      'uri'      => 'public://logo.png',
      'filename' => 'logo.png',
      'filemime' => 'image/png',
      'status'   => 1,
    ]);
    $file->save();

    $result = $this->container->get('git_content.file_exporter')
      ->exportToFile($file, TRUE);

    // Path ends at files/logo.md, not files/./logo.md or files//logo.md.
    $this->assertMatchesRegularExpression('#/files/logo\.md$#', $result['path']);
  }

  /**
   * The .md filename must NOT include the Drupal file ID as a prefix.
   * Drupal guarantees unique filenames within a directory, so the ID is noise.
   */
  public function testFilenameHasNoFidPrefix(): void {
    $file = File::create([
      'uri'      => 'public://documents/report.pdf',
      'filename' => 'report.pdf',
      'filemime' => 'application/pdf',
      'status'   => 1,
    ]);
    $file->save();

    $result = $this->container->get('git_content.file_exporter')
      ->exportToFile($file, TRUE);

    $filename = basename($result['path']);
    $this->assertEquals('report.md', $filename);
    $this->assertFalse(str_starts_with($filename, $file->id() . '-'));
  }

  /**
   * The clean path (without stream wrapper) appears at root for SSGs.
   * The full URI (with stream wrapper) goes under drupal:.
   */
  public function testPathAndUriPlacement(): void {
    $file = File::create([
      'uri'      => 'public://images/drupal.jpg',
      'filename' => 'drupal.jpg',
      'filemime' => 'image/jpeg',
      'status'   => 1,
    ]);
    $file->save();

    $fm = (new MarkdownSerializer())
      ->deserialize($this->container->get('git_content.file_exporter')->export($file))['frontmatter'];

    // SSG-friendly path at root — no stream wrapper.
    $this->assertEquals('images/drupal.jpg', $fm['path']);
    $this->assertStringNotContainsString('public://', $fm['path']);

    // Full URI with stream wrapper lives under drupal:.
    $this->assertEquals('public://images/drupal.jpg', $fm['drupal']['uri']);
  }

  /**
   * File size is exported at root so SSGs can display download size.
   */
  public function testFileSizeAtRoot(): void {
    $file = File::create([
      'uri'      => 'public://brochure.pdf',
      'filename' => 'brochure.pdf',
      'filemime' => 'application/pdf',
      'filesize' => 204800,
      'status'   => 1,
    ]);
    $file->save();

    $fm = (new MarkdownSerializer())
      ->deserialize($this->container->get('git_content.file_exporter')->export($file))['frontmatter'];

    $this->assertEquals(204800, $fm['size']);
  }

  /**
   * Nested subdirectories are fully preserved.
   *
   * public://2026/01/photo.jpg → content_export/files/2026/01/photo.md
   */
  public function testNestedSubdirFullyPreserved(): void {
    $file = File::create([
      'uri'      => 'public://2026/01/photo.jpg',
      'filename' => 'photo.jpg',
      'filemime' => 'image/jpeg',
      'status'   => 1,
    ]);
    $file->save();

    $result = $this->container->get('git_content.file_exporter')
      ->exportToFile($file, TRUE);

    $this->assertStringContainsString('/files/2026/01/', $result['path']);
    $this->assertStringEndsWith('photo.md', $result['path']);
  }

}
