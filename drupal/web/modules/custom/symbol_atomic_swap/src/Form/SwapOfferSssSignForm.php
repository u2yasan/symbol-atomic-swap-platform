<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolAddressDeriver;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Drupal\symbol_atomic_swap\Signing\AliceSignUrl;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferSssSignForm extends FormBase {

  private const MAX_SIGNED_PAYLOAD_HEX_LENGTH = 262144;

  /**
   * @var array<string, mixed>
   */
  private array $offer = [];

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SymbolEngineClient $engineClient,
    private readonly SymbolAddressDeriver $addressDeriver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('symbol_atomic_swap.address_deriver'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_sss_sign_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;
    $unsigned_payload = $this->normalizeHex((string) ($offer['unsigned_payload'] ?? ''));
    $required_signer = $this->requiredRootSigner($offer);
    $required_signer_address = $this->addressFromPublicKey($required_signer, (string) $offer['network']);
    $is_aggregate_bonded = $this->isAggregateBonded($offer);

    $form['#attached']['library'][] = 'symbol_atomic_swap/qr';
    $form['#attached']['library'][] = 'symbol_atomic_swap/sss_sign';
    $form['#attributes']['data-symbol-sss-container'] = '1';
    $form['#attributes']['data-symbol-sss-unsigned-payload'] = $unsigned_payload;
    $form['#attributes']['data-symbol-sss-required-signer'] = $required_signer;

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    if ($this->currentUser()->hasPermission('administer symbol atomic swap offers')) {
      $form['intent_hash'] = [
        '#type' => 'item',
        '#title' => $this->t('Intent hash'),
        '#markup' => (string) ($offer['intent_hash'] ?: $this->t('No intent hash has been generated.')),
      ];
    }
    $form['signer'] = [
      '#type' => 'container',
      'heading' => [
        '#type' => 'item',
        '#title' => $this->t('Required aggregate signer account'),
        '#description' => $is_aggregate_bonded
          ? $this->t('Aggregate bonded is initiated by the taker. Root signed payload must be signed by this taker account.')
          : $this->t('Root signed payload must be signed by this aggregate signer account. The other party cosigns after the root signature is stored.'),
      ],
      'address' => [
        '#type' => 'item',
        '#title' => $this->t('Address'),
        '#markup' => $required_signer_address !== '' ? $required_signer_address : $this->t('Unavailable'),
      ],
      'public_key' => [
        '#type' => 'item',
        '#title' => $this->t('Public Key'),
        '#markup' => $required_signer,
      ],
    ];
    $form['unsigned_payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Unsigned payload sent to external app'),
      '#value' => $unsigned_payload,
      '#rows' => 8,
      '#attributes' => [
        'readonly' => 'readonly',
        'spellcheck' => 'false',
      ],
    ];
    $form['sss'] = [
      '#type' => 'details',
      '#title' => $this->t('Browser signing with SSS Extension'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['symbol-atomic-swap-sss-sign']],
      'notice' => [
        '#type' => 'item',
        '#markup' => $this->t('Use SSS Extension in this browser to sign the unsigned payload with the required aggregate signer account. Confirm the active SSS account before signing.'),
      ],
      'open' => [
        '#type' => 'link',
        '#title' => $this->t('Install SSS Extension'),
        '#url' => Url::fromUri('https://chromewebstore.google.com/detail/sss-extension/llildiojemakefgnhhkmiiffonembcan?hl=ja'),
        '#attributes' => [
          'class' => ['button'],
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ],
      'sign' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => (string) $this->t('Sign unsigned payload with SSS'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--primary'],
          'data-symbol-sss-sign' => '1',
        ],
      ],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '',
        '#attributes' => [
          'data-symbol-sss-status' => '1',
          'aria-live' => 'polite',
        ],
      ],
    ];
    if ($unsigned_payload !== '') {
      $alice_url = AliceSignUrl::transaction($unsigned_payload, $required_signer);
      $form['alice'] = [
        '#type' => 'details',
        '#title' => $this->t('Mobile signing with aLice'),
        '#open' => FALSE,
        'notice' => [
          '#type' => 'item',
          '#markup' => $this->t('Scan this QR with a phone that has aLice installed, or open the aLice URL on the mobile device. aLice displays the signed payload when no callback URL is provided; paste that signed payload below and submit it for verification.'),
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
    $form['payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Signed payload'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Paste the root signed payload returned by the external signing app. Submit it to verify and store the maker signature before collecting taker cosignatures.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-sss-signed-payload' => '1',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify root signed payload'),
      '#button_type' => 'primary',
      '#disabled' => !$this->offers->canSubmitSignedPayload($offer) || $unsigned_payload === '',
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
    if (!$this->offers->canSubmitSignedPayload($this->offer)) {
      $form_state->setErrorByName('payload', $this->t('External app signing is only available for QR-generated or already signed settlements with a valid intent hash.'));
    }
    if ($this->normalizeHex((string) ($this->offer['unsigned_payload'] ?? '')) === '') {
      $form_state->setErrorByName('payload', $this->t('Unsigned payload is missing.'));
    }

    $payload = $this->normalizeHex((string) $form_state->getValue('payload', ''));
    if ($payload === '' || preg_match('/^[0-9A-Fa-f]+$/', $payload) !== 1 || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('payload', $this->t('Signed payload must be even-length hex.'));
    }
    if (strlen($payload) > self::MAX_SIGNED_PAYLOAD_HEX_LENGTH) {
      $form_state->setErrorByName('payload', $this->t('Signed payload is too large. Maximum accepted normalized size is @bytes bytes.', [
        '@bytes' => (string) intdiv(self::MAX_SIGNED_PAYLOAD_HEX_LENGTH, 2),
      ]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }

    try {
      $payload = $this->normalizeHex((string) $form_state->getValue('payload'));
      $result = $this->engineClient->verifyRootSignedPayload((string) $offer['intent_hash'], $payload);
      if (($result['accepted'] ?? FALSE) !== TRUE || empty($result['transactionHash'])) {
        $this->messenger()->addError($this->t('Root signed payload was rejected: @reason', [
          '@reason' => $this->safeRejectionReason((string) ($result['reason'] ?? 'unknown_reason'), $offer),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->offers->markRootSigned($offer_id, $payload, (string) $result['transactionHash']);
      $this->messenger()->addStatus($this->isAggregateBonded($offer)
        ? $this->t('Root signed payload was verified. Next, build and announce the taker-funded hash lock, then announce the aggregate bonded transaction as partial.')
        : $this->t('Root signed payload was verified. Next, collect the taker cosignature and assemble the final signed payload.'));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException $exception) {
      $this->messenger()->addError($this->t('Root signed payload verification failed: @reason', [
        '@reason' => $this->safeRejectionReason($this->engineFailureReason($exception), $offer),
      ]));
      $form_state->setRebuild(TRUE);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      $this->messenger()->addError($this->t('Root signed payload verification failed: @reason', [
        '@reason' => 'verification_unavailable',
      ]));
      $form_state->setRebuild(TRUE);
    }
  }

  private function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
  }

  /**
   * @param array<string, mixed> $offer
   */
  private function requiredRootSigner(array $offer): string {
    if ($this->isAggregateBonded($offer)) {
      return (string) ($offer['leg2_signer_public_key'] ?: '');
    }
    $qr_payload = $this->decodedQrPayload($offer);
    $required_cosigners = $qr_payload['requiredCosigners'] ?? [];
    if (is_array($required_cosigners) && isset($required_cosigners[0]) && is_string($required_cosigners[0])) {
      return strtoupper($required_cosigners[0]);
    }
    $payload_signer = $this->aggregateSignerFromPayload((string) ($offer['unsigned_payload'] ?? ''));
    return $payload_signer !== '' ? $payload_signer : (string) ($offer['leg1_signer_public_key'] ?: '');
  }

  /**
   * @param array<string, mixed> $offer
   */
  private function isAggregateBonded(array $offer): bool {
    $qr_payload = $this->decodedQrPayload($offer);
    return ($qr_payload['type'] ?? '') === 'symbol-aggregate-bonded';
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

  /**
   * @param array<string, mixed> $offer
   */
  private function safeRejectionReason(string $reason, array $offer): string {
    $reason = strtolower(trim(preg_replace('/\s+/', ' ', $reason) ?? ''));
    $required_signer = $this->requiredRootSigner($offer);
    $required_signer_address = $this->addressFromPublicKey($required_signer, (string) $offer['network']);
    $target = $required_signer_address !== '' ? $required_signer_address : $required_signer;
    if (
      $required_signer !== ''
      && (str_contains($reason, 'missing required signer ' . strtolower($required_signer))
        || str_contains($reason, 'aggregate signer mismatch'))
    ) {
      return sprintf(
        'wrong SSS account: root signed payload must be signed by the required aggregate signer account %s. The other settlement signer must cosign after the root signature is stored.',
        $target,
      );
    }
    if ($reason === '' || preg_match('/^[a-z0-9_.: -]{1,120}$/', $reason) !== 1) {
      return 'verification_failed';
    }
    return $reason;
  }

  private function engineFailureReason(SymbolEngineException $exception): string {
    $reason = $exception->details['reason'] ?? $exception->engineError ?? 'symbol_engine_error';
    return is_string($reason) ? $reason : 'symbol_engine_error';
  }

  private function addressFromPublicKey(string $public_key, string $network): string {
    try {
      return $public_key !== '' ? $this->addressDeriver->deriveFromPublicKey($public_key, $network) : '';
    }
    catch (\InvalidArgumentException) {
      return '';
    }
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

  private function aggregateSignerFromPayload(string $payload): string {
    $payload = $this->normalizeHex($payload);
    $signer_offset = (4 + 64) * 2;
    if (strlen($payload) < $signer_offset + 64) {
      return '';
    }
    $signer = substr($payload, $signer_offset, 64);
    return preg_match('/^[0-9A-F]{64}$/', $signer) === 1 ? $signer : '';
  }

}
