import type { BlockchainEvent } from '../dto/events.js';

type SymbolNetwork = 'mainnet' | 'testnet';

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null ? value as Record<string, unknown> : {};
}

function readString(...values: unknown[]): string | undefined {
  for (const value of values) {
    if (typeof value === 'string' && value.length > 0) {
      return value;
    }
  }
  return undefined;
}

function readPositiveNumber(...values: unknown[]): number | undefined {
  for (const value of values) {
    if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
      return value;
    }
    if (typeof value === 'string' && /^[1-9][0-9]*$/.test(value)) {
      return Number(value);
    }
  }
  return undefined;
}

function extractTransactionHash(payload: Record<string, unknown>): string | undefined {
  const meta = asRecord(payload.meta);
  const transactionInfo = asRecord(payload.transactionInfo);
  return readString(payload.hash, meta.hash, transactionInfo.hash);
}

function extractSignerPublicKey(payload: Record<string, unknown>): string | undefined {
  const transaction = asRecord(payload.transaction);
  return readString(payload.signerPublicKey, transaction.signerPublicKey);
}

function extractBlockHeight(payload: Record<string, unknown>): number | undefined {
  const meta = asRecord(payload.meta);
  const transactionInfo = asRecord(payload.transactionInfo);
  const height = asRecord(transactionInfo.height);
  return readPositiveNumber(payload.height, meta.height, transactionInfo.height, height.compact);
}

export function normalizeSymbolWebSocketEvent(input: {
  topic: string;
  payload: unknown;
  network: SymbolNetwork;
  observedAt?: string;
}): BlockchainEvent | null {
  const payload = asRecord(input.payload);
  const observedAt = input.observedAt ?? new Date().toISOString();

  if (input.topic.startsWith('unconfirmedAdded/')) {
    const transactionHash = extractTransactionHash(payload);
    if (!transactionHash) return null;
    return {
      transactionHash,
      network: input.network,
      eventType: 'TransactionUnconfirmed',
      signerPublicKey: extractSignerPublicKey(payload),
      observedAt,
    };
  }

  if (input.topic.startsWith('confirmedAdded/')) {
    const transactionHash = extractTransactionHash(payload);
    const blockHeight = extractBlockHeight(payload);
    if (!transactionHash || !blockHeight) return null;
    return {
      transactionHash,
      network: input.network,
      eventType: 'TransactionConfirmed',
      signerPublicKey: extractSignerPublicKey(payload),
      blockHeight,
      observedAt,
    };
  }

  if (input.topic.startsWith('status/')) {
    const transactionHash = readString(payload.hash);
    if (!transactionHash) return null;
    return {
      transactionHash,
      network: input.network,
      eventType: 'TransactionFailed',
      statusCode: readString(payload.code) ?? 'UNKNOWN_STATUS',
      observedAt,
    };
  }

  if (input.topic.startsWith('partialAdded/')) {
    const transactionHash = extractTransactionHash(payload);
    if (!transactionHash) return null;
    return {
      transactionHash,
      network: input.network,
      eventType: 'PartialTransactionAdded',
      signerPublicKey: extractSignerPublicKey(payload),
      observedAt,
    };
  }

  if (input.topic.startsWith('cosignature/')) {
    const transactionHash = readString(payload.parentHash, payload.hash);
    if (!transactionHash) return null;
    return {
      transactionHash,
      network: input.network,
      eventType: 'CosignatureReceived',
      signerPublicKey: readString(payload.signerPublicKey),
      observedAt,
    };
  }

  return null;
}

export function normalizeFinalizedBlockHeight(payload: unknown): number | null {
  const record = asRecord(payload);
  const finalizationPoint = asRecord(record.finalizationPoint);
  const height = readPositiveNumber(record.height, record.finalizedHeight, finalizationPoint.height);
  return height ?? null;
}
