import type { EngineEnv } from './env.js';
import { SymbolRestClient } from '../transaction/symbolRestClient.js';

type FetchLike = typeof fetch;

export type ProductionPreflightOptions = {
  fetcher?: FetchLike;
  timeoutMs?: number;
};

function withTimeout(fetcher: FetchLike, timeoutMs: number): FetchLike {
  return async (input, init) => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      return await fetcher(input, {
        ...init,
        signal: controller.signal,
      });
    } finally {
      clearTimeout(timeout);
    }
  };
}

export async function runProductionPreflight(
  env: EngineEnv,
  options: ProductionPreflightOptions = {},
): Promise<void> {
  if (env.NODE_ENV !== 'production' || !env.SYMBOL_NODE_URL) {
    return;
  }

  const fetcher = withTimeout(options.fetcher ?? fetch, options.timeoutMs ?? 5000);
  const client = new SymbolRestClient(env.SYMBOL_NODE_URL, fetcher);
  const properties = await client.getNetworkProperties();
  if (!properties.networkIdentifier) {
    throw new Error('Symbol node network identifier is missing from /network/properties.');
  }

  if (properties.networkIdentifier !== env.SYMBOL_NETWORK) {
    throw new Error(`SYMBOL_NETWORK=${env.SYMBOL_NETWORK} does not match Symbol node network identifier=${properties.networkIdentifier}.`);
  }
}
