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
use Drupal\symbol_atomic_swap\Signing\AliceSignUrl;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class SymbolAccountVerificationForm extends FormBase {

  private const TEMPSTORE_COLLECTION = 'symbol_atomic_swap_account_verification';
  private const TEMPSTORE_KEY = 'challenge';
  private const CHALLENGE_TTL = 600;
  private const VERIFICATION_METHOD = 'sss_zero_fee_transfer';
  private const ON_CHAIN_VERIFICATION_METHOD = 'on_chain_transfer';

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

    $form['#attached']['library'][] = 'symbol_atomic_swap/qr';
    $form['#attached']['library'][] = 'symbol_atomic_swap/sss_sign';
    $alice_url = '';
    if ($challenge) {
      $form['#attributes']['data-symbol-sss-container'] = '1';
      $form['#attributes']['data-symbol-sss-unsigned-payload'] = (string) $challenge['unsignedPayload'];
      $form['#attributes']['data-symbol-sss-required-signer'] = (string) $challenge['publicKey'];
      $alice_url = AliceSignUrl::transaction((string) $challenge['unsignedPayload'], (string) $challenge['publicKey']);
    }

    $verified = (bool) ($account->get('field_symbol_address_verified')->value ?? FALSE);
    $verified_at = (int) ($account->get('field_symbol_address_verified_at')->value ?? 0);
    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Verification status'),
      '#markup' => $verified
      ? $this->t('Verified at @time.', ['@time' => $this->dateFormatterService()->format($verified_at, 'short')])
        : $this->t('Not verified.'),
    ];

    if ($verified) {
      $form['network'] = [
        '#type' => 'item',
        '#title' => $this->t('Symbol network'),
        '#markup' => $this->plainValue((string) ($account->get('field_symbol_network')->value ?? '')),
      ];
      $form['address'] = [
        '#type' => 'item',
        '#title' => $this->t('Symbol address'),
        '#markup' => $this->plainValue((string) ($account->get('field_symbol_address')->value ?? '')),
      ];
    }
    else {
      $form['network'] = [
        '#type' => 'select',
        '#title' => $this->t('Symbol network'),
        '#options' => $this->networkOptions(),
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
    }

    if ($challenge) {
      $expires = (int) $challenge['expires'];
      $on_chain_recipient = $this->onChainRecipient((string) $challenge['network']);
      $on_chain_message = $this->onChainMessage($challenge);
      $form['challenge'] = [
        '#type' => 'details',
        '#title' => $this->t('Current verification challenge'),
        '#open' => TRUE,
        'expires' => [
          '#type' => 'item',
          '#title' => $this->t('Expires'),
          '#markup' => $this->dateFormatterService()->format($expires, 'short'),
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
        'manual' => [
          '#type' => 'details',
          '#title' => $this->t('Manual signing without SSS'),
          '#open' => TRUE,
          'notice' => [
            '#type' => 'item',
            '#markup' => $this->t('Copy this zero-fee verification payload, sign it with Symbol CLI or an SDK tool that can sign raw transaction payloads, then paste the signed payload below. Do not announce this transaction.'),
          ],
          'copy' => [
            '#type' => 'container',
            'label' => [
              '#type' => 'html_tag',
              '#tag' => 'strong',
              '#value' => (string) $this->t('Copy unsigned verification payload'),
            ],
            'value' => $this->copyValue((string) $challenge['unsignedPayload']),
          ],
        ],
        'sss' => [
          '#type' => 'details',
          '#title' => $this->t('Browser signing with SSS'),
          '#open' => FALSE,
          'controls' => [
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
        ],
        'alice' => [
          '#type' => 'details',
          '#title' => $this->t('Mobile signing with aLice'),
          '#open' => FALSE,
          'notice' => [
            '#type' => 'item',
            '#markup' => $this->t('Scan this QR with a phone that has aLice installed, or open the aLice URL on the mobile device. aLice displays the signed payload when no callback URL is provided; paste that signed payload below and submit it for verification.'),
          ],
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
        ],
        'onchain' => [
          '#type' => 'details',
          '#title' => $this->t('On-chain verification without signing tools'),
          '#open' => FALSE,
          'notice' => [
            '#type' => 'item',
            '#markup' => $on_chain_recipient !== ''
              ? $this->t('Use this only when SSS, Symbol CLI, and SDK signing tools are unavailable. Send a confirmed Symbol Transfer from the registered address to the site address with the exact message below, then paste the transaction hash. This costs a network fee and the verification transfer is public on-chain. Do not send funds; use a message-only transfer if your wallet supports it.')
              : $this->t('On-chain verification is disabled because no site recipient address is configured for this network.'),
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
      if ($on_chain_recipient !== '') {
        $form['challenge']['onchain']['recipient'] = [
          '#type' => 'item',
          '#title' => $this->t('Site recipient address'),
          '#markup' => $this->plainValue($on_chain_recipient),
        ];
        $form['challenge']['onchain']['recipient_copy'] = $this->copyValue($on_chain_recipient);
        $form['challenge']['onchain']['message'] = [
          '#type' => 'item',
          '#title' => $this->t('Transfer message'),
          '#markup' => $this->plainValue($on_chain_message),
        ];
        $form['challenge']['onchain']['message_copy'] = $this->copyValue($on_chain_message);
        $form['challenge']['onchain']['transaction_hash'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Confirmed transaction hash'),
          '#maxlength' => 64,
          '#size' => 72,
          '#attributes' => [
            'autocomplete' => 'off',
            'spellcheck' => 'false',
          ],
        ];
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    if ($verified) {
      $form['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove registered Symbol account'),
        '#validate' => [],
        '#submit' => ['::submitRemove'],
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }
    else {
      $form['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $challenge ? $this->t('Regenerate verification payload') : $this->t('Generate verification payload'),
        '#button_type' => $challenge ? 'secondary' : 'primary',
        '#validate' => ['::validateGenerate'],
        '#submit' => ['::submitGenerate'],
      ];
    }
    if (!$verified && $challenge) {
      $form['actions']['verify'] = [
        '#type' => 'submit',
        '#value' => $this->t('Verify signed payload'),
        '#button_type' => 'primary',
        '#validate' => ['::validateVerify'],
        '#submit' => ['::submitVerify'],
      ];
      if ($this->onChainRecipient((string) $challenge['network']) !== '') {
        $form['actions']['verify_on_chain'] = [
          '#type' => 'submit',
          '#value' => $this->t('Verify on-chain transaction'),
          '#button_type' => 'secondary',
          '#validate' => ['::validateOnChainVerify'],
          '#submit' => ['::submitOnChainVerify'],
        ];
      }
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Submit handling is routed to explicit generate/verify submit handlers.
  }

  public function validateGenerate(array &$form, FormStateInterface $form_state): void {
    $network = (string) $form_state->getValue('network');
    $address = strtoupper(trim((string) $form_state->getValue('address')));
    if ($network === 'mainnet' && !$this->mainnetEnabled()) {
      $form_state->setErrorByName('network', $this->t('Mainnet operations are disabled in Symbol Atomic Swap settings.'));
      return;
    }
    if (!$this->isNetworkAddress($address, $network)) {
      $form_state->setErrorByName('address', $this->t('Symbol address must be a valid raw address for the selected network.'));
      return;
    }
    if ($this->verifiedAddressBelongsToAnotherUser($network, $address)) {
      $form_state->setErrorByName('address', $this->t('This Symbol address is already registered by another user.'));
      return;
    }

    try {
      $form_state->set('symbol_public_key', $this->publicKeyResolverService()->resolve($network, $address));
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
    $challenge_hash = hash('sha256', $challenge);
    $on_chain_message = 'symbol-atomic-swap:' . $challenge_hash;

    try {
      $built = $this->engineClientService()->buildAccountVerification($network, $address, $public_key, $challenge);
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
      'field_symbol_challenge_hash' => $challenge_hash,
    ]);
    $this->tempStoreFactoryService()->get(self::TEMPSTORE_COLLECTION)->set(self::TEMPSTORE_KEY, [
      'network' => $network,
      'address' => $address,
      'publicKey' => $public_key,
      'challenge' => $challenge,
      'challengeHash' => $challenge_hash,
      'onChainMessage' => $on_chain_message,
      'unsignedPayload' => strtoupper((string) $built['unsignedPayload']),
      'issued' => $issued,
      'expires' => $expires,
    ]);
    $this->messenger()->addStatus($this->t('Verification payload was generated. Sign it with SSS, then submit the signed payload.'));
    $form_state->setRebuild();
  }

  public function submitRemove(array &$form, FormStateInterface $form_state): void {
    $this->saveUserFields([
      'field_symbol_network' => NULL,
      'field_symbol_address' => NULL,
      'field_symbol_public_key' => NULL,
      'field_symbol_address_verified' => FALSE,
      'field_symbol_address_verified_at' => NULL,
      'field_symbol_verification_method' => NULL,
      'field_symbol_challenge_hash' => NULL,
    ]);
    $this->tempStoreFactoryService()->get(self::TEMPSTORE_COLLECTION)->delete(self::TEMPSTORE_KEY);
    $this->messenger()->addStatus($this->t('Registered Symbol account was removed. You can register a new address.'));
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
    if ($this->verifiedAddressBelongsToAnotherUser((string) $challenge['network'], (string) $challenge['address'])) {
      $form_state->setErrorByName('signed_payload', $this->t('This Symbol address is already registered by another user.'));
      return;
    }
    $payload = $this->signedPayloadValue($form_state);
    if (!preg_match('/^[0-9A-F]+$/', $payload) || strlen($payload) % 2 !== 0) {
      $form_state->setErrorByName('signed_payload', $this->t('Signed payload must be even-length hex.'));
    }
  }

  public function submitVerify(array &$form, FormStateInterface $form_state): void {
    $challenge = $this->challenge();
    if (!$challenge) {
      return;
    }
    $payload = $this->signedPayloadValue($form_state);

    try {
      $result = $this->engineClientService()->verifyAccountVerification(
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
    if ($this->verifiedAddressBelongsToAnotherUser((string) $challenge['network'], (string) $challenge['address'])) {
      $this->messenger()->addError($this->t('This Symbol address is already registered by another user.'));
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
    $this->tempStoreFactoryService()->get(self::TEMPSTORE_COLLECTION)->delete(self::TEMPSTORE_KEY);
    $this->messenger()->addStatus($this->t('Symbol address ownership was verified.'));
    $form_state->setRebuild();
  }

  public function validateOnChainVerify(array &$form, FormStateInterface $form_state): void {
    $challenge = $this->challenge();
    if (!$challenge) {
      $form_state->setErrorByName('transaction_hash', $this->t('Generate a verification payload first.'));
      return;
    }
    if ((int) $challenge['expires'] < \Drupal::time()->getRequestTime()) {
      $form_state->setErrorByName('transaction_hash', $this->t('Verification challenge expired. Generate a new payload.'));
      return;
    }
    if ($this->verifiedAddressBelongsToAnotherUser((string) $challenge['network'], (string) $challenge['address'])) {
      $form_state->setErrorByName('transaction_hash', $this->t('This Symbol address is already registered by another user.'));
      return;
    }
    if ($this->onChainRecipient((string) $challenge['network']) === '') {
      $form_state->setErrorByName('transaction_hash', $this->t('On-chain verification is not configured for this network.'));
      return;
    }
    $transaction_hash = $this->transactionHashValue($form_state);
    if (!preg_match('/^[0-9A-F]{64}$/', $transaction_hash)) {
      $form_state->setErrorByName('transaction_hash', $this->t('Transaction hash must be 64 hex characters.'));
    }
  }

  public function submitOnChainVerify(array &$form, FormStateInterface $form_state): void {
    $challenge = $this->challenge();
    if (!$challenge) {
      return;
    }
    $network = (string) $challenge['network'];
    $transaction_hash = $this->transactionHashValue($form_state);
    $recipient = $this->onChainRecipient($network);

    try {
      $result = $this->engineClientService()->verifyOnChainAccountVerification(
        $network,
        (string) $challenge['address'],
        (string) $challenge['publicKey'],
        $this->onChainMessage($challenge),
        $recipient,
        $transaction_hash,
      );
    }
    catch (SymbolEngineException | \RuntimeException | \InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('On-chain verification failed: @message', ['@message' => $exception->getMessage()]));
      return;
    }

    if (empty($result['accepted'])) {
      $this->messenger()->addError($this->t('On-chain verification failed: @message', ['@message' => (string) ($result['reason'] ?? 'rejected')]));
      return;
    }
    if ($this->verifiedAddressBelongsToAnotherUser($network, (string) $challenge['address'])) {
      $this->messenger()->addError($this->t('This Symbol address is already registered by another user.'));
      return;
    }

    $this->saveUserFields([
      'field_symbol_network' => $network,
      'field_symbol_address' => (string) $challenge['address'],
      'field_symbol_public_key' => strtoupper((string) ($result['signerPublicKey'] ?? $challenge['publicKey'])),
      'field_symbol_address_verified' => TRUE,
      'field_symbol_address_verified_at' => \Drupal::time()->getRequestTime(),
      'field_symbol_verification_method' => self::ON_CHAIN_VERIFICATION_METHOD,
      'field_symbol_challenge_hash' => (string) $challenge['challengeHash'],
    ]);
    $this->tempStoreFactoryService()->get(self::TEMPSTORE_COLLECTION)->delete(self::TEMPSTORE_KEY);
    $this->messenger()->addStatus($this->t('Symbol address ownership was verified by confirmed on-chain transfer.'));
    $form_state->setRebuild();
  }

  /**
   * @return array<string, mixed>|null
   */
  private function challenge(): ?array {
    $challenge = $this->tempStoreFactoryService()->get(self::TEMPSTORE_COLLECTION)->get(self::TEMPSTORE_KEY);
    return is_array($challenge) ? $challenge : NULL;
  }

  private function signedPayloadValue(FormStateInterface $form_state): string {
    $payload = $form_state->getValue('signed_payload');
    if ($payload === NULL) {
      $payload = $form_state->getValue(['challenge', 'signed_payload']);
    }
    return strtoupper(preg_replace('/\s+/', '', (string) $payload));
  }

  private function transactionHashValue(FormStateInterface $form_state): string {
    $transaction_hash = $form_state->getValue('transaction_hash');
    if ($transaction_hash === NULL) {
      $transaction_hash = $form_state->getValue(['challenge', 'onchain', 'transaction_hash']);
    }
    return strtoupper(preg_replace('/\s+/', '', (string) $transaction_hash));
  }

  private function verifiedAddressBelongsToAnotherUser(string $network, string $address): bool {
    $network = strtolower(trim($network));
    $address = strtoupper(trim($address));
    if ($network === '' || $address === '') {
      return FALSE;
    }

    $matches = $this->entityTypeManagerService()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $this->currentUserService()->id(), '<>')
      ->condition('field_symbol_network', $network)
      ->condition('field_symbol_address', $address)
      ->condition('field_symbol_address_verified', TRUE)
      ->range(0, 1)
      ->execute();

    return $matches !== [];
  }

  private function plainValue(string $value): string {
    return $value !== '' ? $value : (string) $this->t('Not set');
  }

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private function networkOptions(): array {
    $options = ['testnet' => $this->t('Testnet')];
    if ($this->mainnetEnabled()) {
      $options['mainnet'] = $this->t('Mainnet');
    }
    return $options;
  }

  private function mainnetEnabled(): bool {
    return (bool) $this->config('symbol_atomic_swap.settings')->get('mainnet_enabled');
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

  private function challengeMessage(string $network, string $address, int $issued, int $expires): string {
    $host = $this->requestStackService()->getCurrentRequest()?->getHost() ?: 'localhost';
    return implode("\n", [
      'symbol-atomic-swap address verification',
      'domain: ' . $host,
      'user_id: ' . $this->currentUserService()->id(),
      'network: ' . $network,
      'address: ' . $address,
      'nonce: ' . bin2hex(random_bytes(16)),
      'issued_at: ' . gmdate(DATE_ATOM, $issued),
      'expires_at: ' . gmdate(DATE_ATOM, $expires),
    ]);
  }

  /**
   * @param array<string, mixed> $challenge
   */
  private function onChainMessage(array $challenge): string {
    $stored = (string) ($challenge['onChainMessage'] ?? '');
    if ($stored !== '') {
      return $stored;
    }
    return 'symbol-atomic-swap:' . (string) ($challenge['challengeHash'] ?? hash('sha256', (string) ($challenge['challenge'] ?? '')));
  }

  private function onChainRecipient(string $network): string {
    $key = match ($network) {
      'mainnet' => 'account_verification_recipient_mainnet',
      'testnet' => 'account_verification_recipient_testnet',
      default => '',
    };
    if ($key === '') {
      return '';
    }
    return strtoupper(trim((string) ($this->config('symbol_atomic_swap.settings')->get($key) ?: '')));
  }

  private function currentUserService(): AccountProxyInterface {
    return isset($this->currentUser)
      ? $this->currentUser
      : \Drupal::service('current_user');
  }

  private function entityTypeManagerService(): EntityTypeManagerInterface {
    return isset($this->entityTypeManager)
      ? $this->entityTypeManager
      : \Drupal::entityTypeManager();
  }

  private function publicKeyResolverService(): SymbolAccountPublicKeyResolverInterface {
    return isset($this->publicKeyResolver)
      ? $this->publicKeyResolver
      : \Drupal::service('symbol_atomic_swap.account_public_key_resolver');
  }

  private function engineClientService(): SymbolEngineClient {
    return isset($this->engineClient)
      ? $this->engineClient
      : \Drupal::service('symbol_atomic_swap.engine_client');
  }

  private function tempStoreFactoryService(): PrivateTempStoreFactory {
    return isset($this->tempStoreFactory)
      ? $this->tempStoreFactory
      : \Drupal::service('tempstore.private');
  }

  private function dateFormatterService(): DateFormatterInterface {
    return isset($this->dateFormatter)
      ? $this->dateFormatter
      : \Drupal::service('date.formatter');
  }

  private function requestStackService(): RequestStack {
    return isset($this->requestStackService)
      ? $this->requestStackService
      : \Drupal::service('request_stack');
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
    return $this->entityTypeManagerService()->getStorage('user')->load((int) $this->currentUserService()->id());
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
