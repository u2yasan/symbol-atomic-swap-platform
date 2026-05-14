<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferForm extends FormBase {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SymbolEngineClient $engineClient,
    private readonly UuidInterface $uuid,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('uuid'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer_id = $offerId !== NULL ? (int) $offerId : NULL;
    $offer = $offer_id ? $this->offers->find($offer_id) : NULL;
    if ($offerId && !$offer) {
      throw new NotFoundHttpException();
    }

    $form['offer_id'] = [
      '#type' => 'value',
      '#value' => $offer_id,
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Offer label'),
      '#maxlength' => 128,
      '#required' => TRUE,
      '#default_value' => $offer['label'] ?? '',
    ];
    $form['network'] = [
      '#type' => 'select',
      '#title' => $this->t('Network'),
      '#options' => [
        'testnet' => $this->t('Testnet'),
        'mainnet' => $this->t('Mainnet'),
      ],
      '#default_value' => $offer['network'] ?? 'testnet',
      '#description' => $this->config('symbol_atomic_swap.settings')->get('mainnet_enabled')
        ? $this->t('Mainnet operations are enabled. Verify all transaction terms before building QR payloads.')
        : $this->t('Mainnet operations are disabled in Symbol Atomic Swap settings.'),
    ];
    $form['correlation_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Correlation ID'),
      '#maxlength' => 128,
      '#required' => TRUE,
      '#default_value' => $offer['correlation_id'] ?? '',
    ];
    $form['deadline_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadline hours'),
      '#default_value' => $offer['deadline_hours'] ?? 2,
      '#min' => 1,
      '#max' => 48,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['max_fee'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Max fee'),
      '#maxlength' => 32,
      '#size' => 24,
      '#default_value' => $offer['max_fee'] ?? '',
      '#description' => $this->t('Optional positive integer. Leave empty to use Engine default.'),
      '#attributes' => [
        'pattern' => '[1-9][0-9]*',
        'autocomplete' => 'off',
      ],
    ];

    for ($index = 1; $index <= 2; $index++) {
      $form['leg_' . $index] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Transfer leg @number', ['@number' => $index]),
      ];
      $form['leg_' . $index]['signer_public_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Signer public key'),
        '#maxlength' => 64,
        '#size' => 72,
        '#required' => TRUE,
        '#default_value' => $offer['leg' . $index . '_signer_public_key'] ?? '',
        '#attributes' => [
          'pattern' => '[0-9A-Fa-f]{64}',
          'autocomplete' => 'off',
          'spellcheck' => 'false',
        ],
      ];
      $form['leg_' . $index]['recipient_address'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Recipient address'),
        '#maxlength' => 46,
        '#size' => 52,
        '#required' => TRUE,
        '#default_value' => $offer['leg' . $index . '_recipient_address'] ?? '',
        '#attributes' => [
          'autocomplete' => 'off',
          'spellcheck' => 'false',
        ],
      ];
      $form['leg_' . $index]['mosaic_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Mosaic ID'),
        '#maxlength' => 16,
        '#size' => 24,
        '#required' => TRUE,
        '#default_value' => $offer['leg' . $index . '_mosaic_id'] ?? '',
        '#attributes' => [
          'pattern' => '[0-9A-Fa-f]{16}',
          'autocomplete' => 'off',
          'spellcheck' => 'false',
        ],
      ];
      $form['leg_' . $index]['amount'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Amount'),
        '#maxlength' => 32,
        '#size' => 24,
        '#required' => TRUE,
        '#default_value' => $offer['leg' . $index . '_amount'] ?? '',
        '#attributes' => [
          'pattern' => '[1-9][0-9]*',
          'autocomplete' => 'off',
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $offer ? $this->t('Save and rebuild QR') : $this->t('Create and build QR'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('symbol_atomic_swap.offer_list'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $correlation_id = trim((string) $form_state->getValue('correlation_id', ''));
    $deadline_hours = (int) $form_state->getValue('deadline_hours', 0);
    $max_fee = trim((string) $form_state->getValue('max_fee', ''));

    if (strlen($correlation_id) < 8 || strlen($correlation_id) > 128) {
      $form_state->setErrorByName('correlation_id', $this->t('Correlation ID must be 8 to 128 characters.'));
    }
    if ($deadline_hours < 1 || $deadline_hours > 48) {
      $form_state->setErrorByName('deadline_hours', $this->t('Deadline hours must be between 1 and 48.'));
    }
    if ((string) $form_state->getValue('network') === 'mainnet' && !$this->config('symbol_atomic_swap.settings')->get('mainnet_enabled')) {
      $form_state->setErrorByName('network', $this->t('Mainnet operations are disabled in Symbol Atomic Swap settings.'));
    }
    if ($max_fee !== '' && !$this->isPositiveInteger($max_fee)) {
      $form_state->setErrorByName('max_fee', $this->t('Max fee must be a positive integer.'));
    }

    $signers = [];
    for ($index = 1; $index <= 2; $index++) {
      $leg = (array) $form_state->getValue('leg_' . $index, []);
      $signer = trim((string) ($leg['signer_public_key'] ?? ''));
      $address = trim((string) ($leg['recipient_address'] ?? ''));
      $mosaic_id = trim((string) ($leg['mosaic_id'] ?? ''));
      $amount = trim((string) ($leg['amount'] ?? ''));

      if (!$this->isHash($signer)) {
        $form_state->setErrorByName("leg_$index][signer_public_key", $this->t('Signer public key must be 64 hex characters.'));
      }
      if (strlen($address) < 39 || strlen($address) > 46) {
        $form_state->setErrorByName("leg_$index][recipient_address", $this->t('Recipient address must be 39 to 46 characters.'));
      }
      if (!$this->isMosaicId($mosaic_id)) {
        $form_state->setErrorByName("leg_$index][mosaic_id", $this->t('Mosaic ID must be 16 hex characters.'));
      }
      if (!$this->isPositiveInteger($amount)) {
        $form_state->setErrorByName("leg_$index][amount", $this->t('Amount must be a positive integer.'));
      }
      $signers[] = strtoupper($signer);
    }

    if (count(array_unique($signers)) !== 2) {
      $form_state->setErrorByName('leg_2][signer_public_key', $this->t('Signer public keys must be distinct.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = $form_state->getValue('offer_id');
    $now = \Drupal::time()->getRequestTime();
    $values = $this->offerValues($form_state);
    $values['changed'] = $now;

    if ($offer_id) {
      $this->offers->update((int) $offer_id, $values + [
        'state' => 'draft',
        'intent_hash' => NULL,
        'unsigned_payload' => NULL,
        'qr_payload' => NULL,
        'transaction_hash' => NULL,
      ]);
      $id = (int) $offer_id;
    }
    else {
      $values += [
        'uuid' => $this->uuid->generate(),
        'state' => 'draft',
        'uid' => (int) $this->currentUser->id(),
        'created' => $now,
      ];
      $id = $this->offers->insert($values);
    }

    $offer = $this->offers->find($id);
    if (!$offer) {
      throw new \RuntimeException('Swap offer was not saved.');
    }

    try {
      $engine_result = $this->engineClient->buildAggregateComplete($this->offers->toEngineBuildPayload($offer));
      $this->offers->update($id, $this->offers->engineFields($offer, $engine_result) + [
        'changed' => \Drupal::time()->getRequestTime(),
      ]);
      $this->messenger()->addStatus($this->t('Swap offer was saved and QR payload was generated.'));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $id]);
    }
    catch (SymbolEngineException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('Swap offer was saved as draft, but Symbol Engine build failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRedirect('symbol_atomic_swap.offer_edit', ['offerId' => $id]);
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function offerValues(FormStateInterface $form_state): array {
    $leg1 = (array) $form_state->getValue('leg_1', []);
    $leg2 = (array) $form_state->getValue('leg_2', []);
    $max_fee = trim((string) $form_state->getValue('max_fee', ''));

    return [
      'label' => trim((string) $form_state->getValue('label')),
      'network' => (string) $form_state->getValue('network'),
      'correlation_id' => trim((string) $form_state->getValue('correlation_id')),
      'deadline_hours' => (int) $form_state->getValue('deadline_hours'),
      'max_fee' => $max_fee !== '' ? $max_fee : NULL,
      'leg1_signer_public_key' => strtoupper(trim((string) $leg1['signer_public_key'])),
      'leg1_recipient_address' => trim((string) $leg1['recipient_address']),
      'leg1_mosaic_id' => strtoupper(trim((string) $leg1['mosaic_id'])),
      'leg1_amount' => trim((string) $leg1['amount']),
      'leg2_signer_public_key' => strtoupper(trim((string) $leg2['signer_public_key'])),
      'leg2_recipient_address' => trim((string) $leg2['recipient_address']),
      'leg2_mosaic_id' => strtoupper(trim((string) $leg2['mosaic_id'])),
      'leg2_amount' => trim((string) $leg2['amount']),
    ];
  }

  private function isHash(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{64}$/', $value) === 1;
  }

  private function isMosaicId(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{16}$/', $value) === 1;
  }

  private function isPositiveInteger(string $value): bool {
    return preg_match('/^[1-9][0-9]*$/', $value) === 1;
  }

}
