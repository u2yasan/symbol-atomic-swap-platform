<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TakeListingForm extends ConfirmFormBase {

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
    return 'symbol_p2p_ad_listing_take_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Create atomic settlement for this listing?');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $this->listing['id'] ?? 0]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, $listingId = NULL): array {
    $listing = $listingId !== NULL ? $this->listings->find((int) $listingId) : NULL;
    if (!$listing) {
      throw new NotFoundHttpException();
    }
    $this->listing = $listing;
    $form = parent::buildForm($form, $form_state);
    $form['listing_id'] = ['#type' => 'value', '#value' => (int) $listing['id']];
    $form['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('The listing is not locked. Seller and taker balances will be checked again before the settlement is created.'),
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $listing = $this->listings->find((int) $form_state->getValue('listing_id'));
    if (!$listing || (string) $listing['status'] !== AdListingRepository::ACTIVE) {
      $form_state->setErrorByName('listing_id', $this->t('Listing is no longer active.'));
      return;
    }
    if ($this->listings->isExpired($listing)) {
      $form_state->setErrorByName('listing_id', $this->t('Listing is expired.'));
      return;
    }
    if ((string) $listing['network'] === 'mainnet' && !$this->mainnetEnabled()) {
      $form_state->setErrorByName('listing_id', $this->t('Mainnet operations are disabled in Symbol Atomic Swap settings.'));
      return;
    }
    if ((int) $listing['seller_uid'] === (int) $this->currentUser->id()) {
      $form_state->setErrorByName('listing_id', $this->t('Seller cannot take their own listing.'));
      return;
    }
    $taker = $this->verifiedSymbolAccount();
    if (!$taker || (string) $taker['network'] !== (string) $listing['network']) {
      $form_state->setErrorByName('listing_id', $this->t('My Symbol Account must be verified on the same network as the listing.'));
      return;
    }
    if (strtoupper((string) $taker['public_key']) === strtoupper((string) $listing['seller_public_key'])) {
      $form_state->setErrorByName('listing_id', $this->t('Taker account must differ from seller account.'));
      return;
    }

    try {
      $this->assertTransferable((string) $listing['network'], (string) $listing['offered_mosaic_id']);
      $this->assertTransferable((string) $listing['network'], (string) $listing['requested_mosaic_id']);
      $balances = $this->engineClient->accountMosaicBalanceBatch([
        'seller' => [
          'network' => (string) $listing['network'],
          'address' => (string) $listing['seller_address'],
          'mosaic_id' => (string) $listing['offered_mosaic_id'],
        ],
        'taker' => [
          'network' => (string) $listing['network'],
          'address' => (string) $taker['address'],
          'mosaic_id' => (string) $listing['requested_mosaic_id'],
        ],
      ]);
      if (!($balances['seller']['ok'] ?? FALSE)) {
        $form_state->setErrorByName('listing_id', $this->t('Seller balance could not be verified: @message', [
          '@message' => $this->balanceCheckError($balances['seller']['error'] ?? NULL),
        ]));
      }
      if (!($balances['taker']['ok'] ?? FALSE)) {
        $form_state->setErrorByName('listing_id', $this->t('Taker balance could not be verified: @message', [
          '@message' => $this->balanceCheckError($balances['taker']['error'] ?? NULL),
        ]));
      }
      if ($form_state->hasAnyErrors()) {
        return;
      }

      $seller_balance = (string) ($balances['seller']['result']['amount'] ?? '0');
      $taker_balance = (string) ($balances['taker']['result']['amount'] ?? '0');
      if ($this->compareAtomic($seller_balance, (string) $listing['offered_amount']) < 0) {
        $form_state->setErrorByName('listing_id', $this->t('Seller balance is no longer sufficient.'));
      }
      if ($this->compareAtomic($taker_balance, (string) $listing['requested_amount']) < 0) {
        $form_state->setErrorByName('listing_id', $this->t('Taker balance is lower than the requested amount.'));
      }
    }
    catch (SymbolEngineException | \InvalidArgumentException $exception) {
      $form_state->setErrorByName('listing_id', $exception->getMessage());
    }

    $form_state->set('taker', $taker);
    $this->listing = $listing;
  }

  private function balanceCheckError(mixed $error): string {
    return $error instanceof \Throwable && $error->getMessage() !== ''
      ? $error->getMessage()
      : (string) $this->t('Unknown balance check error.');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $taker = (array) $form_state->get('taker');
    $offer_id = $this->listings->matchToAtomicSettlement($this->listing, [
      'uid' => (int) $this->currentUser->id(),
      'address' => (string) $taker['address'],
      'public_key' => (string) $taker['public_key'],
    ]);
    $this->messenger()->addStatus($this->t('Atomic settlement was created from the listing. Review and build the QR payload.'));
    $form_state->setRedirect('symbol_atomic_swap.offer_accept', ['offerId' => $offer_id]);
  }

  private function assertTransferable(string $network, string $mosaic_id): void {
    $metadata = $this->engineClient->mosaicMetadata($network, $mosaic_id);
    if (($metadata['transferable'] ?? TRUE) !== TRUE) {
      throw new \InvalidArgumentException((string) $this->t('Mosaic is not transferable.'));
    }
  }

  private function mainnetEnabled(): bool {
    return (bool) $this->config('symbol_atomic_swap.settings')->get('mainnet_enabled');
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
