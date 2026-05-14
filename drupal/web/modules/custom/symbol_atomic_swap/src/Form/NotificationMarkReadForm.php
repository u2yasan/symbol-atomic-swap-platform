<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class NotificationMarkReadForm extends ConfirmFormBase {

  private ?int $notificationId = NULL;

  public function __construct(
    private readonly SwapOfferNotificationRepository $notifications,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_notification_repository'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_notification_mark_read_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $notificationId = NULL): array {
    $this->notificationId = $notificationId !== NULL ? (int) $notificationId : NULL;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return (string) $this->t('Mark notification as read?');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('symbol_atomic_swap.notification_list');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->notificationId !== NULL) {
      $this->notifications->markRead($this->notificationId);
      $this->messenger()->addStatus($this->t('Notification was marked read.'));
    }

    $form_state->setRedirect('symbol_atomic_swap.notification_list');
  }

}
