<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;

final class AdListingRepository {

  private const TABLE = 'symbol_p2p_ad_listing';
  public const ACTIVE = 'active';
  public const MATCHING = 'matching';
  public const MATCHED = 'matched';
  public const CANCELLED = 'cancelled';
  public const EXPIRED = 'expired';
  public const INSUFFICIENT_BALANCE = 'insufficient_balance';
  private const EDITABLE_STATES = [self::ACTIVE, self::INSUFFICIENT_BALANCE];
  private const LISTING_LIMIT_STATES = [self::ACTIVE, self::INSUFFICIENT_BALANCE, self::MATCHING];
  private const RESERVING_STATES = [self::ACTIVE, self::INSUFFICIENT_BALANCE, self::MATCHING, self::MATCHED];
  private const RELEASABLE_OFFER_STATES = ['expired', 'cancelled', 'failed', 'rolled_back'];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly UuidInterface $uuid,
    private readonly SwapOfferRepository $offers,
  ) {}

  /**
   * @param array<string, string> $filters
   * @param array<int, mixed>|null $sort_header
   *
   * @return array<int, array<string, mixed>>
   */
  public function search(array $filters = [], int $limit = 100, ?array $sort_header = NULL): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l')
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

    if ($sort_header !== NULL) {
      $query = $query->extend(TableSortExtender::class);
      $query->orderByHeader($sort_header);
    }
    $query
      ->orderBy('changed', 'DESC')
      ->orderBy('id', 'DESC');

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

  public function countListingLimitedBySellerUid(int $seller_uid, ?int $exclude_id = NULL): int {
    $query = $this->database->select(self::TABLE, 'l')
      ->condition('seller_uid', $seller_uid)
      ->condition('status', self::LISTING_LIMIT_STATES, 'IN');
    $query->addExpression('COUNT(*)');
    if ($exclude_id !== NULL) {
      $query->condition('id', $exclude_id, '<>');
    }

    return (int) $query->execute()->fetchField();
  }

  public function hasDuplicateReservingListing(array $values, ?int $exclude_id = NULL): bool {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['id'])
      ->condition('seller_uid', (int) $values['seller_uid'])
      ->condition('network', (string) $values['network'])
      ->condition('offered_mosaic_id', strtoupper((string) $values['offered_mosaic_id']))
      ->condition('offered_amount', (string) $values['offered_amount'])
      ->condition('requested_mosaic_id', strtoupper((string) $values['requested_mosaic_id']))
      ->condition('requested_amount', (string) $values['requested_amount'])
      ->condition('status', self::RESERVING_STATES, 'IN')
      ->range(0, 1);
    if ($exclude_id !== NULL) {
      $query->condition('id', $exclude_id, '<>');
    }

    return (bool) $query->execute()->fetchField();
  }

  public function sumReservedOfferedAmount(string $network, string $seller_address, string $mosaic_id, ?int $exclude_id = NULL): string {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['offered_amount'])
      ->condition('network', $network)
      ->condition('seller_address', strtoupper($seller_address))
      ->condition('offered_mosaic_id', strtoupper($mosaic_id))
      ->condition('status', self::RESERVING_STATES, 'IN');
    if ($exclude_id !== NULL) {
      $query->condition('id', $exclude_id, '<>');
    }

    $total = '0';
    foreach ($query->execute()->fetchCol() as $amount) {
      $amount = (string) $amount;
      if (preg_match('/^[0-9]+$/', $amount) === 1) {
        $total = $this->addAtomic($total, $amount);
      }
    }
    return $total;
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
   * @param array<string, mixed> $values
   */
  public function updateEditable(int $id, array $values): void {
    $values['changed'] = $this->time->getRequestTime();

    $this->database->update(self::TABLE)
      ->fields($values + ['status' => self::ACTIVE])
      ->condition('id', $id)
      ->condition('status', self::EDITABLE_STATES, 'IN')
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

  public function deleteActive(int $id): void {
    $this->database->delete(self::TABLE)
      ->condition('id', $id)
      ->condition('status', self::EDITABLE_STATES, 'IN')
      ->execute();
  }

  /**
   * @return int[]
   */
  public function expirationCandidateIds(int $now, int $limit = 50): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['id'])
      ->condition('status', [self::ACTIVE, self::INSUFFICIENT_BALANCE], 'IN')
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
      ->condition('status', [self::ACTIVE, self::INSUFFICIENT_BALANCE], 'IN')
      ->execute();
  }

  /**
   * Reopens matched listings whose backing atomic settlement cannot complete.
   *
   * @return array{released: int[], expired: int[]}
   */
  public function releaseFailedMatches(int $now, int $limit = 50): array {
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['id', 'expires_at'])
      ->condition('l.status', self::MATCHED)
      ->isNotNull('l.matched_offer_id')
      ->orderBy('l.changed', 'ASC')
      ->range(0, $limit);
    $query->leftJoin('symbol_atomic_swap_offer', 'o', 'o.id = l.matched_offer_id');
    $query->addField('o', 'id', 'offer_id');
    $query->addField('o', 'state', 'offer_state');
    $terminal = $query->orConditionGroup()
      ->isNull('o.id')
      ->condition('o.state', self::RELEASABLE_OFFER_STATES, 'IN');
    $query->condition($terminal);

    $released = [];
    $expired = [];
    foreach ($query->execute()->fetchAll(FetchAs::Associative) as $record) {
      $id = (int) $record['id'];
      if (!empty($record['expires_at']) && (int) $record['expires_at'] <= $now) {
        $updated = (int) $this->database->update(self::TABLE)
          ->fields([
            'status' => self::EXPIRED,
            'changed' => $this->time->getRequestTime(),
          ])
          ->condition('id', $id)
          ->condition('status', self::MATCHED)
          ->execute();
        if ($updated === 1) {
          $expired[] = $id;
        }
        continue;
      }

      $updated = (int) $this->database->update(self::TABLE)
        ->fields([
          'status' => self::ACTIVE,
          'matched_offer_id' => NULL,
          'changed' => $this->time->getRequestTime(),
        ])
        ->condition('id', $id)
        ->condition('status', self::MATCHED)
        ->execute();
      if ($updated === 1) {
        $released[] = $id;
      }
    }

    return ['released' => $released, 'expired' => $expired];
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

  public function markInsufficientBalance(int $id, string $amount): void {
    if (!preg_match('/^(0|[1-9][0-9]*)$/', $amount)) {
      throw new \InvalidArgumentException('Balance amount must be an atomic integer string.');
    }

    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::INSUFFICIENT_BALANCE,
        'seller_balance_checked_amount' => $amount,
        'seller_balance_checked_at' => $this->time->getRequestTime(),
        'changed' => $this->time->getRequestTime(),
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

  private function addAtomic(string $left, string $right): string {
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    $carry = 0;
    $sum = '';
    $left_index = strlen($left) - 1;
    $right_index = strlen($right) - 1;

    while ($left_index >= 0 || $right_index >= 0 || $carry > 0) {
      $digit = $carry;
      if ($left_index >= 0) {
        $digit += (int) $left[$left_index];
        $left_index--;
      }
      if ($right_index >= 0) {
        $digit += (int) $right[$right_index];
        $right_index--;
      }
      $sum = (string) ($digit % 10) . $sum;
      $carry = intdiv($digit, 10);
    }

    return ltrim($sum, '0') ?: '0';
  }

}
