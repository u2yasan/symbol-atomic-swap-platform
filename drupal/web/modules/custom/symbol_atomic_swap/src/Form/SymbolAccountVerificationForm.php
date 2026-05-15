<?php

declare(strict_types=1);

namespace Drupal\symbol_atomic_swap\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\symbol_atomic_swap\Exception\SymbolEngineException;
use Drupal\symbol_atomic_swap\Service\SymbolAccountPublicKeyResolverInterface;
use Drupal\symbol_atomic_swap\Service\SymbolEngineClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class SymbolAccountVerificationForm extends FormBase {

  private const TEMPSTORE_COLLECTION = 'symbol_atomic_swap_account_verification';
  private const TEMPSTORE_KEY = 'challenge';
  private const CHALLENGE_TTL = 600;
  private const VERIFICATION_METHOD = 'sss_zero_fee_transfer';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SymbolAccountPublicKeyResolverInterface $publicKeyResolver,
    private readonly SymbolEngineClient $engineClient,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStackService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('symbol_atomic_swap.account_public_key_resolver'),
      $container->get('symbol_atomic_swap.engine_client'),
      $container->get('tempstore.private'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  public function getFormId(): string {
    return 'symbol_atomic_swap_account_verification_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->loadUser();
    $challenge = $this->challenge();

    $form['#attached']['library'][] = 'symbol_atomic_swap/sss_sign';
    if ($challenge) {
      $form['#attributes']['data-symbol-sss-container'] = '1';
      $form['#attributes']['data-symbol-sss-unsigned-payload'] = (string) $challenge['unsignedPayload'];
      $form['#attributes']['data-symbol-sss-required-signer'] = (string) $challenge['publicKey'];
    }

    $verified = (bool) ($account->get('field_symbol_address_verified')->value ?? FALSE);
    $verified_at = (int) ($account->get('field_symbol_address_verified_at')->value ?? 0);
    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Verification status'),
      '#markup' => $verified
        ? $this->t('Verified at @time.', ['@time' => $this->dateFormatter->format($verified_at, 'short')])
        : $this->t('Not verified.'),
    ];

    $form['network'] = [
      '#type' => 'select',
      '#title' => $this->t('Symbol network'),
      '#options' => [
        'testnet' => $this->t('Testnet'),
        'mainnet' => $this->t('Mainnet'),
      ],
      '#default_value' => $account->get('field_symbol_network')->value ?: 'testnet',
      '#required' => TRUE,
    ];
    $form['address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Symbol address'),
      '#maxlength' => 46,
      '#size' => 52,
      '#default_value' => $account->get('field_symbol_address')->value ?? '',
      '#required' => TRUE,
      '#description' => $this->t('The account must have sent at least one signed transaction so its public key is available on Symbol.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];

    if ($challenge) {
      $expires = (int) $challenge['expires'];
      $form['challenge'] = [
        '#type' => 'details',
        '#title' => $this->t('Current verification challenge'),
        '#open' => TRUE,
        'expires' => [
          '#type' => 'item',
          '#title' => $this->t('Expires'),
          '#markup' => $this->dateFormatter->format($expires, 'short'),
        ],
        'unsigned_payload' => [
          '#type' => 'textarea',
          '#title' => $this->t('Unsigned verification payload'),
          '#value' => (string) $challenge['unsignedPayload'],
          '#rows' => 8,
          '#attributes' => [
            'readonly' => 'readonly',
            'spellcheck' => 'false',
          ],
        ],
        'sss' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['symbol-atomic-swap-sss-sign']],
          'install' => [
            '#type' => 'link',
            '#title' => $this->t('Install SSS Extension'),
            '#url' => Url::fromUri('https://chromewebstore.google.com/detail/sss-extension/llildiojemakefgnhhkmiiffonembcan?hl=ja'),
            '#attributes' => ['class' => ['button']],
          ],
          'sign' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => (string) $this->t('Sign verification payload with SSS'),
            '#attributes' => [
              'type' => 'button',
              'class' => ['button', 'button--primary'],
              'data-symbol-sss-sign' => '1',
            ],
          ],
          'status' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => '',
            '#attributes' => [
              'data-symbol-sss-status' => '1',
              'aria-live' => 'polite',
            ],
          ],
        ],
        'signed_payload' => [
          '#type' => 'textarea',
          '#title' => $this->t('Signed verification payload'),
          '#rows' => 8,
          '#attributes' => [
            'spellcheck' => 'false',
            'data-symbol-sss-signed-payload' => '1',
          ],
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['generate'] = [
      '#type' => 'submit',
      '#value' => $challenge ? $this->t('Regenerate verification payload') : $this->t('Generate verification payload'),
      '#button_type' => $challenge ? 'secondary' : 'primary',
      '#validate' => ['::validateGenerate'],
      '#submit' => ['::submitGenerate'],
    ];
    if ($challenge) {
      $form['actions']['verify'] = [
        '#type' => 'submit',
        '#value' => $this->t('Verify signed payload'),
        '#button_type' => 'primary',
        '#validate' => ['::validateVerify'],
        '#submit' => ['::submitVerify'],
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Submit handling is routed to explicit generate/verify submit handlers.
  }

  public function validateGenerate(array &$form, FormStateInterface $form_state): void {
    $network = (string) $form_state->getValue('network');
    $address = strtoupper(trim((string) $form_state->getValue('address')));
    if (!$this->isNetworkAddress($address, $network)) {
      $form_state->setErrorByName('address', $this->t('Symbol address must be a valid raw address for the selected network.'));
      return;
    }

    try {
      $form_state->set('symbol_public_key', $this->publicKeyResolver->resolve($network, $address));
    }
    catch (SymbolEngineException) {
      $form_state->setErrorByName('address', $this->t('No public key was found for this address on the selected network. Use an account that has sent at least one signed transaction.'));
    }
    catch (\InvalidArgumentException) {
      $form_state->setErrorByName('address', $this->t('Symbol address public key could not be verified.'));
    }
  }

  public function submitGenerate(array &$form, FormStateInterface $form_state): void {
    $network = (string) $form_state->getValue('network');
    $address = strtoupper(trim((string) $form_state->getValue('address')));
    $public_key = strtoupper((string) $form_state->get('symbol_public_key'));
    $issued = \Drupal::time()->getRequestTime();
    $expires = $issued + self::CHALLENGE_TTL;
    $challenge = $this->challengeMessage($network, $address, $issued, $expires);

    try {
      $built = $this->engineClient->buildAccountVerification($network, $address, $public_key, $challenge);
    }
    catch (SymbolEngineException | \RuntimeException | \InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('Verification payload build failed: @message', ['@message' => $exception->getMessage()]));
      return;
    }

    $this->saveUserFields([
      'field_symbol_network' => $network,
      'field_symbol_address' => $address,
      'field_symbol_public_key' => $public_key,
      'field_symbol_address_verified' => FALSE,
      'field_symbol_address_verified_at' => NULL,
      'field_symbol_verification_method' => '',
      'field_symbol_challenge_hash' => hash('sha256', $challenge),
    ]);
    $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION)->set(self::TEMPSTORE_KEY, [
      'network' => $network,
      'address' => $address,
      'publicKey' => $public_key,
      'challenge' => $challenge,
      'challengeHash' => hash('sha256', $challenge),
      'unsignedPayload' => strtoupper((string) $built['unsignedPayload']),
      'issued' => $issued,
      'expires' => $expires,
    ]);
    $this->messenger()->addStatus($this->t('Verification payload was generated. Sign it with SSS, then submit the signed payload.'));
    $form_state->setRebuild();
  }

  public function validateVerify(array &$form, FormStateInterface $form_state): void {
    $challenge = $this->challenge();
    if (!$challenge) {
      $form_state->setErrorByName('signed_payload', $this->t('Generate a verification payload first.'));
      return;
    }
    if ((int) $challenge['expires'] < \Drupal::time()->getRequestTime()) {
      $form_state->setErrorByName('signed_payload', $this->t('Verification challenge expired. Generate a new payload.'));
      return;
    }
    $payload = strtoupper(trim((string) $form_state->getValue(['challenge', 'signed_payload'])));
    if (!preg_match('/^[0-9A-F]+$/', $payload) || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('challenge][signed_payload', $this->t('Signed payload must be even-length hex.'));
    }
  }

  public function submitVerify(array &$form, FormStateInterface $form_state): void {
    $challenge = $this->challenge();
    if (!$challenge) {
      return;
    }
    $payload = strtoupper(trim((string) $form_state->getValue(['challenge', 'signed_payload'])));

    try {
      $result = $this->engineClient->verifyAccountVerification(
        (string) $challenge['network'],
        (string) $challenge['address'],
        (string) $challenge['publicKey'],
        (string) $challenge['challenge'],
        $payload,
      );
    }
    catch (SymbolEngineException | \RuntimeException | \InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('Verification failed: @message', ['@message' => $exception->getMessage()]));
      return;
    }

    if (empty($result['accepted'])) {
      $this->messenger()->addError($this->t('Verification failed: @message', ['@message' => (string) ($result['reason'] ?? 'rejected')]));
      return;
    }

    $this->saveUserFields([
      'field_symbol_network' => (string) $challenge['network'],
      'field_symbol_address' => (string) $challenge['address'],
      'field_symbol_public_key' => strtoupper((string) ($result['signerPublicKey'] ?? $challenge['publicKey'])),
      'field_symbol_address_verified' => TRUE,
      'field_symbol_address_verified_at' => \Drupal::time()->getRequestTime(),
      'field_symbol_verification_method' => self::VERIFICATION_METHOD,
      'field_symbol_challenge_hash' => (string) $challenge['challengeHash'],
    ]);
    $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION)->delete(self::TEMPSTORE_KEY);
    $this->messenger()->addStatus($this->t('Symbol address ownership was verified.'));
    $form_state->setRebuild();
  }

  /**
   * @return array<string, mixed>|null
   */
  private function challenge(): ?array {
    $challenge = $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION)->get(self::TEMPSTORE_KEY);
    return is_array($challenge) ? $challenge : NULL;
  }

  private function challengeMessage(string $network, string $address, int $issued, int $expires): string {
    $host = $this->requestStackService->getCurrentRequest()?->getHost() ?: 'localhost';
    return implode("\n", [
      'symbol-atomic-swap address verification',
      'domain: ' . $host,
      'user_id: ' . $this->currentUser->id(),
      'network: ' . $network,
      'address: ' . $address,
      'nonce: ' . bin2hex(random_bytes(16)),
      'issued_at: ' . gmdate(DATE_ATOM, $issued),
      'expires_at: ' . gmdate(DATE_ATOM, $expires),
    ]);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function saveUserFields(array $values): void {
    $account = $this->loadUser();
    foreach ($values as $field => $value) {
      $account->set($field, $value);
    }
    $account->save();
  }

  private function loadUser() {
    return $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
  }

  private function isNetworkAddress(string $value, string $network): bool {
    $prefix = match ($network) {
      'mainnet' => 'N',
      'testnet' => 'T',
      default => '',
    };
    return $prefix !== '' && preg_match('/^' . $prefix . '[A-Z2-7]{38}$/', strtoupper(trim($value))) === 1;
  }

}
