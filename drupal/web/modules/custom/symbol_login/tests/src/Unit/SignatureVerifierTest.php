<?php

namespace Drupal\Tests\symbol_login\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\symbol_login\Service\SignatureVerifier;
use Drupal\symbol_login\Service\SymbolLoginException;
use Drupal\Tests\UnitTestCase;

/**
 * Tests Symbol signature verifier input boundaries.
 *
 * @group symbol_login
 */
final class SignatureVerifierTest extends UnitTestCase {

  public function testNormalizeAddressRemovesSeparators(): void {
    $verifier = $this->createVerifier('testnet');

    $this->assertSame(
      'TALICE2GMA34SAMPLEADDRESS000000000',
      $verifier->normalizeAddress('talice-2gma 34sampleaddress000000000'),
    );
  }

  public function testRejectsUnsupportedNetworkType(): void {
    $verifier = $this->createVerifier('private');

    $this->expectException(SymbolLoginException::class);
    $this->expectExceptionMessage('Unsupported Symbol network type.');

    $verifier->addressFromPublicKey(str_repeat('a', 64));
  }

  public function testRejectsInvalidPublicKeyFormatBeforeVerification(): void {
    $verifier = $this->createVerifier('testnet');

    $this->expectException(SymbolLoginException::class);
    $this->expectExceptionMessage('Invalid public key format.');

    $verifier->verify('TALICE2GMA34SAMPLEADDRESS000000000', 'not-hex', str_repeat('b', 128), 'message');
  }

  private function createVerifier(string $networkType): SignatureVerifier {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('network_type')->willReturn($networkType);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('symbol_login.settings')->willReturn($config);

    return new SignatureVerifier($configFactory);
  }

}

