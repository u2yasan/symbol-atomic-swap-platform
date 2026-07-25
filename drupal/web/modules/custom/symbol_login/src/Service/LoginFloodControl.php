<?php

declare(strict_types=1);

namespace Drupal\symbol_login\Service;

use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\Request;

final class LoginFloodControl {

  private const WINDOW_SECONDS = 60;

  public function __construct(
    private readonly FloodInterface $flood,
  ) {}

  public function consume(Request $request, string $operation, int $threshold): bool {
    $identifier = hash('sha256', (string) ($request->getClientIp() ?: 'unknown'));
    $event = 'symbol_login.' . $operation;
    if (!$this->flood->isAllowed($event, $threshold, self::WINDOW_SECONDS, $identifier)) {
      return FALSE;
    }

    $this->flood->register($event, self::WINDOW_SECONDS, $identifier);
    return TRUE;
  }

  public function retryAfterSeconds(): int {
    return self::WINDOW_SECONDS;
  }

}
