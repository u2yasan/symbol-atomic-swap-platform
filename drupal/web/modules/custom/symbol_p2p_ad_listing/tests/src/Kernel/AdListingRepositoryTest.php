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

    $this->repository->updateSellerBalanceCheck($id, '9000000');
    $this->repository->updateSellerBalanceCheck($cancelled_id, '9000000');

    $listing = $this->repository->find($id);
    $this->assertSame('9000000', $listing['seller_balance_checked_amount']);
    $this->assertNotEmpty($listing['seller_balance_checked_at']);

    $cancelled = $this->repository->find($cancelled_id);
    $this->assertSame('5000000', $cancelled['seller_balance_checked_amount']);
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
