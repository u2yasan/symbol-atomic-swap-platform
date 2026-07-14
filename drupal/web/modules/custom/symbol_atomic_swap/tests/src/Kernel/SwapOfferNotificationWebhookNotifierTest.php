<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_atomic_swap\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\symbol_atomic_swap\Service\SwapOfferNotificationWebhookNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests outbound swap offer notification webhooks.
 */
#[Group('symbol_atomic_swap')]
final class SwapOfferNotificationWebhookNotifierTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'symbol_engine', 'symbol_atomic_swap'];

  /**
   * Environment values to restore after each test.
   *
   * @var array<string, string|false>
   */
  private array $previousEnv = [];

  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'SYMBOL_ATOMIC_SWAP_WEBHOOK_URL',
      'SYMBOL_ATOMIC_SWAP_WEBHOOK_TOKEN',
      'SYMBOL_ATOMIC_SWAP_WEBHOOK_TIMEOUT',
    ] as $name) {
      $this->previousEnv[$name] = getenv($name);
    }
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
   * Configured webhook endpoint receives the notification payload and token.
   */
  public function testNotifySendsConfiguredWebhook(): void {
    putenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_URL=https://hooks.example.test/symbol');
    putenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TOKEN=test-token');
    putenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TIMEOUT=2');

    $history = [];
    $notifier = $this->notifier([
      new Response(202, [], '{"ok":true}'),
    ], $history, static fn (): array => ['93.184.216.34']);

    $notifier->notify([
      'offer_id' => 42,
      'type' => 'offer_confirmed',
      'severity' => 'status',
      'message' => 'Swap transaction was confirmed.',
      'created' => 1700000000,
    ]);

    $this->assertCount(1, $history);
    $request = $history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://hooks.example.test/symbol', (string) $request->getUri());
    $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));

    $payload = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame('swap_offer_notification', $payload['event']);
    $this->assertSame(42, $payload['offerId']);
    $this->assertSame('offer_confirmed', $payload['type']);
  }

  /**
   * A webhook host that resolves to a private address is blocked (SSRF guard).
   */
  public function testNotifyBlocksPrivateAddressWebhook(): void {
    putenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_URL=https://internal.example.test/symbol');
    putenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TOKEN=test-token');

    $history = [];
    // Host resolves to a link-local metadata address; delivery must be blocked
    // before any request is issued.
    $notifier = $this->notifier([], $history, static fn (): array => ['169.254.169.254']);

    $notifier->notify([
      'offer_id' => 42,
      'type' => 'offer_confirmed',
      'severity' => 'status',
      'message' => 'Swap transaction was confirmed.',
      'created' => 1700000000,
    ]);

    $this->assertSame([], $history);
  }

  /**
   * Missing webhook URL disables outbound delivery.
   */
  public function testNotifyDoesNothingWhenWebhookUrlMissing(): void {
    putenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_URL');
    putenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TOKEN');

    $history = [];
    $notifier = $this->notifier([], $history);

    $notifier->notify([
      'offer_id' => 42,
      'type' => 'offer_confirmed',
      'severity' => 'status',
      'message' => 'Swap transaction was confirmed.',
      'created' => 1700000000,
    ]);

    $this->assertSame([], $history);
  }

  /**
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   * @param array<int, array<string, mixed>> $history
   * @param (callable(string): string[])|null $hostAddressResolver
   */
  private function notifier(array $responses, array &$history, ?callable $hostAddressResolver = NULL): SwapOfferNotificationWebhookNotifier {
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new SwapOfferNotificationWebhookNotifier(
      new Client(['handler' => $stack]),
      $this->container->get('logger.factory'),
      $this->container->get('config.factory'),
      $hostAddressResolver,
    );
  }

}
