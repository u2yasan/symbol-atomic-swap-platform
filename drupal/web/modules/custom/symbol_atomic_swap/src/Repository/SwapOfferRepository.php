<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;

final class SwapOfferRepository {

  private const TABLE = 'symbol_atomic_swap_offer';

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * @return array<int, array<string, mixed>>
   */
  public function all(): array {
    return $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->orderBy('changed', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function find(int $id): ?array {
    $record = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return is_array($record) ? $record : NULL;
  }

  /**
   * @return int[]
   */
  public function projectionSyncCandidateIds(int $limit = 50): array {
    $query = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['id'])
      ->isNotNull('transaction_hash')
      ->condition('state', ['signed', 'announced', 'unconfirmed', 'confirmed', 'partial_announced', 'partial_cosigned'], 'IN')
      ->orderBy('changed', 'ASC')
      ->range(0, $limit);

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * @return int[]
   */
  public function expirationCandidateIds(int $now, int $limit = 50): array {
    $records = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['id', 'created', 'deadline_hours'])
      ->condition('state', ['draft', 'qr_generated', 'signed'], 'IN')
      ->isNull('expired_at')
      ->orderBy('created', 'ASC')
      ->range(0, max($limit * 10, $limit))
      ->execute()
      ->fetchAll(FetchAs::Associative);

    $ids = [];
    foreach ($records as $record) {
      $expires_at = (int) $record['created'] + ((int) $record['deadline_hours'] * 3600);
      if ($expires_at <= $now) {
        $ids[] = (int) $record['id'];
      }
      if (count($ids) >= $limit) {
        break;
      }
    }

    return $ids;
  }

  /**
   * @param array<string, mixed> $values
   */
  public function insert(array $values): int {
    return (int) $this->database->insert(self::TABLE)
      ->fields($values)
      ->execute();
  }

  /**
   * @param array<string, mixed> $values
   */
  public function update(int $id, array $values): void {
    $this->database->update(self::TABLE)
      ->fields($values)
      ->condition('id', $id)
      ->execute();
  }

  public function delete(int $id): void {
    $this->database->delete(self::TABLE)
      ->condition('id', $id)
      ->execute();
  }

  public function markSigned(int $id, string $transaction_hash): void {
    $this->update($id, [
      'state' => 'signed',
      'transaction_hash' => strtoupper($transaction_hash),
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markAnnounced(int $id, string $transaction_hash): void {
    $this->update($id, [
      'state' => 'announced',
      'transaction_hash' => strtoupper($transaction_hash),
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markExpired(int $id, int $expired_at): bool {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Swap offer not found.');
    }

    if (!in_array($offer['state'], ['draft', 'qr_generated', 'signed'], TRUE)) {
      return FALSE;
    }

    $this->update($id, [
      'state' => 'expired',
      'expired_at' => $expired_at,
      'changed' => \Drupal::time()->getRequestTime(),
    ]);

    return TRUE;
  }

  /**
   * @param array<string, mixed> $projection
   */
  public function applyProjection(int $id, array $projection): void {
    $state = isset($projection['state']) ? (string) $projection['state'] : '';
    if (!in_array($state, [
      'announced',
      'unconfirmed',
      'confirmed',
      'finalized',
      'failed',
      'rolled_back',
      'partial_announced',
      'partial_cosigned',
    ], TRUE)) {
      throw new \InvalidArgumentException('Invalid projection state.');
    }

    $current = $this->find($id);
    if (!$current) {
      throw new \InvalidArgumentException('Swap offer not found.');
    }
    if (($current['state'] ?? '') === 'finalized' && $state !== 'finalized') {
      throw new \InvalidArgumentException('Finalized swap offer cannot transition to a non-finalized state.');
    }
    if (in_array($current['state'] ?? '', ['failed', 'rolled_back'], TRUE) && $state === 'finalized') {
      throw new \InvalidArgumentException('Failed or rolled back swap offer cannot transition to finalized.');
    }

    $fields = [
      'projection_state' => $state,
      'state' => $state,
      'changed' => \Drupal::time()->getRequestTime(),
    ];

    if (isset($projection['blockHeight'])) {
      $fields['block_height'] = (int) $projection['blockHeight'];
    }
    if (isset($projection['finalizedHeight'])) {
      $fields['finalized_height'] = (int) $projection['finalizedHeight'];
    }
    if (isset($projection['updatedAt'])) {
      $fields['projection_updated_at'] = (string) $projection['updatedAt'];
    }

    $this->update($id, $fields);
  }

  /**
   * Converts one row to a Symbol Engine Aggregate Complete build request.
   *
   * @param array<string, mixed> $offer
   *
   * @return array<string, mixed>
   */
  public function toEngineBuildPayload(array $offer): array {
    $payload = [
      'network' => (string) $offer['network'],
      'correlationId' => (string) $offer['correlation_id'],
      'deadlineHours' => (int) $offer['deadline_hours'],
      'legs' => [
        [
          'signerPublicKey' => (string) $offer['leg1_signer_public_key'],
          'recipientAddress' => (string) $offer['leg1_recipient_address'],
          'mosaicId' => (string) $offer['leg1_mosaic_id'],
          'amount' => (string) $offer['leg1_amount'],
        ],
        [
          'signerPublicKey' => (string) $offer['leg2_signer_public_key'],
          'recipientAddress' => (string) $offer['leg2_recipient_address'],
          'mosaicId' => (string) $offer['leg2_mosaic_id'],
          'amount' => (string) $offer['leg2_amount'],
        ],
      ],
    ];

    if (!empty($offer['max_fee'])) {
      $payload['maxFee'] = (string) $offer['max_fee'];
    }

    return $payload;
  }

  /**
   * @param array<string, mixed> $offer
   * @param array<string, mixed> $engine_result
   */
  public function engineFields(array $offer, array $engine_result): array {
    try {
      $qr_payload = isset($engine_result['qrPayload']) && is_array($engine_result['qrPayload'])
        ? json_encode($engine_result['qrPayload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        : NULL;
    }
    catch (\JsonException $exception) {
      Error::logException(\Drupal::logger('symbol_atomic_swap'), $exception);
      throw new \RuntimeException((string) new TranslatableMarkup('Symbol Engine returned an invalid QR payload.'));
    }

    return [
      'state' => 'qr_generated',
      'intent_hash' => isset($engine_result['intentHash']) ? strtoupper((string) $engine_result['intentHash']) : NULL,
      'unsigned_payload' => isset($engine_result['unsignedPayload']) ? strtoupper((string) $engine_result['unsignedPayload']) : NULL,
      'qr_payload' => $qr_payload,
      'transaction_hash' => $offer['transaction_hash'] ?? NULL,
    ];
  }

}
