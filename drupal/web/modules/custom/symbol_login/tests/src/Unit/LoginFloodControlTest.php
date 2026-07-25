<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_login\Unit;

use Drupal\Core\Flood\FloodInterface;
use Drupal\symbol_login\Service\LoginFloodControl;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

final class LoginFloodControlTest extends UnitTestCase {

  public function testRegistersAllowedRequestWithHashedIpIdentifier(): void {
    $expected_identifier = hash('sha256', '203.0.113.10');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('symbol_login.sss_challenge', 5, 60, $expected_identifier)
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('symbol_login.sss_challenge', 60, $expected_identifier);

    $request = Request::create('/symbol/login/sss/challenge', 'POST', server: [
      'REMOTE_ADDR' => '203.0.113.10',
    ]);
    $control = new LoginFloodControl($flood);

    $this->assertTrue($control->consume($request, 'sss_challenge', 5));
  }

  public function testBlockedRequestIsNotRegistered(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $control = new LoginFloodControl($flood);
    $this->assertFalse($control->consume(Request::create('/symbol/login/challenge', 'POST'), 'challenge', 5));
    $this->assertSame(60, $control->retryAfterSeconds());
  }

}
