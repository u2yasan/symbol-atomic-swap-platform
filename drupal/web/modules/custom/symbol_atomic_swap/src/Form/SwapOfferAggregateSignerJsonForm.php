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

final class SwapOfferAggregateSignerJsonForm extends FormBase {

  private const MAX_SIGNATURE_JSON_LENGTH = 8192;

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
    return 'symbol_atomic_swap_offer_aggregate_signer_json_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    $form['intent_hash'] = [
      '#type' => 'item',
      '#title' => $this->t('Intent hash'),
      '#markup' => $offer['intent_hash'] ?: $this->t('No intent hash has been generated.'),
    ];
    $form['signature_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Aggregate signer JSON'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Paste the JSON returned by Symbol Desktop Wallet when the aggregate signer signs the unsigned payload. The signerPublicKey must be the first transfer leg signer. Wrapped Desktop Wallet copies are normalized.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Build and verify root signed payload'),
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
      $form_state->setErrorByName('signature_json', $this->t('Root signed payload can only be built for QR-generated or already signed offers with a valid intent hash.'));
    }

    $raw = trim((string) $form_state->getValue('signature_json', ''));
    if ($raw === '' || strlen($raw) > self::MAX_SIGNATURE_JSON_LENGTH) {
      $form_state->setErrorByName('signature_json', $this->t('Aggregate signer JSON is required and must be smaller than @bytes bytes.', [
        '@bytes' => (string) self::MAX_SIGNATURE_JSON_LENGTH,
      ]));
      return;
    }

    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($decoded)) {
        $form_state->setErrorByName('signature_json', $this->t('Aggregate signer JSON must be an object.'));
        return;
      }
      $normalized = $this->normalizeSignatureJson($decoded);
      if (!$this->hasRequiredSignatureFields($normalized)) {
        $form_state->setErrorByName('signature_json', $this->t('Aggregate signer JSON must include parentHash, signerPublicKey, and signature.'));
        return;
      }
      $form_state->set('symbol_atomic_swap_aggregate_signer_json', $normalized);
    }
    catch (\JsonException) {
      $form_state->setErrorByName('signature_json', $this->t('Aggregate signer JSON is malformed.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }

    $signature_json = $form_state->get('symbol_atomic_swap_aggregate_signer_json');
    if (!is_array($signature_json)) {
      $this->messenger()->addError($this->t('Root signed payload build failed: @reason', ['@reason' => 'invalid_json']));
      $form_state->setRebuild(TRUE);
      return;
    }

    try {
      $result = $this->engineClient->buildRootSignedPayload((string) $offer['intent_hash'], $signature_json);
      if (($result['accepted'] ?? FALSE) !== TRUE || empty($result['transactionHash'])) {
        $this->messenger()->addError($this->t('Root signed payload build failed: @reason', [
          '@reason' => $this->safeRejectionReason((string) ($result['reason'] ?? 'unknown_reason')),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->offers->markSigned($offer_id, (string) $result['transactionHash']);
      $this->messenger()->addStatus($this->t('Root signed payload was built and verified.'));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException $exception) {
      $this->messenger()->addError($this->t('Root signed payload build failed: @reason', [
        '@reason' => $this->safeRejectionReason($this->engineFailureReason($exception)),
      ]));
      $form_state->setRebuild(TRUE);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      $this->messenger()->addError($this->t('Root signed payload build failed: @reason', [
        '@reason' => 'build_unavailable',
      ]));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * @param array<string, mixed> $signature_json
   *
   * @return array<string, mixed>
   */
  private function normalizeSignatureJson(array $signature_json): array {
    $normalized = [];
    foreach ($signature_json as $key => $value) {
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
   * @param array<string, mixed> $signature_json
   */
  private function hasRequiredSignatureFields(array $signature_json): bool {
    return isset($signature_json['parentHash'], $signature_json['signerPublicKey'], $signature_json['signature'])
      && is_string($signature_json['parentHash'])
      && is_string($signature_json['signerPublicKey'])
      && is_string($signature_json['signature'])
      && $signature_json['parentHash'] !== ''
      && $signature_json['signerPublicKey'] !== ''
      && $signature_json['signature'] !== '';
  }

  private function safeRejectionReason(string $reason): string {
    $reason = strtolower(trim(preg_replace('/\s+/', ' ', $reason) ?? ''));
    if ($reason === '' || preg_match('/^[a-z0-9_.:, -]{1,160}$/', $reason) !== 1) {
      return 'build_failed';
    }
    return $reason;
  }

  private function engineFailureReason(SymbolEngineException $exception): string {
    $reason = $exception->details['reason'] ?? $exception->engineError ?? 'symbol_engine_error';
    return is_string($reason) ? $reason : 'symbol_engine_error';
  }

}
