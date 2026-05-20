<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SymbolAccountVerificationForm extends FormBase {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_account_verification_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->loadUser();
    $verified = (bool) ($account->get('field_symbol_address_verified')->value ?? FALSE);
    $verified_at = (int) ($account->get('field_symbol_address_verified_at')->value ?? 0);

    $form['source'] = [
      '#type' => 'item',
      '#title' => $this->t('Identity provider'),
      '#markup' => $this->t('Symbol Login'),
    ];

    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Verification status'),
      '#markup' => $verified
        ? $this->t('Verified at @time.', ['@time' => $this->dateFormatter->format($verified_at, 'custom', 'Y-m-d H:i')])
        : $this->t('Not connected. Use Symbol Login to verify and connect your Symbol account.'),
    ];

    if ($verified) {
      $form['network'] = [
        '#type' => 'item',
        '#title' => $this->t('Symbol network'),
        '#markup' => $this->plainValue((string) ($account->get('field_symbol_network')->value ?? '')),
      ];
      $form['address'] = [
        '#type' => 'item',
        '#title' => $this->t('Symbol address'),
        '#markup' => $this->plainValue((string) ($account->get('field_symbol_address')->value ?? '')),
      ];
      $form['public_key'] = [
        '#type' => 'item',
        '#title' => $this->t('Symbol public key'),
        '#markup' => $this->plainValue((string) ($account->get('field_symbol_public_key')->value ?? '')),
      ];
      $form['method'] = [
        '#type' => 'item',
        '#title' => $this->t('Verification method'),
        '#markup' => $this->plainValue((string) ($account->get('field_symbol_verification_method')->value ?? '')),
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Disconnect Symbol account'),
        '#button_type' => 'danger',
        '#submit' => ['::submitRemove'],
      ];
      return $form;
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['sss'] = [
      '#type' => 'link',
      '#title' => $this->t('Continue with SSS'),
      '#url' => Url::fromRoute('symbol_login.login_sss'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];
    $form['actions']['alice'] = [
      '#type' => 'link',
      '#title' => $this->t('Continue with aLice'),
      '#url' => Url::fromRoute('symbol_login.login_alice'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Submit handling is routed to explicit handlers.
  }

  public function submitRemove(array &$form, FormStateInterface $form_state): void {
    $account = $this->loadUser();
    foreach ([
      'field_symbol_network',
      'field_symbol_address',
      'field_symbol_public_key',
      'field_symbol_address_verified_at',
      'field_symbol_verification_method',
      'field_symbol_challenge_hash',
    ] as $field) {
      if ($account->hasField($field)) {
        $account->set($field, NULL);
      }
    }
    if ($account->hasField('field_symbol_address_verified')) {
      $account->set('field_symbol_address_verified', FALSE);
    }
    if ($account->hasField('field_symbol_last_verified')) {
      $account->set('field_symbol_last_verified', NULL);
    }
    $account->save();

    $this->messenger()->addStatus($this->t('Registered Symbol account was removed.'));
    $form_state->setRebuild();
  }

  private function loadUser() {
    return $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
  }

  private function plainValue(string $value): string {
    return $value !== '' ? $value : (string) $this->t('Not set');
  }

}
