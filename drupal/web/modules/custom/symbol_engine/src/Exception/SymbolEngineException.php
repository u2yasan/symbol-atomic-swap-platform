<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Exception;

final class SymbolEngineException extends \RuntimeException {

  /**
   * @param array<string,mixed> $details
   */
  public function __construct(
    string $message,
    public readonly int $statusCode = 0,
    public readonly ?string $engineError = NULL,
    public readonly array $details = [],
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}

