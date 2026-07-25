<?php

namespace Drupal\symbol_login\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\symbol_engine\Exception\SymbolEngineException;
use Drupal\symbol_engine\Service\SymbolEngineClient;
use Drupal\symbol_login\Service\ChallengeManager;
use Drupal\symbol_login\Service\LoginFloodControl;
use Drupal\symbol_login\Service\RoleSynchronizer;
use Drupal\symbol_login\Service\SignatureVerifier;
use Drupal\symbol_login\Service\SymbolLoginException;
use Drupal\symbol_login\Service\SymbolUserMapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles Symbol login JSON endpoints.
 */
final class SymbolLoginController extends ControllerBase {

  public function __construct(
    private readonly ChallengeManager $challengeManager,
    private readonly SignatureVerifier $signatureVerifier,
    private readonly SymbolUserMapper $symbolUserMapper,
    private readonly RoleSynchronizer $roleSynchronizer,
    private readonly SymbolEngineClient $symbolEngineClient,
    private readonly LoginFloodControl $floodControl,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('symbol_login.challenge_manager'),
      $container->get('symbol_login.signature_verifier'),
      $container->get('symbol_login.user_mapper'),
      $container->get('symbol_login.role_synchronizer'),
      $container->get('symbol_engine.client'),
      $container->get('symbol_login.flood_control'),
    );
  }

  public function challenge(Request $request): JsonResponse {
    if ($limited = $this->rateLimit($request, 'challenge', 5)) {
      return $limited;
    }
    return new JsonResponse($this->challengeManager->create());
  }

  public function verify(Request $request): JsonResponse {
    if ($limited = $this->rateLimit($request, 'verify', 10)) {
      return $limited;
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Invalid JSON request.', 400);
    }

    try {
      $address = (string) ($payload['address'] ?? '');
      $publicKey = (string) ($payload['publicKey'] ?? '');
      $signature = (string) ($payload['signature'] ?? '');
      $challengeId = (string) ($payload['challengeId'] ?? '');

      $message = $this->challengeManager->consume($challengeId);
      $this->signatureVerifier->verify($address, $publicKey, $signature, $message);

      $normalizedAddress = $this->signatureVerifier->normalizeAddress($address);
      $account = $this->symbolUserMapper->loadOrCreate($normalizedAddress, $publicKey);
      $this->roleSynchronizer->synchronize($account, $normalizedAddress);

      user_login_finalize($account);

      return new JsonResponse([
        'ok' => TRUE,
        'redirect' => Url::fromRoute('user.page')->toString(),
      ]);
    }
    catch (SymbolLoginException $e) {
      return $this->error($e->getMessage(), 403);
    }
  }

  public function sssChallenge(Request $request): JsonResponse {
    if ($limited = $this->rateLimit($request, 'sss_challenge', 5)) {
      return $limited;
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Invalid JSON request.', 400);
    }

    try {
      $address = $this->signatureVerifier->normalizeAddress((string) ($payload['address'] ?? ''));
      $publicKey = strtoupper((string) ($payload['publicKey'] ?? ''));
      $network = (string) ($this->config('symbol_login.settings')->get('network_type') ?: 'testnet');
      if ($this->signatureVerifier->addressFromPublicKey($publicKey) !== $address) {
        throw new SymbolLoginException('Address does not match public key.');
      }

      $challenge = $this->challengeManager->createForAccount($address, $publicKey);
      $built = $this->symbolEngineClient->buildAccountVerification($network, $address, $publicKey, (string) $challenge['message']);

      return new JsonResponse([
        'id' => $challenge['id'],
        'message' => $challenge['message'],
        'expires' => $challenge['expires'],
        'network' => $network,
        'address' => $address,
        'publicKey' => $publicKey,
        'unsignedPayload' => strtoupper((string) ($built['unsignedPayload'] ?? '')),
      ]);
    }
    catch (SymbolEngineException | SymbolLoginException | \InvalidArgumentException | \RuntimeException $e) {
      return $this->error($e->getMessage(), 403);
    }
  }

  public function sssVerify(Request $request): JsonResponse {
    if ($limited = $this->rateLimit($request, 'sss_verify', 10)) {
      return $limited;
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Invalid JSON request.', 400);
    }

    try {
      $challenge = $this->challengeManager->consumeRecord((string) ($payload['challengeId'] ?? ''));
      $network = (string) ($challenge['network'] ?? '');
      $address = (string) ($challenge['address'] ?? '');
      $publicKey = (string) ($challenge['publicKey'] ?? '');
      $signedPayload = strtoupper((string) ($payload['payload'] ?? ''));

      $result = $this->symbolEngineClient->verifyAccountVerification(
        $network,
        $address,
        $publicKey,
        (string) $challenge['message'],
        $signedPayload,
      );
      if (empty($result['accepted'])) {
        throw new SymbolLoginException('SSS signed payload was rejected: ' . (string) ($result['reason'] ?? 'rejected'));
      }

      $account = $this->symbolUserMapper->loadOrCreate($address, $publicKey, 'symbol_login_sss_payload');
      $this->roleSynchronizer->synchronize($account, $address);
      user_login_finalize($account);

      return new JsonResponse([
        'ok' => TRUE,
        'redirect' => Url::fromRoute('user.page')->toString(),
      ]);
    }
    catch (SymbolEngineException | SymbolLoginException | \InvalidArgumentException | \RuntimeException $e) {
      return $this->error($e->getMessage(), 403);
    }
  }

  private function error(string $message, int $status): JsonResponse {
    return new JsonResponse([
      'ok' => FALSE,
      'error' => $message,
    ], $status);
  }

  private function rateLimit(Request $request, string $operation, int $threshold): ?JsonResponse {
    if ($this->floodControl->consume($request, $operation, $threshold)) {
      return NULL;
    }

    return new JsonResponse([
      'ok' => FALSE,
      'error' => 'too_many_requests',
    ], 429, [
      'Retry-After' => (string) $this->floodControl->retryAfterSeconds(),
    ]);
  }

}
