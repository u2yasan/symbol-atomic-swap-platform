import assert from 'node:assert/strict';
import test from 'node:test';
import { PrivateKey } from 'symbol-sdk';
import { createSymbolFacadeForNetwork } from '../config/networkProfile.js';
import { buildAggregateComplete } from './aggregateCompleteBuilder.js';

const validRequest = {
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
};

test('buildAggregateComplete returns unsigned payload and QR JSON', () => {
  const result = buildAggregateComplete(validRequest);

  assert.equal(result.network, 'testnet');
  assert.match(result.unsignedPayload, /^[0-9A-F]+$/);
  assert.equal(result.qrPayload.type, 'symbol-aggregate-complete');
  assert.equal(result.qrPayload.unsignedPayload, result.unsignedPayload);
  assert.deepEqual(result.requiredCosigners, [
    validRequest.legs[0]!.signerPublicKey,
    validRequest.legs[1]!.signerPublicKey,
  ]);
  assert.doesNotMatch(JSON.stringify(result), /privateKey|mnemonic|password/i);
});

test('buildAggregateComplete allows the second leg signer as aggregate signer', () => {
  const result = buildAggregateComplete({
    ...validRequest,
    aggregateSignerPublicKey: validRequest.legs[1]!.signerPublicKey,
  });

  assert.deepEqual(result.requiredCosigners, [
    validRequest.legs[1]!.signerPublicKey,
    validRequest.legs[0]!.signerPublicKey,
  ]);
  assert.deepEqual(result.qrPayload.requiredCosigners, result.requiredCosigners);
});

test('buildAggregateComplete rejects aggregate signer outside transfer legs', () => {
  assert.throws(() => buildAggregateComplete({
    ...validRequest,
    aggregateSignerPublicKey: 'C'.repeat(64),
  }), /aggregate signer public key must match one transfer leg signer/);
});

test('buildAggregateComplete rejects same signer', () => {
  assert.throws(() => buildAggregateComplete({
    ...validRequest,
    legs: [
      validRequest.legs[0]!,
      { ...validRequest.legs[1]!, signerPublicKey: validRequest.legs[0]!.signerPublicKey },
    ],
  }), /distinct signer public keys/);
});

test('buildAggregateComplete rejects deadlines above Symbol aggregate complete limit', () => {
  assert.throws(() => buildAggregateComplete({
    ...validRequest,
    deadlineHours: 7,
  }), /Number must be less than or equal to 6/);
});

test('buildAggregateComplete rejects invalid amount and mosaic id', () => {
  assert.throws(() => buildAggregateComplete({
    ...validRequest,
    legs: [
      { ...validRequest.legs[0]!, amount: '0' },
      validRequest.legs[1]!,
    ],
  }), /positive integer/);

  assert.throws(() => buildAggregateComplete({
    ...validRequest,
    legs: [
      { ...validRequest.legs[0]!, mosaicId: 'BAD' },
      validRequest.legs[1]!,
    ],
  }), /mosaic id/);
});

test('buildAggregateComplete supports a configured private network profile', () => {
  const originalProfiles = process.env.SYMBOL_NETWORK_PROFILES_JSON;
  process.env.SYMBOL_NETWORK_PROFILES_JSON = JSON.stringify([
    {
      key: 'private-alpha',
      label: 'Private Alpha',
      networkIdentifier: 168,
      addressPrefix: 'V',
      generationHashSeed: '1'.repeat(64),
      epochAdjustment: 1700000000,
      currencyMosaicId: '1234567890ABCDEF',
      currencyDivisibility: 6,
      enabledForSwap: true,
      allowInsecureTransport: true,
    },
  ]);

  try {
    const { facade, profile } = createSymbolFacadeForNetwork('private-alpha');
    const maker = facade.createAccount(PrivateKey.random());
    const taker = facade.createAccount(PrivateKey.random());
    const result = buildAggregateComplete({
      network: 'private-alpha',
      deadlineHours: 2,
      correlationId: 'swap-private-0001',
      legs: [
        {
          signerPublicKey: maker.publicKey.toString(),
          recipientAddress: taker.address.toString(),
          mosaicId: '1234567890ABCDEF',
          amount: '100',
        },
        {
          signerPublicKey: taker.publicKey.toString(),
          recipientAddress: maker.address.toString(),
          mosaicId: '1234567890ABCDEF',
          amount: '200',
        },
      ],
    });

    assert.equal(result.network, 'private-alpha');
    assert.equal(result.qrPayload.network, 'private-alpha');
    assert.equal(maker.address.toString()[0], profile.addressPrefix);
  } finally {
    if (originalProfiles === undefined) {
      delete process.env.SYMBOL_NETWORK_PROFILES_JSON;
    } else {
      process.env.SYMBOL_NETWORK_PROFILES_JSON = originalProfiles;
    }
  }
});
