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

final class AdListingForm extends FormBase {

  private const CURRENCY_MOSAIC_IDS = [
    'mainnet' => '6BED913FA20223F8',
    'testnet' => '72C0212E67A08BCE',
  ];

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

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'symbol_atomic_swap/offer_form';
    $form['#attributes']['data-symbol-maker-address-form'] = '1';
    $account = $this->verifiedSymbolAccount();
    $network = (string) ($account['network'] ?? 'testnet');
    $default_mosaic_id = $this->defaultCurrencyMosaicId($network);
    if (!$account) {
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
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Listing label'),
      '#maxlength' => 128,
      '#required' => TRUE,
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
      '#markup' => (string) ($account['address'] ?? $this->t('Not verified')),
    ];
    $form['seller_address'] = [
      '#type' => 'hidden',
      '#value' => (string) ($account['address'] ?? ''),
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
      '#default_value' => $default_mosaic_id,
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
      '#default_value' => $default_mosaic_id,
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
      '#attributes' => [
        'pattern' => '[0-9]+(\\.[0-9]+)?',
        'autocomplete' => 'off',
        'data-symbol-mosaic-amount' => '1',
      ],
    ];
    $form['swap_window_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Settlement window minutes'),
      '#default_value' => 120,
      '#min' => 15,
      '#max' => 2880,
      '#step' => 15,
      '#required' => TRUE,
    ];
    $form['expiration'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Listing expiration'),
    ];
    $form['expiration']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Expiration'),
      '#default_value' => 'duration',
      '#required' => TRUE,
      '#options' => [
        'duration' => $this->t('Expire after a fixed duration'),
        'never' => $this->t('No expiration'),
      ],
    ];
    $form['expiration']['duration_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Listing duration hours'),
      '#default_value' => 24,
      '#min' => 1,
      '#max' => 8760,
      '#step' => 1,
      '#states' => [
        'visible' => [
          ':input[name="expiration[mode]"]' => ['value' => 'duration'],
        ],
        'required' => [
          ':input[name="expiration[mode]"]' => ['value' => 'duration'],
        ],
      ],
      '#description' => $this->t('Minimum listing duration is 1 hour. Use no expiration only for actively maintained listings.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create listing'),
      '#button_type' => 'primary',
      '#disabled' => !$account,
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->verifiedSymbolAccount();
    if (!$account) {
      $form_state->setErrorByName('seller', $this->t('Register and verify My Symbol Account before creating a listing.'));
      return;
    }

    $network = (string) $account['network'];
    $offered = (array) $form_state->getValue('offered', []);
    $requested = (array) $form_state->getValue('requested', []);
    $expiration = (array) $form_state->getValue('expiration', []);
    $offered_mosaic_id = strtoupper(trim((string) ($offered['mosaic_id'] ?? '')));
    $requested_mosaic_id = strtoupper(trim((string) ($requested['mosaic_id'] ?? '')));

    $offered_amount = $this->validateMosaicAmount($form_state, 'offered', $network, $offered_mosaic_id, (string) ($offered['amount'] ?? ''));
    $requested_amount = $this->validateMosaicAmount($form_state, 'requested', $network, $requested_mosaic_id, (string) ($requested['amount'] ?? ''));
    if ($offered_amount !== NULL) {
      try {
        $balance = (string) ($this->engineClient->accountMosaicBalance($network, (string) $account['address'], $offered_mosaic_id)['amount'] ?? '0');
        if ($this->compareAtomic($balance, $offered_amount) < 0) {
          $form_state->setErrorByName('offered][amount', $this->t('Seller balance is lower than the listed amount.'));
        }
        $form_state->set('seller_balance_checked_amount', $balance);
      }
      catch (SymbolEngineException | \InvalidArgumentException $exception) {
        $form_state->setErrorByName('offered][amount', $this->t('Seller balance could not be verified: @message', ['@message' => $exception->getMessage()]));
      }
    }

    $form_state->set('offered_amount_atomic', $offered_amount);
    $form_state->set('requested_amount_atomic', $requested_amount);
    if (($expiration['mode'] ?? '') === 'duration') {
      $duration_hours = (int) ($expiration['duration_hours'] ?? 0);
      if ($duration_hours < 1) {
        $form_state->setErrorByName('expiration][duration_hours', $this->t('Listing duration must be at least 1 hour.'));
      }
    }
    elseif (($expiration['mode'] ?? '') !== 'never') {
      $form_state->setErrorByName('expiration][mode', $this->t('Choose a valid expiration option.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->verifiedSymbolAccount();
    if (!$account) {
      throw new \RuntimeException('Verified Symbol account disappeared during listing creation.');
    }

    $offered = (array) $form_state->getValue('offered', []);
    $requested = (array) $form_state->getValue('requested', []);
    $expiration = (array) $form_state->getValue('expiration', []);
    $expires_at = NULL;
    if (($expiration['mode'] ?? '') === 'duration') {
      $expires_at = \Drupal::time()->getRequestTime() + ((int) $expiration['duration_hours'] * 3600);
    }
    $id = $this->listings->create([
      'label' => trim((string) $form_state->getValue('label')),
      'network' => (string) $account['network'],
      'seller_uid' => (int) $this->currentUser->id(),
      'seller_address' => (string) $account['address'],
      'seller_public_key' => (string) $account['public_key'],
      'offered_mosaic_id' => strtoupper(trim((string) $offered['mosaic_id'])),
      'offered_amount' => (string) $form_state->get('offered_amount_atomic'),
      'requested_mosaic_id' => strtoupper(trim((string) $requested['mosaic_id'])),
      'requested_amount' => (string) $form_state->get('requested_amount_atomic'),
      'swap_window_minutes' => (int) $form_state->getValue('swap_window_minutes'),
      'expires_at' => $expires_at,
      'seller_balance_checked_amount' => (string) $form_state->get('seller_balance_checked_amount'),
      'seller_balance_checked_at' => \Drupal::time()->getRequestTime(),
    ]);

    $this->messenger()->addStatus($this->t('P2P listing was created. No assets were locked.'));
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

  private function defaultCurrencyMosaicId(string $network): string {
    return self::CURRENCY_MOSAIC_IDS[$network] ?? self::CURRENCY_MOSAIC_IDS['testnet'];
  }

}
