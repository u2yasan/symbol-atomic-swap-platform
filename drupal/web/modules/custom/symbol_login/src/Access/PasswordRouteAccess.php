<?php

namespace Drupal\symbol_login\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Restricts password recovery and public registration routes.
 */
final class PasswordRouteAccess {

  public static function access(AccountInterface $account): AccessResult {
    if ((int) $account->id() === 1 || $account->hasPermission('use password login') || $account->hasPermission('administer users')) {
      return AccessResult::allowed()->cachePerPermissions()->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerPermissions()->cachePerUser();
  }

}
