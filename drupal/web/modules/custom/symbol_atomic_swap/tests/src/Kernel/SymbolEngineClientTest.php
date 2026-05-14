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

    return new SymbolEngineClient(new Client(['handler' => $stack]));
  }

}
