<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferCancelForm extends ConfirmFormBase {

  /**
   * @var array<string, mixed>
   */
  private array $offer = [];

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_cancel_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    if (!$this->canCancelOffer($offer)) {
      throw new AccessDeniedHttpException();
    }

    $this->offer = $offer;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return (string) $this->t('Cancel @label?', ['@label' => $this->offer['label'] ?? 'atomic settlement']);
  }

  public function getDescription(): string {
    return (string) $this->t('This only cancels the local settlement before transaction announcement. It cannot reverse an announced or finalized Symbol transaction.');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $this->offer['id'] ?? 0]);
  }

  public function getConfirmText(): string {
    return (string) $this->t('Cancel settlement');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer = $this->offers->find((int) $this->offer['id']);
    if (!$offer || !$this->canCancelOffer($offer)) {
      throw new AccessDeniedHttpException();
    }

    $this->offers->cancel((int) $offer['id']);
    $this->messenger()->addStatus($this->t('Atomic settlement was cancelled.'));
    $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]);
  }

  /**
   * @param array<string, mixed> $offer
   */
  private function canCancelOffer(array $offer): bool {
    if (!$this->offers->canCancel($offer)) {
      return FALSE;
    }
    if ($this->currentUser->hasPermission('administer symbol atomic swap offers')) {
      return TRUE;
    }
    if ((int) ($offer['uid'] ?? 0) === (int) $this->currentUser->id()) {
      return TRUE;
    }

    $symbol_account = $this->verifiedSymbolAccount();
    return $symbol_account !== NULL
      && $symbol_account['network'] === (string) ($offer['network'] ?? '')
      && hash_equals($symbol_account['public_key'], strtoupper((string) ($offer['leg2_signer_public_key'] ?? '')));
  }

  /**
   * @return array{network: string, public_key: string}|null
   */
  private function verifiedSymbolAccount(): ?array {
    $account = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$account || !(bool) ($account->get('field_symbol_address_verified')->value ?? FALSE)) {
      return NULL;
    }

    $network = (string) ($account->get('field_symbol_network')->value ?? '');
    $public_key = strtoupper((string) ($account->get('field_symbol_public_key')->value ?? ''));
    if (!in_array($network, ['mainnet', 'testnet'], TRUE) || !preg_match('/^[0-9A-F]{64}$/', $public_key)) {
      return NULL;
    }

    return ['network' => $network, 'public_key' => $public_key];
  }

}
