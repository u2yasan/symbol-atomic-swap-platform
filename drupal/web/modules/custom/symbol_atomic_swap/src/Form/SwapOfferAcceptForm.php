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
      '#title' => $this->t('Offer terms'),
      '#markup' => $this->t('Maker pays @pay_amount of @pay_mosaic and wants @want_amount of @want_mosaic.', [
        '@pay_amount' => $this->formatMosaicAmount((string) $offer['leg1_amount'], (string) $offer['network'], (string) $offer['leg1_mosaic_id']),
        '@pay_mosaic' => $this->formatMosaicName((string) $offer['network'], (string) $offer['leg1_mosaic_id']),
        '@want_amount' => $this->formatMosaicAmount((string) $offer['leg2_amount'], (string) $offer['network'], (string) $offer['leg2_mosaic_id']),
        '@want_mosaic' => $this->formatMosaicName((string) $offer['network'], (string) $offer['leg2_mosaic_id']),
      ]),
    ];
    $form['taker'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Taker details'),
    ];
    $form['taker']['recipient_address'] = [
      '#type' => 'item',
      '#title' => $this->t('Taker recipient address'),
      '#markup' => $this->plainValue((string) ($verified_symbol_account['address'] ?? '')),
      '#description' => $this->t('Uses the verified address from My Symbol Account. Remove and re-register that account to change it.'),
    ];
    if (!$can_use_verified_account) {
      $form['taker']['account_verification_required'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#type' => 'item',
          '#markup' => $verified_symbol_account
            ? $this->t('My Symbol Account network must match this offer network before accepting the offer.')
            : $this->t('Accept Swap Offer requires a verified Symbol address in My Symbol Account.'),
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
    $form['transaction']['deadline_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Transaction deadline hours'),
      '#default_value' => 2,
      '#min' => 1,
      '#max' => self::AGGREGATE_BONDED_MAX_DEADLINE_HOURS,
      '#step' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Aggregate complete allows 1 to 6 hours. Aggregate bonded allows 1 to 48 hours. The maker offer itself does not expire from this value.'),
    ];
    $form['transaction']['hash_lock'] = [
      '#type' => 'details',
      '#title' => $this->t('Hash lock settings'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="transaction[aggregate_type]"]' => ['value' => self::AGGREGATE_BONDED],
        ],
      ],
    ];
    $hash_lock = $this->defaultHashLock((string) $offer['network']);
    $form['transaction']['hash_lock']['mosaic_id'] = [
      '#type' => 'item',
      '#title' => $this->t('Hash lock mosaic ID'),
      '#markup' => $hash_lock['mosaicId'],
      '#description' => $this->t('Fixed to the network currency mosaic.'),
    ];
    $form['transaction']['hash_lock']['amount'] = [
      '#type' => 'item',
      '#title' => $this->t('Hash lock amount'),
      '#markup' => $hash_lock['amount'],
      '#description' => $this->t('Fixed atomic amount. This is 10 XYM for networks with 6 divisibility.'),
    ];
    $form['transaction']['hash_lock']['duration'] = [
      '#type' => 'item',
      '#title' => $this->t('Hash lock duration blocks'),
      '#markup' => (string) $hash_lock['duration'],
      '#description' => $this->t('Maximum 5760 blocks, approximately 48 hours on Symbol.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Accept and build QR'),
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
      $form_state->setErrorByName('taker][signer_public_key', $this->t('Only open offers can be accepted.'));
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
      $form_state->setErrorByName('taker][recipient_address', $this->t('Register and verify My Symbol Account before accepting a swap offer.'));
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
        $engine_result = $this->engineClient->buildAggregateComplete($payload);
      }
      $this->offers->update($offer_id, $this->offers->engineFields($accepted, $engine_result) + [
        'changed' => \Drupal::time()->getRequestTime(),
      ]);
      $this->messenger()->addStatus($this->t('Trade offer was accepted and QR payload was generated.'));
    }
    catch (SymbolEngineException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('Trade offer was accepted, but Symbol Engine build failed: @message', [
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
