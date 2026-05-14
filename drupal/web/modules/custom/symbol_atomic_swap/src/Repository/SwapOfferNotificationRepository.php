<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\symbol_atomic_swap\Service\SwapOfferNotificationEmailNotifier;
use Drupal\symbol_atomic_swap\Service\SwapOfferNotificationWebhookNotifier;

final class SwapOfferNotificationRepository {

  private const TABLE = 'symbol_atomic_swap_notification';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly SwapOfferNotificationWebhookNotifier $webhookNotifier,
    private readonly SwapOfferNotificationEmailNotifier $emailNotifier,
  ) {}

  public function createOnce(int $offer_id, string $type, string $severity, string $message): void {
    if (!in_array($severity, ['status', 'warning', 'error'], TRUE)) {
      throw new \InvalidArgumentException('Invalid notification severity.');
    }

    $existing = $this->database->select(self::TABLE, 'n')
      ->fields('n', ['id'])
      ->condition('offer_id', $offer_id)
      ->condition('type', $type)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $created = $this->time->getRequestTime();

    $this->database->merge(self::TABLE)
      ->keys([
        'offer_id' => $offer_id,
        'type' => $type,
      ])
      ->fields([
        'severity' => $severity,
        'message' => $message,
        'created' => $created,
      ])
      ->execute();

    if (!$existing) {
      $this->emailNotifier->notify([
        'offer_id' => $offer_id,
        'type' => $type,
        'severity' => $severity,
        'message' => $message,
        'created' => $created,
      ]);
      $this->webhookNotifier->notify([
        'offer_id' => $offer_id,
        'type' => $type,
        'severity' => $severity,
        'message' => $message,
        'created' => $created,
      ]);
    }
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function all(int $limit = 100, bool $unread_only = FALSE, ?int $owner_id = NULL): array {
    $query = $this->database->select(self::TABLE, 'n')
      ->fields('n')
      ->orderBy('created', 'DESC')
      ->range(0, $limit);

    if ($owner_id !== NULL) {
      $query->innerJoin('symbol_atomic_swap_offer', 'o', 'o.id = n.offer_id');
      $query->condition('o.uid', $owner_id);
    }
    if ($unread_only) {
      $query->isNull('read_at');
    }

    return $query->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function findByOffer(int $offer_id, int $limit = 20): array {
    return $this->database->select(self::TABLE, 'n')
      ->fields('n')
      ->condition('offer_id', $offer_id)
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  public function unreadCount(?int $owner_id = NULL): int {
    $query = $this->database->select(self::TABLE, 'n')
      ->condition('read_at', NULL, 'IS NULL');
    if ($owner_id !== NULL) {
      $query->innerJoin('symbol_atomic_swap_offer', 'o', 'o.id = n.offer_id');
      $query->condition('o.uid', $owner_id);
    }

    return (int) $query->countQuery()->execute()->fetchField();
  }

  public function markRead(int $id): void {
    $this->database->update(self::TABLE)
      ->fields(['read_at' => $this->time->getRequestTime()])
      ->condition('id', $id)
      ->isNull('read_at')
      ->execute();
  }

  public function markAllRead(?int $owner_id = NULL): void {
    if ($owner_id === NULL) {
      $this->database->update(self::TABLE)
        ->fields(['read_at' => $this->time->getRequestTime()])
        ->isNull('read_at')
        ->execute();
      return;
    }

    $ids = $this->database->select(self::TABLE, 'n')
      ->fields('n', ['id'])
      ->condition('read_at', NULL, 'IS NULL');
    $ids->innerJoin('symbol_atomic_swap_offer', 'o', 'o.id = n.offer_id');
    $ids->condition('o.uid', $owner_id);
    $notification_ids = array_map('intval', $ids->execute()->fetchCol());
    if ($notification_ids === []) {
      return;
    }

    $this->database->update(self::TABLE)
      ->fields(['read_at' => $this->time->getRequestTime()])
      ->condition('id', $notification_ids, 'IN')
      ->execute();
  }

  public function ownerId(int $notification_id): ?int {
    $query = $this->database->select(self::TABLE, 'n')
      ->condition('n.id', $notification_id)
      ->range(0, 1);
    $query->innerJoin('symbol_atomic_swap_offer', 'o', 'o.id = n.offer_id');
    $query->fields('o', ['uid']);
    $owner_id = $query->execute()->fetchField();

    return $owner_id !== FALSE ? (int) $owner_id : NULL;
  }

}
