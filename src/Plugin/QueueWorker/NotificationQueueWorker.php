<?php

declare(strict_types=1);


namespace Drupal\flagger_notify\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\flagger_notify\Service\FlaggerNotifyService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Processes entity updates to send notifications.
 *
 * @QueueWorker(
 *   id = "flagger_notify_worker",
 *   title = @Translation("Flagger Notify Worker"),
 *   cron = {"time" = 60}
 * )
 */
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Processes entity updates to send notifications.
 *
 * @QueueWorker(
 *   id = "flagger_notify_worker",
 *   title = @Translation("Flagger Notify Worker"),
 *   cron = {"time" = 60}
 * )
 */
class NotificationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The notification service.
   *
   * @var \Drupal\flagger_notify\Service\FlaggerNotifyService
   */
  protected $notifyService;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new NotificationQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\flagger_notify\Service\FlaggerNotifyService $notify_service
   *   The notification service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FlaggerNotifyService $notify_service, \Drupal\Core\State\StateInterface $state, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->notifyService = $notify_service;
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('flagger_notify.service'),
      $container->get('state'),
      $container->get('config.factory'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (isset($data->nid)) {
      try {
        $this->notifyService->sendNodeNotifications((int) $data->nid);
        // Successful processing, release lock.
        $this->state->delete('flagger_notify.queued.' . $data->nid);
      }
      catch (\Exception $e) {
        $logger = $this->loggerFactory->get('flagger_notify');
        
        // If robust retry is enabled, check if we should requeue.
        $config = $this->configFactory->get('flagger_notify.settings');
        if ($config->get('retry_on_failure')) {
          // Log specific error for debugging.
          $logger->error('Processing failed for node @nid. Retrying. Error: @msg', [
            '@nid' => $data->nid,
            '@msg' => $e->getMessage(),
          ]);
          // Requeue the item. Drupal will release it back to the queue.
          // Note: The state lock 'flagger_notify.queued.NID' REMAINS SET,
          // preventing the debounce logic from adding a DUPLICATE item.
          // This is exactly what we want.
          throw new RequeueException($e->getMessage());
        }
        else {
          // Standard behavior: Release the lock so it CAN be queued again by a NEW update event,
          // but this specific attempt is abandoned.
          $this->state->delete('flagger_notify.queued.' . $data->nid);
          // Re-throw so Drupal logs it as a failed worker item if needed, 
          // or just swallow it if we want to fail silently (but logging is better).
          // For now, let's log and not re-throw to avoid "broken queue" status unless desired.
          $logger->error('Processing failed for node @nid. Dropped. Error: @msg', [
            '@nid' => $data->nid,
            '@msg' => $e->getMessage(),
          ]);
        }
      }
    }
  }

}
