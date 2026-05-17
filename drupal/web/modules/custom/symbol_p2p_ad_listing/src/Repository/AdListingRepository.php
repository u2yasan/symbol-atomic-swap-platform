<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;

final class AdListingRepository {

  private const TABLE = 'symbol_p2p_ad_listing';
  public const ACTIVE = 'active';
  public const MATCHING = 'matching';
  public const MATCHED = 'matched';
  public const CANCELLED = 'cancelled';
  public const EXPIRED = 'expired';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly UuidInterface $uuid,
    private readonly SwapOfferRepository $offers,
  ) {}

  /**
   * @param array<string, string> $filters
   *
   * @return array<int, array<string, mixed>>
   */
  public function search(array $filters = [], int $limit = 100): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->orderBy('changed', 'DESC')
      ->range(0, $limit);

    if (!empty($filters['status'])) {
      $query->condition('status', $filters['status']);
    }
    if (!empty($filters['network'])) {
      $query->condition('network', $filters['network']);
    }
    if (!empty($filters['offered_mosaic_id']) && $this->isMosaicId($filters['offered_mosaic_id'])) {
      $query->condition('offered_mosaic_id', strtoupper($filters['offered_mosaic_id']));
    }
    if (!empty($filters['requested_mosaic_id']) && $this->isMosaicId($filters['requested_mosaic_id'])) {
      $query->condition('requested_mosaic_id', strtoupper($filters['requested_mosaic_id']));
    }
    if (!empty($filters['q'])) {
      $or = $query->orConditionGroup()
        ->condition('label', '%' . $this->database->escapeLike($filters['q']) . '%', 'LIKE')
        ->condition('seller_address', strtoupper($filters['q']))
        ->condition('offered_mosaic_id', strtoupper($filters['q']))
        ->condition('requested_mosaic_id', strtoupper($filters['q']));
      $query->condition($or);
    }

    return $query->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function find(int $id): ?array {
    $record = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return is_array($record) ? $record : NULL;
  }

  /**
   * @param array<string, mixed> $values
   */
  public function create(array $values): int {
    $now = $this->time->getRequestTime();
    $values += [
      'uuid' => $this->uuid->generate(),
      'status' => self::ACTIVE,
      'created' => $now,
      'changed' => $now,
    ];

    return (int) $this->database->insert(self::TABLE)
      ->fields($values)
      ->execute();
  }

  /**
   * Creates the exact atomic settlement that will execute a matched listing.
   *
   * @param array<string, mixed> $listing
   * @param array{uid: int, address: string, public_key: string} $taker
   */
  public function matchToAtomicSettlement(array $listing, array $taker): int {
    if ((string) $listing['status'] !== self::ACTIVE) {
      throw new \InvalidArgumentException('Only active listings can be matched.');
    }
    if ($this->isExpired($listing)) {
      throw new \InvalidArgumentException('Listing is expired.');
    }
    if ((int) $listing['seller_uid'] === (int) $taker['uid']) {
      throw new \InvalidArgumentException('Seller cannot take their own listing.');
    }

    $transaction = $this->database->startTransaction();
    try {
      $claimed = (int) $this->database->update(self::TABLE)
        ->fields([
          'status' => self::MATCHING,
          'changed' => $this->time->getRequestTime(),
        ])
        ->condition('id', (int) $listing['id'])
        ->condition('status', self::ACTIVE)
        ->execute();
      if ($claimed !== 1) {
        throw new \InvalidArgumentException('Listing is no longer active.');
      }

      $offer_id = $this->offers->insert([
        'uuid' => $this->uuid->generate(),
        'label' => 'P2P listing #' . $listing['id'] . ': ' . $listing['label'],
        'state' => 'open',
        'network' => (string) $listing['network'],
        'correlation_id' => $this->offers->nextCorrelationId((string) $listing['network']),
        'deadline_hours' => max(1, min(48, (int) ceil(((int) $listing['swap_window_minutes']) / 60))),
        'max_fee' => NULL,
        'leg1_signer_public_key' => (string) $listing['seller_public_key'],
        'leg1_recipient_address' => (string) $taker['address'],
        'leg1_mosaic_id' => (string) $listing['offered_mosaic_id'],
        'leg1_amount' => (string) $listing['offered_amount'],
        'leg2_signer_public_key' => (string) $taker['public_key'],
        'leg2_recipient_address' => (string) $listing['seller_address'],
        'leg2_mosaic_id' => (string) $listing['requested_mosaic_id'],
        'leg2_amount' => (string) $listing['requested_amount'],
        'uid' => (int) $listing['seller_uid'],
        'created' => $this->time->getRequestTime(),
        'changed' => $this->time->getRequestTime(),
      ]);

      $this->database->update(self::TABLE)
        ->fields([
          'status' => self::MATCHED,
          'matched_offer_id' => $offer_id,
          'changed' => $this->time->getRequestTime(),
        ])
        ->condition('id', (int) $listing['id'])
        ->condition('status', self::MATCHING)
        ->execute();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    return $offer_id;
  }

  public function cancel(int $id): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::CANCELLED,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('status', self::ACTIVE)
      ->execute();
  }

  /**
   * @return int[]
   */
  public function expirationCandidateIds(int $now, int $limit = 50): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['id'])
      ->condition('status', self::ACTIVE)
      ->isNotNull('expires_at')
      ->condition('expires_at', $now, '<=')
      ->orderBy('expires_at', 'ASC')
      ->range(0, $limit);

    return array_map('intval', $query->execute()->fetchCol());
  }

  public function markExpired(int $id): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::EXPIRED,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('status', self::ACTIVE)
      ->execute();
  }

  /**
   * @return int[]
   */
  public function activeBalanceCheckCandidateIds(int $limit = 50): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['id'])
      ->condition('status', self::ACTIVE)
      ->orderBy('seller_balance_checked_at', 'ASC')
      ->orderBy('changed', 'ASC')
      ->range(0, $limit);

    return array_map('intval', $query->execute()->fetchCol());
  }

  public function updateSellerBalanceCheck(int $id, string $amount): void {
    if (!preg_match('/^(0|[1-9][0-9]*)$/', $amount)) {
      throw new \InvalidArgumentException('Balance amount must be an atomic integer string.');
    }

    $this->database->update(self::TABLE)
      ->fields([
        'seller_balance_checked_amount' => $amount,
        'seller_balance_checked_at' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('status', self::ACTIVE)
      ->execute();
  }

  /**
   * @param array<string, mixed> $listing
   */
  public function isExpired(array $listing): bool {
    return !empty($listing['expires_at']) && (int) $listing['expires_at'] <= $this->time->getRequestTime();
  }

  private function isMosaicId(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{16}$/', $value) === 1;
  }

}
