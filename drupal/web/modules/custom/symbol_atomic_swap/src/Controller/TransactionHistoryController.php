<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Repository\SwapOfferRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class TransactionHistoryController extends ControllerBase {

  public function __construct(
    private readonly SwapOfferRepository $offers,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_atomic_swap.offer_repository'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  public function list(): array {
    $filters = $this->filtersFromRequest() + ['has_transaction_hash' => '1'];
    $owner_id = $this->currentUser()->hasPermission('administer symbol atomic swap offers')
      ? NULL
      : (int) $this->currentUser()->id();
    $rows = [];

    foreach ($this->offers->search($filters, 100, $owner_id) as $offer) {
      $state = (string) $offer['state'];
      $rows[] = [
        ['data' => $this->hashValue((string) $offer['transaction_hash'])],
        Link::fromTextAndUrl((string) $offer['label'], Url::fromRoute('symbol_atomic_swap.offer_view', ['offerId' => $offer['id']]))->toString(),
        (string) $offer['network'],
        $state,
        $state === 'finalized' ? $this->t('Yes') : $this->t('No'),
        (string) ($offer['projection_state'] ?: ''),
        (string) ($offer['block_height'] ?: ''),
        (string) ($offer['finalized_height'] ?: ''),
        !empty($offer['projection_updated_at']) ? (string) $offer['projection_updated_at'] : '',
        $offer['changed'] ? $this->dateFormatter->format((int) $offer['changed'], 'short') : '',
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['symbol_atomic_swap/qr']],
      'filters' => $this->filterForm($filters),
      'transactions' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Transaction hash'),
          $this->t('Offer'),
          $this->t('Network'),
          $this->t('State'),
          $this->t('Completed'),
          $this->t('Projection state'),
          $this->t('Block height'),
          $this->t('Finalized height'),
          $this->t('Projection updated at'),
          $this->t('Changed'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No swap transactions have been tracked.'),
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
    foreach (['state', 'network', 'q'] as $key) {
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
      '#type' => 'inline_template',
      '#template' => '<form method="get" action="{{ action }}"><label>{{ q_label }} <input name="q" value="{{ q }}" /></label> <label>{{ state_label }} <select name="state"><option value="">{{ any }}</option>{% for value,label in states %}<option value="{{ value }}"{% if value == state %} selected{% endif %}>{{ label }}</option>{% endfor %}</select></label> <label>{{ network_label }} <select name="network"><option value="">{{ any }}</option><option value="testnet"{% if network == "testnet" %} selected{% endif %}>testnet</option><option value="mainnet"{% if network == "mainnet" %} selected{% endif %}>mainnet</option></select></label> <button class="button" type="submit">{{ apply }}</button> <a class="button" href="{{ action }}">{{ reset }}</a></form>',
      '#context' => [
        'action' => Url::fromRoute('symbol_atomic_swap.transaction_history')->toString(),
        'q_label' => $this->t('Search'),
        'state_label' => $this->t('State'),
        'network_label' => $this->t('Network'),
        'apply' => $this->t('Apply'),
        'reset' => $this->t('Reset'),
        'any' => $this->t('- Any -'),
        'q' => $filters['q'] ?? '',
        'state' => $filters['state'] ?? '',
        'network' => $filters['network'] ?? '',
        'states' => [
          'signed' => 'signed',
          'announced' => 'announced',
          'unconfirmed' => 'unconfirmed',
          'confirmed' => 'confirmed',
          'finalized' => 'finalized',
          'failed' => 'failed',
          'rolled_back' => 'rolled_back',
        ],
      ],
    ];
  }

  private function hashValue(string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['symbol-atomic-swap-copy']],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'code',
        '#value' => $value,
        '#attributes' => ['class' => ['symbol-atomic-swap-hash']],
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
