import { Hash256, PublicKey, Signature, utils } from 'symbol-sdk';
import { descriptors, models, SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { z } from 'zod';
import { integerStringSchema, publicKeySchema } from '../dto/aggregateComplete.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { NormalizedBondedSwapIntent, SwapIntentRecord } from '../repository/types.js';

const intentHashSchema = z.string().regex(/^[0-9A-Fa-f]{64}$/);

const hashLockBuildRequestSchema = z.object({
  intentHash: intentHashSchema,
  signerPublicKey: publicKeySchema,
  deadlineHours: z.number().int().min(1).max(48),
  maxFee: integerStringSchema.optional(),
});

const signedHashLockAnnouncementSchema = z.object({
  intentHash: intentHashSchema,
  payload: z.string().regex(/^[0-9A-Fa-f]+$/, 'payload must be hex').min(2),
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
  nodeResponse: unknown;
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
      reason: error instanceof Error ? error.message : 'hash lock verification failed',
    };
  }
}

export async function announceSignedHashLock(
  input: unknown,
  dependencies: {
    nodeUrl: string | undefined;
    swapIntents: SwapIntentRepository;
  },
): Promise<HashLockAnnouncementResult> {
  const request = signedHashLockAnnouncementSchema.parse(input);
  if (!dependencies.nodeUrl) {
    throw new Error('SYMBOL_NODE_URL is required for hash lock announcement.');
  }

  const intent = await dependencies.swapIntents.findByIntentHash(request.intentHash.toUpperCase());
  const verification = verifySignedHashLockPayload(input, intent);
  if (!verification.accepted || !verification.payload || !verification.transactionHash || !intent?.transactionHash) {
    throw new InvalidHashLockError(verification.reason);
  }

  const response = await fetch(new URL('/transactions', dependencies.nodeUrl), {
    method: 'PUT',
    headers: {
      'content-type': 'application/json',
    },
    body: JSON.stringify({ payload: verification.payload }),
  });

  const nodeResponse = await response.json().catch(() => ({ status: response.status }));
  if (!response.ok) {
    throw new InvalidHashLockError('symbol node rejected hash lock announcement');
  }

  return {
    accepted: true,
    intentHash: intent.intentHash,
    aggregateTransactionHash: intent.transactionHash,
    hashLockTransactionHash: verification.transactionHash,
    nodeResponse,
  };
}
