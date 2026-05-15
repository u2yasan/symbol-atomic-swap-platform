<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolAccountPublicKeyResolverInterface;
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
    private readonly SymbolAccountPublicKeyResolverInterface $accountPublicKeyResolver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('symbol_atomic_swap.account_public_key_resolver'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_accept_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'symbol_atomic_swap/offer_form';

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
      '#attributes' => [
        'data-symbol-taker-address-form' => '1',
        'data-symbol-taker-network' => (string) $offer['network'],
      ],
    ];
    $form['taker']['recipient_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Taker recipient address'),
      '#maxlength' => 46,
      '#size' => 52,
      '#required' => TRUE,
      '#description' => $this->t('Maker payment will be sent to this address. The account must have sent at least one signed transaction on the offer network.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-taker-address' => '1',
      ],
    ];
    $form['taker']['address_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'data-symbol-taker-address-status' => '1',
        'aria-live' => 'polite',
      ],
    ];
    $form['transaction'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Transaction settings'),
    ];
    $form['transaction']['deadline_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Transaction deadline hours'),
      '#default_value' => 2,
      '#min' => 1,
      '#max' => 6,
      '#step' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Symbol aggregate complete transactions must be announced within 1 to 6 hours after this payload is generated. The maker offer itself does not expire from this value.'),
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
    $transaction = (array) $form_state->getValue('transaction', []);
    $address = strtoupper(trim((string) ($taker['recipient_address'] ?? '')));
    $deadline_hours = (int) ($transaction['deadline_hours'] ?? 0);

    if ($deadline_hours < 1 || $deadline_hours > 6) {
      $form_state->setErrorByName('transaction][deadline_hours', $this->t('Transaction deadline hours must be between 1 and 6 for aggregate complete transactions.'));
    }

    if (!$this->isNetworkAddress($address, (string) $this->offer['network'])) {
      $form_state->setErrorByName('taker][recipient_address', $this->t('Taker recipient address must be a valid raw Symbol address for the offer network.'));
      return;
    }

    try {
      $signer = $this->accountPublicKeyResolver->resolve((string) $this->offer['network'], $address);
      if (strtoupper($signer) === strtoupper((string) $this->offer['leg1_signer_public_key'])) {
        $form_state->setErrorByName('taker][recipient_address', $this->t('Taker account must differ from maker account.'));
        return;
      }
      $form_state->set('symbol_atomic_swap_taker_address', $address);
      $form_state->set('symbol_atomic_swap_taker_public_key', $signer);
    }
    catch (SymbolEngineException) {
      $form_state->setErrorByName('taker][recipient_address', $this->t('Taker recipient address must have a public key on the offer network. Use an account that has sent at least one signed transaction.'));
    }
    catch (\InvalidArgumentException) {
      $form_state->setErrorByName('taker][recipient_address', $this->t('Taker public key could not be verified.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $form_state->getValue('offer_id');
    $offer = $this->offers->find($offer_id);
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $taker = (array) $form_state->getValue('taker', []);
    $taker_address = (string) ($form_state->get('symbol_atomic_swap_taker_address') ?: strtoupper(trim((string) ($taker['recipient_address'] ?? ''))));
    $taker_public_key = (string) ($form_state->get('symbol_atomic_swap_taker_public_key') ?: $this->accountPublicKeyResolver->resolve((string) $offer['network'], $taker_address));

    $values = [
      'deadline_hours' => (int) $form_state->getValue(['transaction', 'deadline_hours']),
      'leg1_recipient_address' => $taker_address,
      'leg2_signer_public_key' => $taker_public_key,
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
