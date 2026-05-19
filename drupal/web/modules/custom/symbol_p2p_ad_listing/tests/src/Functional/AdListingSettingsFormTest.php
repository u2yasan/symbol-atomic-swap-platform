<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_p2p_ad_listing\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Symbol P2P Ad Listing settings visibility.
 */
#[Group('symbol_p2p_ad_listing')]
#[RunTestsInSeparateProcesses]
final class AdListingSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['symbol_p2p_ad_listing'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Settings route is admin-only and linked from the modules page.
   */
  public function testSettingsRouteAndModuleConfigureLink(): void {
    $assert_session = $this->assertSession();

    $this->drupalGet('/admin/config/services/symbol-p2p-ad-listing/settings');
    $assert_session->statusCodeEquals(403);

    $admin = $this->drupalCreateUser([
      'administer modules',
      'administer site configuration',
    ]);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/modules');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Symbol P2P Ad Listing');
    $assert_session->linkByHrefExists('/admin/config/services/symbol-p2p-ad-listing/settings');

    $this->drupalGet('/admin/config/services/symbol-p2p-ad-listing/settings');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Listing abuse guards');
    $assert_session->fieldValueEquals('Maximum active-like listings per seller', '5');
    $assert_session->pageTextContains('Duplicate reserving listing guard');
    $assert_session->pageTextContains('Aggregate reserved balance guard');
    $assert_session->pageTextContains('Balance checks');
    $assert_session->fieldValueEquals('Cron seller balance refresh batch size', '50');

    $this->submitForm([
      'max_reserving_listings_per_seller' => '7',
      'cron_balance_check_batch_size' => '25',
    ], 'Save configuration');
    $assert_session->pageTextContains('The configuration options have been saved.');
    $this->assertSame(7, \Drupal::config('symbol_p2p_ad_listing.settings')->get('max_reserving_listings_per_seller'));
    $this->assertSame(25, \Drupal::config('symbol_p2p_ad_listing.settings')->get('cron_balance_check_batch_size'));

    $this->drupalGet('/admin/config/services/symbol-p2p-ad-listing/settings');
    $assert_session->fieldValueEquals('Maximum active-like listings per seller', '7');
    $assert_session->fieldValueEquals('Cron seller balance refresh batch size', '25');
  }

}
