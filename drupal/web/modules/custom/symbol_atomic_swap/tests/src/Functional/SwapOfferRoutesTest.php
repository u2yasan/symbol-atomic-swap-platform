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
    $assert_session->fieldExists('Signer public key');
    $assert_session->buttonExists('Create and build QR');
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
    $assert_session->pageTextContains('Transfer legs');
    $assert_session->pageTextContains('Projection');
    $assert_session->pageTextContains(str_repeat('C', 64));
    $assert_session->pageTextContains('Swap transaction was confirmed but is not finalized yet.');
    $assert_session->pageTextContains('QR payload');
    $assert_session->pageTextContains('"type": "symbol-aggregate-complete"');
    $assert_session->linkNotExists('Submit signed payload');
    $assert_session->pageTextContains('Public offer JSON');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/edit');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldValueEquals('Offer label', 'Test offer');
    $assert_session->buttonExists('Save and rebuild QR');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/delete');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Delete Test offer?');
  }

  /**
   * Signed payload and announce routes require operation permission.
   */
  public function testOfferOperationRoutesRenderForOperators(): void {
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
      'uid' => 1,
      'created' => 1700000000,
      'changed' => 1700000000,
    ]);

    $viewer = $this->drupalCreateUser(['view symbol atomic swap offers']);
    $this->drupalLogin($viewer);
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $this->assertSession()->statusCodeEquals(403);

    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $this->drupalLogin($operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers');
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Submit signed payload');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id);
    $assert_session->statusCodeEquals(200);
    $assert_session->linkExists('Submit signed payload');
    $assert_session->linkNotExists('Announce transaction');

    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('Signed payload');
    $assert_session->buttonExists('Verify signed payload');

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
   * Signed payload form exposes normalization and blocks terminal offers.
   */
  public function testSignedPayloadFormBlocksTerminalOffers(): void {
    $repository = \Drupal::service('symbol_atomic_swap.offer_repository');
    $id = $repository->insert($this->offerValues([
      'uuid' => 'offer-terminal-payload',
      'label' => 'Terminal payload offer',
      'state' => 'finalized',
      'transaction_hash' => str_repeat('D', 64),
    ]));

    $operator = $this->drupalCreateUser([
      'view symbol atomic swap offers',
      'operate symbol atomic swap offers',
    ]);
    $this->drupalLogin($operator);

    $assert_session = $this->assertSession();
    $this->drupalGet('/symbol-atomic-swap/offers/' . $id . '/submit-signed-payload');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Offer state');
    $assert_session->pageTextContains('Maximum accepted normalized size');
    $assert_session->buttonExists('Verify signed payload')->hasAttribute('disabled');
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
      'correlation_id' => 'swap-test-functional',
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

}
