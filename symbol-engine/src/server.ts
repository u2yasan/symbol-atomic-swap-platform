import Fastify from 'fastify';
import { createApiAuthHook } from './api/auth.js';
import { handleApiError } from './api/errorHandler.js';
import { registerRoutes } from './api/routes.js';
import { createLoggerOptions, DEFAULT_BODY_LIMIT_BYTES, registerSecurity } from './api/security.js';
import { loadEnv } from './config/env.js';
import { runProductionPreflight } from './config/productionPreflight.js';
import { createDatabase } from './db/pool.js';
import { runMigrations } from './db/migrations.js';
import { SwapIntentRepository } from './repository/swapIntentRepository.js';
import { EventRepository } from './repository/eventRepository.js';
import { ProjectionRepository } from './repository/projectionRepository.js';
import { SymbolListener } from './listener/symbolListener.js';
import { TransactionReconciler } from './worker/transactionReconciler.js';

const env = loadEnv();
await runProductionPreflight(env);
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

await registerSecurity(app);

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
  return {
    network: env.SYMBOL_NETWORK,
    nodeUrl: env.SYMBOL_NODE_URL ?? null,
    wsUrl: env.SYMBOL_WS_URL ?? null,
  };
});

await registerRoutes(app, {
  nodeUrl: env.SYMBOL_NODE_URL,
  nodeRequestTimeoutMs: env.SYMBOL_NODE_REQUEST_TIMEOUT_MS,
  repositories,
});

let listener: SymbolListener | null = null;
let reconciler: TransactionReconciler | null = null;
if (env.SYMBOL_ENGINE_LISTENER_ENABLED) {
  if (!env.SYMBOL_WS_URL) {
    throw new Error('SYMBOL_WS_URL is required when SYMBOL_ENGINE_LISTENER_ENABLED=true.');
  }

  listener = new SymbolListener({
    wsUrl: env.SYMBOL_WS_URL,
    network: env.SYMBOL_NETWORK,
    addresses: env.SYMBOL_ENGINE_LISTENER_ADDRESSES,
    repositories,
    logger: app.log,
  });
  listener.start();
}

if (env.SYMBOL_ENGINE_RECONCILER_ENABLED) {
  if (!env.SYMBOL_NODE_URL) {
    throw new Error('SYMBOL_NODE_URL is required when SYMBOL_ENGINE_RECONCILER_ENABLED=true.');
  }

  reconciler = new TransactionReconciler({
    network: env.SYMBOL_NETWORK,
    nodeUrl: env.SYMBOL_NODE_URL,
    intervalMs: env.SYMBOL_ENGINE_RECONCILER_INTERVAL_MS,
    repositories,
    logger: app.log,
  });
  reconciler.start();
}

const shutdown = async (): Promise<void> => {
  listener?.stop();
  reconciler?.stop();
  await db.end();
};

process.once('SIGINT', () => {
  void shutdown().finally(() => process.exit(0));
});

process.once('SIGTERM', () => {
  void shutdown().finally(() => process.exit(0));
});

try {
  await app.listen({ port: env.SYMBOL_ENGINE_PORT, host: '0.0.0.0' });
} catch (error) {
  app.log.error(error);
  process.exit(1);
}
