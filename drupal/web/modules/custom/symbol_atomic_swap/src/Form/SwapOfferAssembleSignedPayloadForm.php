<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_engine\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferCosignatureRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_engine\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferAssembleSignedPayloadForm extends FormBase {

  private const MAX_SIGNED_PAYLOAD_HEX_LENGTH = 262144;

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
      $container->get('symbol_engine.client'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_assemble_signed_payload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;
    $stored_cosignatures = $this->cosignatures->assemblyPayloadsByOffer((int) $offer['id']);

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => (int) $offer['id'],
    ];
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Stored cosignatures'),
      '#markup' => $this->t('@count cosignature(s) will be attached.', ['@count' => (string) count($stored_cosignatures)]),
    ];
    if ($stored_cosignatures === []) {
      $form['missing_cosignatures'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#markup' => $this->t('At least one stored cosignature is required before assembly. Submit detached cosignature JSON from the non-root signer first.'),
        ],
        'action' => [
          '#type' => 'link',
          '#title' => $this->t('Submit cosignature JSON'),
          '#url' => Url::fromRoute('symbol_atomic_swap.offer_submit_cosignature', ['offerId' => $offer['id']]),
          '#attributes' => ['class' => ['button']],
        ],
      ];
    }
    $form['root_signed_payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Root signed payload'),
      '#rows' => 10,
      '#required' => TRUE,
      '#default_value' => (string) ($offer['root_signed_payload'] ?? ''),
      '#description' => $this->t('Paste the root signed transaction payload HEX created by the aggregate signer. This is not enough by itself; stored detached cosignatures from non-root signers are also required.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Assemble and verify signed payload'),
      '#button_type' => 'primary',
      '#disabled' => !$this->offers->canSubmitSignedPayload($offer) || $stored_cosignatures === [],
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
      $form_state->setErrorByName('root_signed_payload', $this->t('Signed payload assembly is only available for payload-generated or already signed settlements with a valid intent hash.'));
    }

    $payload = $this->normalizeHex((string) $form_state->getValue('root_signed_payload', ''));
    $form_state->set('symbol_atomic_swap_root_signed_payload', $payload);
    if ($payload === '' || preg_match('/^[0-9A-Fa-f]+$/', $payload) !== 1 || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('root_signed_payload', $this->t('Root signed payload must be even-length hex.'));
    }
    if (strlen($payload) > self::MAX_SIGNED_PAYLOAD_HEX_LENGTH) {
      $form_state->setErrorByName('root_signed_payload', $this->t('Root signed payload is too large.'));
    }
    if ($this->cosignatures->assemblyPayloadsByOffer((int) $this->offer['id']) === []) {
      $form_state->setErrorByName('root_signed_payload', $this->t('At least one stored cosignature is required before assembly.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }

    try {
      $result = $this->engineClient->assembleCompletePayload(
        (string) $offer['intent_hash'],
        (string) $form_state->get('symbol_atomic_swap_root_signed_payload'),
        $this->cosignatures->assemblyPayloadsByOffer($offer_id),
      );
      if (($result['accepted'] ?? FALSE) !== TRUE || empty($result['transactionHash'])) {
        $this->messenger()->addError($this->t('Signed payload assembly failed: @reason', [
          '@reason' => $this->safeRejectionReason((string) ($result['reason'] ?? 'unknown_reason')),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->offers->markSigned($offer_id, (string) $result['transactionHash']);
      $this->messenger()->addStatus($this->t('Signed payload was assembled and verified.'));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException $exception) {
      $this->messenger()->addError($this->t('Signed payload assembly failed: @reason', [
        '@reason' => $this->safeRejectionReason($this->engineFailureReason($exception)),
      ]));
      $form_state->setRebuild(TRUE);
    }
    catch (\InvalidArgumentException | \RuntimeException) {
      $this->messenger()->addError($this->t('Signed payload assembly failed: @reason', [
        '@reason' => 'assembly_unavailable',
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
      return 'assembly_failed';
    }
    return $reason;
  }

  private function engineFailureReason(SymbolEngineException $exception): string {
    $reason = $exception->details['reason'] ?? $exception->engineError ?? 'symbol_engine_error';
    return is_string($reason) ? $reason : 'symbol_engine_error';
  }

}
