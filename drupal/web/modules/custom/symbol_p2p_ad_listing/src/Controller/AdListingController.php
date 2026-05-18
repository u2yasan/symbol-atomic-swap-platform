<?php

declare(strict_types=1);

namespace Drupal\symbol_p2p_ad_listing\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Drupal\symbol_p2p_ad_listing\Repository\AdListingRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AdListingController extends ControllerBase {

  public function __construct(
    private readonly AdListingRepository $listings,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
    private readonly SymbolEngineClient $engineClient,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_p2p_ad_listing.repository'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
      $container->get('symbol_atomic_swap.engine_client'),
    );
  }

  public function list(): array {
    $filters = $this->filtersFromRequest();
    $header = $this->listingTableHeader();
    $rows = [];
    foreach ($this->listings->search($filters, 100, $header) as $listing) {
      $rows[] = [
        Link::fromTextAndUrl((string) $listing['label'], Url::fromRoute('symbol_p2p_ad_listing.view', ['listingId' => $listing['id']]))->toString(),
        ['data' => $this->mosaicTerm($listing, 'offered')],
        ['data' => $this->mosaicTerm($listing, 'requested')],
        $this->expirationLabel($listing),
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
        '#header' => $header,
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
      [$this->t('Seller offers'), ['data' => $this->mosaicTermWithBalanceCheck($listing, 'offered')]],
      [$this->t('Seller wants'), ['data' => $this->mosaicTermWithBalanceCheck($listing, 'requested')]],
      [$this->t('Settlement window'), (string) $this->t('@minutes minutes', ['@minutes' => (string) $listing['swap_window_minutes']])],
      [$this->t('Expires'), $this->expirationLabel($listing)],
      [$this->t('Balance checked amount'), $this->formatBalanceCheckedAmount($listing)],
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
      '#attached' => [
        'library' => ['symbol_p2p_ad_listing/balance_check'],
      ],
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
          '#attributes' => [
            'class' => ['button', 'button--primary'],
            'data-symbol-p2p-take-listing' => '1',
          ],
        ],
        'edit' => [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => Url::fromRoute('symbol_p2p_ad_listing.edit', ['listingId' => $listing['id']]),
          '#access' => $this->canManageListing($listing),
          '#attributes' => ['class' => ['button']],
        ],
        'delete' => [
          '#type' => 'link',
          '#title' => $this->t('Delete'),
          '#url' => Url::fromRoute('symbol_p2p_ad_listing.delete', ['listingId' => $listing['id']]),
          '#access' => $this->canManageListing($listing),
          '#attributes' => ['class' => ['button', 'button--danger']],
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

  public function sellerBalanceCheck($listingId): JsonResponse {
    $listing = $this->loadListing((int) $listingId);
    if (!$this->canCheckSellerBalance($listing)) {
      return new JsonResponse(['ok' => FALSE, 'message' => (string) $this->t('This listing cannot be balance checked.')], 403);
    }

    try {
      $balance = (string) ($this->engineClient->accountMosaicBalance(
        (string) $listing['network'],
        (string) $listing['seller_address'],
        (string) $listing['offered_mosaic_id'],
      )['amount'] ?? '0');
    }
    catch (SymbolEngineException | \InvalidArgumentException $exception) {
      return new JsonResponse(['ok' => FALSE, 'message' => $exception->getMessage()], 502);
    }

    return new JsonResponse($this->balanceCheckResponse(
      $balance,
      (string) $listing['offered_amount'],
      (string) $listing['network'],
      (string) $listing['offered_mosaic_id'],
    ));
  }

  public function myBalanceCheck($listingId): JsonResponse {
    $listing = $this->loadListing((int) $listingId);
    if (!$this->canCheckMyBalance($listing)) {
      return new JsonResponse(['ok' => FALSE, 'message' => (string) $this->t('This listing cannot be balance checked.')], 403);
    }

    $account = $this->verifiedSymbolAccount();
    if (!$account || (string) $account['network'] !== (string) $listing['network']) {
      return new JsonResponse(['ok' => FALSE, 'message' => (string) $this->t('My Symbol Account must be verified on the same network as the listing.')], 403);
    }

    try {
      $balance = (string) ($this->engineClient->accountMosaicBalance(
        (string) $listing['network'],
        (string) $account['address'],
        (string) $listing['requested_mosaic_id'],
      )['amount'] ?? '0');
    }
    catch (SymbolEngineException | \InvalidArgumentException $exception) {
      return new JsonResponse(['ok' => FALSE, 'message' => $exception->getMessage()], 502);
    }

    return new JsonResponse($this->balanceCheckResponse(
      $balance,
      (string) $listing['requested_amount'],
      (string) $listing['network'],
      (string) $listing['requested_mosaic_id'],
    ));
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
      '#form_id' => 'symbol_p2p_ad_listing_filter_form',
      '#method' => 'get',
      'status' => [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => [
          '' => $this->t('- Any -'),
          AdListingRepository::ACTIVE => $this->t('Active'),
          AdListingRepository::INSUFFICIENT_BALANCE => $this->t('Insufficient balance'),
          AdListingRepository::MATCHED => $this->t('Matched'),
          AdListingRepository::CANCELLED => $this->t('Cancelled'),
          AdListingRepository::EXPIRED => $this->t('Expired'),
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
   * @return array{network: string, address: string, public_key: string}|null
   */
  private function verifiedSymbolAccount(): ?array {
    $account = $this->entityTypeManager()->getStorage('user')->load((int) $this->currentUser()->id());
    if (!$account || !(bool) ($account->get('field_symbol_address_verified')->value ?? FALSE)) {
      return NULL;
    }
    $network = (string) ($account->get('field_symbol_network')->value ?? '');
    $address = strtoupper((string) ($account->get('field_symbol_address')->value ?? ''));
    $public_key = strtoupper((string) ($account->get('field_symbol_public_key')->value ?? ''));
    if (!in_array($network, ['mainnet', 'testnet'], TRUE) || !preg_match('/^[NT][A-Z2-7]{38}$/', $address) || !preg_match('/^[0-9A-F]{64}$/', $public_key)) {
      return NULL;
    }
    return ['network' => $network, 'address' => $address, 'public_key' => $public_key];
  }

  /**
   * @return array<int, mixed>
   */
  private function listingTableHeader(): array {
    return [
      $this->t('Listing'),
      [
        'data' => $this->t('Offers'),
        'field' => 'l.offered_mosaic_id',
        'initial_click_sort' => 'asc',
      ],
      [
        'data' => $this->t('Wants'),
        'field' => 'l.requested_mosaic_id',
        'initial_click_sort' => 'asc',
      ],
      [
        'data' => $this->t('Expires'),
        'field' => 'l.expires_at',
        'initial_click_sort' => 'asc',
      ],
    ];
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function mosaicTerm(array $listing, string $side): FormattableMarkup {
    $network = (string) $listing['network'];
    $mosaic_id = strtoupper((string) $listing[$side . '_mosaic_id']);
    $amount = $this->formatMosaicAmount((string) $listing[$side . '_amount'], $network, $mosaic_id);
    $alias = $this->formatMosaicAlias($network, $mosaic_id);
    if ($alias !== '') {
      return new FormattableMarkup('@alias<br>@mosaic_id<br>@amount', [
        '@alias' => $alias,
        '@mosaic_id' => $mosaic_id,
        '@amount' => $amount,
      ]);
    }

    return new FormattableMarkup('@mosaic_id<br>@amount', [
      '@mosaic_id' => $mosaic_id,
      '@amount' => $amount,
    ]);
  }

  /**
   * @param array<string, mixed> $listing
   *
   * @return array<string, mixed>
   */
  private function mosaicTermWithBalanceCheck(array $listing, string $side): array {
    $build = [
      'term' => [
        '#markup' => $this->mosaicTerm($listing, $side),
      ],
    ];

    if ($side === 'offered' && $this->canCheckSellerBalance($listing)) {
      $build['balance_check'] = $this->balanceCheckStatus((int) $listing['id'], 'seller');
    }
    elseif ($side === 'requested' && $this->canCheckMyBalance($listing)) {
      $build['balance_check'] = $this->balanceCheckStatus((int) $listing['id'], 'mine');
    }

    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function balanceCheckStatus(int $listing_id, string $target): array {
    $path = $target === 'seller'
      ? '/symbol-p2p/listings/' . $listing_id . '/balance-check/seller'
      : '/symbol-p2p/listings/' . $listing_id . '/balance-check/me';

    return [
      '#type' => 'container',
      '#attributes' => [
        'data-symbol-p2p-balance-check' => $target,
        'data-symbol-p2p-balance-check-url' => $path,
      ],
      'status' => [
        '#markup' => '<span data-symbol-p2p-balance-check-status>' . $this->t('Checking...') . '</span>',
      ],
    ];
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function formatBalanceCheckedAmount(array $listing): string {
    $amount = (string) ($listing['seller_balance_checked_amount'] ?? '');
    if ($amount === '') {
      return (string) $this->t('Not checked');
    }

    $network = (string) $listing['network'];
    $mosaic_id = (string) $listing['offered_mosaic_id'];
    return $this->formatMosaicAmount($amount, $network, $mosaic_id) . ' ' . $this->formatMosaicName($network, $mosaic_id);
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canTakeListing(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) !== (int) $this->currentUser()->id()
      && $this->currentUser()->hasPermission('operate symbol p2p ad listings');
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canCheckSellerBalance(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) !== (int) $this->currentUser()->id()
      && $this->currentUser()->hasPermission('view symbol p2p ad listings');
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canCheckMyBalance(array $listing): bool {
    return (string) $listing['status'] === AdListingRepository::ACTIVE
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) !== (int) $this->currentUser()->id()
      && $this->currentUser()->isAuthenticated()
      && $this->currentUser()->hasPermission('view symbol p2p ad listings');
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function canManageListing(array $listing): bool {
    return in_array((string) $listing['status'], [AdListingRepository::ACTIVE, AdListingRepository::INSUFFICIENT_BALANCE], TRUE)
      && !$this->listings->isExpired($listing)
      && (int) ($listing['seller_uid'] ?? 0) === (int) $this->currentUser()->id()
      && $this->currentUser()->hasPermission('create symbol p2p ad listings');
  }

  /**
   * @return array{ok: bool, sufficient: bool, amount: string, formattedAmount: string, message: string}
   */
  private function balanceCheckResponse(string $balance, string $required, string $network, string $mosaic_id): array {
    $sufficient = $this->compareAtomic($balance, $required) >= 0;
    $formatted = $this->formatMosaicAmount($balance, $network, $mosaic_id) . ' ' . $this->formatMosaicName($network, $mosaic_id);
    return [
      'ok' => TRUE,
      'sufficient' => $sufficient,
      'amount' => $balance,
      'formattedAmount' => $formatted,
      'message' => $sufficient
        ? (string) $this->t('Sufficient: @amount', ['@amount' => $formatted])
        : (string) $this->t('Insufficient: @amount', ['@amount' => $formatted]),
    ];
  }

  private function compareAtomic(string $left, string $right): int {
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
  }

  /**
   * @param array<string, mixed> $listing
   */
  private function expirationLabel(array $listing): string {
    if (empty($listing['expires_at'])) {
      return (string) $this->t('No expiration');
    }
    return $this->dateFormatter->format((int) $listing['expires_at'], 'custom', 'Y-m-d H:i');
  }

  private function formatMosaicAmount(string $atomic_amount, string $network, string $mosaic_id): string {
    $metadata = $this->lookupMosaicMetadata($network, $mosaic_id);
    $divisibility = $metadata['divisibility'] ?? NULL;
    if (!is_int($divisibility) || $divisibility < 0 || $divisibility > 6 || preg_match('/^\d+$/', $atomic_amount) !== 1) {
      return $atomic_amount;
    }

    if ($divisibility === 0) {
      return ltrim($atomic_amount, '0') ?: '0';
    }

    $padded = str_pad($atomic_amount, $divisibility + 1, '0', STR_PAD_LEFT);
    $whole = substr($padded, 0, -$divisibility);
    $fraction = substr($padded, -$divisibility);
    return (ltrim($whole, '0') ?: '0') . '.' . $fraction;
  }

  private function formatMosaicName(string $network, string $mosaic_id): string {
    $normalized = strtoupper($mosaic_id);
    $metadata = $this->lookupMosaicMetadata($network, $normalized);
    $aliases = $metadata['aliases'] ?? [];
    if (is_array($aliases) && isset($aliases[0]) && is_string($aliases[0]) && $aliases[0] !== '') {
      return $aliases[0] . ' (' . $normalized . ')';
    }
    return $normalized;
  }

  private function formatMosaicAlias(string $network, string $mosaic_id): string {
    $normalized = strtoupper($mosaic_id);
    $metadata = $this->lookupMosaicMetadata($network, $normalized);
    $aliases = $metadata['aliases'] ?? [];
    if (is_array($aliases) && isset($aliases[0]) && is_string($aliases[0]) && $aliases[0] !== '') {
      return $aliases[0];
    }
    return '';
  }

  /**
   * @return array<string, mixed>
   */
  private function lookupMosaicMetadata(string $network, string $mosaic_id): array {
    $normalized = strtoupper($mosaic_id);
    $overrides = \Drupal::state()->get('symbol_atomic_swap.mosaic_metadata_test_overrides', []);
    $override = $overrides[$network][$normalized] ?? NULL;
    if (is_array($override)) {
      return $override + ['mosaicId' => $normalized, 'aliases' => []];
    }

    try {
      return $this->engineClient->mosaicMetadata($network, $normalized);
    }
    catch (SymbolEngineException) {
      return ['mosaicId' => $normalized, 'aliases' => []];
    }
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
      AdListingRepository::INSUFFICIENT_BALANCE => (string) $this->t('Insufficient balance'),
      AdListingRepository::EXPIRED => (string) $this->t('Expired'),
      default => $status,
    };
  }

}
