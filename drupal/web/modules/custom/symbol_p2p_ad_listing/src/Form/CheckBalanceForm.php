<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Drupal\symbol_p2p_ad_listing\Service\AdListingBalanceCheckManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CheckBalanceForm extends ConfirmFormBase {

  /**
   * @var array<string, mixed>
   */
  private array $listing = [];

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

  public function getQuestion(): string {
    return (string) $this->t('Check seller balance now?');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $this->listing['id'] ?? 0]);
  }

  public function getConfirmText(): string {
    return (string) $this->t('Check balance');
  }

  public function buildForm(array $form, FormStateInterface $form_state, $listingId = NULL): array {
    $listing = $listingId !== NULL ? $this->listings->find((int) $listingId) : NULL;
    if (!$listing) {
      throw new NotFoundHttpException();
    }
    $this->listing = $listing;
    $form['listing_id'] = ['#type' => 'value', '#value' => (int) $listing['id']];
    $form['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('This checks the seller address balance for the offered mosaic. It does not lock assets.'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $listing = $this->listings->find((int) $form_state->getValue('listing_id'));
    if (!$listing || (string) $listing['status'] !== AdListingRepository::ACTIVE) {
      $form_state->setErrorByName('listing_id', $this->t('Only active listings can be balance checked.'));
    }
    elseif ($this->listings->isExpired($listing)) {
      $form_state->setErrorByName('listing_id', $this->t('Expired listings cannot be balance checked.'));
    }
    $this->listing = $listing ?: [];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $listing_id = (int) $form_state->getValue('listing_id');
    $result = $this->balanceCheckManager->checkListing($listing_id);
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
    $form_state->setRedirect('symbol_p2p_ad_listing.view', ['listingId' => $listing_id]);
  }

}
