<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_atomic_swap\Kernel;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\KernelTests\KernelTestBase;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SwapOfferProjectionSynchronizer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests swap offer persistence behavior.
 */
#[Group('symbol_atomic_swap')]
final class SwapOfferRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'symbol_engine', 'symbol_atomic_swap'];

  private SwapOfferRepository $repository;

  protected function setUp(): void {
    parent::setUp();
    $schema = $this->container->get('database')->schema();
    if (!$schema->tableExists('queue')) {
      $queue = new DatabaseQueue('symbol_atomic_swap_projection_sync', $this->container->get('database'));
      $schema->createTable('queue', $queue->schemaDefinition());
    }
    $this->installSchema('symbol_atomic_swap', ['symbol_atomic_swap_offer', 'symbol_atomic_swap_notification']);
    $this->repository = $this->container->get('symbol_atomic_swap.offer_repository');
  }

  /**
   * Engine projection data updates local offer state without storing payloads.
   */
  public function testApplyProjectionUpdatesOfferState(): void {
    $id = $this->repository->insert($this->offerValues([
      'state' => 'announced',
      'transaction_hash' => str_repeat('D', 64),
    ]));

    $this->repository->applyProjection($id, [
      'transactionHash' => str_repeat('D', 64),
      'network' => 'testnet',
      'state' => 'confirmed',
      'lastEventKey' => 'testnet:' . str_repeat('D', 64) . ':TransactionConfirmed:10:0:',
      'blockHeight' => 10,
      'updatedAt' => 1778716800,
    ]);

    $offer = $this->repository->find($id);
    $this->assertSame('confirmed', $offer['state']);
    $this->assertSame('confirmed', $offer['projection_state']);
    $this->assertSame('10', (string) $offer['block_height']);
    $this->assertSame('1778716800', (string) $offer['projection_updated_at']);
    $this->assertSame(str_repeat('D', 64), $offer['transaction_hash']);
    $this->assertEmpty($offer['finalized_height']);
  }

  /**
   * Failed projection status codes are extracted from Symbol Engine event keys.
   */
  public function testFailureCodeFromProjectionExtractsStatusCode(): void {
    $this->assertSame('Failure_Core_Past_Deadline', SwapOfferProjectionSynchronizer::failureCodeFromProjection([
      'lastEventKey' => 'testnet:' . str_repeat('D', 64) . ':TransactionFailed:0:0:Failure_Core_Past_Deadline',
    ]));
    $this->assertSame('', SwapOfferProjectionSynchronizer::failureCodeFromProjection([
      'lastEventKey' => 'testnet:' . str_repeat('D', 64) . ':TransactionConfirmed:10:0:',
    ]));
  }

  /**
   * Unknown projection states are rejected.
   */
  public function testApplyProjectionRejectsUnknownState(): void {
    $id = $this->repository->insert($this->offerValues());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid projection state.');

    $this->repository->applyProjection($id, [
      'state' => 'completed',
    ]);
  }

  /**
   * Finalized local offers must not be downgraded by stale projection reads.
   */
  public function testApplyProjectionRejectsFinalizedDowngrade(): void {
    $id = $this->repository->insert($this->offerValues([
      'state' => 'finalized',
      'projection_state' => 'finalized',
      'transaction_hash' => str_repeat('D', 64),
      'block_height' => 10,
      'finalized_height' => 10,
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Finalized atomic settlement cannot transition to a non-finalized state.');

    $this->repository->applyProjection($id, [
      'state' => 'confirmed',
      'blockHeight' => 10,
      'updatedAt' => 1778716800,
    ]);
  }

  /**
   * Failed and rolled back offers cannot be promoted to finalized.
   */
  public function testApplyProjectionRejectsFailedToFinalized(): void {
    $id = $this->repository->insert($this->offerValues([
      'state' => 'failed',
      'projection_state' => 'failed',
      'transaction_hash' => str_repeat('D', 64),
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Failed or rolled back atomic settlement cannot transition to finalized.');

    $this->repository->applyProjection($id, [
      'state' => 'finalized',
      'blockHeight' => 10,
      'finalizedHeight' => 10,
      'updatedAt' => 1778716800,
    ]);
  }

  /**
   * Cron queues only non-terminal offers that have transaction hashes.
   */
  public function testCronQueuesProjectionSyncCandidates(): void {
    $queued_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-queue-1',
      'state' => 'confirmed',
      'transaction_hash' => str_repeat('D', 64),
      'changed' => 1700000000,
    ]));
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-queue-finalized',
      'state' => 'finalized',
      'transaction_hash' => str_repeat('E', 64),
      'changed' => 1700000001,
    ]));
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-queue-missing-hash',
      'state' => 'confirmed',
      'transaction_hash' => NULL,
      'changed' => 1700000002,
    ]));

    $this->assertSame([$queued_id], $this->repository->projectionSyncCandidateIds());

    \symbol_atomic_swap_cron();

    $queue = \Drupal::queue('symbol_atomic_swap_projection_sync');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame(['offer_id' => $queued_id], $item->data);
  }

  /**
   * Expiration candidates are limited to pre-announcement stale offers.
   */
  public function testExpirationCandidateIds(): void {
    $expired_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-expired-candidate',
      'state' => 'payload_generated',
      'created' => 1700000000,
      'deadline_hours' => 1,
    ]));
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-not-expired',
      'state' => 'payload_generated',
      'created' => 1700003500,
      'deadline_hours' => 1,
    ]));
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-open-no-transaction-deadline-expiry',
      'state' => 'open',
      'created' => 1700000000,
      'deadline_hours' => 1,
    ]));
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-announced-not-local-expiry',
      'state' => 'announced',
      'transaction_hash' => str_repeat('D', 64),
      'created' => 1700000000,
      'deadline_hours' => 1,
    ]));

    $this->assertSame([$expired_id], $this->repository->expirationCandidateIds(1700003600));
  }

  /**
   * Expiring an offer is a terminal local transition for pre-announcement work.
   */
  public function testMarkExpired(): void {
    $id = $this->repository->insert($this->offerValues([
      'state' => 'signed',
      'transaction_hash' => str_repeat('D', 64),
    ]));

    $this->assertTrue($this->repository->markExpired($id, 1700003600));

    $offer = $this->repository->find($id);
    $this->assertSame('expired', $offer['state']);
    $this->assertSame('1700003600', (string) $offer['expired_at']);
    $this->assertSame(str_repeat('D', 64), $offer['transaction_hash']);
  }

  /**
   * Announced and finalized offers must not be locally expired by Drupal.
   */
  public function testMarkExpiredSkipsChainTrackedStates(): void {
    $id = $this->repository->insert($this->offerValues([
      'state' => 'announced',
      'transaction_hash' => str_repeat('D', 64),
    ]));

    $this->assertFalse($this->repository->markExpired($id, 1700003600));
    $this->assertSame('announced', $this->repository->find($id)['state']);
  }

  /**
   * Offer operation guards must reject unsafe state transitions.
   */
  public function testOperationGuardsRejectUnsafeStates(): void {
    $draft_id = $this->repository->insert($this->offerValues([
      'state' => 'draft',
      'transaction_hash' => NULL,
    ]));

    $this->assertFalse($this->repository->canSubmitSignedPayload($this->repository->find($draft_id)));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Signed payload can only be submitted');
    $this->repository->markSigned($draft_id, str_repeat('D', 64));
  }

  /**
   * Announce and projection sync guards are state-aware.
   */
  public function testOperationGuardsAllowOnlyChainTrackedStates(): void {
    $signed_id = $this->repository->insert($this->offerValues([
      'state' => 'signed',
      'transaction_hash' => str_repeat('D', 64),
    ]));
    $finalized_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-finalized-guard',
      'state' => 'finalized',
      'transaction_hash' => str_repeat('E', 64),
    ]));

    $this->assertTrue($this->repository->canSubmitSignedPayload($this->repository->find($signed_id)));
    $this->assertTrue($this->repository->canAnnounce($this->repository->find($signed_id)));
    $this->assertTrue($this->repository->canSyncProjection($this->repository->find($signed_id)));
    $this->assertFalse($this->repository->canSubmitSignedPayload($this->repository->find($finalized_id)));
    $this->assertFalse($this->repository->canAnnounce($this->repository->find($finalized_id)));
    $this->assertFalse($this->repository->canSyncProjection($this->repository->find($finalized_id)));
  }

  /**
   * Local cancellation is allowed only before announcement.
   */
  public function testCancelMarksOnlyUnannouncedOffersCancelled(): void {
    $signed_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-cancel-signed',
      'state' => 'signed',
      'transaction_hash' => str_repeat('D', 64),
    ]));
    $announced_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-cancel-announced',
      'state' => 'announced',
      'transaction_hash' => str_repeat('E', 64),
    ]));

    $this->assertTrue($this->repository->canCancel($this->repository->find($signed_id)));
    $this->assertFalse($this->repository->canCancel($this->repository->find($announced_id)));

    $this->repository->cancel($signed_id);
    $this->assertSame('cancelled', $this->repository->find($signed_id)['state']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only unannounced atomic settlements can be cancelled.');
    $this->repository->cancel($announced_id);
  }

  /**
   * Editing and deletion are limited to local drafts before chain artifacts exist.
   */
  public function testMutableGuardsRejectAnnouncedAndBuiltOffers(): void {
    $open_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-mutable-open',
      'state' => 'open',
      'intent_hash' => NULL,
      'unsigned_payload' => NULL,
      'qr_payload' => NULL,
      'transaction_hash' => NULL,
    ]));
    $built_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-mutable-built',
      'state' => 'payload_generated',
      'intent_hash' => str_repeat('C', 64),
      'unsigned_payload' => 'ABCD',
      'qr_payload' => '{"type":"symbol-aggregate-complete"}',
    ]));
    $announced_id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-mutable-announced',
      'state' => 'announced',
      'transaction_hash' => str_repeat('D', 64),
    ]));

    $this->assertTrue($this->repository->canEdit($this->repository->find($open_id)));
    $this->assertTrue($this->repository->canDelete($this->repository->find($open_id)));
    $this->assertFalse($this->repository->canEdit($this->repository->find($built_id)));
    $this->assertFalse($this->repository->canDelete($this->repository->find($built_id)));
    $this->assertFalse($this->repository->canEdit($this->repository->find($announced_id)));
    $this->assertFalse($this->repository->canDelete($this->repository->find($announced_id)));

    $this->repository->updateEditable($open_id, ['label' => 'Updated open offer']);
    $this->assertSame('Updated open offer', $this->repository->find($open_id)['label']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only open draft atomic settlements can be edited.');
    $this->repository->updateEditable($announced_id, ['label' => 'Unsafe update']);
  }

  /**
   * Acceptance reservation is a compare-and-set and cannot be claimed twice.
   */
  public function testAcceptanceReservationIsAtomicAndCompletesForReservedTaker(): void {
    $id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-atomic-acceptance',
      'state' => 'open',
      'intent_hash' => NULL,
      'unsigned_payload' => NULL,
      'qr_payload' => NULL,
      'transaction_hash' => NULL,
    ]));
    $first_taker = str_repeat('A', 64);
    $second_taker = str_repeat('B', 64);

    $this->assertTrue($this->repository->reserveAcceptance($id, [
      'deadline_hours' => 6,
      'leg1_recipient_address' => 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'leg2_signer_public_key' => $first_taker,
    ]));
    $this->assertFalse($this->repository->reserveAcceptance($id, [
      'deadline_hours' => 6,
      'leg1_recipient_address' => 'TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI',
      'leg2_signer_public_key' => $second_taker,
    ]));
    $this->assertSame('accepting', $this->repository->find($id)['state']);

    $engine_fields = [
      'intent_hash' => str_repeat('C', 64),
      'unsigned_payload' => 'ABCD',
      'qr_payload' => '{"type":"symbol-aggregate-complete"}',
    ];
    $this->assertFalse($this->repository->completeAcceptance($id, $second_taker, $engine_fields));
    $this->assertTrue($this->repository->completeAcceptance($id, $first_taker, $engine_fields));
    $this->assertSame('payload_generated', $this->repository->find($id)['state']);
  }

  /**
   * A failed synchronous build releases only the matching reservation.
   */
  public function testAcceptanceReservationCanBeReleasedAfterEngineFailure(): void {
    $original = $this->offerValues([
      'uuid' => 'offer-release-acceptance',
      'state' => 'open',
      'intent_hash' => NULL,
      'unsigned_payload' => NULL,
      'qr_payload' => NULL,
      'transaction_hash' => NULL,
    ]);
    $id = $this->repository->insert($original);
    $taker = str_repeat('A', 64);

    $this->assertTrue($this->repository->reserveAcceptance($id, [
      'deadline_hours' => 6,
      'leg1_recipient_address' => 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'leg2_signer_public_key' => $taker,
    ]));
    $this->assertFalse($this->repository->releaseAcceptance($id, str_repeat('B', 64), $original));
    $this->assertTrue($this->repository->releaseAcceptance($id, $taker, $original));
    $this->assertSame('open', $this->repository->find($id)['state']);
  }

  /**
   * Cron expires stale offers before queueing projection sync candidates.
   */
  public function testCronExpiresStaleOffers(): void {
    $id = $this->repository->insert($this->offerValues([
      'uuid' => 'offer-cron-expired',
      'state' => 'payload_generated',
      'created' => 100,
      'deadline_hours' => 1,
    ]));

    \symbol_atomic_swap_cron();

    $offer = $this->repository->find($id);
    $this->assertSame('expired', $offer['state']);
    $this->assertNotEmpty($offer['expired_at']);

    $notifications = \Drupal::service('symbol_atomic_swap.offer_notification_repository')->findByOffer($id);
    $this->assertCount(1, $notifications);
    $this->assertSame('offer_expired', $notifications[0]['type']);
    $this->assertSame('warning', $notifications[0]['severity']);
  }

  /**
   * Offer notifications are unique by offer and type.
   */
  public function testNotificationCreateOnce(): void {
    $id = $this->repository->insert($this->offerValues());
    $notifications = \Drupal::service('symbol_atomic_swap.offer_notification_repository');

    $notifications->createOnce($id, 'offer_confirmed', 'status', 'First message.');
    $notifications->createOnce($id, 'offer_confirmed', 'status', 'Updated message.');

    $records = $notifications->findByOffer($id);
    $this->assertCount(1, $records);
    $this->assertSame('offer_confirmed', $records[0]['type']);
    $this->assertSame('Updated message.', $records[0]['message']);
  }

  /**
   * Network/correlation ID pairs are unique at repository storage level.
   */
  public function testNetworkCorrelationIdIsUnique(): void {
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-unique-correlation-1',
      'network' => 'testnet',
      'correlation_id' => 'swap-unique-correlation',
    ]));

    $this->assertTrue($this->repository->existsByNetworkCorrelationId('testnet', 'swap-unique-correlation'));
    $this->assertFalse($this->repository->existsByNetworkCorrelationId('mainnet', 'swap-unique-correlation'));

    $this->expectException(IntegrityConstraintViolationException::class);
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-unique-correlation-2',
      'network' => 'testnet',
      'correlation_id' => 'swap-unique-correlation',
    ]));
  }

  /**
   * Generated correlation IDs are sequential within a network.
   */
  public function testNextCorrelationId(): void {
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-next-correlation-1',
      'network' => 'testnet',
      'correlation_id' => 'swap-testnet-000001',
    ]));
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-next-correlation-mainnet',
      'network' => 'mainnet',
      'correlation_id' => 'swap-mainnet-000001',
    ]));
    $this->repository->insert($this->offerValues([
      'uuid' => 'offer-next-correlation-custom',
      'network' => 'testnet',
      'correlation_id' => 'custom-correlation',
    ]));

    $this->assertSame('swap-testnet-000002', $this->repository->nextCorrelationId('testnet'));
    $this->assertSame('swap-mainnet-000002', $this->repository->nextCorrelationId('mainnet'));
  }


  /**
   * @param array<string, mixed> $overrides
   *
   * @return array<string, mixed>
   */
  private function offerValues(array $overrides = []): array {
    return $overrides + [
      'uuid' => 'offer-kernel-' . bin2hex(random_bytes(4)),
      'label' => 'Kernel offer',
      'state' => 'payload_generated',
      'network' => 'testnet',
      'correlation_id' => 'swap-test-kernel-' . bin2hex(random_bytes(4)),
      'deadline_hours' => 2,
      'max_fee' => NULL,
      'leg1_signer_public_key' => str_repeat('A', 64),
      'leg1_recipient_address' => 'TAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
      'leg1_mosaic_id' => '72C0212E67A08BCE',
      'leg1_amount' => '100',
      'leg2_signer_public_key' => str_repeat('B', 64),
      'leg2_recipient_address' => 'TBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
      'leg2_mosaic_id' => '72C0212E67A08BCE',
      'leg2_amount' => '200',
      'intent_hash' => str_repeat('C', 64),
      'unsigned_payload' => 'ABCD',
      'qr_payload' => '{"type":"symbol-aggregate-complete"}',
      'transaction_hash' => NULL,
      'projection_state' => NULL,
      'block_height' => NULL,
      'finalized_height' => NULL,
      'projection_updated_at' => NULL,
      'expired_at' => NULL,
      'uid' => 1,
      'created' => 1700000000,
      'changed' => 1700000000,
    ];
  }

}
