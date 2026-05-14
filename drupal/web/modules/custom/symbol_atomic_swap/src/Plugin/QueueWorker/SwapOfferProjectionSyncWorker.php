<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SwapOfferProjectionSynchronizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs one swap offer from Symbol Engine projection state.
 *
 * @QueueWorker(
 *   id = "symbol_atomic_swap_projection_sync",
 *   title = @Translation("Symbol atomic swap projection sync"),
 *   cron = {"time" = 30}
 * )
 */
final class SwapOfferProjectionSyncWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SwapOfferProjectionSynchronizer $synchronizer,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('symbol_atomic_swap.offer_projection_synchronizer'),
      $container->get('logger.factory'),
    );
  }

  public function processItem($data): void {
    $offer_id = is_array($data) && isset($data['offer_id']) ? (int) $data['offer_id'] : 0;
    if ($offer_id <= 0) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Projection sync queue item skipped because offer_id is missing.');
      return;
    }

    try {
      $result = $this->synchronizer->sync($offer_id);
      $this->loggerFactory->get('symbol_atomic_swap')->info('Projection sync queue item processed for offer @offer_id: @reason', [
        '@offer_id' => $offer_id,
        '@reason' => $result['reason'],
      ]);
    }
    catch (SymbolEngineException $exception) {
      $this->loggerFactory->get('symbol_atomic_swap')->error('Projection sync failed for offer @offer_id: @message', [
        '@offer_id' => $offer_id,
        '@message' => $exception->getMessage(),
      ]);
      throw $exception;
    }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->loggerFactory->get('symbol_atomic_swap')->warning('Projection sync skipped for offer @offer_id: @message', [
        '@offer_id' => $offer_id,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
