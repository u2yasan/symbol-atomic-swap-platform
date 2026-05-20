<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\symbol_engine\Exception\SymbolEngineException;
use Drupal\symbol_engine\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final class EngineController extends ControllerBase {

  public function __construct(
    private readonly SymbolEngineClient $engineClient,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_engine.client'),
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
      return new JsonResponse([
        'error' => $exception->engineError ?? 'symbol_engine_error',
        'message' => $exception->getMessage(),
        'details' => $exception->details,
      ], $exception->statusCode > 0 ? $exception->statusCode : 502);
    }
    catch (\InvalidArgumentException $exception) {
      return new JsonResponse([
        'error' => 'invalid_request',
        'message' => $exception->getMessage(),
      ], 400);
    }
  }

}
