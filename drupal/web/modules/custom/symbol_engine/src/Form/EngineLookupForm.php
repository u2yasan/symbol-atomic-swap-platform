<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\symbol_engine\Exception\SymbolEngineException;
use Drupal\symbol_engine\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EngineLookupForm extends FormBase {

  public function __construct(
    protected SymbolEngineClient $engineClient,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_engine.client'),
    );
  }

  public function getFormId(): string {
    return 'symbol_engine_lookup_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#markup' => '<p>Symbol Engine read API lookup and health dashboard. This page reads Engine state only.</p>',
    ];

    $form['dashboard'] = [
      '#type' => 'details',
      '#title' => $this->t('Dashboard'),
      '#open' => TRUE,
      'table' => $this->dashboardTable(),
    ];
    $form['dashboard']['actions'] = [
      '#type' => 'actions',
      'health' => [
        '#type' => 'submit',
        '#value' => $this->t('Read health'),
        '#submit' => ['::submitHealth'],
        '#limit_validation_errors' => [],
      ],
      'network' => [
        '#type' => 'submit',
        '#value' => $this->t('Read network'),
        '#submit' => ['::submitNetwork'],
        '#limit_validation_errors' => [],
      ],
    ];

    $form['network_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Network'),
      '#open' => TRUE,
    ];
    $form['network_status']['actions'] = [
      '#type' => 'actions',
    ];
    $form['network_status']['actions']['network'] = [
      '#type' => 'submit',
      '#value' => $this->t('Read network'),
      '#submit' => ['::submitNetwork'],
      '#limit_validation_errors' => [],
    ];

    $form['intent_lookup'] = [
      '#type' => 'details',
      '#title' => $this->t('Intent'),
      '#open' => TRUE,
    ];
    $form['intent_lookup']['intent_hash'] = [
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
    $form['intent_lookup']['actions'] = [
      '#type' => 'actions',
    ];
    $form['intent_lookup']['actions']['intent'] = [
      '#type' => 'submit',
      '#value' => $this->t('Read intent'),
      '#submit' => ['::submitIntent'],
      '#validate' => ['::validateIntent'],
    ];

    $form['projection_lookup'] = [
      '#type' => 'details',
      '#title' => $this->t('Projection'),
      '#open' => TRUE,
    ];
    $form['projection_lookup']['network'] = [
      '#type' => 'select',
      '#title' => $this->t('Network'),
      '#options' => [
        'testnet' => $this->t('Testnet'),
        'mainnet' => $this->t('Mainnet'),
      ],
      '#default_value' => 'testnet',
    ];
    $form['projection_lookup']['transaction_hash'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction hash'),
      '#maxlength' => 64,
      '#size' => 72,
      '#attributes' => [
        'pattern' => '[0-9A-Fa-f]{64}',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['projection_lookup']['actions'] = [
      '#type' => 'actions',
    ];
    $form['projection_lookup']['actions']['projection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Read projection'),
      '#submit' => ['::submitProjection'],
      '#validate' => ['::validateProjection'],
    ];

    $result = $form_state->get('symbol_engine_result');
    if (is_array($result)) {
      $form['#attached']['library'][] = 'symbol_engine/qr';
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

  public function validateIntent(array &$form, FormStateInterface $form_state): void {
    $intent_hash = (string) $form_state->getValue(['intent_lookup', 'intent_hash'], '');
    if (!$this->isHash($intent_hash)) {
      $form_state->setErrorByName('intent_lookup][intent_hash', $this->t('Intent hash must be 64 hex characters.'));
    }
  }

  public function validateProjection(array &$form, FormStateInterface $form_state): void {
    $transaction_hash = (string) $form_state->getValue(['projection_lookup', 'transaction_hash'], '');
    if (!$this->isHash($transaction_hash)) {
      $form_state->setErrorByName('projection_lookup][transaction_hash', $this->t('Transaction hash must be 64 hex characters.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function submitHealth(array &$form, FormStateInterface $form_state): void {
    $this->storeResult($form_state, fn (): array => $this->engineClient->health());
  }

  public function submitNetwork(array &$form, FormStateInterface $form_state): void {
    $this->storeResult($form_state, fn (): array => $this->engineClient->network());
  }

  public function submitIntent(array &$form, FormStateInterface $form_state): void {
    $intent_hash = (string) $form_state->getValue(['intent_lookup', 'intent_hash']);
    $this->storeResult($form_state, fn (): array => $this->engineClient->intent($intent_hash));
  }

  public function submitProjection(array &$form, FormStateInterface $form_state): void {
    $network = (string) $form_state->getValue(['projection_lookup', 'network']);
    $transaction_hash = (string) $form_state->getValue(['projection_lookup', 'transaction_hash']);
    $this->storeResult($form_state, fn (): array => $this->engineClient->projection($network, $transaction_hash));
  }

  private function storeResult(FormStateInterface $form_state, callable $callback): void {
    try {
      $form_state->set('symbol_engine_result', $callback());
    }
    catch (SymbolEngineException $exception) {
      $form_state->set('symbol_engine_result', [
        'error' => $exception->engineError ?? 'symbol_engine_error',
        'message' => $exception->getMessage(),
        'details' => $exception->details,
      ]);
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->set('symbol_engine_result', [
        'error' => 'invalid_request',
        'message' => $exception->getMessage(),
      ]);
    }

    $form_state->setRebuild(TRUE);
  }

  private function isHash(string $value): bool {
    return preg_match('/^[0-9A-Fa-f]{64}$/', $value) === 1;
  }

  private function dashboardTable(): array {
    return [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Health route'), $this->t('Use Read health to verify Drupal to Engine HTTP connectivity.')],
        [$this->t('Protected API token'), getenv('SYMBOL_ENGINE_API_TOKEN') ? $this->t('Configured in environment') : $this->t('Missing. Protected calls fail closed.')],
        [$this->t('Network route'), $this->t('Use Read network to verify authenticated Engine access and network identity.')],
      ],
    ];
  }

}
