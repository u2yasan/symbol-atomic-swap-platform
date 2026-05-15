import assert from 'node:assert/strict';
import test from 'node:test';
import { PrivateKey } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import {
  buildAccountVerificationPayload,
  verifyAccountVerificationPayload,
} from './accountVerificationService.js';

function signPayload(unsignedPayload: string, privateKey: PrivateKey): string {
  const facade = new SymbolFacade('testnet');
  const account = facade.createAccount(privateKey);
  const transaction = SymbolTransactionFactory.deserialize(Buffer.from(unsignedPayload, 'hex'));
  const signature = account.signTransaction(transaction);
  const signed = JSON.parse(SymbolTransactionFactory.attachSignature(transaction, signature)) as { payload: string };
  return signed.payload.toUpperCase();
}

test('account verification accepts signed zero-fee challenge payload', () => {
  const facade = new SymbolFacade('testnet');
  const privateKey = PrivateKey.random();
  const account = facade.createAccount(privateKey);
  const challenge = 'symbol-atomic-swap address verification nonce=abc1234567890';

  const built = buildAccountVerificationPayload({
    network: 'testnet',
    address: account.address.toString(),
    signerPublicKey: account.publicKey.toString(),
    challenge,
    deadlineHours: 1,
  });
  const payload = signPayload(built.unsignedPayload, privateKey);
  const result = verifyAccountVerificationPayload({
    network: 'testnet',
    address: account.address.toString(),
    signerPublicKey: account.publicKey.toString(),
    challenge,
    payload,
  });

  assert.equal(result.accepted, true);
  assert.equal(result.signerPublicKey, account.publicKey.toString().toUpperCase());
  assert.match(result.transactionHash ?? '', /^[0-9A-F]{64}$/);
});

test('account verification rejects a different challenge', () => {
  const facade = new SymbolFacade('testnet');
  const privateKey = PrivateKey.random();
  const account = facade.createAccount(privateKey);
  const built = buildAccountVerificationPayload({
    network: 'testnet',
    address: account.address.toString(),
    signerPublicKey: account.publicKey.toString(),
    challenge: 'symbol-atomic-swap address verification nonce=original',
    deadlineHours: 1,
  });
  const payload = signPayload(built.unsignedPayload, privateKey);
  const result = verifyAccountVerificationPayload({
    network: 'testnet',
    address: account.address.toString(),
    signerPublicKey: account.publicKey.toString(),
    challenge: 'symbol-atomic-swap address verification nonce=tampered',
    payload,
  });

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'challenge mismatch');
});
