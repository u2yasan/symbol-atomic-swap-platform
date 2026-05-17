<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AdListingController extends ControllerBase {

  public function __construct(
    private readonly AdListingRepository $listings,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_p2p_ad_listing.repository'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  public function list(): array {
    $filters = $this->filtersFromRequest();
    $rows = [];
    foreach ($this->listings->search($filters) as $listing) {
      $rows[] = [
        Link::fromTextAndUrl((string) $listing['label'], Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $listing['id']]))->toString(),
        (string) $listing['network'],
        $this->mosaicPair($listing),
        $this->statusLabel((string) $listing['status']),
        $this->balanceCheckSummary($listing),
        $listing['changed'] ? $this->dateFormatter->format((int) $listing['changed'], 'short') : '',
        ['data' => ['#markup' => implode(' | ', $this->operationLinks($listing))]],
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      'filters' => $this->filterForm($filters),
      'actions' => [
        '#type' => 'actions',
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('Create P2P listing'),
          '#url' => Url::fromRoute('symbol_p2p_ad_listing.add'),
          '#access' => $this->currentUser()->hasPermission('create symbol p2p ad listings'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],
      'listings' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Listing'),
          $this->t('Network'),
          $this->t('Terms'),
          $this->t('Status'),
          $this->t('Seller balance check'),
          $this->t('Changed'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No P2P listings have been created.'),
      ],
    ];
  }

  public function view($listingId): array {
    $listing = $this->loadListing((int) $listingId);
    $rows = [
      [$this->t('Status'), $this->statusLabel((string) $listing['status'])],
      [$this->t('Network'), (string) $listing['network']],
      [$this->t('Seller address'), $this->hashValue((string) $listing['seller_address'])],
      [$this->t('Seller offers'), $this->atomicAmount((string) $listing['offered_amount']) . ' ' . (string) $listing['offered_mosaic_id']],
      [$this->t('Seller wants'), $this->atomicAmount((string) $listing['requested_amount']) . ' ' . (string) $listing['requested_mosaic_id']],
      [$this->t('Settlement window'), (string) $this->t('@minutes minutes', ['@minutes' => (string) $listing['swap_window_minutes']])],
      [$this->t('Balance checked amount'), $this->atomicAmount((string) ($listing['seller_balance_checked_amount'] ?? ''))],
      [$this->t('Balance checked at'), !empty($listing['seller_balance_checked_at']) ? $this->dateFormatter->format((int) $listing['seller_balance_checked_at'], 'short') : ''],
      [$this->t('Created'), $this->dateFormatter->format((int) $listing['created'], 'short')],
      [$this->t('Changed'), $this->dateFormatter->format((int) $listing['changed'], 'short')],
    ];
    if (!empty($listing['matched_offer_id'])) {
      $rows[] = [
        $this->t('Atomic settlement'),
        Link::fromTextAndUrl('#' . $listing['matched_offer_id'], Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $listing['matched_offer_id']]))->toString(),
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      'summary' => [
        '#type' => 'details',
        '#title' => $this->t('Listing'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#rows' => $rows,
        ],
      ],
      'actions' => [
        '#type' => 'actions',
        'take' => [
          '#type' => 'link',
          '#title' => $this->t('Take listing'),
          '#url' => Url::fromRoute('symbol_p2p_ad_listing.take', ['listingId' => $listing['id']]),
          '#access' => $this->canTakeListing($listing),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'check_balance' => [
          '#type' => 'link',
          '#title' => $this->t('Check seller balance'),
          '#url' => Url::fromRoute('symbol_p2p_ad_listing.check_balance', ['listingId' => $listing['id']]),
          '#access' => $this->canCheckSellerBalance($listing),
          '#attributes' => ['class' => ['button']],
        ],
        'cancel' => [
          '#type' => 'link',
          '#title' => $this->t('Cancel'),
          '#url' => Url::fromRoute('symbol_p2p_ad_listing.cancel', ['listingId' => $listing['id']]),
          '#access' => (string) $listing['status'] === AdListingRepository::ACTIVE && $this->currentUser()->hasPermission('administer symbol p2p ad listings'),
          '#attributes' => ['class' => ['button']],
        ],
      ],
    ];
  }

  public function title($listingId): string {
    return (string) $this->loadListing((int) $listingId)['label'];
  }

  /**
   * @return array<string, string>
   */
  private function filtersFromRequest(): array {
    $query = $this->requestStack->getCurrentRequest()?->query;
    return [
      'status' => trim((string) $query?->get('status', AdListingRepository::ACTIVE)),
      'network' => trim((string) $query?->get('network', '')),
      'q' => trim((string) $query?->get('q', '')),
    ];
  }

  /**
   * @param array<string, string> $filters
   */
  private function filterForm(array $filters): array {
    return [
      '#type' => 'form',
      '#method' => 'get',
      'status' => [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => [
          '' => $this->t('- Any -'),
          AdListingRepository::ACTIVE => $this->t('Active'),
          AdListingRepository::MATCHED => $this->t('Matched'),
          AdListingRepository::CANCELLED => $this->t('Cancelled'),
        ],
        '#default_value' => $filters['status'],
      ],
      'network' => [
        '#type' => 'select',
        '#title' => $this->t('Network'),
        '#options' => ['' => $this->t('- Any -'), 'testnet' => $this->t('Testnet'), 'mainnet' => $this->t('Mainnet')],
        '#default_value' => $filters['network'],
      ],
      'q' => [
        '#type' => 'textfield',
        '#title' => $this->t('Search'),
        '#default_value' => $filters['q'],
        '#size' => 32,
      ],
      'actions' => [
        '#type' => 'actions',
        'submit' => ['#type' => 'submit', '#value' => $this->t('Filter')],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function operationLinks(array $listing): array {
    $links = [
      Link::fromTextAndUrl($this->t('View'), Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $listing['id']]))->toString(),
    ];
    if ($this->canTakeListing($listing)) {
      $links[] = Link::fromTextAndUrl($this->t('Take'), Url::fromRoute('symbol_p2p_ad_listing.take', ['listingId' => $listing['id']]))->toString();
    }
    if ($this->canCheckSellerBalance($listing)) {
      $links[] = Link::fromTextAndUrl($this->t('Check balance'), Url::fromRoute('symbol_p2p_ad_listing.check_balance', ['listingId' => $listing['id']]))->toString();
    }
    return $links;
  }

  /**
   * @return array<string, mixed>
   */
  private function loadListing(int $id): array {
    $listing = $this->listings->find($id);
    if (!$listing) {
      throw new NotFoundHttpException();
    }
    return $listing;
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function mosaicPair(array $listing): string {
    return $this->atomicAmount((string) $listing['offered_amount']) . ' ' . $listing['offered_mosaic_id'] . ' -> ' . $this->atomicAmount((string) $listing['requested_amount']) . ' ' . $listing['requested_mosaic_id'];
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function balanceCheckSummary(array $listing): string {
    $amount = $this->atomicAmount((string) ($listing['seller_balance_checked_amount'] ?? ''));
    $checked_at = !empty($listing['seller_balance_checked_at'])
      ? $this->dateFormatter->format((int) $listing['seller_balance_checked_at'], 'short')
      : (string) $this->t('Never');

    return (string) $this->t('@amount at @checked_at', [
      '@amount' => $amount,
      '@checked_at' => $checked_at,
    ]);
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canTakeListing(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && (int) ($listing['seller_uid'] ?? 0) !== (int) $this->currentUser()->id()
      && $this->currentUser()->hasPermission('operate symbol p2p ad listings');
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canCheckSellerBalance(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && (int) ($listing['seller_uid'] ?? 0) !== (int) $this->currentUser()->id()
      && $this->currentUser()->hasPermission('view symbol p2p ad listings');
  }

  private function atomicAmount(string $amount): string {
    return $amount !== '' ? $amount : (string) $this->t('Not checked');
  }

  private function hashValue(string $value): string {
    return $value !== '' ? $value : (string) $this->t('Not set');
  }

  private function statusLabel(string $status): string {
    return match ($status) {
      AdListingRepository::ACTIVE => (string) $this->t('Active'),
      AdListingRepository::MATCHING => (string) $this->t('Matching'),
      AdListingRepository::MATCHED => (string) $this->t('Matched'),
      AdListingRepository::CANCELLED => (string) $this->t('Cancelled'),
      default => $status,
    };
  }

}
