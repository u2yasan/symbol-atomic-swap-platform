<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Signing;

final class AliceSignUrl {

  public static function transaction(string $unsigned_payload, string $signer_public_key = ''): string {
    $payload = self::normalizeHex($unsigned_payload);
    $signer = self::normalizeHex($signer_public_key);
    $query = [
      'type' => 'request_sign_transaction',
      'data' => $payload,
    ];
    if ($signer !== '') {
      $query['set_public_key'] = $signer;
    }

    return 'alice://sign?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

  public static function cosignature(string $aggregate_payload, string $signer_public_key = ''): string {
    $payload = self::normalizeHex($aggregate_payload);
    $signer = self::normalizeHex($signer_public_key);
    $query = [
      'type' => 'request_sign_cosignature',
      'method' => 'get',
      'data' => $payload,
    ];
    if ($signer !== '') {
      $query['set_public_key'] = $signer;
    }

    return 'alice://sign?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

  private static function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
  }

}
