<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;

final class SwapOfferExpirationManager {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly SwapOfferNotificationRepository $notifications,
  ) {}

  /**
   * @return int[]
   */
  public function expireStaleOffers(int $limit = 50): array {
    $now = $this->time->getRequestTime();
    $expired = [];

    foreach ($this->offers->expirationCandidateIds($now, $limit) as $offer_id) {
      if ($this->offers->markExpired($offer_id, $now)) {
        $expired[] = $offer_id;
        $this->notifications->createOnce(
          $offer_id,
          'offer_expired',
          'warning',
          'Atomic settlement expired before transaction announcement.',
        );
        $this->loggerFactory->get('symbol_atomic_swap')->notice('Atomic settlement @offer_id expired before announcement.', [
          '@offer_id' => $offer_id,
        ]);
      }
    }

    return $expired;
  }

}
