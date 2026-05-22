import { PublicKey, Signature, utils } from 'symbol-sdk';
import { Address, descriptors, models, SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { z } from 'zod';
import { createSymbolFacadeForNetwork, deserializeTransactionForNetwork, networkKeySchema } from '../config/networkProfile.js';
import { addressSchema, publicKeySchema } from '../dto/aggregateComplete.js';
import type { SymbolRestClient } from './symbolRestClient.js';

const challengeSchema = z.string().min(32).max(1024);
const signedPayloadSchema = z.string().regex(/^[0-9A-Fa-f]+$/, 'payload must be hex').min(2);
const transactionHashSchema = z.string().regex(/^[0-9A-Fa-f]{64}$/, 'transaction hash must be 64 hex characters');

const accountVerificationBuildSchema = z.object({
  network: networkKeySchema,
  address: addressSchema,
  signerPublicKey: publicKeySchema,
  challenge: challengeSchema,
  deadlineHours: z.number().int().min(1).max(6).default(1),
});

const accountVerificationVerifySchema = accountVerificationBuildSchema.omit({ deadlineHours: true }).extend({
  payload: signedPayloadSchema,
});

const accountVerificationOnChainSchema = accountVerificationBuildSchema.omit({ deadlineHours: true }).extend({
  recipientAddress: addressSchema,
  transactionHash: transactionHashSchema,
});

export type AccountVerificationBuildResult = {
  network: string;
  address: string;
  signerPublicKey: string;
  challenge: string;
  unsignedPayload: string;
  deadline: string;
};

export type AccountVerificationResult = {
  accepted: boolean;
  reason: string;
  signerPublicKey?: string;
  transactionHash?: string;
};

function readMessage(transaction: unknown): string {
  const message = (transaction as { message?: Uint8Array | string }).message;
  if (message === undefined) {
    return '';
  }
  if (typeof message === 'string') {
    return message;
  }
  return new TextDecoder().decode(message);
}

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null ? value as Record<string, unknown> : {};
}

function readRestString(...values: unknown[]): string | undefined {
  for (const value of values) {
    if (typeof value === 'string' && value.length > 0) {
      return value;
    }
  }
  return undefined;
}

function decodePlainMessage(payload: string): string {
  if (!/^[0-9A-Fa-f]*$/.test(payload) || payload.length % 2 !== 0) {
    return '';
  }
  const decoded = new TextDecoder().decode(utils.hexToUint8(payload));
  if (decoded.startsWith('\u0000')) {
    return decoded.slice(1);
  }
  return decoded;
}

function normalizeRestAddress(value: string): string {
  const normalized = value.toUpperCase();
  if (/^[A-Z2-7]{39}$/.test(normalized)) {
    return normalized;
  }
  if (/^[0-9A-F]{48}$/.test(normalized)) {
    return new Address(utils.hexToUint8(normalized)).toString();
  }
  return normalized;
}

function extractRestPlainMessage(value: unknown): string | null {
  if (typeof value === 'string') {
    if (!/^[0-9A-Fa-f]*$/.test(value) || value.length % 2 !== 0) {
      return null;
    }
    if (value === '' || value.startsWith('00')) {
      return decodePlainMessage(value);
    }
    return null;
  }

  const message = asRecord(value);
  if (message.type !== 0) {
    return null;
  }
  const payload = readRestString(message.payload) ?? '';
  return decodePlainMessage(payload);
}

export function buildAccountVerificationPayload(input: unknown): AccountVerificationBuildResult {
  const request = accountVerificationBuildSchema.parse(input);
  const { facade } = createSymbolFacadeForNetwork(request.network);
  const transaction = facade.createTransactionFromTypedDescriptor(
    new descriptors.TransferTransactionV1Descriptor(
      new Address(request.address),
      [],
      request.challenge,
    ),
    new PublicKey(request.signerPublicKey),
    0,
    request.deadlineHours * 60 * 60,
  );

  transaction.fee = new models.Amount(0n);

  return {
    network: request.network,
    address: request.address,
    signerPublicKey: request.signerPublicKey.toUpperCase(),
    challenge: request.challenge,
    unsignedPayload: utils.uint8ToHex(transaction.serialize()).toUpperCase(),
    deadline: transaction.deadline.value.toString(),
  };
}

export function verifyAccountVerificationPayload(input: unknown): AccountVerificationResult {
  const parsed = accountVerificationVerifySchema.safeParse(input);
  if (!parsed.success) {
    return {
      accepted: false,
      reason: parsed.error.issues.map((issue) => issue.message).join('; '),
    };
  }
  if (parsed.data.payload.length % 2 !== 0) {
    return { accepted: false, reason: 'payload hex length must be even' };
  }

  try {
    const request = parsed.data;
    const { facade, profile } = createSymbolFacadeForNetwork(request.network);
    const { transaction } = deserializeTransactionForNetwork(utils.hexToUint8(request.payload), request.network);
    const signerPublicKey = transaction.signerPublicKey.toString().toUpperCase();

    if (transaction.type.value !== models.TransactionType.TRANSFER.value) {
      return { accepted: false, reason: 'transaction is not transfer' };
    }
    if (transaction.network.value !== profile.networkIdentifier) {
      return { accepted: false, reason: 'network mismatch' };
    }
    if (signerPublicKey !== request.signerPublicKey.toUpperCase()) {
      return { accepted: false, reason: 'signer public key mismatch' };
    }
    if (facade.network.publicKeyToAddress(new PublicKey(signerPublicKey)).toString() !== request.address) {
      return { accepted: false, reason: 'signer address mismatch' };
    }

    const transfer = transaction as unknown as {
      recipientAddress: { bytes: Uint8Array };
      mosaics?: unknown[];
      fee: { value: bigint };
      signature: { bytes: Uint8Array };
    };
    if (utils.uint8ToHex(transfer.recipientAddress.bytes).toUpperCase() !== utils.uint8ToHex(new Address(request.address).bytes).toUpperCase()) {
      return { accepted: false, reason: 'recipient address mismatch' };
    }
    if ((transfer.mosaics ?? []).length !== 0) {
      return { accepted: false, reason: 'verification transaction must not contain mosaics' };
    }
    if (transfer.fee.value !== 0n) {
      return { accepted: false, reason: 'verification transaction fee must be zero' };
    }
    if (readMessage(transaction) !== request.challenge) {
      return { accepted: false, reason: 'challenge mismatch' };
    }

    const signatureBytes = transfer.signature.bytes;
    if (!signatureBytes.some((byte) => byte !== 0)) {
      return { accepted: false, reason: 'transaction signature is missing' };
    }
    if (!facade.verifyTransaction(transaction, new Signature(signatureBytes))) {
      return { accepted: false, reason: 'transaction signature verification failed' };
    }

    return {
      accepted: true,
      reason: 'account_verification_passed',
      signerPublicKey,
      transactionHash: facade.hashTransaction(transaction).toString().toUpperCase(),
    };
  } catch {
    return { accepted: false, reason: 'account verification failed' };
  }
}

export async function verifyOnChainAccountVerificationTransaction(
  input: unknown,
  restClient: SymbolRestClient,
): Promise<AccountVerificationResult> {
  const parsed = accountVerificationOnChainSchema.safeParse(input);
  if (!parsed.success) {
    return {
      accepted: false,
      reason: parsed.error.issues.map((issue) => issue.message).join('; '),
    };
  }

  try {
    const request = parsed.data;
    const lookup = await restClient.getConfirmedTransactionDetails(request.transactionHash.toUpperCase());
    if (!lookup.found || !lookup.raw) {
      return { accepted: false, reason: 'confirmed transaction not found' };
    }

    const root = asRecord(lookup.raw);
    const meta = asRecord(root.meta);
    const transaction = asRecord(root.transaction);
    const signerPublicKey = readRestString(transaction.signerPublicKey)?.toUpperCase() ?? '';
    const recipientAddress = normalizeRestAddress(readRestString(transaction.recipientAddress) ?? '');
    const type = transaction.type;
    const network = transaction.network;
    const hash = readRestString(meta.hash, root.hash, lookup.transactionHash)?.toUpperCase() ?? '';
    const plainMessage = extractRestPlainMessage(transaction.message);
    const { facade, profile } = createSymbolFacadeForNetwork(request.network);

    if (hash !== request.transactionHash.toUpperCase()) {
      return { accepted: false, reason: 'transaction hash mismatch' };
    }
    if (type !== models.TransactionType.TRANSFER.value) {
      return { accepted: false, reason: 'transaction is not transfer' };
    }
    if (Number(network) !== profile.networkIdentifier) {
      return { accepted: false, reason: 'network mismatch' };
    }
    if (signerPublicKey !== request.signerPublicKey.toUpperCase()) {
      return { accepted: false, reason: 'signer public key mismatch' };
    }
    if (facade.network.publicKeyToAddress(new PublicKey(signerPublicKey)).toString() !== request.address) {
      return { accepted: false, reason: 'signer address mismatch' };
    }
    if (recipientAddress !== request.recipientAddress.toUpperCase()) {
      return { accepted: false, reason: 'recipient address mismatch' };
    }
    if (plainMessage === null) {
      return { accepted: false, reason: 'verification message must be plain text' };
    }
    if (plainMessage !== request.challenge) {
      return { accepted: false, reason: 'challenge mismatch' };
    }

    return {
      accepted: true,
      reason: 'account_on_chain_verification_passed',
      signerPublicKey,
      transactionHash: request.transactionHash.toUpperCase(),
    };
  } catch {
    return { accepted: false, reason: 'on-chain account verification failed' };
  }
}
