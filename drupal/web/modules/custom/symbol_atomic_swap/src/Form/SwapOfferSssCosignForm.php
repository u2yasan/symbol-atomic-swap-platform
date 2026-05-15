<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferCosignatureRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
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
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.offer_cosignature_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
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
    $unsigned_payload = $this->normalizeHex((string) ($offer['unsigned_payload'] ?? ''));

    $form['#attached']['library'][] = 'symbol_atomic_swap/sss_sign';
    $form['#attributes']['data-symbol-sss-container'] = '1';
    $form['#attributes']['data-symbol-sss-unsigned-payload'] = $unsigned_payload;

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    $form['intent_hash'] = [
      '#type' => 'item',
      '#title' => $this->t('Intent hash'),
      '#markup' => (string) ($offer['intent_hash'] ?: $this->t('No intent hash has been generated.')),
    ];
    $form['expected_signer'] = [
      '#type' => 'item',
      '#title' => $this->t('Expected cosigner public key'),
      '#markup' => (string) $offer['leg2_signer_public_key'],
    ];
    $form['unsigned_payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Unsigned payload sent to SSS'),
      '#value' => $unsigned_payload,
      '#rows' => 8,
      '#attributes' => [
        'readonly' => 'readonly',
        'spellcheck' => 'false',
      ],
    ];
    $form['parent_hash'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent hash fallback'),
      '#default_value' => (string) ($offer['transaction_hash'] ?: ''),
      '#description' => $this->t('Used only when SSS does not return a hash. If the aggregate signer already submitted a signed payload, this is the transaction hash.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-sss-parent-hash' => '1',
      ],
    ];
    $form['sss'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['symbol-atomic-swap-sss-sign']],
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
        '#value' => (string) $this->t('Cosign unsigned payload with SSS'),
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
    $form['payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cosignature JSON'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $this->t('SSS fills this field after cosignature approval. Submit it to verify and store the cosignature.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-sss-cosignature-json' => '1',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify and store SSS cosignature'),
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
      $form_state->setErrorByName('payload', $this->t('Cosignatures can only be submitted for QR-generated or already signed offers with a valid intent hash.'));
    }

    $raw = trim((string) $form_state->getValue('payload', ''));
    if ($raw === '' || strlen($raw) > self::MAX_COSIGNATURE_JSON_LENGTH) {
      $form_state->setErrorByName('payload', $this->t('Cosignature JSON is required and must be smaller than @bytes bytes.', [
        '@bytes' => (string) self::MAX_COSIGNATURE_JSON_LENGTH,
      ]));
      return;
    }

    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($decoded)) {
        $form_state->setErrorByName('payload', $this->t('Cosignature JSON must be an object.'));
        return;
      }
      $normalized = $this->normalizeCosignature($decoded);
      if (!$this->hasRequiredCosignatureFields($normalized)) {
        $form_state->setErrorByName('payload', $this->t('Cosignature JSON must include parentHash, signerPublicKey, and signature.'));
        return;
      }
      if (strtoupper((string) $normalized['signerPublicKey']) === strtoupper((string) $this->offer['leg1_signer_public_key'])) {
        $form_state->setErrorByName('payload', $this->t('SSS cosignature must be created by the non-root signer public key @key.', [
          '@key' => strtoupper((string) $this->offer['leg2_signer_public_key']),
        ]));
        return;
      }
      $form_state->set('symbol_atomic_swap_cosignature', $normalized);
    }
    catch (\JsonException) {
      $form_state->setErrorByName('payload', $this->t('Cosignature JSON is malformed.'));
    }
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

    try {
      $result = $this->engineClient->verifyCosignature((string) $offer['intent_hash'], $cosignature);
      if (($result['accepted'] ?? FALSE) !== TRUE) {
        $this->messenger()->addError($this->t('SSS cosignature was rejected: @reason', [
          '@reason' => $this->safeRejectionReason((string) ($result['reason'] ?? 'unknown_reason')),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->cosignatures->upsert([
        'offer_id' => $offer_id,
        'parent_hash' => (string) $result['parentHash'],
        'signer_public_key' => (string) $result['signerPublicKey'],
        'signature' => (string) $cosignature['signature'],
        'trusted_parent_hash' => !empty($result['trustedParentHash']),
        'uid' => (int) $this->currentUser()->id(),
      ]);
      $this->messenger()->addStatus($this->t('SSS cosignature was verified and stored.'));
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

}
