import { createHash } from 'node:crypto';
import { Hash256, PublicKey, Signature, utils } from 'symbol-sdk';
import { Address, descriptors, models, SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { z } from 'zod';
import { createSymbolFacadeForNetwork, deserializeTransactionForNetwork, networkKeySchema } from '../config/networkProfile.js';
import { addressSchema, integerStringSchema, mosaicIdSchema, publicKeySchema } from '../dto/aggregateComplete.js';
import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';
import { putJsonToSymbolNode } from './symbolNodeHttp.js';
import { publicSymbolNodeResponse, type PublicSymbolNodeResponse } from './symbolNodeResponse.js';

const secretSchema = z.string().regex(/^[0-9A-Fa-f]{64}$/, 'secret must be 32-byte hex');
const proofSchema = z.string().regex(/^[0-9A-Fa-f]{2,2048}$/, 'proof must be hex');
const signedPayloadSchema = z.string().regex(/^[0-9A-Fa-f]+$/, 'payload must be hex').min(2);
const lockHashAlgorithmSchema = z.literal('SHA3_256');

const secretLockBuildRequestSchema = z.object({
  network: networkKeySchema,
  signerPublicKey: publicKeySchema,
  recipientAddress: addressSchema,
  mosaicId: mosaicIdSchema,
  amount: integerStringSchema,
  duration: z.number().int().min(1),
  secret: secretSchema,
  hashAlgorithm: lockHashAlgorithmSchema,
  deadlineHours: z.number().int().min(1).max(48),
  maxFee: integerStringSchema.optional(),
});

const signedSecretLockAnnouncementSchema = secretLockBuildRequestSchema.omit({ deadlineHours: true, maxFee: true }).extend({
  payload: signedPayloadSchema,
});

const secretProofBuildRequestSchema = z.object({
  network: networkKeySchema,
  signerPublicKey: publicKeySchema,
  recipientAddress: addressSchema,
  proof: proofSchema,
  hashAlgorithm: lockHashAlgorithmSchema,
  deadlineHours: z.number().int().min(1).max(48),
  maxFee: integerStringSchema.optional(),
});

const signedSecretProofAnnouncementSchema = secretProofBuildRequestSchema.omit({ deadlineHours: true, maxFee: true }).extend({
  payload: signedPayloadSchema,
});

export type SecretLockBuildResult = {
  network: string;
  signerPublicKey: string;
  recipientAddress: string;
  mosaicId: string;
  amount: string;
  duration: number;
  secret: string;
  hashAlgorithm: 'SHA3_256';
  unsignedPayload: string;
  deadline: string;
};

export type SecretProofBuildResult = {
  network: string;
  signerPublicKey: string;
  recipientAddress: string;
  secret: string;
  proof: string;
  hashAlgorithm: 'SHA3_256';
  unsignedPayload: string;
  deadline: string;
};

export type SecretAnnouncementResult = {
  accepted: true;
  transactionHash: string;
  nodeResponse: PublicSymbolNodeResponse;
};

export class InvalidSecretLockError extends Error {
  public readonly statusCode = 409;
}

function toMosaicId(value: string): bigint {
  return BigInt(`0x${value}`);
}

function secretFromProof(proofHex: string): string {
  return createHash('sha3-256').update(Buffer.from(proofHex, 'hex')).digest('hex').toUpperCase();
}

function hashAlgorithm(): models.LockHashAlgorithm {
  return models.LockHashAlgorithm.SHA3_256;
}

function hexAddress(address: string): string {
  return utils.uint8ToHex(new Address(address).bytes).toUpperCase();
}

function readMosaicId(mosaicId: unknown): string {
  const value = (mosaicId as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid secret lock mosaic id');
  }
  return value.toString(16).toUpperCase().padStart(16, '0');
}

function readAmount(amount: unknown): string {
  const value = (amount as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid secret lock amount');
  }
  return value.toString();
}

function readDuration(duration: unknown): string {
  const value = (duration as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid secret lock duration');
  }
  return value.toString();
}

function readAlgorithm(algorithm: unknown): string {
  const value = (algorithm as { value?: number }).value;
  if (value !== models.LockHashAlgorithm.SHA3_256.value) {
    throw new Error('unsupported secret lock hash algorithm');
  }
  return 'SHA3_256';
}

function assertSignedTransaction(transaction: { signature: { bytes: Uint8Array } }, facade: SymbolFacade): void {
  const signatureBytes = transaction.signature.bytes;
  const hasSignature = signatureBytes.some((byte) => byte !== 0);
  if (!hasSignature) {
    throw new InvalidSecretLockError('transaction signature is missing');
  }
  if (!facade.verifyTransaction(transaction as never, new Signature(signatureBytes))) {
    throw new InvalidSecretLockError('transaction signature verification failed');
  }
}

const publicSecretLockVerificationErrorMessages = new Set([
  'invalid secret lock mosaic id',
  'invalid secret lock amount',
  'invalid secret lock duration',
  'unsupported secret lock hash algorithm',
  'transaction signature is missing',
  'transaction signature verification failed',
]);

function publicSecretLockVerificationFailureReason(error: unknown, fallback: string): string {
  if (error instanceof Error && publicSecretLockVerificationErrorMessages.has(error.message)) {
    return error.message;
  }
  return fallback;
}

export function buildSecretLockTransaction(input: unknown): SecretLockBuildResult {
  const request = secretLockBuildRequestSchema.parse(input);
  const { facade } = createSymbolFacadeForNetwork(request.network);
  const transaction = facade.createTransactionFromTypedDescriptor(
    new descriptors.SecretLockTransactionV1Descriptor(
      new Address(request.recipientAddress),
      new Hash256(request.secret.toUpperCase()),
      new descriptors.UnresolvedMosaicDescriptor(
        new models.UnresolvedMosaicId(toMosaicId(request.mosaicId)),
        new models.Amount(BigInt(request.amount)),
      ),
      new models.BlockDuration(BigInt(request.duration)),
      hashAlgorithm(),
    ),
    new PublicKey(request.signerPublicKey.toUpperCase()),
    100,
    request.deadlineHours * 60 * 60,
  );

  if (request.maxFee) {
    transaction.fee = new models.Amount(BigInt(request.maxFee));
  }

  return {
    network: request.network,
    signerPublicKey: request.signerPublicKey.toUpperCase(),
    recipientAddress: request.recipientAddress,
    mosaicId: request.mosaicId.toUpperCase(),
    amount: request.amount,
    duration: request.duration,
    secret: request.secret.toUpperCase(),
    hashAlgorithm: request.hashAlgorithm,
    unsignedPayload: utils.uint8ToHex(transaction.serialize()).toUpperCase(),
    deadline: transaction.deadline.value.toString(),
  };
}

export function buildSecretProofTransaction(input: unknown): SecretProofBuildResult {
  const request = secretProofBuildRequestSchema.parse(input);
  const { facade } = createSymbolFacadeForNetwork(request.network);
  const proof = request.proof.toUpperCase();
  const secret = secretFromProof(proof);
  const transaction = facade.createTransactionFromTypedDescriptor(
    new descriptors.SecretProofTransactionV1Descriptor(
      new Address(request.recipientAddress),
      new Hash256(secret),
      hashAlgorithm(),
      utils.hexToUint8(proof),
    ),
    new PublicKey(request.signerPublicKey.toUpperCase()),
    100,
    request.deadlineHours * 60 * 60,
  );

  if (request.maxFee) {
    transaction.fee = new models.Amount(BigInt(request.maxFee));
  }

  return {
    network: request.network,
    signerPublicKey: request.signerPublicKey.toUpperCase(),
    recipientAddress: request.recipientAddress,
    secret,
    proof,
    hashAlgorithm: request.hashAlgorithm,
    unsignedPayload: utils.uint8ToHex(transaction.serialize()).toUpperCase(),
    deadline: transaction.deadline.value.toString(),
  };
}

export function verifySignedSecretLockPayload(input: unknown): {
  accepted: boolean;
  reason: string;
  payload?: string;
  transactionHash?: string;
} {
  const parsed = signedSecretLockAnnouncementSchema.safeParse(input);
  if (!parsed.success) {
    return { accepted: false, reason: parsed.error.issues.map((issue) => issue.message).join('; ') };
  }

  try {
    const request = parsed.data;
    const { facade, profile } = createSymbolFacadeForNetwork(request.network);
    const { transaction } = deserializeTransactionForNetwork(utils.hexToUint8(request.payload), request.network);
    if (transaction.type.value !== models.TransactionType.SECRET_LOCK.value) {
      return { accepted: false, reason: 'transaction is not secret lock' };
    }
    if (transaction.network.value !== profile.networkIdentifier) {
      return { accepted: false, reason: 'network mismatch' };
    }
    if (transaction.signerPublicKey.toString().toUpperCase() !== request.signerPublicKey.toUpperCase()) {
      return { accepted: false, reason: 'signer public key mismatch' };
    }

    const secretLockTransaction = transaction as unknown as {
      recipientAddress: { bytes: Uint8Array };
      secret: { toString(): string };
      mosaic: { mosaicId: unknown; amount: unknown };
      duration: unknown;
      hashAlgorithm: unknown;
      signature: { bytes: Uint8Array };
    };
    if (utils.uint8ToHex(secretLockTransaction.recipientAddress.bytes).toUpperCase() !== hexAddress(request.recipientAddress)) {
      return { accepted: false, reason: 'secret lock recipient mismatch' };
    }
    if (secretLockTransaction.secret.toString().toUpperCase() !== request.secret.toUpperCase()) {
      return { accepted: false, reason: 'secret mismatch' };
    }
    if (readMosaicId(secretLockTransaction.mosaic.mosaicId) !== request.mosaicId.toUpperCase()) {
      return { accepted: false, reason: 'secret lock mosaic id mismatch' };
    }
    if (readAmount(secretLockTransaction.mosaic.amount) !== request.amount) {
      return { accepted: false, reason: 'secret lock amount mismatch' };
    }
    if (readDuration(secretLockTransaction.duration) !== String(request.duration)) {
      return { accepted: false, reason: 'secret lock duration mismatch' };
    }
    readAlgorithm(secretLockTransaction.hashAlgorithm);
    assertSignedTransaction(secretLockTransaction, facade);

    return {
      accepted: true,
      reason: 'semantic_verification_passed',
      payload: request.payload.toUpperCase(),
      transactionHash: facade.hashTransaction(transaction).toString().toUpperCase(),
    };
  } catch (error) {
    return {
      accepted: false,
      reason: publicSecretLockVerificationFailureReason(error, 'secret lock verification failed'),
    };
  }
}

export function verifySignedSecretProofPayload(input: unknown): {
  accepted: boolean;
  reason: string;
  payload?: string;
  transactionHash?: string;
  secret?: string;
} {
  const parsed = signedSecretProofAnnouncementSchema.safeParse(input);
  if (!parsed.success) {
    return { accepted: false, reason: parsed.error.issues.map((issue) => issue.message).join('; ') };
  }

  try {
    const request = parsed.data;
    const expectedSecret = secretFromProof(request.proof.toUpperCase());
    const { facade, profile } = createSymbolFacadeForNetwork(request.network);
    const { transaction } = deserializeTransactionForNetwork(utils.hexToUint8(request.payload), request.network);
    if (transaction.type.value !== models.TransactionType.SECRET_PROOF.value) {
      return { accepted: false, reason: 'transaction is not secret proof' };
    }
    if (transaction.network.value !== profile.networkIdentifier) {
      return { accepted: false, reason: 'network mismatch' };
    }
    if (transaction.signerPublicKey.toString().toUpperCase() !== request.signerPublicKey.toUpperCase()) {
      return { accepted: false, reason: 'signer public key mismatch' };
    }

    const secretProofTransaction = transaction as unknown as {
      recipientAddress: { bytes: Uint8Array };
      secret: { toString(): string };
      hashAlgorithm: unknown;
      proof: Uint8Array;
      signature: { bytes: Uint8Array };
    };
    if (utils.uint8ToHex(secretProofTransaction.recipientAddress.bytes).toUpperCase() !== hexAddress(request.recipientAddress)) {
      return { accepted: false, reason: 'secret proof recipient mismatch' };
    }
    if (secretProofTransaction.secret.toString().toUpperCase() !== expectedSecret) {
      return { accepted: false, reason: 'secret proof hash mismatch' };
    }
    if (utils.uint8ToHex(secretProofTransaction.proof).toUpperCase() !== request.proof.toUpperCase()) {
      return { accepted: false, reason: 'secret proof value mismatch' };
    }
    readAlgorithm(secretProofTransaction.hashAlgorithm);
    assertSignedTransaction(secretProofTransaction, facade);

    return {
      accepted: true,
      reason: 'semantic_verification_passed',
      payload: request.payload.toUpperCase(),
      transactionHash: facade.hashTransaction(transaction).toString().toUpperCase(),
      secret: expectedSecret,
    };
  } catch (error) {
    return {
      accepted: false,
      reason: publicSecretLockVerificationFailureReason(error, 'secret proof verification failed'),
    };
  }
}

async function announceVerifiedPayload(
  payload: string,
  transactionHash: string,
  nodeUrl: string | undefined,
  nodeRequestTimeoutMs?: number,
): Promise<SecretAnnouncementResult> {
  if (!nodeUrl) {
    throw new SymbolNodeUnavailableError('SYMBOL_NODE_URL is required for secret transaction announcement.');
  }

  const response = await putJsonToSymbolNode(nodeUrl, '/transactions', {
    payload,
  }, nodeRequestTimeoutMs ? { timeoutMs: nodeRequestTimeoutMs } : {});
  const nodeResponse = await publicSymbolNodeResponse(response);
  if (!response.ok) {
    throw new InvalidSecretLockError('symbol node rejected secret transaction announcement');
  }

  return {
    accepted: true,
    transactionHash,
    nodeResponse,
  };
}

export async function announceSignedSecretLock(
  input: unknown,
  nodeUrl: string | undefined,
  nodeRequestTimeoutMs?: number,
): Promise<SecretAnnouncementResult> {
  const verification = verifySignedSecretLockPayload(input);
  if (!verification.accepted || !verification.payload || !verification.transactionHash) {
    throw new InvalidSecretLockError(verification.reason);
  }
  return announceVerifiedPayload(verification.payload, verification.transactionHash, nodeUrl, nodeRequestTimeoutMs);
}

export async function announceSignedSecretProof(
  input: unknown,
  nodeUrl: string | undefined,
  nodeRequestTimeoutMs?: number,
): Promise<SecretAnnouncementResult> {
  const verification = verifySignedSecretProofPayload(input);
  if (!verification.accepted || !verification.payload || !verification.transactionHash) {
    throw new InvalidSecretLockError(verification.reason);
  }
  return announceVerifiedPayload(verification.payload, verification.transactionHash, nodeUrl, nodeRequestTimeoutMs);
}
