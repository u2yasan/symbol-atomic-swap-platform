<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Service\SwapOfferProjectionSynchronizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferSyncProjectionForm extends ConfirmFormBase {

  /**
   * @var array<string, mixed>
   */
  private array $offer = [];

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SwapOfferProjectionSynchronizer $synchronizer,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.offer_projection_synchronizer'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_offer_sync_projection_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $offerId = NULL): array {
    $offer = $offerId !== NULL ? $this->offers->find((int) $offerId) : NULL;
    if (!$offer) {
      throw new NotFoundHttpException();
    }
    $this->offer = $offer;

    if (!$this->offers->canSyncProjection($offer)) {
      $this->messenger()->addWarning($this->t('Only non-terminal chain-tracked settlements with a valid transaction hash can be synced.'));
    }

    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return (string) $this->t('Sync projection for @label?', ['@label' => $this->offer['label'] ?? 'atomic settlement']);
  }

  public function getDescription(): string {
    return (string) $this->t('This reads Symbol Engine projection state for the offer transaction hash and updates the local offer record.');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $this->offer['id'] ?? 0]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $offer_id = (int) $this->offer['id'];
    if (!$this->offers->canSyncProjection($this->offer)) {
      $this->messenger()->addError($this->t('Projection sync is not allowed for the current offer state.'));
      $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
      return;
    }

    try {
      $result = $this->synchronizer->sync($offer_id);
      if ($result['synced']) {
        $state = (string) ($result['projection']['state'] ?? 'unknown');
        $this->messenger()->addStatus($this->t('Projection was synced. Current state: @state', [
          '@state' => $state,
        ]));
      }
      else {
        $this->messenger()->addWarning($this->t('Projection was not synced: @reason', [
          '@reason' => $result['reason'],
        ]));
      }
    }
    catch (SymbolEngineException | \RuntimeException | \InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('Projection sync failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRedirect('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]);
  }

}
