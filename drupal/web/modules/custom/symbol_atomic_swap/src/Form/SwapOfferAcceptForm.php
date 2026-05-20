<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferAcceptForm extends FormBase {

  private const AGGREGATE_COMPLETE = 'aggregate_complete';
  private const AGGREGATE_BONDED = 'aggregate_bonded';
  private const AGGREGATE_COMPLETE_MAX_DEADLINE_HOURS = 6;
  private const AGGREGATE_BONDED_MAX_DEADLINE_HOURS = 48;
  private const HASH_LOCK_MAX_DURATION_BLOCKS = 5760;
  private const HASH_LOCK_TRANSACTION_FEE_BUFFER = '100000';

  /**
   * @var array<string, mixed>
   */
  private array $offer = [];

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SymbolEngineClient $engineClient,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_accept_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'symbol_atomic_swap/offer_form';

    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;
    $verified_symbol_account = $this->verifiedSymbolAccount();
    $can_use_verified_account = $verified_symbol_account !== NULL
      && $verified_symbol_account['network'] === (string) $offer['network'];

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Settlement terms'),
      '#markup' => $this->t('Maker pays @pay_amount of @pay_mosaic and wants @want_amount of @want_mosaic.', [
        '@pay_amount' => $this->formatMosaicAmount((string) $offer['leg1_amount'], (string) $offer['network'], (string) $offer['leg1_mosaic_id']),
        '@pay_mosaic' => $this->formatMosaicName((string) $offer['network'], (string) $offer['leg1_mosaic_id']),
        '@want_amount' => $this->formatMosaicAmount((string) $offer['leg2_amount'], (string) $offer['network'], (string) $offer['leg2_mosaic_id']),
        '@want_mosaic' => $this->formatMosaicName((string) $offer['network'], (string) $offer['leg2_mosaic_id']),
      ]),
      '#description' => $this->t('Finalize only after both parties agree to execute this settlement within the transaction deadline.'),
    ];
    $form['taker'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Taker details'),
    ];
    $form['taker']['recipient_address'] = [
      '#type' => 'item',
      '#title' => $this->t('Taker recipient address'),
      '#markup' => $this->plainValue((string) ($verified_symbol_account['address'] ?? '')),
    ];
    if (!$can_use_verified_account) {
      $form['taker']['account_verification_required'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#type' => 'item',
          '#markup' => $verified_symbol_account
            ? $this->t('My Symbol Account network must match this offer network before accepting the offer.')
            : $this->t('Finalize Atomic Settlement requires a verified Symbol address in My Symbol Account.'),
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Open My Symbol Account'),
          '#url' => Url::fromRoute('symbol_atomic_swap.account_verification'),
          '#attributes' => ['class' => ['button']],
        ],
      ];
    }
    $form['transaction'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Transaction settings'),
      '#attributes' => [
        'data-symbol-aggregate-deadline-settings' => '1',
        'data-symbol-aggregate-complete-deadline-hours' => (string) self::AGGREGATE_COMPLETE_MAX_DEADLINE_HOURS,
        'data-symbol-aggregate-bonded-deadline-hours' => (string) self::AGGREGATE_BONDED_MAX_DEADLINE_HOURS,
      ],
    ];
    $form['transaction']['aggregate_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Aggregate transaction type'),
      '#default_value' => self::AGGREGATE_COMPLETE,
      '#required' => TRUE,
      '#options' => [
        self::AGGREGATE_COMPLETE => $this->t('Aggregate complete'),
        self::AGGREGATE_BONDED => $this->t('Aggregate bonded'),
      ],
      '#description' => $this->t('Aggregate complete requires all cosignatures before announcement. Aggregate bonded can be announced partially and then cosigned on-chain.'),
    ];
    $form['transaction']['aggregate_bonded_cost'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#states' => [
        'visible' => [
          ':input[name="transaction[aggregate_type]"]' => ['value' => self::AGGREGATE_BONDED],
        ],
      ],
      'message' => [
        '#type' => 'item',
        '#markup' => $this->t('Aggregate bonded requires the taker account to fund a 10 XYM hash lock plus transaction fee. The taker network currency balance is checked before the transaction is built.'),
      ],
    ];
    $form['transaction']['aggregate_complete_cost'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#states' => [
        'visible' => [
          ':input[name="transaction[aggregate_type]"]' => ['value' => self::AGGREGATE_COMPLETE],
        ],
      ],
      'message' => [
        '#type' => 'item',
        '#markup' => $this->t('Aggregate complete transaction fee is paid by the taker account. The taker exchange mosaic balance and XYM fee balance are checked before the transaction is built.'),
      ],
    ];
    $form['transaction']['deadline_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Transaction deadline hours'),
      '#default_value' => self::AGGREGATE_COMPLETE_MAX_DEADLINE_HOURS,
      '#min' => 1,
      '#max' => self::AGGREGATE_BONDED_MAX_DEADLINE_HOURS,
      '#step' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Aggregate complete allows 1 to 6 hours. Aggregate bonded allows 1 to 48 hours. The maker offer itself does not expire from this value.'),
      '#attributes' => [
        'data-symbol-aggregate-deadline-hours' => '1',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Accept and build Transaction'),
      '#button_type' => 'primary',
      '#disabled' => !$this->offers->canAccept($offer) || !$can_use_verified_account,
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->offers->canAccept($this->offer)) {
      $form_state->setErrorByName('taker][signer_public_key', $this->t('Only open settlements can be finalized.'));
    }
    $transaction = (array) $form_state->getValue('transaction', []);
    $aggregate_type = (string) ($transaction['aggregate_type'] ?? '');
    $deadline_hours = (int) ($transaction['deadline_hours'] ?? 0);
    $verified_symbol_account = $this->verifiedSymbolAccount();

    if (!in_array($aggregate_type, [self::AGGREGATE_COMPLETE, self::AGGREGATE_BONDED], TRUE)) {
      $form_state->setErrorByName('transaction][aggregate_type', $this->t('Choose a valid aggregate transaction type.'));
    }

    $max_deadline_hours = $aggregate_type === self::AGGREGATE_BONDED
      ? self::AGGREGATE_BONDED_MAX_DEADLINE_HOURS
      : self::AGGREGATE_COMPLETE_MAX_DEADLINE_HOURS;
    if ($deadline_hours < 1 || $deadline_hours > $max_deadline_hours) {
      $form_state->setErrorByName('transaction][deadline_hours', $this->t('Transaction deadline hours must be between 1 and @max for the selected aggregate transaction type.', [
        '@max' => (string) $max_deadline_hours,
      ]));
    }

    if (!$verified_symbol_account) {
      $form_state->setErrorByName('taker][recipient_address', $this->t('Register and verify My Symbol Account before finalizing an atomic settlement.'));
      return;
    }

    if ((string) $verified_symbol_account['network'] !== (string) $this->offer['network']) {
      $form_state->setErrorByName('taker][recipient_address', $this->t('My Symbol Account network must match the offer network.'));
      return;
    }

    if (strtoupper((string) $verified_symbol_account['public_key']) === strtoupper((string) $this->offer['leg1_signer_public_key'])) {
      $form_state->setErrorByName('taker][recipient_address', $this->t('Taker account must differ from maker account.'));
      return;
    }

    $this->validateTakerFundingBalances($form_state, $verified_symbol_account, $aggregate_type);

    $form_state->set('symbol_atomic_swap_taker_address', (string) $verified_symbol_account['address']);
    $form_state->set('symbol_atomic_swap_taker_public_key', (string) $verified_symbol_account['public_key']);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $verified_symbol_account = $this->verifiedSymbolAccount();
    $taker_address = (string) ($form_state->get('symbol_atomic_swap_taker_address') ?: ($verified_symbol_account['address'] ?? ''));
    $taker_public_key = (string) ($form_state->get('symbol_atomic_swap_taker_public_key') ?: ($verified_symbol_account['public_key'] ?? ''));

    $values = [
      'deadline_hours' => (int) $form_state->getValue(['transaction', 'deadline_hours']),
      'leg1_recipient_address' => $taker_address,
      'leg2_signer_public_key' => $taker_public_key,
      'intent_hash' => NULL,
      'unsigned_payload' => NULL,
      'qr_payload' => NULL,
      'transaction_hash' => NULL,
    ];
    $this->offers->accept($offer_id, $values);
    $accepted = $this->offers->find($offer_id);
    if (!$accepted) {
      throw new \RuntimeException('Accepted offer was not found.');
    }

    try {
      $aggregate_type = (string) $form_state->getValue(['transaction', 'aggregate_type']);
      $payload = $this->offers->toEngineBuildPayload($accepted);
      if ($aggregate_type === self::AGGREGATE_BONDED) {
        $payload['hashLock'] = $this->defaultHashLock((string) $accepted['network']);
        $engine_result = $this->engineClient->buildAggregateBonded($payload);
      }
      else {
        $payload['aggregateSignerPublicKey'] = $taker_public_key;
        $engine_result = $this->engineClient->buildAggregateComplete($payload);
      }
      $this->offers->update($offer_id, $this->offers->engineFields($accepted, $engine_result) + [
        'changed' => \Drupal::time()->getRequestTime(),
      ]);
      $this->messenger()->addStatus($this->t('Atomic settlement was finalized and payload was generated.'));
    }
    catch (SymbolEngineException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('Atomic settlement was finalized, but Symbol Engine build failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
  }

  private function isNetworkAddress(string $value, string $network): bool {
    $value = strtoupper(trim($value));
    $prefix = match ($network) {
      'mainnet' => 'N',
      'testnet' => 'T',
      default => '',
    };
    return $prefix !== '' && preg_match('/^' . $prefix . '[A-Z2-7]{38}$/', $value) === 1;
  }

  private function plainValue(string $value): string {
    return $value !== '' ? $value : (string) $this->t('Not set');
  }

  private function networkCurrencyMosaicId(string $network): string {
    return match ($network) {
      'mainnet' => '6BED913FA20223F8',
      'testnet' => '72C0212E67A08BCE',
      default => '',
    };
  }

  /**
   * @return array{mosaicId: string, amount: string, duration: int}
   */
  private function defaultHashLock(string $network): array {
    return [
      'mosaicId' => $this->networkCurrencyMosaicId($network),
      'amount' => '10000000',
      'duration' => self::HASH_LOCK_MAX_DURATION_BLOCKS,
    ];
  }

  /**
   * @param array{network: string, address: string, public_key: string} $verified_symbol_account
   */
  private function validateTakerFundingBalances(FormStateInterface $form_state, array $verified_symbol_account, string $aggregate_type): void {
    $network = (string) $this->offer['network'];
    $currency_mosaic_id = $this->networkCurrencyMosaicId($network);
    if ($currency_mosaic_id === '') {
      $form_state->setErrorByName('transaction][aggregate_type', $this->t('Network currency mosaic is not configured for taker balance checks.'));
      return;
    }

    $required_by_mosaic = [
      $currency_mosaic_id => self::HASH_LOCK_TRANSACTION_FEE_BUFFER,
    ];
    $leg2_mosaic_id = strtoupper((string) ($this->offer['leg2_mosaic_id'] ?? ''));
    if ($leg2_mosaic_id !== '') {
      $required_by_mosaic[$leg2_mosaic_id] = $this->addAtomic(
        $required_by_mosaic[$leg2_mosaic_id] ?? '0',
        (string) ($this->offer['leg2_amount'] ?? '0'),
      );
    }
    if ($aggregate_type === self::AGGREGATE_BONDED) {
      $hash_lock = $this->defaultHashLock($network);
      $required_by_mosaic[$currency_mosaic_id] = $this->addAtomic(
        $required_by_mosaic[$currency_mosaic_id] ?? '0',
        $hash_lock['amount'],
      );
    }

    foreach ($required_by_mosaic as $mosaic_id => $required) {
      try {
        $balance = (string) ($this->accountMosaicBalance($network, (string) $verified_symbol_account['address'], $mosaic_id)['amount'] ?? '0');
      }
      catch (SymbolEngineException | \InvalidArgumentException $exception) {
        $form_state->setErrorByName('transaction][aggregate_type', $this->t('Taker balance could not be verified for mosaic @mosaic: @message', [
          '@mosaic' => $mosaic_id,
          '@message' => $exception->getMessage(),
        ]));
        continue;
      }

      if ($this->compareAtomic($balance, $required) < 0) {
        $form_state->setErrorByName('transaction][aggregate_type', $this->t('Taker account needs at least @required atomic units of mosaic @mosaic for the selected aggregate transaction type. Current balance is @balance.', [
          '@required' => $required,
          '@mosaic' => $mosaic_id,
          '@balance' => $balance,
        ]));
      }
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function accountMosaicBalance(string $network, string $address, string $mosaic_id): array {
    $normalized_address = strtoupper($address);
    $normalized_mosaic_id = strtoupper($mosaic_id);
    $overrides = \Drupal::state()->get('symbol_atomic_swap.account_mosaic_balance_test_overrides', []);
    $override = $overrides[$network][$normalized_address][$normalized_mosaic_id] ?? NULL;
    if (is_array($override)) {
      return $override + [
        'network' => $network,
        'address' => $normalized_address,
        'mosaicId' => $normalized_mosaic_id,
      ];
    }

    return $this->engineClient->accountMosaicBalance($network, $normalized_address, $normalized_mosaic_id);
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

  /**
   * @return array{network: string, address: string, public_key: string}|null
   */
  private function verifiedSymbolAccount(): ?array {
    $account = \Drupal::entityTypeManager()->getStorage('user')->load((int) $this->currentUser->id());
    if (!$account || !(bool) ($account->get('field_symbol_address_verified')->value ?? FALSE)) {
      return NULL;
    }

    $network = (string) ($account->get('field_symbol_network')->value ?? '');
    $address = strtoupper((string) ($account->get('field_symbol_address')->value ?? ''));
    $public_key = strtoupper((string) ($account->get('field_symbol_public_key')->value ?? ''));
    if (!in_array($network, ['mainnet', 'testnet'], TRUE)
      || !$this->isNetworkAddress($address, $network)
      || !preg_match('/^[0-9A-F]{64}$/', $public_key)) {
      return NULL;
    }

    return [
      'network' => $network,
      'address' => $address,
      'public_key' => $public_key,
    ];
  }

  private function formatMosaicAmount(string $atomic_amount, string $network, string $mosaic_id): string {
    $metadata = $this->mosaicMetadata($network, $mosaic_id);
    $divisibility = $metadata['divisibility'] ?? NULL;
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

  private function formatMosaicName(string $network, string $mosaic_id): string {
    $normalized = strtoupper($mosaic_id);
    $metadata = $this->mosaicMetadata($network, $normalized);
    $aliases = $metadata['aliases'] ?? [];
    if (is_array($aliases) && isset($aliases[0]) && is_string($aliases[0]) && $aliases[0] !== '') {
      return $aliases[0] . ' (' . $normalized . ')';
    }
    return $normalized;
  }

  /**
   * @return array<string, mixed>
   */
  private function mosaicMetadata(string $network, string $mosaic_id): array {
    $normalized = strtoupper($mosaic_id);
    $overrides = \Drupal::state()->get('symbol_atomic_swap.mosaic_metadata_test_overrides', []);
    $override = $overrides[$network][$normalized] ?? NULL;
    if (is_array($override)) {
      return $override + ['mosaicId' => $normalized, 'aliases' => []];
    }

    try {
      return $this->engineClient->mosaicMetadata($network, $normalized);
    }
    catch (SymbolEngineException) {
      return ['mosaicId' => $normalized, 'aliases' => []];
    }
  }

}
