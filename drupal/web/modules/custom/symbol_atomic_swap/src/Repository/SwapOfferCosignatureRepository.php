<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Component\Datetime\TimeInterface;

final class SwapOfferCosignatureRepository {

  private const TABLE = 'symbol_atomic_swap_cosignature';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return array<int, array<string, mixed>>
   */
  public function findByOffer(int $offer_id): array {
    return $this->database->select(self::TABLE, 'c')
      ->fields('c')
      ->condition('offer_id', $offer_id)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * @return array<int, array<string, string>>
   */
  public function assemblyPayloadsByOffer(int $offer_id): array {
    $records = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['parent_hash', 'signer_public_key', 'signature'])
      ->condition('offer_id', $offer_id)
      ->orderBy('signer_public_key')
      ->execute()
      ->fetchAll(FetchAs::Associative);

    return array_map(static fn (array $record): array => [
      'parentHash' => (string) $record['parent_hash'],
      'signerPublicKey' => (string) $record['signer_public_key'],
      'signature' => (string) $record['signature'],
    ], $records);
  }

  /**
   * @param array<string, mixed> $values
   */
  public function upsert(array $values): void {
    $existing_id = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['id'])
      ->condition('offer_id', (int) $values['offer_id'])
      ->condition('parent_hash', strtoupper((string) $values['parent_hash']))
      ->condition('signer_public_key', strtoupper((string) $values['signer_public_key']))
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $fields = [
      'offer_id' => (int) $values['offer_id'],
      'parent_hash' => strtoupper((string) $values['parent_hash']),
      'signer_public_key' => strtoupper((string) $values['signer_public_key']),
      'signature' => strtoupper((string) $values['signature']),
      'trusted_parent_hash' => !empty($values['trusted_parent_hash']) ? 1 : 0,
      'uid' => (int) ($values['uid'] ?? 0),
      'created' => $this->time->getRequestTime(),
    ];

    if ($existing_id !== FALSE) {
      $this->database->update(self::TABLE)
        ->fields($fields)
        ->condition('id', (int) $existing_id)
        ->execute();
      return;
    }

    $this->database->insert(self::TABLE)
      ->fields($fields)
      ->execute();
  }

}
