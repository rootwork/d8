<?php

/**
 * @file
 * Contains \Drupal\Core\Block\BlockBase.
 */

namespace Drupal\Core\Block;

use Drupal\block\BlockInterface;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContextAwarePluginBase;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Transliteration\TransliterationInterface;

/**
 * Defines a base block implementation that most blocks plugins will extend.
 *
 * This abstract class provides the generic block configuration form, default
 * block settings, and handling for general user-defined block visibility
 * settings.
 *
 * @ingroup block_api
 */
abstract class BlockBase extends ContextAwarePluginBase implements BlockPluginInterface {

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * {@inheritdoc}
   */
  public function label() {
    if (!empty($this->configuration['label'])) {
      return $this->configuration['label'];
    }

    $definition = $this->getPluginDefinition();
    // Cast the admin label to a string since it is an object.
    // @see \Drupal\Core\StringTranslation\TranslationWrapper
    return (string) $definition['admin_label'];
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = NestedArray::mergeDeep(
      $this->baseConfigurationDefaults(),
      $this->defaultConfiguration(),
      $configuration
    );
  }

  /**
   * Returns generic default configuration for block plugins.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  protected function baseConfigurationDefaults() {
    return array(
      'id' => $this->getPluginId(),
      'label' => '',
      'provider' => $this->pluginDefinition['provider'],
      'label_display' => BlockInterface::BLOCK_LABEL_VISIBLE,
      'cache' => array(
        // Blocks are cacheable by default.
        'max_age' => Cache::PERMANENT,
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array();
  }

  /**
   * {@inheritdoc}
   */
  public function setConfigurationValue($key, $value) {
    $this->configuration[$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return array();
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    // @todo Remove self::blockAccess() and force individual plugins to return
    //   their own AccessResult logic. Until that is done in
    //   https://www.drupal.org/node/2375689 the access will be set uncacheable.
    if ($this->blockAccess($account)) {
      $access = AccessResult::allowed();
    }
    else {
      $access = AccessResult::forbidden();
    }

    $access->setCacheMaxAge(0);
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Indicates whether the block should be shown.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user session for which to check access.
   *
   * @return bool
   *   TRUE if the block should be shown, or FALSE otherwise.
   *
   * @see self::access()
   */
  protected function blockAccess(AccountInterface $account) {
    // By default, the block is visible.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Creates a generic configuration form for all block types. Individual
   * block plugins can add elements to this form by overriding
   * BlockBase::blockForm(). Most block plugins should not override this
   * method unless they need to alter the generic form elements.
   *
   * @see \Drupal\Core\Block\BlockBase::blockForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $definition = $this->getPluginDefinition();
    $form['provider'] = array(
      '#type' => 'value',
      '#value' => $definition['provider'],
    );

    $form['admin_label'] = array(
      '#type' => 'item',
      '#title' => $this->t('Block description'),
      '#markup' => SafeMarkup::checkPlain($definition['admin_label']),
    );
    $form['label'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 255,
      '#default_value' => $this->label(),
      '#required' => TRUE,
    );
    $form['label_display'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Display title'),
      '#default_value' => ($this->configuration['label_display'] === BlockInterface::BLOCK_LABEL_VISIBLE),
      '#return_value' => BlockInterface::BLOCK_LABEL_VISIBLE,
    );
    // Identical options to the ones for page caching.
    // @see \Drupal\system\Form\PerformanceForm::buildForm()
    $period = array(0, 60, 180, 300, 600, 900, 1800, 2700, 3600, 10800, 21600, 32400, 43200, 86400);
    $period = array_map(array(\Drupal::service('date.formatter'), 'formatInterval'), array_combine($period, $period));
    $period[0] = '<' . $this->t('no caching') . '>';
    $period[\Drupal\Core\Cache\Cache::PERMANENT] = $this->t('Forever');
    $form['cache'] = array(
      '#type' => 'details',
      '#title' => $this->t('Cache settings'),
    );
    $form['cache']['max_age'] = array(
      '#type' => 'select',
      '#title' => $this->t('Maximum age'),
      '#description' => $this->t('The maximum time this block may be cached.'),
      '#default_value' => $this->configuration['cache']['max_age'],
      '#options' => $period,
    );

    // Add plugin-specific settings for this block type.
    $form += $this->blockForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    return array();
  }

  /**
   * {@inheritdoc}
   *
   * Most block plugins should not override this method. To add validation
   * for a specific block type, override BlockBase::blockValidate().
   *
   * @see \Drupal\Core\Block\BlockBase::blockValidate()
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Remove the admin_label form item element value so it will not persist.
    $form_state->unsetValue('admin_label');

    $this->blockValidate($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   *
   * Most block plugins should not override this method. To add submission
   * handling for a specific block type, override BlockBase::blockSubmit().
   *
   * @see \Drupal\Core\Block\BlockBase::blockSubmit()
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Process the block's submission handling if no errors occurred only.
    if (!$form_state->getErrors()) {
      $this->configuration['label'] = $form_state->getValue('label');
      $this->configuration['label_display'] = $form_state->getValue('label_display');
      $this->configuration['provider'] = $form_state->getValue('provider');
      $this->configuration['cache'] = $form_state->getValue('cache');
      $this->blockSubmit($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function getMachineNameSuggestion() {
    $definition = $this->getPluginDefinition();
    $admin_label = $definition['admin_label'];

    // @todo This is basically the same as what is done in
    //   \Drupal\system\MachineNameController::transliterate(), so it might make
    //   sense to provide a common service for the two.
    $transliterated = $this->transliteration()->transliterate($admin_label, LanguageInterface::LANGCODE_DEFAULT, '_');

    $replace_pattern = '[^a-z0-9_.]+';

    $transliterated = Unicode::strtolower($transliterated);

    if (isset($replace_pattern)) {
      $transliterated = preg_replace('@' . $replace_pattern . '@', '', $transliterated);
    }

    return $transliterated;
  }

  /**
   * Wraps the transliteration service.
   *
   * @return \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected function transliteration() {
    if (!$this->transliteration) {
      $this->transliteration = \Drupal::transliteration();
    }
    return $this->transliteration;
  }

  /**
   * Sets the transliteration service.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service.
   */
  public function setTransliteration(TransliterationInterface $transliteration) {
    $this->transliteration = $transliteration;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return (int)$this->configuration['cache']['max_age'];
  }

}
