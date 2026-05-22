<?php

namespace Drupal\symbol_login\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\symbol_engine\Exception\SymbolEngineException;
use Drupal\symbol_engine\Service\SymbolEngineClient;
use Drupal\symbol_login\Service\ChallengeManager;
use Drupal\symbol_login\Service\RoleSynchronizer;
use Drupal\symbol_login\Service\SignatureVerifier;
use Drupal\symbol_login\Service\SymbolLoginException;
use Drupal\symbol_login\Service\SymbolUserMapper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the Symbol login page.
 */
final class SymbolLoginForm extends FormBase {

  public function __construct(
    protected RouteMatchInterface $currentRouteMatch,
    protected ChallengeManager $challengeManager,
    protected SignatureVerifier $signatureVerifier,
    protected SymbolEngineClient $symbolEngineClient,
    protected SymbolUserMapper $symbolUserMapper,
    protected RoleSynchronizer $roleSynchronizer,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_route_match'),
      $container->get('symbol_login.challenge_manager'),
      $container->get('symbol_login.signature_verifier'),
      $container->get('symbol_engine.client'),
      $container->get('symbol_login.user_mapper'),
      $container->get('symbol_login.role_synchronizer'),
    );
  }

  public function getFormId(): string {
    return 'symbol_login_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $provider = (string) ($this->currentRouteMatch->getParameter('provider') ?: '');
    $form['#attached']['library'][] = 'symbol_login/login';
    $form['#attached']['drupalSettings']['symbolLogin'] = [
      'challengeUrl' => Url::fromRoute('symbol_login.challenge')->toString(),
      'verifyUrl' => Url::fromRoute('symbol_login.verify')->toString(),
      'sssChallengeUrl' => Url::fromRoute('symbol_login.sss_challenge')->toString(),
      'sssVerifyUrl' => Url::fromRoute('symbol_login.sss_verify')->toString(),
      'provider' => $provider,
    ];

    if ($provider === '') {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['sss'] = [
        '#type' => 'link',
        '#title' => $this->t('Continue with SSS'),
        '#url' => Url::fromRoute('symbol_login.login_sss'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'symbol-login-button'],
        ],
      ];
      $form['actions']['alice'] = [
        '#type' => 'link',
        '#title' => $this->t('Continue with aLice'),
        '#url' => Url::fromRoute('symbol_login.login_alice'),
        '#attributes' => [
          'class' => ['button', 'symbol-login-button'],
        ],
      ];
      return $form;
    }

    if ($provider === 'alice') {
      return $this->buildAliceForm($form, $form_state);
    }

    $label = $provider === 'alice' ? $this->t('aLice') : $this->t('SSS');

    $form['status'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['symbol-login-status'],
        'data-symbol-login-status' => 'idle',
      ],
      'message' => [
        '#markup' => '<span data-symbol-login-message>' . $this->t('@providerで署名してログインします。', ['@provider' => $label]) . '</span>',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['login'] = [
      '#type' => 'button',
      '#value' => $provider === 'alice' ? $this->t('Continue with aLice') : $this->t('Continue with SSS'),
      '#attributes' => [
        'data-symbol-login-trigger' => '1',
        'data-symbol-login-provider' => $provider,
        'class' => ['button', 'button--primary', 'symbol-login-button'],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  public function validateAliceGenerate(array &$form, FormStateInterface $form_state): void {
    $address = $this->normalizeAddress((string) $form_state->getValue('address'));
    $network = (string) ($this->config('symbol_login.settings')->get('network_type') ?: 'testnet');
    if (!$this->isNetworkAddress($address, $network)) {
      $form_state->setErrorByName('address', $this->t('Symbol address must be a valid raw address for the configured network.'));
    }
  }

  public function submitAliceGenerate(array &$form, FormStateInterface $form_state): void {
    $address = $this->normalizeAddress((string) $form_state->getValue('address'));
    $network = (string) ($this->config('symbol_login.settings')->get('network_type') ?: 'testnet');

    try {
      $resolved = $this->symbolEngineClient->accountPublicKey($network, $address);
      $public_key = $this->normalizePublicKey((string) ($resolved['publicKey'] ?? ''));
      if (!preg_match('/^[0-9A-F]{64}$/', $public_key)) {
        throw new SymbolLoginException('No public key was found for this address. Use an account that has sent at least one signed transaction.');
      }
      $challenge = $this->challengeManager->createForAccount($address, $public_key);
      $built = $this->symbolEngineClient->buildAccountVerification($network, $address, $public_key, (string) $challenge['message']);
    }
    catch (SymbolEngineException $exception) {
      $message = $exception->engineError === 'account_public_key_not_found'
        ? $this->t('No public key was found for this address. Use an account that has sent at least one signed transaction.')
        : $exception->getMessage();
      $this->messenger()->addError($this->t('aLice signing URL generation failed: @message', ['@message' => $message]));
      return;
    }
    catch (SymbolLoginException | \InvalidArgumentException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('aLice signing URL generation failed: @message', ['@message' => $exception->getMessage()]));
      return;
    }

    $form_state->set('alice_challenge', [
      'id' => (string) $challenge['id'],
      'address' => $address,
      'publicKey' => $public_key,
      'unsignedPayload' => strtoupper((string) ($built['unsignedPayload'] ?? '')),
    ]);
    $form_state->setRebuild();
  }

  public function validateAliceVerify(array &$form, FormStateInterface $form_state): void {
    $challenge_id = trim((string) $form_state->getValue('challenge_id'));
    $payload = $this->normalizeHex((string) $form_state->getValue('signed_payload'));
    if ($challenge_id === '') {
      $form_state->setErrorByName('signed_payload', $this->t('Generate an aLice signing URL first.'));
    }
    if ($payload === '' || !preg_match('/^[0-9A-F]+$/', $payload) || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('signed_payload', $this->t('Signed payload must be even-length hex.'));
    }
  }

  public function submitAliceVerify(array &$form, FormStateInterface $form_state): void {
    try {
      $challenge = $this->challengeManager->consumeRecord((string) $form_state->getValue('challenge_id'));
      $network = (string) ($challenge['network'] ?? '');
      $address = (string) ($challenge['address'] ?? '');
      $public_key = (string) ($challenge['publicKey'] ?? '');
      $payload = $this->normalizeHex((string) $form_state->getValue('signed_payload'));

      $result = $this->symbolEngineClient->verifyAccountVerification($network, $address, $public_key, (string) $challenge['message'], $payload);
      if (empty($result['accepted'])) {
        throw new SymbolLoginException('aLice signed payload was rejected: ' . (string) ($result['reason'] ?? 'rejected'));
      }

      $account = $this->symbolUserMapper->loadOrCreate($address, $public_key, 'symbol_login_alice_payload');
      $this->roleSynchronizer->synchronize($account, $address);
      user_login_finalize($account);
      $form_state->setRedirect('user.page');
    }
    catch (SymbolEngineException | SymbolLoginException | \InvalidArgumentException | \RuntimeException $exception) {
      $this->messenger()->addError($this->t('aLice login failed: @message', ['@message' => $exception->getMessage()]));
      $form_state->setRebuild();
    }
  }

  private function buildAliceForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'symbol_engine/qr';

    $challenge = $form_state->get('alice_challenge');
    $address = $challenge['address'] ?? $this->normalizeAddress((string) $form_state->getValue('address'));
    $public_key = $challenge['publicKey'] ?? '';
    $unsigned_payload = (string) ($challenge['unsignedPayload'] ?? '');
    $alice_url = $unsigned_payload !== '' ? $this->aliceTransactionUrl($unsigned_payload, (string) $public_key) : '';

    $form['notice'] = [
      '#type' => 'item',
      '#markup' => $this->t('Enter the Symbol address registered in aLice, generate a signing URL, then scan the QR or open the URL with aLice. Paste the signed payload returned by aLice to log in. The account must already have a public key on-chain.'),
    ];
    $form['address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Symbol address'),
      '#maxlength' => 46,
      '#size' => 52,
      '#default_value' => $address,
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    if ($public_key !== '') {
      $form['public_key'] = [
        '#type' => 'item',
        '#title' => $this->t('Resolved Symbol public key'),
        '#markup' => $public_key,
      ];
    }
    $form['generate'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Generate aLice signing URL'),
        '#button_type' => 'primary',
        '#validate' => ['::validateAliceGenerate'],
        '#submit' => ['::submitAliceGenerate'],
      ],
    ];

    if ($alice_url !== '') {
      $form['challenge_id'] = [
        '#type' => 'hidden',
        '#value' => (string) $challenge['id'],
      ];
      $form['alice'] = [
        '#type' => 'details',
        '#title' => $this->t('aLice signing URL'),
        '#open' => TRUE,
        'qr' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['symbol-atomic-swap-qr'],
            'data-qr-payload' => $alice_url,
          ],
        ],
        'open' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => (string) $this->t('Open aLice signer'),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
            'href' => $alice_url,
          ],
        ],
        'copy' => [
          '#type' => 'container',
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => (string) $this->t('Copy aLice signing URL'),
          ],
          'value' => $this->copyValue($alice_url),
        ],
      ];
      $form['signed_payload'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Signed payload'),
        '#rows' => 10,
        '#required' => TRUE,
        '#description' => $this->t('Paste the signed payload displayed by aLice.'),
        '#attributes' => [
          'autocomplete' => 'off',
          'spellcheck' => 'false',
        ],
      ];
      $form['actions'] = [
        '#type' => 'actions',
        'verify' => [
          '#type' => 'submit',
          '#value' => $this->t('Log in with signed payload'),
          '#button_type' => 'primary',
          '#validate' => ['::validateAliceVerify'],
          '#submit' => ['::submitAliceVerify'],
        ],
      ];
    }

    return $form;
  }

  private function copyValue(string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['symbol-atomic-swap-copy']],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'code',
        '#value' => $value,
        '#attributes' => ['class' => ['symbol-atomic-swap-long-value']],
      ],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => (string) $this->t('Copy'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--small', 'symbol-atomic-swap-copy__button'],
          'data-symbol-copy' => $value,
        ],
      ],
    ];
  }

  private function aliceTransactionUrl(string $unsigned_payload, string $public_key): string {
    return 'alice://sign?' . http_build_query([
      'type' => 'request_sign_transaction',
      'set_public_key' => $this->normalizePublicKey($public_key),
      'data' => $this->normalizeHex($unsigned_payload),
    ], '', '&', PHP_QUERY_RFC3986);
  }

  private function normalizeAddress(string $address): string {
    return strtoupper(str_replace(['-', ' '], '', trim($address)));
  }

  private function normalizePublicKey(string $public_key): string {
    return $this->normalizeHex($public_key);
  }

  private function isNetworkAddress(string $address, string $network): bool {
    try {
      $profile = $this->symbolEngineClient->networkProfile($network);
    }
    catch (SymbolEngineException | \InvalidArgumentException | \RuntimeException) {
      return FALSE;
    }
    $prefix = strtoupper((string) ($profile['addressPrefix'] ?? ''));
    return $prefix !== '' && preg_match('/^' . preg_quote($prefix, '/') . '[A-Z2-7]{38}$/', strtoupper(trim($address))) === 1;
  }

  private function normalizeHex(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
  }

}
