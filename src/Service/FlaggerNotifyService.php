<?php

namespace Drupal\flagger_notify\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Service to handle flagger notifications.
 */
class FlaggerNotifyService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new FlaggerNotifyService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    Connection $database,
    LanguageManagerInterface $language_manager,
    Token $token,
    MailManagerInterface $mail_manager,
    RendererInterface $renderer
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->languageManager = $language_manager;
    $this->token = $token;
    $this->mailManager = $mail_manager;
    $this->renderer = $renderer;
  }

  /**
   * Sends notifications for a specific node update.
   *
   * @param int $nid
   *   The node ID.
   */
  public function sendNodeNotifications(int $nid): void {
    $logger = $this->loggerFactory->get('flagger_notify');
    $config = $this->configFactory->get('flagger_notify.settings');
    $flags_config = $config->get('flags_config') ?: [];
    $debug_mode = (bool) $config->get('debug_mode');
    
    // Load Global Defaults
    $default_subject = $config->get('default_subject');
    $default_body = $config->get('default_body');

    if (empty($default_subject) || empty($default_body)) {
       if ($debug_mode) {
           $logger->warning('Module missing global defaults. Notification skipped or using fallback.');
       }
       $default_subject = '[site:name] Notification';
       $default_body = 'Content updated.';
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$node->isPublished()) {
      if ($debug_mode) {
        $logger->info('Skipped node @nid. Entity not found or unpublished.', ['@nid' => $nid]);
      }
      return;
    }

    foreach ($flags_config as $flag_id => $settings) {
      if (empty($settings['enabled'])) {
        continue;
      }

      $subject_template = !empty($settings['subject']) ? $settings['subject'] : $default_subject;
      $body_template = !empty($settings['body']) ? $settings['body'] : $default_body;

      // Find users who flagged this content with THIS specific flag.
      $uids = $this->database->select('flagging', 'f')
        ->fields('f', ['uid'])
        ->condition('f.entity_type', 'node')
        ->condition('f.entity_id', $nid)
        ->condition('f.flag_id', $flag_id)
        ->condition('f.uid', 0, '>')
        ->distinct()
        ->execute()
        ->fetchCol();

      if (empty($uids)) {
        if ($debug_mode) {
          $logger->info('No users found for node @nid with flag @flag.', ['@nid' => $nid, '@flag' => $flag_id]);
        }
        continue;
      }

      $accounts = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
      
      if ($debug_mode) {
        $logger->info('Found @count users to notify for node @nid (Flag: @flag).', ['@count' => count($accounts), '@nid' => $nid, '@flag' => $flag_id]);
      }

      foreach ($accounts as $account) {
        if (!$account instanceof UserInterface || !$account->isActive()) {
          continue;
        }

        // Feature: Access Control Check.
        // Ensure the user still has permission to view this node before notifying them.
        if (!$node->access('view', $account)) {
          if ($debug_mode) {
             $logger->info('Skipped user @uid for node @nid. Access denied.', ['@uid' => $account->id(), '@nid' => $nid]);
          }
          continue;
        }

        $to = $account->getEmail();
        if (empty($to)) {
          continue;
        }

        $langcode = $account->getPreferredLangcode();
        
        // Load Translated Config
        $cfg_override = $this->languageManager->getLanguageConfigOverride($langcode, 'flagger_notify.settings');
        
        $trans_default_subject = $cfg_override->get('default_subject') ?? $default_subject;
        $trans_default_body = $cfg_override->get('default_body') ?? $default_body;

        $flags_override = $cfg_override->get('flags_config') ?? [];
        $flag_override_settings = $flags_override[$flag_id] ?? [];
        
        $has_override_subject = !empty($settings['subject']);
        $has_override_body = !empty($settings['body']);
        
        if ($has_override_subject) {
            $subject_pattern = $flag_override_settings['subject'] ?? $settings['subject'];
        } else {
            $subject_pattern = $trans_default_subject;
        }

        if ($has_override_body) {
            $body_pattern = $flag_override_settings['body'] ?? $settings['body'];
        } else {
            $body_pattern = $trans_default_body;
        }

        $token_data = ['node' => $node, 'user' => $account];
        $token_options = ['langcode' => $langcode, 'clear' => TRUE];

        $subject = $this->token->replace($subject_pattern, $token_data, $token_options);
        $body_html = $this->token->replace($body_pattern, $token_data, $token_options);

        $params = [
          'subject' => $subject,
          'body' => [
            '#type' => 'inline_template',
            '#template' => '{{ body|raw }}',
            '#context' => [
              'body' => $body_html,
            ],
          ],
        ];

        // Send the email.
        $result = $this->mailManager->mail('flagger_notify', 'content_update', $to, $langcode, $params, NULL, TRUE);
        
        if (!empty($result['result'])) {
          if ($debug_mode) {
            $logger->info('Sent notification to @mail (UID: @uid) for node @nid (Flag: @flag, Lang: @lang). Subject: "@subject" Body: "@body"', [
              '@mail' => $to,
              '@uid' => $account->id(),
              '@nid' => $nid,
              '@flag' => $flag_id,
              '@lang' => $langcode,
              '@subject' => $subject,
              '@body' => $body_html,
            ]);
          }
        } elseif ($debug_mode) {
           $logger->error('Failed to send email to @mail (UID: @uid) for node @nid.', ['@mail' => $to, '@uid' => $account->id(), '@nid' => $nid]);
        }
      }
    }
  }

}
