<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Exception;

final class SymbolEngineException extends \RuntimeException {

  public function __construct(
    string $message,
    public readonly int $statusCode = 0,
    public readonly ?string $engineError = NULL,
    public readonly array $details = [],
  ) {
    parent::__construct($message, $statusCode);
  }

}
