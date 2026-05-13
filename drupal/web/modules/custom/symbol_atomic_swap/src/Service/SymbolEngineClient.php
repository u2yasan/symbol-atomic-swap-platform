<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

final class SymbolEngineClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  public function health(): array {
    return $this->request('GET', '/health', FALSE);
  }

  public function network(): array {
    return $this->request('GET', '/v1/network');
  }

  public function buildAggregateComplete(array $payload): array {
    return $this->request('POST', '/v1/aggregate-complete/build', TRUE, $payload);
  }

  public function intent(string $intent_hash): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('GET', '/v1/intents/' . strtoupper($intent_hash));
  }

  public function projection(string $network, string $transaction_hash): array {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }
    $this->assertHash($transaction_hash, 'transaction hash');
    return $this->request('GET', '/v1/projections/' . $network . '/' . strtoupper($transaction_hash));
  }

  public function verifySignedPayload(string $intent_hash, string $payload): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/verify-signed-payload', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'payload' => strtoupper($payload),
    ]);
  }

  public function announce(string $intent_hash): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/announce', TRUE, [
      'intentHash' => strtoupper($intent_hash),
    ]);
  }

  private function baseUrl(): string {
    $base_url = getenv('SYMBOL_ENGINE_BASE_URL') ?: 'http://symbol-engine:3000';
    return rtrim($base_url, '/');
  }

  /**
   * @return array<string, string>
   */
  private function authHeaders(): array {
    $token = getenv('SYMBOL_ENGINE_API_TOKEN') ?: '';
    if ($token === '') {
      throw new \RuntimeException('SYMBOL_ENGINE_API_TOKEN is required for protected Symbol Engine API calls.');
    }

    return [
      'Authorization' => 'Bearer ' . $token,
    ];
  }

  private function timeout(): float {
    $timeout = getenv('SYMBOL_ENGINE_TIMEOUT') ?: '10';
    return max(1.0, (float) $timeout);
  }

  private function assertHash(string $value, string $label): void {
    if (!preg_match('/^[0-9A-Fa-f]{64}$/', $value)) {
      throw new \InvalidArgumentException(sprintf('Invalid %s.', $label));
    }
  }

  private function request(string $method, string $path, bool $authenticated = TRUE, ?array $json = NULL): array {
    $options = [
      'timeout' => $this->timeout(),
      'headers' => $authenticated ? $this->authHeaders() : [],
    ];

    if ($json !== NULL) {
      $options['json'] = $json;
    }

    try {
      $response = $this->httpClient->request($method, $this->baseUrl() . $path, $options);
      return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (RequestException $exception) {
      throw $this->normalizeRequestException($exception);
    }
    catch (GuzzleException $exception) {
      throw new SymbolEngineException('Symbol Engine request failed: ' . $exception->getMessage(), 0);
    }
    catch (\JsonException $exception) {
      throw new SymbolEngineException('Symbol Engine returned invalid JSON.', 0, NULL, [
        'json_error' => $exception->getMessage(),
      ]);
    }
  }

  private function normalizeRequestException(RequestException $exception): SymbolEngineException {
    $response = $exception->getResponse();
    $status_code = $response ? $response->getStatusCode() : 0;
    $body = $response ? (string) $response->getBody() : '';
    $decoded = [];

    if ($body !== '') {
      try {
        $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        $decoded = ['raw' => $body];
      }
    }

    $engine_error = is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])
      ? $decoded['error']
      : NULL;
    $engine_message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
      ? $decoded['message']
      : NULL;

    $message = match ($status_code) {
      400 => 'Symbol Engine validation failed.',
      401, 403 => 'Symbol Engine authentication failed.',
      404 => 'Symbol Engine resource was not found.',
      409 => 'Symbol Engine rejected the current state transition.',
      default => $status_code >= 500 ? 'Symbol Engine is unavailable.' : 'Symbol Engine request failed.',
    };

    if ($engine_message !== NULL) {
      $message .= ' ' . $engine_message;
    }

    return new SymbolEngineException($message, $status_code, $engine_error, is_array($decoded) ? $decoded : []);
  }

}
