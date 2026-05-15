<?php

declare(strict_types=1);

namespace Drupal\Tests\symbol_atomic_swap\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests swap offer UI access control and form rendering.
 */
#[Group('symbol_atomic_swap')]
#[RunTestsInSeparateProcesses]
final class SwapOfferRoutesTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['symbol_atomic_swap'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Offer CRUD routes must require explicit permissions.
   */
  public function testOfferRoutesRequireExplicitPermissions(): void {
    $this->installAccountPublicKeyResolverStub();
    $assert_session = $this->assertSession();

    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(403);

    $viewer = $this->drupalCreateUser(['view symbol atomic swap offers']);
    $this->drupalLogin($viewer);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('No swap offers have been created.');
    $assert_session->linkNotExists('Create swap offer');

    $creator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'create symbol atomic swap offers',
    ]);
    $this->drupalLogin($creator);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Create swap offer');

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Offer label');
    $assert_session->fieldExists('Correlation ID');
    $assert_session->fieldExists('Maker address');
    $assert_session->fieldExists('Resolved maker public key');
    $assert_session->pageTextContains('Same as Maker address.');
    $assert_session->buttonExists('Create trade offer');

    $this->drupalGet('/symbol-atomic-swap/public-key/testnet/TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ');
    $assert_session->statusCodeEquals(200);
    $this->assertStringContainsString('97E42C98FF3E5D0DD4BEB7234628DFE658402EDAD7A2CF5190451F7EFFA5B79D', $this->getSession()->getPage()->getContent());
  }

  /**
   * Nested transfer leg values must not overwrite each other on submit.
   */
  public function testCreateOfferPreservesDistinctTransferLegValues(): void {
    $this->installAccountPublicKeyResolverStub();
    $creator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'create symbol atomic swap offers',
    ]);
    $this->drupalLogin($creator);

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $this->submitForm([
      'label' => 'Distinct leg submit offer',
      'network' => 'testnet',
      'correlation_id' => 'ui-distinct-leg-0001',
      'deadline_hours' => '2',
      'max_fee' => '',
      'maker_pays[address]' => 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'maker_pays[mosaic_id]' => '72C0212E67A08BCE',
      'maker_pays[amount]' => '100',
      'maker_wants[mosaic_id]' => '72C0212E67A08BCF',
      'maker_wants[amount]' => '200',
    ], 'Create trade offer');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextNotContains('Signer public key must be 64 hex characters.');
    $assert_session->pageTextNotContains('Recipient address must be a valid raw Symbol address for the selected network.');
    $assert_session->pageTextNotContains('Mosaic ID must be 16 hex characters.');
    $assert_session->pageTextNotContains('Amount must be a positive integer.');

    $records = \Drupal::service('symbol_atomic_swap.offer_repository')->search([
      'q' => 'ui-distinct-leg-0001',
    ]);
    $this->assertCount(1, $records);
    $this->assertSame('97E42C98FF3E5D0DD4BEB7234628DFE658402EDAD7A2CF5190451F7EFFA5B79D', $records[0]['leg1_signer_public_key']);
    $this->assertSame('', $records[0]['leg1_recipient_address']);
    $this->assertSame('72C0212E67A08BCE', $records[0]['leg1_mosaic_id']);
    $this->assertSame('100', $records[0]['leg1_amount']);
    $this->assertSame('', $records[0]['leg2_signer_public_key']);
    $this->assertSame('TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', $records[0]['leg2_recipient_address']);
    $this->assertSame('72C0212E67A08BCF', $records[0]['leg2_mosaic_id']);
    $this->assertSame('200', $records[0]['leg2_amount']);
  }

  /**
   * Accepting an offer resolves the taker public key from the taker address.
   */
  public function testAcceptOfferResolvesTakerPublicKeyFromAddress(): void {
    $this->installAccountPublicKeyResolverStub();
    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-accept-address',
      'label' => 'Accept address offer',
      'state' => 'open',
      'intent_hash' => NULL,
      'unsigned_payload' => NULL,
      'qr_payload' => NULL,
      'transaction_hash' => NULL,
      'uid' => (int) $operator->id(),
    ]));

    $this->drupalLogin($operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/accept');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Taker recipient address');
    $assert_session->fieldExists('Resolved taker public key');
    $assert_session->buttonExists('Accept and build QR');

    $this->submitForm([
      'taker[recipient_address]' => 'TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI',
    ], 'Accept and build QR');

    $offer = $repository->find($id);
    $this->assertSame('TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI', $offer['leg1_recipient_address']);
    $this->assertSame('D82CF80BDA16BE82EB8ED09995DC3CC5DA56E22D4B75E9B9F44B3FA51543AC16', $offer['leg2_signer_public_key']);
  }

  /**
   * Correlation IDs must be unique per network.
   */
  public function testCreateOfferRejectsDuplicateNetworkCorrelationId(): void {
    $this->installAccountPublicKeyResolverStub();
    $creator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'create symbol atomic swap offers',
    ]);
    $this->drupalLogin($creator);

    \Drupal::service('symbol_atomic_swap.offer_repository')->insert($this->offerValues([
      'uuid' => 'offer-duplicate-correlation',
      'label' => 'Existing correlation offer',
      'network' => 'testnet',
      'correlation_id' => 'ui-duplicate-correlation',
      'uid' => (int) $creator->id(),
    ]));

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $this->submitForm([
      'label' => 'Duplicate correlation offer',
      'network' => 'testnet',
      'correlation_id' => 'ui-duplicate-correlation',
      'deadline_hours' => '2',
      'max_fee' => '',
      'maker_pays[address]' => 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
      'maker_pays[mosaic_id]' => '72C0212E67A08BCE',
      'maker_pays[amount]' => '100',
      'maker_wants[mosaic_id]' => '72C0212E67A08BCF',
      'maker_wants[amount]' => '200',
    ], 'Create trade offer');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Correlation ID is already used for this network.');

    $records = \Drupal::service('symbol_atomic_swap.offer_repository')->search([
      'q' => 'ui-duplicate-correlation',
    ]);
    $this->assertCount(1, $records);
  }


  /**
   * Existing offers can be viewed and admin-only edit/delete routes render.
   */
  public function testExistingOfferRoutesRender(): void {
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert([
      'uuid' => 'offer-test-uuid',
      'label' => 'Test offer',
      'state' => 'qr_generated',
      'network' => 'testnet',
      'correlation_id' => 'swap-test-0001',
      'deadline_hours' => 2,
      'max_fee' => NULL,
      'leg1_signer_public_key' => str_repeat('A', 64),
      'leg1_recipient_address' => 'TAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
      'leg1_mosaic_id' => '72C0212E67A08BCE',
      'leg1_amount' => '100',
      'leg2_signer_public_key' => str_repeat('B', 64),
      'leg2_recipient_address' => 'TBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
      'leg2_mosaic_id' => '72C0212E67A08BCE',
      'leg2_amount' => '200',
      'intent_hash' => str_repeat('C', 64),
      'unsigned_payload' => 'ABCD',
      'qr_payload' => '{"type":"symbol-aggregate-complete"}',
      'transaction_hash' => NULL,
      'projection_state' => NULL,
      'block_height' => NULL,
      'finalized_height' => NULL,
      'projection_updated_at' => NULL,
      'expired_at' => NULL,
      'uid' => 1,
      'created' => 1700000000,
      'changed' => 1700000000,
    ]);
    \Drupal::service('symbol_atomic_swap.offer_notification_repository')->createOnce(
      $id,
      'offer_confirmed',
      'status',
      'Swap transaction was confirmed but is not finalized yet.',
    );

    $admin = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'administer symbol atomic swap offers',
    ]);
    $this->drupalLogin($admin);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Test offer');
    $assert_session->pageTextContains('qr_generated');
    $assert_session->pageTextContains('Summary');
    $assert_session->pageTextContains('Trade terms');
    $assert_session->pageTextContains('Projection');
    $assert_session->pageTextContains('Manual sync allowed');
    $assert_session->pageTextContains('Automatic sync eligible');
    $assert_session->pageTextContains(str_repeat('C', 64));
    $assert_session->pageTextContains('Swap transaction was confirmed but is not finalized yet.');
    $assert_session->pageTextContains('status / unread');
    $assert_session->pageTextContains('QR payload');
    $assert_session->pageTextContains('QR URL');
    $assert_session->pageTextContains('"type": "symbol-aggregate-complete"');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->pageTextContains('Public offer JSON');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/qr-payload/' . str_repeat('C', 64));
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('QR payload for Test offer');
    $assert_session->pageTextContains('QR scan text');
    $assert_session->pageTextContains('symbol-swap:v1:');
    $assert_session->pageTextContains('Unsigned payload');
    $assert_session->pageTextContains('QR payload JSON');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/qr-payload/' . str_repeat('D', 64));
    $assert_session->statusCodeEquals(404);

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/edit');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldValueEquals('Offer label', 'Test offer');
    $assert_session->buttonExists('Save offer');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/delete');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Delete Test offer?');
  }

  /**
   * Signed payload and announce routes require operation permission.
   */
  public function testOfferOperationRoutesRenderForOperators(): void {
    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert([
      'uuid' => 'offer-operator-uuid',
      'label' => 'Operator offer',
      'state' => 'qr_generated',
      'network' => 'testnet',
      'correlation_id' => 'swap-test-0002',
      'deadline_hours' => 2,
      'max_fee' => NULL,
      'leg1_signer_public_key' => str_repeat('A', 64),
      'leg1_recipient_address' => 'TAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
      'leg1_mosaic_id' => '72C0212E67A08BCE',
      'leg1_amount' => '100',
      'leg2_signer_public_key' => str_repeat('B', 64),
      'leg2_recipient_address' => 'TBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
      'leg2_mosaic_id' => '72C0212E67A08BCE',
      'leg2_amount' => '200',
      'intent_hash' => str_repeat('C', 64),
      'unsigned_payload' => 'ABCD',
      'qr_payload' => '{"type":"symbol-aggregate-complete"}',
      'transaction_hash' => NULL,
      'projection_state' => NULL,
      'block_height' => NULL,
      'finalized_height' => NULL,
      'projection_updated_at' => NULL,
      'expired_at' => NULL,
      'uid' => (int) $operator->id(),
      'created' => 1700000000,
      'changed' => 1700000000,
    ]);

    $viewer = $this->drupalCreateUser(['view symbol atomic swap offers']);
    $this->drupalLogin($viewer);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/sign-with-sss');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-aggregate-signer-json');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/assemble-signed-payload');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/cosign-with-sss');
    $this->assertSession()->statusCodeEquals(403);

    $non_owner_operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $this->drupalLogin($non_owner_operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/sign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Unsigned payload sent to SSS');
    $assert_session->fieldExists('Signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-aggregate-signer-json');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Aggregate signer JSON');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/assemble-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Root signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/cosign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Cosignature JSON');

    $this->drupalLogin($operator);

    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Submit signed payload');
    $assert_session->linkExists('Sign with SSS');
    $assert_session->linkExists('Submit aggregate signer JSON');
    $assert_session->linkExists('Cosign with SSS');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Submit signed payload');
    $assert_session->linkExists('Sign with SSS');
    $assert_session->linkExists('Submit aggregate signer JSON');
    $assert_session->linkExists('Cosign with SSS');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Signed payload');
    $assert_session->buttonExists('Verify signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/sign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Unsigned payload sent to SSS');
    $assert_session->fieldExists('Signed payload');
    $assert_session->buttonExists('Sign unsigned payload with SSS');
    $assert_session->buttonExists('Verify SSS signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-aggregate-signer-json');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Aggregate signer JSON');
    $assert_session->buttonExists('Build and verify root signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/cosign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Unsigned payload sent to SSS');
    $assert_session->fieldExists('Parent hash fallback');
    $assert_session->fieldExists('Cosignature JSON');
    $assert_session->buttonExists('Cosign unsigned payload with SSS');
    $assert_session->buttonExists('Verify and store SSS cosignature');

    $repository->markSigned($id, str_repeat('D', 64));
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Submit signed payload');
    $assert_session->linkExists('Announce transaction');
    $assert_session->linkExists('Sync projection');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Announce transaction');
    $assert_session->linkExists('Sync projection');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/announce');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Announce Operator offer?');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/sync-projection');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Sync projection for Operator offer?');
  }

  /**
   * Offer list filters and terminal state labels are visible.
   */
  public function testOfferListFiltersByStateNetworkOwnerAndSearch(): void {
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $repository->insert($this->offerValues([
      'uuid' => 'offer-filter-alpha',
      'label' => 'Alpha finalized offer',
      'state' => 'finalized',
      'network' => 'testnet',
      'uid' => 11,
      'transaction_hash' => str_repeat('D', 64),
    ]));
    $repository->insert($this->offerValues([
      'uuid' => 'offer-filter-beta',
      'label' => 'Beta signed offer',
      'state' => 'signed',
      'network' => 'mainnet',
      'uid' => 12,
      'transaction_hash' => str_repeat('E', 64),
    ]));

    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
      'administer symbol atomic swap offers',
    ]);
    $this->drupalLogin($operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers', [
      'query' => [
        'state' => 'finalized',
        'network' => 'testnet',
        'owner' => '11',
        'q' => 'Alpha',
      ],
    ]);
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldValueEquals('q', 'Alpha');
    $assert_session->pageTextContains('Alpha finalized offer');
    $assert_session->pageTextContains('finalized [terminal, completed]');
    $assert_session->pageTextNotContains('Beta signed offer');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->linkNotExists('Announce transaction');
    $assert_session->linkNotExists('Sync projection');
  }

  /**
   * Signed payload route blocks terminal offers.
   */
  public function testSignedPayloadFormBlocksTerminalOffers(): void {
    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-terminal-payload',
      'label' => 'Terminal payload offer',
      'state' => 'finalized',
      'transaction_hash' => str_repeat('D', 64),
      'uid' => (int) $operator->id(),
    ]));

    $this->drupalLogin($operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $assert_session->statusCodeEquals(403);
  }

  /**
   * Notification list supports unread filtering and mark-read operations.
   */
  public function testNotificationListAndReadActions(): void {
    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-notification-list',
      'label' => 'Notification list offer',
      'uid' => (int) $operator->id(),
    ]));
    \Drupal::service('symbol_atomic_swap.offer_notification_repository')->createOnce(
      $id,
      'offer_failed',
      'error',
      'Swap transaction failed on-chain.',
    );

    $this->drupalLogin($operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/notifications', ['query' => ['unread' => '1']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Unread notifications');
    $assert_session->pageTextContains('Swap transaction failed on-chain.');
    $assert_session->pageTextContains('Unread');
    $assert_session->linkExists('Mark read');

    $this->clickLink('Mark read');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Mark notification as read?');
    $this->submitForm([], 'Confirm');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Notification was marked read.');
    $assert_session->pageTextContains('Read');
  }

  /**
   * Non-admin users can view public offers but cannot owner-operate another account's offer.
   */
  public function testOfferRoutesAreScopedToOwnerForNonAdmins(): void {
    $owner = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $other = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-owner-scope',
      'label' => 'Owner scoped offer',
      'state' => 'signed',
      'transaction_hash' => str_repeat('D', 64),
      'uid' => (int) $owner->id(),
    ]));

    $assert_session = $this->assertSession();
    $this->drupalLogin($other);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Owner scoped offer');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $assert_session->statusCodeEquals(200);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/announce');
    $assert_session->statusCodeEquals(403);

    $this->drupalLogin($owner);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Owner scoped offer');
  }

  /**
   * Transaction history is scoped to owned offers and marks only finalized as completed.
   */
  public function testTransactionHistoryIsOwnerScoped(): void {
    $owner = $this->drupalCreateUser(['view symbol atomic swap offers']);
    $other = $this->drupalCreateUser(['view symbol atomic swap offers']);
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $repository->insert($this->offerValues([
      'uuid' => 'offer-history-finalized',
      'label' => 'Finalized history offer',
      'state' => 'finalized',
      'transaction_hash' => str_repeat('D', 64),
      'projection_state' => 'finalized',
      'finalized_height' => 20,
      'uid' => (int) $owner->id(),
    ]));
    $repository->insert($this->offerValues([
      'uuid' => 'offer-history-other',
      'label' => 'Other history offer',
      'state' => 'confirmed',
      'transaction_hash' => str_repeat('E', 64),
      'projection_state' => 'confirmed',
      'uid' => (int) $other->id(),
    ]));

    $this->drupalLogin($owner);
    $this->drupalGet('/symbol-atomic-swap/transactions');
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Finalized history offer');
    $assert_session->pageTextContains('Yes');
    $assert_session->pageTextContains('Copy');
    $assert_session->pageTextNotContains('Other history offer');
  }

  /**
   * @param array<string, mixed> $overrides
   *
   * @return array<string, mixed>
   */
  private function offerValues(array $overrides = []): array {
    return $overrides + [
      'uuid' => 'offer-functional-' . bin2hex(random_bytes(4)),
      'label' => 'Functional offer',
      'state' => 'qr_generated',
      'network' => 'testnet',
      'correlation_id' => 'swap-test-functional-' . bin2hex(random_bytes(4)),
      'deadline_hours' => 2,
      'max_fee' => NULL,
      'leg1_signer_public_key' => str_repeat('A', 64),
      'leg1_recipient_address' => 'TAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
      'leg1_mosaic_id' => '72C0212E67A08BCE',
      'leg1_amount' => '100',
      'leg2_signer_public_key' => str_repeat('B', 64),
      'leg2_recipient_address' => 'TBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
      'leg2_mosaic_id' => '72C0212E67A08BCE',
      'leg2_amount' => '200',
      'intent_hash' => str_repeat('C', 64),
      'unsigned_payload' => 'ABCD',
      'qr_payload' => '{"type":"symbol-aggregate-complete"}',
      'transaction_hash' => NULL,
      'projection_state' => NULL,
      'block_height' => NULL,
      'finalized_height' => NULL,
      'projection_updated_at' => NULL,
      'expired_at' => NULL,
      'uid' => 1,
      'created' => 1700000000,
      'changed' => 1700000000,
    ];
  }

  private function installAccountPublicKeyResolverStub(): void {
    \Drupal::state()->set('symbol_atomic_swap.account_public_key_test_overrides', [
      'testnet' => [
        'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ' => '97E42C98FF3E5D0DD4BEB7234628DFE658402EDAD7A2CF5190451F7EFFA5B79D',
        'TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI' => 'D82CF80BDA16BE82EB8ED09995DC3CC5DA56E22D4B75E9B9F44B3FA51543AC16',
      ],
    ]);
  }

}
