<?php

namespace Drupal\git_content\Serializer;

use Symfony\Component\Yaml\Yaml;

/**
 * Serialize and deserialize Markdown files with YAML frontmatter.
 *
 * This class is the single source of truth for the on-disk file format:
 * how human-readable YAML is built and how it is parsed back.
 * Exporters and importers delegate formatting logic to this class.
 */
class MarkdownSerializer {

  /**
   * Build a full Markdown file from frontmatter and body.
   *
   * @param array $frontmatter
   *   Structured data. Keys that are only underscores ('_', '__', …) are
   *   rendered as blank lines for readability.
   * @param string $body
   *   Body content in Markdown (may be empty).
   *
   * @return string
   *   The full .md file contents.
   */
  public function serialize(array $frontmatter, string $body = ''): string {
    $yaml = $this->buildCleanYaml($frontmatter);
    return "---\n" . $yaml . "---\n\n" . $body;
  }

  /**
   * Parse a Markdown file with YAML frontmatter.
   *
   * @param string $raw
   *   Raw contents of the .md file.
   *
   * @return array{frontmatter: array, body: string}
   *   Array with keys 'frontmatter' (array) and 'body' (string).
   *
   * @throws \InvalidArgumentException
   */
  public function deserialize(string $raw): array {
    if (!str_starts_with(ltrim($raw), '---')) {
      throw new \InvalidArgumentException('File does not contain valid YAML frontmatter (missing --- delimiter).');
    }

    $pattern = '/^---\s*\n(.*?)\n---\s*\n?(.*)/s';
    if (!preg_match($pattern, ltrim($raw), $matches)) {
      throw new \InvalidArgumentException('Failed to parse YAML frontmatter.');
    }

    $yaml_raw = $matches[1];
    $body = trim($matches[2]);

    // Remove placeholder blank-line keys that the serializer inserts.
    $yaml_clean = preg_replace('/^_+:\s*(null|~)?\s*$/m', '', $yaml_raw);
    $yaml_clean = preg_replace('/^\s*:\s*(null|~)?\s*$/m', '', $yaml_clean);

    $frontmatter = Yaml::parse($yaml_clean) ?? [];

    return [
      'frontmatter' => $frontmatter,
      'body'        => $body,
    ];
  }

  /**
   * Flatten taxonomy, media and references groups to the root level.
   * Useful for the importer, which operates on a per-field basis.
   *
   * @param array $frontmatter
   *   Frontmatter with possible nested groups.
   *
   * @return array
   *   Frontmatter with groups flattened.
   */
  public function flattenGroups(array $frontmatter): array {
    foreach (['taxonomy', 'media', 'references'] as $group) {
      if (isset($frontmatter[$group]) && is_array($frontmatter[$group])) {
        foreach ($frontmatter[$group] as $key => $value) {
          if (!isset($frontmatter[$key])) {
            $frontmatter[$key] = $value;
          }
        }
        unset($frontmatter[$group]);
      }
    }
    return $frontmatter;
  }

  /**
   * Convert basic HTML to Markdown.
   * Uses league/html-to-markdown if available.
   *
   * @param string $html
   *   HTML to convert.
   *
   * @return string
   *   Resulting Markdown.
   */
  public function htmlToMarkdown(string $html): string {
    if (class_exists('\League\HTMLToMarkdown\HtmlConverter')) {
      $converter = new \League\HTMLToMarkdown\HtmlConverter([
        'strip_tags'   => FALSE,
        'bold_style'   => '**',
        'italic_style' => '_',
      ]);
      return $converter->convert($html);
    }

    // Fallback with a basic regex approach
    $md = $html;
    for ($i = 6; $i >= 1; $i--) {
      $md = preg_replace('/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is', str_repeat('#', $i) . ' $1', $md);
    }
    $md = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $md);
    $md = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '**$1**', $md);
    $md = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '_$1_', $md);
    $md = preg_replace('/<i[^>]*>(.*?)<\/i>/is', '_$1_', $md);
    $md = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $md);
    $md = preg_replace('/<li[^>]*>(.*?)<\/li>/is', '- $1', $md);
    $md = preg_replace('/<\/?[ou]l[^>]*>/is', '', $md);
    $md = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $md);
    $md = preg_replace('/<br\s*\/?>/i', "\n", $md);
    $md = strip_tags($md);
    $md = html_entity_decode($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim($md) . "\n";
  }

  /**
   * Convert Markdown to HTML.
   * Uses league/commonmark if available.
   *
   * @param string $markdown
   *   Markdown to convert.
   *
   * @return string
   *   Resulting HTML.
   */
  public function markdownToHtml(string $markdown): string {
    if (class_exists('\League\CommonMark\CommonMarkConverter')) {
      $converter = new \League\CommonMark\CommonMarkConverter();
      return (string) $converter->convert($markdown);
    }

    // Fallback básico
    $html = htmlspecialchars($markdown, ENT_NOQUOTES, 'UTF-8');
    for ($i = 6; $i >= 1; $i--) {
      $html = preg_replace('/^' . str_repeat('#', $i) . ' (.+)$/m', '<h' . $i . '>$1</h' . $i . '>', $html);
    }
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/_(.+?)_/s', '<em>$1</em>', $html);
    $html = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $html);
    $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
    $paragraphs = preg_split('/\n{2,}/', trim($html));
    $html = implode("\n", array_map(fn($p) => '<p>' . trim($p) . '</p>', $paragraphs));

    return $html;
  }

  // ---------------------------------------------------------------------------
  // Privado
  // ---------------------------------------------------------------------------

  /**
   * Build clean YAML with support for blank lines via placeholder keys.
   */
  private function buildCleanYaml(array $frontmatter): string {
    $lines = [];

    foreach ($frontmatter as $key => $value) {
      // Claves ficticias → línea en blanco
      if ($key === '' || preg_match('/^_+$/', (string) $key)) {
        $lines[] = '';
        continue;
      }

      if ($value === NULL) {
        $lines[] = $key . ': null';
        continue;
      }

      if (is_bool($value)) {
        $lines[] = $key . ': ' . ($value ? 'true' : 'false');
        continue;
      }

      if (is_array($value)) {
        $lines[] = rtrim(Yaml::dump([$key => $value], 4, 2, Yaml::DUMP_NULL_AS_TILDE));
        continue;
      }

      $lines[] = rtrim(Yaml::dump([$key => $value], 1, 2));
    }

    return implode("\n", $lines) . "\n";
  }

}
