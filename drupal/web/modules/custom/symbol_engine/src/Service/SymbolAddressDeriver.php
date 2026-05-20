<?php

declare(strict_types=1);

namespace Drupal\symbol_engine\Service;

final class SymbolAddressDeriver {

  private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

  public function deriveFromPublicKey(string $public_key, string $network): string {
    $public_key = strtoupper(trim($public_key));
    if (preg_match('/^[0-9A-F]{64}$/', $public_key) !== 1) {
      throw new \InvalidArgumentException('Public key must be 64 hex characters.');
    }

    $network_byte = match ($network) {
      'mainnet' => 0x68,
      'testnet' => 0x98,
      default => throw new \InvalidArgumentException('Network must be mainnet or testnet.'),
    };

    $public_key_bytes = hex2bin($public_key);
    if ($public_key_bytes === FALSE) {
      throw new \InvalidArgumentException('Public key must be valid hex.');
    }

    $sha3 = hash('sha3-256', $public_key_bytes, TRUE);
    $ripemd = hash('ripemd160', $sha3, TRUE);
    $body = chr($network_byte) . $ripemd;
    $checksum = substr(hash('sha3-256', $body, TRUE), 0, 3);

    return $this->base32Encode($body . $checksum);
  }

  private function base32Encode(string $bytes): string {
    $bits = '';
    foreach (str_split($bytes) as $byte) {
      $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $encoded = '';
    for ($offset = 0; $offset < strlen($bits); $offset += 5) {
      $chunk = substr($bits, $offset, 5);
      if (strlen($chunk) < 5) {
        $chunk = str_pad($chunk, 5, '0');
      }
      $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
    }

    return $encoded;
  }

}
