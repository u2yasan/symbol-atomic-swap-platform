import assert from 'node:assert/strict';
import test from 'node:test';
import { PrivateKey, utils } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import {
  announceSignedSecretLock,
  announceSignedSecretProof,
  buildSecretLockTransaction,
  buildSecretProofTransaction,
  verifySignedSecretLockPayload,
  verifySignedSecretProofPayload,
} from './secretLockService.js';

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

function makeAccounts() {
  const facade = new SymbolFacade('testnet');
  return {
    signer: facade.createAccount(PrivateKey.random()),
    recipient: facade.createAccount(PrivateKey.random()),
  };
}

test('buildSecretLockTransaction returns unsigned secret lock payload', () => {
  const { signer, recipient } = makeAccounts();
  const result = buildSecretLockTransaction({
    network: 'testnet',
    signerPublicKey: signer.publicKey.toString(),
    recipientAddress: recipient.address.toString(),
    mosaicId: '72C0212E67A08BCE',
    amount: '100',
    duration: 480,
    secret: 'A'.repeat(64),
    hashAlgorithm: 'SHA3_256',
    deadlineHours: 2,
  });

  assert.equal(result.signerPublicKey, signer.publicKey.toString().toUpperCase());
  assert.equal(result.recipientAddress, recipient.address.toString());
  assert.match(result.unsignedPayload, /^[0-9A-F]+$/);
});

test('verifySignedSecretLockPayload accepts signed matching secret lock payload', () => {
  const { signer, recipient } = makeAccounts();
  const request = {
    network: 'testnet' as const,
    signerPublicKey: signer.publicKey.toString(),
    recipientAddress: recipient.address.toString(),
    mosaicId: '72C0212E67A08BCE',
    amount: '100',
    duration: 480,
    secret: 'A'.repeat(64),
    hashAlgorithm: 'SHA3_256' as const,
    deadlineHours: 2,
  };
  const built = buildSecretLockTransaction(request);
  const payload = attachSignature(built.unsignedPayload, signer.keyPair.privateKey);
  const result = verifySignedSecretLockPayload({
    ...request,
    payload,
  });

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'semantic_verification_passed');
  assert.match(result.transactionHash ?? '', /^[0-9A-F]{64}$/);
});

test('verifySignedSecretLockPayload rejects mismatched amount', () => {
  const { signer, recipient } = makeAccounts();
  const request = {
    network: 'testnet' as const,
    signerPublicKey: signer.publicKey.toString(),
    recipientAddress: recipient.address.toString(),
    mosaicId: '72C0212E67A08BCE',
    amount: '100',
    duration: 480,
    secret: 'A'.repeat(64),
    hashAlgorithm: 'SHA3_256' as const,
    deadlineHours: 2,
  };
  const built = buildSecretLockTransaction(request);
  const payload = attachSignature(built.unsignedPayload, signer.keyPair.privateKey);
  const result = verifySignedSecretLockPayload({
    ...request,
    amount: '101',
    payload,
  });

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'secret lock amount mismatch');
});

test('buildSecretProofTransaction derives secret from proof and returns unsigned payload', () => {
  const { signer, recipient } = makeAccounts();
  const result = buildSecretProofTransaction({
    network: 'testnet',
    signerPublicKey: signer.publicKey.toString(),
    recipientAddress: recipient.address.toString(),
    proof: 'B'.repeat(64),
    hashAlgorithm: 'SHA3_256',
    deadlineHours: 2,
  });

  assert.equal(result.signerPublicKey, signer.publicKey.toString().toUpperCase());
  assert.match(result.secret, /^[0-9A-F]{64}$/);
  assert.match(result.unsignedPayload, /^[0-9A-F]+$/);
});

test('verifySignedSecretProofPayload accepts signed matching secret proof payload', () => {
  const { signer, recipient } = makeAccounts();
  const request = {
    network: 'testnet' as const,
    signerPublicKey: signer.publicKey.toString(),
    recipientAddress: recipient.address.toString(),
    proof: 'B'.repeat(64),
    hashAlgorithm: 'SHA3_256' as const,
    deadlineHours: 2,
  };
  const built = buildSecretProofTransaction(request);
  const payload = attachSignature(built.unsignedPayload, signer.keyPair.privateKey);
  const result = verifySignedSecretProofPayload({
    ...request,
    payload,
  });

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'semantic_verification_passed');
  assert.equal(result.secret, built.secret);
});

test('verifySignedSecretProofPayload rejects wrong proof expectation', () => {
  const { signer, recipient } = makeAccounts();
  const request = {
    network: 'testnet' as const,
    signerPublicKey: signer.publicKey.toString(),
    recipientAddress: recipient.address.toString(),
    proof: 'B'.repeat(64),
    hashAlgorithm: 'SHA3_256' as const,
    deadlineHours: 2,
  };
  const built = buildSecretProofTransaction(request);
  const payload = attachSignature(built.unsignedPayload, signer.keyPair.privateKey);
  const result = verifySignedSecretProofPayload({
    ...request,
    proof: 'C'.repeat(64),
    payload,
  });

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'secret proof hash mismatch');
});

test('announceSignedSecretLock sends verified payload to node', async () => {
  const originalFetch = globalThis.fetch;
  const calls: Array<{ url: string; body: unknown }> = [];
  globalThis.fetch = (async (url: URL | string, init?: RequestInit) => {
    calls.push({ url: url.toString(), body: init?.body });
    return new Response(JSON.stringify({ message: 'accepted' }), { status: 202 });
  }) as typeof fetch;

  try {
    const { signer, recipient } = makeAccounts();
    const request = {
      network: 'testnet' as const,
      signerPublicKey: signer.publicKey.toString(),
      recipientAddress: recipient.address.toString(),
      mosaicId: '72C0212E67A08BCE',
      amount: '100',
      duration: 480,
      secret: 'A'.repeat(64),
      hashAlgorithm: 'SHA3_256' as const,
      deadlineHours: 2,
    };
    const built = buildSecretLockTransaction(request);
    const payload = attachSignature(built.unsignedPayload, signer.keyPair.privateKey);
    const result = await announceSignedSecretLock({
      ...request,
      payload,
    }, 'https://node.example.test');

    assert.equal(result.accepted, true);
    assert.equal(calls[0]!.url, 'https://node.example.test/transactions');
    assert.deepEqual(JSON.parse(calls[0]!.body as string), { payload });
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('announceSignedSecretProof rejects node failure', async () => {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = (async () => new Response(JSON.stringify({ code: 'Failure' }), { status: 409 })) as typeof fetch;

  try {
    const { signer, recipient } = makeAccounts();
    const request = {
      network: 'testnet' as const,
      signerPublicKey: signer.publicKey.toString(),
      recipientAddress: recipient.address.toString(),
      proof: 'B'.repeat(64),
      hashAlgorithm: 'SHA3_256' as const,
      deadlineHours: 2,
    };
    const built = buildSecretProofTransaction(request);
    const payload = attachSignature(built.unsignedPayload, signer.keyPair.privateKey);

    await assert.rejects(
      () => announceSignedSecretProof({
        ...request,
        payload,
      }, 'https://node.example.test'),
      /rejected secret transaction/,
    );
  } finally {
    globalThis.fetch = originalFetch;
  }
});
