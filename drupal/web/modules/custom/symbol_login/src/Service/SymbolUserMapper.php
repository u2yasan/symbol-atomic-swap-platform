<?php

namespace Drupal\symbol_login\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Maps verified Symbol addresses to Drupal users.
 */
final class SymbolUserMapper {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function loadOrCreate(string $address, string $publicKey, string $verificationMethod = 'symbol_login_signature'): UserInterface {
    $address = strtoupper(str_replace(['-', ' '], '', trim($address)));
    $publicKey = strtoupper(trim($publicKey));
    $network = (string) ($this->configFactory->get('symbol_login.settings')->get('network_type') ?: 'testnet');
    $timestamp = $this->time->getRequestTime();
    $datetime = gmdate('Y-m-d\TH:i:s', $timestamp);

    $storage = $this->entityTypeManager->getStorage('user');
    $matches = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_symbol_address', $address)
      ->range(0, 2)
      ->execute();

    if (count($matches) > 1) {
      throw new SymbolLoginException('Symbol address is linked to multiple users.');
    }

    if ($matches) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $storage->load(reset($matches));
      $this->applySymbolIdentity($account, $network, $address, $publicKey, $timestamp, $datetime, $verificationMethod);
      $account->activate();
      $account->save();
      return $account;
    }

    /** @var \Drupal\user\UserInterface $account */
    $account = $storage->create([
      'name' => 'symbol_' . strtolower($address),
      'mail' => 'symbol_' . strtolower($address) . '@local.invalid',
      'status' => 1,
      'field_symbol_address' => $address,
      'field_symbol_public_key' => $publicKey,
    ]);
    $this->applySymbolIdentity($account, $network, $address, $publicKey, $timestamp, $datetime, $verificationMethod);
    $account->save();

    return $account;
  }

  private function applySymbolIdentity(UserInterface $account, string $network, string $address, string $publicKey, int $timestamp, string $datetime, string $verificationMethod): void {
    $values = [
      'field_symbol_network' => $network,
      'field_symbol_address' => $address,
      'field_symbol_public_key' => $publicKey,
      'field_symbol_address_verified' => TRUE,
      'field_symbol_address_verified_at' => $timestamp,
      'field_symbol_verification_method' => $verificationMethod,
      'field_symbol_challenge_hash' => NULL,
      'field_symbol_last_verified' => $datetime,
    ];

    foreach ($values as $field => $value) {
      if ($account->hasField($field)) {
        $account->set($field, $value);
      }
    }
  }

}
