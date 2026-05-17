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
    $assert_session->pageTextContains('No atomic settlements have been created.');
    $assert_session->linkNotExists('Create atomic settlement');

    $creator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'create symbol atomic swap offers',
    ]);
    $this->verifySymbolAccount($creator);
    $this->drupalLogin($creator);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Create atomic settlement');

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Settlement label');
    $assert_session->fieldNotExists('Correlation ID');
    $assert_session->pageTextContains('Generated automatically when the offer is saved.');
    $assert_session->fieldNotExists('Deadline hours');
    $assert_session->fieldNotExists('Max fee');
    $assert_session->fieldNotExists('Maker address');
    $assert_session->pageTextContains('TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ');
    $assert_session->pageTextContains('Uses the verified address from My Symbol Account.');
    $assert_session->fieldNotExists('Resolved maker public key');
    $assert_session->fieldValueEquals('maker_pays[mosaic_id]', '72C0212E67A08BCE');
    $assert_session->fieldValueEquals('maker_wants[mosaic_id]', '72C0212E67A08BCE');
    $this->assertSame(2, $this->getSession()->getPage()->findAll('css', '[data-symbol-mosaic-status]') ? count($this->getSession()->getPage()->findAll('css', '[data-symbol-mosaic-status]')) : 0);
    $assert_session->pageTextContains('Same as Maker address.');
    $assert_session->buttonExists('Create settlement');

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
    $this->verifySymbolAccount($creator);
    $this->drupalLogin($creator);

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $this->submitForm([
      'label' => 'Distinct leg submit offer',
      'maker_pays[mosaic_id]' => '72C0212E67A08BCE',
      'maker_pays[amount]' => '100',
      'maker_wants[mosaic_id]' => '72C0212E67A08BCF',
      'maker_wants[amount]' => '200',
    ], 'Create settlement');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextNotContains('Signer public key must be 64 hex characters.');
    $assert_session->pageTextNotContains('Recipient address must be a valid raw Symbol address for the selected network.');
    $assert_session->pageTextNotContains('Mosaic ID must be 16 hex characters.');
    $assert_session->pageTextNotContains('Amount must be a positive integer.');

    $records = \Drupal::service('symbol_atomic_swap.offer_repository')->search([
      'q' => 'Distinct leg submit offer',
    ]);
    $this->assertCount(1, $records);
    $this->assertSame('swap-testnet-000001', $records[0]['correlation_id']);
    $this->assertSame('97E42C98FF3E5D0DD4BEB7234628DFE658402EDAD7A2CF5190451F7EFFA5B79D', $records[0]['leg1_signer_public_key']);
    $this->assertSame('', $records[0]['leg1_recipient_address']);
    $this->assertSame('72C0212E67A08BCE', $records[0]['leg1_mosaic_id']);
    $this->assertSame('100000000', $records[0]['leg1_amount']);
    $this->assertSame('', $records[0]['leg2_signer_public_key']);
    $this->assertSame('TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', $records[0]['leg2_recipient_address']);
    $this->assertSame('72C0212E67A08BCF', $records[0]['leg2_mosaic_id']);
    $this->assertSame('20000', $records[0]['leg2_amount']);
  }

  /**
   * Creating an offer must reject non-transferable mosaics before chain failure.
   */
  public function testCreateOfferRejectsNonTransferableMosaic(): void {
    $this->installAccountPublicKeyResolverStub();
    $overrides = \Drupal::state()->get('symbol_atomic_swap.mosaic_metadata_test_overrides');
    $overrides['testnet']['72C0212E67A08BCF']['transferable'] = FALSE;
    \Drupal::state()->set('symbol_atomic_swap.mosaic_metadata_test_overrides', $overrides);

    $creator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'create symbol atomic swap offers',
    ]);
    $this->verifySymbolAccount($creator);
    $this->drupalLogin($creator);

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $this->submitForm([
      'label' => 'Non-transferable mosaic offer',
      'maker_pays[mosaic_id]' => '72C0212E67A08BCF',
      'maker_pays[amount]' => '100',
      'maker_wants[mosaic_id]' => '72C0212E67A08BCE',
      'maker_wants[amount]' => '200',
    ], 'Create settlement');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Mosaic is not transferable and cannot be used in an atomic settlement.');
    $records = \Drupal::service('symbol_atomic_swap.offer_repository')->search([
      'q' => 'Non-transferable mosaic offer',
    ]);
    $this->assertCount(0, $records);
  }

  /**
   * Creating an offer requires a verified Symbol account.
   */
  public function testCreateOfferWarnsWhenMySymbolAccountIsNotVerified(): void {
    $creator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'create symbol atomic swap offers',
    ]);
    $this->drupalLogin($creator);

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Create Atomic Settlement requires a verified Symbol address in My Symbol Account.');
    $assert_session->linkExists('Open My Symbol Account');
    $assert_session->linkByHrefExists('/symbol-atomic-swap/account');
    $assert_session->pageTextContains('Register and verify My Symbol Account before creating an atomic settlement.');
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
    $this->verifySymbolAccount(
      $operator,
      'testnet',
      'TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI',
      'D82CF80BDA16BE82EB8ED09995DC3CC5DA56E22D4B75E9B9F44B3FA51543AC16',
    );
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-accept-address',
      'label' => 'Accept address offer',
      'state' => 'open',
      'leg1_mosaic_id' => '72C0212E67A08BCF',
      'leg1_amount' => '100',
      'leg2_mosaic_id' => '72C0212E67A08BCE',
      'leg2_amount' => '1000000',
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
    $assert_session->fieldNotExists('Taker recipient address');
    $assert_session->pageTextContains('TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI');
    $assert_session->pageTextContains('Uses the verified address from My Symbol Account.');
    $assert_session->fieldNotExists('Resolved taker public key');
    $assert_session->fieldExists('Transaction deadline hours');
    $assert_session->buttonExists('Accept and build QR');
    $assert_session->pageTextContains('Maker pays 1.00 of 72C0212E67A08BCF and wants 1.000000 of symbol.xym (72C0212E67A08BCE).');

    $this->submitForm([
      'transaction[deadline_hours]' => '6',
    ], 'Accept and build QR');

    $offer = $repository->find($id);
    $this->assertSame('6', (string) $offer['deadline_hours']);
    $this->assertSame('TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI', $offer['leg1_recipient_address']);
    $this->assertSame('D82CF80BDA16BE82EB8ED09995DC3CC5DA56E22D4B75E9B9F44B3FA51543AC16', $offer['leg2_signer_public_key']);
  }

  /**
   * Accepting an offer can request an aggregate bonded payload.
   */
  public function testAcceptOfferSupportsAggregateBondedSettings(): void {
    $this->installAccountPublicKeyResolverStub();
    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $this->verifySymbolAccount(
      $operator,
      'testnet',
      'TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI',
      'D82CF80BDA16BE82EB8ED09995DC3CC5DA56E22D4B75E9B9F44B3FA51543AC16',
    );
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-accept-bonded',
      'label' => 'Accept bonded offer',
      'state' => 'open',
      'intent_hash' => NULL,
      'unsigned_payload' => NULL,
      'qr_payload' => NULL,
      'transaction_hash' => NULL,
      'uid' => (int) $operator->id(),
    ]));

    $this->drupalLogin($operator);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/accept');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Aggregate transaction type');
    $assert_session->fieldExists('transaction[aggregate_type]');
    $assert_session->fieldNotExists('Hash lock mosaic ID');
    $assert_session->fieldNotExists('Hash lock amount');
    $assert_session->fieldNotExists('Hash lock duration blocks');
    $assert_session->pageTextContains('Hash lock mosaic ID');
    $assert_session->pageTextContains('72C0212E67A08BCE');
    $assert_session->pageTextContains('Hash lock amount');
    $assert_session->pageTextContains('10000000');
    $assert_session->pageTextContains('Hash lock duration blocks');
    $assert_session->pageTextContains('5760');
    $assert_session->pageTextContains('Aggregate bonded allows 1 to 48 hours.');
    $assert_session->pageTextContains('Maximum 5760 blocks, approximately 48 hours on Symbol.');

    $this->submitForm([
      'transaction[aggregate_type]' => 'aggregate_bonded',
      'transaction[deadline_hours]' => '48',
    ], 'Accept and build QR');

    $offer = $repository->find($id);
    $this->assertSame('48', (string) $offer['deadline_hours']);
    $this->assertSame('TDJF6EAS3P6HNKO4LTPK7PIFGEGZA33LG5FLLAI', $offer['leg1_recipient_address']);
    $this->assertSame('D82CF80BDA16BE82EB8ED09995DC3CC5DA56E22D4B75E9B9F44B3FA51543AC16', $offer['leg2_signer_public_key']);
  }

  /**
   * Correlation IDs are automatically numbered per network.
   */
  public function testCreateOfferGeneratesNextNetworkCorrelationId(): void {
    $this->installAccountPublicKeyResolverStub();
    $creator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'create symbol atomic swap offers',
    ]);
    $this->verifySymbolAccount($creator);
    $this->drupalLogin($creator);

    \Drupal::service('symbol_atomic_swap.offer_repository')->insert($this->offerValues([
      'uuid' => 'offer-duplicate-correlation',
      'label' => 'Existing correlation offer',
      'network' => 'testnet',
      'correlation_id' => 'swap-testnet-000001',
      'uid' => (int) $creator->id(),
    ]));

    $this->drupalGet('/symbol-atomic-swap/offers/add');
    $this->submitForm([
      'label' => 'Auto correlation offer',
      'maker_pays[mosaic_id]' => '72C0212E67A08BCE',
      'maker_pays[amount]' => '100',
      'maker_wants[mosaic_id]' => '72C0212E67A08BCF',
      'maker_wants[amount]' => '200',
    ], 'Create settlement');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Atomic settlement was saved.');

    $records = \Drupal::service('symbol_atomic_swap.offer_repository')->search([
      'q' => 'Auto correlation offer',
    ]);
    $this->assertCount(1, $records);
    $this->assertSame('swap-testnet-000002', $records[0]['correlation_id']);
  }


  /**
   * Existing offers can be viewed and admin-only edit/delete routes render.
   */
  public function testExistingOfferRoutesRender(): void {
    $this->installAccountPublicKeyResolverStub();
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
      'Atomic settlement transaction was confirmed but is not finalized yet.',
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
    $assert_session->pageTextContains('Signer address');
    $assert_session->pageTextContains('symbol.xym (72C0212E67A08BCE)');
    $assert_session->pageTextContains('0.000100');
    $assert_session->pageTextContains('0.000200');
    $assert_session->pageTextContains('Projection');
    $assert_session->pageTextContains('Manual sync allowed');
    $assert_session->pageTextContains('Automatic sync eligible');
    $assert_session->pageTextContains(str_repeat('C', 64));
    $assert_session->pageTextContains('Intent hash');
    $assert_session->pageTextContains('Root transaction hash');
    $assert_session->pageTextContains('Atomic settlement transaction was confirmed but is not finalized yet.');
    $assert_session->pageTextContains('status / unread');
    $assert_session->pageTextContains('QR payload');
    $assert_session->pageTextContains('QR URL');
    $assert_session->pageTextContains('"type": "symbol-aggregate-complete"');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->pageTextContains('Public settlement JSON');

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
    $assert_session->fieldValueEquals('Settlement label', 'Test offer');
    $assert_session->buttonExists('Save settlement');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/delete');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Delete Test offer?');
  }

  /**
   * Aggregate bonded QR pages explain the partial announcement sequence.
   */
  public function testAggregateBondedQrPayloadShowsPartialAnnouncementSteps(): void {
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $qr_payload = [
      'type' => 'symbol-aggregate-bonded',
      'network' => 'testnet',
      'unsignedPayload' => 'BEEF',
      'deadline' => '123',
      'requiredCosigners' => [
        str_repeat('A', 64),
        str_repeat('B', 64),
      ],
      'callback' => NULL,
      'intentHash' => str_repeat('C', 64),
      'hashLock' => [
        'mosaicId' => '72C0212E67A08BCE',
        'amount' => '10000000',
        'duration' => 5760,
      ],
    ];
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-bonded-steps',
      'label' => 'Bonded steps offer',
      'intent_hash' => str_repeat('C', 64),
      'unsigned_payload' => 'BEEF',
      'qr_payload' => json_encode($qr_payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ]));

    $admin = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'administer symbol atomic swap offers',
    ]);
    $this->drupalLogin($admin);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Aggregate bonded partial announcement steps');
    $assert_session->pageTextContains('This is not an aggregate complete transaction.');
    $assert_session->pageTextContains('Required order');
    $assert_session->pageTextContains('POST /v1/hash-lock/build');
    $assert_session->pageTextContains('POST /v1/hash-lock/announce');
    $assert_session->pageTextContains('POST /v1/transactions/announce-partial');
    $assert_session->pageTextContains('Hash lock signer');
    $assert_session->pageTextContains('Taker initiates this aggregate bonded transaction from the accept page and pays the 10 XYM hash lock.');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/qr-payload/' . str_repeat('C', 64));
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Aggregate type');
    $assert_session->pageTextContains('aggregate bonded');
    $assert_session->pageTextContains('Taker initiates this aggregate bonded transaction from the accept page and pays the 10 XYM hash lock.');
    $assert_session->pageTextContains('"intentHash": "' . str_repeat('C', 64) . '"');
    $assert_session->pageTextContains('"signerPublicKey": "' . str_repeat('B', 64) . '"');

    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $this->drupalLogin($operator);

    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Bonded steps offer');
    $assert_session->linkExists('Sign with SSS');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->linkNotExists('Submit aggregate signer JSON');
    $assert_session->linkNotExists('Submit cosignature JSON');
    $assert_session->linkNotExists('Cosign with SSS');
    $assert_session->linkNotExists('Assemble signed payload');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Sign with SSS');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->linkNotExists('Submit aggregate signer JSON');
    $assert_session->linkNotExists('Submit cosignature JSON');
    $assert_session->linkNotExists('Cosign with SSS');
    $assert_session->linkNotExists('Assemble signed payload');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/sign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Aggregate bonded is initiated by the taker.');
    $assert_session->pageTextContains('Required aggregate signer account');
    $assert_session->pageTextContains('TCNAOT3ZKSU45DVFCV3RHMTWHDKL4VS3LG33ELY');
    $assert_session->pageTextContains(str_repeat('B', 64));

    $repository->update($id, [
      'state' => 'root_signed',
      'root_signed_payload' => 'ABCD',
      'root_transaction_hash' => str_repeat('D', 64),
    ]);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Sign hash lock and announce partial');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Sign hash lock and announce partial');
    $this->drupalLogin($admin);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/bonded-partial-announce');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('wait for hash lock confirmation');
    $assert_session->pageTextContains('Hash lock signer public key');
    $assert_session->buttonExists('Sign hash lock and announce partial');

    $repository->update($id, [
      'state' => 'partial_announced',
      'projection_state' => 'partial_announced',
      'transaction_hash' => str_repeat('D', 64),
    ]);
    $this->drupalLogin($operator);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Cosign and announce partial with SSS');
    $assert_session->linkNotExists('Submit signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/cosign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Root signed payload sent to SSS');
    $assert_session->buttonExists('Cosign and announce partial with SSS');
    $this->submitForm([
      'payload' => json_encode([
        'parentHash' => str_repeat('D', 64),
        'signerPublicKey' => str_repeat('B', 64),
        'signature' => str_repeat('E', 128),
      ], JSON_THROW_ON_ERROR),
    ], 'Announce aggregate bonded cosignature');
    $assert_session->pageTextContains('SSS cosignature must be created by the non-root signer public key');
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
    $assert_session->pageTextContains('Required aggregate signer account');
    $assert_session->pageTextContains('TC4JSF33PUM667PHTJPK5X5IDGGTMXLG2ZHCPPQ');
    $assert_session->pageTextContains('Root signed payload must be signed by this aggregate signer account.');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-aggregate-signer-json');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Aggregate signer JSON');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/assemble-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Root signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/cosign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Cosignature JSON');
    $assert_session->pageTextContains('SSS must be set to the non-root signer account before cosigning.');

    $this->drupalLogin($operator);

    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Sign with SSS');
    $assert_session->linkNotExists('Cosign with SSS');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->linkNotExists('Submit aggregate signer JSON');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Sign with SSS');
    $assert_session->linkNotExists('Cosign with SSS');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->linkNotExists('Submit aggregate signer JSON');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Signed payload');
    $assert_session->buttonExists('Verify signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/sign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Unsigned payload sent to SSS');
    $assert_session->fieldExists('Signed payload');
    $assert_session->pageTextContains('Required aggregate signer account');
    $assert_session->pageTextContains('TC4JSF33PUM667PHTJPK5X5IDGGTMXLG2ZHCPPQ');
    $assert_session->pageTextContains('Root signed payload must be signed by this aggregate signer account.');
    $assert_session->buttonExists('Sign unsigned payload with SSS');
    $assert_session->buttonExists('Verify SSS root signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-aggregate-signer-json');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Aggregate signer JSON');
    $assert_session->buttonExists('Build and verify root signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/cosign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Unsigned payload sent to SSS');
    $assert_session->fieldExists('Parent hash fallback');
    $assert_session->fieldExists('Cosignature JSON');
    $assert_session->pageTextContains('SSS must be set to the non-root signer account before cosigning.');
    $assert_session->buttonExists('Cosign unsigned payload with SSS');
    $assert_session->buttonExists('Verify and store SSS cosignature');

    $repository->update($id, [
      'qr_payload' => json_encode([
        'type' => 'symbol-aggregate-complete',
        'requiredCosigners' => [str_repeat('B', 64), str_repeat('A', 64)],
      ], JSON_THROW_ON_ERROR),
    ]);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/sign-with-sss');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('TCNAOT3ZKSU45DVFCV3RHMTWHDKL4VS3LG33ELY');
    $assert_session->pageTextContains(str_repeat('B', 64));

    $this->verifySymbolAccount($operator, 'testnet', 'TC4JSF33PUM667PHTJPK5X5IDGGTMXLG2ZHCPPQ', str_repeat('A', 64));
    $repository->update($id, [
      'state' => 'root_signed',
      'root_signed_payload' => 'ABCD',
      'root_transaction_hash' => str_repeat('E', 64),
    ]);
    $this->drupalLogin($operator);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Cosign with SSS');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Cosign with SSS');
    $assert_session->linkNotExists('Assemble signed payload');

    \Drupal::service('symbol_atomic_swap.offer_cosignature_repository')->upsert([
      'offer_id' => $id,
      'parent_hash' => str_repeat('E', 64),
      'signer_public_key' => str_repeat('A', 64),
      'signature' => str_repeat('F', 128),
      'trusted_parent_hash' => TRUE,
      'uid' => (int) $operator->id(),
    ]);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Assemble signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Assemble signed payload');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/assemble-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('1 cosignature(s) will be attached.');

    $taker_operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $this->verifySymbolAccount($taker_operator, 'testnet', 'TCNAOT3ZKSU45DVFCV3RHMTWHDKL4VS3LG33ELY', str_repeat('B', 64));
    $this->drupalLogin($taker_operator);
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkNotExists('Cosign with SSS');
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkNotExists('Cosign with SSS');

    $this->drupalLogin($operator);
    $repository->markSigned($id, str_repeat('D', 64));
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->linkNotExists('Sign with SSS');
    $assert_session->linkNotExists('Cosign with SSS');
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
      'Atomic settlement transaction failed on-chain.',
    );

    $this->drupalLogin($operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/notifications', ['query' => ['unread' => '1']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Unread notifications');
    $assert_session->pageTextContains('Atomic settlement transaction failed on-chain.');
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
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/bonded-partial-announce');
    $assert_session->statusCodeEquals(200);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/announce');
    $assert_session->statusCodeEquals(403);

    $this->drupalLogin($owner);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Owner scoped offer');
    $assert_session->pageTextContains('Transaction hash');
    $assert_session->pageTextContains(str_repeat('D', 64));
    $assert_session->pageTextNotContains('Intent hash');
    $assert_session->pageTextNotContains('Root transaction hash');
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
    \Drupal::state()->set('symbol_atomic_swap.mosaic_metadata_test_overrides', [
      'testnet' => [
        '72C0212E67A08BCE' => [
          'divisibility' => 6,
          'aliases' => ['symbol.xym'],
        ],
        '72C0212E67A08BCF' => [
          'divisibility' => 2,
          'aliases' => [],
        ],
      ],
    ]);
  }

  private function verifySymbolAccount(
    $account,
    string $network = 'testnet',
    string $address = 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
    string $public_key = '97E42C98FF3E5D0DD4BEB7234628DFE658402EDAD7A2CF5190451F7EFFA5B79D',
  ): void {
    $account->set('field_symbol_network', $network);
    $account->set('field_symbol_address', $address);
    $account->set('field_symbol_public_key', $public_key);
    $account->set('field_symbol_address_verified', TRUE);
    $account->set('field_symbol_address_verified_at', 1700000000);
    $account->set('field_symbol_verification_method', 'on_chain_transfer');
    $account->set('field_symbol_challenge_hash', str_repeat('A', 64));
    $account->save();
  }

}
