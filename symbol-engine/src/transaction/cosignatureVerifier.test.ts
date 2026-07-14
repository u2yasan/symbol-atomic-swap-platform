import assert from 'node:assert/strict';
import test from 'node:test';
import { Hash256, PrivateKey, utils } from 'symbol-sdk';
import { SymbolFacade } from 'symbol-sdk/symbol';
import { buildAggregateComplete } from '../aggregate/aggregateCompleteBuilder.js';
import { createSymbolFacadeForNetwork, deserializeTransactionForNetwork } from '../config/networkProfile.js';
import type { SwapIntentRecord } from '../repository/types.js';
import { verifyCosignature } from './cosignatureVerifier.js';

function aggregateHashFor(intent: SwapIntentRecord): string {
  const { facade } = createSymbolFacadeForNetwork(intent.network);
  const { transaction } = deserializeTransactionForNetwork(
    utils.hexToUint8(intent.unsignedPayload),
    intent.network,
  );
  return facade.hashTransaction(transaction).toString().toUpperCase();
}

function makeIntent(): { intent: SwapIntentRecord; cosignerPrivateKey: PrivateKey } {
  const facade = new SymbolFacade('testnet');
  const aggregateSigner = facade.createAccount(PrivateKey.random());
  const cosigner = facade.createAccount(PrivateKey.random());
  const built = buildAggregateComplete({
    network: 'testnet',
    deadlineHours: 2,
    correlationId: 'swap-0001',
    legs: [
      {
        signerPublicKey: aggregateSigner.publicKey.toString(),
        recipientAddress: cosigner.address.toString(),
        mosaicId: '72C0212E67A08BCE',
        amount: '100',
      },
      {
        signerPublicKey: cosigner.publicKey.toString(),
        recipientAddress: aggregateSigner.address.toString(),
        mosaicId: '72C0212E67A08BCE',
        amount: '200',
      },
    ],
  });

  return {
    cosignerPrivateKey: cosigner.keyPair.privateKey,
    intent: {
      id: built.intentId,
      correlationId: built.correlationId,
      network: built.network,
      intentHash: built.intentHash,
      state: 'created',
      aggregateType: 'aggregate_complete',
      unsignedPayload: built.unsignedPayload,
      qrPayload: built.qrPayload,
      requiredCosigners: built.requiredCosigners,
      intent: built.intent,
      signedPayload: null,
      transactionHash: null,
      nodeResponse: null,
    },
  };
}

test('verifyCosignature accepts a cosigner signature over the derived aggregate hash', () => {
  const { intent, cosignerPrivateKey } = makeIntent();
  const facade = new SymbolFacade('testnet');
  const cosigner = facade.createAccount(cosignerPrivateKey);
  const parentHash = aggregateHashFor(intent);
  const detached = cosigner.cosignTransactionHash(new Hash256(parentHash), true) as unknown;
  const signature = (detached as { signature: { toString(): string } }).signature.toString();

  const result = verifyCosignature({
    intentHash: intent.intentHash,
    parentHash,
    signerPublicKey: cosigner.publicKey.toString(),
    signature,
    version: { lower: 0, higher: 0 },
  }, intent);

  assert.equal(result.accepted, true);
  assert.equal(result.trustedParentHash, true);
});

test('verifyCosignature rejects a cosignature over an attacker-chosen parent hash', () => {
  const { intent, cosignerPrivateKey } = makeIntent();
  const facade = new SymbolFacade('testnet');
  const cosigner = facade.createAccount(cosignerPrivateKey);
  // A validly-signed cosignature, but over a parent hash that is not the real
  // aggregate transaction hash, must be rejected even though transactionHash is
  // not yet persisted on the intent.
  const forgedParentHash = 'A'.repeat(64);
  const detached = cosigner.cosignTransactionHash(new Hash256(forgedParentHash), true) as unknown;
  const signature = (detached as { signature: { toString(): string } }).signature.toString();

  const result = verifyCosignature({
    intentHash: intent.intentHash,
    parentHash: forgedParentHash,
    signerPublicKey: cosigner.publicKey.toString(),
    signature,
    version: { lower: 0, higher: 0 },
  }, intent);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'cosignature parent hash mismatch');
});

test('verifyCosignature rejects aggregate signer cosignature', () => {
  const { intent } = makeIntent();
  const result = verifyCosignature({
    intentHash: intent.intentHash,
    parentHash: 'A'.repeat(64),
    signerPublicKey: intent.requiredCosigners[0],
    signature: 'B'.repeat(128),
  }, intent);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'aggregate signer must submit root signed payload');
});
