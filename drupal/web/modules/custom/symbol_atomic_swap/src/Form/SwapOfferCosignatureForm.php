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

final class SwapOfferCosignatureForm extends FormBase {

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
    return 'symbol_atomic_swap_offer_cosignature_form';
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
    $form['payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cosignature JSON'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Paste the JSON returned by Symbol Desktop Wallet after cosigning. This stores a verified detached cosignature; it is not the final signed payload.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify and store cosignature'),
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
      $form_state->set('symbol_atomic_swap_cosignature', $decoded);
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
      $this->messenger()->addError($this->t('Cosignature verification failed: @reason', ['@reason' => 'invalid_json']));
      $form_state->setRebuild(TRUE);
      return;
    }

    try {
      $result = $this->engineClient->verifyCosignature((string) $offer['intent_hash'], $cosignature);
      if (($result['accepted'] ?? FALSE) !== TRUE) {
        $this->messenger()->addError($this->t('Cosignature was rejected: @reason', [
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
      $this->messenger()->addStatus($this->t('Cosignature was verified and stored.'));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException $exception) {
      $this->messenger()->addError($this->t('Cosignature verification failed: @reason', [
        '@reason' => $this->safeRejectionReason($exception->engineError ?? 'symbol_engine_error'),
      ]));
      $form_state->setRebuild(TRUE);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      $this->messenger()->addError($this->t('Cosignature verification failed: @reason', [
        '@reason' => 'verification_unavailable',
      ]));
      $form_state->setRebuild(TRUE);
    }
  }

  private function safeRejectionReason(string $reason): string {
    $reason = strtolower(trim($reason));
    if ($reason === '' || preg_match('/^[a-z0-9_.:-]{1,100}$/', $reason) !== 1) {
      return 'verification_failed';
    }
    return $reason;
  }

}
