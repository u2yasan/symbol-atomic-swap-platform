<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_p2p_ad_listing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use PHPUnit\Framework\Attributes\Group;

#[Group('symbol_p2p_ad_listing')]
#[Group('symbol_atomic_swap')]
final class AdListingRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'symbol_atomic_swap', 'symbol_p2p_ad_listing'];

  private AdListingRepository $repository;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('symbol_atomic_swap', ['symbol_atomic_swap_offer']);
    $this->installSchema('symbol_p2p_ad_listing', ['symbol_p2p_ad_listing']);
    $this->repository = $this->container->get('symbol_p2p_ad_listing.repository');
  }

  public function testCreateAndSearchActiveListing(): void {
    $id = $this->repository->create($this->listingValues());

    $listing = $this->repository->find($id);
    $this->assertNotNull($listing);
    $this->assertSame(AdListingRepository::ACTIVE, $listing['status']);
    $this->assertSame('72C0212E67A08BCE', $listing['offered_mosaic_id']);

    $results = $this->repository->search(['status' => AdListingRepository::ACTIVE, 'network' => 'testnet']);
    $this->assertCount(1, $results);
    $this->assertSame($id, (int) $results[0]['id']);
  }

  public function testReservingListingAbuseQueriesIgnoreTerminalListings(): void {
    $first_id = $this->repository->create($this->listingValues([
      'label' => 'First',
      'offered_amount' => '999999999999999999999999999999',
    ]));
    $second_id = $this->repository->create($this->listingValues([
      'label' => 'Second',
      'offered_amount' => '1',
    ]));
    $cancelled_id = $this->repository->create($this->listingValues([
      'label' => 'Cancelled',
      'offered_amount' => '777',
    ]));
    $other_seller_id = $this->repository->create($this->listingValues([
      'label' => 'Other seller',
      'seller_uid' => 20,
      'seller_address' => 'TOTHER4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'offered_amount' => '500',
    ]));
    $this->repository->cancel($cancelled_id);

    $this->assertSame(2, $this->repository->countListingLimitedBySellerUid(10));
    $this->assertSame(1, $this->repository->countListingLimitedBySellerUid(10, $first_id));
    $this->assertSame('1000000000000000000000000000000', $this->repository->sumReservedOfferedAmount('testnet', 'TSELLER4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', '72c0212e67a08bce'));
    $this->assertSame('1', $this->repository->sumReservedOfferedAmount('testnet', 'TSELLER4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', '72C0212E67A08BCE', $first_id));
    $this->assertSame('500', $this->repository->sumReservedOfferedAmount('testnet', 'TOTHER4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', '72C0212E67A08BCE'));

    $this->assertTrue($this->repository->hasDuplicateReservingListing($this->listingValues([
      'offered_amount' => '999999999999999999999999999999',
    ])));
    $this->assertFalse($this->repository->hasDuplicateReservingListing($this->listingValues([
      'offered_amount' => '999999999999999999999999999999',
    ]), $first_id));
    $this->assertFalse($this->repository->hasDuplicateReservingListing($this->listingValues([
      'seller_uid' => 20,
      'offered_amount' => '999999999999999999999999999999',
    ])));

    $this->assertNotNull($this->repository->find($second_id));
    $this->assertNotNull($this->repository->find($other_seller_id));
  }

  public function testMatchCreatesAtomicSettlementAndMarksListingMatched(): void {
    $id = $this->repository->create($this->listingValues());
    $listing = $this->repository->find($id);
    $this->assertNotNull($listing);

    $offer_id = $this->repository->matchToAtomicSettlement($listing, [
      'uid' => 20,
      'address' => 'TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'public_key' => str_repeat('B', 64),
    ]);

    $matched = $this->repository->find($id);
    $this->assertSame(AdListingRepository::MATCHED, $matched['status']);
    $this->assertSame($offer_id, (int) $matched['matched_offer_id']);

    $offer = $this->container->get('symbol_atomic_swap.offer_repository')->find($offer_id);
    $this->assertSame('open', $offer['state']);
    $this->assertSame('P2P listing #' . $id . ': Test listing', $offer['label']);
    $this->assertSame('TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', $offer['leg1_recipient_address']);
    $this->assertSame(str_repeat('B', 64), $offer['leg2_signer_public_key']);
  }

  public function testSellerCannotMatchOwnListing(): void {
    $id = $this->repository->create($this->listingValues());
    $listing = $this->repository->find($id);
    $this->assertNotNull($listing);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Seller cannot take their own listing.');

    $this->repository->matchToAtomicSettlement($listing, [
      'uid' => 10,
      'address' => 'TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'public_key' => str_repeat('B', 64),
    ]);
  }

  public function testCancelOnlyChangesActiveListing(): void {
    $id = $this->repository->create($this->listingValues());

    $this->repository->cancel($id);
    $this->repository->cancel($id);

    $listing = $this->repository->find($id);
    $this->assertSame(AdListingRepository::CANCELLED, $listing['status']);
  }

  public function testUpdateEditableOnlyChangesActiveListing(): void {
    $id = $this->repository->create($this->listingValues());
    $insufficient_id = $this->repository->create($this->listingValues(['label' => 'Insufficient']));
    $cancelled_id = $this->repository->create($this->listingValues(['label' => 'Cancelled']));
    $this->repository->markInsufficientBalance($insufficient_id, '1');
    $this->repository->cancel($cancelled_id);

    $this->repository->updateEditable($id, [
      'label' => 'Updated listing',
      'offered_amount' => '3000000',
    ]);
    $this->repository->updateEditable($insufficient_id, [
      'label' => 'Restored listing',
    ]);
    $this->repository->updateEditable($cancelled_id, [
      'label' => 'Should not update',
    ]);

    $listing = $this->repository->find($id);
    $this->assertSame('Updated listing', $listing['label']);
    $this->assertSame('3000000', $listing['offered_amount']);

    $restored = $this->repository->find($insufficient_id);
    $this->assertSame('Restored listing', $restored['label']);
    $this->assertSame(AdListingRepository::ACTIVE, $restored['status']);

    $cancelled = $this->repository->find($cancelled_id);
    $this->assertSame('Cancelled', $cancelled['label']);
  }

  public function testDeleteActiveDeletesEditableListingsOnly(): void {
    $id = $this->repository->create($this->listingValues());
    $insufficient_id = $this->repository->create($this->listingValues(['label' => 'Insufficient']));
    $cancelled_id = $this->repository->create($this->listingValues(['label' => 'Cancelled']));
    $this->repository->markInsufficientBalance($insufficient_id, '1');
    $this->repository->cancel($cancelled_id);

    $this->repository->deleteActive($id);
    $this->repository->deleteActive($insufficient_id);
    $this->repository->deleteActive($cancelled_id);

    $this->assertNull($this->repository->find($id));
    $this->assertNull($this->repository->find($insufficient_id));
    $this->assertNotNull($this->repository->find($cancelled_id));
  }

  public function testBalanceCheckCandidatesReturnOnlyActiveListingsOldestFirst(): void {
    $old_id = $this->repository->create($this->listingValues([
      'label' => 'Old check',
      'seller_balance_checked_at' => 1700000000,
    ]));
    $new_id = $this->repository->create($this->listingValues([
      'label' => 'New check',
      'seller_balance_checked_at' => 1700000100,
    ]));
    $cancelled_id = $this->repository->create($this->listingValues([
      'label' => 'Cancelled',
      'seller_balance_checked_at' => 1699999999,
    ]));
    $this->repository->cancel($cancelled_id);

    $this->assertSame([$old_id, $new_id], $this->repository->activeBalanceCheckCandidateIds());
  }

  public function testUpdateSellerBalanceCheckUpdatesActiveListingOnly(): void {
    $id = $this->repository->create($this->listingValues());
    $cancelled_id = $this->repository->create($this->listingValues(['label' => 'Cancelled']));
    $this->repository->cancel($cancelled_id);
    $original_changed = (int) $this->repository->find($id)['changed'];

    $this->repository->updateSellerBalanceCheck($id, '9000000');
    $this->repository->updateSellerBalanceCheck($cancelled_id, '9000000');

    $listing = $this->repository->find($id);
    $this->assertSame('9000000', $listing['seller_balance_checked_amount']);
    $this->assertNotEmpty($listing['seller_balance_checked_at']);
    $this->assertSame($original_changed, (int) $listing['changed']);

    $cancelled = $this->repository->find($cancelled_id);
    $this->assertSame('5000000', $cancelled['seller_balance_checked_amount']);
  }

  public function testMarkInsufficientBalanceSuspendsActiveListingOnly(): void {
    $id = $this->repository->create($this->listingValues());
    $cancelled_id = $this->repository->create($this->listingValues(['label' => 'Cancelled']));
    $this->repository->cancel($cancelled_id);

    $this->repository->markInsufficientBalance($id, '999');
    $this->repository->markInsufficientBalance($cancelled_id, '1');

    $listing = $this->repository->find($id);
    $this->assertSame(AdListingRepository::INSUFFICIENT_BALANCE, $listing['status']);
    $this->assertSame('999', $listing['seller_balance_checked_amount']);
    $this->assertNotEmpty($listing['seller_balance_checked_at']);

    $cancelled = $this->repository->find($cancelled_id);
    $this->assertSame(AdListingRepository::CANCELLED, $cancelled['status']);
    $this->assertSame('5000000', $cancelled['seller_balance_checked_amount']);
  }

  public function testExpirationCandidatesAndMarkExpired(): void {
    $expired_id = $this->repository->create($this->listingValues([
      'label' => 'Expired',
      'expires_at' => 1700000000,
    ]));
    $this->repository->create($this->listingValues([
      'label' => 'Future',
      'expires_at' => 1700007200,
    ]));
    $this->repository->create($this->listingValues([
      'label' => 'Never',
      'expires_at' => NULL,
    ]));
    $insufficient_id = $this->repository->create($this->listingValues([
      'label' => 'Insufficient expired',
      'expires_at' => 1700000000,
    ]));
    $this->repository->markInsufficientBalance($insufficient_id, '1');

    $this->assertSame([$expired_id, $insufficient_id], $this->repository->expirationCandidateIds(1700003600));

    $this->repository->markExpired($expired_id);
    $this->repository->markExpired($insufficient_id);
    $listing = $this->repository->find($expired_id);
    $this->assertSame(AdListingRepository::EXPIRED, $listing['status']);
    $insufficient = $this->repository->find($insufficient_id);
    $this->assertSame(AdListingRepository::EXPIRED, $insufficient['status']);
  }

  public function testExpiredListingCannotCreateAtomicSettlement(): void {
    $id = $this->repository->create($this->listingValues([
      'expires_at' => 1,
    ]));
    $listing = $this->repository->find($id);
    $this->assertNotNull($listing);
    $this->assertTrue($this->repository->isExpired($listing));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Listing is expired.');

    $this->repository->matchToAtomicSettlement($listing, [
      'uid' => 20,
      'address' => 'TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'public_key' => str_repeat('B', 64),
    ]);
  }

  public function testFailedAtomicSettlementReleasesUnexpiredMatchedListing(): void {
    $now = \Drupal::time()->getRequestTime();
    $id = $this->repository->create($this->listingValues([
      'expires_at' => $now + 7200,
    ]));
    $offer_id = $this->repository->matchToAtomicSettlement($this->repository->find($id), [
      'uid' => 20,
      'address' => 'TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'public_key' => str_repeat('B', 64),
    ]);
    $this->container->get('symbol_atomic_swap.offer_repository')->update($offer_id, [
      'state' => 'failed',
    ]);

    $result = $this->repository->releaseFailedMatches($now + 3600);

    $this->assertSame([$id], $result['released']);
    $this->assertSame([], $result['expired']);
    $listing = $this->repository->find($id);
    $this->assertSame(AdListingRepository::ACTIVE, $listing['status']);
    $this->assertEmpty($listing['matched_offer_id']);
  }

  public function testMissingAtomicSettlementReleasesUnexpiredMatchedListing(): void {
    $now = \Drupal::time()->getRequestTime();
    $id = $this->repository->create($this->listingValues([
      'expires_at' => $now + 7200,
    ]));
    $offer_id = $this->repository->matchToAtomicSettlement($this->repository->find($id), [
      'uid' => 20,
      'address' => 'TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'public_key' => str_repeat('B', 64),
    ]);
    $this->container->get('symbol_atomic_swap.offer_repository')->delete($offer_id);

    $result = $this->repository->releaseFailedMatches($now + 3600);

    $this->assertSame([$id], $result['released']);
    $this->assertSame(AdListingRepository::ACTIVE, $this->repository->find($id)['status']);
  }

  public function testFailedAtomicSettlementExpiresListingPastListingDeadline(): void {
    $now = \Drupal::time()->getRequestTime();
    $id = $this->repository->create($this->listingValues([
      'expires_at' => $now + 1800,
    ]));
    $offer_id = $this->repository->matchToAtomicSettlement($this->repository->find($id), [
      'uid' => 20,
      'address' => 'TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'public_key' => str_repeat('B', 64),
    ]);
    $this->container->get('symbol_atomic_swap.offer_repository')->update($offer_id, [
      'state' => 'expired',
    ]);

    $result = $this->repository->releaseFailedMatches($now + 3600);

    $this->assertSame([], $result['released']);
    $this->assertSame([$id], $result['expired']);
    $listing = $this->repository->find($id);
    $this->assertSame(AdListingRepository::EXPIRED, $listing['status']);
    $this->assertSame($offer_id, (int) $listing['matched_offer_id']);
  }

  public function testFinalizedAtomicSettlementDoesNotReleaseMatchedListing(): void {
    $now = \Drupal::time()->getRequestTime();
    $id = $this->repository->create($this->listingValues([
      'expires_at' => $now + 7200,
    ]));
    $offer_id = $this->repository->matchToAtomicSettlement($this->repository->find($id), [
      'uid' => 20,
      'address' => 'TBOB3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'public_key' => str_repeat('B', 64),
    ]);
    $this->container->get('symbol_atomic_swap.offer_repository')->update($offer_id, [
      'state' => 'finalized',
    ]);

    $result = $this->repository->releaseFailedMatches($now + 3600);

    $this->assertSame([], $result['released']);
    $this->assertSame([], $result['expired']);
    $this->assertSame(AdListingRepository::MATCHED, $this->repository->find($id)['status']);
  }

  /**
   * @param array<string, mixed> $overrides
   *
   * @return array<string, mixed>
   */
  private function listingValues(array $overrides = []): array {
    return $overrides + [
      'label' => 'Test listing',
      'network' => 'testnet',
      'seller_uid' => 10,
      'seller_address' => 'TSELLER4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'seller_public_key' => str_repeat('A', 64),
      'offered_mosaic_id' => '72C0212E67A08BCE',
      'offered_amount' => '1000000',
      'requested_mosaic_id' => '1234567890ABCDEF',
      'requested_amount' => '2000000',
      'swap_window_minutes' => 120,
      'seller_balance_checked_amount' => '5000000',
      'seller_balance_checked_at' => 1700000000,
    ];
  }

}
