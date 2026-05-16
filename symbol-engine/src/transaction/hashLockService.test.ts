import assert from 'node:assert/strict';
import test from 'node:test';
import { PrivateKey, utils } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { buildAggregateBonded } from '../aggregate/aggregateBondedBuilder.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { SwapIntentRecord } from '../repository/types.js';
import { verifySignedPayload } from './signedPayloadVerifier.js';
import { announceSignedHashLock, buildHashLockTransaction, verifySignedHashLockPayload } from './hashLockService.js';

function attachSignature(unsignedPayload: string, privateKey: PrivateKey): string {
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

function makeSignedBondedIntent(): { intent: SwapIntentRecord; lockPrivateKey: PrivateKey } {
  const facade = new SymbolFacade('testnet');
  const initiator = facade.createAccount(PrivateKey.random());
  const counterparty = facade.createAccount(PrivateKey.random());
  const lockSigner = facade.createAccount(PrivateKey.random());
  const built = buildAggregateBonded({
    network: 'testnet',
    deadlineHours: 2,
    correlationId: 'bonded-0001',
    hashLock: {
      mosaicId: '72C0212E67A08BCE',
      amount: '10000000',
      duration: 480,
    },
    legs: [
      {
        signerPublicKey: initiator.publicKey.toString(),
        recipientAddress: counterparty.address.toString(),
        mosaicId: '72C0212E67A08BCE',
        amount: '100',
      },
      {
        signerPublicKey: counterparty.publicKey.toString(),
        recipientAddress: initiator.address.toString(),
        mosaicId: '72C0212E67A08BCE',
        amount: '200',
      },
    ],
  });
  const signedPayload = attachSignature(built.unsignedPayload, counterparty.keyPair.privateKey);
  const verification = verifySignedPayload({
    payload: signedPayload,
    intentHash: built.intentHash,
  }, {
    id: built.intentId,
    correlationId: built.correlationId,
    network: built.network,
    intentHash: built.intentHash,
    state: 'created',
    aggregateType: 'aggregate_bonded',
    unsignedPayload: built.unsignedPayload,
    qrPayload: built.qrPayload,
    requiredCosigners: built.requiredCosigners,
    intent: built.intent,
    signedPayload: null,
    transactionHash: null,
    nodeResponse: null,
  });

  if (!verification.accepted || !verification.transactionHash) {
    throw new Error(verification.reason);
  }

  return {
    lockPrivateKey: lockSigner.keyPair.privateKey,
    intent: {
      id: built.intentId,
      correlationId: built.correlationId,
      network: built.network,
      intentHash: built.intentHash,
      state: 'signed',
      aggregateType: 'aggregate_bonded',
      unsignedPayload: built.unsignedPayload,
      qrPayload: built.qrPayload,
      requiredCosigners: built.requiredCosigners,
      intent: built.intent,
      signedPayload,
      transactionHash: verification.transactionHash,
      nodeResponse: null,
    },
  };
}

function repoReturning(intent: SwapIntentRecord | null): SwapIntentRepository {
  return {
    findByIntentHash: async () => intent,
  } as unknown as SwapIntentRepository;
}

test('buildHashLockTransaction returns unsigned hash lock tied to signed bonded aggregate hash', async () => {
  const { intent } = makeSignedBondedIntent();
  const facade = new SymbolFacade('testnet');
  const lockSigner = facade.createAccount(PrivateKey.random());
  const result = await buildHashLockTransaction({
    intentHash: intent.intentHash,
    signerPublicKey: lockSigner.publicKey.toString(),
    deadlineHours: 2,
  }, repoReturning(intent));

  assert.equal(result.intentHash, intent.intentHash);
  assert.equal(result.aggregateTransactionHash, intent.transactionHash);
  assert.equal(result.lockSignerPublicKey, lockSigner.publicKey.toString());
  assert.deepEqual(result.hashLock, intent.qrPayload.hashLock);
  assert.match(result.unsignedPayload, /^[0-9A-F]+$/);
});

test('verifySignedHashLockPayload accepts signed hash lock matching the bonded intent', async () => {
  const { intent, lockPrivateKey } = makeSignedBondedIntent();
  const facade = new SymbolFacade('testnet');
  const lockSigner = facade.createAccount(lockPrivateKey);
  const built = await buildHashLockTransaction({
    intentHash: intent.intentHash,
    signerPublicKey: lockSigner.publicKey.toString(),
    deadlineHours: 2,
  }, repoReturning(intent));
  const payload = attachSignature(built.unsignedPayload, lockPrivateKey);
  const result = verifySignedHashLockPayload({
    intentHash: intent.intentHash,
    payload,
  }, intent);

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'semantic_verification_passed');
  assert.match(result.transactionHash ?? '', /^[0-9A-F]{64}$/);
});

test('verifySignedHashLockPayload rejects unsigned hash lock', async () => {
  const { intent, lockPrivateKey } = makeSignedBondedIntent();
  const facade = new SymbolFacade('testnet');
  const lockSigner = facade.createAccount(lockPrivateKey);
  const built = await buildHashLockTransaction({
    intentHash: intent.intentHash,
    signerPublicKey: lockSigner.publicKey.toString(),
    deadlineHours: 2,
  }, repoReturning(intent));
  const result = verifySignedHashLockPayload({
    intentHash: intent.intentHash,
    payload: built.unsignedPayload,
  }, intent);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'hash lock signature is missing');
});

test('announceSignedHashLock waits for confirmed hash lock when requested', async () => {
  const { intent, lockPrivateKey } = makeSignedBondedIntent();
  const facade = new SymbolFacade('testnet');
  const lockSigner = facade.createAccount(lockPrivateKey);
  const built = await buildHashLockTransaction({
    intentHash: intent.intentHash,
    signerPublicKey: lockSigner.publicKey.toString(),
    deadlineHours: 2,
  }, repoReturning(intent));
  const payload = attachSignature(built.unsignedPayload, lockPrivateKey);
  const originalFetch = globalThis.fetch;
  const calls: string[] = [];
  globalThis.fetch = (async (url: URL | string) => {
    const value = url.toString();
    calls.push(value);
    if (value.endsWith('/transactions')) {
      return new Response(JSON.stringify({ message: 'accepted' }), { status: 202 });
    }
    if (value.includes('/transactionStatus/')) {
      return new Response(JSON.stringify({ message: 'missing' }), { status: 404 });
    }
    if (value.includes('/transactions/confirmed/')) {
      return new Response(JSON.stringify({ meta: { height: 123 } }), { status: 200 });
    }
    if (value.includes('/transactions/unconfirmed/')) {
      return new Response(JSON.stringify({ message: 'missing' }), { status: 404 });
    }
    return new Response(JSON.stringify({ message: 'unexpected' }), { status: 500 });
  }) as typeof fetch;

  try {
    const result = await announceSignedHashLock({
      intentHash: intent.intentHash,
      payload,
      waitForConfirmation: true,
      confirmationTimeoutMs: 1000,
      confirmationPollIntervalMs: 250,
    }, {
      nodeUrl: 'https://node.example.test',
      swapIntents: repoReturning(intent),
    });

    assert.equal(result.accepted, true);
    assert.equal(result.hashLockConfirmed, true);
    assert.ok(calls.some((call) => call.includes('/transactions/confirmed/')));
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('announceSignedHashLock skips reannounce when hash lock is already confirmed', async () => {
  const { intent, lockPrivateKey } = makeSignedBondedIntent();
  const facade = new SymbolFacade('testnet');
  const lockSigner = facade.createAccount(lockPrivateKey);
  const built = await buildHashLockTransaction({
    intentHash: intent.intentHash,
    signerPublicKey: lockSigner.publicKey.toString(),
    deadlineHours: 2,
  }, repoReturning(intent));
  const payload = attachSignature(built.unsignedPayload, lockPrivateKey);
  const originalFetch = globalThis.fetch;
  const calls: string[] = [];
  globalThis.fetch = (async (url: URL | string) => {
    const value = url.toString();
    calls.push(value);
    if (value.includes('/transactionStatus/')) {
      return new Response(JSON.stringify({ message: 'missing' }), { status: 404 });
    }
    if (value.includes('/transactions/confirmed/')) {
      return new Response(JSON.stringify({ meta: { height: 123 } }), { status: 200 });
    }
    return new Response(JSON.stringify({ message: 'unexpected announce' }), { status: 500 });
  }) as typeof fetch;

  try {
    const result = await announceSignedHashLock({
      intentHash: intent.intentHash,
      payload,
      waitForConfirmation: true,
      confirmationTimeoutMs: 1000,
      confirmationPollIntervalMs: 250,
    }, {
      nodeUrl: 'https://node.example.test',
      swapIntents: repoReturning(intent),
    });

    assert.equal(result.accepted, true);
    assert.equal(result.hashLockConfirmed, true);
    assert.equal(result.nodeResponse.message, 'hash lock already confirmed');
    assert.ok(!calls.some((call) => call.endsWith('/transactions')));
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('verifySignedHashLockPayload hides malformed transaction decoder details', () => {
  const { intent } = makeSignedBondedIntent();
  const result = verifySignedHashLockPayload({
    intentHash: intent.intentHash,
    payload: 'AB',
  }, intent);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'hash lock verification failed');
});

test('buildHashLockTransaction rejects future transaction deadlines above Symbol limit', async () => {
  const { intent } = makeSignedBondedIntent();
  await assert.rejects(() => buildHashLockTransaction({
    intentHash: intent.intentHash,
    signerPublicKey: intent.requiredCosigners[0]!,
    deadlineHours: 48,
  }, repoReturning(intent)), /less than or equal to 6/);
});

test('buildHashLockTransaction rejects unsigned bonded intent', async () => {
  const { intent } = makeSignedBondedIntent();
  await assert.rejects(() => buildHashLockTransaction({
    intentHash: intent.intentHash,
    signerPublicKey: intent.requiredCosigners[0]!,
    deadlineHours: 2,
  }, repoReturning({
    ...intent,
    state: 'created',
    signedPayload: null,
    transactionHash: null,
  })), /must be signed/);
});
