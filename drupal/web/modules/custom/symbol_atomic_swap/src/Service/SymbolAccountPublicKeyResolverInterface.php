<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

interface SymbolAccountPublicKeyResolverInterface {

  public function resolve(string $network, string $address): string;

}
