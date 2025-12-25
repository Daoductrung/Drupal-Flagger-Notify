<?php

declare(strict_types=1);

namespace Drupal\Tests\flagger_notify\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Flagger Notify configuration form.
 *
 * @group flagger_notify
 */
class FlaggerNotifyTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'user',
    'flag',
    'token',
    'flagger_notify',
  ];

  /**
   * Tests access to the settings page.
   */
  public function testSettingsPage() {
    // Create a user with permission to administer flagger notify.
    $account = $this->drupalCreateUser(['administer flagger notify']);
    $this->drupalLogin($account);

    // Visit the settings page.
    $this->drupalGet('admin/config/system/flagger-notify');
    $this->assertSession()->statusCodeEquals(200);

    // Check that key fields exist.
    $this->assertSession()->fieldExists('debug_mode');
    $this->assertSession()->fieldExists('retry_on_failure');
    $this->assertSession()->fieldExists('default_subject');
    $this->assertSession()->fieldExists('default_body');

    // Test saving configuration.
    $edit = [
      'default_subject' => 'Test Subject',
      'debug_mode' => TRUE,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    
    // Verify config was saved.
    $config = $this->config('flagger_notify.settings');
    $this->assertEquals('Test Subject', $config->get('default_subject'));
    $this->assertEquals(TRUE, $config->get('debug_mode'));
  }

}
