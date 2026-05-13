import assert from 'node:assert/strict';
import test from 'node:test';
import { buildAggregateComplete } from '../aggregate/aggregateCompleteBuilder.js';
import type { SwapIntentRecord } from '../repository/types.js';
import { verifySignedPayload } from './signedPayloadVerifier.js';

function makeIntent(): SwapIntentRecord {
  const built = buildAggregateComplete({
    network: 'testnet',
    deadlineHours: 2,
    correlationId: 'swap-0001',
    legs: [
      {
        signerPublicKey: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        recipientAddress: 'TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
        mosaicId: '72C0212E67A08BCE',
        amount: '100',
      },
      {
        signerPublicKey: 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
        recipientAddress: 'TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
        mosaicId: '72C0212E67A08BCE',
        amount: '200',
      },
    ],
  });

  return {
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
  };
}

test('verifySignedPayload rejects malformed hex', () => {
  const intent = makeIntent();
  const result = verifySignedPayload({
    payload: 'not-hex',
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, false);
  assert.match(result.reason, /payload must be hex/);
});

test('verifySignedPayload rejects missing stored intent', () => {
  const intent = makeIntent();
  const result = verifySignedPayload({
    payload: intent.unsignedPayload,
    intentHash: intent.intentHash,
  }, null);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'swap intent not found');
});

test('verifySignedPayload rejects signer mismatch', () => {
  const intent = makeIntent();
  const result = verifySignedPayload({
    payload: intent.unsignedPayload,
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, false);
  assert.match(result.reason, /missing required signer/);
});
