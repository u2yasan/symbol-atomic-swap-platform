import assert from 'node:assert/strict';
import test from 'node:test';
import { buildAggregateBonded } from './aggregateBondedBuilder.js';

const validRequest = {
  network: 'testnet',
  deadlineHours: 2,
  correlationId: 'swap-0001',
  hashLock: {
    mosaicId: '72C0212E67A08BCE',
    amount: '10000000',
    duration: 480,
  },
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

test('buildAggregateBonded returns unsigned payload, QR JSON, and hash lock requirements', () => {
  const result = buildAggregateBonded(validRequest);

  assert.equal(result.network, 'testnet');
  assert.match(result.unsignedPayload, /^[0-9A-F]+$/);
  assert.equal(result.qrPayload.type, 'symbol-aggregate-bonded');
  assert.equal(result.qrPayload.unsignedPayload, result.unsignedPayload);
  assert.deepEqual(result.qrPayload.hashLock, validRequest.hashLock);
  assert.deepEqual(result.requiredCosigners, [
    validRequest.legs[1]!.signerPublicKey,
    validRequest.legs[0]!.signerPublicKey,
  ]);
  assert.doesNotMatch(JSON.stringify(result), /privateKey|mnemonic|password/i);
});

test('buildAggregateBonded rejects same signer', () => {
  assert.throws(() => buildAggregateBonded({
    ...validRequest,
    legs: [
      validRequest.legs[0]!,
      { ...validRequest.legs[1]!, signerPublicKey: validRequest.legs[0]!.signerPublicKey },
    ],
  }), /distinct signer public keys/);
});

test('buildAggregateBonded rejects invalid hash lock requirement', () => {
  assert.throws(() => buildAggregateBonded({
    ...validRequest,
    hashLock: {
      ...validRequest.hashLock,
      amount: '0',
    },
  }), /positive integer/);

  assert.throws(() => buildAggregateBonded({
    ...validRequest,
    hashLock: {
      ...validRequest.hashLock,
      duration: 0,
    },
  }), /Number must be greater than or equal to 1/);
});
