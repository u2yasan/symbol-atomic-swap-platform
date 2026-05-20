<?php

namespace Drupal\symbol_login\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\UserInterface;

/**
 * Synchronizes configured Drupal roles from Symbol account state.
 */
final class RoleSynchronizer {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SymbolRestClient $symbolRestClient,
  ) {}

  public function synchronize(UserInterface $account, string $symbolAddress): void {
    $rules = array_filter((array) $this->configFactory->get('symbol_login.settings')->get('role_rules'));
    if (!$rules) {
      return;
    }

    $managedRoles = [];
    foreach ($rules as $rule) {
      if (!empty($rule['enabled']) && !empty($rule['role'])) {
        $managedRoles[(string) $rule['role']] = (string) $rule['role'];
      }
    }

    try {
      $symbolAccount = $this->symbolRestClient->getAccount($symbolAddress);
      $metadata = $this->symbolRestClient->getMetadata($symbolAddress);
      $mosaics = $this->extractMosaicBalances($symbolAccount);
    }
    catch (SymbolLoginException) {
      foreach ($managedRoles as $role) {
        $account->removeRole($role);
      }
      if ($managedRoles) {
        $account->save();
      }
      return;
    }

    foreach ($rules as $rule) {
      if (empty($rule['enabled']) || empty($rule['role'])) {
        continue;
      }

      $role = (string) $rule['role'];
      if ($this->ruleMatches($rule, $mosaics, $metadata)) {
        $account->addRole($role);
      }
      else {
        $account->removeRole($role);
      }
    }

    if ($managedRoles) {
      $account->save();
    }
  }

  /**
   * @param array<string,mixed> $symbolAccount
   *
   * @return array<string,string>
   */
  private function extractMosaicBalances(array $symbolAccount): array {
    $balances = [];
    $mosaics = $symbolAccount['account']['mosaics'] ?? [];
    if (!is_array($mosaics)) {
      return $balances;
    }

    foreach ($mosaics as $mosaic) {
      $id = strtoupper((string) ($mosaic['id'] ?? ''));
      $amount = (string) ($mosaic['amount'] ?? '0');
      if ($id !== '') {
        $balances[$id] = $amount;
      }
    }
    return $balances;
  }

  /**
   * @param array<string,mixed> $rule
   * @param array<string,string> $mosaics
   * @param array<int,array<string,mixed>> $metadata
   */
  private function ruleMatches(array $rule, array $mosaics, array $metadata): bool {
    $hasMosaicCondition = !empty($rule['mosaic_id']);
    $hasMetadataCondition = !empty($rule['metadata_key']);

    if ($hasMosaicCondition && !$this->mosaicMatches($rule, $mosaics)) {
      return FALSE;
    }
    if ($hasMetadataCondition && !$this->metadataMatches($rule, $metadata)) {
      return FALSE;
    }

    return $hasMosaicCondition || $hasMetadataCondition;
  }

  /**
   * @param array<string,mixed> $rule
   * @param array<string,string> $mosaics
   */
  private function mosaicMatches(array $rule, array $mosaics): bool {
    $mosaicId = strtoupper((string) $rule['mosaic_id']);
    $minimum = (string) ($rule['minimum_amount'] ?? '1');
    $actual = $mosaics[$mosaicId] ?? '0';

    if (function_exists('bccomp')) {
      return bccomp($actual, $minimum, 0) >= 0;
    }

    return (int) $actual >= (int) $minimum;
  }

  /**
   * @param array<string,mixed> $rule
   * @param array<int,array<string,mixed>> $metadata
   */
  private function metadataMatches(array $rule, array $metadata): bool {
    $source = strtoupper(str_replace(['-', ' '], '', (string) ($rule['metadata_source_address'] ?? '')));
    $key = (string) ($rule['metadata_key'] ?? '');
    $expected = (string) ($rule['metadata_value'] ?? '');

    foreach ($metadata as $entry) {
      $metadataEntry = $entry['metadataEntry'] ?? [];
      if (!is_array($metadataEntry)) {
        continue;
      }
      $scopedKey = (string) ($metadataEntry['scopedMetadataKey'] ?? '');
      $sourceAddress = strtoupper(str_replace(['-', ' '], '', (string) ($metadataEntry['sourceAddress'] ?? '')));
      $value = (string) ($metadataEntry['value'] ?? '');

      if ($key !== '' && !hash_equals(strtolower($key), strtolower($scopedKey))) {
        continue;
      }
      if ($source !== '' && !hash_equals($source, $sourceAddress)) {
        continue;
      }
      if ($expected !== '' && !hash_equals($expected, $value)) {
        continue;
      }
      return TRUE;
    }

    return FALSE;
  }

}
