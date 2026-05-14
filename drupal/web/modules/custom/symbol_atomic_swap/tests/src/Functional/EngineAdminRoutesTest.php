<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_atomic_swap\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Symbol Engine admin routes.
 */
#[Group('symbol_atomic_swap')]
#[RunTestsInSeparateProcesses]
final class EngineAdminRoutesTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['symbol_atomic_swap'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin Engine pages must be closed to anonymous users and available to admins.
   */
  public function testAdminRoutesRequireSiteConfigurationPermission(): void {
    $assert_session = $this->assertSession();

    $this->drupalGet('/admin/config/services/symbol-atomic-swap/engine');
    $assert_session->statusCodeEquals(403);

    $this->drupalGet('/admin/config/services/symbol-atomic-swap/engine/operations');
    $assert_session->statusCodeEquals(403);

    $this->drupalGet('/admin/config/services/symbol-atomic-swap/settings');
    $assert_session->statusCodeEquals(403);

    $account = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($account);

    $this->drupalGet('/admin/config/services/symbol-atomic-swap/engine');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Symbol Engine read API lookup and health dashboard');
    $assert_session->buttonExists('Read health');
    $assert_session->buttonExists('Read network');
    $assert_session->fieldExists('Intent hash');
    $assert_session->fieldExists('Transaction hash');

    $this->drupalGet('/admin/config/services/symbol-atomic-swap/engine/operations');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Manual Symbol Engine operations');
    $assert_session->buttonExists('Build unsigned transaction');
    $assert_session->buttonExists('Verify signed payload');
    $assert_session->buttonExists('Announce transaction');

    $this->drupalGet('/admin/config/services/symbol-atomic-swap/settings');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Engine base URL');
    $assert_session->fieldExists('Engine timeout seconds');
    $assert_session->pageTextContains('API token state');
    $assert_session->fieldExists('Notification email recipient');
    $assert_session->fieldExists('Webhook URL');
    $assert_session->pageTextContains('Webhook token state');
  }

}
