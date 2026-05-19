<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Drupal\symbol_atomic_swap\Signing\AliceSignUrl;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferBondedPartialAnnounceForm extends FormBase {

  private const MAX_SIGNED_PAYLOAD_HEX_LENGTH = 262144;

  /**
   * @var array<string, mixed>
   */
  private array $offer = [];

  /**
   * @var array<string, mixed>
   */
  private array $hashLock = [];

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SymbolEngineClient $engineClient,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_bonded_partial_announce_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers()->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;
    $hash_lock = [];
    $build_error = '';

    if (!$this->canRun($offer)) {
      $build_error = (string) $this->t('Aggregate bonded partial announcement requires a root-signed bonded offer.');
    }
    else {
      try {
        $hash_lock = $this->engineClient()->buildHashLock(
          (string) $offer['intent_hash'],
          (string) $offer['leg2_signer_public_key'],
          2,
        );
      }
      catch (SymbolEngineException | \InvalidArgumentException | \RuntimeException $exception) {
        $build_error = $exception->getMessage();
      }
    }
    $this->hashLock = $hash_lock;

    $unsigned_payload = $this->normalizeHex((string) ($hash_lock['unsignedPayload'] ?? ''));
    $form['#attached']['library'][] = 'symbol_atomic_swap/sss_sign';
    $form['#attributes']['data-symbol-sss-container'] = '1';
    $form['#attributes']['data-symbol-hash-lock-unsigned-payload'] = $unsigned_payload;
    $form['#attributes']['data-symbol-sss-required-signer'] = (string) $offer['leg2_signer_public_key'];

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Action'),
      '#markup' => $this->t('Sign and announce the taker-funded hash lock, wait for hash lock confirmation, then announce the aggregate bonded transaction as partial.'),
    ];
    $form['signer'] = [
      '#type' => 'item',
      '#title' => $this->t('Hash lock signer public key'),
      '#markup' => (string) $offer['leg2_signer_public_key'],
      '#description' => $this->t('SSS must be set to this taker account. This account funds the 10 XYM hash lock.'),
    ];
    if ($build_error !== '') {
      $form['build_error'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        'message' => [
          '#markup' => $this->t('Hash lock payload could not be built: @message', ['@message' => $build_error]),
        ],
      ];
    }
    $form['unsigned_payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Unsigned hash lock payload sent to SSS'),
      '#value' => $unsigned_payload,
      '#rows' => 8,
      '#attributes' => [
        'readonly' => 'readonly',
        'spellcheck' => 'false',
      ],
    ];
    if ($unsigned_payload !== '') {
      $alice_url = AliceSignUrl::transaction($unsigned_payload, (string) $offer['leg2_signer_public_key']);
      $form['alice'] = [
        '#type' => 'details',
        '#title' => $this->t('Mobile signing with aLice'),
        '#open' => FALSE,
        'notice' => [
          '#type' => 'item',
          '#markup' => $this->t('Scan this QR with a phone that has aLice installed, or open the aLice URL on the mobile device. aLice displays the signed hash lock payload when no callback URL is provided; paste that signed payload below and submit it.'),
        ],
        'qr' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['symbol-atomic-swap-qr'],
            'data-qr-payload' => $alice_url,
          ],
        ],
        'open' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => (string) $this->t('Open aLice signer'),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
            'href' => $alice_url,
          ],
        ],
        'copy' => [
          '#type' => 'container',
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => (string) $this->t('Copy aLice signing URL'),
          ],
          'value' => $this->copyValue($alice_url),
        ],
      ];
    }
    $form['signed_hash_lock_payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Signed hash lock payload'),
      '#rows' => 10,
      '#description' => $this->t('SSS fills and submits this field automatically. You may also paste the signed hash lock payload displayed by aLice and submit it manually.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-hash-lock-signed-payload' => '1',
      ],
    ];
    $form['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'data-symbol-sss-status' => '1',
        'aria-live' => 'polite',
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $submit_attributes = [
      'type' => 'button',
      'class' => ['button', 'button--primary'],
      'data-symbol-bonded-hash-lock-announce' => '1',
    ];
    if ($build_error !== '' || $unsigned_payload === '') {
      $submit_attributes['disabled'] = 'disabled';
    }
    $form['actions']['sign'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Sign hash lock and announce partial'),
      '#attributes' => $submit_attributes,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit signed hash lock'),
      '#button_type' => 'primary',
      '#attributes' => [
        'data-symbol-bonded-submit-trigger' => '1',
      ],
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
    $offer = $this->offerForValidation($form_state);
    if (!$offer || !$this->canRun($offer)) {
      $form_state->setErrorByName('signed_hash_lock_payload', $this->t('Aggregate bonded partial announcement requires a root-signed bonded offer.'));
    }
    $payload = $this->normalizeHex((string) $form_state->getValue('signed_hash_lock_payload', ''));
    if ($payload === '') {
      $form_state->setErrorByName('signed_hash_lock_payload', $this->t('Signed hash lock payload is missing. Sign with SSS or paste the signed hash lock payload displayed by aLice.'));
    }
    elseif (preg_match('/^[0-9A-F]+$/', $payload) !== 1 || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('signed_hash_lock_payload', $this->t('Signed hash lock payload must be even-length hex.'));
    }
    if (strlen($payload) > self::MAX_SIGNED_PAYLOAD_HEX_LENGTH) {
      $form_state->setErrorByName('signed_hash_lock_payload', $this->t('Signed hash lock payload is too large.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers()->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }

    try {
      @set_time_limit(360);
      $this->engineClient()->announceHashLock(
        (string) $offer['intent_hash'],
        $this->normalizeHex((string) $form_state->getValue('signed_hash_lock_payload')),
        TRUE,
      );
      $partial = $this->engineClient()->announcePartial((string) $offer['intent_hash']);
      $transaction_hash = (string) ($partial['transactionHash'] ?? $offer['root_transaction_hash'] ?? $offer['transaction_hash'] ?? '');
      $this->offers()->markPartialAnnounced($offer_id, $transaction_hash);
      $this->messenger()->addStatus($this->t('Hash lock was confirmed and the aggregate bonded transaction was announced as partial.'));
    }
    catch (SymbolEngineException | \InvalidArgumentException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('Aggregate bonded partial announcement failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
  }

  /**
   * @param array<string, mixed> $offer
   */
  private function canRun(array $offer): bool {
    $qr_payload = $this->decodedQrPayload($offer);
    return ($qr_payload['type'] ?? '') === 'symbol-aggregate-bonded'
      && in_array((string) ($offer['state'] ?? ''), ['root_signed', 'signed'], TRUE)
      && !empty($offer['intent_hash'])
      && !empty($offer['root_signed_payload'])
      && !empty($offer['root_transaction_hash'])
      && !empty($offer['leg2_signer_public_key']);
  }

  private function offers(): SwapOfferRepository {
    if (!isset($this->offers)) {
      $this->offers = \Drupal::service('symbol_atomic_swap.offer_repository');
    }
    return $this->offers;
  }

  private function engineClient(): SymbolEngineClient {
    if (!isset($this->engineClient)) {
      $this->engineClient = \Drupal::service('symbol_atomic_swap.engine_client');
    }
    return $this->engineClient;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function offerForValidation(FormStateInterface $form_state): ?array {
    $offer_id = (int) $form_state->getValue('offer_id', 0);
    if ($offer_id > 0) {
      return $this->offers()->find($offer_id);
    }
    return $this->offer ?: NULL;
  }

  /**
   * @param array<string, mixed> $offer
   *
   * @return array<string, mixed>
   */
  private function decodedQrPayload(array $offer): array {
    if (empty($offer['qr_payload'])) {
      return [];
    }
    try {
      $decoded = json_decode((string) $offer['qr_payload'], TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\JsonException) {
      return [];
    }
  }

  private function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
  }

  private function copyValue(string $value): array|string {
    if ($value === '') {
      return '';
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['symbol-atomic-swap-copy']],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'code',
        '#value' => $value,
        '#attributes' => ['class' => ['symbol-atomic-swap-long-value']],
      ],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => (string) $this->t('Copy'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--small', 'symbol-atomic-swap-copy__button'],
          'data-symbol-copy' => $value,
        ],
      ],
    ];
  }

}
