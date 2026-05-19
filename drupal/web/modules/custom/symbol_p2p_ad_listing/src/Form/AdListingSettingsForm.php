<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

final class AdListingSettingsForm extends FormBase {

  public function getFormId(): string {
    return 'symbol_p2p_ad_listing_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('Symbol P2P Ad Listing currently uses fixed abuse guards. These values are code-level safeguards and are not editable from Drupal configuration.'),
    ];

    $form['listing_abuse_guards'] = [
      '#type' => 'details',
      '#title' => $this->t('Listing abuse guards'),
      '#open' => TRUE,
    ];
    $form['listing_abuse_guards']['max_reserving_listings_per_seller'] = [
      '#type' => 'item',
      '#title' => $this->t('Maximum active-like listings per seller'),
      '#markup' => (string) AdListingForm::MAX_RESERVING_LISTINGS_PER_SELLER,
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
    $form['balance_checks']['cron_balance_check'] = [
      '#type' => 'item',
      '#title' => $this->t('Cron seller balance refresh batch size'),
      '#markup' => '50',
      '#description' => $this->t('Cron refreshes active listing seller balances in batches and marks listings insufficient when the seller can no longer cover the offered amount.'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
