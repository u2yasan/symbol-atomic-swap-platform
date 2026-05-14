type FetchLike = typeof fetch;

export type SymbolTransactionLookup = {
  found: boolean;
  transactionHash: string;
  blockHeight?: number;
  raw?: unknown;
};

export type SymbolStatusLookup = {
  found: boolean;
  transactionHash: string;
  code?: string;
  raw?: unknown;
};

export type SymbolNetworkProperties = {
  networkIdentifier?: string;
  raw: unknown;
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

async function readJson(response: Response): Promise<unknown> {
  return response.json().catch(() => null);
}

export class SymbolRestClient {
  public constructor(
    private readonly nodeUrl: string,
    private readonly fetcher: FetchLike = fetch,
  ) {}

  public async getConfirmedTransaction(transactionHash: string): Promise<SymbolTransactionLookup> {
    const response = await this.fetcher(new URL(`/transactions/confirmed/${transactionHash}`, this.nodeUrl));
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
      ...(blockHeight ? { blockHeight } : {}),
      raw,
    };
  }

  public async getUnconfirmedTransaction(transactionHash: string): Promise<SymbolTransactionLookup> {
    const response = await this.fetcher(new URL(`/transactions/unconfirmed/${transactionHash}`, this.nodeUrl));
    if (response.status === 404) {
      return { found: false, transactionHash };
    }
    if (!response.ok) {
      throw new Error(`unconfirmed transaction lookup failed: ${response.status}`);
    }

    return {
      found: true,
      transactionHash,
      raw: await readJson(response),
    };
  }

  public async getTransactionStatus(transactionHash: string): Promise<SymbolStatusLookup> {
    const response = await this.fetcher(new URL(`/transactionStatus/${transactionHash}`, this.nodeUrl));
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
      raw,
    };
  }

  public async getFinalizedHeight(): Promise<number | null> {
    const response = await this.fetcher(new URL('/chain/info', this.nodeUrl));
    if (!response.ok) {
      throw new Error(`chain info lookup failed: ${response.status}`);
    }

    return extractChainFinalizedHeight(await readJson(response));
  }

  public async getNetworkProperties(): Promise<SymbolNetworkProperties> {
    const response = await this.fetcher(new URL('/network/properties', this.nodeUrl));
    if (!response.ok) {
      throw new Error(`network properties lookup failed: ${response.status}`);
    }

    const raw = await readJson(response);
    const networkIdentifier = extractNetworkIdentifier(raw);
    return {
      raw,
      ...(networkIdentifier ? { networkIdentifier } : {}),
    };
  }
}
