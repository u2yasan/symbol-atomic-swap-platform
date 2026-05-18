import assert from 'node:assert/strict';
import test from 'node:test';
import { dispatchBlockchainEvent, InvalidStateTransitionError } from './eventDispatcher.js';
import { DuplicateEventError } from '../repository/eventRepository.js';
import type { TransactionProjection } from '../repository/projectionRepository.js';

const transactionHash = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

test('dispatchBlockchainEvent rejects direct finalized transition', async () => {
  await assert.rejects(
    () => dispatchBlockchainEvent({
      transactionHash,
      network: 'testnet',
      eventType: 'TransactionFinalized',
      finalizedHeight: 10,
      observedAt: '2026-05-12T15:21:00.000Z',
    }, {
      events: { insert: async () => undefined },
      projections: { find: async () => null, upsert: async (projection: TransactionProjection) => projection },
    }),
    InvalidStateTransitionError,
  );
});

test('dispatchBlockchainEvent returns existing projection for duplicate event', async () => {
  const existing: TransactionProjection = {
    transactionHash,
    network: 'testnet',
    state: 'confirmed',
    lastEventKey: 'key',
    updatedAt: 1778599260,
    blockHeight: 10,
  };

  const result = await dispatchBlockchainEvent({
    transactionHash,
    network: 'testnet',
    eventType: 'TransactionConfirmed',
    blockHeight: 10,
    observedAt: '2026-05-12T15:21:00.000Z',
  }, {
    events: { insert: async () => { throw new DuplicateEventError('duplicate'); } },
    projections: { find: async () => existing, upsert: async (projection: TransactionProjection) => projection },
  });

  assert.equal(result, existing);
});

test('dispatchBlockchainEvent moves confirmed projection to finalized', async () => {
  const existing: TransactionProjection = {
    transactionHash,
    network: 'testnet',
    state: 'confirmed',
    lastEventKey: 'confirmed-key',
    updatedAt: 1778599260,
    blockHeight: 10,
  };

  const result = await dispatchBlockchainEvent({
    transactionHash,
    network: 'testnet',
    eventType: 'TransactionFinalized',
    blockHeight: 10,
    finalizedHeight: 10,
    observedAt: '2026-05-12T15:22:00.000Z',
  }, {
    events: { insert: async () => undefined },
    projections: { find: async () => existing, upsert: async (projection: TransactionProjection) => projection },
  });

  assert.equal(result.state, 'finalized');
  assert.equal(result.updatedAt, 1778599320);
  assert.equal(result.finalizedHeight, 10);
});
