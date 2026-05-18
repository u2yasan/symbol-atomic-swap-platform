<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Drupal\symbol_p2p_ad_listing\Service\AdListingBalanceCheckManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backward-compatible route-cache fallback for seller balance checks.
 */
final class CheckBalanceForm extends FormBase {

  public function __construct(
    private readonly AdListingRepository $listings,
    private readonly AdListingBalanceCheckManager $balanceCheckManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_p2p_ad_listing.repository'),
      $container->get('symbol_p2p_ad_listing.balance_check_manager'),
    );
  }

  public function getFormId(): string {
    return 'symbol_p2p_ad_listing_check_balance_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $listingId = NULL): array {
    $listing = $listingId !== NULL ? $this->listings->find((int) $listingId) : NULL;
    if (!$listing) {
      throw new NotFoundHttpException();
    }
    if (!$this->canCheckSellerBalance($listing)) {
      throw new AccessDeniedHttpException();
    }

    $result = $this->balanceCheckManager->checkListing((int) $listing['id']);
    if ($result['sufficient']) {
      $this->messenger()->addStatus($this->t('Seller balance was checked. Current balance is sufficient: @amount atomic units.', [
        '@amount' => $result['balance'],
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('Seller balance was checked. Current balance is insufficient: @amount atomic units.', [
        '@amount' => $result['balance'],
      ]));
    }

    $form_state->setRedirect('symbol_p2p_ad_listing.view', ['listingId' => (int) $listing['id']]);
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * @param array<string, mixed> $listing
   */
  private function canCheckSellerBalance(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) !== (int) $this->currentUser()->id()
      && $this->currentUser()->hasPermission('view symbol p2p ad listings');
  }

}
