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
      '#title' => $this->t('Signed payload'),
      '#rows' => 10,
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify signed payload'),
      '#button_type' => 'primary',
      '#disabled' => empty($offer['intent_hash']),
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
    if (empty($this->offer['intent_hash']) || !$this->isHash((string) $this->offer['intent_hash'])) {
      $form_state->setErrorByName('payload', $this->t('Offer does not have a valid intent hash.'));
    }

    $payload = $this->normalizeHex((string) $form_state->getValue('payload', ''));
    if ($payload === '' || preg_match('/^[0-9A-Fa-f]+$/', $payload) !== 1 || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('payload', $this->t('Signed payload must be even-length hex.'));
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
          '@reason' => (string) ($result['reason'] ?? 'unknown reason'),
        ]));
        $form_state->setRebuild(TRUE);
        return;
      }

      $this->offers->markSigned($offer_id, (string) $result['transactionHash']);
      $this->messenger()->addStatus($this->t('Signed payload was verified.'));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
    }
    catch (SymbolEngineException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('Signed payload verification failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRebuild(TRUE);
    }
  }

  private function isHash(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{64}$/', $value) === 1;
  }

  private function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
  }

}
