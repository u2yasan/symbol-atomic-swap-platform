<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Service;

use Drupal\Core\State\StateInterface;

final class SymbolEngineAccountPublicKeyResolver implements SymbolAccountPublicKeyResolverInterface {

  public function __construct(
    private readonly SymbolEngineClient $engineClient,
    private readonly SymbolAddressDeriver $addressDeriver,
    private readonly StateInterface $state,
  ) {}

  public function resolve(string $network, string $address): string {
    $address = strtoupper(trim($address));
    $override = $this->testOverride($network, $address);
    if ($override !== NULL) {
      return $override;
    }

    $lookup = $this->engineClient->accountPublicKey($network, $address);
    $public_key = strtoupper((string) ($lookup['publicKey'] ?? ''));
    if (preg_match('/^[0-9A-F]{64}$/', $public_key) !== 1) {
      throw new \InvalidArgumentException('Account public key was not found.');
    }
    if ($this->addressDeriver->deriveFromPublicKey($public_key, $network) !== $address) {
      throw new \InvalidArgumentException('Account public key does not match address.');
    }
    return $public_key;
  }

  private function testOverride(string $network, string $address): ?string {
    $overrides = $this->state->get('symbol_atomic_swap.account_public_key_test_overrides', []);
    if (!is_array($overrides)) {
      return NULL;
    }

    $public_key = strtoupper((string) ($overrides[$network][$address] ?? ''));
    return preg_match('/^[0-9A-F]{64}$/', $public_key) === 1 ? $public_key : NULL;
  }

}
