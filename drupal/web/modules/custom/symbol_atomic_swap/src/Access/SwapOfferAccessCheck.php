<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Symfony\Component\Routing\Route;

final class SwapOfferAccessCheck implements AccessInterface {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function access(Route $route, AccountInterface $account, mixed $offerId = NULL): AccessResult {
    $operation = (string) $route->getRequirement('_symbol_atomic_swap_offer_access');
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if ($offerId !== NULL && !$offer) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    if ($account->hasPermission('administer symbol atomic swap offers') && $offer) {
      $allowed = match ($operation) {
        'edit' => $this->offers->canEdit($offer),
        'delete' => $this->offers->canDelete($offer),
        'cancel' => $this->offers->canCancel($offer),
        default => TRUE,
      };
      return AccessResult::allowedIf($allowed)
        ->cachePerPermissions()
        ->cachePerUser();
    }

    if ($operation === 'admin' || $operation === 'edit' || $operation === 'delete') {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    if (!$offer) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $owns_offer = (int) ($offer['uid'] ?? 0) === (int) $account->id();
    $allowed = match ($operation) {
      'view' => $account->hasPermission('view symbol atomic swap offers'),
      'operate' => $account->hasPermission('operate symbol atomic swap offers') && $owns_offer,
      'accept' => $account->hasPermission('operate symbol atomic swap offers') && $this->offers->canAccept($offer),
      'cancel' => $account->hasPermission('operate symbol atomic swap offers')
        && $this->offers->canCancel($offer)
        && $this->canCancelOffer($offer, $account),
      'sign' => $account->hasPermission('operate symbol atomic swap offers') && $this->offers->canSubmitSignedPayload($offer),
      'cosign' => $account->hasPermission('operate symbol atomic swap offers')
        && ($this->offers->canSubmitSignedPayload($offer) || $this->offers->canSubmitBondedCosignature($offer)),
      'bonded_partial' => $account->hasPermission('operate symbol atomic swap offers')
        && in_array((string) ($offer['state'] ?? ''), ['root_signed', 'signed'], TRUE)
        && !empty($offer['intent_hash']),
      'sync' => $account->hasPermission('operate symbol atomic swap offers') && $this->offers->canSyncProjection($offer),
      default => FALSE,
    };

    return AccessResult::allowedIf($allowed)
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * @param array<string, mixed> $offer
   */
  private function canCancelOffer(array $offer, AccountInterface $account): bool {
    if ((int) ($offer['uid'] ?? 0) === (int) $account->id()) {
      return TRUE;
    }

    $public_key = $this->verifiedSymbolPublicKey($account);
    return $public_key !== ''
      && (string) ($offer['network'] ?? '') === $this->verifiedSymbolNetwork($account)
      && hash_equals($public_key, strtoupper((string) ($offer['leg2_signer_public_key'] ?? '')));
  }

  private function verifiedSymbolPublicKey(AccountInterface $account): string {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    if (!$user || !(bool) ($user->get('field_symbol_address_verified')->value ?? FALSE)) {
      return '';
    }

    $public_key = strtoupper((string) ($user->get('field_symbol_public_key')->value ?? ''));
    return preg_match('/^[0-9A-F]{64}$/', $public_key) === 1 ? $public_key : '';
  }

  private function verifiedSymbolNetwork(AccountInterface $account): string {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    if (!$user || !(bool) ($user->get('field_symbol_address_verified')->value ?? FALSE)) {
      return '';
    }

    $network = (string) ($user->get('field_symbol_network')->value ?? '');
    return in_array($network, ['mainnet', 'testnet'], TRUE) ? $network : '';
  }

}
