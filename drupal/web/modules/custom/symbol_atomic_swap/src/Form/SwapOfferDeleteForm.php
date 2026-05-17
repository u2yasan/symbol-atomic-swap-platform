<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferDeleteForm extends ConfirmFormBase {

  /**
   * @var array<string, mixed>
   */
  private array $offer = [];

  public function __construct(
    private readonly SwapOfferRepository $offers,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_delete_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    if (!$this->offers->canDelete($offer)) {
      throw new AccessDeniedHttpException();
    }
    $this->offer = $offer;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return (string) $this->t('Delete @label?', ['@label' => $this->offer['label'] ?? 'atomic settlement']);
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_atomic_swap.offer_list');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->offers->deleteEditable((int) $this->offer['id']);
    $this->messenger()->addStatus($this->t('Atomic settlement was deleted.'));
    $form_state->setRedirect('symbol_atomic_swap.offer_list');
  }

}
