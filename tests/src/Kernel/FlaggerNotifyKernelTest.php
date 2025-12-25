<?php

declare(strict_types=1);

namespace Drupal\Tests\flagger_notify\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\flag\Entity\Flag;
use Drupal\Core\Test\AssertMailTrait;

/**
 * Tests the Flagger Notify logic (End-to-End).
 *
 * @group flagger_notify
 */
class FlaggerNotifyKernelTest extends KernelTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'text',
    'field',
    'flag',
    'token',
    'flagger_notify',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences', 'key_value']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('flagging');
    $this->installConfig(['flagger_notify']);

    // Create a content type.
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    // Create a Flag.
    $flag = Flag::create([
      'id' => 'subscribe',
      'label' => 'Subscribe',
      'entity_type' => 'node',
      'global' => FALSE,
      'flag_type' => 'entity:node',
      'link_type' => 'ajax_link',
      'flagTypeConfig' => [],
      'linkTypeConfig' => [],
    ]);
    $flag->save();

    // Enable notifications for this flag in config.
    $this->config('flagger_notify.settings')
      ->set('flags_config', [
        'subscribe' => [
          'enabled' => TRUE,
          'subject' => 'Update: [node:title]',
          'body' => 'Hello [user:display-name], content updated.',
        ],
      ])
      ->save();
  }

  /**
   * Tests the notification flow: Flag -> Update -> Queue -> Email.
   */
  public function testNotificationFlow() {
    // 1. Create a User.
    $user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
      'status' => 1,
    ]);
    $user->save();

    // 2. Create a Node.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Original Title',
      'status' => 1,
      'uid' => $user->id(),
    ]);
    $node->save();

    // 3. User flags the node.
    /** @var \Drupal\flag\FlagService $flag_service */
    $flag_service = \Drupal::service('flag');
    $flag = $flag_service->getFlagById('subscribe');
    $flag_service->flag($flag, $node, $user);

    // 4. Update the node (triggering hook_entity_update).
    $node->setTitle('Updated Title');
    $node->save();

    // Assert item is in queue.
    $queue = \Drupal::queue('flagger_notify_worker');
    $this->assertEquals(1, $queue->numberOfItems(), 'Item added to queue after node update.');

    // 5. Process the queue.
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $worker */
    $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('flagger_notify_worker');
    $item = $queue->claimItem();
    $worker->processItem($item->data);

    // 6. Assert Email Sent.
    $mails = $this->getMails();
    $this->assertNotEmpty($mails, 'Email was sent.');
    $mail = end($mails);
    
    $this->assertEquals('test@example.com', $mail['to']);
    $this->assertEquals('Update: Updated Title', $mail['subject']);
    // Check trim because renderPlain might add whitespace
    $this->assertStringContainsString('Hello test_user, content updated.', trim((string)$mail['body']));
  }

}
