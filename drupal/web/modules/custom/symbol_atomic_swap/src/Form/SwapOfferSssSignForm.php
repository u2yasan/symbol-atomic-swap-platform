<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
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
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
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
    $form['signer'] = [
      '#type' => 'item',
      '#title' => $this->t('Required aggregate signer public key'),
      '#markup' => (string) ($offer['leg1_signer_public_key'] ?: ''),
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
    $form['payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Signed payload'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $this->t('SSS fills this field after signature approval. Submit it to verify the payload with Symbol Engine.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-sss-signed-payload' => '1',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify SSS signed payload'),
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
      $form_state->setErrorByName('payload', $this->t('SSS signing is only available for QR-generated or already signed offers with a valid intent hash.'));
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
      $result = $this->engineClient->verifySignedPayload((string) $offer['intent_hash'], $payload);
      if (($result['accepted'] ?? FALSE) !== TRUE || empty($result['transactionHash'])) {
        $this->messenger()->addError($this->t('SSS signed payload was rejected: @reason', [
          '@reason' => $this->safeRejectionReason((string) ($result['reason'] ?? 'unknown_reason')),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->offers->markSigned($offer_id, (string) $result['transactionHash']);
      $this->messenger()->addStatus($this->t('SSS signed payload was verified. Normalized size: @bytes bytes.', [
        '@bytes' => (string) intdiv(strlen($payload), 2),
      ]));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException $exception) {
      $this->messenger()->addError($this->t('SSS signed payload verification failed: @reason', [
        '@reason' => $this->safeRejectionReason($this->engineFailureReason($exception)),
      ]));
      $form_state->setRebuild(TRUE);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      $this->messenger()->addError($this->t('SSS signed payload verification failed: @reason', [
        '@reason' => 'verification_unavailable',
      ]));
      $form_state->setRebuild(TRUE);
    }
  }

  private function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
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
