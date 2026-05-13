import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeFinalizedBlockHeight, normalizeSymbolWebSocketEvent } from './eventNormalizer.js';

const transactionHash = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
const signerPublicKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

test('normalizeSymbolWebSocketEvent maps confirmedAdded to TransactionConfirmed', () => {
  const event = normalizeSymbolWebSocketEvent({
    topic: 'confirmedAdded/TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
    network: 'testnet',
    observedAt: '2026-05-13T00:00:00.000Z',
    payload: {
      meta: { hash: transactionHash, height: '20' },
      transaction: { signerPublicKey },
    },
  });

  assert.deepEqual(event, {
    transactionHash,
    network: 'testnet',
    eventType: 'TransactionConfirmed',
    signerPublicKey,
    blockHeight: 20,
    observedAt: '2026-05-13T00:00:00.000Z',
  });
});

test('normalizeSymbolWebSocketEvent maps status to TransactionFailed', () => {
  const event = normalizeSymbolWebSocketEvent({
    topic: 'status/TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
    network: 'testnet',
    observedAt: '2026-05-13T00:00:00.000Z',
    payload: {
      hash: transactionHash,
      code: 'Failure_Core_Past_Deadline',
    },
  });

  assert.deepEqual(event, {
    transactionHash,
    network: 'testnet',
    eventType: 'TransactionFailed',
    statusCode: 'Failure_Core_Past_Deadline',
    observedAt: '2026-05-13T00:00:00.000Z',
  });
});

test('normalizeFinalizedBlockHeight extracts finalized height', () => {
  assert.equal(normalizeFinalizedBlockHeight({ finalizationPoint: { height: '123' } }), 123);
  assert.equal(normalizeFinalizedBlockHeight({ height: 456 }), 456);
});
