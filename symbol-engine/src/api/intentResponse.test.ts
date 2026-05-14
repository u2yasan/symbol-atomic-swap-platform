import assert from 'node:assert/strict';
import test from 'node:test';
import type { SwapIntentRecord } from '../repository/types.js';
import { intentResponse } from './intentResponse.js';

test('intentResponse omits signed payload and node response', () => {
  const response = intentResponse({
    id: 'intent-1',
    correlationId: 'swap-1',
    network: 'testnet',
    intentHash: 'A'.repeat(64),
    state: 'announced',
    aggregateType: 'aggregate_complete',
    unsignedPayload: 'B'.repeat(64),
    qrPayload: {
      type: 'symbol-aggregate-complete',
      network: 'testnet',
      unsignedPayload: 'B'.repeat(64),
      deadline: '1',
      requiredCosigners: [],
      callback: null,
      intentHash: 'A'.repeat(64),
    },
    requiredCosigners: [],
    intent: {
      network: 'testnet',
      deadlineHours: 1,
      correlationId: 'swap-1',
      aggregateType: 'aggregate_complete',
      requiredCosigners: [],
      legs: [],
    },
    signedPayload: 'C'.repeat(128),
    transactionHash: 'D'.repeat(64),
    nodeResponse: {
      status: 202,
      message: 'accepted',
    },
  } satisfies SwapIntentRecord);

  assert.equal('signedPayload' in response, false);
  assert.equal('nodeResponse' in response, false);
  assert.doesNotMatch(JSON.stringify(response), /CCCCCCCC/);
  assert.doesNotMatch(JSON.stringify(response), /accepted/);
});
