<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class NotificationMarkAllReadForm extends ConfirmFormBase {

  public function __construct(
    private readonly SwapOfferNotificationRepository $notifications,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_notification_repository'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_notification_mark_all_read_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Mark all notifications as read?');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_atomic_swap.notification_list');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $owner_id = $this->currentUser()->hasPermission('administer symbol atomic swap offers')
      ? NULL
      : (int) $this->currentUser()->id();
    $this->notifications->markAllRead($owner_id);
    $this->messenger()->addStatus($this->t('All notifications were marked read.'));
    $form_state->setRedirect('symbol_atomic_swap.notification_list');
  }

}
