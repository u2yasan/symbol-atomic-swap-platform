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
use Drupal\symbol_atomic_swap\Service\SymbolAccountPublicKeyResolverInterface;
use Drupal\symbol_atomic_swap\Service\SymbolAddressDeriver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferForm extends FormBase {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly UuidInterface $uuid,
    private readonly AccountProxyInterface $currentUser,
    private readonly SymbolAddressDeriver $addressDeriver,
    private readonly SymbolAccountPublicKeyResolverInterface $accountPublicKeyResolver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('uuid'),
      $container->get('current_user'),
      $container->get('symbol_atomic_swap.address_deriver'),
      $container->get('symbol_atomic_swap.account_public_key_resolver'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'symbol_atomic_swap/offer_form';
    $form['#attributes']['data-symbol-maker-address-form'] = '1';

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
      '#attributes' => [
        'data-symbol-maker-network' => '1',
      ],
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

    $form['maker_pays'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Maker pays'),
    ];
    $form['maker_pays']['address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maker address'),
      '#maxlength' => 46,
      '#size' => 52,
      '#required' => TRUE,
      '#default_value' => $this->defaultMakerAddress($offer),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-maker-address' => '1',
      ],
    ];
    $form['maker_pays']['signer_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resolved maker public key'),
      '#maxlength' => 64,
      '#size' => 72,
      '#default_value' => $offer['leg1_signer_public_key'] ?? '',
      '#description' => $this->t('Resolved from Maker address. The account must have sent at least one signed transaction on the selected network.'),
      '#attributes' => [
        'readonly' => 'readonly',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'data-symbol-maker-public-key' => '1',
      ],
    ];
    $form['maker_pays']['mosaic_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mosaic ID'),
      '#maxlength' => 16,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => $offer['leg1_mosaic_id'] ?? '',
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{16}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['maker_pays']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#maxlength' => 32,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => $offer['leg1_amount'] ?? '',
      '#attributes' => [
        'pattern' => '[1-9][0-9]*',
        'autocomplete' => 'off',
      ],
    ];

    $form['maker_wants'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Maker wants'),
    ];
    $form['maker_wants']['recipient_address'] = [
      '#type' => 'hidden',
      '#default_value' => $this->defaultMakerAddress($offer),
    ];
    $form['maker_wants']['recipient_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Maker recipient address'),
      '#markup' => $this->t('Same as Maker address.'),
      '#description' => $this->t('Taker payment will be sent to Maker address; it is not entered separately.'),
    ];
    $form['maker_pays']['address_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'data-symbol-maker-address-status' => '1',
        'aria-live' => 'polite',
      ],
    ];
    $form['maker_wants']['mosaic_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mosaic ID'),
      '#maxlength' => 16,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => $offer['leg2_mosaic_id'] ?? '',
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{16}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['maker_wants']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#maxlength' => 32,
      '#size' => 24,
      '#required' => TRUE,
      '#default_value' => $offer['leg2_amount'] ?? '',
      '#attributes' => [
        'pattern' => '[1-9][0-9]*',
        'autocomplete' => 'off',
      ],
    ];

    if ($offer && !$this->offers->canAccept($offer)) {
      for ($index = 1; $index <= 2; $index++) {
        $form['leg_' . $index] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Transfer leg @number', ['@number' => $index]),
        ];
        $form['leg_' . $index]['signer_public_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Transfer leg @number signer public key', ['@number' => $index]),
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
        '#title' => $this->t('Transfer leg @number recipient address', ['@number' => $index]),
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
        '#title' => $this->t('Transfer leg @number mosaic ID', ['@number' => $index]),
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
        '#title' => $this->t('Transfer leg @number amount', ['@number' => $index]),
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
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $offer ? $this->t('Save offer') : $this->t('Create trade offer'),
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
    $network = (string) $form_state->getValue('network');
    $offer_id = $form_state->getValue('offer_id');

    if (strlen($correlation_id) < 8 || strlen($correlation_id) > 128) {
      $form_state->setErrorByName('correlation_id', $this->t('Correlation ID must be 8 to 128 characters.'));
    }
    if ($deadline_hours < 1 || $deadline_hours > 48) {
      $form_state->setErrorByName('deadline_hours', $this->t('Deadline hours must be between 1 and 48.'));
    }
    if ($network === 'mainnet' && !$this->config('symbol_atomic_swap.settings')->get('mainnet_enabled')) {
      $form_state->setErrorByName('network', $this->t('Mainnet operations are disabled in Symbol Atomic Swap settings.'));
    }
    if ($correlation_id !== '' && $this->offers->existsByNetworkCorrelationId($network, $correlation_id, $offer_id ? (int) $offer_id : NULL)) {
      $form_state->setErrorByName('correlation_id', $this->t('Correlation ID is already used for this network.'));
    }
    if ($max_fee !== '' && !$this->isPositiveInteger($max_fee)) {
      $form_state->setErrorByName('max_fee', $this->t('Max fee must be a positive integer.'));
    }

    $maker_pays = (array) $form_state->getValue('maker_pays', []);
    $maker_wants = (array) $form_state->getValue('maker_wants', []);
    $maker_address = strtoupper(trim((string) ($maker_pays['address'] ?? '')));

    if (!$this->isNetworkAddress($maker_address, $network)) {
      $form_state->setErrorByName('maker_pays][address', $this->t('Maker address must be a valid raw Symbol address for the selected network.'));
    }
    else {
      try {
        $maker_public_key = $this->resolveMakerPublicKey($maker_address, $network);
        $form_state->set('symbol_atomic_swap_maker_address', $maker_address);
        $form_state->set('symbol_atomic_swap_maker_public_key', $maker_public_key);
      }
      catch (SymbolEngineException) {
        $form_state->setErrorByName('maker_pays][address', $this->t('Maker address must have a public key on the selected network. Use an account that has sent at least one signed transaction.'));
      }
      catch (\InvalidArgumentException) {
        $form_state->setErrorByName('maker_pays][address', $this->t('Maker address public key could not be verified.'));
      }
    }
    if (!$this->isMosaicId(trim((string) ($maker_pays['mosaic_id'] ?? '')))) {
      $form_state->setErrorByName('maker_pays][mosaic_id', $this->t('Maker pays mosaic ID must be 16 hex characters.'));
    }
    if (!$this->isPositiveInteger(trim((string) ($maker_pays['amount'] ?? '')))) {
      $form_state->setErrorByName('maker_pays][amount', $this->t('Maker pays amount must be a positive integer.'));
    }
    if (!$this->isMosaicId(trim((string) ($maker_wants['mosaic_id'] ?? '')))) {
      $form_state->setErrorByName('maker_wants][mosaic_id', $this->t('Maker wants mosaic ID must be 16 hex characters.'));
    }
    if (!$this->isPositiveInteger(trim((string) ($maker_wants['amount'] ?? '')))) {
      $form_state->setErrorByName('maker_wants][amount', $this->t('Maker wants amount must be a positive integer.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = $form_state->getValue('offer_id');
    $now = \Drupal::time()->getRequestTime();
    $values = $this->offerValues($form_state);
    $values['changed'] = $now;

    if ($offer_id) {
      $this->offers->update((int) $offer_id, $values + [
        'state' => 'open',
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
        'state' => 'open',
        'uid' => (int) $this->currentUser->id(),
        'created' => $now,
      ];
      $id = $this->offers->insert($values);
    }

    $this->messenger()->addStatus($this->t('Trade offer was saved. It will generate an unsigned payload after a taker accepts it.'));
    $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $id]);
  }

  /**
   * @return array<string, mixed>
   */
  private function offerValues(FormStateInterface $form_state): array {
    $maker_pays = (array) $form_state->getValue('maker_pays', []);
    $maker_wants = (array) $form_state->getValue('maker_wants', []);
    $max_fee = trim((string) $form_state->getValue('max_fee', ''));
    $network = (string) $form_state->getValue('network');
    $maker_address = (string) ($form_state->get('symbol_atomic_swap_maker_address') ?: strtoupper(trim((string) ($maker_pays['address'] ?? ''))));
    $maker_public_key = (string) ($form_state->get('symbol_atomic_swap_maker_public_key') ?: $this->resolveMakerPublicKey($maker_address, $network));

    return [
      'label' => trim((string) $form_state->getValue('label')),
      'network' => $network,
      'correlation_id' => trim((string) $form_state->getValue('correlation_id')),
      'deadline_hours' => (int) $form_state->getValue('deadline_hours'),
      'max_fee' => $max_fee !== '' ? $max_fee : NULL,
      'leg1_signer_public_key' => $maker_public_key,
      'leg1_recipient_address' => '',
      'leg1_mosaic_id' => strtoupper(trim((string) $maker_pays['mosaic_id'])),
      'leg1_amount' => trim((string) $maker_pays['amount']),
      'leg2_signer_public_key' => '',
      'leg2_recipient_address' => $maker_address,
      'leg2_mosaic_id' => strtoupper(trim((string) $maker_wants['mosaic_id'])),
      'leg2_amount' => trim((string) $maker_wants['amount']),
    ];
  }

  private function isHash(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{64}$/', $value) === 1;
  }

  private function isMosaicId(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{16}$/', $value) === 1;
  }

  private function isNetworkAddress(string $value, string $network): bool {
    $prefix = match ($network) {
      'mainnet' => 'N',
      'testnet' => 'T',
      default => '',
    };
    return $prefix !== '' && preg_match('/^' . $prefix . '[A-Z2-7]{38}$/', strtoupper(trim($value))) === 1;
  }

  private function isPositiveInteger(string $value): bool {
    return preg_match('/^[1-9][0-9]*$/', $value) === 1;
  }

  private function resolveMakerPublicKey(string $maker_address, string $network): string {
    return $this->accountPublicKeyResolver->resolve($network, $maker_address);
  }

  /**
   * @param array<string, mixed>|null $offer
   */
  private function defaultMakerAddress(?array $offer): string {
    if (!$offer) {
      return '';
    }

    try {
      return $this->addressDeriver->deriveFromPublicKey(
        (string) ($offer['leg1_signer_public_key'] ?? ''),
        (string) ($offer['network'] ?? ''),
      );
    }
    catch (\InvalidArgumentException) {
      return (string) ($offer['leg2_recipient_address'] ?? '');
    }
  }

}
