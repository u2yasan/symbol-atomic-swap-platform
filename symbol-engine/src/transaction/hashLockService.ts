import { Hash256, PublicKey, Signature, utils } from 'symbol-sdk';
import { descriptors, models, SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { z } from 'zod';
import { integerStringSchema, publicKeySchema } from '../dto/aggregateComplete.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { NormalizedBondedSwapIntent, SwapIntentRecord } from '../repository/types.js';
import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';
import { putJsonToSymbolNode } from './symbolNodeHttp.js';
import { publicSymbolNodeResponse, type PublicSymbolNodeResponse } from './symbolNodeResponse.js';
import { SymbolRestClient } from './symbolRestClient.js';

const intentHashSchema = z.string().regex(/^[0-9A-Fa-f]{64}$/);
const DEFAULT_CONFIRMATION_TIMEOUT_MS = 300_000;
const DEFAULT_CONFIRMATION_POLL_INTERVAL_MS = 5_000;

const hashLockBuildRequestSchema = z.object({
  intentHash: intentHashSchema,
  signerPublicKey: publicKeySchema,
  deadlineHours: z.number().int().min(1).max(6),
  maxFee: integerStringSchema.optional(),
});

const signedHashLockAnnouncementSchema = z.object({
  intentHash: intentHashSchema,
  payload: z.string().regex(/^[0-9A-Fa-f]+$/, 'payload must be hex').min(2),
  waitForConfirmation: z.boolean().optional(),
  confirmationTimeoutMs: z.number().int().min(1_000).max(300_000).optional(),
  confirmationPollIntervalMs: z.number().int().min(250).max(30_000).optional(),
});

export type HashLockBuildResult = {
  intentHash: string;
  aggregateTransactionHash: string;
  network: 'mainnet' | 'testnet';
  lockSignerPublicKey: string;
  unsignedPayload: string;
  deadline: string;
  hashLock: {
    mosaicId: string;
    amount: string;
    duration: number;
  };
};

export type HashLockAnnouncementResult = {
  accepted: true;
  intentHash: string;
  aggregateTransactionHash: string;
  hashLockTransactionHash: string;
  hashLockConfirmed: boolean;
  nodeResponse: PublicSymbolNodeResponse;
};

export class InvalidHashLockError extends Error {
  public readonly statusCode = 409;
}

type SignedBondedIntentRecord = SwapIntentRecord & {
  aggregateType: 'aggregate_bonded';
  state: 'signed';
  signedPayload: string;
  transactionHash: string;
  intent: NormalizedBondedSwapIntent;
};

function toMosaicId(value: string): bigint {
  return BigInt(`0x${value}`);
}

function requireSignedBondedIntent(intent: SwapIntentRecord | null): SignedBondedIntentRecord {
  if (!intent) {
    throw new InvalidHashLockError('swap intent not found');
  }

  if (intent.aggregateType !== 'aggregate_bonded') {
    throw new InvalidHashLockError('hash lock requires aggregate bonded intent');
  }

  if (intent.state !== 'signed' || !intent.signedPayload || !intent.transactionHash) {
    throw new InvalidHashLockError('aggregate bonded intent must be signed before hash lock');
  }

  return intent as SignedBondedIntentRecord;
}

export async function buildHashLockTransaction(
  input: unknown,
  swapIntents: SwapIntentRepository,
): Promise<HashLockBuildResult> {
  const request = hashLockBuildRequestSchema.parse(input);
  const intent = requireSignedBondedIntent(
    await swapIntents.findByIntentHash(request.intentHash.toUpperCase()),
  );
  const facade = new SymbolFacade(intent.network);
  const hashLock = intent.intent.hashLock;
  const transaction = facade.createTransactionFromTypedDescriptor(
    new descriptors.HashLockTransactionV1Descriptor(
      new descriptors.UnresolvedMosaicDescriptor(
        new models.UnresolvedMosaicId(toMosaicId(hashLock.mosaicId)),
        new models.Amount(BigInt(hashLock.amount)),
      ),
      new models.BlockDuration(BigInt(hashLock.duration)),
      new Hash256(intent.transactionHash),
    ),
    new PublicKey(request.signerPublicKey.toUpperCase()),
    100,
    request.deadlineHours * 60 * 60,
  );

  if (request.maxFee) {
    transaction.fee = new models.Amount(BigInt(request.maxFee));
  }

  return {
    intentHash: intent.intentHash,
    aggregateTransactionHash: intent.transactionHash,
    network: intent.network,
    lockSignerPublicKey: request.signerPublicKey.toUpperCase(),
    unsignedPayload: utils.uint8ToHex(transaction.serialize()).toUpperCase(),
    deadline: transaction.deadline.value.toString(),
    hashLock,
  };
}

function readMosaicId(mosaicId: unknown): string {
  const value = (mosaicId as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid hash lock mosaic id');
  }
  return value.toString(16).toUpperCase().padStart(16, '0');
}

function readAmount(amount: unknown): string {
  const value = (amount as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid hash lock amount');
  }
  return value.toString();
}

function readDuration(duration: unknown): string {
  const value = (duration as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid hash lock duration');
  }
  return value.toString();
}

const publicHashLockVerificationErrorMessages = new Set([
  'swap intent not found',
  'hash lock requires aggregate bonded intent',
  'aggregate bonded intent must be signed before hash lock',
  'invalid hash lock mosaic id',
  'invalid hash lock amount',
  'invalid hash lock duration',
]);

function publicHashLockVerificationFailureReason(error: unknown): string {
  if (error instanceof Error && publicHashLockVerificationErrorMessages.has(error.message)) {
    return error.message;
  }
  return 'hash lock verification failed';
}

export function verifySignedHashLockPayload(input: unknown, intent: SwapIntentRecord | null): {
  accepted: boolean;
  reason: string;
  payload?: string;
  transactionHash?: string;
} {
  const parsed = signedHashLockAnnouncementSchema.safeParse(input);
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
    const signedIntent = requireSignedBondedIntent(intent);
    if (parsed.data.intentHash.toUpperCase() !== signedIntent.intentHash) {
      return { accepted: false, reason: 'intent hash mismatch' };
    }

    const transaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(parsed.data.payload));
    const facade = new SymbolFacade(signedIntent.network);
    if (transaction.type.value !== models.TransactionType.HASH_LOCK.value) {
      return { accepted: false, reason: 'transaction is not hash lock' };
    }

    if (transaction.network.value !== (signedIntent.network === 'mainnet' ? 104 : 152)) {
      return { accepted: false, reason: 'network mismatch' };
    }

    const hashLockTransaction = transaction as unknown as {
      mosaic: { mosaicId: unknown; amount: unknown };
      duration: unknown;
      hash: { toString(): string };
      signature: { bytes: Uint8Array };
    };
    const expected = signedIntent.intent.hashLock;
    if (readMosaicId(hashLockTransaction.mosaic.mosaicId) !== expected.mosaicId) {
      return { accepted: false, reason: 'hash lock mosaic id mismatch' };
    }
    if (readAmount(hashLockTransaction.mosaic.amount) !== expected.amount) {
      return { accepted: false, reason: 'hash lock amount mismatch' };
    }
    if (readDuration(hashLockTransaction.duration) !== String(expected.duration)) {
      return { accepted: false, reason: 'hash lock duration mismatch' };
    }
    if (hashLockTransaction.hash.toString().toUpperCase() !== signedIntent.transactionHash) {
      return { accepted: false, reason: 'hash lock aggregate hash mismatch' };
    }

    const signatureBytes = transaction.signature.bytes;
    const hasSignature = signatureBytes.some((byte: number) => byte !== 0);
    if (!hasSignature) {
      return { accepted: false, reason: 'hash lock signature is missing' };
    }
    if (!facade.verifyTransaction(transaction, new Signature(signatureBytes))) {
      return { accepted: false, reason: 'hash lock signature verification failed' };
    }

    return {
      accepted: true,
      reason: 'semantic_verification_passed',
      payload: parsed.data.payload.toUpperCase(),
      transactionHash: facade.hashTransaction(transaction).toString().toUpperCase(),
    };
  } catch (error) {
    return {
      accepted: false,
      reason: publicHashLockVerificationFailureReason(error),
    };
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForConfirmedHashLock(
  transactionHash: string,
  dependencies: {
    nodeUrl: string;
    nodeRequestTimeoutMs?: number;
  },
  options: {
    timeoutMs: number;
    pollIntervalMs: number;
  },
): Promise<void> {
  const client = new SymbolRestClient(dependencies.nodeUrl, fetch, dependencies.nodeRequestTimeoutMs);
  const deadline = Date.now() + options.timeoutMs;
  let sawUnconfirmed = false;

  while (Date.now() <= deadline) {
    const status = await client.getTransactionStatus(transactionHash);
    if (status.found && status.code && status.code !== 'Success') {
      throw new InvalidHashLockError(`hash lock transaction failed: ${status.code}`);
    }

    const confirmed = await client.getConfirmedTransaction(transactionHash);
    if (confirmed.found) {
      return;
    }

    const unconfirmed = await client.getUnconfirmedTransaction(transactionHash);
    if (unconfirmed.found) {
      sawUnconfirmed = true;
    }

    const remaining = deadline - Date.now();
    if (remaining <= 0) {
      break;
    }
    await sleep(Math.min(options.pollIntervalMs, remaining));
  }

  throw new InvalidHashLockError(sawUnconfirmed
    ? `hash lock stayed unconfirmed until timeout: ${transactionHash}`
    : `hash lock was not found on the node after announcement: ${transactionHash}`);
}

async function isConfirmedHashLock(
  transactionHash: string,
  dependencies: {
    nodeUrl: string;
    nodeRequestTimeoutMs?: number;
  },
): Promise<boolean> {
  const client = new SymbolRestClient(dependencies.nodeUrl, fetch, dependencies.nodeRequestTimeoutMs);
  const status = await client.getTransactionStatus(transactionHash);
  if (status.found && status.code && status.code !== 'Success') {
    throw new InvalidHashLockError(`hash lock transaction failed: ${status.code}: ${transactionHash}`);
  }
  return (await client.getConfirmedTransaction(transactionHash)).found;
}

export async function announceSignedHashLock(
  input: unknown,
  dependencies: {
    nodeUrl: string | undefined;
    swapIntents: SwapIntentRepository;
    nodeRequestTimeoutMs?: number;
  },
): Promise<HashLockAnnouncementResult> {
  const request = signedHashLockAnnouncementSchema.parse(input);
  if (!dependencies.nodeUrl) {
    throw new SymbolNodeUnavailableError('SYMBOL_NODE_URL is required for hash lock announcement.');
  }

  const intent = await dependencies.swapIntents.findByIntentHash(request.intentHash.toUpperCase());
  const verification = verifySignedHashLockPayload(input, intent);
  if (!verification.accepted || !verification.payload || !verification.transactionHash || !intent?.transactionHash) {
    throw new InvalidHashLockError(verification.reason);
  }

  if (request.waitForConfirmation && await isConfirmedHashLock(verification.transactionHash, {
    nodeUrl: dependencies.nodeUrl,
    nodeRequestTimeoutMs: dependencies.nodeRequestTimeoutMs,
  })) {
    return {
      accepted: true,
      intentHash: intent.intentHash,
      aggregateTransactionHash: intent.transactionHash,
      hashLockTransactionHash: verification.transactionHash,
      hashLockConfirmed: true,
      nodeResponse: {
        status: 200,
        message: 'hash lock already confirmed',
      },
    };
  }

  const response = await putJsonToSymbolNode(dependencies.nodeUrl, '/transactions', {
    payload: verification.payload,
  }, dependencies.nodeRequestTimeoutMs ? { timeoutMs: dependencies.nodeRequestTimeoutMs } : {});

  const nodeResponse = await publicSymbolNodeResponse(response);
  if (!response.ok) {
    if (request.waitForConfirmation && await isConfirmedHashLock(verification.transactionHash, {
      nodeUrl: dependencies.nodeUrl,
      nodeRequestTimeoutMs: dependencies.nodeRequestTimeoutMs,
    })) {
      return {
        accepted: true,
        intentHash: intent.intentHash,
        aggregateTransactionHash: intent.transactionHash,
        hashLockTransactionHash: verification.transactionHash,
        hashLockConfirmed: true,
        nodeResponse: {
          status: 200,
          message: 'hash lock already confirmed',
        },
      };
    }
    throw new InvalidHashLockError('symbol node rejected hash lock announcement');
  }

  if (request.waitForConfirmation) {
    await waitForConfirmedHashLock(verification.transactionHash, {
      nodeUrl: dependencies.nodeUrl,
      nodeRequestTimeoutMs: dependencies.nodeRequestTimeoutMs,
    }, {
      timeoutMs: request.confirmationTimeoutMs ?? DEFAULT_CONFIRMATION_TIMEOUT_MS,
      pollIntervalMs: request.confirmationPollIntervalMs ?? DEFAULT_CONFIRMATION_POLL_INTERVAL_MS,
    });
  }

  return {
    accepted: true,
    intentHash: intent.intentHash,
    aggregateTransactionHash: intent.transactionHash,
    hashLockTransactionHash: verification.transactionHash,
    hashLockConfirmed: Boolean(request.waitForConfirmation),
    nodeResponse,
  };
}
