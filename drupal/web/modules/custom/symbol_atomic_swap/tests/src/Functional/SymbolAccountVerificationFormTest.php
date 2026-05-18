<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_atomic_swap\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Symbol account verification UI wiring.
 */
#[Group('symbol_atomic_swap')]
#[RunTestsInSeparateProcesses]
final class SymbolAccountVerificationFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['symbol_atomic_swap'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testAccountVerificationRouteRequiresLoginAndRendersFields(): void {
    $assert_session = $this->assertSession();

    $this->drupalGet('/symbol-atomic-swap/account');
    $assert_session->statusCodeEquals(403);

    $account = $this->drupalCreateUser();
    $this->assertTrue($account->hasField('field_symbol_network'));
    $this->assertTrue($account->hasField('field_symbol_address'));
    $this->assertTrue($account->hasField('field_symbol_public_key'));
    $this->assertTrue($account->hasField('field_symbol_address_verified'));
    $this->assertTrue($account->hasField('field_symbol_address_verified_at'));
    $this->assertTrue($account->hasField('field_symbol_verification_method'));
    $this->assertTrue($account->hasField('field_symbol_challenge_hash'));

    $this->drupalLogin($account);
    $this->drupalGet('/symbol-atomic-swap/account');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Verification status');
    $assert_session->pageTextContains('Not verified.');
    $assert_session->fieldExists('Symbol network');
    $assert_session->optionExists('Symbol network', 'testnet');
    $assert_session->optionNotExists('Symbol network', 'mainnet');
    $assert_session->fieldExists('Symbol address');
    $assert_session->buttonExists('Generate verification payload');
  }

  public function testMainnetAccountVerificationRequiresExplicitEnablement(): void {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $this->drupalGet('/symbol-atomic-swap/account');
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->optionNotExists('Symbol network', 'mainnet');

    $this->config('symbol_atomic_swap.settings')
      ->set('mainnet_enabled', TRUE)
      ->save();
    $this->drupalGet('/symbol-atomic-swap/account');
    $assert_session->optionExists('Symbol network', 'mainnet');
  }

  public function testVerifiedAccountIsDisplayedReadOnlyUntilRemoved(): void {
    $account = $this->drupalCreateUser();
    $account->set('field_symbol_network', 'testnet');
    $account->set('field_symbol_address', 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ');
    $account->set('field_symbol_public_key', '97E42C98FF3E5D0DD4BEB7234628DFE658402EDAD7A2CF5190451F7EFFA5B79D');
    $account->set('field_symbol_address_verified', TRUE);
    $account->set('field_symbol_address_verified_at', 1700000000);
    $account->set('field_symbol_verification_method', 'sss_zero_fee_transfer');
    $account->set('field_symbol_challenge_hash', str_repeat('A', 64));
    $account->save();

    $this->drupalLogin($account);
    $this->drupalGet('/symbol-atomic-swap/account');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Verified at');
    $assert_session->pageTextContains('testnet');
    $assert_session->pageTextContains('TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ');
    $assert_session->fieldNotExists('Symbol address');
    $assert_session->buttonNotExists('Generate verification payload');
    $assert_session->buttonExists('Remove registered Symbol account');

    $this->submitForm([], 'Remove registered Symbol account');
    $assert_session->pageTextContains('Registered Symbol account was removed.');
    $assert_session->fieldExists('Symbol address');
    $assert_session->buttonExists('Generate verification payload');
  }

  public function testAddressAlreadyVerifiedByAnotherUserCannotBeRegistered(): void {
    $address = 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ';
    $existing = $this->drupalCreateUser();
    $existing->set('field_symbol_network', 'testnet');
    $existing->set('field_symbol_address', $address);
    $existing->set('field_symbol_public_key', '97E42C98FF3E5D0DD4BEB7234628DFE658402EDAD7A2CF5190451F7EFFA5B79D');
    $existing->set('field_symbol_address_verified', TRUE);
    $existing->set('field_symbol_address_verified_at', 1700000000);
    $existing->set('field_symbol_verification_method', 'sss_zero_fee_transfer');
    $existing->set('field_symbol_challenge_hash', str_repeat('A', 64));
    $existing->save();

    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
    $this->drupalGet('/symbol-atomic-swap/account');
    $this->submitForm([
      'network' => 'testnet',
      'address' => $address,
    ], 'Generate verification payload');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('This Symbol address is already registered by another user.');
    $assert_session->pageTextContains('Not verified.');
  }

}
