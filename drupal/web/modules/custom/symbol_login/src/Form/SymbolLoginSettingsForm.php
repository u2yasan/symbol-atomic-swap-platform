<?php

namespace Drupal\symbol_login\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\Role;

/**
 * Configures Symbol login.
 */
final class SymbolLoginSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['symbol_login.settings'];
  }

  public function getFormId(): string {
    return 'symbol_login_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('symbol_login.settings');

    $form['network_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Network type'),
      '#options' => [
        'testnet' => $this->t('Testnet'),
        'mainnet' => $this->t('Mainnet'),
      ],
      '#default_value' => $config->get('network_type') ?: 'testnet',
      '#required' => TRUE,
    ];

    $form['rest_endpoints'] = [
      '#type' => 'textarea',
      '#title' => $this->t('REST endpoints'),
      '#default_value' => implode("\n", (array) $config->get('rest_endpoints')),
      '#description' => $this->t('One HTTPS Symbol REST endpoint per line. Tried in order.'),
      '#required' => TRUE,
    ];

    $form['challenge_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Challenge TTL seconds'),
      '#default_value' => $config->get('challenge_ttl_seconds') ?: 300,
      '#min' => 60,
      '#max' => 900,
      '#required' => TRUE,
    ];

    $form['request_timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('REST request timeout seconds'),
      '#default_value' => $config->get('request_timeout_seconds') ?: 5,
      '#min' => 1,
      '#max' => 30,
      '#required' => TRUE,
    ];

    $form['compatibility'] = [
      '#type' => 'details',
      '#title' => $this->t('Existing login compatibility'),
      '#open' => TRUE,
    ];

    $form['compatibility']['disable_password_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable password login for normal users'),
      '#default_value' => (bool) $config->get('disable_password_login'),
      '#description' => $this->t('Leave disabled to keep Drupal username and password login available beside Symbol login. UID 1 and users with the configured emergency permission are never blocked.'),
    ];

    $form['compatibility']['disable_password_reset'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable public password reset route'),
      '#default_value' => (bool) $config->get('disable_password_reset'),
    ];

    $form['compatibility']['disable_public_registration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable public user registration route'),
      '#default_value' => (bool) $config->get('disable_public_registration'),
    ];

    $roles = [];
    foreach (Role::loadMultiple() as $role) {
      if (!$role->isAdmin() && $role->id() !== 'anonymous' && $role->id() !== 'authenticated') {
        $roles[$role->id()] = $role->label();
      }
    }

    $form['role_rules_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Role rules JSON'),
      '#default_value' => json_encode($config->get('role_rules') ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#description' => $this->t('Array of rules with role, mosaic_id, minimum_amount, metadata_source_address, metadata_key, metadata_value, enabled. Available non-admin roles: @roles', ['@roles' => implode(', ', array_keys($roles))]),
      '#rows' => 12,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $endpoints = $this->parseLines((string) $form_state->getValue('rest_endpoints'));
    foreach ($endpoints as $endpoint) {
      if (!str_starts_with($endpoint, 'https://')) {
        $form_state->setErrorByName('rest_endpoints', $this->t('REST endpoints must use HTTPS.'));
      }
    }

    $rules = json_decode((string) $form_state->getValue('role_rules_json'), TRUE);
    if (!is_array($rules)) {
      $form_state->setErrorByName('role_rules_json', $this->t('Role rules must be valid JSON.'));
      return;
    }
    foreach ($rules as $rule) {
      if (!is_array($rule) || empty($rule['role'])) {
        $form_state->setErrorByName('role_rules_json', $this->t('Every role rule must define a role.'));
        return;
      }
      if (empty($rule['mosaic_id']) && empty($rule['metadata_key'])) {
        $form_state->setErrorByName('role_rules_json', $this->t('Every role rule must define a mosaic_id or metadata_key.'));
        return;
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('symbol_login.settings')
      ->set('network_type', $form_state->getValue('network_type'))
      ->set('rest_endpoints', $this->parseLines((string) $form_state->getValue('rest_endpoints')))
      ->set('challenge_ttl_seconds', (int) $form_state->getValue('challenge_ttl_seconds'))
      ->set('request_timeout_seconds', (int) $form_state->getValue('request_timeout_seconds'))
      ->set('disable_password_login', (bool) $form_state->getValue('disable_password_login'))
      ->set('disable_password_reset', (bool) $form_state->getValue('disable_password_reset'))
      ->set('disable_public_registration', (bool) $form_state->getValue('disable_public_registration'))
      ->set('role_rules', json_decode((string) $form_state->getValue('role_rules_json'), TRUE) ?: [])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * @return string[]
   */
  private function parseLines(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
  }

}
