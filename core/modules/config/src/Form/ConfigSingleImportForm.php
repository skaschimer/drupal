<?php

namespace Drupal\config\Form;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Importer\ConfigImporterBatch;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Provides a form for importing a single configuration file.
 *
 * @internal
 */
class ConfigSingleImportForm extends ConfirmFormBase {

  /**
   * If the config exists, this is that object. Otherwise, FALSE.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\Entity\ConfigEntityInterface|bool
   */
  protected $configExists = FALSE;

  /**
   * The submitted data needing to be confirmed.
   *
   * @var array
   */
  protected $data = [];

  /**
   * Constructs a new ConfigSingleImportForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\StorageInterface $configStorage
   *   The config storage.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher used to notify subscribers of config import events.
   * @param \Drupal\Core\Config\ConfigManagerInterface $configManager
   *   The configuration manager.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend to ensure multiple imports do not occur at the same
   *   time.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed configuration manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $moduleInstaller
   *   The module installer.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list.
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeExtensionList
   *   The theme extension list.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StorageInterface $configStorage,
    protected RendererInterface $renderer,
    protected EventDispatcherInterface $eventDispatcher,
    protected ConfigManagerInterface $configManager,
    protected LockBackendInterface $lock,
    protected TypedConfigManagerInterface $typedConfigManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleInstallerInterface $moduleInstaller,
    protected ThemeHandlerInterface $themeHandler,
    protected ModuleExtensionList $moduleExtensionList,
    protected ThemeExtensionList $themeExtensionList,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.storage'),
      $container->get('renderer'),
      $container->get('event_dispatcher'),
      $container->get('config.manager'),
      $container->get('lock.persistent'),
      $container->get('config.typed'),
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('theme_handler'),
      $container->get('extension.list.module'),
      $container->get('extension.list.theme')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_single_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('config.import_single');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if ($this->data['config_type'] === 'system.simple') {
      $name = $this->data['config_name'];
      $type = $this->t('simple configuration');
    }
    else {
      $definition = $this->entityTypeManager->getDefinition($this->data['config_type']);
      $name = $this->data['import'][$definition->getKey('id')];
      $type = $definition->getSingularLabel();
    }

    $args = [
      '%name' => $name,
      '@type' => strtolower($type),
    ];
    if ($this->configExists) {
      $question = $this->t('Are you sure you want to update the %name @type?', $args);
    }
    else {
      $question = $this->t('Are you sure you want to create a new %name @type?', $args);
    }
    return $question;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // When this is the confirmation step fall through to the confirmation form.
    if ($this->data) {
      return parent::buildForm($form, $form_state);
    }

    $entity_types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type => $definition) {
      if ($definition->entityClassImplements(ConfigEntityInterface::class)) {
        $entity_types[$entity_type] = $definition->getLabel();
      }
    }
    // Sort the entity types by label, then add the simple config to the top.
    uasort($entity_types, 'strnatcasecmp');
    $config_types = [
      'system.simple' => $this->t('Simple configuration'),
    ] + $entity_types;
    $form['config_type'] = [
      '#title' => $this->t('Configuration type'),
      '#type' => 'select',
      '#options' => $config_types,
      '#required' => TRUE,
    ];
    $form['config_name'] = [
      '#title' => $this->t('Configuration name'),
      '#description' => $this->t('Enter the name of the configuration file without the <em>.yml</em> extension. (e.g. <em>system.site</em>)'),
      '#type' => 'textfield',
      '#states' => [
        'required' => [
          ':input[name="config_type"]' => ['value' => 'system.simple'],
        ],
        'visible' => [
          ':input[name="config_type"]' => ['value' => 'system.simple'],
        ],
      ],
    ];
    $form['import'] = [
      '#title' => $this->t('Paste your configuration here'),
      '#type' => 'textarea',
      '#rows' => 24,
      '#required' => TRUE,
    ];
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
    ];
    $form['advanced']['custom_entity_id'] = [
      '#title' => $this->t('Custom Entity ID'),
      '#type' => 'textfield',
      '#description' => $this->t('Specify a custom entity ID. This will override the entity ID in the configuration above.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // The confirmation step needs no additional validation.
    if ($this->data) {
      return;
    }

    try {
      // Decode the submitted import.
      $data = Yaml::decode($form_state->getValue('import'));
    }
    catch (InvalidDataTypeException $e) {
      $form_state->setErrorByName('import', $this->t('The import failed with the following message: %message', ['%message' => $e->getMessage()]));
    }

    // Validate for config entities.
    if ($form_state->getValue('config_type') && $form_state->getValue('config_type') !== 'system.simple') {
      $definition = $this->entityTypeManager->getDefinition($form_state->getValue('config_type'));
      $id_key = $definition->getKey('id');

      // If a custom entity ID is specified, override the value in the
      // configuration data being imported.
      if (!$form_state->isValueEmpty('custom_entity_id')) {
        $data[$id_key] = $form_state->getValue('custom_entity_id');
      }

      $entity_storage = $this->entityTypeManager->getStorage($form_state->getValue('config_type'));
      // If an entity ID was not specified, set an error.
      if (!isset($data[$id_key])) {
        $form_state->setErrorByName('import', $this->t('Missing ID key "@id_key" for this @entity_type import.', ['@id_key' => $id_key, '@entity_type' => $definition->getLabel()]));
        return;
      }

      $config_name = $definition->getConfigPrefix() . '.' . $data[$id_key];
      // If there is an existing entity, ensure matching ID and UUID.
      if ($entity = $entity_storage->load($data[$id_key])) {
        $this->configExists = $entity;
        if (!isset($data['uuid'])) {
          $form_state->setErrorByName('import', $this->t('An entity with this machine name already exists but the import did not specify a UUID.'));
          return;
        }
        if ($data['uuid'] !== $entity->uuid()) {
          $form_state->setErrorByName('import', $this->t('An entity with this machine name already exists but the UUID does not match.'));
          return;
        }
      }
      // If there is no entity with a matching ID, check for a UUID match.
      elseif (isset($data['uuid']) && $entity_storage->loadByProperties(['uuid' => $data['uuid']])) {
        $form_state->setErrorByName('import', $this->t('An entity with this UUID already exists but the machine name does not match.'));
      }
    }
    else {
      $config_name = $form_state->getValue('config_name');
      $config = $this->config($config_name);
      $this->configExists = !$config->isNew() ? $config : FALSE;
    }

    // Use ConfigImporter validation.
    if (!$form_state->getErrors()) {
      $source_storage = new StorageReplaceDataWrapper($this->configStorage);
      $source_storage->replaceData($config_name, $data);
      $storage_comparer = new StorageComparer($source_storage, $this->configStorage);

      $storage_comparer->createChangelist();
      if (!$storage_comparer->hasChanges()) {
        $form_state->setErrorByName('import', $this->t('There are no changes to import.'));
      }
      else {
        $config_importer = new ConfigImporter(
          $storage_comparer,
          $this->eventDispatcher,
          $this->configManager,
          $this->lock,
          $this->typedConfigManager,
          $this->moduleHandler,
          $this->moduleInstaller,
          $this->themeHandler,
          $this->getStringTranslation(),
          $this->moduleExtensionList,
          $this->themeExtensionList
        );

        try {
          $config_importer->validate();
          $form_state->set('config_importer', $config_importer);
        }
        catch (ConfigImporterException) {
          // There are validation errors.
          $item_list = [
            '#theme' => 'item_list',
            '#items' => $config_importer->getErrors(),
            '#title' => $this->t('The configuration cannot be imported because it failed validation for the following reasons:'),
          ];
          $form_state->setErrorByName('import', $this->renderer->render($item_list));
        }
      }
    }

    // Store the decoded version of the submitted import.
    $form_state->setValueForElement($form['import'], $data);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // If this form has not yet been confirmed, store the values and rebuild.
    if (!$this->data) {
      $form_state->setRebuild();
      $this->data = $form_state->getValues();
      return;
    }

    /** @var \Drupal\Core\Config\ConfigImporter $config_importer */
    $config_importer = $form_state->get('config_importer');
    if ($config_importer->alreadyImporting()) {
      $this->messenger()->addError($this->t('Another request may be importing configuration already.'));
    }
    else {
      try {
        $sync_steps = $config_importer->initialize();
        $batch_builder = (new BatchBuilder())
          ->setTitle($this->t('Importing configuration'))
          ->setFinishCallback([ConfigImporterBatch::class, 'finish'])
          ->setInitMessage($this->t('Starting configuration import.'))
          ->setProgressMessage($this->t('Completed @current step of @total.'))
          ->setErrorMessage($this->t('Configuration import has encountered an error.'));
        foreach ($sync_steps as $sync_step) {
          $batch_builder->addOperation([ConfigImporterBatch::class, 'process'], [$config_importer, $sync_step]);
        }
        batch_set($batch_builder->toArray());
      }
      catch (ConfigImporterException) {
        // There are validation errors.
        $this->messenger()->addError($this->t('The configuration import failed for the following reasons:'));
        foreach ($config_importer->getErrors() as $message) {
          $this->messenger()->addError($message);
        }
      }
    }
  }

}
