import Fastify from 'fastify';
import { createApiAuthHook } from './api/auth.js';
import { handleApiError } from './api/errorHandler.js';
import { networkResponse } from './api/network.js';
import { registerRoutes } from './api/routes.js';
import { createLoggerOptions, DEFAULT_BODY_LIMIT_BYTES, registerSecurity } from './api/security.js';
import { loadEnv } from './config/env.js';
import { findNetworkProfile, loadNetworkProfilesFromEnv } from './config/networkProfile.js';
import { runProductionPreflight } from './config/productionPreflight.js';
import { createDatabase } from './db/pool.js';
import { runMigrations } from './db/migrations.js';
import { SwapIntentRepository } from './repository/swapIntentRepository.js';
import { EventRepository } from './repository/eventRepository.js';
import { ProjectionRepository } from './repository/projectionRepository.js';
import { SymbolListener } from './listener/symbolListener.js';
import { TransactionReconciler } from './worker/transactionReconciler.js';
import { handleShutdownSignal, shutdownSymbolEngine } from './serverLifecycle.js';

const env = loadEnv();
await runProductionPreflight(env);
const networkProfiles = loadNetworkProfilesFromEnv(env as unknown as NodeJS.ProcessEnv);
const activeProfile = findNetworkProfile(env.SYMBOL_NETWORK, networkProfiles);
const db = createDatabase(env);
await runMigrations(db);

const repositories = {
  swapIntents: new SwapIntentRepository(db),
  events: new EventRepository(db),
  projections: new ProjectionRepository(db),
};

const app = Fastify({
  bodyLimit: DEFAULT_BODY_LIMIT_BYTES,
  logger: createLoggerOptions(),
});

await registerSecurity(app, env.SYMBOL_ENGINE_API_TOKEN, env.SYMBOL_ENGINE_RATE_LIMIT_MAX);

app.setErrorHandler(handleApiError);

app.get('/health', {
  config: {
    rateLimit: false,
  },
}, async () => {
  return {
    status: 'ok',
    service: 'symbol-engine',
    network: env.SYMBOL_NETWORK,
  };
});

app.addHook('preHandler', createApiAuthHook(env.SYMBOL_ENGINE_API_TOKEN));

app.get('/v1/network', async () => {
  return networkResponse({
    network: env.SYMBOL_NETWORK,
    exposeNodeEndpoints: env.SYMBOL_ENGINE_EXPOSE_NODE_ENDPOINTS,
    nodeUrl: activeProfile.nodeUrl ?? env.SYMBOL_NODE_URL,
    wsUrl: activeProfile.wsUrl ?? env.SYMBOL_WS_URL,
    profiles: networkProfiles,
  });
});

await registerRoutes(app, {
  network: env.SYMBOL_NETWORK,
  nodeUrl: activeProfile.nodeUrl ?? env.SYMBOL_NODE_URL,
  nodeRequestTimeoutMs: env.SYMBOL_NODE_REQUEST_TIMEOUT_MS,
  profiles: networkProfiles,
  repositories,
});

let listener: SymbolListener | null = null;
let reconciler: TransactionReconciler | null = null;
if (env.SYMBOL_ENGINE_LISTENER_ENABLED) {
  if (!(activeProfile.wsUrl ?? env.SYMBOL_WS_URL)) {
    throw new Error('SYMBOL_WS_URL is required when SYMBOL_ENGINE_LISTENER_ENABLED=true.');
  }

  listener = new SymbolListener({
    wsUrl: activeProfile.wsUrl ?? env.SYMBOL_WS_URL!,
    network: env.SYMBOL_NETWORK,
    addresses: env.SYMBOL_ENGINE_LISTENER_ADDRESSES,
    repositories,
    logger: app.log,
  });
  listener.start();
}

if (env.SYMBOL_ENGINE_RECONCILER_ENABLED) {
  if (!(activeProfile.nodeUrl ?? env.SYMBOL_NODE_URL)) {
    throw new Error('SYMBOL_NODE_URL is required when SYMBOL_ENGINE_RECONCILER_ENABLED=true.');
  }

  reconciler = new TransactionReconciler({
    network: env.SYMBOL_NETWORK,
    nodeUrl: activeProfile.nodeUrl ?? env.SYMBOL_NODE_URL!,
    nodeRequestTimeoutMs: env.SYMBOL_NODE_REQUEST_TIMEOUT_MS,
    intervalMs: env.SYMBOL_ENGINE_RECONCILER_INTERVAL_MS,
    repositories,
    logger: app.log,
  });
  reconciler.start();
}

const shutdown = async (): Promise<void> => {
  await shutdownSymbolEngine({
    app,
    db,
    listener,
    reconciler,
  });
};

process.once('SIGINT', () => {
  handleShutdownSignal({
    shutdown,
    logError: (error) => app.log.error(error),
    exit: (code) => process.exit(code),
  });
});

process.once('SIGTERM', () => {
  handleShutdownSignal({
    shutdown,
    logError: (error) => app.log.error(error),
    exit: (code) => process.exit(code),
  });
});

try {
  await app.listen({ port: env.SYMBOL_ENGINE_PORT, host: '0.0.0.0' });
} catch (error) {
  app.log.error(error);
  process.exit(1);
}
