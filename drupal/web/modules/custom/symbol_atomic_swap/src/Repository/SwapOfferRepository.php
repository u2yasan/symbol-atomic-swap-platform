<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;

final class SwapOfferRepository {

  private const TABLE = 'symbol_atomic_swap_offer';
  public const TERMINAL_STATES = ['expired', 'cancelled', 'failed', 'rolled_back', 'finalized'];
  public const SIGNABLE_STATES = ['payload_generated', 'root_signed', 'signed'];
  public const CANCELLABLE_STATES = ['open', 'draft', 'payload_generated', 'root_signed', 'signed'];
  public const SYNCABLE_STATES = ['signed', 'announced', 'unconfirmed', 'confirmed', 'partial_announced', 'partial_cosigned'];

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * @return array<int, array<string, mixed>>
   */
  public function all(): array {
    return $this->search();
  }

  /**
   * @param array<string, string> $filters
   *
   * @return array<int, array<string, mixed>>
   */
  /**
   * @param array{uid: int, network?: string, public_key?: string}|null $participant
   */
  public function search(array $filters = [], int $limit = 100, ?int $owner_id = NULL, ?array $participant = NULL): array {
    $query = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->orderBy('changed', 'DESC')
      ->range(0, $limit);

    if (!empty($filters['state'])) {
      $query->condition('state', $filters['state']);
    }
    if (!empty($filters['network'])) {
      $query->condition('network', $filters['network']);
    }
    if (!empty($filters['owner'])) {
      $query->condition('uid', (int) $filters['owner']);
    }
    if ($owner_id !== NULL) {
      $query->condition('uid', $owner_id);
    }
    if ($participant !== NULL) {
      $related = $query->orConditionGroup()
        ->condition('uid', (int) $participant['uid']);
      if (!empty($participant['network']) && !empty($participant['public_key'])) {
        $signer = $query->andConditionGroup()
          ->condition('network', (string) $participant['network']);
        $signer_keys = $query->orConditionGroup()
          ->condition('leg1_signer_public_key', strtoupper((string) $participant['public_key']))
          ->condition('leg2_signer_public_key', strtoupper((string) $participant['public_key']));
        $signer->condition($signer_keys);
        $related->condition($signer);
      }
      $query->condition($related);
    }
    if (!empty($filters['has_transaction_hash'])) {
      $query->isNotNull('transaction_hash');
    }
    if (!empty($filters['q'])) {
      $or = $query->orConditionGroup()
        ->condition('label', '%' . $this->database->escapeLike($filters['q']) . '%', 'LIKE')
        ->condition('correlation_id', '%' . $this->database->escapeLike($filters['q']) . '%', 'LIKE')
        ->condition('intent_hash', strtoupper($filters['q']))
        ->condition('transaction_hash', strtoupper($filters['q']));
      $query->condition($or);
    }

    return $query->execute()->fetchAll(FetchAs::Associative);
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
   * Returns TRUE when a network/correlation ID pair is already used.
   */
  public function existsByNetworkCorrelationId(string $network, string $correlation_id, ?int $exclude_id = NULL): bool {
    $query = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['id'])
      ->condition('network', $network)
      ->condition('correlation_id', $correlation_id)
      ->range(0, 1);

    if ($exclude_id !== NULL) {
      $query->condition('id', $exclude_id, '<>');
    }

    return $query->execute()->fetchField() !== FALSE;
  }

  public function nextCorrelationId(string $network): string {
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)) {
      throw new \InvalidArgumentException('Network must be mainnet or testnet.');
    }

    $prefix = 'swap-' . $network . '-';
    $records = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['correlation_id'])
      ->condition('network', $network)
      ->condition('correlation_id', $this->database->escapeLike($prefix) . '%', 'LIKE')
      ->execute()
      ->fetchCol();

    $max = 0;
    foreach ($records as $record) {
      if (preg_match('/^' . preg_quote($prefix, '/') . '([0-9]{6})$/', (string) $record, $matches) === 1) {
        $max = max($max, (int) $matches[1]);
      }
    }

    do {
      $candidate = $prefix . str_pad((string) ++$max, 6, '0', STR_PAD_LEFT);
    } while ($this->existsByNetworkCorrelationId($network, $candidate));

    return $candidate;
  }

  /**
   * @return int[]
   */
  public function projectionSyncCandidateIds(int $limit = 50): array {
    $query = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['id'])
      ->isNotNull('transaction_hash')
      ->condition('state', self::SYNCABLE_STATES, 'IN')
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
      ->condition('state', ['payload_generated', 'root_signed', 'signed'], 'IN')
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

  /**
   * @param array<string, mixed> $values
   */
  public function updateEditable(int $id, array $values): void {
    $offer = $this->find($id);
    if (!$offer || !$this->canEdit($offer)) {
      throw new \InvalidArgumentException('Only open draft atomic settlements can be edited.');
    }

    $this->update($id, $values);
  }

  public function delete(int $id): void {
    $this->database->delete(self::TABLE)
      ->condition('id', $id)
      ->execute();
  }

  public function deleteEditable(int $id): void {
    $offer = $this->find($id);
    if (!$offer || !$this->canDelete($offer)) {
      throw new \InvalidArgumentException('Only open draft atomic settlements can be deleted.');
    }

    $this->delete($id);
  }

  public function cancel(int $id): void {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (!$this->canCancel($offer)) {
      throw new \InvalidArgumentException('Only unannounced atomic settlements can be cancelled.');
    }

    $this->update($id, [
      'state' => 'cancelled',
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markSigned(int $id, string $transaction_hash): void {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (!$this->canSubmitSignedPayload($offer)) {
      throw new \InvalidArgumentException('Signed payload can only be submitted for payload-generated or already signed settlements with an intent hash.');
    }

    $this->update($id, [
      'state' => 'signed',
      'transaction_hash' => strtoupper($transaction_hash),
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markRootSigned(int $id, string $root_signed_payload, string $root_transaction_hash): void {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (!$this->canSubmitSignedPayload($offer)) {
      throw new \InvalidArgumentException('Root signed payload can only be submitted for signable settlements with an intent hash.');
    }
    if (!$this->isHash($root_transaction_hash)) {
      throw new \InvalidArgumentException('Root transaction hash must be 64 hex characters.');
    }

    $this->update($id, [
      'state' => 'root_signed',
      'root_signed_payload' => strtoupper($root_signed_payload),
      'root_transaction_hash' => strtoupper($root_transaction_hash),
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  /**
   * @param array<string, mixed> $values
   */
  public function accept(int $id, array $values): void {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (!$this->canAccept($offer)) {
      throw new \InvalidArgumentException('Only open atomic settlements can be finalized.');
    }

    $this->update($id, $values + [
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markAnnounced(int $id, string $transaction_hash): void {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (!$this->canAnnounce($offer)) {
      throw new \InvalidArgumentException('Only signed settlements with an intent hash and transaction hash can be announced.');
    }

    $this->update($id, [
      'state' => 'announced',
      'transaction_hash' => strtoupper($transaction_hash),
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markPartialAnnounced(int $id, string $transaction_hash): void {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (empty($offer['intent_hash']) || !$this->isHash((string) $offer['intent_hash'])) {
      throw new \InvalidArgumentException('Only settlements with an intent hash can be marked partial announced.');
    }
    if (!$this->isHash($transaction_hash)) {
      throw new \InvalidArgumentException('Transaction hash must be 64 hex characters.');
    }

    $this->update($id, [
      'state' => 'partial_announced',
      'projection_state' => 'partial_announced',
      'transaction_hash' => strtoupper($transaction_hash),
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markPartialCosigned(int $id, string $transaction_hash): void {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (empty($offer['intent_hash']) || !$this->isHash((string) $offer['intent_hash'])) {
      throw new \InvalidArgumentException('Only settlements with an intent hash can be marked partial cosigned.');
    }
    if (!$this->isHash($transaction_hash)) {
      throw new \InvalidArgumentException('Transaction hash must be 64 hex characters.');
    }

    $this->update($id, [
      'state' => 'partial_cosigned',
      'projection_state' => 'partial_cosigned',
      'transaction_hash' => strtoupper($transaction_hash),
      'changed' => \Drupal::time()->getRequestTime(),
    ]);
  }

  public function markExpired(int $id, int $expired_at): bool {
    $offer = $this->find($id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }

    if (!in_array($offer['state'], ['open', 'draft', 'payload_generated', 'signed'], TRUE)) {
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
   * @param array<string, mixed> $offer
   */
  public function canSubmitSignedPayload(array $offer): bool {
    return !empty($offer['intent_hash'])
      && $this->isHash((string) $offer['intent_hash'])
      && in_array((string) ($offer['state'] ?? ''), self::SIGNABLE_STATES, TRUE);
  }

  /**
   * @param array<string, mixed> $offer
   */
  public function canSubmitBondedCosignature(array $offer): bool {
    return !empty($offer['intent_hash'])
      && $this->isHash((string) $offer['intent_hash'])
      && !empty($offer['transaction_hash'])
      && $this->isHash((string) $offer['transaction_hash'])
      && !empty($offer['root_signed_payload'])
      && !empty($offer['leg2_signer_public_key'])
      && in_array((string) ($offer['state'] ?? ''), ['partial_announced', 'partial_cosigned'], TRUE);
  }

  /**
   * @param array<string, mixed> $offer
   */
  public function canAccept(array $offer): bool {
    return in_array((string) ($offer['state'] ?? ''), ['open', 'draft'], TRUE)
      && empty($offer['intent_hash'])
      && empty($offer['unsigned_payload']);
  }

  /**
   * @param array<string, mixed> $offer
   */
  public function canEdit(array $offer): bool {
    return $this->isLocallyMutable($offer);
  }

  /**
   * @param array<string, mixed> $offer
   */
  public function canDelete(array $offer): bool {
    return $this->isLocallyMutable($offer);
  }

  /**
   * @param array<string, mixed> $offer
   */
  public function canCancel(array $offer): bool {
    return in_array((string) ($offer['state'] ?? ''), self::CANCELLABLE_STATES, TRUE);
  }

  /**
   * @param array<string, mixed> $offer
   */
  public function canAnnounce(array $offer): bool {
    return ($offer['state'] ?? '') === 'signed'
      && !empty($offer['intent_hash'])
      && $this->isHash((string) $offer['intent_hash'])
      && !empty($offer['transaction_hash'])
      && $this->isHash((string) $offer['transaction_hash']);
  }

  /**
   * @param array<string, mixed> $offer
   */
  public function canSyncProjection(array $offer): bool {
    return !empty($offer['transaction_hash'])
      && $this->isHash((string) $offer['transaction_hash'])
      && in_array((string) ($offer['state'] ?? ''), self::SYNCABLE_STATES, TRUE);
  }

  /**
   * @param array<string, mixed> $offer
   */
  private function isLocallyMutable(array $offer): bool {
    return in_array((string) ($offer['state'] ?? ''), ['open', 'draft'], TRUE)
      && empty($offer['intent_hash'])
      && empty($offer['unsigned_payload'])
      && empty($offer['qr_payload'])
      && empty($offer['root_signed_payload'])
      && empty($offer['root_transaction_hash'])
      && empty($offer['transaction_hash']);
  }

  public function isProjectionSyncQueued(int $offer_id): bool {
    if (!$this->database->schema()->tableExists('queue')) {
      return FALSE;
    }

    $records = $this->database->select('queue', 'q')
      ->fields('q', ['data'])
      ->condition('name', 'symbol_atomic_swap_projection_sync')
      ->execute()
      ->fetchCol();

    foreach ($records as $record) {
      $data = @unserialize((string) $record, ['allowed_classes' => FALSE]);
      if (is_array($data) && (int) ($data['offer_id'] ?? 0) === $offer_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  public function isTerminalState(string $state): bool {
    return in_array($state, self::TERMINAL_STATES, TRUE);
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
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }
    if (($current['state'] ?? '') === 'finalized' && $state !== 'finalized') {
      throw new \InvalidArgumentException('Finalized atomic settlement cannot transition to a non-finalized state.');
    }
    if (in_array($current['state'] ?? '', ['failed', 'rolled_back'], TRUE) && $state === 'finalized') {
      throw new \InvalidArgumentException('Failed or rolled back atomic settlement cannot transition to finalized.');
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
      if (!is_int($projection['updatedAt']) && !(is_string($projection['updatedAt']) && preg_match('/^\d+$/', $projection['updatedAt']) === 1)) {
        throw new \InvalidArgumentException('Projection updatedAt must be a Unix timestamp.');
      }
      $fields['projection_updated_at'] = (int) $projection['updatedAt'];
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
      'state' => 'payload_generated',
      'intent_hash' => isset($engine_result['intentHash']) ? strtoupper((string) $engine_result['intentHash']) : NULL,
      'unsigned_payload' => isset($engine_result['unsignedPayload']) ? strtoupper((string) $engine_result['unsignedPayload']) : NULL,
      'qr_payload' => $qr_payload,
      'root_signed_payload' => NULL,
      'root_transaction_hash' => NULL,
      'transaction_hash' => $offer['transaction_hash'] ?? NULL,
    ];
  }

  private function isHash(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{64}$/', $value) === 1;
  }

}
