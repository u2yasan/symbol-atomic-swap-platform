<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class AdListingSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['symbol_p2p_ad_listing.settings'];
  }

  public function getFormId(): string {
    return 'symbol_p2p_ad_listing_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('symbol_p2p_ad_listing.settings');

    $form['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('Symbol P2P Ad Listing keeps critical abuse guards enabled. Only numeric limits are editable here.'),
    ];

    $form['listing_abuse_guards'] = [
      '#type' => 'details',
      '#title' => $this->t('Listing abuse guards'),
      '#open' => TRUE,
    ];
    $form['listing_abuse_guards']['max_reserving_listings_per_seller'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum active-like listings per seller'),
      '#default_value' => $this->boundedInt($config->get('max_reserving_listings_per_seller'), AdListingForm::DEFAULT_MAX_RESERVING_LISTINGS_PER_SELLER, 1, 100),
      '#min' => 1,
      '#max' => 100,
      '#step' => 1,
      '#required' => TRUE,
      '#description' => $this->t('New listing creation is blocked when the seller already has this many reserving listings. Active, matching, and insufficient-balance listings count toward this limit.'),
    ];
    $form['listing_abuse_guards']['duplicate_reserving_listing'] = [
      '#type' => 'item',
      '#title' => $this->t('Duplicate reserving listing guard'),
      '#markup' => $this->t('Enabled'),
      '#description' => $this->t('A seller cannot create another reserving listing with the same network, offered mosaic, offered amount, requested mosaic, and requested amount.'),
    ];
    $form['listing_abuse_guards']['reserved_balance_guard'] = [
      '#type' => 'item',
      '#title' => $this->t('Aggregate reserved balance guard'),
      '#markup' => $this->t('Enabled'),
      '#description' => $this->t('At create or edit time, the seller balance must cover the new listing amount plus the seller’s already reserved active-like amount for the same offered mosaic.'),
    ];

    $form['balance_checks'] = [
      '#type' => 'details',
      '#title' => $this->t('Balance checks'),
      '#open' => TRUE,
    ];
    $form['balance_checks']['create_listing_balance_check'] = [
      '#type' => 'item',
      '#title' => $this->t('Create/edit listing seller balance check'),
      '#markup' => $this->t('Enabled'),
      '#description' => $this->t('The seller’s offered mosaic balance is checked against Symbol Engine before the listing can be saved.'),
    ];
    $form['balance_checks']['take_listing_balance_check'] = [
      '#type' => 'item',
      '#title' => $this->t('Take listing balance check'),
      '#markup' => $this->t('Enabled'),
      '#description' => $this->t('Seller and taker balances are checked again before a listing is matched into an atomic settlement.'),
    ];
    $form['balance_checks']['cron_balance_check_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Cron seller balance refresh batch size'),
      '#default_value' => $this->boundedInt($config->get('cron_balance_check_batch_size'), 50, 1, 500),
      '#min' => 1,
      '#max' => 500,
      '#step' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Cron refreshes active listing seller balances in batches and marks listings insufficient when the seller can no longer cover the offered amount.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $this->validateBoundedInt($form_state, 'max_reserving_listings_per_seller', 1, 100);
    $this->validateBoundedInt($form_state, 'cron_balance_check_batch_size', 1, 500);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('symbol_p2p_ad_listing.settings')
      ->set('max_reserving_listings_per_seller', (int) $form_state->getValue('max_reserving_listings_per_seller'))
      ->set('cron_balance_check_batch_size', (int) $form_state->getValue('cron_balance_check_batch_size'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  private function validateBoundedInt(FormStateInterface $form_state, string $name, int $min, int $max): void {
    $value = $form_state->getValue($name);
    if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
      $form_state->setErrorByName($name, $this->t('@name must be between @min and @max.', [
        '@name' => $name,
        '@min' => (string) $min,
        '@max' => (string) $max,
      ]));
    }
  }

  private function boundedInt(mixed $value, int $default, int $min, int $max): int {
    if (!is_numeric($value)) {
      return $default;
    }
    return max($min, min($max, (int) $value));
  }

}
