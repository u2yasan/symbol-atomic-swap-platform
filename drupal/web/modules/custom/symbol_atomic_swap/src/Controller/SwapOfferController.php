<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SwapOfferController extends ControllerBase {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SwapOfferNotificationRepository $notifications,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.offer_notification_repository'),
      $container->get('date.formatter'),
    );
  }

  public function list(): array {
    $rows = [];
    foreach ($this->offers->all() as $offer) {
      $operations = [
        Link::fromTextAndUrl($this->t('View'), Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]))->toString(),
      ];
      if ($this->currentUser()->hasPermission('operate symbol atomic swap offers')) {
        if (!empty($offer['intent_hash']) && in_array($offer['state'], ['qr_generated', 'signed'], TRUE)) {
          $operations[] = Link::fromTextAndUrl($this->t('Submit signed payload'), Url::fromRoute('symbol_atomic_swap.offer_submit_signed_payload', ['offerId' => $offer['id']]))->toString();
        }
        if ($offer['state'] === 'signed') {
          $operations[] = Link::fromTextAndUrl($this->t('Announce transaction'), Url::fromRoute('symbol_atomic_swap.offer_announce', ['offerId' => $offer['id']]))->toString();
        }
        if (!empty($offer['transaction_hash']) && !in_array($offer['state'], ['draft', 'qr_generated', 'finalized'], TRUE)) {
          $operations[] = Link::fromTextAndUrl($this->t('Sync projection'), Url::fromRoute('symbol_atomic_swap.offer_sync_projection', ['offerId' => $offer['id']]))->toString();
        }
      }
      if ($this->currentUser()->hasPermission('administer symbol atomic swap offers')) {
        $operations[] = Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('symbol_atomic_swap.offer_edit', ['offerId' => $offer['id']]))->toString();
        $operations[] = Link::fromTextAndUrl($this->t('Delete'), Url::fromRoute('symbol_atomic_swap.offer_delete', ['offerId' => $offer['id']]))->toString();
      }

      $rows[] = [
        Link::fromTextAndUrl((string) $offer['label'], Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]))->toString(),
        $offer['state'],
        $offer['network'],
        $offer['intent_hash'] ?: '',
        $offer['changed'] ? $this->dateFormatter->format((int) $offer['changed'], 'short') : '',
        [
          'data' => [
            '#markup' => implode(' | ', $operations),
          ],
        ],
      ];
    }

    return [
      '#cache' => [
        'max-age' => 0,
      ],
      'actions' => [
        '#type' => 'actions',
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('Create swap offer'),
          '#url' => Url::fromRoute('symbol_atomic_swap.offer_add'),
          '#access' => $this->currentUser()->hasPermission('create symbol atomic swap offers'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],
      'offers' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Offer'),
          $this->t('State'),
          $this->t('Network'),
          $this->t('Intent hash'),
          $this->t('Changed'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No swap offers have been created.'),
      ],
    ];
  }

  public function view($offerId): array {
    $offer = $this->loadOffer((int) $offerId);
    $qr_payload = [];
    if (!empty($offer['qr_payload'])) {
      try {
        $decoded = json_decode((string) $offer['qr_payload'], TRUE, 512, JSON_THROW_ON_ERROR);
        $qr_payload = is_array($decoded) ? $decoded : [];
      }
      catch (\JsonException) {
        $qr_payload = [];
      }
    }

    $build = [
      '#cache' => [
        'max-age' => 0,
      ],
      '#attached' => ['library' => ['symbol_atomic_swap/qr']],
      'summary' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('State: @state', ['@state' => $offer['state']]),
          $this->t('Network: @network', ['@network' => $offer['network']]),
          $this->t('Correlation ID: @id', ['@id' => $offer['correlation_id']]),
          $this->t('Intent hash: @hash', ['@hash' => $offer['intent_hash'] ?: '']),
          $this->t('Transaction hash: @hash', ['@hash' => $offer['transaction_hash'] ?: '']),
          $this->t('Projection state: @state', ['@state' => $offer['projection_state'] ?: '']),
          $this->t('Block height: @height', ['@height' => $offer['block_height'] ?: '']),
          $this->t('Finalized height: @height', ['@height' => $offer['finalized_height'] ?: '']),
          $this->t('Expired at: @time', ['@time' => !empty($offer['expired_at']) ? $this->dateFormatter->format((int) $offer['expired_at'], 'short') : '']),
        ],
      ],
    ];

    if ($qr_payload !== []) {
      $build['qr'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['symbol-atomic-swap-qr'],
          'data-qr-payload' => json_encode($qr_payload, JSON_UNESCAPED_SLASHES),
        ],
      ];
      $build['qr_payload'] = [
        '#type' => 'textarea',
        '#title' => $this->t('QR payload'),
        '#value' => json_encode($qr_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        '#rows' => 8,
        '#attributes' => [
          'readonly' => 'readonly',
          'spellcheck' => 'false',
        ],
      ];
    }

    $notification_items = [];
    foreach ($this->notifications->findByOffer((int) $offer['id']) as $notification) {
      $notification_items[] = $this->t('@severity: @message (@created)', [
        '@severity' => (string) $notification['severity'],
        '@message' => (string) $notification['message'],
        '@created' => $this->dateFormatter->format((int) $notification['created'], 'short'),
      ]);
    }

    if ($notification_items !== []) {
      $build['notifications'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Notifications'),
        '#items' => $notification_items,
      ];
    }

    $build['actions'] = [
      '#type' => 'actions',
      'submit_signed_payload' => [
        '#type' => 'link',
        '#title' => $this->t('Submit signed payload'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_submit_signed_payload', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && !empty($offer['intent_hash'])
          && in_array($offer['state'], ['qr_generated', 'signed'], TRUE),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'announce' => [
        '#type' => 'link',
        '#title' => $this->t('Announce transaction'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_announce', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && $offer['state'] === 'signed',
        '#attributes' => ['class' => ['button']],
      ],
      'sync_projection' => [
        '#type' => 'link',
        '#title' => $this->t('Sync projection'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_sync_projection', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && !empty($offer['transaction_hash'])
          && !in_array($offer['state'], ['draft', 'qr_generated', 'finalized'], TRUE),
        '#attributes' => ['class' => ['button']],
      ],
      'edit' => [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_edit', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('administer symbol atomic swap offers'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    $build['details'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public offer JSON'),
      '#value' => json_encode($this->publicOfferDebugData($offer), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#rows' => 24,
      '#attributes' => [
        'readonly' => 'readonly',
        'spellcheck' => 'false',
      ],
    ];

    return $build;
  }

  public function title($offerId): string {
    return (string) $this->loadOffer((int) $offerId)['label'];
  }

  /**
   * @return array<string, mixed>
   */
  private function loadOffer(int $offerId): array {
    $offer = $this->offers->find($offerId);
    if (!$offer) {
      throw $this->createNotFoundException();
    }
    return $offer;
  }

  /**
   * @param array<string, mixed> $offer
   *
   * @return array<string, mixed>
   */
  private function publicOfferDebugData(array $offer): array {
    unset($offer['signed_payload'], $offer['node_response']);
    return $offer;
  }

}
