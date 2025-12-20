<?php

namespace Drupal\flagger_notify\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\flagger_notify\Service\FlaggerNotifyService;

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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FlaggerNotifyService $notify_service, \Drupal\Core\State\StateInterface $state) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->notifyService = $notify_service;
    $this->state = $state;
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
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (isset($data->nid)) {
      try {
        $this->notifyService->sendNodeNotifications($data->nid);
      }
      finally {
        // Release the lock so this node can be queued again in the future.
        $this->state->delete('flagger_notify.queued.' . $data->nid);
      }
    }
  }

}
