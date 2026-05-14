<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Symfony\Component\Routing\Route;

final class SwapNotificationAccessCheck implements AccessInterface {

  public function __construct(
    private readonly SwapOfferNotificationRepository $notifications,
  ) {}

  public function access(Route $route, AccountInterface $account, mixed $notificationId = NULL): AccessResult {
    if ($account->hasPermission('administer symbol atomic swap offers')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if (!$account->hasPermission('operate symbol atomic swap offers')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $operation = (string) $route->getRequirement('_symbol_atomic_swap_notification_access');
    if ($operation === 'mark_all_read') {
      return AccessResult::allowed()->cachePerPermissions()->cachePerUser();
    }

    $owner_id = $notificationId !== NULL ? $this->notifications->ownerId((int) $notificationId) : NULL;
    return AccessResult::allowedIf($owner_id !== NULL && $owner_id === (int) $account->id())
      ->cachePerPermissions()
      ->cachePerUser();
  }

}
