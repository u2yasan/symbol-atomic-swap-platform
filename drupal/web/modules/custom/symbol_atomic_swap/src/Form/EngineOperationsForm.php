<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EngineOperationsForm extends FormBase {

  public function __construct(
    private readonly SymbolEngineClient $engineClient,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.engine_client'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_engine_operations_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#markup' => '<p>Manual Symbol Engine operations. Drupal never signs transactions and never stores private keys.</p>',
    ];

    $form['build'] = [
      '#type' => 'details',
      '#title' => $this->t('Build Aggregate Complete'),
      '#open' => TRUE,
    ];
    $form['build']['network'] = [
      '#type' => 'select',
      '#title' => $this->t('Network'),
      '#options' => [
        'testnet' => $this->t('Testnet'),
        'mainnet' => $this->t('Mainnet'),
      ],
      '#default_value' => 'testnet',
    ];
    $form['build']['correlation_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Correlation ID'),
      '#maxlength' => 128,
      '#size' => 48,
    ];
    $form['build']['deadline_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadline hours'),
      '#default_value' => 2,
      '#min' => 1,
      '#max' => 6,
      '#step' => 1,
      '#description' => $this->t('Aggregate complete transactions must use a Symbol transaction deadline between 1 and 6 hours.'),
    ];
    for ($index = 0; $index < 2; $index++) {
      $leg_key = 'leg_' . ($index + 1);
      $form['build'][$leg_key] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Transfer leg @number', ['@number' => $index + 1]),
      ];
      $form['build'][$leg_key]['signer_public_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Signer public key'),
        '#maxlength' => 64,
        '#size' => 72,
        '#attributes' => [
          'pattern' => '[0-9A-Fa-f]{64}',
          'autocomplete' => 'off',
          'spellcheck' => 'false',
        ],
      ];
      $form['build'][$leg_key]['recipient_address'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Recipient address'),
        '#maxlength' => 46,
        '#size' => 52,
        '#attributes' => [
          'autocomplete' => 'off',
          'spellcheck' => 'false',
        ],
      ];
      $form['build'][$leg_key]['mosaic_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Mosaic ID'),
        '#maxlength' => 16,
        '#size' => 24,
        '#attributes' => [
          'pattern' => '[0-9A-Fa-f]{16}',
          'autocomplete' => 'off',
          'spellcheck' => 'false',
        ],
      ];
      $form['build'][$leg_key]['amount'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Amount'),
        '#maxlength' => 32,
        '#size' => 24,
        '#attributes' => [
          'pattern' => '[1-9][0-9]*',
          'autocomplete' => 'off',
        ],
      ];
    }

    $form['build']['actions'] = [
      '#type' => 'actions',
    ];
    $form['build']['actions']['build'] = [
      '#type' => 'submit',
      '#value' => $this->t('Build unsigned transaction'),
      '#submit' => ['::submitBuild'],
      '#validate' => ['::validateBuild'],
    ];

    $form['verify'] = [
      '#type' => 'details',
      '#title' => $this->t('Verify Signed Payload'),
      '#open' => TRUE,
    ];
    $form['verify']['intent_hash'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Intent hash'),
      '#maxlength' => 64,
      '#size' => 72,
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{64}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['verify']['payload'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Signed payload'),
      '#rows' => 8,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['verify']['actions'] = [
      '#type' => 'actions',
    ];
    $form['verify']['actions']['verify'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify signed payload'),
      '#submit' => ['::submitVerify'],
      '#validate' => ['::validateVerify'],
    ];

    $form['announce'] = [
      '#type' => 'details',
      '#title' => $this->t('Announce Verified Transaction'),
      '#open' => TRUE,
    ];
    $form['announce']['intent_hash'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Intent hash'),
      '#maxlength' => 64,
      '#size' => 72,
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{64}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['announce']['actions'] = [
      '#type' => 'actions',
    ];
    $form['announce']['actions']['announce'] = [
      '#type' => 'submit',
      '#value' => $this->t('Announce transaction'),
      '#submit' => ['::submitAnnounce'],
      '#validate' => ['::validateAnnounce'],
    ];

    $result = $form_state->get('symbol_atomic_swap_operation_result');
    if (is_array($result)) {
      $form['#attached']['library'][] = 'symbol_atomic_swap/qr';
      $form['result'] = [
        '#type' => 'details',
        '#title' => $this->t('Result'),
        '#open' => TRUE,
      ];
      if (isset($result['qrPayload']) && is_array($result['qrPayload'])) {
        $form['result']['qr'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['symbol-atomic-swap-qr'],
            'data-qr-payload' => json_encode($result['qrPayload'], JSON_UNESCAPED_SLASHES),
          ],
        ];
      }
      $form['result']['payload'] = [
        '#type' => 'textarea',
        '#title' => $this->t('JSON'),
        '#value' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        '#rows' => 24,
        '#attributes' => [
          'readonly' => 'readonly',
          'spellcheck' => 'false',
        ],
      ];
    }

    return $form;
  }

  public function validateBuild(array &$form, FormStateInterface $form_state): void {
    $build = (array) $form_state->getValue('build', []);
    $correlation_id = trim((string) ($build['correlation_id'] ?? ''));
    $deadline_hours = (int) ($build['deadline_hours'] ?? 0);

    if (strlen($correlation_id) < 8 || strlen($correlation_id) > 128) {
      $form_state->setErrorByName('build][correlation_id', $this->t('Correlation ID must be 8 to 128 characters.'));
    }
    if ($deadline_hours < 1 || $deadline_hours > 6) {
      $form_state->setErrorByName('build][deadline_hours', $this->t('Deadline hours must be between 1 and 6 for aggregate complete transactions.'));
    }
    $signers = [];
    for ($index = 1; $index <= 2; $index++) {
      $leg_key = 'leg_' . $index;
      $leg = (array) ($build[$leg_key] ?? []);
      $signer = trim((string) ($leg['signer_public_key'] ?? ''));
      $address = trim((string) ($leg['recipient_address'] ?? ''));
      $mosaic_id = trim((string) ($leg['mosaic_id'] ?? ''));
      $amount = trim((string) ($leg['amount'] ?? ''));

      if (!$this->isHash($signer)) {
        $form_state->setErrorByName("build][$leg_key][signer_public_key", $this->t('Signer public key must be 64 hex characters.'));
      }
      if (strlen($address) < 39 || strlen($address) > 46) {
        $form_state->setErrorByName("build][$leg_key][recipient_address", $this->t('Recipient address must be 39 to 46 characters.'));
      }
      if (!$this->isMosaicId($mosaic_id)) {
        $form_state->setErrorByName("build][$leg_key][mosaic_id", $this->t('Mosaic ID must be 16 hex characters.'));
      }
      if (!$this->isPositiveInteger($amount)) {
        $form_state->setErrorByName("build][$leg_key][amount", $this->t('Amount must be a positive integer.'));
      }

      $signers[] = strtoupper($signer);
    }

    if (count(array_unique($signers)) !== 2) {
      $form_state->setErrorByName('build][leg_2][signer_public_key', $this->t('Signer public keys must be distinct.'));
    }
  }

  public function validateVerify(array &$form, FormStateInterface $form_state): void {
    $intent_hash = trim((string) $form_state->getValue(['verify', 'intent_hash'], ''));
    $payload = $this->normalizeHex((string) $form_state->getValue(['verify', 'payload'], ''));

    if (!$this->isHash($intent_hash)) {
      $form_state->setErrorByName('verify][intent_hash', $this->t('Intent hash must be 64 hex characters.'));
    }
    if ($payload === '' || preg_match('/^[0-9A-Fa-f]+$/', $payload) !== 1 || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('verify][payload', $this->t('Signed payload must be even-length hex.'));
    }
  }

  public function validateAnnounce(array &$form, FormStateInterface $form_state): void {
    $intent_hash = trim((string) $form_state->getValue(['announce', 'intent_hash'], ''));
    if (!$this->isHash($intent_hash)) {
      $form_state->setErrorByName('announce][intent_hash', $this->t('Intent hash must be 64 hex characters.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function submitBuild(array &$form, FormStateInterface $form_state): void {
    $build = (array) $form_state->getValue('build');
    $payload = [
      'network' => (string) $build['network'],
      'correlationId' => trim((string) $build['correlation_id']),
      'deadlineHours' => (int) $build['deadline_hours'],
      'legs' => [],
    ];

    for ($index = 1; $index <= 2; $index++) {
      $leg = (array) $build['leg_' . $index];
      $payload['legs'][] = [
        'signerPublicKey' => strtoupper(trim((string) $leg['signer_public_key'])),
        'recipientAddress' => trim((string) $leg['recipient_address']),
        'mosaicId' => strtoupper(trim((string) $leg['mosaic_id'])),
        'amount' => trim((string) $leg['amount']),
      ];
    }

    $this->storeResult($form_state, fn (): array => $this->engineClient->buildAggregateComplete($payload));
  }

  public function submitVerify(array &$form, FormStateInterface $form_state): void {
    $intent_hash = trim((string) $form_state->getValue(['verify', 'intent_hash']));
    $payload = $this->normalizeHex((string) $form_state->getValue(['verify', 'payload']));
    $this->storeResult($form_state, fn (): array => $this->engineClient->verifySignedPayload($intent_hash, $payload));
  }

  public function submitAnnounce(array &$form, FormStateInterface $form_state): void {
    $intent_hash = trim((string) $form_state->getValue(['announce', 'intent_hash']));
    $this->storeResult($form_state, fn (): array => $this->engineClient->announce($intent_hash));
  }

  private function storeResult(FormStateInterface $form_state, callable $callback): void {
    try {
      $form_state->set('symbol_atomic_swap_operation_result', $callback());
    }
    catch (SymbolEngineException $exception) {
      $form_state->set('symbol_atomic_swap_operation_result', [
        'error' => $exception->engineError ?? 'symbol_engine_error',
        'message' => $exception->getMessage(),
        'details' => $exception->details,
      ]);
    }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      $form_state->set('symbol_atomic_swap_operation_result', [
        'error' => 'invalid_request',
        'message' => $exception->getMessage(),
      ]);
    }

    $form_state->setRebuild(TRUE);
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

  private function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
  }

}
