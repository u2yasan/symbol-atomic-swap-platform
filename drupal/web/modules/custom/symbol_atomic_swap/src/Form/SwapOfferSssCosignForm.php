<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferCosignatureRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolAddressDeriver;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Drupal\symbol_atomic_swap\Signing\AliceSignUrl;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferSssCosignForm extends FormBase {

  private const MAX_COSIGNATURE_JSON_LENGTH = 8192;

  /**
   * @var array<string, mixed>
   */
  private array $offer = [];

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SwapOfferCosignatureRepository $cosignatures,
    private readonly SymbolEngineClient $engineClient,
    private readonly SymbolAddressDeriver $addressDeriver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.offer_cosignature_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('symbol_atomic_swap.address_deriver'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_sss_cosign_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;
    $is_bonded_cosignature = $this->offers->canSubmitBondedCosignature($offer);
    $payload_for_sss = $this->normalizeHex((string) ($is_bonded_cosignature || !empty($offer['root_signed_payload'])
      ? ($offer['root_signed_payload'] ?? '')
      : ($offer['unsigned_payload'] ?? '')));
    $parent_hash = (string) (($offer['root_transaction_hash'] ?? '') ?: ($offer['transaction_hash'] ?: ''));
    $expected_cosigner = $this->expectedCosignerPublicKey($offer);
    $expected_cosigner_address = $this->addressFromPublicKey($expected_cosigner, (string) $offer['network']);

    $form['#attached']['library'][] = 'symbol_atomic_swap/sss_sign';
    $form['#attributes']['data-symbol-sss-container'] = '1';
    $form['#attributes']['data-symbol-sss-unsigned-payload'] = $payload_for_sss;
    $form['#attributes']['data-symbol-sss-required-signer'] = $expected_cosigner;
    if ($is_bonded_cosignature) {
      $form['#attributes']['data-symbol-sss-cosign-auto-submit'] = '1';
    }

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    $form['intent_hash'] = [
      '#type' => 'item',
      '#title' => $this->t('Intent hash'),
      '#markup' => (string) ($offer['intent_hash'] ?: $this->t('No intent hash has been generated.')),
      '#access' => $this->currentUser()->hasPermission('administer symbol atomic swap offers'),
    ];
    $form['expected_signer'] = [
      '#type' => 'container',
      'heading' => [
        '#type' => 'item',
        '#title' => $this->t('Expected cosigner public key'),
      ],
      'address' => [
        '#type' => 'item',
        '#title' => $this->t('Address'),
        '#markup' => $expected_cosigner_address !== '' ? $expected_cosigner_address : $this->t('Unavailable'),
      ],
      'public_key' => [
        '#type' => 'item',
        '#title' => $this->t('Public Key'),
        '#markup' => $expected_cosigner,
      ],
    ];
    $form['unsigned_payload'] = [
      '#type' => 'textarea',
      '#title' => $is_bonded_cosignature || !empty($offer['root_signed_payload'])
        ? $this->t('Root signed payload sent to signing app')
        : $this->t('Unsigned payload sent to SSS'),
      '#value' => $payload_for_sss,
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
        '#markup' => $this->t('Use SSS Extension in this browser to cosign with the expected cosigner account. Confirm the active SSS account before cosigning.'),
      ],
      'parent_hash' => [
        '#type' => 'textfield',
        '#title' => $this->t('Parent hash fallback'),
        '#default_value' => $parent_hash,
        '#description' => $this->t('Used only when SSS does not return a hash. This must be the root transaction hash produced by the aggregate signer signature.'),
        '#attributes' => [
          'autocomplete' => 'off',
          'spellcheck' => 'false',
          'data-symbol-sss-parent-hash' => '1',
        ],
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
      'cosign' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => (string) ($is_bonded_cosignature ? $this->t('Cosign and announce partial with SSS') : $this->t('Cosign unsigned payload with SSS')),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--primary'],
          'data-symbol-sss-cosign' => '1',
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
    if ($payload_for_sss !== '') {
      $alice_url = AliceSignUrl::cosignature($payload_for_sss, $expected_cosigner);
      $form['alice'] = [
        '#type' => 'details',
        '#title' => $this->t('Mobile signing with aLice'),
        '#open' => FALSE,
        'notice' => [
          '#type' => 'item',
          '#markup' => $this->t('Scan this QR with a phone that has aLice installed, or open the aLice URL on the mobile device. aLice displays a cosignature signature when no callback URL is provided; paste that 128-hex signature below. This form combines it with the parent hash and expected cosigner before verification.'),
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
      '#title' => $this->t('Cosignature JSON or aLice signature'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $is_bonded_cosignature
        ? $this->t('SSS fills this field after cosignature approval. You may also paste the 128-hex signature displayed by aLice. The form then announces the aggregate bonded cosignature to the node.')
        : $this->t('SSS fills this field after cosignature approval. You may also paste the 128-hex signature displayed by aLice. Submit it to verify and store the cosignature.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-sss-cosignature-json' => '1',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $is_bonded_cosignature ? $this->t('Announce aggregate bonded cosignature') : $this->t('Verify and store cosignature'),
      '#button_type' => 'primary',
      '#disabled' => (!$this->offers->canSubmitSignedPayload($offer) && !$is_bonded_cosignature) || $payload_for_sss === '',
      '#attributes' => [
        'data-symbol-sss-cosign-submit' => '1',
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
    $is_bonded_cosignature = $this->offers->canSubmitBondedCosignature($offer);
    if (!$this->offers->canSubmitSignedPayload($offer) && !$is_bonded_cosignature) {
      $form_state->setErrorByName('payload', $this->t('Cosignatures can only be submitted for QR-generated or already signed settlements with a valid intent hash.'));
    }

    $raw = trim((string) $form_state->getValue('payload', ''));
    if ($raw === '' || strlen($raw) > self::MAX_COSIGNATURE_JSON_LENGTH) {
      $form_state->setErrorByName('payload', $this->t('Cosignature JSON is required and must be smaller than @bytes bytes.', [
        '@bytes' => (string) self::MAX_COSIGNATURE_JSON_LENGTH,
      ]));
      return;
    }
    $raw_hex = $this->normalizeHex($raw);
    if (preg_match('/^[0-9A-Fa-f]+$/', $raw_hex) === 1) {
      if (strlen($raw_hex) !== 128) {
        $form_state->setErrorByName('payload', $this->t('This looks like signed payload HEX, not a 128-hex aLice cosignature signature. Paste cosignature JSON or the signature displayed by aLice cosignature signing.'));
        return;
      }
      $normalized = $this->cosignatureFromSignature($raw_hex, $offer, $form_state);
      if ($normalized === NULL) {
        return;
      }
    }
    else {
      try {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
          $form_state->setErrorByName('payload', $this->t('Cosignature JSON must be an object.'));
          return;
        }
        $normalized = $this->normalizeCosignature($decoded);
      }
      catch (\JsonException) {
        $form_state->setErrorByName('payload', $this->t('Cosignature JSON is malformed.'));
        return;
      }
    }

    if (!$this->hasRequiredCosignatureFields($normalized)) {
      $form_state->setErrorByName('payload', $this->t('Cosignature JSON must include parentHash, signerPublicKey, and signature.'));
      return;
    }
    $signer_public_key = strtoupper((string) $normalized['signerPublicKey']);
    $expected_signer_public_key = $this->expectedCosignerPublicKey($offer);
    if ($signer_public_key !== $expected_signer_public_key) {
      $form_state->setErrorByName('payload', $this->t('SSS cosignature must be created by the non-root signer public key @key.', [
        '@key' => $expected_signer_public_key,
      ]));
      return;
    }
    if (!$is_bonded_cosignature) {
      $expected_parent_hash = strtoupper((string) ($offer['root_transaction_hash'] ?? ''));
      $parent_hash = strtoupper((string) ($normalized['parentHash'] ?? ''));
      if ($expected_parent_hash === '' || $parent_hash !== $expected_parent_hash) {
        $form_state->setErrorByName('payload', $this->t('SSS cosignature must be created against the root signed transaction hash @hash. Sign the root signed payload again with SSS.', [
          '@hash' => $expected_parent_hash,
        ]));
        return;
      }
    }
    $form_state->set('symbol_atomic_swap_cosignature', $normalized);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }

    $cosignature = $form_state->get('symbol_atomic_swap_cosignature');
    if (!is_array($cosignature)) {
      $this->messenger()->addError($this->t('SSS cosignature verification failed: @reason', ['@reason' => 'invalid_json']));
      $form_state->setRebuild(TRUE);
      return;
    }

    $is_bonded_cosignature = $this->offers->canSubmitBondedCosignature($offer);
    try {
      $result = $is_bonded_cosignature
        ? $this->engineClient->announceCosignature((string) $offer['intent_hash'], $cosignature)
        : $this->engineClient->verifyCosignature((string) $offer['intent_hash'], $cosignature);
      if (($result['accepted'] ?? FALSE) !== TRUE) {
        $this->messenger()->addError($this->t('SSS cosignature was rejected: @reason', [
          '@reason' => $this->safeRejectionReason((string) ($result['reason'] ?? 'unknown_reason')),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->cosignatures->upsert([
        'offer_id' => $offer_id,
        'parent_hash' => (string) ($result['parentHash'] ?? $cosignature['parentHash']),
        'signer_public_key' => (string) $result['signerPublicKey'],
        'signature' => (string) $cosignature['signature'],
        'trusted_parent_hash' => !empty($result['trustedParentHash']),
        'uid' => (int) $this->currentUser()->id(),
      ]);
      if ($is_bonded_cosignature) {
        $this->offers->markPartialCosigned($offer_id, (string) ($result['transactionHash'] ?? $offer['transaction_hash']));
        $this->messenger()->addStatus($this->t('Aggregate bonded cosignature was announced. Wait for confirmation or sync the projection.'));
      }
      else {
        $assembled = $this->engineClient->assembleCompletePayload(
          (string) $offer['intent_hash'],
          (string) ($offer['root_signed_payload'] ?? ''),
          $this->cosignatures->assemblyPayloadsByOffer($offer_id),
        );
        if (($assembled['accepted'] ?? FALSE) !== TRUE || empty($assembled['transactionHash'])) {
          $this->messenger()->addError($this->t('Signed payload assembly failed: @reason', [
            '@reason' => $this->safeRejectionReason((string) ($assembled['reason'] ?? 'unknown_reason')),
          ]));
          $form_state->setRebuild(TRUE);
          return;
        }
        $transaction_hash = (string) $assembled['transactionHash'];
        $this->offers->markSigned($offer_id, $transaction_hash);
        try {
          $announced = $this->engineClient->announce((string) $offer['intent_hash']);
          $this->offers->markAnnounced($offer_id, (string) ($announced['transactionHash'] ?? $transaction_hash));
          $this->messenger()->addStatus($this->t('Cosignature was verified, the signed payload was assembled, and the aggregate complete transaction was announced.'));
        }
        catch (SymbolEngineException | \RuntimeException $exception) {
          $this->messenger()->addWarning($this->t('Cosignature was verified and the signed payload was assembled, but announcement failed: @message', [
            '@message' => $exception->getMessage(),
          ]));
        }
      }
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException $exception) {
      $this->messenger()->addError($this->t('SSS cosignature verification failed: @reason', [
        '@reason' => $this->safeRejectionReason($this->engineFailureReason($exception)),
      ]));
      $form_state->setRebuild(TRUE);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      $this->messenger()->addError($this->t('SSS cosignature verification failed: @reason', [
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
  private function expectedCosignerPublicKey(array $offer): string {
    if ($this->offers->canSubmitBondedCosignature($offer)) {
      return strtoupper((string) ($offer['leg1_signer_public_key'] ?? ''));
    }
    $qr_payload = $this->decodedQrPayload($offer);
    $required_cosigners = $qr_payload['requiredCosigners'] ?? [];
    if (is_array($required_cosigners) && isset($required_cosigners[1]) && is_string($required_cosigners[1])) {
      return strtoupper($required_cosigners[1]);
    }
    return strtoupper((string) ($offer['leg2_signer_public_key'] ?? ''));
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
   * @return array<string, mixed>
   */
  private function offerForValidation(FormStateInterface $form_state): array {
    if ($this->offer !== []) {
      return $this->offer;
    }
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;
    return $offer;
  }

  /**
   * @param array<string, mixed> $cosignature
   *
   * @return array<string, mixed>
   */
  private function normalizeCosignature(array $cosignature): array {
    $normalized = [];
    foreach ($cosignature as $key => $value) {
      if (!is_string($key)) {
        continue;
      }
      $canonical_key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? '');
      $target_key = match ($canonical_key) {
        'parenthash' => 'parentHash',
        'signerpublickey' => 'signerPublicKey',
        'signature' => 'signature',
        'version' => 'version',
        default => $key,
      };

      if (in_array($target_key, ['parentHash', 'signerPublicKey', 'signature'], TRUE) && is_scalar($value)) {
        $normalized[$target_key] = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string) $value) ?? '');
        continue;
      }

      $normalized[$target_key] = $value;
    }

    return $normalized;
  }

  /**
   * @param array<string, mixed> $cosignature
   */
  private function hasRequiredCosignatureFields(array $cosignature): bool {
    return isset($cosignature['parentHash'], $cosignature['signerPublicKey'], $cosignature['signature'])
      && is_string($cosignature['parentHash'])
      && is_string($cosignature['signerPublicKey'])
      && is_string($cosignature['signature'])
      && $cosignature['parentHash'] !== ''
      && $cosignature['signerPublicKey'] !== ''
      && $cosignature['signature'] !== '';
  }

  /**
   * @param array<string, mixed> $offer
   *
   * @return array<string, mixed>|null
   */
  private function cosignatureFromSignature(string $signature, array $offer, FormStateInterface $form_state): ?array {
    $sss_values = (array) $form_state->getValue('sss', []);
    $parent_hash = $this->normalizeHex((string) ($sss_values['parent_hash'] ?? $form_state->getValue('parent_hash', '')));
    if ($parent_hash === '') {
      $parent_hash = $this->normalizeHex((string) (($offer['root_transaction_hash'] ?? '') ?: ($offer['transaction_hash'] ?? '')));
    }
    if (preg_match('/^[0-9A-F]{64}$/', $parent_hash) !== 1) {
      $form_state->setErrorByName('parent_hash', $this->t('Parent hash fallback must be a 64-hex transaction hash when pasting an aLice signature.'));
      return NULL;
    }

    $signer_public_key = $this->expectedCosignerPublicKey($offer);
    if (preg_match('/^[0-9A-F]{64}$/', $signer_public_key) !== 1) {
      $form_state->setErrorByName('payload', $this->t('Expected cosigner public key is unavailable. The aLice signature cannot be converted to cosignature JSON.'));
      return NULL;
    }

    return [
      'parentHash' => $parent_hash,
      'signerPublicKey' => $signer_public_key,
      'signature' => $signature,
      'version' => [
        'lower' => 0,
        'higher' => 0,
      ],
    ];
  }

  private function safeRejectionReason(string $reason): string {
    $reason = strtolower(trim(preg_replace('/\s+/', ' ', $reason) ?? ''));
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

}
