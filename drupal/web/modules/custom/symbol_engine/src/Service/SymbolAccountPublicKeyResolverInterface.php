<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Service;

interface SymbolAccountPublicKeyResolverInterface {

  public function resolve(string $network, string $address): string;

}
