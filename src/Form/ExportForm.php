<?php

namespace Drupal\git_content\Form;

use Drupal\git_content\Exporter\MarkdownExporter;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Two-step form for exporting Drupal content to content_export/.
 *
 * Step 1 (preview): shows entity counts per type and files currently on disk.
 * Step 2 (results): shows what was exported, unchanged, deleted, and any errors.
 */
class ExportForm extends FormBase {

  public function __construct(
    protected MarkdownExporter $exporter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('git_content.exporter'));
  }

  public function getFormId(): string {
    return 'git_content_export_form';
  }

  // ---------------------------------------------------------------------------
  // Form builder
  // ---------------------------------------------------------------------------

  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($form_state->get('step') === 'results') {
      return $this->buildResultsStep($form, $form_state);
    }

    return $this->buildPreviewStep($form, $form_state);
  }

  private function buildPreviewStep(array $form, FormStateInterface $form_state): array {
    $preview = $this->exporter->previewAll();

    $labels = [
      'node'              => $this->t('Nodes'),
      'taxonomy_term'     => $this->t('Taxonomy terms'),
      'media'             => $this->t('Media'),
      'block_content'     => $this->t('Block content'),
      'file'              => $this->t('Files'),
      'user'              => $this->t('Users'),
      'menu_link_content' => $this->t('Menu links'),
    ];

    $rows = [];
    foreach ($preview as $entity_type => $counts) {
      if ($counts['entities'] === 0 && $counts['files'] === 0) {
        continue;
      }
      $rows[] = [
        $labels[$entity_type] ?? $entity_type,
        $counts['entities'],
        $counts['files'],
      ];
    }

    $form['description'] = [
      '#markup' => '<p>' . $this->t(
        'Review the content below, then click <strong>Export</strong> to write all entities to <code>content_export/</code>. Stale files will be removed.'
      ) . '</p>',
    ];

    $form['preview'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Type'),
        $this->t('Entities in Drupal'),
        $this->t('Files on disk'),
      ],
      '#rows'  => $rows,
      '#empty' => $this->t('No content found.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Export'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  private function buildResultsStep(array $form, FormStateInterface $form_state): array {
    $form['results'] = [
      '#markup' => Markup::create($this->renderResult($form_state->get('result'))),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['again'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Export again'),
    ];

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Submit handler
  // ---------------------------------------------------------------------------

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('step') === 'results') {
      // "Export again" resets to the preview step.
      $form_state->set('step', NULL);
      $form_state->set('result', NULL);
      $form_state->setRebuild(TRUE);
      return;
    }

    $result = $this->exporter->exportAll();
    $form_state->set('step', 'results');
    $form_state->set('result', $result);
    $form_state->setRebuild(TRUE);
  }

  // ---------------------------------------------------------------------------
  // Rendering helpers
  // ---------------------------------------------------------------------------

  private function renderResult(array $result): string {
    $output  = '';
    $grouped = ['exported' => [], 'skipped' => [], 'deleted' => []];

    foreach (['exported', 'skipped', 'deleted'] as $op) {
      foreach ($result[$op] as $file) {
        $grouped[$op][$this->detectTypeFromPath($file)][] = $file;
      }
    }

    $opInfo = [
      'exported' => ['label' => $this->t('Exported'),  'icon' => '✔'],
      'skipped'  => ['label' => $this->t('Unchanged'), 'icon' => '→'],
      'deleted'  => ['label' => $this->t('Deleted'),   'icon' => '✖'],
    ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total   = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $this->t('@label (@count):', ['@label' => $info['label'], '@count' => (string) $total]) . '</strong><br>';

      foreach ($grouped[$op] as $type => $files) {
        $output .= '<details><summary>' . Html::escape($this->labelForType($type)) . ' (' . count($files) . '):</summary>';
        foreach ($files as $file) {
          $output .= Html::escape($info['icon']) . ' ' . Html::escape($file) . '<br>';
        }
        $output .= '</details>';
      }
      $output .= '<br>';
    }

    if (!empty($result['errors'])) {
      $output .= '<strong>' . $this->t('Errors (@count):', ['@count' => (string) count($result['errors'])]) . '</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . Html::escape($error) . '<br>';
      }
    }

    return $output ?: (string) $this->t('Nothing to export.');
  }

  private function detectTypeFromPath(string $relativePath): string {
    return match (explode('/', $relativePath)[0] ?? '') {
      'content_types' => 'nodes',
      'taxonomy'      => 'taxonomy',
      'media'         => 'media',
      'blocks'        => 'blocks',
      'files'         => 'files',
      'users'         => 'users',
      'menus'         => 'menus',
      default         => 'other',
    };
  }

  private function labelForType(string $type): string {
    return (string) match ($type) {
      'nodes'    => $this->t('Nodes'),
      'taxonomy' => $this->t('Taxonomy'),
      'media'    => $this->t('Media'),
      'blocks'   => $this->t('Block content'),
      'files'    => $this->t('Files'),
      'users'    => $this->t('Users'),
      'menus'    => $this->t('Menu links'),
      default    => $this->t('Other'),
    };
  }

}
