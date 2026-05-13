import Fastify from 'fastify';
import { ZodError } from 'zod';
import { createApiAuthHook } from './api/auth.js';
import { registerRoutes } from './api/routes.js';
import { loadEnv } from './config/env.js';
import { InvalidStateTransitionError } from './listener/eventDispatcher.js';
import { createDatabase } from './db/pool.js';
import { runMigrations } from './db/migrations.js';
import { SwapIntentRepository } from './repository/swapIntentRepository.js';
import { EventRepository } from './repository/eventRepository.js';
import { ProjectionRepository } from './repository/projectionRepository.js';
import { InvalidAnnouncementError } from './transaction/announceService.js';
import { SymbolListener } from './listener/symbolListener.js';
import { TransactionReconciler } from './worker/transactionReconciler.js';

const env = loadEnv();
const db = createDatabase(env);
await runMigrations(db);

const repositories = {
  swapIntents: new SwapIntentRepository(db),
  events: new EventRepository(db),
  projections: new ProjectionRepository(db),
};

const app = Fastify({
  logger: true,
});

app.setErrorHandler((error, _request, reply) => {
  if (error instanceof ZodError) {
    return reply.code(400).send({
      error: 'validation_failed',
      issues: error.issues,
    });
  }

  if (error instanceof InvalidStateTransitionError) {
    return reply.code(error.statusCode).send({
      error: 'invalid_state_transition',
      message: error.message,
    });
  }

  if (error instanceof InvalidAnnouncementError) {
    return reply.code(error.statusCode).send({
      error: 'invalid_announcement',
      message: error.message,
    });
  }

  app.log.error(error);
  return reply.code(500).send({
    error: 'internal_error',
  });
});

app.get('/health', async () => {
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
