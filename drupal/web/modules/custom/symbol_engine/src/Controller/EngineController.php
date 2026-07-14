<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\symbol_engine\Exception\SymbolEngineException;
use Drupal\symbol_engine\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final class EngineController extends ControllerBase {

  public function __construct(
    private readonly SymbolEngineClient $engineClient,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_engine.client'),
      $container->get('logger.factory')->get('symbol_engine'),
    );
  }

  public function network(): JsonResponse {
    return $this->json(fn (): array => $this->engineClient->network());
  }

  public function intent(string $intentHash): JsonResponse {
    return $this->json(fn (): array => $this->engineClient->intent($intentHash));
  }

  public function projection(string $network, string $transactionHash): JsonResponse {
    return $this->json(fn (): array => $this->engineClient->projection($network, $transactionHash));
  }

  private function json(callable $callback): JsonResponse {
    try {
      return new JsonResponse($callback());
    }
    catch (SymbolEngineException $exception) {
      // Log the full engine error server-side but never disclose the raw
      // message or the decoded engine response body to the (non-admin) caller;
      // only a stable machine-readable error code and status are returned.
      $this->logger->warning('Symbol Engine request failed: @message @details', [
        '@message' => $exception->getMessage(),
        '@details' => json_encode($exception->details),
      ]);
      return new JsonResponse([
        'error' => $exception->engineError ?? 'symbol_engine_error',
        'message' => 'The Symbol Engine request could not be completed.',
      ], $exception->statusCode > 0 ? $exception->statusCode : 502);
    }
    catch (\InvalidArgumentException $exception) {
      $this->logger->warning('Symbol Engine proxy received an invalid request: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'invalid_request',
        'message' => 'The request was invalid.',
      ], 400);
    }
  }

}
