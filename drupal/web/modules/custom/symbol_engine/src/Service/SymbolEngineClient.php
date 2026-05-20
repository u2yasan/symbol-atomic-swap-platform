<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\symbol_engine\Exception\SymbolEngineException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class SymbolEngineClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function health(): array {
    return $this->request('GET', '/health', FALSE);
  }

  public function network(): array {
    return $this->request('GET', '/v1/network');
  }

  public function buildAccountVerification(string $network, string $address, string $signer_public_key, string $challenge): array {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }
    $this->assertRawAddress($address, $network);
    $this->assertPublicKey($signer_public_key);

    return $this->request('POST', '/v1/account-verification/build', TRUE, [
      'network' => $network,
      'address' => strtoupper($address),
      'signerPublicKey' => strtoupper($signer_public_key),
      'challenge' => $challenge,
      'deadlineHours' => 1,
    ]);
  }

  public function verifyAccountVerification(string $network, string $address, string $signer_public_key, string $challenge, string $payload): array {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }
    $this->assertRawAddress($address, $network);
    $this->assertPublicKey($signer_public_key);
    if (!preg_match('/^[0-9A-Fa-f]+$/', $payload) || strlen($payload) % 2 !== 0) {
      throw new \InvalidArgumentException('Invalid signed payload.');
    }

    return $this->request('POST', '/v1/account-verification/verify', TRUE, [
      'network' => $network,
      'address' => strtoupper($address),
      'signerPublicKey' => strtoupper($signer_public_key),
      'challenge' => $challenge,
      'payload' => strtoupper($payload),
    ]);
  }

  private function baseUrl(): string {
    $symbol_engine = $this->configFactory->get('symbol_engine.settings');
    $legacy = $this->configFactory->get('symbol_atomic_swap.settings');
    $configured = (string) ($symbol_engine->get('engine_base_url') ?: $legacy->get('engine_base_url') ?: 'http://symbol-engine:3000');
    return rtrim(getenv('SYMBOL_ENGINE_BASE_URL') ?: $configured, '/');
  }

  /**
   * @return array<string,string>
   */
  private function authHeaders(): array {
    $token = getenv('SYMBOL_ENGINE_API_TOKEN') ?: '';
    if ($token === '') {
      throw new \RuntimeException('SYMBOL_ENGINE_API_TOKEN is required for protected Symbol Engine API calls.');
    }
    if (strlen($token) < 32) {
      throw new \RuntimeException('SYMBOL_ENGINE_API_TOKEN must be at least 32 characters.');
    }

    return ['Authorization' => 'Bearer ' . $token];
  }

  private function timeout(): float {
    $symbol_engine = $this->configFactory->get('symbol_engine.settings');
    $legacy = $this->configFactory->get('symbol_atomic_swap.settings');
    $timeout = getenv('SYMBOL_ENGINE_TIMEOUT') ?: (string) ($symbol_engine->get('engine_timeout') ?: $legacy->get('engine_timeout') ?: '10');
    return max(1.0, (float) $timeout);
  }

  private function assertPublicKey(string $value): void {
    if (!preg_match('/^[0-9A-Fa-f]{64}$/', $value)) {
      throw new \InvalidArgumentException('Invalid public key.');
    }
  }

  private function assertRawAddress(string $value, string $network): void {
    $prefix = match ($network) {
      'mainnet' => 'N',
      'testnet' => 'T',
      default => '',
    };
    if ($prefix === '' || preg_match('/^' . $prefix . '[A-Z2-7]{38}$/', strtoupper($value)) !== 1) {
      throw new \InvalidArgumentException('Invalid Symbol address.');
    }
  }

  /**
   * @param array<string,mixed>|null $json
   *
   * @return array<string,mixed>
   */
  private function request(string $method, string $path, bool $authenticated = TRUE, ?array $json = NULL): array {
    $options = [
      'timeout' => $this->timeout(),
      'headers' => $authenticated ? $this->authHeaders() : [],
      'http_errors' => FALSE,
    ];
    if ($json !== NULL) {
      $options['json'] = $json;
    }

    try {
      $response = $this->httpClient->request($method, $this->baseUrl() . $path, $options);
    }
    catch (GuzzleException $exception) {
      throw new SymbolEngineException('Symbol Engine request failed: ' . $exception->getMessage(), 0, NULL, [], $exception);
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $decoded['reason'] ?? 'request failed') : 'request failed';
      throw new SymbolEngineException('Symbol Engine request failed. ' . $message, $response->getStatusCode(), is_array($decoded) ? (string) ($decoded['error'] ?? $decoded['reason'] ?? '') : NULL, is_array($decoded) ? $decoded : []);
    }
    if (!is_array($decoded)) {
      throw new SymbolEngineException('Symbol Engine returned invalid JSON.');
    }

    return $decoded;
  }

}

