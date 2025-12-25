<?php

declare(strict_types=1);


namespace Drupal\flagger_notify\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
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
   * Constructs a new FlaggerNotifyService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    Connection $database,
    LanguageManagerInterface $language_manager,
    Token $token,
    MailManagerInterface $mail_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->languageManager = $language_manager;
    $this->token = $token;
    $this->mailManager = $mail_manager;
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

    // Track notified users to prevent spam (e.g. user subscribed via 2 different flags).
    $processed_uids = [];

    foreach ($flags_config as $flag_id => $settings) {
      if (empty($settings['enabled'])) {
        continue;
      }

      $subject_template = !empty($settings['subject']) ? $settings['subject'] : $default_subject;
      $body_template = !empty($settings['body']) ? $settings['body'] : $default_body;

      // Find users who flagged this content with THIS specific flag.
      // Performance Note: We use a direct database select here instead of EntityQuery
      // because we only need the UIDs (fetchCol). We dynamically resolve the table name
      // to ensure compatibility with future Flag module updates.
      $flagging_storage = $this->entityTypeManager->getStorage('flagging'); 
      $table_name = $flagging_storage->getEntityType()->getBaseTable();

      $uids = $this->database->select($table_name, 'f')
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

      // Batch processing to prevent memory issues with large subscriber lists.
      // Process users in chunks of 50 to maintain low memory footprint.
      $chunks = array_chunk($uids, 50);
      
      if ($debug_mode) {
         $logger->info('Found @count users to notify for node @nid (Flag: @flag). Processing in @batches batches.', [
             '@count' => count($uids), 
             '@nid' => $nid, 
             '@flag' => $flag_id,
             '@batches' => count($chunks)
         ]);
      }

      foreach ($chunks as $chunk_uids) {
        $accounts = $this->entityTypeManager->getStorage('user')->loadMultiple($chunk_uids);

        // Pre-sort users by language to optimize config loading.
        $users_by_lang = [];
        foreach ($accounts as $account) {
          if ($account instanceof UserInterface && $account->isActive()) {
            // SPAM PREVENTION: Skip users who already received a notification for this specific update event via another flag.
            // Only if "Prevent Duplicate Emails" is enabled in config.
            if ($config->get('prevent_duplicate_emails') !== FALSE) {
               if (isset($processed_uids[$account->id()])) {
                 continue;
               }
            }
            $users_by_lang[$account->getPreferredLangcode()][] = $account;
          }
        }

        foreach ($users_by_lang as $langcode => $lang_accounts) {
          // Optimization: Load translated config ONCE per language group per batch.
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

          foreach ($lang_accounts as $account) {
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

            $token_data = ['node' => $node, 'user' => $account];
            $token_options = ['langcode' => $langcode, 'clear' => TRUE];
            
            // Re-implement token replacement (Restoring logic).
            $subject_raw = $this->token->replace($subject_pattern, $token_data, $token_options);
            $body_raw = $this->token->replace($body_pattern, $token_data, $token_options);

            // Sanitize Subject: Remove newlines to prevent header injection.
            $subject = str_replace(["\r", "\n"], '', trim((string) $subject_raw));
            $body_html = trim((string) $body_raw);

            // Safety: Data verification.
            if ($subject === '' || $body_html === '') {
              if ($debug_mode) {
                $logger->warning('Skipped user @uid. Empty subject or body after token replacement.', ['@uid' => $account->id()]);
              }
              continue;
            }

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
              // Mark user as processed.
              $processed_uids[$account->id()] = TRUE;
              
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
        // Explicitly clear memory for this batch (optional but good practice)
        unset($accounts);
      }
    }
  }

}
