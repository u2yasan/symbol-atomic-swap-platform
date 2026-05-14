<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Symfony\Component\Routing\Route;

final class SwapOfferAccessCheck implements AccessInterface {

  public function __construct(
    private readonly SwapOfferRepository $offers,
  ) {}

  public function access(Route $route, AccountInterface $account, mixed $offerId = NULL): AccessResult {
    $operation = (string) $route->getRequirement('_symbol_atomic_swap_offer_access');
    if ($account->hasPermission('administer symbol atomic swap offers')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation === 'admin') {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $owns_offer = (int) ($offer['uid'] ?? 0) === (int) $account->id();
    $allowed = match ($operation) {
      'view' => $account->hasPermission('view symbol atomic swap offers') && $owns_offer,
      'operate' => $account->hasPermission('operate symbol atomic swap offers') && $owns_offer,
      default => FALSE,
    };

    return AccessResult::allowedIf($allowed)
      ->cachePerPermissions()
      ->cachePerUser();
  }

}
