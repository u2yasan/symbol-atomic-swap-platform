<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;

final class SwapOfferProjectionSynchronizer {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SymbolEngineClient $engineClient,
    private readonly SwapOfferNotificationRepository $notifications,
  ) {}

  /**
   * @return array{synced: bool, reason: string, projection?: array<string, mixed>}
   */
  public function sync(int $offer_id): array {
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new \InvalidArgumentException('Atomic settlement not found.');
    }

    if (empty($offer['transaction_hash'])) {
      return [
        'synced' => FALSE,
        'reason' => 'transaction_hash_missing',
      ];
    }

    if (($offer['state'] ?? '') === 'finalized') {
      return [
        'synced' => FALSE,
        'reason' => 'already_finalized',
      ];
    }

    try {
      $projection = $this->engineClient->reconcileProjection((string) $offer['network'], (string) $offer['transaction_hash']);
    }
    catch (SymbolEngineException $exception) {
      if ($exception->statusCode === 404) {
        return [
          'synced' => FALSE,
          'reason' => 'projection_not_found',
        ];
      }
      throw $exception;
    }

    $previous_state = (string) ($offer['state'] ?? '');
    $this->offers->applyProjection($offer_id, $projection);
    $next_state = (string) ($projection['state'] ?? '');
    if ($next_state !== '' && $next_state !== $previous_state) {
      $this->notifyStateChange($offer_id, $next_state);
    }

    return [
      'synced' => TRUE,
      'reason' => 'projection_synced',
      'projection' => $projection,
    ];
  }

  private function notifyStateChange(int $offer_id, string $state): void {
    switch ($state) {
      case 'finalized':
        $this->notifications->createOnce($offer_id, 'offer_finalized', 'status', 'Atomic settlement transaction was finalized on-chain.');
        break;

      case 'confirmed':
        $this->notifications->createOnce($offer_id, 'offer_confirmed', 'status', 'Atomic settlement transaction was confirmed but is not finalized yet.');
        break;

      case 'failed':
        $this->notifications->createOnce($offer_id, 'offer_failed', 'error', 'Atomic settlement transaction failed on-chain.');
        break;

      case 'rolled_back':
        $this->notifications->createOnce($offer_id, 'offer_rolled_back', 'error', 'Atomic settlement transaction was rolled back before finalization.');
        break;
    }
  }

}
