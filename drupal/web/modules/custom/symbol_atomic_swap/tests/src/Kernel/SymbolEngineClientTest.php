<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_atomic_swap\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Symbol Engine HTTP client behavior.
 */
#[Group('symbol_atomic_swap')]
final class SymbolEngineClientTest extends KernelTestBase {

  private const VALID_TOKEN = 'f3b9c1a84e7d42fa9c05b8d63e2a71cb';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['symbol_atomic_swap'];

  /**
   * Environment values to restore after each test.
   *
   * @var array<string, string|false>
   */
  private array $previousEnv = [];

  protected function setUp(): void {
    parent::setUp();
    foreach (['SYMBOL_ENGINE_BASE_URL', 'SYMBOL_ENGINE_API_TOKEN', 'SYMBOL_ENGINE_TIMEOUT'] as $name) {
      $this->previousEnv[$name] = getenv($name);
    }
  }

  protected function tearDown(): void {
    foreach ($this->previousEnv as $name => $value) {
      if ($value === FALSE) {
        putenv($name);
      }
      else {
        putenv($name . '=' . $value);
      }
    }
    parent::tearDown();
  }

  /**
   * Protected Engine API requests must send the configured bearer token.
   */
  public function testProtectedRequestUsesBearerToken(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=' . self::VALID_TOKEN);
    putenv('SYMBOL_ENGINE_TIMEOUT=3');

    $history = [];
    $client = $this->client([
      new Response(200, [], '{"network":"testnet"}'),
    ], $history);

    $result = $client->network();

    $this->assertSame('testnet', $result['network']);
    $this->assertCount(1, $history);
    $request = $history[0]['request'];
    $this->assertSame('Bearer ' . self::VALID_TOKEN, $request->getHeaderLine('Authorization'));
    $this->assertSame('http://engine.local/v1/network', (string) $request->getUri());
  }

  /**
   * Protected Engine API requests must reject weak local token values before IO.
   */
  public function testProtectedRequestRejectsShortTokenBeforeRequest(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=test-token');

    $history = [];
    $client = $this->client([], $history);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SYMBOL_ENGINE_API_TOKEN must be at least 32 characters.');

    try {
      $client->network();
    }
    finally {
      $this->assertSame([], $history);
    }
  }

  /**
   * Protected Engine API requests must reject placeholder token values before IO.
   */
  public function testProtectedRequestRejectsPlaceholderTokenBeforeRequest(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=0123456789abcdef0123456789abcdef');

    $history = [];
    $client = $this->client([], $history);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SYMBOL_ENGINE_API_TOKEN must not use a placeholder value.');

    try {
      $client->network();
    }
    finally {
      $this->assertSame([], $history);
    }
  }

  /**
   * Protected Engine API requests must reject repeated token patterns before IO.
   */
  public function testProtectedRequestRejectsRepeatedTokenBeforeRequest(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    $history = [];
    $client = $this->client([], $history);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SYMBOL_ENGINE_API_TOKEN must look randomly generated.');

    try {
      $client->network();
    }
    finally {
      $this->assertSame([], $history);
    }
  }

  /**
   * Health checks must remain unauthenticated.
   */
  public function testHealthRequestDoesNotRequireToken(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN');

    $history = [];
    $client = $this->client([
      new Response(200, [], '{"status":"ok"}'),
    ], $history);

    $result = $client->health();

    $this->assertSame('ok', $result['status']);
    $this->assertCount(1, $history);
    $this->assertSame('', $history[0]['request']->getHeaderLine('Authorization'));
  }

  /**
   * Mosaic metadata requests are routed through Symbol Engine.
   */
  public function testMosaicMetadataRequest(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=' . self::VALID_TOKEN);

    $history = [];
    $client = $this->client([
      new Response(200, [], '{"found":true,"mosaicId":"72C0212E67A08BCE","divisibility":6,"aliases":["symbol.xym"]}'),
    ], $history);

    $result = $client->mosaicMetadata('testnet', '72c0212e67a08bce');

    $this->assertSame('symbol.xym', $result['aliases'][0]);
    $this->assertSame(6, $result['divisibility']);
    $this->assertSame('http://engine.local/v1/mosaics/testnet/72C0212E67A08BCE', (string) $history[0]['request']->getUri());
  }

  /**
   * Aggregate bonded builds must call the bonded build endpoint.
   */
  public function testBuildAggregateBondedRequest(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=' . self::VALID_TOKEN);

    $history = [];
    $client = $this->client([
      new Response(201, [], '{"intentHash":"' . str_repeat('A', 64) . '","unsignedPayload":"BEEF","qrPayload":{"type":"symbol-aggregate-bonded"}}'),
    ], $history);

    $result = $client->buildAggregateBonded([
      'network' => 'testnet',
      'deadlineHours' => 48,
      'correlationId' => 'swap-testnet-000001',
      'legs' => [],
      'hashLock' => [
        'mosaicId' => '72C0212E67A08BCE',
        'amount' => '10000000',
        'duration' => 5760,
      ],
    ]);

    $this->assertSame('symbol-aggregate-bonded', $result['qrPayload']['type']);
    $request = $history[0]['request'];
    $this->assertSame('http://engine.local/v1/aggregate-bonded/build', (string) $request->getUri());
    $body = json_decode((string) $request->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(48, $body['deadlineHours']);
    $this->assertSame('72C0212E67A08BCE', $body['hashLock']['mosaicId']);
    $this->assertSame('10000000', $body['hashLock']['amount']);
    $this->assertSame(5760, $body['hashLock']['duration']);
  }

  /**
   * Account mosaic balance requests are routed through Symbol Engine.
   */
  public function testAccountMosaicBalanceRequest(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=' . self::VALID_TOKEN);

    $history = [];
    $client = $this->client([
      new Response(200, [], '{"found":true,"address":"TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ","mosaicId":"72C0212E67A08BCE","amount":"719328436"}'),
    ], $history);

    $result = $client->accountMosaicBalance('testnet', 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', '72c0212e67a08bce');

    $this->assertSame('719328436', $result['amount']);
    $this->assertSame('http://engine.local/v1/accounts/testnet/TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ/mosaics/72C0212E67A08BCE', (string) $history[0]['request']->getUri());
  }

  /**
   * Engine state errors should be normalized without losing engine details.
   */
  public function testRequestExceptionIsNormalized(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=' . self::VALID_TOKEN);

    $client = $this->client([
      new Response(409, [], '{"error":"invalid_state","message":"announce requires signed intent"}'),
    ]);

    $this->expectException(SymbolEngineException::class);
    $this->expectExceptionMessage('Symbol Engine rejected the current state transition. announce requires signed intent');

    try {
      $client->announce(str_repeat('A', 64));
    }
    catch (SymbolEngineException $exception) {
      $this->assertSame(409, $exception->statusCode);
      $this->assertSame('invalid_state', $exception->engineError);
      $this->assertSame('announce requires signed intent', $exception->details['message']);
      throw $exception;
    }
  }

  /**
   * Engine rejection reasons must not be collapsed into a generic client error.
   */
  public function testRequestExceptionUsesReasonWhenErrorIsAbsent(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=' . self::VALID_TOKEN);

    $client = $this->client([
      new Response(400, [], '{"accepted":false,"reason":"unexpected_cosignature_signer"}'),
    ]);

    $this->expectException(SymbolEngineException::class);
    $this->expectExceptionMessage('Symbol Engine validation failed. unexpected_cosignature_signer');

    try {
      $client->verifyCosignature(str_repeat('A', 64), [
        'parentHash' => str_repeat('B', 64),
        'signerPublicKey' => str_repeat('C', 64),
        'signature' => str_repeat('D', 128),
      ]);
    }
    catch (SymbolEngineException $exception) {
      $this->assertSame(400, $exception->statusCode);
      $this->assertSame('unexpected_cosignature_signer', $exception->engineError);
      $this->assertSame('unexpected_cosignature_signer', $exception->details['reason']);
      throw $exception;
    }
  }

  /**
   * Client-side hash validation should reject malformed identifiers early.
   */
  public function testInvalidHashIsRejectedBeforeRequest(): void {
    putenv('SYMBOL_ENGINE_BASE_URL=http://engine.local');
    putenv('SYMBOL_ENGINE_API_TOKEN=' . self::VALID_TOKEN);

    $history = [];
    $client = $this->client([], $history);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid intent hash.');

    try {
      $client->intent('not-a-hash');
    }
    finally {
      $this->assertSame([], $history);
    }
  }

  /**
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   * @param array<int, array<string, mixed>> $history
   */
  private function client(array $responses, array &$history = []): SymbolEngineClient {
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new SymbolEngineClient(new Client(['handler' => $stack]), $this->container->get('config.factory'));
  }

}
