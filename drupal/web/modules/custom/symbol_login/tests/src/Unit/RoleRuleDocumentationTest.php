<?php

namespace Drupal\Tests\symbol_login\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Documents the required role rule shape used by configuration.
 *
 * @group symbol_login
 */
final class RoleRuleDocumentationTest extends UnitTestCase {

  public function testRoleRuleShape(): void {
    $rule = [
      'role' => 'premium_member',
      'mosaic_id' => '72C0212E67A08BCE',
      'minimum_amount' => '1',
      'metadata_source_address' => 'TALICE2GMA34SAMPLEADDRESS000000000',
      'metadata_key' => '0000000000000001',
      'metadata_value' => 'active',
      'enabled' => TRUE,
    ];

    $this->assertSame('premium_member', $rule['role']);
    $this->assertTrue($rule['enabled']);
    $this->assertNotEmpty($rule['mosaic_id']);
    $this->assertNotEmpty($rule['metadata_key']);
  }

}

