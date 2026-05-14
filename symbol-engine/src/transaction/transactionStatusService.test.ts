import assert from 'node:assert/strict';
import test from 'node:test';
import { reconcileTransactionStatus } from './transactionStatusService.js';
import type { TransactionProjection } from '../repository/projectionRepository.js';
import { DuplicateEventError } from '../repository/eventRepository.js';

const transactionHash = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

function repositories() {
  const projections = new Map<string, TransactionProjection>();
  const events = new Set<string>();
  const failedIntents: unknown[] = [];

  return {
    failedIntents,
    repositories: {
      swapIntents: {
        findReconciliationCandidates: async () => [],
        markFailed: async (_intentHash: string, nodeResponse: unknown) => {
          failedIntents.push(nodeResponse);
          return null;
        },
      },
      events: {
        insert: async (_event: unknown, key: string) => {
          if (events.has(key)) throw new DuplicateEventError('duplicate');
          events.add(key);
        },
      },
      projections: {
        find: async (network: string, hash: string) => projections.get(`${network}:${hash}`) ?? null,
        findReconciliationCandidates: async () => [],
        findConfirmedAtOrBelow: async () => [],
        upsert: async (projection: TransactionProjection) => {
          projections.set(`${projection.network}:${projection.transactionHash}`, projection);
          return projection;
        },
      },
    },
    projections,
  };
}

test('reconcileTransactionStatus maps confirmed REST hit to confirmed projection', async () => {
  const context = repositories();
  const result = await reconcileTransactionStatus({
    candidate: { transactionHash, network: 'testnet' },
    finalizedHeight: null,
    repositories: context.repositories,
    client: {
      getTransactionStatus: async () => ({ found: false, transactionHash }),
      getConfirmedTransaction: async () => ({ found: true, transactionHash, blockHeight: 20 }),
      getUnconfirmedTransaction: async () => ({ found: false, transactionHash }),
      getFinalizedHeight: async () => null,
    },
    observedAt: '2026-05-13T00:00:00.000Z',
  });

  assert.equal(result, 'confirmed');
  assert.equal(context.projections.get(`testnet:${transactionHash}`)?.state, 'confirmed');
});

test('reconcileTransactionStatus finalizes confirmed transaction below finalized height', async () => {
  const context = repositories();
  const result = await reconcileTransactionStatus({
    candidate: { transactionHash, network: 'testnet' },
    finalizedHeight: 20,
    repositories: context.repositories,
    client: {
      getTransactionStatus: async () => ({ found: false, transactionHash }),
      getConfirmedTransaction: async () => ({ found: true, transactionHash, blockHeight: 20 }),
      getUnconfirmedTransaction: async () => ({ found: false, transactionHash }),
      getFinalizedHeight: async () => 20,
    },
    observedAt: '2026-05-13T00:00:00.000Z',
  });

  assert.equal(result, 'finalized');
  assert.equal(context.projections.get(`testnet:${transactionHash}`)?.state, 'finalized');
});

test('reconcileTransactionStatus maps status failure to failed', async () => {
  const context = repositories();
  const result = await reconcileTransactionStatus({
    candidate: {
      transactionHash,
      network: 'testnet',
      intent: {
        id: 'intent-1',
        correlationId: 'swap-1',
        network: 'testnet',
        intentHash: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        state: 'announced',
        aggregateType: 'aggregate_complete',
        unsignedPayload: 'AA',
        qrPayload: {
          type: 'symbol-aggregate-complete',
          network: 'testnet',
          unsignedPayload: 'AA',
          deadline: '1',
          requiredCosigners: [],
          callback: null,
          intentHash: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        },
        requiredCosigners: [],
        intent: {
          network: 'testnet',
          deadlineHours: 1,
          correlationId: 'swap-1',
          legs: [],
          aggregateType: 'aggregate_complete',
          requiredCosigners: [],
        },
        signedPayload: 'AA',
        transactionHash,
        nodeResponse: null,
      },
    },
    finalizedHeight: null,
    repositories: context.repositories,
    client: {
      getTransactionStatus: async () => ({
        found: true,
        transactionHash,
        code: 'Failure_Core_Past_Deadline',
      }),
      getConfirmedTransaction: async () => ({ found: false, transactionHash }),
      getUnconfirmedTransaction: async () => ({ found: false, transactionHash }),
      getFinalizedHeight: async () => null,
    },
    observedAt: '2026-05-13T00:00:00.000Z',
  });

  assert.equal(result, 'failed');
  assert.deepEqual(context.failedIntents, [{ code: 'Failure_Core_Past_Deadline' }]);
  assert.equal(context.projections.get(`testnet:${transactionHash}`)?.state, 'failed');
});

test('reconcileTransactionStatus leaves REST 404 as missing', async () => {
  const context = repositories();
  const result = await reconcileTransactionStatus({
    candidate: { transactionHash, network: 'testnet' },
    finalizedHeight: null,
    repositories: context.repositories,
    client: {
      getTransactionStatus: async () => ({ found: false, transactionHash }),
      getConfirmedTransaction: async () => ({ found: false, transactionHash }),
      getUnconfirmedTransaction: async () => ({ found: false, transactionHash }),
      getFinalizedHeight: async () => null,
    },
    observedAt: '2026-05-13T00:00:00.000Z',
  });

  assert.equal(result, 'missing');
  assert.equal(context.projections.size, 0);
});
