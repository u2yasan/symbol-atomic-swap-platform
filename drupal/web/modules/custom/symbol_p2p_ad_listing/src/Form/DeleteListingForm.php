<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteListingForm extends ConfirmFormBase {

  /**
   * @var array<string, mixed>
   */
  private array $listing = [];

  public function __construct(
    private readonly AdListingRepository $listings,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_p2p_ad_listing.repository'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'symbol_p2p_ad_listing_delete_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Delete this P2P listing?');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $this->listing['id'] ?? 0]);
  }

  public function getConfirmText(): string {
    return (string) $this->t('Delete');
  }

  public function buildForm(array $form, FormStateInterface $form_state, $listingId = NULL): array {
    $listing = $listingId !== NULL ? $this->listings->find((int) $listingId) : NULL;
    if (!$listing) {
      throw new NotFoundHttpException();
    }
    if (!$this->canDeleteListing($listing)) {
      throw new AccessDeniedHttpException();
    }
    $this->listing = $listing;
    $form['listing_id'] = ['#type' => 'value', '#value' => (int) $listing['id']];
    $form['warning'] = [
      '#type' => 'item',
      '#markup' => $this->t('Only active listings can be deleted. This does not affect any existing atomic settlement.'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $listing_id = (int) $form_state->getValue('listing_id');
    $listing = $this->listings->find($listing_id);
    if (!$listing || !$this->canDeleteListing($listing)) {
      throw new AccessDeniedHttpException();
    }
    $this->listings->deleteActive($listing_id);
    $this->messenger()->addStatus($this->t('P2P listing was deleted.'));
    $form_state->setRedirect('symbol_p2p_ad_listing.list');
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canDeleteListing(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) === (int) $this->currentUser->id();
  }

}
