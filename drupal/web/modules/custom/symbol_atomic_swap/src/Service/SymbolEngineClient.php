<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

final class SymbolEngineClient {

  private const WEAK_API_TOKEN_VALUES = [
    '0123456789abcdef0123456789abcdef',
    'replace-with-at-least-32-random-characters',
    'change-me',
    'changeme',
    'development-token',
  ];

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

  public function buildAggregateComplete(array $payload): array {
    return $this->request('POST', '/v1/aggregate-complete/build', TRUE, $payload);
  }

  public function buildAggregateBonded(array $payload): array {
    return $this->request('POST', '/v1/aggregate-bonded/build', TRUE, $payload);
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

  public function accountPublicKey(string $network, string $address): array {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }
    $this->assertRawAddress($address, $network);
    return $this->request('GET', '/v1/accounts/' . $network . '/' . strtoupper($address) . '/public-key');
  }

  public function mosaicMetadata(string $network, string $mosaic_id): array {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }
    if (!preg_match('/^[0-9A-Fa-f]{16}$/', $mosaic_id)) {
      throw new \InvalidArgumentException('Invalid mosaic ID.');
    }
    return $this->request('GET', '/v1/mosaics/' . $network . '/' . strtoupper($mosaic_id));
  }

  public function accountMosaicBalance(string $network, string $address, string $mosaic_id): array {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }
    $this->assertRawAddress($address, $network);
    if (!preg_match('/^[0-9A-Fa-f]{16}$/', $mosaic_id)) {
      throw new \InvalidArgumentException('Invalid mosaic ID.');
    }
    return $this->request('GET', '/v1/accounts/' . $network . '/' . strtoupper($address) . '/mosaics/' . strtoupper($mosaic_id));
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

  public function verifyOnChainAccountVerification(string $network, string $address, string $signer_public_key, string $challenge, string $recipient_address, string $transaction_hash): array {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }
    $this->assertRawAddress($address, $network);
    $this->assertRawAddress($recipient_address, $network);
    $this->assertPublicKey($signer_public_key);
    $this->assertHash($transaction_hash, 'transaction hash');
    return $this->request('POST', '/v1/account-verification/verify-on-chain', TRUE, [
      'network' => $network,
      'address' => strtoupper($address),
      'signerPublicKey' => strtoupper($signer_public_key),
      'challenge' => $challenge,
      'recipientAddress' => strtoupper($recipient_address),
      'transactionHash' => strtoupper($transaction_hash),
    ]);
  }

  public function verifySignedPayload(string $intent_hash, string $payload): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/verify-signed-payload', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'payload' => strtoupper($payload),
    ]);
  }

  public function verifyRootSignedPayload(string $intent_hash, string $payload): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/verify-root-signed-payload', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'payload' => strtoupper($payload),
    ]);
  }

  /**
   * @param array<string, mixed> $cosignature
   */
  public function verifyCosignature(string $intent_hash, array $cosignature): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/verify-cosignature', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'parentHash' => strtoupper((string) ($cosignature['parentHash'] ?? '')),
      'signerPublicKey' => strtoupper((string) ($cosignature['signerPublicKey'] ?? '')),
      'signature' => strtoupper((string) ($cosignature['signature'] ?? '')),
      'version' => $cosignature['version'] ?? NULL,
    ]);
  }

  /**
   * @param array<int, array<string, string>> $cosignatures
   */
  public function assembleCompletePayload(string $intent_hash, string $root_signed_payload, array $cosignatures): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/assemble-complete-payload', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'rootSignedPayload' => strtoupper($root_signed_payload),
      'cosignatures' => $cosignatures,
    ]);
  }

  /**
   * @param array<string, mixed> $signature
   */
  public function buildRootSignedPayload(string $intent_hash, array $signature): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/root-signed-payload', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'parentHash' => strtoupper((string) ($signature['parentHash'] ?? '')),
      'signerPublicKey' => strtoupper((string) ($signature['signerPublicKey'] ?? '')),
      'signature' => strtoupper((string) ($signature['signature'] ?? '')),
      'version' => $signature['version'] ?? NULL,
    ]);
  }

  public function announce(string $intent_hash): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/announce', TRUE, [
      'intentHash' => strtoupper($intent_hash),
    ]);
  }

  public function buildHashLock(string $intent_hash, string $signer_public_key, int $deadline_hours): array {
    $this->assertHash($intent_hash, 'intent hash');
    $this->assertPublicKey($signer_public_key);
    if ($deadline_hours < 1 || $deadline_hours > 48) {
      throw new \InvalidArgumentException('Hash lock deadline hours must be between 1 and 48.');
    }
    return $this->request('POST', '/v1/hash-lock/build', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'signerPublicKey' => strtoupper($signer_public_key),
      'deadlineHours' => $deadline_hours,
    ]);
  }

  public function announceHashLock(string $intent_hash, string $payload): array {
    $this->assertHash($intent_hash, 'intent hash');
    if (!preg_match('/^[0-9A-Fa-f]+$/', $payload) || strlen($payload) % 2 !== 0) {
      throw new \InvalidArgumentException('Invalid signed hash lock payload.');
    }
    return $this->request('POST', '/v1/hash-lock/announce', TRUE, [
      'intentHash' => strtoupper($intent_hash),
      'payload' => strtoupper($payload),
    ]);
  }

  public function announcePartial(string $intent_hash): array {
    $this->assertHash($intent_hash, 'intent hash');
    return $this->request('POST', '/v1/transactions/announce-partial', TRUE, [
      'intentHash' => strtoupper($intent_hash),
    ]);
  }

  private function baseUrl(): string {
    $base_url = getenv('SYMBOL_ENGINE_BASE_URL') ?: (string) ($this->configFactory->get('symbol_atomic_swap.settings')->get('engine_base_url') ?: 'http://symbol-engine:3000');
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
    if (strlen($token) < 32) {
      throw new \RuntimeException('SYMBOL_ENGINE_API_TOKEN must be at least 32 characters.');
    }
    if (in_array(strtolower($token), self::WEAK_API_TOKEN_VALUES, TRUE)) {
      throw new \RuntimeException('SYMBOL_ENGINE_API_TOKEN must not use a placeholder value.');
    }
    if ($this->uniqueCharacterCount($token) < 8) {
      throw new \RuntimeException('SYMBOL_ENGINE_API_TOKEN must look randomly generated.');
    }
    if ($this->isRepeatedPattern($token)) {
      throw new \RuntimeException('SYMBOL_ENGINE_API_TOKEN must not use a repeated pattern.');
    }

    return [
      'Authorization' => 'Bearer ' . $token,
    ];
  }

  private function uniqueCharacterCount(string $value): int {
    return strlen(count_chars($value, 3));
  }

  private function isRepeatedPattern(string $value): bool {
    $length = strlen($value);
    for ($pattern_length = 1; $pattern_length <= intdiv($length, 2); $pattern_length++) {
      if ($length % $pattern_length !== 0) {
        continue;
      }

      $pattern = substr($value, 0, $pattern_length);
      if (str_repeat($pattern, intdiv($length, $pattern_length)) === $value) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function timeout(): float {
    $timeout = getenv('SYMBOL_ENGINE_TIMEOUT') ?: (string) ($this->configFactory->get('symbol_atomic_swap.settings')->get('engine_timeout') ?: '10');
    return max(1.0, (float) $timeout);
  }

  private function assertHash(string $value, string $label): void {
    if (!preg_match('/^[0-9A-Fa-f]{64}$/', $value)) {
      throw new \InvalidArgumentException(sprintf('Invalid %s.', $label));
    }
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
    $engine_reason = is_array($decoded) && isset($decoded['reason']) && is_string($decoded['reason'])
      ? $decoded['reason']
      : NULL;
    $engine_error ??= $engine_reason;
    $engine_message ??= $engine_reason;

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
