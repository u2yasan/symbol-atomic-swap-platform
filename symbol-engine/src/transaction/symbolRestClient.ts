import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';

type FetchLike = typeof fetch;

export type SymbolTransactionLookup = {
  found: boolean;
  transactionHash: string;
  blockHeight?: number;
};

export type SymbolConfirmedTransactionDetails = SymbolTransactionLookup & {
  raw?: unknown;
};

export type SymbolStatusLookup = {
  found: boolean;
  transactionHash: string;
  code?: string;
};

export type SymbolNetworkProperties = {
  networkIdentifier?: string;
};

export type SymbolAccountPublicKeyLookup = {
  found: boolean;
  address: string;
  publicKey?: string;
};

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

function extractHeight(payload: unknown): number | undefined {
  const record = asRecord(payload);
  const meta = asRecord(record.meta);
  const transactionInfo = asRecord(record.transactionInfo);
  const height = asRecord(transactionInfo.height);
  return readPositiveNumber(record.height, meta.height, transactionInfo.height, height.compact);
}

function extractChainFinalizedHeight(payload: unknown): number | null {
  const record = asRecord(payload);
  const latestFinalizedBlock = asRecord(record.latestFinalizedBlock);
  const height = asRecord(latestFinalizedBlock.height);
  return readPositiveNumber(record.finalizedHeight, latestFinalizedBlock.height, height.compact) ?? null;
}

function extractNetworkIdentifier(payload: unknown): string | undefined {
  const record = asRecord(payload);
  const network = asRecord(record.network);
  return readString(network.identifier, record.identifier);
}

function extractAccountPublicKey(payload: unknown): string | undefined {
  const record = asRecord(payload);
  const account = asRecord(record.account);
  const publicKey = readString(account.publicKey, record.publicKey)?.toUpperCase();
  if (!publicKey || !/^[0-9A-F]{64}$/.test(publicKey) || /^0+$/.test(publicKey)) {
    return undefined;
  }
  return publicKey;
}

async function readJson(response: Response): Promise<unknown> {
  return response.json().catch(() => null);
}

export class SymbolRestClient {
  public constructor(
    private readonly nodeUrl: string,
    private readonly fetcher: FetchLike = fetch,
    private readonly requestTimeoutMs = 10000,
  ) {}

  private async request(path: string): Promise<Response> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.requestTimeoutMs);

    try {
      return await this.fetcher(new URL(path, this.nodeUrl), {
        signal: controller.signal,
      });
    } catch (error) {
      if (controller.signal.aborted) {
        throw new SymbolNodeUnavailableError(`Symbol node request timed out after ${this.requestTimeoutMs}ms.`);
      }

      throw new SymbolNodeUnavailableError('Symbol node request failed.', { cause: error });
    } finally {
      clearTimeout(timeout);
    }
  }

  public async getConfirmedTransaction(transactionHash: string): Promise<SymbolTransactionLookup> {
    const details = await this.getConfirmedTransactionDetails(transactionHash);
    const { raw: _raw, ...lookup } = details;
    return lookup;
  }

  public async getConfirmedTransactionDetails(transactionHash: string): Promise<SymbolConfirmedTransactionDetails> {
    const response = await this.request(`/transactions/confirmed/${transactionHash}`);
    if (response.status === 404) {
      return { found: false, transactionHash };
    }
    if (!response.ok) {
      throw new Error(`confirmed transaction lookup failed: ${response.status}`);
    }

    const raw = await readJson(response);
    const blockHeight = extractHeight(raw);
    return {
      found: true,
      transactionHash,
      raw,
      ...(blockHeight ? { blockHeight } : {}),
    };
  }

  public async getUnconfirmedTransaction(transactionHash: string): Promise<SymbolTransactionLookup> {
    const response = await this.request(`/transactions/unconfirmed/${transactionHash}`);
    if (response.status === 404) {
      return { found: false, transactionHash };
    }
    if (!response.ok) {
      throw new Error(`unconfirmed transaction lookup failed: ${response.status}`);
    }

    return {
      found: true,
      transactionHash,
    };
  }

  public async getTransactionStatus(transactionHash: string): Promise<SymbolStatusLookup> {
    const response = await this.request(`/transactionStatus/${transactionHash}`);
    if (response.status === 404) {
      return { found: false, transactionHash };
    }
    if (!response.ok) {
      throw new Error(`transaction status lookup failed: ${response.status}`);
    }

    const raw = await readJson(response);
    const record = asRecord(raw);
    const code = readString(record.code);
    return {
      found: true,
      transactionHash: readString(record.hash) ?? transactionHash,
      ...(code ? { code } : {}),
    };
  }

  public async getFinalizedHeight(): Promise<number | null> {
    const response = await this.request('/chain/info');
    if (!response.ok) {
      throw new Error(`chain info lookup failed: ${response.status}`);
    }

    return extractChainFinalizedHeight(await readJson(response));
  }

  public async getNetworkProperties(): Promise<SymbolNetworkProperties> {
    const response = await this.request('/network/properties');
    if (!response.ok) {
      throw new Error(`network properties lookup failed: ${response.status}`);
    }

    const raw = await readJson(response);
    const networkIdentifier = extractNetworkIdentifier(raw);
    return {
      ...(networkIdentifier ? { networkIdentifier } : {}),
    };
  }

  public async getAccountPublicKey(address: string): Promise<SymbolAccountPublicKeyLookup> {
    const normalizedAddress = address.toUpperCase();
    const response = await this.request(`/accounts/${normalizedAddress}`);
    if (response.status === 404) {
      return { found: false, address: normalizedAddress };
    }
    if (!response.ok) {
      throw new Error(`account lookup failed: ${response.status}`);
    }

    const publicKey = extractAccountPublicKey(await readJson(response));
    return {
      found: Boolean(publicKey),
      address: normalizedAddress,
      ...(publicKey ? { publicKey } : {}),
    };
  }
}
