import assert from 'node:assert/strict';
import test from 'node:test';
import { TransactionReconciler } from './transactionReconciler.js';

test('TransactionReconciler skips overlapping runs', async () => {
  const warnings: string[] = [];
  let release!: () => void;
  const blocker = new Promise<void>((resolve) => {
    release = resolve;
  });

  const reconciler = new TransactionReconciler({
    network: 'testnet',
    nodeUrl: 'http://node.local',
    intervalMs: 30000,
    logger: {
      warn: (message: string) => warnings.push(message),
      info: () => undefined,
      error: () => undefined,
    } as never,
    repositories: {
      swapIntents: {
        findReconciliationCandidates: async () => {
          await blocker;
          return [];
        },
      },
      projections: {
        findReconciliationCandidates: async () => [],
      },
      events: {},
    } as never,
    client: {
      getFinalizedHeight: async () => null,
    } as never,
  });

  reconciler.start();
  await reconciler.runOnce();
  release();
  await new Promise((resolve) => setTimeout(resolve, 0));
  reconciler.stop();

  assert.ok(warnings.includes('transaction reconciler skipped overlapping run'));
});
