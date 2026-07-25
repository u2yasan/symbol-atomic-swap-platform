import assert from 'node:assert/strict';
import test from 'node:test';
import { dispatchBlockchainEvent, InvalidStateTransitionError } from './eventDispatcher.js';
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
      events: {
        apply: async (_event, _key, buildProjection) => buildProjection(null),
      },
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
    events: {
      apply: async () => existing,
    },
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
    events: {
      apply: async (_event, _key, buildProjection) => buildProjection(existing),
    },
  });

  assert.equal(result.state, 'finalized');
  assert.equal(result.updatedAt, 1778599320);
  assert.equal(result.finalizedHeight, 10);
});

test('dispatchBlockchainEvent cannot regress a concurrently finalized projection', async () => {
  let stored: TransactionProjection = {
    transactionHash,
    network: 'testnet',
    state: 'confirmed',
    lastEventKey: 'confirmed-key',
    updatedAt: 1778599260,
    blockHeight: 10,
  };
  let serial = Promise.resolve();
  const events = {
    apply: async (
      _event: unknown,
      _key: string,
      buildProjection: (existing: TransactionProjection | null) => TransactionProjection,
    ): Promise<TransactionProjection> => {
      const operation = serial.then(() => {
        const next = buildProjection(stored);
        stored = next;
        return next;
      });
      serial = operation.then(() => undefined, () => undefined);
      return operation;
    },
  };

  const finalized = dispatchBlockchainEvent({
    transactionHash,
    network: 'testnet',
    eventType: 'TransactionFinalized',
    blockHeight: 10,
    finalizedHeight: 10,
    observedAt: '2026-05-12T15:22:00.000Z',
  }, { events });
  const rolledBack = dispatchBlockchainEvent({
    transactionHash,
    network: 'testnet',
    eventType: 'TransactionRolledBack',
    blockHeight: 10,
    observedAt: '2026-05-12T15:22:01.000Z',
  }, { events });

  await finalized;
  await assert.rejects(rolledBack, InvalidStateTransitionError);
  assert.equal(stored.state, 'finalized');
});
