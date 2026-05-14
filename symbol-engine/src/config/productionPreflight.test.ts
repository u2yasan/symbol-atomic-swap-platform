import assert from 'node:assert/strict';
import test from 'node:test';
import type { EngineEnv } from './env.js';
import { runProductionPreflight } from './productionPreflight.js';

const productionEnv: EngineEnv = {
  NODE_ENV: 'production',
  SYMBOL_ENGINE_PORT: 3000,
  SYMBOL_ENGINE_API_TOKEN: 'f3b9c1a84e7d42fa9c05b8d63e2a71cb',
  SYMBOL_ENGINE_DATABASE_URL: 'postgresql://drupal:drupal@postgres:5432/drupal',
  SYMBOL_ENGINE_LISTENER_ENABLED: false,
  SYMBOL_ENGINE_LISTENER_ADDRESSES: [],
  SYMBOL_ENGINE_RECONCILER_ENABLED: false,
  SYMBOL_ENGINE_RECONCILER_INTERVAL_MS: 30000,
  SYMBOL_NODE_REQUEST_TIMEOUT_MS: 10000,
  SYMBOL_NETWORK: 'testnet',
  SYMBOL_NODE_URL: 'https://symbol-node.example:3001',
  SYMBOL_WS_URL: undefined,
};

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: {
      'content-type': 'application/json',
    },
    ...init,
  });
}

class OpeningWebSocket {
  public static openedUrl = '';
  public closed = false;
  private openListener: (() => void) | null = null;
  private errorListener: ((error: Error) => void) | null = null;

  public constructor(url: string) {
    OpeningWebSocket.openedUrl = url;
    setTimeout(() => this.openListener?.(), 0);
  }

  public once(event: 'open' | 'error', listener: (() => void) | ((error: Error) => void)): this {
    if (event === 'open') {
      this.openListener = listener as () => void;
    } else {
      this.errorListener = listener as (error: Error) => void;
    }
    return this;
  }

  public close(): void {
    this.closed = true;
  }
}

class FailingWebSocket {
  private openListener: (() => void) | null = null;
  private errorListener: ((error: Error) => void) | null = null;

  public constructor(_url: string) {
    setTimeout(() => this.errorListener?.(new Error('handshake rejected')), 0);
  }

  public once(event: 'open' | 'error', listener: (() => void) | ((error: Error) => void)): this {
    if (event === 'open') {
      this.openListener = listener as () => void;
    } else {
      this.errorListener = listener as (error: Error) => void;
    }
    return this;
  }

  public close(): void {}

  public terminate(): void {}
}

class HangingWebSocket {
  public constructor(_url: string) {}

  public once(_event: 'open' | 'error', _listener: (() => void) | ((error: Error) => void)): this {
    return this;
  }

  public close(): void {}

  public terminate(): void {}
}

test('runProductionPreflight skips non-production environments', async () => {
  let called = false;
  await runProductionPreflight({
    ...productionEnv,
    NODE_ENV: 'development',
  }, {
    fetcher: async () => {
      called = true;
      return jsonResponse({});
    },
  });

  assert.equal(called, false);
});

test('runProductionPreflight skips production network check without node URL', async () => {
  let called = false;
  await runProductionPreflight({
    ...productionEnv,
    SYMBOL_NODE_URL: undefined,
  }, {
    fetcher: async () => {
      called = true;
      return jsonResponse({});
    },
  });

  assert.equal(called, false);
});

test('runProductionPreflight accepts matching Symbol network identifier', async () => {
  let requestedUrl = '';
  let signalConfigured = false;
  await runProductionPreflight(productionEnv, {
    fetcher: async (input, init) => {
      requestedUrl = String(input);
      signalConfigured = init?.signal instanceof AbortSignal;
      return jsonResponse({
        network: {
          identifier: 'testnet',
        },
      });
    },
  });

  assert.equal(requestedUrl, 'https://symbol-node.example:3001/network/properties');
  assert.equal(signalConfigured, true);
});

test('runProductionPreflight rejects mismatched Symbol network identifier', async () => {
  await assert.rejects(() => runProductionPreflight(productionEnv, {
    fetcher: async () => jsonResponse({
      network: {
        identifier: 'mainnet',
      },
    }),
  }), /SYMBOL_NETWORK=testnet does not match Symbol node network identifier=mainnet/);
});

test('runProductionPreflight rejects missing Symbol network identifier', async () => {
  await assert.rejects(() => runProductionPreflight(productionEnv, {
    fetcher: async () => jsonResponse({
      network: {},
    }),
  }), /Symbol node network identifier is missing/);
});

test('runProductionPreflight rejects failed network properties lookup', async () => {
  await assert.rejects(() => runProductionPreflight(productionEnv, {
    fetcher: async () => jsonResponse({
      error: 'unavailable',
    }, {
      status: 503,
    }),
  }), /network properties lookup failed: 503/);
});

test('runProductionPreflight accepts reachable Symbol WebSocket when listener is enabled', async () => {
  OpeningWebSocket.openedUrl = '';
  await runProductionPreflight({
    ...productionEnv,
    SYMBOL_NODE_URL: undefined,
    SYMBOL_ENGINE_LISTENER_ENABLED: true,
    SYMBOL_WS_URL: 'wss://symbol-node.example:3001/ws',
  }, {
    webSocketConstructor: OpeningWebSocket,
  });

  assert.equal(OpeningWebSocket.openedUrl, 'wss://symbol-node.example:3001/ws');
});

test('runProductionPreflight rejects missing production Symbol WebSocket URL when listener is enabled', async () => {
  await assert.rejects(() => runProductionPreflight({
    ...productionEnv,
    SYMBOL_NODE_URL: undefined,
    SYMBOL_ENGINE_LISTENER_ENABLED: true,
    SYMBOL_WS_URL: undefined,
  }), /SYMBOL_WS_URL is required when SYMBOL_ENGINE_LISTENER_ENABLED=true in production/);
});

test('runProductionPreflight rejects failing production Symbol WebSocket handshake', async () => {
  await assert.rejects(() => runProductionPreflight({
    ...productionEnv,
    SYMBOL_NODE_URL: undefined,
    SYMBOL_ENGINE_LISTENER_ENABLED: true,
    SYMBOL_WS_URL: 'wss://symbol-node.example:3001/ws',
  }, {
    webSocketConstructor: FailingWebSocket,
  }), /Symbol WebSocket preflight failed: handshake rejected/);
});

test('runProductionPreflight rejects timed out production Symbol WebSocket handshake', async () => {
  await assert.rejects(() => runProductionPreflight({
    ...productionEnv,
    SYMBOL_NODE_URL: undefined,
    SYMBOL_ENGINE_LISTENER_ENABLED: true,
    SYMBOL_WS_URL: 'wss://symbol-node.example:3001/ws',
  }, {
    webSocketConstructor: HangingWebSocket,
    websocketTimeoutMs: 1,
  }), /Symbol WebSocket preflight timed out after 1ms/);
});
