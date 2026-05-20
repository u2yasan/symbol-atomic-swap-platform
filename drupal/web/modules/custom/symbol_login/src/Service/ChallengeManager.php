<?php

namespace Drupal\symbol_login\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;

/**
 * Issues and consumes one-time Symbol login challenges.
 */
final class ChallengeManager {

  private const COLLECTION = 'symbol_login_challenges';

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Creates a short-lived challenge.
   *
   * @return array{id:string,message:string,expires:int}
   */
  public function create(): array {
    $config = $this->configFactory->get('symbol_login.settings');
    $ttl = max(60, (int) $config->get('challenge_ttl_seconds') ?: 300);
    $id = $this->uuid->generate();
    $expires = $this->time->getRequestTime() + $ttl;
    $message = implode("\n", [
      'Symbol Login',
      'Challenge: ' . $id,
      'Network: ' . ($config->get('network_type') ?: 'testnet'),
      'Expires: ' . $expires,
    ]);

    $this->store()->setWithExpire($id, [
      'message' => $message,
      'expires' => $expires,
      'used' => FALSE,
    ], $ttl);

    return [
      'id' => $id,
      'message' => $message,
      'expires' => $expires,
    ];
  }

  /**
   * Creates a challenge bound to a Symbol account for transaction payload signing.
   *
   * @return array{id:string,message:string,expires:int,network:string,address:string,publicKey:string}
   */
  public function createForAccount(string $address, string $publicKey): array {
    $challenge = $this->create();
    $network = (string) ($this->configFactory->get('symbol_login.settings')->get('network_type') ?: 'testnet');
    $record = $this->store()->get($challenge['id']);
    if (!is_array($record)) {
      throw new SymbolLoginException('Challenge could not be stored.');
    }
    $record['network'] = $network;
    $record['address'] = strtoupper(str_replace(['-', ' '], '', trim($address)));
    $record['publicKey'] = strtoupper(trim($publicKey));
    $ttl = max(60, (int) $this->configFactory->get('symbol_login.settings')->get('challenge_ttl_seconds') ?: 300);
    $this->store()->setWithExpire($challenge['id'], $record, $ttl);

    return $challenge + [
      'network' => $record['network'],
      'address' => $record['address'],
      'publicKey' => $record['publicKey'],
    ];
  }

  /**
   * Consumes a challenge once and returns the signed message.
   */
  public function consume(string $id): string {
    return (string) $this->consumeRecord($id)['message'];
  }

  /**
   * Consumes a challenge once and returns the full record.
   *
   * @return array<string,mixed>
   */
  public function consumeRecord(string $id): array {
    $id = trim($id);
    if ($id === '') {
      throw new SymbolLoginException('Missing challenge ID.');
    }

    $record = $this->store()->get($id);
    if (!is_array($record)) {
      throw new SymbolLoginException('Challenge is invalid or expired.');
    }

    $this->store()->delete($id);

    if (!empty($record['used']) || empty($record['message']) || empty($record['expires'])) {
      throw new SymbolLoginException('Challenge is invalid.');
    }
    if ((int) $record['expires'] < $this->time->getRequestTime()) {
      throw new SymbolLoginException('Challenge has expired.');
    }

    return $record;
  }

  private function store() {
    return $this->keyValueExpirableFactory->get(self::COLLECTION);
  }

}
