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
    $assert_session->fieldExists('Symbol address');
    $assert_session->buttonExists('Generate verification payload');
  }

}
