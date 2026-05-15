<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class SymbolAtomicSwapSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['symbol_atomic_swap.settings'];
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('symbol_atomic_swap.settings');

    $form['engine'] = [
      '#type' => 'details',
      '#title' => $this->t('Symbol Engine'),
      '#open' => TRUE,
    ];
    $form['engine']['engine_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Engine base URL'),
      '#default_value' => $config->get('engine_base_url') ?: 'http://symbol-engine:3000',
      '#required' => TRUE,
      '#description' => $this->t('Use an internal service URL. Do not expose Symbol Engine directly to the public internet.'),
    ];
    $form['engine']['engine_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Engine timeout seconds'),
      '#default_value' => (int) ($config->get('engine_timeout') ?: 10),
      '#min' => 1,
      '#max' => 60,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['engine']['api_token_state'] = [
      '#type' => 'item',
      '#title' => $this->t('API token state'),
      '#markup' => getenv('SYMBOL_ENGINE_API_TOKEN') ? $this->t('Configured in environment') : $this->t('Missing. Protected Engine API calls fail closed.'),
    ];
    $form['engine']['mainnet_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable mainnet UI operations'),
      '#default_value' => (bool) $config->get('mainnet_enabled'),
      '#description' => $this->t('Keep disabled unless production Engine and Symbol node configuration has been verified.'),
    ];

    $form['account_verification'] = [
      '#type' => 'details',
      '#title' => $this->t('Account verification'),
      '#open' => TRUE,
    ];
    $form['account_verification']['account_verification_recipient_testnet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Testnet on-chain verification recipient address'),
      '#maxlength' => 46,
      '#size' => 52,
      '#default_value' => $config->get('account_verification_recipient_testnet') ?: '',
      '#description' => $this->t('Public site-owned address that receives challenge transfers for users who cannot use SSS, Symbol CLI, or SDK tools. Leave empty to disable this method.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['account_verification']['account_verification_recipient_mainnet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mainnet on-chain verification recipient address'),
      '#maxlength' => 46,
      '#size' => 52,
      '#default_value' => $config->get('account_verification_recipient_mainnet') ?: '',
      '#description' => $this->t('Only configure this after mainnet operations are ready. This address is public and does not require storing a private key in Drupal.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications'),
      '#open' => TRUE,
    ];
    $form['notifications']['notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Notification email recipient'),
      '#default_value' => $config->get('notification_email') ?: '',
      '#description' => $this->t('Leave empty to disable outbound notification email.'),
    ];
    $form['notifications']['webhook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Webhook URL'),
      '#default_value' => $config->get('webhook_url') ?: '',
      '#description' => $this->t('Leave empty to disable outbound notification webhooks.'),
    ];
    $form['notifications']['webhook_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Webhook timeout seconds'),
      '#default_value' => (int) ($config->get('webhook_timeout') ?: 3),
      '#min' => 1,
      '#max' => 10,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['notifications']['webhook_token_state'] = [
      '#type' => 'item',
      '#title' => $this->t('Webhook token state'),
      '#markup' => getenv('SYMBOL_ATOMIC_SWAP_WEBHOOK_TOKEN') ? $this->t('Configured in environment') : $this->t('Not configured'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $engine_base_url = rtrim(trim((string) $form_state->getValue('engine_base_url')), '/');
    if (!$this->isHttpUrl($engine_base_url)) {
      $form_state->setErrorByName('engine_base_url', $this->t('Engine base URL must be an http or https URL.'));
    }

    $webhook_url = trim((string) $form_state->getValue('webhook_url'));
    if ($webhook_url !== '' && !$this->isHttpUrl($webhook_url)) {
      $form_state->setErrorByName('webhook_url', $this->t('Webhook URL must be an http or https URL.'));
    }

    $testnet_recipient = strtoupper(trim((string) $form_state->getValue('account_verification_recipient_testnet')));
    if ($testnet_recipient !== '' && !$this->isNetworkAddress($testnet_recipient, 'testnet')) {
      $form_state->setErrorByName('account_verification_recipient_testnet', $this->t('Testnet verification recipient must be a valid raw testnet Symbol address.'));
    }

    $mainnet_recipient = strtoupper(trim((string) $form_state->getValue('account_verification_recipient_mainnet')));
    if ($mainnet_recipient !== '' && !$this->isNetworkAddress($mainnet_recipient, 'mainnet')) {
      $form_state->setErrorByName('account_verification_recipient_mainnet', $this->t('Mainnet verification recipient must be a valid raw mainnet Symbol address.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('symbol_atomic_swap.settings')
      ->set('engine_base_url', rtrim(trim((string) $form_state->getValue('engine_base_url')), '/'))
      ->set('engine_timeout', (int) $form_state->getValue('engine_timeout'))
      ->set('account_verification_recipient_testnet', strtoupper(trim((string) $form_state->getValue('account_verification_recipient_testnet'))))
      ->set('account_verification_recipient_mainnet', strtoupper(trim((string) $form_state->getValue('account_verification_recipient_mainnet'))))
      ->set('notification_email', trim((string) $form_state->getValue('notification_email')))
      ->set('webhook_url', trim((string) $form_state->getValue('webhook_url')))
      ->set('webhook_timeout', (int) $form_state->getValue('webhook_timeout'))
      ->set('mainnet_enabled', (bool) $form_state->getValue('mainnet_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  private function isHttpUrl(string $url): bool {
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return filter_var($url, FILTER_VALIDATE_URL) !== FALSE
      && in_array($scheme, ['http', 'https'], TRUE);
  }

  private function isNetworkAddress(string $value, string $network): bool {
    $prefix = match ($network) {
      'mainnet' => 'N',
      'testnet' => 'T',
      default => '',
    };
    return $prefix !== '' && preg_match('/^' . $prefix . '[A-Z2-7]{38}$/', strtoupper(trim($value))) === 1;
  }

}
