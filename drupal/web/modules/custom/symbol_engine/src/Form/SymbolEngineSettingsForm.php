<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class SymbolEngineSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['symbol_engine.settings'];
  }

  public function getFormId(): string {
    return 'symbol_engine_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('symbol_engine.settings');

    $form['engine_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Engine base URL'),
      '#default_value' => $config->get('engine_base_url') ?: 'http://symbol-engine:3000',
      '#required' => TRUE,
      '#description' => $this->t('Use an internal service URL. Do not expose Symbol Engine directly to the public internet.'),
    ];
    $form['engine_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Engine timeout seconds'),
      '#default_value' => (int) ($config->get('engine_timeout') ?: 10),
      '#min' => 1,
      '#max' => 60,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['api_token_state'] = [
      '#type' => 'item',
      '#title' => $this->t('API token state'),
      '#markup' => getenv('SYMBOL_ENGINE_API_TOKEN') ? $this->t('Configured in environment') : $this->t('Missing. Protected Engine API calls fail closed.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $engine_base_url = rtrim(trim((string) $form_state->getValue('engine_base_url')), '/');
    if (!$this->isHttpUrl($engine_base_url)) {
      $form_state->setErrorByName('engine_base_url', $this->t('Engine base URL must be an http or https URL.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('symbol_engine.settings')
      ->set('engine_base_url', rtrim(trim((string) $form_state->getValue('engine_base_url')), '/'))
      ->set('engine_timeout', (int) $form_state->getValue('engine_timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  private function isHttpUrl(string $url): bool {
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return filter_var($url, FILTER_VALIDATE_URL) !== FALSE
      && in_array($scheme, ['http', 'https'], TRUE);
  }

}

