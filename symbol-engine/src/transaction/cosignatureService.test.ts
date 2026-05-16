import assert from 'node:assert/strict';
import test from 'node:test';
import { Hash256, PrivateKey, utils } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { buildAggregateBonded } from '../aggregate/aggregateBondedBuilder.js';
import type { BlockchainEvent } from '../dto/events.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { SwapIntentRecord } from '../repository/types.js';
import { announceAggregateBondedCosignature } from './cosignatureService.js';

type SignedDetachedCosignature = {
  parentHash: string;
  signerPublicKey: string;
  signature: string;
  version: string;
};

function makePartialAnnouncedIntent(): { intent: SwapIntentRecord; cosignature: SignedDetachedCosignature } {
  const facade = new SymbolFacade('testnet');
  const aggregateSigner = facade.createAccount(PrivateKey.random());
  const cosigner = facade.createAccount(PrivateKey.random());
  const transactionHash = 'D'.repeat(64);
  const detached = cosigner.cosignTransactionHash(new Hash256(transactionHash), true) as unknown as {
    parentHash: { toString(): string };
    signerPublicKey: { toString(): string };
    signature: { toString(): string };
    version: bigint;
  };

  return {
    cosignature: {
      parentHash: detached.parentHash.toString().toUpperCase(),
      signerPublicKey: detached.signerPublicKey.toString().toUpperCase(),
      signature: detached.signature.toString().toUpperCase(),
      version: detached.version.toString(),
    },
    intent: {
      id: 'intent-1',
      correlationId: 'swap-0001',
      network: 'testnet',
      intentHash: 'A'.repeat(64),
      state: 'partial_announced',
      aggregateType: 'aggregate_bonded',
      unsignedPayload: 'AA',
      qrPayload: {
        type: 'symbol-aggregate-bonded',
        network: 'testnet',
        unsignedPayload: 'AA',
        deadline: '1',
        requiredCosigners: [
          aggregateSigner.publicKey.toString().toUpperCase(),
          cosigner.publicKey.toString().toUpperCase(),
        ],
        callback: null,
        intentHash: 'A'.repeat(64),
        hashLock: {
          mosaicId: '72C0212E67A08BCE',
          amount: '10000000',
          duration: 480,
        },
      },
      requiredCosigners: [
        aggregateSigner.publicKey.toString().toUpperCase(),
        cosigner.publicKey.toString().toUpperCase(),
      ],
      intent: {
        aggregateType: 'aggregate_bonded',
        network: 'testnet',
        deadlineHours: 2,
        correlationId: 'swap-0001',
        requiredCosigners: [
          aggregateSigner.publicKey.toString().toUpperCase(),
          cosigner.publicKey.toString().toUpperCase(),
        ],
        hashLock: {
          mosaicId: '72C0212E67A08BCE',
          amount: '10000000',
          duration: 480,
        },
        legs: [
          {
            signerPublicKey: aggregateSigner.publicKey.toString().toUpperCase(),
            recipientAddress: cosigner.address.toString(),
            mosaicId: '72C0212E67A08BCE',
            amount: '100',
          },
          {
            signerPublicKey: cosigner.publicKey.toString().toUpperCase(),
            recipientAddress: aggregateSigner.address.toString(),
            mosaicId: '72C0212E67A08BCE',
            amount: '200',
          },
        ],
      },
      signedPayload: 'ABCD',
      transactionHash,
      nodeResponse: null,
    },
  };
}

function makeDependencies(intent: SwapIntentRecord | null) {
  const events: BlockchainEvent[] = [];
  const marked: { partialCosigned?: unknown; failed?: unknown } = {};

  return {
    events,
    marked,
    dependencies: {
      nodeUrl: 'https://node.example.test',
      swapIntents: {
        findByIntentHash: async () => intent,
        markPartialCosigned: async (_intentHash: string, nodeResponse: unknown) => {
          marked.partialCosigned = nodeResponse;
          return { ...intent!, state: 'partial_cosigned', nodeResponse } as SwapIntentRecord;
        },
        markFailed: async (_intentHash: string, nodeResponse: unknown) => {
          marked.failed = nodeResponse;
          return intent ? { ...intent, state: 'failed', nodeResponse } as SwapIntentRecord : null;
        },
      } as unknown as SwapIntentRepository,
      events: {
        insert: async (event: BlockchainEvent) => {
          events.push(event);
        },
      } as unknown as EventRepository,
      projections: {
        find: async () => null,
        upsert: async (projection: unknown) => projection,
      } as unknown as ProjectionRepository,
    },
  };
}

function signPayload(unsignedPayload: string, privateKey: PrivateKey): string {
  const facade = new SymbolFacade('testnet');
  const account = facade.createAccount(privateKey);
  const transaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(unsignedPayload));
  const signedPayloadJson = SymbolTransactionFactory.attachSignature(transaction, account.signTransaction(transaction));
  const signedPayload = JSON.parse(signedPayloadJson) as { payload?: unknown };
  if (typeof signedPayload.payload !== 'string') {
    throw new Error('signed payload is missing');
  }
  return signedPayload.payload.toUpperCase();
}

test('announceAggregateBondedCosignature verifies and announces detached cosignature', async () => {
  const originalFetch = globalThis.fetch;
  const calls: Array<{ url: string; body: unknown }> = [];
  globalThis.fetch = (async (url: URL | string, init?: RequestInit) => {
    calls.push({ url: url.toString(), body: init?.body });
    return new Response(JSON.stringify({ message: 'accepted' }), { status: 202 });
  }) as typeof fetch;

  try {
    const { intent, cosignature } = makePartialAnnouncedIntent();
    const { dependencies, events, marked } = makeDependencies(intent);
    const result = await announceAggregateBondedCosignature({
      intentHash: intent.intentHash,
      ...cosignature,
    }, dependencies);

    assert.equal(result.accepted, true);
    assert.equal(result.transactionHash, intent.transactionHash);
    assert.equal(result.signerPublicKey, cosignature.signerPublicKey);
    assert.equal(calls[0]!.url, 'https://node.example.test/transactions/cosignature');
    assert.deepEqual(JSON.parse(calls[0]!.body as string), cosignature);
    assert.deepEqual(marked.partialCosigned, { status: 202, message: 'accepted' });
    assert.equal(events[0]!.eventType, 'CosignatureReceived');
    assert.equal(events[0]!.signerPublicKey, cosignature.signerPublicKey);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('announceAggregateBondedCosignature derives aggregate signer from signed payload', async () => {
  const facade = new SymbolFacade('testnet');
  const maker = facade.createAccount(PrivateKey.random());
  const taker = facade.createAccount(PrivateKey.random());
  const built = buildAggregateBonded({
    network: 'testnet',
    deadlineHours: 2,
    correlationId: 'swap-legacy-order',
    hashLock: {
      mosaicId: '72C0212E67A08BCE',
      amount: '10000000',
      duration: 480,
    },
    legs: [
      {
        signerPublicKey: maker.publicKey.toString(),
        recipientAddress: taker.address.toString(),
        mosaicId: '72C0212E67A08BCE',
        amount: '100',
      },
      {
        signerPublicKey: taker.publicKey.toString(),
        recipientAddress: maker.address.toString(),
        mosaicId: '72C0212E67A08BCE',
        amount: '200',
      },
    ],
  });
  const transactionHash = 'D'.repeat(64);
  const detached = maker.cosignTransactionHash(new Hash256(transactionHash), true) as unknown as {
    parentHash: { toString(): string };
    signerPublicKey: { toString(): string };
    signature: { toString(): string };
    version: bigint;
  };
  const intent: SwapIntentRecord = {
    id: built.intentId,
    correlationId: built.correlationId,
    network: built.network,
    intentHash: built.intentHash,
    state: 'partial_announced',
    aggregateType: 'aggregate_bonded',
    unsignedPayload: built.unsignedPayload,
    qrPayload: {
      ...built.qrPayload,
      requiredCosigners: [
        maker.publicKey.toString().toUpperCase(),
        taker.publicKey.toString().toUpperCase(),
      ],
    },
    requiredCosigners: [
      maker.publicKey.toString().toUpperCase(),
      taker.publicKey.toString().toUpperCase(),
    ],
    intent: {
      ...built.intent,
      requiredCosigners: [
        maker.publicKey.toString().toUpperCase(),
        taker.publicKey.toString().toUpperCase(),
      ],
    },
    signedPayload: signPayload(built.unsignedPayload, taker.keyPair.privateKey),
    transactionHash,
    nodeResponse: null,
  };
  const originalFetch = globalThis.fetch;
  globalThis.fetch = (async () => new Response(JSON.stringify({ message: 'accepted' }), { status: 202 })) as typeof fetch;

  try {
    const { dependencies } = makeDependencies(intent);
    const result = await announceAggregateBondedCosignature({
      intentHash: intent.intentHash,
      parentHash: detached.parentHash.toString().toUpperCase(),
      signerPublicKey: detached.signerPublicKey.toString().toUpperCase(),
      signature: detached.signature.toString().toUpperCase(),
      version: detached.version.toString(),
    }, dependencies);

    assert.equal(result.accepted, true);
    assert.equal(result.signerPublicKey, maker.publicKey.toString().toUpperCase());
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('announceAggregateBondedCosignature rejects unexpected signer', async () => {
  const { intent, cosignature } = makePartialAnnouncedIntent();
  const { dependencies } = makeDependencies(intent);

  await assert.rejects(
    () => announceAggregateBondedCosignature({
      intentHash: intent.intentHash,
      ...cosignature,
      signerPublicKey: 'E'.repeat(64),
    }, dependencies),
    /unexpected cosignature signer/,
  );
});

test('announceAggregateBondedCosignature rejects signature for wrong parent hash', async () => {
  const { intent, cosignature } = makePartialAnnouncedIntent();
  const { dependencies } = makeDependencies(intent);

  await assert.rejects(
    () => announceAggregateBondedCosignature({
      intentHash: intent.intentHash,
      ...cosignature,
      parentHash: 'F'.repeat(64),
    }, dependencies),
    /parent hash mismatch/,
  );
});

test('announceAggregateBondedCosignature marks failed when node rejects cosignature', async () => {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = (async () => new Response(JSON.stringify({ code: 'Failure' }), { status: 409 })) as typeof fetch;

  try {
    const { intent, cosignature } = makePartialAnnouncedIntent();
    const { dependencies, events, marked } = makeDependencies(intent);

    await assert.rejects(
      () => announceAggregateBondedCosignature({
        intentHash: intent.intentHash,
        ...cosignature,
      }, dependencies),
      /rejected cosignature/,
    );
    assert.deepEqual(marked.failed, { status: 409, code: 'Failure' });
    assert.equal(events[0]!.eventType, 'TransactionFailed');
  } finally {
    globalThis.fetch = originalFetch;
  }
});
