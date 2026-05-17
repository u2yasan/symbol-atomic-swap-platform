<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CancelListingForm extends ConfirmFormBase {

  /**
   * @var array<string, mixed>
   */
  private array $listing = [];

  public function __construct(
    private readonly AdListingRepository $listings,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('symbol_p2p_ad_listing.repository'));
  }

  public function getFormId(): string {
    return 'symbol_p2p_ad_listing_cancel_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Cancel this P2P listing?');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $this->listing['id'] ?? 0]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, $listingId = NULL): array {
    $listing = $listingId !== NULL ? $this->listings->find((int) $listingId) : NULL;
    if (!$listing) {
      throw new NotFoundHttpException();
    }
    $this->listing = $listing;
    $form['listing_id'] = ['#type' => 'value', '#value' => (int) $listing['id']];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->listings->cancel((int) $form_state->getValue('listing_id'));
    $this->messenger()->addStatus($this->t('P2P listing was cancelled.'));
    $form_state->setRedirect('symbol_p2p_ad_listing.list');
  }

}
