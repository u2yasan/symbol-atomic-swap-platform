<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class SwapOfferNotificationWebhookNotifier {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Sends a best-effort webhook for a user-facing offer notification.
   *
   * @param array<string, mixed> $notification
   */
  public function notify(array $notification): void {
    $url = trim((string) getenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_URL'));
    if ($url === '') {
      return;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], TRUE)) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Swap notification webhook URL is invalid.');
      return;
    }

    $headers = [
      'content-type' => 'application/json',
    ];
    $token = trim((string) getenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TOKEN'));
    if ($token !== '') {
      $headers['authorization'] = 'Bearer ' . $token;
    }

    $timeout = (float) (getenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TIMEOUT') ?: 3);
    if ($timeout <= 0 || $timeout > 10) {
      $timeout = 3;
    }

    try {
      $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'json' => [
          'event' => 'swap_offer_notification',
          'offerId' => (int) $notification['offer_id'],
          'type' => (string) $notification['type'],
          'severity' => (string) $notification['severity'],
          'message' => (string) $notification['message'],
          'created' => (int) $notification['created'],
        ],
        'timeout' => $timeout,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $exception) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Swap notification webhook delivery failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
