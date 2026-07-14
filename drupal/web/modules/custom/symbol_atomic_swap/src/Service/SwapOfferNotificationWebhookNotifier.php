<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class SwapOfferNotificationWebhookNotifier {

  /**
   * @param (callable(string): string[])|null $hostAddressResolver
   *   Resolves a hostname to its IP addresses. Defaults to real DNS resolution;
   *   injectable so the SSRF guard can be tested deterministically without
   *   depending on live DNS.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private $hostAddressResolver = NULL,
  ) {}

  /**
   * Sends a best-effort webhook for a user-facing offer notification.
   *
   * @param array<string, mixed> $notification
   */
  public function notify(array $notification): void {
    $config = $this->configFactory->get('symbol_atomic_swap.settings');
    $url = trim((string) (getenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_URL') ?: $config->get('webhook_url')));
    if ($url === '') {
      return;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], TRUE)) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Swap notification webhook URL is invalid.');
      return;
    }

    // SSRF guard: the webhook destination must resolve to a public address so a
    // misconfigured URL cannot be used to reach loopback, link-local, or
    // private-range internal services (e.g. cloud metadata endpoints).
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '' || !$this->hostResolvesToPublicAddress($host)) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Swap notification webhook URL resolves to a non-public address and was blocked.');
      return;
    }

    $headers = [
      'content-type' => 'application/json',
    ];
    $token = trim((string) getenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TOKEN'));
    if ($token !== '') {
      $headers['authorization'] = 'Bearer ' . $token;
    }

    $timeout = (float) (getenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TIMEOUT') ?: ($config->get('webhook_timeout') ?: 3));
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
        // Do not follow redirects: a 30x to an internal host would otherwise
        // bypass the resolved-address SSRF guard above.
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $exception) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Swap notification webhook delivery failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Determines whether every resolved address for a host is publicly routable.
   *
   * Fails closed: an unresolvable host or any private/reserved/loopback/
   * link-local address blocks delivery.
   */
  private function hostResolvesToPublicAddress(string $host): bool {
    // Strip an IPv6 literal's surrounding brackets, e.g. "[::1]".
    $literal = trim($host, '[]');
    if (filter_var($literal, FILTER_VALIDATE_IP) !== FALSE) {
      return $this->isPublicAddress($literal);
    }

    $addresses = $this->resolveHostAddresses($host);
    if ($addresses === []) {
      return FALSE;
    }

    foreach ($addresses as $address) {
      if (!$this->isPublicAddress($address)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Resolves a hostname to its IPv4/IPv6 addresses.
   *
   * @return string[]
   */
  private function resolveHostAddresses(string $host): array {
    if ($this->hostAddressResolver !== NULL) {
      return array_values(($this->hostAddressResolver)($host));
    }

    $addresses = [];
    $ipv4 = @gethostbynamel($host);
    if (is_array($ipv4)) {
      $addresses = $ipv4;
    }
    $ipv6Records = @dns_get_record($host, DNS_AAAA);
    if (is_array($ipv6Records)) {
      foreach ($ipv6Records as $record) {
        if (isset($record['ipv6'])) {
          $addresses[] = (string) $record['ipv6'];
        }
      }
    }

    return $addresses;
  }

  private function isPublicAddress(string $address): bool {
    return filter_var(
      $address,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) !== FALSE;
  }

}
