<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class HealthController extends ControllerBase {

  public function health(): JsonResponse {
    return new JsonResponse([
      'status' => 'ok',
      'module' => 'symbol_atomic_swap',
    ]);
  }

}
