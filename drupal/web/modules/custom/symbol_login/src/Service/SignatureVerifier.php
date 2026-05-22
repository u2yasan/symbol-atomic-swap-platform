<?php

namespace Drupal\symbol_login\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\symbol_engine\Exception\SymbolEngineException;

/**
 * Verifies Symbol Ed25519 signatures and address ownership.
 */
final class SignatureVerifier {

  private const NETWORK_BYTES = [
    'mainnet' => 0x68,
    'testnet' => 0x98,
  ];

  private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Verifies the signed login challenge.
   */
  public function verify(string $address, string $publicKey, string $signature, string $message): void {
    $address = $this->normalizeAddress($address);
    $publicKey = strtolower(trim($publicKey));
    $signature = strtolower(trim($signature));

    if (!preg_match('/^[0-9a-f]{64}$/', $publicKey)) {
      throw new SymbolLoginException('Invalid public key format.');
    }
    if (!preg_match('/^[0-9a-f]{128}$/', $signature)) {
      throw new SymbolLoginException('Invalid signature format.');
    }

    $expectedAddress = $this->addressFromPublicKey($publicKey);
    if (!hash_equals($expectedAddress, $address)) {
      throw new SymbolLoginException('Address does not match public key.');
    }

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
      throw new SymbolLoginException('PHP sodium extension is required for signature verification.');
    }

    $valid = sodium_crypto_sign_verify_detached(
      hex2bin($signature),
      $message,
      hex2bin($publicKey),
    );
    if (!$valid) {
      throw new SymbolLoginException('Signature verification failed.');
    }
  }

  public function normalizeAddress(string $address): string {
    return strtoupper(str_replace(['-', ' '], '', trim($address)));
  }

  public function addressFromPublicKey(string $publicKey): string {
    $networkType = (string) ($this->configFactory->get('symbol_login.settings')->get('network_type') ?: 'testnet');
    $network_byte = self::NETWORK_BYTES[$networkType] ?? $this->networkIdentifierFromProfile($networkType);
    if ($network_byte === NULL) {
      throw new SymbolLoginException('Unsupported Symbol network type.');
    }

    $publicKeyBytes = hex2bin(strtolower($publicKey));
    if ($publicKeyBytes === FALSE) {
      throw new SymbolLoginException('Invalid public key.');
    }

    $publicKeyHash = hash('sha3-256', $publicKeyBytes, TRUE);
    $ripemd160 = hash('ripemd160', $publicKeyHash, TRUE);
    $versioned = chr($network_byte) . $ripemd160;
    $checksum = substr(hash('sha3-256', $versioned, TRUE), 0, 3);

    return $this->base32Encode($versioned . $checksum);
  }

  private function base32Encode(string $bytes): string {
    $bits = '';
    $encoded = '';
    $length = strlen($bytes);
    for ($i = 0; $i < $length; $i++) {
      $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    for ($i = 0; $i < strlen($bits); $i += 5) {
      $chunk = substr($bits, $i, 5);
      if (strlen($chunk) < 5) {
        $chunk = str_pad($chunk, 5, '0');
      }
      $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
    }
    return $encoded;
  }

  private function networkIdentifierFromProfile(string $network): ?int {
    try {
      $profile = \Drupal::service('symbol_engine.client')->networkProfile($network);
    }
    catch (SymbolEngineException | \InvalidArgumentException | \RuntimeException) {
      return NULL;
    }
    $identifier = $profile['networkIdentifier'] ?? NULL;
    if (!is_int($identifier) && !(is_string($identifier) && preg_match('/^[0-9]+$/', $identifier) === 1)) {
      return NULL;
    }
    $identifier = (int) $identifier;
    return $identifier >= 0 && $identifier <= 255 ? $identifier : NULL;
  }

}
