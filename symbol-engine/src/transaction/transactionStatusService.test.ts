import assert from 'node:assert/strict';
import test from 'node:test';
import { reconcileTransactionProjection, reconcileTransactionStatus } from './transactionStatusService.js';
import type { BlockchainEvent } from '../dto/events.js';
import type { ProjectionUpdate } from '../repository/eventRepository.js';
import type { TransactionProjection } from '../repository/projectionRepository.js';
import type { SwapIntentRecord } from '../repository/types.js';

const transactionHash = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

function repositories() {
  const projections = new Map<string, TransactionProjection>();
  const events = new Set<string>();
  const failedIntents: unknown[] = [];

  return {
    failedIntents,
    repositories: {
      swapIntents: {
        findByTransactionHash: async (): Promise<SwapIntentRecord | null> => null,
        findReconciliationCandidates: async () => [],
        markFailed: async (_intentHash: string, nodeResponse: unknown) => {
          failedIntents.push(nodeResponse);
          return null;
        },
      },
      events: {
        apply: async (
          event: BlockchainEvent,
          key: string,
          buildProjection: (existing: TransactionProjection | null) => ProjectionUpdate,
        ) => {
          const mapKey = `${event.network}:${event.transactionHash}`;
          const existing = projections.get(mapKey) ?? null;
          if (events.has(key)) {
            if (!existing) throw new Error('idempotency record exists without projection');
            return existing;
          }
          const projection = buildProjection(existing);
          events.add(key);
          projections.set(mapKey, projection);
          return projection;
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

test('reconcileTransactionStatus ignores successful transaction status and confirms by lookup', async () => {
  const context = repositories();
  const result = await reconcileTransactionStatus({
    candidate: { transactionHash, network: 'testnet' },
    finalizedHeight: null,
    repositories: context.repositories,
    client: {
      getTransactionStatus: async () => ({ found: true, transactionHash, code: 'Success' }),
      getConfirmedTransaction: async () => ({ found: true, transactionHash, blockHeight: 20 }),
      getUnconfirmedTransaction: async () => ({ found: false, transactionHash }),
      getFinalizedHeight: async () => null,
    },
    observedAt: '2026-05-13T00:00:00.000Z',
  });

  assert.equal(result, 'confirmed');
  assert.equal(context.failedIntents.length, 0);
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

test('reconcileTransactionProjection resolves intent by transaction hash before reconciling failure', async () => {
  const context = repositories();
  const intent = {
    id: 'intent-1',
    correlationId: 'swap-1',
    network: 'testnet' as const,
    intentHash: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    state: 'partial_cosigned' as const,
    aggregateType: 'aggregate_bonded' as const,
    unsignedPayload: 'AA',
    qrPayload: {
      type: 'symbol-aggregate-bonded' as const,
      network: 'testnet' as const,
      unsignedPayload: 'AA',
      deadline: '1',
      requiredCosigners: [],
      callback: null,
      intentHash: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
      hashLock: {
        mosaicId: '72C0212E67A08BCE',
        amount: '10000000',
        duration: 5760,
      },
    },
    requiredCosigners: [],
    intent: {
      network: 'testnet' as const,
      deadlineHours: 48,
      correlationId: 'swap-1',
      legs: [],
      aggregateType: 'aggregate_bonded' as const,
      requiredCosigners: [],
      hashLock: {
        mosaicId: '72C0212E67A08BCE',
        amount: '10000000',
        duration: 5760,
      },
    },
    signedPayload: 'AA',
    transactionHash,
    nodeResponse: null,
  };
  context.repositories.swapIntents.findByTransactionHash = async () => intent;

  const result = await reconcileTransactionProjection({
    transactionHash,
    network: 'testnet',
    repositories: context.repositories,
    client: {
      getTransactionStatus: async () => ({
        found: true,
        transactionHash,
        code: 'Failure_Mosaic_Non_Transferable',
      }),
      getConfirmedTransaction: async () => ({ found: false, transactionHash }),
      getUnconfirmedTransaction: async () => ({ found: false, transactionHash }),
      getFinalizedHeight: async () => 30,
    },
    observedAt: '2026-05-17T00:00:00.000Z',
  });

  assert.equal(result.result, 'failed');
  assert.deepEqual(context.failedIntents, [{ code: 'Failure_Mosaic_Non_Transferable' }]);
  assert.equal(result.projection?.state, 'failed');
});
