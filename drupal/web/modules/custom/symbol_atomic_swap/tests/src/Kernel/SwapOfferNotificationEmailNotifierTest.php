<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_atomic_swap\Kernel;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\symbol_atomic_swap\Service\SwapOfferNotificationEmailNotifier;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests outbound swap offer notification email delivery.
 */
#[Group('symbol_atomic_swap')]
final class SwapOfferNotificationEmailNotifierTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'symbol_atomic_swap'];

  /**
   * Environment values to restore after each test.
   *
   * @var array<string, string|false>
   */
  private array $previousEnv = [];

  protected function setUp(): void {
    parent::setUp();
    $this->previousEnv['SYMBOL_ATOMIC_SWAP_NOTIFICATION_EMAIL'] = getenv('SYMBOL_ATOMIC_SWAP_NOTIFICATION_EMAIL');
  }

  protected function tearDown(): void {
    foreach ($this->previousEnv as $name => $value) {
      if ($value === FALSE) {
        putenv($name);
      }
      else {
        putenv($name . '=' . $value);
      }
    }
    parent::tearDown();
  }

  /**
   * Configured recipient receives the notification email params.
   */
  public function testNotifySendsConfiguredEmail(): void {
    putenv('SYMBOL_ATOMIC_SWAP_NOTIFICATION_EMAIL=ops@example.test');

    $calls = [];
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->willReturnCallback(function (
        string $module,
        string $key,
        string $to,
        string $langcode,
        array $params,
        ?string $reply = NULL,
        bool $send = TRUE,
      ) use (&$calls): array {
        $calls[] = compact('module', 'key', 'to', 'langcode', 'params', 'reply', 'send');
        return ['result' => TRUE];
      });

    $notifier = new SwapOfferNotificationEmailNotifier(
      $mail_manager,
      $this->container->get('language_manager'),
      $this->container->get('logger.factory'),
    );

    $notifier->notify([
      'offer_id' => 42,
      'type' => 'offer_confirmed',
      'severity' => 'status',
      'message' => 'Swap transaction was confirmed.',
      'created' => 1700000000,
    ]);

    $this->assertCount(1, $calls);
    $this->assertSame('symbol_atomic_swap', $calls[0]['module']);
    $this->assertSame('offer_notification', $calls[0]['key']);
    $this->assertSame('ops@example.test', $calls[0]['to']);
    $this->assertTrue($calls[0]['send']);
    $this->assertSame(42, $calls[0]['params']['notification']['offer_id']);
    $this->assertSame('offer_confirmed', $calls[0]['params']['notification']['type']);
  }

  /**
   * Missing recipient disables outbound email.
   */
  public function testNotifyDoesNothingWhenEmailMissing(): void {
    putenv('SYMBOL_ATOMIC_SWAP_NOTIFICATION_EMAIL');

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    $notifier = new SwapOfferNotificationEmailNotifier(
      $mail_manager,
      $this->container->get('language_manager'),
      $this->container->get('logger.factory'),
    );

    $notifier->notify([
      'offer_id' => 42,
      'type' => 'offer_confirmed',
      'severity' => 'status',
      'message' => 'Swap transaction was confirmed.',
      'created' => 1700000000,
    ]);
  }

  /**
   * Invalid recipients are rejected before invoking mail manager.
   */
  public function testNotifyRejectsInvalidEmail(): void {
    putenv('SYMBOL_ATOMIC_SWAP_NOTIFICATION_EMAIL=not-an-email');

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    $notifier = new SwapOfferNotificationEmailNotifier(
      $mail_manager,
      $this->container->get('language_manager'),
      $this->container->get('logger.factory'),
    );

    $notifier->notify([
      'offer_id' => 42,
      'type' => 'offer_confirmed',
      'severity' => 'status',
      'message' => 'Swap transaction was confirmed.',
      'created' => 1700000000,
    ]);
  }

  /**
   * Mail hook formats notification subject and body.
   */
  public function testMailHookFormatsNotification(): void {
    $message = [
      'subject' => '',
      'body' => [],
    ];

    symbol_atomic_swap_mail('offer_notification', $message, [
      'notification' => [
        'offer_id' => 42,
        'type' => 'offer_failed',
        'severity' => 'error',
        'message' => 'Swap transaction failed on-chain.',
      ],
    ]);

    $this->assertSame('Symbol swap notification: offer_failed', $message['subject']);
    $this->assertContains('Offer ID: 42', $message['body']);
    $this->assertContains('Severity: error', $message['body']);
    $this->assertContains('Swap transaction failed on-chain.', $message['body']);
  }

}
