<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CheckMyBalanceForm extends FormBase {

  /**
   * @var array<string, mixed>
   */
  private array $listing = [];

  public function __construct(
    private readonly AdListingRepository $listings,
    private readonly SymbolEngineClient $engineClient,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_p2p_ad_listing.repository'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'symbol_p2p_ad_listing_check_my_balance_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $listingId = NULL): array {
    $listing = $listingId !== NULL ? $this->listings->find((int) $listingId) : NULL;
    if (!$listing) {
      throw new NotFoundHttpException();
    }
    if (!$this->canCheckMyBalance($listing)) {
      throw new AccessDeniedHttpException();
    }
    $this->listing = $listing;

    $form['listing_id'] = [
      '#type' => 'value',
      '#value' => (int) $listing['id'],
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Check my balance'),
        '#button_type' => 'secondary',
      ],
    ];

    return $form;
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $this->listing['id'] ?? 0]);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $listing = $this->listings->find((int) $form_state->getValue('listing_id'));
    if (!$listing || !$this->canCheckMyBalance($listing)) {
      $form_state->setErrorByName('listing_id', $this->t('This listing cannot be balance checked.'));
      return;
    }
    $account = $this->verifiedSymbolAccount();
    if (!$account || (string) $account['network'] !== (string) $listing['network']) {
      $form_state->setErrorByName('listing_id', $this->t('My Symbol Account must be verified on the same network as the listing.'));
      return;
    }

    $this->listing = $listing;
    $form_state->set('symbol_p2p_ad_listing_account', $account);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $listing_id = (int) $form_state->getValue('listing_id');
    $listing = $this->listings->find($listing_id);
    $account = (array) $form_state->get('symbol_p2p_ad_listing_account');
    if (!$listing || $account === []) {
      $this->messenger()->addError($this->t('My balance could not be checked.'));
      $form_state->setRedirect('symbol_p2p_ad_listing.view', ['listingId' => $listing_id]);
      return;
    }

    try {
      $balance = (string) ($this->engineClient->accountMosaicBalance(
        (string) $listing['network'],
        (string) $account['address'],
        (string) $listing['requested_mosaic_id'],
      )['amount'] ?? '0');
    }
    catch (SymbolEngineException | \InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('My balance could not be verified: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRedirect('symbol_p2p_ad_listing.view', ['listingId' => $listing_id]);
      return;
    }

    if ($this->compareAtomic($balance, (string) $listing['requested_amount']) >= 0) {
      $this->messenger()->addStatus($this->t('My balance was checked. Current balance is sufficient: @amount atomic units.', [
        '@amount' => $balance,
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('My balance was checked. Current balance is insufficient: @amount atomic units.', [
        '@amount' => $balance,
      ]));
    }

    $form_state->setRedirect('symbol_p2p_ad_listing.view', ['listingId' => $listing_id]);
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canCheckMyBalance(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) !== (int) $this->currentUser->id()
      && $this->currentUser->isAuthenticated()
      && $this->currentUser->hasPermission('view symbol p2p ad listings');
  }

  /**
   * @return array{network: string, address: string, public_key: string}|null
   */
  private function verifiedSymbolAccount(): ?array {
    $account = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$account || !(bool) ($account->get('field_symbol_address_verified')->value ?? FALSE)) {
      return NULL;
    }
    $network = (string) ($account->get('field_symbol_network')->value ?? '');
    $address = strtoupper((string) ($account->get('field_symbol_address')->value ?? ''));
    $public_key = strtoupper((string) ($account->get('field_symbol_public_key')->value ?? ''));
    if (!in_array($network, ['mainnet', 'testnet'], TRUE) || !preg_match('/^[NT][A-Z2-7]{38}$/', $address) || !preg_match('/^[0-9A-F]{64}$/', $public_key)) {
      return NULL;
    }
    return ['network' => $network, 'address' => $address, 'public_key' => $public_key];
  }

  private function compareAtomic(string $left, string $right): int {
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
  }

}
