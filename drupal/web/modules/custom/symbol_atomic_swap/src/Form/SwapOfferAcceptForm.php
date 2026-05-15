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

final class SwapOfferAcceptForm extends FormBase {

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
    return 'symbol_atomic_swap_offer_accept_form';
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
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Offer terms'),
      '#markup' => $this->t('Maker pays @pay_amount of @pay_mosaic and wants @want_amount of @want_mosaic.', [
        '@pay_amount' => (string) $offer['leg1_amount'],
        '@pay_mosaic' => (string) $offer['leg1_mosaic_id'],
        '@want_amount' => (string) $offer['leg2_amount'],
        '@want_mosaic' => (string) $offer['leg2_mosaic_id'],
      ]),
    ];
    $form['taker'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Taker details'),
    ];
    $form['taker']['signer_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Taker public key'),
      '#maxlength' => 64,
      '#size' => 72,
      '#required' => TRUE,
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{64}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['taker']['recipient_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Taker recipient address'),
      '#maxlength' => 46,
      '#size' => 52,
      '#required' => TRUE,
      '#description' => $this->t('Maker payment will be sent to this address.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Accept and build QR'),
      '#button_type' => 'primary',
      '#disabled' => !$this->offers->canAccept($offer),
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
    if (!$this->offers->canAccept($this->offer)) {
      $form_state->setErrorByName('taker][signer_public_key', $this->t('Only open offers can be accepted.'));
    }
    $taker = (array) $form_state->getValue('taker', []);
    $signer = trim((string) ($taker['signer_public_key'] ?? ''));
    $address = trim((string) ($taker['recipient_address'] ?? ''));

    if (!$this->isHash($signer)) {
      $form_state->setErrorByName('taker][signer_public_key', $this->t('Taker public key must be 64 hex characters.'));
    }
    if (strtoupper($signer) === strtoupper((string) $this->offer['leg1_signer_public_key'])) {
      $form_state->setErrorByName('taker][signer_public_key', $this->t('Taker public key must differ from maker public key.'));
    }
    if (!$this->isNetworkAddress($address, (string) $this->offer['network'])) {
      $form_state->setErrorByName('taker][recipient_address', $this->t('Taker recipient address must be a valid raw Symbol address for the offer network.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $taker = (array) $form_state->getValue('taker', []);

    $values = [
      'leg1_recipient_address' => strtoupper(trim((string) $taker['recipient_address'])),
      'leg2_signer_public_key' => strtoupper(trim((string) $taker['signer_public_key'])),
      'intent_hash' => NULL,
      'unsigned_payload' => NULL,
      'qr_payload' => NULL,
      'transaction_hash' => NULL,
    ];
    $this->offers->accept($offer_id, $values);
    $accepted = $this->offers->find($offer_id);
    if (!$accepted) {
      throw new \RuntimeException('Accepted offer was not found.');
    }

    try {
      $engine_result = $this->engineClient->buildAggregateComplete($this->offers->toEngineBuildPayload($accepted));
      $this->offers->update($offer_id, $this->offers->engineFields($accepted, $engine_result) + [
        'changed' => \Drupal::time()->getRequestTime(),
      ]);
      $this->messenger()->addStatus($this->t('Trade offer was accepted and QR payload was generated.'));
    }
    catch (SymbolEngineException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('Trade offer was accepted, but Symbol Engine build failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
  }

  private function isHash(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{64}$/', $value) === 1;
  }

  private function isNetworkAddress(string $value, string $network): bool {
    $value = strtoupper(trim($value));
    $prefix = match ($network) {
      'mainnet' => 'N',
      'testnet' => 'T',
      default => '',
    };
    return $prefix !== '' && preg_match('/^' . $prefix . '[A-Z2-7]{38}$/', $value) === 1;
  }

}
