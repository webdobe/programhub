<?php

namespace Drupal\programhub_careers\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\programhub_careers\Service\BlsLoader;
use Drupal\programhub_careers\Service\CareersBatchBuilder;
use Drupal\programhub_careers\Service\OnetLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings + uploads + "Refresh now" trigger for the careers importer.
 *
 * One page does three things that share state:
 *   1. Edit citation/region settings (BLS year for the source label, state +
 *      MSA codes for the row filter).
 *   2. Upload the BLS master XLSX and O*NET Tasks TSV — the only two source
 *      files we need. They land at canonical paths the loaders read from.
 *   3. Trigger the importer (live or dry-run) using the current settings +
 *      whatever's currently uploaded.
 */
class RefreshForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly CareersBatchBuilder $batchBuilder,
    private readonly BlsLoader $blsLoader,
    private readonly OnetLoader $onetLoader,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('programhub_careers.batch_builder'),
      $container->get('programhub_careers.bls_loader'),
      $container->get('programhub_careers.onet_loader'),
      $container->get('file_system'),
    );
  }

  public function getFormId(): string {
    return 'programhub_careers_refresh';
  }

  protected function getEditableConfigNames(): array {
    return ['programhub_careers.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('programhub_careers.settings');

    $form['#attributes']['enctype'] = 'multipart/form-data';

    $form['help'] = [
      '#markup' => '<p>' . $this->t(
        'Refreshes <code>career_outcome</code> nodes from BLS OEWS wage data and O*NET task descriptions, keyed on SOC code. Idempotent — editorial fields are preserved on existing nodes.'
      ) . '</p>',
    ];

    // ── Source uploads ─────────────────────────────────────────────────
    $form['uploads'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Source files'),
      '#description' => $this->t(
        'Public-domain data files. Replace once a year (BLS in May, O*NET when they publish a new database). Files are stored at <code>private://programhub_careers/</code>.'
      ),
    ];

    $blsLabel = $this->t('BLS OEWS master XLSX');
    if ($this->blsLoader->hasFile()) {
      $blsLabel = $this->t('BLS OEWS master XLSX <em>(currently uploaded — re-upload to replace)</em>');
    }
    $form['uploads']['bls_file'] = [
      '#type' => 'file',
      '#title' => $blsLabel,
      '#description' => $this->t(
        'Download <code>oesm<year>all.zip</code> from <a href=":bls" target="_blank">bls.gov/oes/tables.htm</a>, unzip it, and upload the <code>all_data_M_<year>.xlsx</code> inside.',
        [':bls' => 'https://www.bls.gov/oes/tables.htm'],
      ),
    ];

    $onetLabel = $this->t('O*NET Task Statements TSV');
    if ($this->onetLoader->hasFile()) {
      $onetLabel = $this->t('O*NET Task Statements TSV <em>(currently uploaded — re-upload to replace)</em>');
    }
    $form['uploads']['onet_file'] = [
      '#type' => 'file',
      '#title' => $onetLabel,
      '#description' => $this->t(
        'Download the database TSV bundle from <a href=":onet" target="_blank">onetcenter.org/database.html</a>, extract it, and upload <code>Task Statements.txt</code>.',
        [':onet' => 'https://www.onetcenter.org/database.html'],
      ),
    ];

    // ── Settings ────────────────────────────────────────────────────────
    $form['settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Region & citation'),
    ];
    $form['settings']['bls_year'] = [
      '#type' => 'textfield',
      '#title' => $this->t('BLS data year'),
      '#default_value' => $config->get('bls_year'),
      '#description' => $this->t('Two-digit suffix matching the file you uploaded. <code>24</code> = May 2024 release. Used in the source citation written to <code>field_pay_source</code>.'),
      '#size' => 4,
      '#required' => TRUE,
    ];
    $form['settings']['bls_msa_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('BLS MSA code'),
      '#default_value' => $config->get('bls_msa_code'),
      '#description' => $this->t('Coeur d\'Alene = <code>17660</code>.'),
      '#size' => 8,
      '#required' => TRUE,
    ];
    $form['settings']['bls_state_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('BLS state FIPS code'),
      '#default_value' => $config->get('bls_state_code'),
      '#description' => $this->t('Idaho = <code>16</code>.'),
      '#size' => 4,
      '#required' => TRUE,
    ];

    // ── Actions ─────────────────────────────────────────────────────────
    $form['actions']['#type'] = 'actions';
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & upload'),
      '#submit' => ['::submitForm'],
    ];
    $form['actions']['dry_run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Dry run'),
      '#submit' => ['::submitDryRun'],
    ];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh now'),
      '#submit' => ['::submitRefresh'],
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('programhub_careers.settings')
      ->set('bls_year', $form_state->getValue('bls_year'))
      ->set('bls_msa_code', $form_state->getValue('bls_msa_code'))
      ->set('bls_state_code', $form_state->getValue('bls_state_code'))
      ->save();
    $this->handleUploads();
  }

  public function submitDryRun(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    $this->execute(TRUE);
  }

  public function submitRefresh(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    $this->execute(FALSE);
  }

  /**
   * Move uploaded files to their canonical loader paths. The loaders read
   * from a fixed location so we can always find the latest upload, regardless
   * of the original filename.
   */
  private function handleUploads(): void {
    $dir = 'private://programhub_careers';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

    $blsFile = file_save_upload(
      'bls_file',
      ['FileExtension' => ['extensions' => 'xlsx']],
      $dir,
      0,
      FileSystemInterface::EXISTS_REPLACE,
    );
    if ($blsFile) {
      $this->fileSystem->move($blsFile->getFileUri(), BlsLoader::CANONICAL_PATH, FileSystemInterface::EXISTS_REPLACE);
      $blsFile->delete();
      $this->messenger()->addStatus($this->t('BLS master XLSX uploaded.'));
    }

    $onetFile = file_save_upload(
      'onet_file',
      ['FileExtension' => ['extensions' => 'tsv txt']],
      $dir,
      0,
      FileSystemInterface::EXISTS_REPLACE,
    );
    if ($onetFile) {
      $this->fileSystem->move($onetFile->getFileUri(), OnetLoader::CANONICAL_PATH, FileSystemInterface::EXISTS_REPLACE);
      $onetFile->delete();
      $this->messenger()->addStatus($this->t('O*NET tasks TSV uploaded.'));
    }
  }

  /**
   * Hand the import off to the Drupal Batch API. The full pipeline is too
   * heavy for a single web request — the BLS master XLSX alone takes longer
   * than the proxy timeout to parse — so the batch builder breaks it into
   * resumable phases (parse BLS, parse O*NET, collect SOCs, upsert).
   */
  private function execute(bool $dryRun): void {
    if (!$this->blsLoader->hasFile() || !$this->onetLoader->hasFile()) {
      $this->messenger()->addError($this->t(
        'Upload both source files before running the import.'
      ));
      return;
    }
    try {
      $batch = $this->batchBuilder->build($dryRun);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Refresh failed to start: @msg', ['@msg' => $e->getMessage()]));
      return;
    }
    batch_set($batch);
  }

}
