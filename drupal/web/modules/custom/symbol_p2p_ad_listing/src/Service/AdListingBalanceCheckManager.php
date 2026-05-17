<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;

final class AdListingBalanceCheckManager {

  public function __construct(
    private readonly AdListingRepository $listings,
    private readonly SymbolEngineClient $engineClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @return array{checked: int, failed: int}
   */
  public function checkActiveListings(int $limit = 50): array {
    $checked = 0;
    $failed = 0;

    foreach ($this->listings->activeBalanceCheckCandidateIds($limit) as $listing_id) {
      try {
        $this->checkListing($listing_id);
        $checked++;
      }
      catch (\Throwable $exception) {
        $failed++;
        $this->loggerFactory->get('symbol_p2p_ad_listing')->warning('P2P listing balance check failed for listing @listing_id: @message', [
          '@listing_id' => (string) $listing_id,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    return ['checked' => $checked, 'failed' => $failed];
  }

  /**
   * @return array{balance: string, sufficient: bool}
   */
  public function checkListing(int $listing_id): array {
    $listing = $this->listings->find($listing_id);
    if (!$listing) {
      throw new \InvalidArgumentException('Listing not found.');
    }
    if ((string) $listing['status'] !== AdListingRepository::ACTIVE) {
      throw new \InvalidArgumentException('Only active listings can be balance checked.');
    }

    try {
      $balance = (string) ($this->engineClient->accountMosaicBalance(
        (string) $listing['network'],
        (string) $listing['seller_address'],
        (string) $listing['offered_mosaic_id'],
      )['amount'] ?? '0');
    }
    catch (SymbolEngineException | \InvalidArgumentException $exception) {
      throw new \RuntimeException('Seller balance could not be verified: ' . $exception->getMessage(), 0, $exception);
    }

    $this->listings->updateSellerBalanceCheck($listing_id, $balance);

    return [
      'balance' => $balance,
      'sufficient' => $this->compareAtomic($balance, (string) $listing['offered_amount']) >= 0,
    ];
  }

  private function compareAtomic(string $left, string $right): int {
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
  }

}
