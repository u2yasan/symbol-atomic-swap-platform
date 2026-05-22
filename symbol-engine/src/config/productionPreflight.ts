import type { EngineEnv } from './env.js';
import WebSocket from 'ws';
import { SymbolRestClient } from '../transaction/symbolRestClient.js';
import { loadNetworkProfilesFromEnv } from './networkProfile.js';

type FetchLike = typeof fetch;
type WebSocketLike = {
  once(event: 'open', listener: () => void): WebSocketLike;
  once(event: 'error', listener: (error: Error) => void): WebSocketLike;
  close(): void;
  terminate?: () => void;
};
type WebSocketConstructor = new (url: string) => WebSocketLike;

export type ProductionPreflightOptions = {
  fetcher?: FetchLike;
  webSocketConstructor?: WebSocketConstructor;
  timeoutMs?: number;
  websocketTimeoutMs?: number;
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

async function verifySymbolWebSocket(
  wsUrl: string,
  webSocketConstructor: WebSocketConstructor,
  timeoutMs: number,
): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    const socket = new webSocketConstructor(wsUrl);
    let settled = false;
    const timeout = setTimeout(() => {
      fail(new Error(`Symbol WebSocket preflight timed out after ${timeoutMs}ms.`));
    }, timeoutMs);

    function finish(): void {
      if (settled) {
        return;
      }
      settled = true;
      clearTimeout(timeout);
      socket.close();
      resolve();
    }

    function fail(error: Error): void {
      if (settled) {
        return;
      }
      settled = true;
      clearTimeout(timeout);
      if (socket.terminate) {
        socket.terminate();
      } else {
        socket.close();
      }
      reject(error);
    }

    socket.once('open', finish);
    socket.once('error', (error) => {
      fail(new Error(`Symbol WebSocket preflight failed: ${error.message}`));
    });
  });
}

export async function runProductionPreflight(
  env: EngineEnv,
  options: ProductionPreflightOptions = {},
): Promise<void> {
  if (env.NODE_ENV !== 'production') {
    return;
  }

  const profiles = loadNetworkProfilesFromEnv(env as unknown as NodeJS.ProcessEnv);
  for (const profile of profiles) {
    if (!profile.nodeUrl) {
      continue;
    }
    const fetcher = withTimeout(options.fetcher ?? fetch, options.timeoutMs ?? 5000);
    const client = new SymbolRestClient(profile.nodeUrl, fetcher);
    const properties = await client.getNetworkProperties();
    if (!properties.networkIdentifier) {
      throw new Error('Symbol node network identifier is missing from /network/properties.');
    }

    if (['mainnet', 'testnet'].includes(profile.key) && properties.networkIdentifier !== profile.key) {
      throw new Error(`Network profile ${profile.key} does not match Symbol node network identifier=${properties.networkIdentifier}.`);
    }

    const nodeInfo = await client.getNodeInfo();
    if (!nodeInfo.networkGenerationHashSeed) {
      throw new Error(`Symbol node ${profile.key} generation hash seed is missing from /node/info.`);
    }
    if (nodeInfo.networkGenerationHashSeed !== profile.generationHashSeed) {
      throw new Error(`Network profile ${profile.key} generation hash seed does not match Symbol node.`);
    }
  }

  if (env.SYMBOL_ENGINE_LISTENER_ENABLED) {
    if (!env.SYMBOL_WS_URL) {
      throw new Error('SYMBOL_WS_URL is required when SYMBOL_ENGINE_LISTENER_ENABLED=true in production.');
    }

    await verifySymbolWebSocket(
      env.SYMBOL_WS_URL,
      options.webSocketConstructor ?? WebSocket,
      options.websocketTimeoutMs ?? 5000,
    );
  }
}
