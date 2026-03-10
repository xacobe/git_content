<?php

namespace Drupal\git_content\Form;

use Drupal\git_content\Importer\MarkdownImporter;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Two-step form for importing content from content_export/ into Drupal.
 *
 * Step 1 (preview): performs a full dry run and shows what would be created,
 *   updated, skipped, and deleted — without touching any data.
 * Step 2 (results): shows what was actually created, updated, skipped, deleted.
 */
class ImportForm extends FormBase {

  public function __construct(
    protected MarkdownImporter $importer,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('git_content.importer'));
  }

  public function getFormId(): string {
    return 'git_content_import_form';
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
    $preview = $this->importer->previewAll();

    $hasChanges = !empty($preview['imported'])
      || !empty($preview['updated'])
      || !empty($preview['deleted'])
      || !empty($preview['errors']);

    $form['description'] = [
      '#markup' => '<p>' . $this->t(
        'This is a <strong>preview</strong> of the changes that will be applied when you import. No data has been modified yet.'
      ) . '</p>',
    ];

    $form['preview'] = [
      '#markup' => Markup::create($this->renderSummary($preview, TRUE)),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Run import'),
      '#button_type' => 'primary',
      '#disabled'    => !$hasChanges && empty($preview['errors']),
    ];

    return $form;
  }

  private function buildResultsStep(array $form, FormStateInterface $form_state): array {
    $form['results'] = [
      '#markup' => Markup::create($this->renderSummary($form_state->get('result'), FALSE)),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['again'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Import again'),
    ];

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Submit handler
  // ---------------------------------------------------------------------------

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('step') === 'results') {
      // "Import again" resets to the preview step.
      $form_state->set('step', NULL);
      $form_state->set('result', NULL);
      $form_state->setRebuild(TRUE);
      return;
    }

    $result = $this->importer->importAll();
    $form_state->set('step', 'results');
    $form_state->set('result', $result);
    $form_state->setRebuild(TRUE);
  }

  // ---------------------------------------------------------------------------
  // Rendering helpers
  // ---------------------------------------------------------------------------

  /**
   * Render an import result/preview array as HTML.
   *
   * @param array $result
   *   Array with keys: imported, updated, skipped, deleted, errors.
   * @param bool $isDryRun
   *   TRUE when rendering a preview (future tense), FALSE for actual results.
   */
  private function renderSummary(array $result, bool $isDryRun): string {
    $output  = '';
    $grouped = ['imported' => [], 'updated' => [], 'skipped' => [], 'deleted' => []];

    foreach (['imported', 'updated', 'skipped', 'deleted'] as $op) {
      foreach ($result[$op] as $item) {
        $grouped[$op][$this->detectTypeFromPath($item)][] = $item;
      }
    }

    $opInfo = $isDryRun
      ? [
        'imported' => ['label' => $this->t('Would create'),  'icon' => '✔'],
        'updated'  => ['label' => $this->t('Would update'),  'icon' => '↻'],
        'skipped'  => ['label' => $this->t('Unchanged'),     'icon' => '→'],
        'deleted'  => ['label' => $this->t('Would delete'),  'icon' => '✖'],
      ]
      : [
        'imported' => ['label' => $this->t('Created'),   'icon' => '✔'],
        'updated'  => ['label' => $this->t('Updated'),   'icon' => '↻'],
        'skipped'  => ['label' => $this->t('Unchanged'), 'icon' => '→'],
        'deleted'  => ['label' => $this->t('Deleted'),   'icon' => '✖'],
      ];

    foreach ($opInfo as $op => $info) {
      if (empty($grouped[$op])) {
        continue;
      }

      $total   = array_sum(array_map('count', $grouped[$op]));
      $output .= '<strong>' . $this->t('@label (@count):', ['@label' => $info['label'], '@count' => (string) $total]) . '</strong><br>';

      foreach ($grouped[$op] as $type => $items) {
        $output .= '<details><summary>' . Html::escape($this->labelForType($type)) . ' (' . count($items) . '):</summary>';
        foreach ($items as $item) {
          $output .= Html::escape($info['icon']) . ' ' . Html::escape($item) . '<br>';
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

    if (empty($output)) {
      $output = (string) $this->t('No files found to import in content_export/.');
    }

    return $output;
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
