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
