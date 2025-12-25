<?php

declare(strict_types=1);


namespace Drupal\flagger_notify\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\flag\FlagInterface;

/**
 * Configure Flagger Notify settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'flagger_notify_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['flagger_notify.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('flagger_notify.settings');
    
    // General Settings
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug Logging'),
      '#description' => $this->t('If enabled, detailed logs about queuing and email sending will be recorded in the Watchdog/DbLog.'),
      '#default_value' => $config->get('debug_mode') ?: FALSE,
    ];

    $form['general']['retry_on_failure'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Robust Retry (System Failure)'),
      '#description' => $this->t('If enabled, system failures (e.g., database connection lost) will cause the notification to remain in the queue and retry later. Warning: Does not retry individual email send failures to prevent duplicates.'),
      '#default_value' => $config->get('retry_on_failure') ?: FALSE,
    ];

    $form['general']['prevent_duplicate_emails'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prevent Duplicate Emails (Spam Protection)'),
      '#description' => $this->t('If a user has flagged content with multiple flags, should they receive only *one* email per update? Uncheck to allow multiple emails (one per flag).'),
      '#default_value' => $config->get('prevent_duplicate_emails') !== NULL ? $config->get('prevent_duplicate_emails') : TRUE,
    ];

    // Global Defaults Section
    $form['defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Global Default Templates'),
      '#open' => TRUE,
    ];

    $default_subject_val = '[site:name] Update: "[node:title]"';
    $default_body_val = "<p>Hello <strong>[user:display-name]</strong>,</p><p>We wanted to let you know that the content you bookmarked, <a href=\"[node:url:absolute]\">[node:title]</a>, has recently been updated.</p><p><a href=\"[node:url:absolute]\" style=\"display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;\">View Update</a></p><p>Best regards,<br>[site:name] Team</p>";

    $form['defaults']['default_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Email Subject'),
      '#default_value' => $config->get('default_subject') ?: $default_subject_val,
      '#required' => TRUE,
    ];

    $form['defaults']['default_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Email Body'),
      '#default_value' => $config->get('default_body') ?: $default_body_val,
      '#rows' => 10,
      '#required' => TRUE,
    ];

    $form['defaults']['token_help'] = [
      '#title' => $this->t('Available tokens'),
      '#type' => 'details',
      '#theme' => 'token_tree_link',
      '#token_types' => ['node', 'user', 'site'],
    ];

    // Per Flag Configuration
    $flags_config = $config->get('flags_config') ?: [];
    $flags = $this->entityTypeManager->getStorage('flag')->loadMultiple();

    $form['flags_config'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $has_node_flags = FALSE;
    foreach ($flags as $flag) {
      if ($flag instanceof FlagInterface && $flag->getFlaggableEntityTypeId() === 'node') {
        $has_node_flags = TRUE;
        break;
      }
    }

    if (!$has_node_flags) {
      $form['flags_config']['no_flags_message'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('No flags for content (nodes) were found. Please <a href="@url">create a Flag</a> for nodes to enable notifications.', ['@url' => \Drupal\Core\Url::fromRoute('entity.flag.collection')->toString()]) . '</div>',
      ];
    }

    foreach ($flags as $flag_id => $flag) {
      if ($flag instanceof FlagInterface && $flag->getFlaggableEntityTypeId() === 'node') {
        $flag_settings = $flags_config[$flag_id] ?? [];
        $is_enabled = !empty($flag_settings['enabled']);
        
        $form['flags_config'][$flag_id] = [
          '#type' => 'details',
          '#title' => $this->t('Flag Override: @label (@id)', ['@label' => $flag->label(), '@id' => $flag_id]),
          '#open' => $is_enabled,
        ];

        $form['flags_config'][$flag_id]['enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable notifications for this flag'),
          '#default_value' => $is_enabled,
        ];

        $form['flags_config'][$flag_id]['subject'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Override Subject'),
          '#description' => $this->t('Leave empty to use Global Default.'),
          '#default_value' => $flag_settings['subject'] ?? '',
          '#states' => [
            'visible' => [
              ':input[name="flags_config[' . $flag_id . '][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];

        $form['flags_config'][$flag_id]['body'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Override Body'),
          '#description' => $this->t('Leave empty to use Global Default.'),
          '#default_value' => $flag_settings['body'] ?? '',
          '#rows' => 10,
          '#states' => [
            'visible' => [
              ':input[name="flags_config[' . $flag_id . '][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
    }
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    
    $flags_config_raw = $form_state->getValue('flags_config');
    $clean_flags_config = [];

    // Only save configuration for enabled flags.
    // This cleans up the config object and ensures the Translation UI 
    // only shows fields for flags that are actually in use.
    if (is_array($flags_config_raw)) {
      foreach ($flags_config_raw as $flag_id => $settings) {
        if (!empty($settings['enabled'])) {
          $clean_flags_config[$flag_id] = $settings;
        }
      }
    }

    $this->config('flagger_notify.settings')
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->set('retry_on_failure', $form_state->getValue('retry_on_failure'))
      ->set('prevent_duplicate_emails', $form_state->getValue('prevent_duplicate_emails'))
      ->set('default_subject', $form_state->getValue('default_subject'))
      ->set('default_body', $form_state->getValue('default_body'))
      ->set('flags_config', $clean_flags_config)
      ->save();
  }
}
