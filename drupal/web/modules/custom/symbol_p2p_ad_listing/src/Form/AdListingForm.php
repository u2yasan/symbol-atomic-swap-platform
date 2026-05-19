<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DrupalDateTime;
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

final class AdListingForm extends FormBase {

  private const CURRENCY_MOSAIC_IDS = [
    'mainnet' => '6BED913FA20223F8',
    'testnet' => '72C0212E67A08BCE',
  ];
  public const DEFAULT_MAX_RESERVING_LISTINGS_PER_SELLER = 5;

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
    return 'symbol_p2p_ad_listing_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $listingId = NULL): array {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'symbol_atomic_swap/offer_form';
    $form['#attributes']['data-symbol-maker-address-form'] = '1';
    $listing = $listingId !== NULL ? $this->listings->find((int) $listingId) : NULL;
    if ($listingId !== NULL && !$listing) {
      throw new NotFoundHttpException();
    }
    if ($listing && !$this->canEditListing($listing)) {
      throw new AccessDeniedHttpException();
    }
    $account = $this->verifiedSymbolAccount();
    $network = (string) ($listing['network'] ?? ($account['network'] ?? 'testnet'));
    $default_mosaic_id = $this->defaultCurrencyMosaicId($network);
    if (!$listing && !$account) {
      $form['account_required'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#type' => 'item',
          '#markup' => $this->t('Create P2P Listing requires a verified Symbol address in My Symbol Account.'),
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Open My Symbol Account'),
          '#url' => Url::fromRoute('symbol_atomic_swap.account_verification'),
          '#attributes' => ['class' => ['button']],
        ],
      ];
    }

    $form['positioning'] = [
      '#type' => 'item',
      '#markup' => $this->t('Listings are advertisements only. Assets are not locked until the atomic settlement is built and signed.'),
    ];
    $form['listing_id'] = [
      '#type' => 'value',
      '#value' => $listing ? (int) $listing['id'] : NULL,
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Listing label'),
      '#maxlength' => 128,
      '#required' => TRUE,
      '#default_value' => (string) ($listing['label'] ?? ''),
    ];
    $form['network'] = [
      '#type' => 'hidden',
      '#value' => $network,
      '#attributes' => [
        'data-symbol-maker-network' => '1',
      ],
    ];
    $form['seller'] = [
      '#type' => 'item',
      '#title' => $this->t('Seller address'),
      '#markup' => (string) ($listing['seller_address'] ?? ($account['address'] ?? $this->t('Not verified'))),
    ];
    $form['seller_address'] = [
      '#type' => 'hidden',
      '#value' => (string) ($listing['seller_address'] ?? ($account['address'] ?? '')),
      '#attributes' => [
        'data-symbol-maker-address' => '1',
      ],
    ];
    $form['seller_address_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'data-symbol-maker-address-status' => '1',
        'aria-live' => 'polite',
      ],
    ];
    $form['offered'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Seller offers'),
    ];
    $form['offered']['mosaic_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mosaic ID'),
      '#maxlength' => 16,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => (string) ($listing['offered_mosaic_id'] ?? $default_mosaic_id),
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{16}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-default-mosaic' => '1',
        'data-symbol-mosaic-id' => '1',
      ],
    ];
    $form['offered']['mosaic_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'data-symbol-mosaic-status' => '1',
        'aria-live' => 'polite',
      ],
    ];
    $form['offered']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#maxlength' => 48,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => $listing ? $this->displayAmount((string) $listing['offered_amount'], $network, (string) $listing['offered_mosaic_id']) : '',
      '#attributes' => [
        'pattern' => '[0-9]+(\\.[0-9]+)?',
        'autocomplete' => 'off',
        'data-symbol-mosaic-amount' => '1',
      ],
    ];
    $form['requested'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Seller wants'),
    ];
    $form['requested']['mosaic_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mosaic ID'),
      '#maxlength' => 16,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => (string) ($listing['requested_mosaic_id'] ?? $default_mosaic_id),
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{16}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-default-mosaic' => '1',
        'data-symbol-mosaic-id' => '1',
      ],
    ];
    $form['requested']['mosaic_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'data-symbol-mosaic-status' => '1',
        'aria-live' => 'polite',
      ],
    ];
    $form['requested']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#maxlength' => 48,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => $listing ? $this->displayAmount((string) $listing['requested_amount'], $network, (string) $listing['requested_mosaic_id']) : '',
      '#attributes' => [
        'pattern' => '[0-9]+(\\.[0-9]+)?',
        'autocomplete' => 'off',
        'data-symbol-mosaic-amount' => '1',
      ],
    ];
    $form['swap_window_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Settlement window minutes'),
      '#default_value' => (int) ($listing['swap_window_minutes'] ?? 120),
      '#min' => 15,
      '#max' => 2880,
      '#step' => 15,
      '#required' => TRUE,
      '#description' => $this->t('The number of minutes allowed to complete the atomic settlement after a buyer takes this listing. If signing and announcement are not completed before this window expires, the trade will not settle.'),
    ];
    $form['expiration'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Listing expiration'),
    ];
    $form['expiration']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Expiration'),
      '#default_value' => $listing && empty($listing['expires_at']) ? 'never' : 'datetime',
      '#required' => TRUE,
      '#options' => [
        'datetime' => $this->t('Expire at a specified date and time'),
        'never' => $this->t('No expiration'),
      ],
    ];
    $form['expiration']['expires_at'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="expiration[mode]"]' => ['value' => 'datetime'],
        ],
      ],
    ];
    $form['expiration']['expires_at']['value'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Listing end date and time'),
      '#default_value' => $this->defaultExpirationDateTime($listing),
      '#date_date_element' => 'date',
      '#date_time_element' => 'time',
      '#date_time_format' => 'H:i',
      '#date_increment' => 60,
      '#states' => [
        'required' => [
          ':input[name="expiration[mode]"]' => ['value' => 'datetime'],
        ],
      ],
      '#description' => $this->t('Choose an exact expiration date and time. Use no expiration only for actively maintained listings.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $listing ? $this->t('Save listing') : $this->t('Create listing'),
      '#button_type' => 'primary',
      '#disabled' => !$listing && !$account,
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $listing_id = $form_state->getValue('listing_id');
    $listing = $listing_id ? $this->listings->find((int) $listing_id) : NULL;
    if ($listing_id && (!$listing || !$this->canEditListing($listing))) {
      $form_state->setErrorByName('listing_id', $this->t('Only the active listing owner can edit this listing.'));
      return;
    }
    $account = $this->verifiedSymbolAccount();
    if (!$listing && !$account) {
      $form_state->setErrorByName('seller', $this->t('Register and verify My Symbol Account before creating a listing.'));
      return;
    }

    $network = (string) ($listing['network'] ?? $account['network']);
    if ($network === 'mainnet' && !$this->mainnetEnabled()) {
      $form_state->setErrorByName('network', $this->t('Mainnet operations are disabled in Symbol Atomic Swap settings.'));
      return;
    }
    $seller_address = (string) ($listing['seller_address'] ?? $account['address']);
    $seller_uid = (int) ($listing['seller_uid'] ?? $this->currentUser->id());
    $offered = (array) $form_state->getValue('offered', []);
    $requested = (array) $form_state->getValue('requested', []);
    $expiration = (array) $form_state->getValue('expiration', []);
    $label = trim((string) $form_state->getValue('label'));
    $offered_mosaic_id = strtoupper(trim((string) ($offered['mosaic_id'] ?? '')));
    $requested_mosaic_id = strtoupper(trim((string) ($requested['mosaic_id'] ?? '')));

    if ($label !== '' && $this->listings->hasListingLabel($label, $listing ? (int) $listing['id'] : NULL)) {
      $form_state->setErrorByName('label', $this->t('Listing label is already used by another listing. Choose a unique label.'));
    }

    $offered_amount = $this->validateMosaicAmount($form_state, 'offered', $network, $offered_mosaic_id, (string) ($offered['amount'] ?? ''));
    $requested_amount = $this->validateMosaicAmount($form_state, 'requested', $network, $requested_mosaic_id, (string) ($requested['amount'] ?? ''));
    if ($offered_amount !== NULL) {
      try {
        $balance = (string) ($this->engineClient->accountMosaicBalance($network, $seller_address, $offered_mosaic_id)['amount'] ?? '0');
        if ($this->compareAtomic($balance, $offered_amount) < 0) {
          $form_state->setErrorByName('offered][amount', $this->t('Seller balance is lower than the listed amount.'));
        }
        $reserved = $this->listings->sumReservedOfferedAmount($network, $seller_address, $offered_mosaic_id, $listing ? (int) $listing['id'] : NULL);
        $total_reserved = $this->addAtomic($reserved, $offered_amount);
        if ($this->compareAtomic($total_reserved, $balance) > 0) {
          $form_state->setErrorByName('offered][amount', $this->t('Seller active listings already reserve @reserved atomic units of this mosaic, which exceeds the current balance with this listing.', [
            '@reserved' => $reserved,
          ]));
        }
        $form_state->set('seller_balance_checked_amount', $balance);
      }
      catch (SymbolEngineException | \InvalidArgumentException $exception) {
        $form_state->setErrorByName('offered][amount', $this->t('Seller balance could not be verified: @message', ['@message' => $exception->getMessage()]));
      }
    }
    if (!$listing && $this->listings->countListingLimitedBySellerUid($seller_uid) >= $this->maxReservingListingsPerSeller()) {
      $form_state->setErrorByName('seller', $this->t('Seller already has the maximum number of active listings.'));
    }
    if ($offered_amount !== NULL && $requested_amount !== NULL) {
      $duplicate_values = [
        'seller_uid' => $seller_uid,
        'network' => $network,
        'offered_mosaic_id' => $offered_mosaic_id,
        'offered_amount' => $offered_amount,
        'requested_mosaic_id' => $requested_mosaic_id,
        'requested_amount' => $requested_amount,
      ];
      if ($this->listings->hasDuplicateReservingListing($duplicate_values, $listing ? (int) $listing['id'] : NULL)) {
        $form_state->setErrorByName('label', $this->t('Seller already has an active listing with the same offer and request.'));
      }
    }

    $form_state->set('offered_amount_atomic', $offered_amount);
    $form_state->set('requested_amount_atomic', $requested_amount);
    if (($expiration['mode'] ?? '') === 'datetime') {
      $expires_at = (array) ($expiration['expires_at'] ?? []);
      $expires_at_value = $expires_at['value'] ?? NULL;
      if (!$expires_at_value instanceof DrupalDateTime) {
        $form_state->setErrorByName('expiration][expires_at][value', $this->t('Choose a valid listing end date and time.'));
      }
      elseif ($expires_at_value->getTimestamp() < \Drupal::time()->getRequestTime() + 3600) {
        $form_state->setErrorByName('expiration][expires_at][value', $this->t('Listing end date and time must be at least 1 hour from now.'));
      }
    }
    elseif (($expiration['mode'] ?? '') !== 'never') {
      $form_state->setErrorByName('expiration][mode', $this->t('Choose a valid expiration option.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $listing_id = $form_state->getValue('listing_id');
    $listing = $listing_id ? $this->listings->find((int) $listing_id) : NULL;
    $account = $this->verifiedSymbolAccount();
    if ($listing_id && (!$listing || !$this->canEditListing($listing))) {
      throw new AccessDeniedHttpException();
    }
    if (!$listing && !$account) {
      throw new \RuntimeException('Verified Symbol account disappeared during listing creation.');
    }

    $offered = (array) $form_state->getValue('offered', []);
    $requested = (array) $form_state->getValue('requested', []);
    $expiration = (array) $form_state->getValue('expiration', []);
    $expires_at = NULL;
    $expires_at_value = ((array) ($expiration['expires_at'] ?? []))['value'] ?? NULL;
    if (($expiration['mode'] ?? '') === 'datetime' && $expires_at_value instanceof DrupalDateTime) {
      $expires_at = $expires_at_value->getTimestamp();
    }
    $values = [
      'label' => trim((string) $form_state->getValue('label')),
      'offered_mosaic_id' => strtoupper(trim((string) $offered['mosaic_id'])),
      'offered_amount' => (string) $form_state->get('offered_amount_atomic'),
      'requested_mosaic_id' => strtoupper(trim((string) $requested['mosaic_id'])),
      'requested_amount' => (string) $form_state->get('requested_amount_atomic'),
      'swap_window_minutes' => (int) $form_state->getValue('swap_window_minutes'),
      'expires_at' => $expires_at,
      'seller_balance_checked_amount' => (string) $form_state->get('seller_balance_checked_amount'),
      'seller_balance_checked_at' => \Drupal::time()->getRequestTime(),
    ];

    if ($listing) {
      $this->listings->updateEditable((int) $listing['id'], $values);
      $id = (int) $listing['id'];
      $this->messenger()->addStatus($this->t('P2P listing was updated. No assets were locked.'));
    }
    else {
      $values += [
        'network' => (string) $account['network'],
        'seller_uid' => (int) $this->currentUser->id(),
        'seller_address' => (string) $account['address'],
        'seller_public_key' => (string) $account['public_key'],
      ];
      $id = $this->listings->create($values);
      $this->messenger()->addStatus($this->t('P2P listing was created. No assets were locked.'));
    }
    $form_state->setRedirect('symbol_p2p_ad_listing.view', ['listingId' => $id]);
  }

  private function validateMosaicAmount(FormStateInterface $form_state, string $group, string $network, string $mosaic_id, string $amount): ?string {
    if (!preg_match('/^[0-9A-Fa-f]{16}$/', $mosaic_id)) {
      $form_state->setErrorByName($group . '][mosaic_id', $this->t('Mosaic ID must be 16 hex characters.'));
      return NULL;
    }
    try {
      $metadata = $this->engineClient->mosaicMetadata($network, $mosaic_id);
      if (($metadata['transferable'] ?? TRUE) !== TRUE) {
        $form_state->setErrorByName($group . '][mosaic_id', $this->t('Mosaic is not transferable.'));
        return NULL;
      }
      $divisibility = $metadata['divisibility'] ?? NULL;
      if (!is_int($divisibility) || $divisibility < 0 || $divisibility > 6) {
        $form_state->setErrorByName($group . '][mosaic_id', $this->t('Mosaic divisibility could not be resolved.'));
        return NULL;
      }
      return $this->toAtomicAmount($amount, $divisibility);
    }
    catch (SymbolEngineException | \InvalidArgumentException $exception) {
      $form_state->setErrorByName($group . '][mosaic_id', $exception->getMessage());
      return NULL;
    }
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

  private function toAtomicAmount(string $value, int $divisibility): string {
    $value = trim($value);
    if (!preg_match('/^(0|[1-9][0-9]*)(?:\\.([0-9]+))?$/', $value, $matches)) {
      throw new \InvalidArgumentException((string) $this->t('Amount must be a positive decimal number.'));
    }
    $fraction = $matches[2] ?? '';
    if (strlen($fraction) > $divisibility) {
      throw new \InvalidArgumentException((string) $this->t('Amount has more decimal places than the mosaic divisibility allows.'));
    }
    $atomic = ltrim($matches[1] . str_pad($fraction, $divisibility, '0'), '0');
    if ($atomic === '') {
      throw new \InvalidArgumentException((string) $this->t('Amount must be greater than zero.'));
    }
    return $atomic;
  }

  private function compareAtomic(string $left, string $right): int {
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
  }

  private function addAtomic(string $left, string $right): string {
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    $carry = 0;
    $sum = '';
    $left_index = strlen($left) - 1;
    $right_index = strlen($right) - 1;

    while ($left_index >= 0 || $right_index >= 0 || $carry > 0) {
      $digit = $carry;
      if ($left_index >= 0) {
        $digit += (int) $left[$left_index];
        $left_index--;
      }
      if ($right_index >= 0) {
        $digit += (int) $right[$right_index];
        $right_index--;
      }
      $sum = (string) ($digit % 10) . $sum;
      $carry = intdiv($digit, 10);
    }

    return ltrim($sum, '0') ?: '0';
  }

  private function defaultCurrencyMosaicId(string $network): string {
    return self::CURRENCY_MOSAIC_IDS[$network] ?? self::CURRENCY_MOSAIC_IDS['testnet'];
  }

  private function mainnetEnabled(): bool {
    return (bool) $this->config('symbol_atomic_swap.settings')->get('mainnet_enabled');
  }

  private function maxReservingListingsPerSeller(): int {
    $value = $this->config('symbol_p2p_ad_listing.settings')->get('max_reserving_listings_per_seller');
    if (!is_numeric($value)) {
      return self::DEFAULT_MAX_RESERVING_LISTINGS_PER_SELLER;
    }
    return max(1, min(100, (int) $value));
  }

  /**
   * @param array<string, mixed>|null $listing
   */
  private function defaultExpirationDateTime(?array $listing): DrupalDateTime {
    $timestamp = $listing && !empty($listing['expires_at'])
      ? (int) $listing['expires_at']
      : \Drupal::time()->getRequestTime() + 86400;
    return DrupalDateTime::createFromTimestamp($timestamp);
  }

  private function displayAmount(string $atomic_amount, string $network, string $mosaic_id): string {
    try {
      $metadata = $this->engineClient->mosaicMetadata($network, $mosaic_id);
      $divisibility = $metadata['divisibility'] ?? NULL;
    }
    catch (SymbolEngineException | \InvalidArgumentException) {
      $divisibility = NULL;
    }
    if (!is_int($divisibility) || $divisibility < 0 || $divisibility > 6 || preg_match('/^\d+$/', $atomic_amount) !== 1) {
      return $atomic_amount;
    }
    if ($divisibility === 0) {
      return ltrim($atomic_amount, '0') ?: '0';
    }
    $padded = str_pad($atomic_amount, $divisibility + 1, '0', STR_PAD_LEFT);
    $whole = substr($padded, 0, -$divisibility);
    $fraction = substr($padded, -$divisibility);
    return (ltrim($whole, '0') ?: '0') . '.' . $fraction;
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canEditListing(array $listing): bool {
    return in_array((string) $listing['status'], [AdListingRepository::ACTIVE, AdListingRepository::INSUFFICIENT_BALANCE], TRUE)
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) === (int) $this->currentUser->id();
  }

}
