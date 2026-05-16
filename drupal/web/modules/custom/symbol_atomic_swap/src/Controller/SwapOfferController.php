<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferCosignatureRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferNotificationRepository;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolAccountPublicKeyResolverInterface;
use Drupal\symbol_atomic_swap\Service\SymbolAddressDeriver;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SwapOfferController extends ControllerBase {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly SwapOfferNotificationRepository $notifications,
    private readonly SwapOfferCosignatureRepository $cosignatures,
    private readonly SymbolAccountPublicKeyResolverInterface $accountPublicKeyResolver,
    private readonly SymbolAddressDeriver $addressDeriver,
    private readonly SymbolEngineClient $engineClient,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('symbol_atomic_swap.offer_notification_repository'),
      $container->get('symbol_atomic_swap.offer_cosignature_repository'),
      $container->get('symbol_atomic_swap.account_public_key_resolver'),
      $container->get('symbol_atomic_swap.address_deriver'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  public function list(): array {
    $filters = $this->filtersFromRequest();
    $rows = [];
    foreach ($this->offers->search($filters, 100, NULL) as $offer) {
      $rows[] = [
        Link::fromTextAndUrl((string) $offer['label'], Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]))->toString(),
        $this->stateLabel((string) $offer['state']),
        $offer['changed'] ? $this->dateFormatter->format((int) $offer['changed'], 'short') : '',
        [
          'data' => [
            '#markup' => implode(' | ', $this->operationLinks($offer)),
          ],
        ],
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['symbol_atomic_swap/qr']],
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
          $this->t('Changed'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No swap offers have been created.'),
      ],
    ];
  }

  public function mosaicMetadata(string $network, string $mosaicId): JsonResponse {
    try {
      $metadata = $this->engineClient->mosaicMetadata($network, $mosaicId);
      $symbol_account = $this->verifiedSymbolAccount();
      if ($symbol_account && $symbol_account['network'] === $network) {
        $metadata['balance'] = $this->engineClient->accountMosaicBalance($network, $symbol_account['address'], $mosaicId)['amount'] ?? '0';
      }
      else {
        $metadata['balance'] = NULL;
      }
      return new JsonResponse($metadata);
    }
    catch (SymbolEngineException $exception) {
      return new JsonResponse([
        'found' => FALSE,
        'mosaicId' => strtoupper($mosaicId),
        'aliases' => [],
        'error' => $exception->engineError ?: 'mosaic_lookup_failed',
      ], $exception->statusCode ?: 502);
    }
    catch (\InvalidArgumentException) {
      return new JsonResponse([
        'found' => FALSE,
        'mosaicId' => strtoupper($mosaicId),
        'aliases' => [],
        'error' => 'invalid_mosaic_id',
      ], 400);
    }
  }

  /**
   * @return array{network: string, address: string}|null
   */
  private function verifiedSymbolAccount(): ?array {
    $account = $this->entityTypeManager()->getStorage('user')->load((int) $this->currentUser()->id());
    if (!$account || !(bool) ($account->get('field_symbol_address_verified')->value ?? FALSE)) {
      return NULL;
    }
    $network = (string) ($account->get('field_symbol_network')->value ?? '');
    $address = strtoupper((string) ($account->get('field_symbol_address')->value ?? ''));
    if (!in_array($network, ['mainnet', 'testnet'], TRUE) || !preg_match('/^[NT][A-Z2-7]{38}$/', $address)) {
      return NULL;
    }
    return [
      'network' => $network,
      'address' => $address,
    ];
  }

  public function view($offerId): array {
    $offer = $this->loadOffer((int) $offerId);
    $qr_payload = $this->decodedQrPayload($offer);

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
          [$this->t('Intent hash'), $this->hashValue((string) ($offer['intent_hash'] ?: ''))],
          [$this->t('Root transaction hash'), $this->hashValue((string) ($offer['root_transaction_hash'] ?? ''))],
          [$this->t('Transaction hash'), $this->hashValue((string) ($offer['transaction_hash'] ?: ''))],
          [$this->t('Created'), $offer['created'] ? $this->dateFormatter->format((int) $offer['created'], 'short') : ''],
          [$this->t('Changed'), $offer['changed'] ? $this->dateFormatter->format((int) $offer['changed'], 'short') : ''],
          [$this->t('Expired at'), !empty($offer['expired_at']) ? $this->dateFormatter->format((int) $offer['expired_at'], 'short') : ''],
        ]),
      ],
      'legs' => [
        '#type' => 'details',
        '#title' => $this->t('Trade terms'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Side'),
            $this->t('Signer address'),
            $this->t('Recipient address'),
            $this->t('Mosaic ID'),
            $this->t('Amount'),
          ],
          '#rows' => [
            [
              $this->t('Maker pays'),
              ['data' => $this->addressValue((string) $offer['leg1_signer_public_key'], (string) $offer['network'])],
              ['data' => $this->hashValue((string) ($offer['leg1_recipient_address'] ?: 'Taker decides on accept'))],
              ['data' => $this->hashValue((string) $offer['leg1_mosaic_id'])],
              (string) $offer['leg1_amount'],
            ],
            [
              $this->t('Maker wants'),
              ['data' => $this->addressValue((string) $offer['leg2_signer_public_key'], (string) $offer['network'])],
              ['data' => $this->hashValue((string) $offer['leg2_recipient_address'])],
              ['data' => $this->hashValue((string) $offer['leg2_mosaic_id'])],
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
      $qr_url = Url::fromRoute('symbol_atomic_swap.offer_qr_payload', [
        'offerId' => $offer['id'],
        'intentHash' => $offer['intent_hash'],
      ], ['absolute' => TRUE])->toString();
      $build['qr'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['symbol-atomic-swap-qr'],
          'data-qr-payload' => $qr_url,
        ],
        'url' => [
          '#type' => 'container',
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => (string) $this->t('QR URL'),
          ],
          'value' => $this->copyValue($qr_url),
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

    if ($this->isAggregateBondedPayload($qr_payload)) {
      $build['aggregate_bonded_workflow'] = $this->aggregateBondedWorkflow($offer, $qr_payload);
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

    $cosignature_rows = [];
    foreach ($this->cosignatures->findByOffer((int) $offer['id']) as $cosignature) {
      $cosignature_rows[] = [
        ['data' => $this->hashValue((string) $cosignature['parent_hash'])],
        ['data' => $this->addressValue((string) $cosignature['signer_public_key'], (string) $offer['network'])],
        !empty($cosignature['trusted_parent_hash']) ? $this->t('Yes') : $this->t('No'),
        $cosignature['created'] ? $this->dateFormatter->format((int) $cosignature['created'], 'short') : '',
      ];
    }
    if ($cosignature_rows !== []) {
      $build['cosignatures'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Cosignature parent hash'),
          $this->t('Signer address'),
          $this->t('Trusted parent hash'),
          $this->t('Submitted'),
        ],
        '#rows' => $cosignature_rows,
        '#caption' => $this->t('Stored cosignatures'),
      ];
    }

    $is_aggregate_bonded = $this->isAggregateBondedPayload($qr_payload);
    $can_submit_bonded_cosignature = $this->offers->canSubmitBondedCosignature($offer);
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
      'sign_with_sss' => [
        '#type' => 'link',
        '#title' => $this->t('Sign with SSS'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_sign_with_sss', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && $this->offers->canSubmitSignedPayload($offer),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'accept' => [
        '#type' => 'link',
        '#title' => $this->t('Accept offer'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_accept', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && $this->offers->canAccept($offer),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'submit_aggregate_signer_json' => [
        '#type' => 'link',
        '#title' => $this->t('Submit aggregate signer JSON'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_submit_aggregate_signer_json', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && $this->offers->canSubmitSignedPayload($offer),
        '#attributes' => ['class' => ['button']],
      ],
      'submit_cosignature' => [
        '#type' => 'link',
        '#title' => $this->t('Submit cosignature JSON'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_submit_cosignature', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && !$is_aggregate_bonded
          && $this->offers->canSubmitSignedPayload($offer),
        '#attributes' => ['class' => ['button']],
      ],
      'cosign_with_sss' => [
        '#type' => 'link',
        '#title' => $can_submit_bonded_cosignature ? $this->t('Cosign and announce partial with SSS') : $this->t('Cosign with SSS'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_cosign_with_sss', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && ((!$is_aggregate_bonded && $this->offers->canSubmitSignedPayload($offer)) || $can_submit_bonded_cosignature),
        '#attributes' => ['class' => ['button']],
      ],
      'assemble_signed_payload' => [
        '#type' => 'link',
        '#title' => $this->t('Assemble signed payload'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_assemble_signed_payload', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && !$is_aggregate_bonded
          && $this->offers->canSubmitSignedPayload($offer),
        '#attributes' => ['class' => ['button']],
      ],
      'announce' => [
        '#type' => 'link',
        '#title' => $this->t('Announce transaction'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_announce', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && !$is_aggregate_bonded
          && $this->offers->canAnnounce($offer),
        '#attributes' => ['class' => ['button']],
      ],
      'bonded_partial_announce' => [
        '#type' => 'link',
        '#title' => $this->t('Sign hash lock and announce partial'),
        '#url' => Url::fromRoute('symbol_atomic_swap.offer_bonded_partial_announce', ['offerId' => $offer['id']]),
        '#access' => $this->currentUser()->hasPermission('operate symbol atomic swap offers')
          && $this->canRunBondedPartialAnnouncement($offer, $qr_payload),
        '#attributes' => ['class' => ['button', 'button--primary']],
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

  public function qrPayload($offerId, string $intentHash): array {
    $offer = $this->loadOffer((int) $offerId);
    if (strtoupper($intentHash) !== strtoupper((string) ($offer['intent_hash'] ?? ''))) {
      throw new NotFoundHttpException();
    }

    $qr_payload = $this->decodedQrPayload($offer);
    if ($qr_payload === []) {
      throw new NotFoundHttpException();
    }

    $raw_json = json_encode($qr_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($raw_json)) {
      throw new NotFoundHttpException();
    }
    $scan_text = $this->qrScanText($raw_json);
    $unsigned_payload = is_string($qr_payload['unsignedPayload'] ?? NULL) ? $qr_payload['unsignedPayload'] : '';
    $is_aggregate_bonded = $this->isAggregateBondedPayload($qr_payload);

    $build = [
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['symbol_atomic_swap/qr']],
      'summary' => [
        '#type' => 'details',
        '#title' => $this->t('Summary'),
        '#open' => TRUE,
        'table' => $this->keyValueTable([
          [$this->t('Offer'), (string) $offer['label']],
          [$this->t('Network'), (string) $offer['network']],
          [$this->t('Aggregate type'), $is_aggregate_bonded ? (string) $this->t('aggregate bonded') : (string) $this->t('aggregate complete')],
          [$this->t('Intent hash'), $this->hashValue((string) $offer['intent_hash'])],
        ]),
      ],
      'scan_text' => [
        '#type' => 'details',
        '#title' => $this->t('QR scan text'),
        '#open' => TRUE,
        'copy' => [
          '#type' => 'container',
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => (string) $this->t('Copy QR scan text'),
          ],
          'value' => $this->copyValue($scan_text),
        ],
        'payload' => [
          '#type' => 'textarea',
          '#title' => $this->t('QR scan text'),
          '#value' => $scan_text,
          '#rows' => 6,
          '#attributes' => [
            'readonly' => 'readonly',
            'spellcheck' => 'false',
          ],
        ],
      ],
      'unsigned_payload' => array_filter([
        '#type' => 'details',
        '#title' => $this->t('Unsigned payload'),
        '#open' => TRUE,
        'copy' => $unsigned_payload !== '' ? [
          '#type' => 'container',
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => (string) $this->t('Copy unsigned payload'),
          ],
          'value' => $this->copyValue($unsigned_payload),
        ] : NULL,
        'payload' => [
          '#type' => 'textarea',
          '#title' => $this->t('Unsigned payload'),
          '#value' => $unsigned_payload,
          '#rows' => 8,
          '#attributes' => [
            'readonly' => 'readonly',
            'spellcheck' => 'false',
          ],
        ],
      ]),
      'raw_json' => [
        '#type' => 'details',
        '#title' => $this->t('QR payload JSON'),
        '#open' => FALSE,
        'payload' => [
          '#type' => 'textarea',
          '#title' => $this->t('QR payload JSON'),
          '#value' => $raw_json,
          '#rows' => 16,
          '#attributes' => [
            'readonly' => 'readonly',
            'spellcheck' => 'false',
          ],
        ],
      ],
    ];
    if ($is_aggregate_bonded) {
      $build['aggregate_bonded_workflow'] = $this->aggregateBondedWorkflow($offer, $qr_payload);
    }

    return $build;
  }

  public function title($offerId): string {
    return (string) $this->loadOffer((int) $offerId)['label'];
  }

  public function qrPayloadTitle($offerId, string $intentHash = ''): string {
    return (string) $this->t('QR payload for @label', [
      '@label' => (string) $this->loadOffer((int) $offerId)['label'],
    ]);
  }

  public function publicKeyFromAddress(string $network, string $address): JsonResponse {
    try {
      $public_key = $this->accountPublicKeyResolver->resolve($network, $address);
      return new JsonResponse([
        'network' => $network,
        'address' => strtoupper($address),
        'publicKey' => $public_key,
      ]);
    }
    catch (SymbolEngineException $exception) {
      return new JsonResponse(['error' => $exception->engineError ?? 'symbol_engine_error'], $exception->statusCode ?: 503);
    }
    catch (\InvalidArgumentException) {
      return new JsonResponse(['error' => 'account_public_key_not_found'], 404);
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function loadOffer(int $offerId): array {
    $offer = $this->offers->find($offerId);
    if (!$offer) {
      throw new NotFoundHttpException();
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
   * @param array<string, mixed> $offer
   *
   * @return array<string, mixed>
   */
  private function decodedQrPayload(array $offer): array {
    if (empty($offer['qr_payload'])) {
      return [];
    }
    try {
      $decoded = json_decode((string) $offer['qr_payload'], TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\JsonException) {
      return [];
    }
  }

  private function qrScanText(string $raw_json): string {
    return 'symbol-swap:v1:' . rtrim(strtr(base64_encode($raw_json), '+/', '-_'), '=');
  }

  /**
   * @param array<string, mixed> $qr_payload
   */
  private function isAggregateBondedPayload(array $qr_payload): bool {
    return ($qr_payload['type'] ?? '') === 'symbol-aggregate-bonded';
  }

  /**
   * @param array<string, mixed> $offer
   * @param array<string, mixed> $qr_payload
   */
  private function canRunBondedPartialAnnouncement(array $offer, array $qr_payload): bool {
    return $this->isAggregateBondedPayload($qr_payload)
      && in_array((string) ($offer['state'] ?? ''), ['root_signed', 'signed'], TRUE)
      && !empty($offer['intent_hash'])
      && !empty($offer['root_signed_payload'])
      && !empty($offer['root_transaction_hash'])
      && !empty($offer['leg2_signer_public_key']);
  }

  /**
   * @param array<string, mixed> $offer
   * @param array<string, mixed> $qr_payload
   */
  private function aggregateBondedWorkflow(array $offer, array $qr_payload): array {
    $required_cosigners = array_values(array_filter(
      array_map('strval', is_array($qr_payload['requiredCosigners'] ?? NULL) ? $qr_payload['requiredCosigners'] : []),
      static fn (string $value): bool => $value !== '',
    ));
    $aggregate_signer = $required_cosigners[0] ?? (string) $offer['leg1_signer_public_key'];
    $taker_cosigner = $required_cosigners[1] ?? (string) $offer['leg2_signer_public_key'];
    $hash_lock_signer = $taker_cosigner;
    $hash_lock = is_array($qr_payload['hashLock'] ?? NULL) ? $qr_payload['hashLock'] : [];

    return [
      '#type' => 'details',
      '#title' => $this->t('Aggregate bonded partial announcement steps'),
      '#open' => TRUE,
      'warning' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'text' => [
          '#markup' => $this->t('This is not an aggregate complete transaction. Do not use the complete-only assemble-and-announce path for partial announcement.'),
        ],
      ],
      'roles' => $this->keyValueTable([
        [$this->t('Aggregate signer'), $this->addressValue(strtoupper($aggregate_signer), (string) $offer['network'])],
        [$this->t('Taker cosigner'), $this->addressValue(strtoupper($taker_cosigner), (string) $offer['network'])],
        [$this->t('Hash lock signer'), $this->addressValue(strtoupper($hash_lock_signer), (string) $offer['network'])],
        [$this->t('Hash lock mosaic'), $this->hashValue(strtoupper((string) ($hash_lock['mosaicId'] ?? '')))],
        [$this->t('Hash lock amount'), (string) ($hash_lock['amount'] ?? '')],
        [$this->t('Hash lock duration blocks'), (string) ($hash_lock['duration'] ?? '')],
      ]),
      'steps' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Required order'),
        '#list_type' => 'ol',
        '#items' => [
          $this->t('Taker initiates this aggregate bonded transaction from the accept page and pays the 10 XYM hash lock.'),
          $this->t('Taker shares the QR URL, QR scan text, or unsigned payload with the aggregate signer.'),
          $this->t('Aggregate signer root-signs the unsigned aggregate bonded payload. It must not be announced as aggregate complete.'),
          $this->t('Submit the root-signed aggregate payload or aggregate signer JSON in Drupal so Symbol Engine records the bonded transaction hash.'),
          $this->t('Taker builds the hash lock with Symbol Engine POST /v1/hash-lock/build using intentHash, the taker signerPublicKey, and deadlineHours, then signs that hash lock transaction.'),
          $this->t('Announce the signed hash lock with POST /v1/hash-lock/announce and wait until the node accepts it.'),
          $this->t('Announce the signed aggregate bonded transaction as partial with POST /v1/transactions/announce-partial using this intent hash.'),
          $this->t('Taker cosigns the partial aggregate, then announces or submits that cosignature. After confirmation/finalization, sync the projection.'),
        ],
      ],
      'api_payloads' => [
        '#type' => 'details',
        '#title' => $this->t('Engine API payloads'),
        '#open' => FALSE,
        'hash_lock_build' => [
          '#type' => 'textarea',
          '#title' => $this->t('POST /v1/hash-lock/build'),
          '#value' => json_encode([
            'intentHash' => (string) $offer['intent_hash'],
            'signerPublicKey' => $hash_lock_signer,
            'deadlineHours' => 2,
          ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
          '#rows' => 6,
          '#attributes' => [
            'readonly' => 'readonly',
            'spellcheck' => 'false',
          ],
        ],
        'partial_announce' => [
          '#type' => 'textarea',
          '#title' => $this->t('POST /v1/transactions/announce-partial'),
          '#value' => json_encode([
            'intentHash' => (string) $offer['intent_hash'],
          ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
          '#rows' => 4,
          '#attributes' => [
            'readonly' => 'readonly',
            'spellcheck' => 'false',
          ],
        ],
      ],
    ];
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
    if (!$this->currentUser()->hasPermission('administer symbol atomic swap offers')) {
      unset($filters['owner']);
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
        '#template' => '<form method="get" action="{{ action }}"><label>{{ q_label }} <input name="q" value="{{ q }}" /></label> <label>{{ state_label }} <select name="state"><option value="">{{ any }}</option>{% for value,label in states %}<option value="{{ value }}"{% if value == state %} selected{% endif %}>{{ label }}</option>{% endfor %}</select></label> <label>{{ network_label }} <select name="network"><option value="">{{ any }}</option><option value="testnet"{% if network == "testnet" %} selected{% endif %}>testnet</option><option value="mainnet"{% if network == "mainnet" %} selected{% endif %}>mainnet</option></select></label>{% if show_owner %} <label>{{ owner_label }} <input name="owner" value="{{ owner }}" size="8" /></label>{% endif %} <button class="button" type="submit">{{ apply }}</button> <a class="button" href="{{ action }}">{{ reset }}</a></form>',
        '#context' => [
          'action' => Url::fromRoute('symbol_atomic_swap.offer_list')->toString(),
          'q_label' => $this->t('Search'),
          'state_label' => $this->t('State'),
          'network_label' => $this->t('Network'),
          'show_owner' => $this->currentUser()->hasPermission('administer symbol atomic swap offers'),
          'owner_label' => $this->t('Owner UID'),
          'apply' => $this->t('Apply'),
          'reset' => $this->t('Reset'),
          'any' => $this->t('- Any -'),
          'q' => $filters['q'] ?? '',
          'state' => $filters['state'] ?? '',
          'network' => $filters['network'] ?? '',
          'owner' => $this->currentUser()->hasPermission('administer symbol atomic swap offers') ? ($filters['owner'] ?? '') : '',
          'states' => [
            'open' => 'open',
            'draft' => 'draft',
            'qr_generated' => 'qr_generated',
            'root_signed' => 'root_signed',
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
    $is_aggregate_bonded = $this->isAggregateBondedPayload($this->decodedQrPayload($offer));
    $operations = [
      Link::fromTextAndUrl($this->t('View'), Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]))->toString(),
    ];
    if ($this->currentUser()->hasPermission('operate symbol atomic swap offers')) {
      $can_submit_bonded_cosignature = $this->offers->canSubmitBondedCosignature($offer);
      if ($this->offers->canAccept($offer)) {
        $operations[] = Link::fromTextAndUrl($this->t('Accept offer'), Url::fromRoute('symbol_atomic_swap.offer_accept', ['offerId' => $offer['id']]))->toString();
      }
      if ($this->offers->canSubmitSignedPayload($offer)) {
        $operations[] = Link::fromTextAndUrl($this->t('Submit signed payload'), Url::fromRoute('symbol_atomic_swap.offer_submit_signed_payload', ['offerId' => $offer['id']]))->toString();
        $operations[] = Link::fromTextAndUrl($this->t('Sign with SSS'), Url::fromRoute('symbol_atomic_swap.offer_sign_with_sss', ['offerId' => $offer['id']]))->toString();
        $operations[] = Link::fromTextAndUrl($this->t('Submit aggregate signer JSON'), Url::fromRoute('symbol_atomic_swap.offer_submit_aggregate_signer_json', ['offerId' => $offer['id']]))->toString();
        if (!$is_aggregate_bonded) {
          $operations[] = Link::fromTextAndUrl($this->t('Submit cosignature JSON'), Url::fromRoute('symbol_atomic_swap.offer_submit_cosignature', ['offerId' => $offer['id']]))->toString();
          $operations[] = Link::fromTextAndUrl($this->t('Cosign with SSS'), Url::fromRoute('symbol_atomic_swap.offer_cosign_with_sss', ['offerId' => $offer['id']]))->toString();
          $operations[] = Link::fromTextAndUrl($this->t('Assemble signed payload'), Url::fromRoute('symbol_atomic_swap.offer_assemble_signed_payload', ['offerId' => $offer['id']]))->toString();
        }
      }
      if ($can_submit_bonded_cosignature) {
        $operations[] = Link::fromTextAndUrl($this->t('Cosign and announce partial with SSS'), Url::fromRoute('symbol_atomic_swap.offer_cosign_with_sss', ['offerId' => $offer['id']]))->toString();
      }
      if (!$is_aggregate_bonded && $this->offers->canAnnounce($offer)) {
        $operations[] = Link::fromTextAndUrl($this->t('Announce transaction'), Url::fromRoute('symbol_atomic_swap.offer_announce', ['offerId' => $offer['id']]))->toString();
      }
      if ($this->canRunBondedPartialAnnouncement($offer, $this->decodedQrPayload($offer))) {
        $operations[] = Link::fromTextAndUrl(
          $this->t('Sign hash lock and announce partial'),
          Url::fromRoute('symbol_atomic_swap.offer_bonded_partial_announce', ['offerId' => $offer['id']]),
        )->toString();
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
      $rows[] = [$row[0], is_array($row[1]) ? ['data' => $row[1]] : $row[1]];
    }

    return [
      '#type' => 'table',
      '#rows' => $rows,
    ];
  }

  private function hashValue(string $value): array|string {
    if ($value === '') {
      return '';
    }

    return $this->copyValue($value, ['symbol-atomic-swap-hash']);
  }

  private function addressValue(string $public_key, string $network): array|string {
    $public_key = strtoupper(trim($public_key));
    if ($public_key === '') {
      return (string) $this->t('Taker decides on accept');
    }

    try {
      return $this->hashValue($this->addressDeriver->deriveFromPublicKey($public_key, $network));
    }
    catch (\InvalidArgumentException) {
      return '';
    }
  }

  /**
   * @param string[] $value_classes
   */
  private function copyValue(string $value, array $value_classes = ['symbol-atomic-swap-long-value']): array|string {
    if ($value === '') {
      return '';
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['symbol-atomic-swap-copy']],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'code',
        '#value' => $value,
        '#attributes' => ['class' => $value_classes],
      ],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => (string) $this->t('Copy'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--small', 'symbol-atomic-swap-copy__button'],
          'data-symbol-copy' => $value,
        ],
      ],
    ];
  }

}
