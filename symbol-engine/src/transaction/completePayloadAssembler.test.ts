import assert from 'node:assert/strict';
import test from 'node:test';
import { PrivateKey, utils } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { buildAggregateComplete } from '../aggregate/aggregateCompleteBuilder.js';
import type { SwapIntentRecord } from '../repository/types.js';
import { assembleCompleteSignedPayload } from './completePayloadAssembler.js';
import { buildRootSignedPayloadFromAggregateSignerSignature } from './rootSignedPayloadBuilder.js';

function makeFixture(): {
  intent: SwapIntentRecord;
  rootSignedPayload: string;
  aggregateSignerSignature: { parentHash: string; signerPublicKey: string; signature: string };
  cosignature: { parentHash: string; signerPublicKey: string; signature: string };
} {
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
  const transaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(built.unsignedPayload));
  const aggregateSignature = aggregateSigner.signTransaction(transaction);
  const signedPayload = JSON.parse(SymbolTransactionFactory.attachSignature(transaction, aggregateSignature)) as { payload: string };
  const signedTransaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(signedPayload.payload));
  const detached = cosigner.cosignTransaction(signedTransaction, true) as unknown as {
    parentHash: { toString(): string };
    signerPublicKey: { toString(): string };
    signature: { toString(): string };
  };

  return {
    rootSignedPayload: signedPayload.payload.toUpperCase(),
    aggregateSignerSignature: {
      parentHash: facade.hashTransaction(signedTransaction).toString().toUpperCase(),
      signerPublicKey: aggregateSigner.publicKey.toString().toUpperCase(),
      signature: aggregateSignature.toString().toUpperCase(),
    },
    cosignature: {
      parentHash: detached.parentHash.toString().toUpperCase(),
      signerPublicKey: detached.signerPublicKey.toString().toUpperCase(),
      signature: detached.signature.toString().toUpperCase(),
    },
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

test('assembleCompleteSignedPayload attaches cosignatures and verifies final payload', () => {
  const fixture = makeFixture();
  const result = assembleCompleteSignedPayload({
    intentHash: fixture.intent.intentHash,
    rootSignedPayload: fixture.rootSignedPayload,
    cosignatures: [fixture.cosignature],
  }, fixture.intent);

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'assembled_payload_verification_passed');
  assert.match(result.payload ?? '', /^[0-9A-F]+$/);
  assert.match(result.transactionHash ?? '', /^[0-9A-F]{64}$/);
});

test('buildRootSignedPayloadFromAggregateSignerSignature attaches aggregate signer signature', () => {
  const fixture = makeFixture();
  const result = buildRootSignedPayloadFromAggregateSignerSignature({
    intentHash: fixture.intent.intentHash,
    ...fixture.aggregateSignerSignature,
  }, fixture.intent);

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'root_signed_payload_build_passed');
  assert.equal(result.payload, fixture.rootSignedPayload);
  assert.equal(result.transactionHash, fixture.aggregateSignerSignature.parentHash);
});

test('buildRootSignedPayloadFromAggregateSignerSignature rejects detached cosignature as root signature', () => {
  const fixture = makeFixture();
  const result = buildRootSignedPayloadFromAggregateSignerSignature({
    intentHash: fixture.intent.intentHash,
    parentHash: fixture.aggregateSignerSignature.parentHash,
    signerPublicKey: fixture.intent.requiredCosigners[0],
    signature: fixture.cosignature.signature,
  }, fixture.intent);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'aggregate signer signature verification failed');
});

test('assembleCompleteSignedPayload rejects missing cosigner', () => {
  const fixture = makeFixture();
  const result = assembleCompleteSignedPayload({
    intentHash: fixture.intent.intentHash,
    rootSignedPayload: fixture.rootSignedPayload,
    cosignatures: [],
  }, fixture.intent);

  assert.equal(result.accepted, false);
  assert.match(result.reason, /missing required signer/);
});
