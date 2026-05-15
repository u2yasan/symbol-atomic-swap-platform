import assert from 'node:assert/strict';
import test from 'node:test';
import { PrivateKey, utils } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import {
  buildAccountVerificationPayload,
  verifyAccountVerificationPayload,
  verifyOnChainAccountVerificationTransaction,
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

test('on-chain account verification accepts a confirmed transfer to the site address', async () => {
  const facade = new SymbolFacade('testnet');
  const privateKey = PrivateKey.random();
  const account = facade.createAccount(privateKey);
  const siteAddress = facade.createAccount(PrivateKey.random()).address.toString();
  const challenge = 'symbol-atomic-swap:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
  const transactionHash = 'A'.repeat(64);
  const restClient = {
    async getConfirmedTransactionDetails() {
      return {
        found: true,
        transactionHash,
        raw: {
          meta: { hash: transactionHash },
          transaction: {
            type: 16724,
            network: 152,
            signerPublicKey: account.publicKey.toString(),
            recipientAddress: siteAddress,
            message: {
              type: 0,
              payload: utils.uint8ToHex(new TextEncoder().encode(challenge)),
            },
          },
        },
      };
    },
  };

  const result = await verifyOnChainAccountVerificationTransaction({
    network: 'testnet',
    address: account.address.toString(),
    signerPublicKey: account.publicKey.toString(),
    recipientAddress: siteAddress,
    challenge,
    transactionHash,
  }, restClient as never);

  assert.equal(result.accepted, true);
  assert.equal(result.reason, 'account_on_chain_verification_passed');
});

test('on-chain account verification rejects a transfer to another recipient', async () => {
  const facade = new SymbolFacade('testnet');
  const privateKey = PrivateKey.random();
  const account = facade.createAccount(privateKey);
  const siteAddress = facade.createAccount(PrivateKey.random()).address.toString();
  const wrongAddress = facade.createAccount(PrivateKey.random()).address.toString();
  const challenge = 'symbol-atomic-swap:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
  const transactionHash = 'B'.repeat(64);
  const restClient = {
    async getConfirmedTransactionDetails() {
      return {
        found: true,
        transactionHash,
        raw: {
          meta: { hash: transactionHash },
          transaction: {
            type: 16724,
            network: 152,
            signerPublicKey: account.publicKey.toString(),
            recipientAddress: wrongAddress,
            message: {
              type: 0,
              payload: utils.uint8ToHex(new TextEncoder().encode(challenge)),
            },
          },
        },
      };
    },
  };

  const result = await verifyOnChainAccountVerificationTransaction({
    network: 'testnet',
    address: account.address.toString(),
    signerPublicKey: account.publicKey.toString(),
    recipientAddress: siteAddress,
    challenge,
    transactionHash,
  }, restClient as never);

  assert.equal(result.accepted, false);
  assert.equal(result.reason, 'recipient address mismatch');
});
