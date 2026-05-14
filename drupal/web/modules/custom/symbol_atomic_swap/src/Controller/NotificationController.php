<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class NotificationController extends ControllerBase {

  public function __construct(
    private readonly SwapOfferNotificationRepository $notifications,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_notification_repository'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  public function list(): array {
    $unread_only = $this->requestStack->getCurrentRequest()?->query->get('unread') === '1';
    $rows = [];

    foreach ($this->notifications->all(100, $unread_only) as $notification) {
      $offer_id = (int) $notification['offer_id'];
      $operations = [
        Link::fromTextAndUrl($this->t('View offer'), Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]))->toString(),
      ];
      if (empty($notification['read_at'])) {
        $operations[] = Link::fromTextAndUrl($this->t('Mark read'), Url::fromRoute('symbol_atomic_swap.notification_mark_read', ['notificationId' => $notification['id']]))->toString();
      }

      $rows[] = [
        (string) $notification['severity'],
        empty($notification['read_at']) ? $this->t('Unread') : $this->t('Read'),
        (string) $notification['type'],
        (string) $notification['message'],
        Link::fromTextAndUrl((string) $offer_id, Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer_id]))->toString(),
        $this->dateFormatter->format((int) $notification['created'], 'short'),
        !empty($notification['read_at']) ? $this->dateFormatter->format((int) $notification['read_at'], 'short') : '',
        [
          'data' => ['#markup' => implode(' | ', $operations)],
        ],
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      'summary' => [
        '#type' => 'item',
        '#title' => $this->t('Unread notifications'),
        '#markup' => (string) $this->notifications->unreadCount(),
      ],
      'actions' => [
        '#type' => 'actions',
        'all' => [
          '#type' => 'link',
          '#title' => $this->t('All notifications'),
          '#url' => Url::fromRoute('symbol_atomic_swap.notification_list'),
          '#attributes' => ['class' => ['button']],
        ],
        'unread' => [
          '#type' => 'link',
          '#title' => $this->t('Unread only'),
          '#url' => Url::fromRoute('symbol_atomic_swap.notification_list', [], ['query' => ['unread' => '1']]),
          '#attributes' => ['class' => ['button']],
        ],
        'mark_all_read' => [
          '#type' => 'link',
          '#title' => $this->t('Mark all read'),
          '#url' => Url::fromRoute('symbol_atomic_swap.notification_mark_all_read'),
          '#attributes' => ['class' => ['button']],
        ],
      ],
      'notifications' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Severity'),
          $this->t('Read state'),
          $this->t('Type'),
          $this->t('Message'),
          $this->t('Offer'),
          $this->t('Created'),
          $this->t('Read at'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No notifications.'),
      ],
    ];
  }

}
