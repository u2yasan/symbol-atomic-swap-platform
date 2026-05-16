import assert from 'node:assert/strict';
import test from 'node:test';
import { PrivateKey, utils } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { buildAggregateBonded } from '../aggregate/aggregateBondedBuilder.js';
import { buildAggregateComplete } from '../aggregate/aggregateCompleteBuilder.js';
import type { SwapIntentRecord } from '../repository/types.js';
import { verifyRootSignedPayload, verifySignedPayload } from './signedPayloadVerifier.js';

function makeIntent(): SwapIntentRecord {
  const built = buildAggregateComplete({
    network: 'testnet',
    deadlineHours: 2,
    correlationId: 'swap-0001',
    legs: [
      {
        signerPublicKey: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        recipientAddress: 'TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
        mosaicId: '72C0212E67A08BCE',
        amount: '100',
      },
      {
        signerPublicKey: 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
        recipientAddress: 'TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I',
        mosaicId: '72C0212E67A08BCE',
        amount: '200',
      },
    ],
  });

  return {
    id: built.intentId,
    correlationId: built.correlationId,
    network: built.network,
    intentHash: built.intentHash,
    state: 'created',
    aggregateType: 'aggregate_complete',
    unsignedPayload: built.unsignedPayload,
    qrPayload: built.qrPayload,
    requiredCosigners: built.requiredCosigners,
    intent: built.intent,
    signedPayload: null,
    transactionHash: null,
    nodeResponse: null,
  };
}

function makeBondedIntent(): { intent: SwapIntentRecord; initiatorPrivateKey: PrivateKey; counterpartyPrivateKey: PrivateKey } {
  const facade = new SymbolFacade('testnet');
  const initiator = facade.createAccount(PrivateKey.random());
  const counterparty = facade.createAccount(PrivateKey.random());
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

  return {
    initiatorPrivateKey: initiator.keyPair.privateKey,
    counterpartyPrivateKey: counterparty.keyPair.privateKey,
    intent: {
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

test('verifySignedPayload rejects malformed hex', () => {
  const intent = makeIntent();
  const result = verifySignedPayload({
    payload: 'not-hex',
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, false);
  assert.match(result.reason, /payload must be hex/);
});

test('verifySignedPayload hides malformed transaction decoder details', () => {
  const intent = makeIntent();
  const result = verifySignedPayload({
    payload: 'AB',
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'signed payload verification failed');
});

test('verifySignedPayload rejects missing stored intent', () => {
  const intent = makeIntent();
  const result = verifySignedPayload({
    payload: intent.unsignedPayload,
    intentHash: intent.intentHash,
  }, null);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'swap intent not found');
});

test('verifySignedPayload rejects signer mismatch', () => {
  const intent = makeIntent();
  const result = verifySignedPayload({
    payload: intent.unsignedPayload,
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, false);
  assert.match(result.reason, /missing required signer/);
});

test('verifyRootSignedPayload accepts aggregate signer payload before cosignatures are attached', () => {
  const facade = new SymbolFacade('testnet');
  const maker = facade.createAccount(PrivateKey.random());
  const taker = facade.createAccount(PrivateKey.random());
  const built = buildAggregateComplete({
    network: 'testnet',
    deadlineHours: 2,
    correlationId: 'root-0001',
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
  const intent: SwapIntentRecord = {
    id: built.intentId,
    correlationId: built.correlationId,
    network: built.network,
    intentHash: built.intentHash,
    state: 'created',
    aggregateType: 'aggregate_complete',
    unsignedPayload: built.unsignedPayload,
    qrPayload: built.qrPayload,
    requiredCosigners: built.requiredCosigners,
    intent: built.intent,
    signedPayload: null,
    transactionHash: null,
    nodeResponse: null,
  };
  const payload = signPayload(intent.unsignedPayload, maker.keyPair.privateKey);

  const result = verifyRootSignedPayload({
    payload,
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'semantic_verification_passed');
  assert.match(result.transactionHash ?? '', /^[0-9A-F]{64}$/);
});

test('verifySignedPayload accepts initiator-signed aggregate bonded payload', () => {
  const { intent, initiatorPrivateKey } = makeBondedIntent();
  const payload = signPayload(intent.unsignedPayload, initiatorPrivateKey);
  const result = verifySignedPayload({
    payload,
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'semantic_verification_passed');
  assert.match(result.transactionHash ?? '', /^[0-9A-F]{64}$/);
});

test('verifyRootSignedPayload accepts non-first aggregate bonded cosigner as root signer', () => {
  const { intent, initiatorPrivateKey } = makeBondedIntent();
  intent.requiredCosigners = [
    intent.requiredCosigners[1]!,
    intent.requiredCosigners[0]!,
  ];
  const payload = signPayload(intent.unsignedPayload, initiatorPrivateKey);
  const result = verifyRootSignedPayload({
    payload,
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'semantic_verification_passed');
  assert.match(result.transactionHash ?? '', /^[0-9A-F]{64}$/);
});

test('verifySignedPayload rejects unsigned aggregate bonded payload', () => {
  const { intent } = makeBondedIntent();
  const result = verifySignedPayload({
    payload: intent.unsignedPayload,
    intentHash: intent.intentHash,
  }, intent);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'transaction signature is missing');
});
