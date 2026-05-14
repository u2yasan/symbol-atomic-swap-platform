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

final class SwapOfferSignedPayloadForm extends FormBase {

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
    return 'symbol_atomic_swap_offer_signed_payload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;
    $normalized_payload = $form_state->get('symbol_atomic_swap_normalized_payload');
    $normalized_bytes = $form_state->get('symbol_atomic_swap_normalized_payload_bytes');

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    $form['intent_hash'] = [
      '#type' => 'item',
      '#title' => $this->t('Intent hash'),
      '#markup' => $offer['intent_hash'] ?: $this->t('No intent hash has been generated.'),
    ];
    $form['state'] = [
      '#type' => 'item',
      '#title' => $this->t('Offer state'),
      '#markup' => (string) $offer['state'],
    ];
    $form['payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Signed payload'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Whitespace is ignored and hex is converted to uppercase before verification. Maximum accepted normalized size is @bytes bytes.', [
        '@bytes' => (string) intdiv(self::MAX_SIGNED_PAYLOAD_HEX_LENGTH, 2),
      ]),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    if (is_string($normalized_payload)) {
      $form['normalized'] = [
        '#type' => 'item',
        '#title' => $this->t('Normalized signed payload'),
        '#markup' => $this->t('@chars hex characters / @bytes bytes. Prefix: @prefix', [
          '@chars' => (string) strlen($normalized_payload),
          '@bytes' => (string) $normalized_bytes,
          '@prefix' => substr($normalized_payload, 0, 32),
        ]),
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify signed payload'),
      '#button_type' => 'primary',
      '#disabled' => !$this->offers->canSubmitSignedPayload($offer),
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
      $form_state->setErrorByName('payload', $this->t('Signed payload can only be submitted for QR-generated or already signed offers with a valid intent hash.'));
    }

    $payload = $this->normalizeHex((string) $form_state->getValue('payload', ''));
    $form_state->set('symbol_atomic_swap_normalized_payload', $payload);
    $form_state->set('symbol_atomic_swap_normalized_payload_bytes', strlen($payload) % 2 === 0 ? intdiv(strlen($payload), 2) : 0);
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
      $result = $this->engineClient->verifySignedPayload(
        (string) $offer['intent_hash'],
        $this->normalizeHex((string) $form_state->getValue('payload')),
      );

      if (($result['accepted'] ?? FALSE) !== TRUE || empty($result['transactionHash'])) {
        $this->messenger()->addError($this->t('Signed payload was rejected: @reason', [
          '@reason' => $this->safeRejectionReason((string) ($result['reason'] ?? 'unknown_reason')),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->offers->markSigned($offer_id, (string) $result['transactionHash']);
      $this->messenger()->addStatus($this->t('Signed payload was verified. Normalized size: @bytes bytes.', [
        '@bytes' => (string) intdiv(strlen($this->normalizeHex((string) $form_state->getValue('payload'))), 2),
      ]));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException $exception) {
      $this->messenger()->addError($this->t('Signed payload verification failed: @reason', [
        '@reason' => $this->safeRejectionReason($exception->engineError ?? 'symbol_engine_error'),
      ]));
      $form_state->setRebuild(TRUE);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      $this->messenger()->addError($this->t('Signed payload verification failed: @reason', [
        '@reason' => 'verification_unavailable',
      ]));
      $form_state->setRebuild(TRUE);
    }
  }

  private function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
  }

  private function safeRejectionReason(string $reason): string {
    $reason = strtolower(trim($reason));
    if ($reason === '' || preg_match('/^[a-z0-9_.:-]{1,80}$/', $reason) !== 1) {
      return 'verification_failed';
    }
    return $reason;
  }

}
