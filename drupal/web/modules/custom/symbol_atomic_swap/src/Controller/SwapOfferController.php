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
use Symfony\Component\HttpFoundation\RequestStack;

final class SwapOfferController extends ControllerBase {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SwapOfferNotificationRepository $notifications,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.offer_notification_repository'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  public function list(): array {
    $filters = $this->filtersFromRequest();
    $rows = [];
    foreach ($this->offers->search($filters) as $offer) {
      $rows[] = [
        Link::fromTextAndUrl((string) $offer['label'], Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]))->toString(),
        $this->stateLabel((string) $offer['state']),
        $offer['network'],
        (string) $offer['uid'],
        $offer['intent_hash'] ?: '',
        $offer['transaction_hash'] ?: '',
        $offer['changed'] ? $this->dateFormatter->format((int) $offer['changed'], 'short') : '',
        [
          'data' => [
            '#markup' => implode(' | ', $this->operationLinks($offer)),
          ],
        ],
      ];
    }

    return [
      '#cache' => [
        'max-age' => 0,
      ],
      'filters' => $this->filterForm($filters),
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
          $this->t('Owner UID'),
          $this->t('Intent hash'),
          $this->t('Transaction hash'),
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
        '#type' => 'details',
        '#title' => $this->t('Summary'),
        '#open' => TRUE,
        'table' => $this->keyValueTable([
          [$this->t('State'), $this->stateLabel((string) $offer['state'])],
          [$this->t('Network'), (string) $offer['network']],
          [$this->t('Owner UID'), (string) $offer['uid']],
          [$this->t('Correlation ID'), (string) $offer['correlation_id']],
          [$this->t('Deadline hours'), (string) $offer['deadline_hours']],
          [$this->t('Max fee'), (string) ($offer['max_fee'] ?: '')],
          [$this->t('Intent hash'), (string) ($offer['intent_hash'] ?: '')],
          [$this->t('Transaction hash'), (string) ($offer['transaction_hash'] ?: '')],
          [$this->t('Created'), $offer['created'] ? $this->dateFormatter->format((int) $offer['created'], 'short') : ''],
          [$this->t('Changed'), $offer['changed'] ? $this->dateFormatter->format((int) $offer['changed'], 'short') : ''],
          [$this->t('Expired at'), !empty($offer['expired_at']) ? $this->dateFormatter->format((int) $offer['expired_at'], 'short') : ''],
        ]),
      ],
      'legs' => [
        '#type' => 'details',
        '#title' => $this->t('Transfer legs'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Leg'),
            $this->t('Signer public key'),
            $this->t('Recipient address'),
            $this->t('Mosaic ID'),
            $this->t('Amount'),
          ],
          '#rows' => [
            [
              '1',
              (string) $offer['leg1_signer_public_key'],
              (string) $offer['leg1_recipient_address'],
              (string) $offer['leg1_mosaic_id'],
              (string) $offer['leg1_amount'],
            ],
            [
              '2',
              (string) $offer['leg2_signer_public_key'],
              (string) $offer['leg2_recipient_address'],
              (string) $offer['leg2_mosaic_id'],
              (string) $offer['leg2_amount'],
            ],
          ],
        ],
      ],
      'projection' => [
        '#type' => 'details',
        '#title' => $this->t('Projection'),
        '#open' => TRUE,
        'table' => $this->keyValueTable([
          [$this->t('Projection state'), (string) ($offer['projection_state'] ?: '')],
          [$this->t('Block height'), (string) ($offer['block_height'] ?: '')],
          [$this->t('Finalized height'), (string) ($offer['finalized_height'] ?: '')],
          [$this->t('Projection updated at'), (string) ($offer['projection_updated_at'] ?: '')],
          [$this->t('Manual sync allowed'), $this->offers->canSyncProjection($offer) ? (string) $this->t('Yes') : (string) $this->t('No')],
          [$this->t('Automatic sync eligible'), $this->offers->canSyncProjection($offer) ? (string) $this->t('Yes') : (string) $this->t('No')],
        ]),
      ],
    ];

    if ($this->currentUser()->hasPermission('administer symbol atomic swap offers')) {
      $build['projection']['table']['#rows'][] = [
        $this->t('Projection sync queued'),
        $this->offers->isProjectionSyncQueued((int) $offer['id']) ? (string) $this->t('Yes') : (string) $this->t('No'),
      ];
    }

    if ($qr_payload !== []) {
      $build['qr'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['symbol-atomic-swap-qr'],
          'data-qr-payload' => json_encode($qr_payload, JSON_UNESCAPED_SLASHES),
        ],
      ];
      $build['qr_payload'] = [
        '#type' => 'details',
        '#title' => $this->t('QR payload'),
        '#open' => FALSE,
        'payload' => [
        '#type' => 'textarea',
        '#title' => $this->t('QR payload'),
        '#value' => json_encode($qr_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        '#rows' => 8,
        '#attributes' => [
          'readonly' => 'readonly',
          'spellcheck' => 'false',
        ],
        ],
      ];
    }

    $notification_items = [];
    foreach ($this->notifications->findByOffer((int) $offer['id']) as $notification) {
      $notification_items[] = $this->t('@severity: @message (@created)', [
        '@severity' => $this->notificationLabel($notification),
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
          && $this->offers->canSubmitSignedPayload($offer),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'announce' => [
        '#type' => 'link',
        '#title' => $this->t('Announce transaction'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_announce', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && $this->offers->canAnnounce($offer),
        '#attributes' => ['class' => ['button']],
      ],
      'sync_projection' => [
        '#type' => 'link',
        '#title' => $this->t('Sync projection'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_sync_projection', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && $this->offers->canSyncProjection($offer),
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
      '#type' => 'details',
      '#title' => $this->t('Public offer JSON'),
      '#open' => FALSE,
      'payload' => [
      '#type' => 'textarea',
      '#title' => $this->t('Public offer JSON'),
      '#value' => json_encode($this->publicOfferDebugData($offer), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#rows' => 24,
      '#attributes' => [
        'readonly' => 'readonly',
        'spellcheck' => 'false',
      ],
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

  /**
   * @return array<string, string>
   */
  private function filtersFromRequest(): array {
    $query = $this->requestStack->getCurrentRequest()?->query;
    if ($query === NULL) {
      return [];
    }

    $filters = [];
    foreach (['state', 'network', 'owner', 'q'] as $key) {
      $value = trim((string) $query->get($key, ''));
      if ($value !== '') {
        $filters[$key] = $value;
      }
    }

    return $filters;
  }

  /**
   * @param array<string, string> $filters
   */
  private function filterForm(array $filters): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['symbol-atomic-swap-offer-filters']],
      'form' => [
        '#type' => 'inline_template',
        '#template' => '<form method="get" action="{{ action }}"><label>{{ q_label }} <input name="q" value="{{ q }}" /></label> <label>{{ state_label }} <select name="state"><option value="">{{ any }}</option>{% for value,label in states %}<option value="{{ value }}"{% if value == state %} selected{% endif %}>{{ label }}</option>{% endfor %}</select></label> <label>{{ network_label }} <select name="network"><option value="">{{ any }}</option><option value="testnet"{% if network == "testnet" %} selected{% endif %}>testnet</option><option value="mainnet"{% if network == "mainnet" %} selected{% endif %}>mainnet</option></select></label> <label>{{ owner_label }} <input name="owner" value="{{ owner }}" size="8" /></label> <button class="button" type="submit">{{ apply }}</button> <a class="button" href="{{ action }}">{{ reset }}</a></form>',
        '#context' => [
          'action' => Url::fromRoute('symbol_atomic_swap.offer_list')->toString(),
          'q_label' => $this->t('Search'),
          'state_label' => $this->t('State'),
          'network_label' => $this->t('Network'),
          'owner_label' => $this->t('Owner UID'),
          'apply' => $this->t('Apply'),
          'reset' => $this->t('Reset'),
          'any' => $this->t('- Any -'),
          'q' => $filters['q'] ?? '',
          'state' => $filters['state'] ?? '',
          'network' => $filters['network'] ?? '',
          'owner' => $filters['owner'] ?? '',
          'states' => [
            'draft' => 'draft',
            'qr_generated' => 'qr_generated',
            'signed' => 'signed',
            'announced' => 'announced',
            'unconfirmed' => 'unconfirmed',
            'confirmed' => 'confirmed',
            'finalized' => 'finalized',
            'expired' => 'expired',
            'failed' => 'failed',
            'rolled_back' => 'rolled_back',
          ],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $offer
   *
   * @return string[]
   */
  private function operationLinks(array $offer): array {
    $operations = [
      Link::fromTextAndUrl($this->t('View'), Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]))->toString(),
    ];
    if ($this->currentUser()->hasPermission('operate symbol atomic swap offers')) {
      if ($this->offers->canSubmitSignedPayload($offer)) {
        $operations[] = Link::fromTextAndUrl($this->t('Submit signed payload'), Url::fromRoute('symbol_atomic_swap.offer_submit_signed_payload', ['offerId' => $offer['id']]))->toString();
      }
      if ($this->offers->canAnnounce($offer)) {
        $operations[] = Link::fromTextAndUrl($this->t('Announce transaction'), Url::fromRoute('symbol_atomic_swap.offer_announce', ['offerId' => $offer['id']]))->toString();
      }
      if ($this->offers->canSyncProjection($offer)) {
        $operations[] = Link::fromTextAndUrl($this->t('Sync projection'), Url::fromRoute('symbol_atomic_swap.offer_sync_projection', ['offerId' => $offer['id']]))->toString();
      }
    }
    if ($this->currentUser()->hasPermission('administer symbol atomic swap offers')) {
      $operations[] = Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('symbol_atomic_swap.offer_edit', ['offerId' => $offer['id']]))->toString();
      $operations[] = Link::fromTextAndUrl($this->t('Delete'), Url::fromRoute('symbol_atomic_swap.offer_delete', ['offerId' => $offer['id']]))->toString();
    }

    return $operations;
  }

  private function stateLabel(string $state): string {
    if ($state === 'finalized') {
      return $state . ' [terminal, completed]';
    }
    if ($this->offers->isTerminalState($state)) {
      return $state . ' [terminal]';
    }
    if (in_array($state, ['signed', 'announced', 'unconfirmed', 'confirmed'], TRUE)) {
      return $state . ' [requires sync]';
    }
    return $state;
  }

  /**
   * @param array<string, mixed> $notification
   */
  private function notificationLabel(array $notification): string {
    $read_state = empty($notification['read_at']) ? 'unread' : 'read';
    return (string) $notification['severity'] . ' / ' . $read_state;
  }

  /**
   * @param array<int, array{0: mixed, 1: string}> $values
   */
  private function keyValueTable(array $values): array {
    $rows = [];
    foreach ($values as $row) {
      $rows[] = [$row[0], $row[1]];
    }

    return [
      '#type' => 'table',
      '#rows' => $rows,
    ];
  }

}
