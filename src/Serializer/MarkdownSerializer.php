<?php

namespace Drupal\git_content\Serializer;

use Symfony\Component\Yaml\Yaml;

/**
 * Serializa y deserializa el formato Markdown + frontmatter YAML.
 *
 * Esta clase es la única responsable del formato de archivo en disco:
 * cómo se construye el YAML legible y cómo se parsea de vuelta.
 * Los exportadores e importadores delegan aquí toda la lógica de formato.
 */
class MarkdownSerializer {

  /**
   * Construye un archivo Markdown completo a partir de frontmatter y cuerpo.
   *
   * @param array $frontmatter
   *   Datos estructurados. Las claves que son solo guiones bajos ('_', '__'…)
   *   se renderizan como líneas en blanco para mejorar la legibilidad.
   * @param string $body
   *   Contenido del cuerpo en Markdown (puede estar vacío).
   *
   * @return string
   *   Contenido completo del archivo .md.
   */
  public function serialize(array $frontmatter, string $body = ''): string {
    $yaml = $this->buildCleanYaml($frontmatter);
    return "---\n" . $yaml . "---\n\n" . $body;
  }

  /**
   * Parsea un archivo Markdown con frontmatter YAML.
   *
   * @param string $raw
   *   Contenido crudo del archivo .md.
   *
   * @return array{frontmatter: array, body: string}
   *   Array con claves 'frontmatter' (array) y 'body' (string).
   *
   * @throws \InvalidArgumentException
   */
  public function deserialize(string $raw): array {
    if (!str_starts_with(ltrim($raw), '---')) {
      throw new \InvalidArgumentException('El archivo no tiene frontmatter YAML válido (falta el delimitador ---).');
    }

    $pattern = '/^---\s*\n(.*?)\n---\s*\n?(.*)/s';
    if (!preg_match($pattern, ltrim($raw), $matches)) {
      throw new \InvalidArgumentException('No se pudo parsear el frontmatter YAML.');
    }

    $yaml_raw = $matches[1];
    $body = trim($matches[2]);

    // Limpiar claves ficticias de línea en blanco que el serializador inserta
    $yaml_clean = preg_replace('/^_+:\s*(null|~)?\s*$/m', '', $yaml_raw);
    $yaml_clean = preg_replace('/^\s*:\s*(null|~)?\s*$/m', '', $yaml_clean);

    $frontmatter = Yaml::parse($yaml_clean) ?? [];

    return [
      'frontmatter' => $frontmatter,
      'body'        => $body,
    ];
  }

  /**
   * Aplana los grupos taxonomy, media y references al nivel raíz.
   * Útil para el importador, que trabaja campo a campo.
   *
   * @param array $frontmatter
   *   Frontmatter con posibles grupos anidados.
   *
   * @return array
   *   Frontmatter con los grupos aplanados.
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
   * Convierte HTML básico a Markdown.
   * Usa league/html-to-markdown si está disponible.
   *
   * @param string $html
   *   HTML a convertir.
   *
   * @return string
   *   Markdown resultante.
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

    // Fallback con regex básico
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
   * Convierte Markdown a HTML.
   * Usa league/commonmark si está disponible.
   *
   * @param string $markdown
   *   Markdown a convertir.
   *
   * @return string
   *   HTML resultante.
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
   * Construye YAML limpio con soporte de líneas en blanco mediante claves ficticias.
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
