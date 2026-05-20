<?php

namespace Drupal\symbol_login\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Reads Symbol account state from configured REST endpoints.
 */
final class SymbolRestClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string,mixed>
   */
  public function getAccount(string $address): array {
    return $this->requestJson('/accounts/' . rawurlencode($address));
  }

  /**
   * @return array<int,array<string,mixed>>
   */
  public function getMetadata(string $targetAddress): array {
    $json = $this->requestJson('/metadata', [
      'targetAddress' => $targetAddress,
      'pageSize' => 100,
      'order' => 'desc',
    ]);

    return is_array($json['data'] ?? NULL) ? $json['data'] : [];
  }

  /**
   * @param array<string,string|int> $query
   *
   * @return array<string,mixed>
   */
  private function requestJson(string $path, array $query = []): array {
    $config = $this->configFactory->get('symbol_login.settings');
    $endpoints = array_filter((array) $config->get('rest_endpoints'));
    $timeout = max(1, (int) $config->get('request_timeout_seconds') ?: 5);
    if (!$endpoints) {
      throw new SymbolLoginException('No Symbol REST endpoints are configured.');
    }

    $lastError = NULL;
    foreach ($endpoints as $endpoint) {
      $url = rtrim((string) $endpoint, '/') . $path;
      try {
        $response = $this->httpClient->request('GET', $url, [
          'query' => $query,
          'timeout' => $timeout,
          'http_errors' => FALSE,
          'headers' => [
            'Accept' => 'application/json',
          ],
        ]);
      }
      catch (GuzzleException $e) {
        $lastError = $e->getMessage();
        continue;
      }

      if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
        $lastError = 'HTTP ' . $response->getStatusCode();
        continue;
      }

      $decoded = json_decode((string) $response->getBody(), TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
      $lastError = 'Invalid JSON response.';
    }

    throw new SymbolLoginException('Symbol REST request failed: ' . ($lastError ?: 'all endpoints failed'));
  }

}

