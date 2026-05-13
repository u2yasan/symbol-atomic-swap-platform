import assert from 'node:assert/strict';
import test from 'node:test';
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

test('buildAggregateComplete rejects same signer', () => {
  assert.throws(() => buildAggregateComplete({
    ...validRequest,
    legs: [
      validRequest.legs[0]!,
      { ...validRequest.legs[1]!, signerPublicKey: validRequest.legs[0]!.signerPublicKey },
    ],
  }), /distinct signer public keys/);
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
