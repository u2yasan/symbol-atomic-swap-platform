import assert from 'node:assert/strict';
import test from 'node:test';
import type { BlockchainEvent } from '../dto/events.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { SwapIntentRecord } from '../repository/types.js';
import { announcePartialAggregateBonded } from './partialAnnouncementService.js';

function makeSignedBondedIntent(): SwapIntentRecord {
  return {
    id: 'intent-1',
    correlationId: 'swap-0001',
    network: 'testnet',
    intentHash: 'A'.repeat(64),
    state: 'signed',
    aggregateType: 'aggregate_bonded',
    unsignedPayload: 'AA',
    qrPayload: {
      type: 'symbol-aggregate-bonded',
      network: 'testnet',
      unsignedPayload: 'AA',
      deadline: '1',
      requiredCosigners: ['B'.repeat(64), 'C'.repeat(64)],
      callback: null,
      intentHash: 'A'.repeat(64),
      hashLock: {
        mosaicId: '72C0212E67A08BCE',
        amount: '10000000',
        duration: 480,
      },
    },
    requiredCosigners: ['B'.repeat(64), 'C'.repeat(64)],
    intent: {
      aggregateType: 'aggregate_bonded',
      network: 'testnet',
      deadlineHours: 2,
      correlationId: 'swap-0001',
      requiredCosigners: ['B'.repeat(64), 'C'.repeat(64)],
      hashLock: {
        mosaicId: '72C0212E67A08BCE',
        amount: '10000000',
        duration: 480,
      },
      legs: [
        {
          signerPublicKey: 'B'.repeat(64),
          recipientAddress: 'TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
          mosaicId: '72C0212E67A08BCE',
          amount: '100',
        },
        {
          signerPublicKey: 'C'.repeat(64),
          recipientAddress: 'TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
          mosaicId: '72C0212E67A08BCE',
          amount: '200',
        },
      ],
    },
    signedPayload: 'ABCD',
    transactionHash: 'D'.repeat(64),
    nodeResponse: null,
  };
}

function makeDependencies(intent: SwapIntentRecord | null) {
  const events: BlockchainEvent[] = [];
  const marked: { partialAnnounced?: unknown; failed?: unknown } = {};

  return {
    events,
    marked,
    dependencies: {
      nodeUrl: 'https://node.example.test',
      swapIntents: {
        findByIntentHash: async () => intent,
        markPartialAnnounced: async (_intentHash: string, nodeResponse: unknown) => {
          marked.partialAnnounced = nodeResponse;
          return { ...intent!, state: 'partial_announced', nodeResponse } as SwapIntentRecord;
        },
        markFailed: async (_intentHash: string, nodeResponse: unknown) => {
          marked.failed = nodeResponse;
          return intent ? { ...intent, state: 'failed', nodeResponse } as SwapIntentRecord : null;
        },
      } as unknown as SwapIntentRepository,
      events: {
        apply: async (
          event: BlockchainEvent,
          _key: string,
          buildProjection: (existing: null) => unknown,
        ) => {
          events.push(event);
          return buildProjection(null);
        },
      } as unknown as EventRepository,
      projections: {
        find: async () => null,
        upsert: async (projection: unknown) => projection,
      } as unknown as ProjectionRepository,
    },
  };
}

test('announcePartialAggregateBonded sends signed bonded payload to partial endpoint', async () => {
  const originalFetch = globalThis.fetch;
  const calls: Array<{ url: string; body: unknown }> = [];
  globalThis.fetch = (async (url: URL | string, init?: RequestInit) => {
    calls.push({ url: url.toString(), body: init?.body });
    return new Response(JSON.stringify({ message: 'accepted' }), { status: 202 });
  }) as typeof fetch;

  try {
    const intent = makeSignedBondedIntent();
    const { dependencies, events, marked } = makeDependencies(intent);
    const result = await announcePartialAggregateBonded({ intentHash: intent.intentHash }, dependencies);

    assert.equal(result.accepted, true);
    assert.equal(result.transactionHash, intent.transactionHash);
    assert.equal(calls[0]!.url, 'https://node.example.test/transactions/partial');
    assert.deepEqual(JSON.parse(calls[0]!.body as string), { payload: intent.signedPayload });
    assert.deepEqual(marked.partialAnnounced, { status: 202, message: 'accepted' });
    assert.equal(events[0]!.eventType, 'PartialTransactionAdded');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('announcePartialAggregateBonded rejects aggregate complete intent', async () => {
  const intent = {
    ...makeSignedBondedIntent(),
    aggregateType: 'aggregate_complete',
  } as SwapIntentRecord;
  const { dependencies } = makeDependencies(intent);

  await assert.rejects(
    () => announcePartialAggregateBonded({ intentHash: intent.intentHash }, dependencies),
    /requires aggregate bonded intent/,
  );
});

test('announcePartialAggregateBonded marks failed when node rejects partial transaction', async () => {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = (async () => new Response(JSON.stringify({ code: 'Failure' }), { status: 409 })) as typeof fetch;

  try {
    const intent = makeSignedBondedIntent();
    const { dependencies, events, marked } = makeDependencies(intent);

    await assert.rejects(
      () => announcePartialAggregateBonded({ intentHash: intent.intentHash }, dependencies),
      /rejected partial transaction/,
    );
    assert.deepEqual(marked.failed, { status: 409, code: 'Failure' });
    assert.equal(events[0]!.eventType, 'TransactionFailed');
  } finally {
    globalThis.fetch = originalFetch;
  }
});
