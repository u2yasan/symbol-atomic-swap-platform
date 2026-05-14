import assert from 'node:assert/strict';
import test from 'node:test';
import Fastify from 'fastify';
import pino from 'pino';
import { createApiAuthHook } from './auth.js';
import { handleApiError } from './errorHandler.js';
import {
  createLoggerOptions,
  DEFAULT_BODY_LIMIT_BYTES,
  registerSecurity,
  SMALL_BODY_LIMIT_BYTES,
} from './security.js';
import { SymbolNodeUnavailableError } from '../transaction/symbolNodeErrors.js';

const token = '0123456789abcdef0123456789abcdef';

async function makeApp() {
  const app = Fastify({
    bodyLimit: DEFAULT_BODY_LIMIT_BYTES,
    logger: false,
  });
  await registerSecurity(app);
  app.setErrorHandler(handleApiError);

  app.get('/health', {
    config: {
      rateLimit: false,
    },
  }, async () => ({ status: 'ok' }));

  app.addHook('preHandler', createApiAuthHook(token));

  app.post('/protected', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 1,
        timeWindow: '1 minute',
      },
    },
  }, async (request) => request.body);

  app.post('/missing-node-url', async () => {
    throw new SymbolNodeUnavailableError('SYMBOL_NODE_URL is required for transaction announcement.');
  });

  return app;
}

test('protected API routes fail closed without a token', async () => {
  const app = await makeApp();

  const response = await app.inject({
    method: 'POST',
    url: '/protected',
    payload: { ok: true },
  });

  assert.equal(response.statusCode, 401);
  assert.equal(response.json().error, 'unauthorized');
  await app.close();
});

test('protected API routes reject invalid bearer tokens regardless of length', async () => {
  const app = await makeApp();

  const shortToken = await app.inject({
    method: 'POST',
    url: '/protected',
    headers: {
      authorization: 'Bearer wrong',
    },
    payload: { ok: true },
  });
  const sameLengthToken = await app.inject({
    method: 'POST',
    url: '/protected',
    headers: {
      authorization: `Bearer ${'f'.repeat(token.length)}`,
    },
    payload: { ok: true },
  });

  assert.equal(shortToken.statusCode, 401);
  assert.equal(shortToken.json().error, 'unauthorized');
  assert.equal(sameLengthToken.statusCode, 401);
  assert.equal(sameLengthToken.json().error, 'unauthorized');
  await app.close();
});

test('oversized request bodies are rejected before handlers run', async () => {
  const app = await makeApp();

  const response = await app.inject({
    method: 'POST',
    url: '/protected',
    headers: {
      authorization: `Bearer ${token}`,
      'content-type': 'application/json',
    },
    payload: JSON.stringify({ payload: 'A'.repeat(SMALL_BODY_LIMIT_BYTES + 1) }),
  });

  assert.equal(response.statusCode, 413);
  await app.close();
});

test('rate limit is enforced per API token and excludes health', async () => {
  const app = await makeApp();
  const headers = {
    authorization: `Bearer ${token}`,
  };

  const first = await app.inject({
    method: 'POST',
    url: '/protected',
    headers,
    payload: { ok: true },
  });
  const second = await app.inject({
    method: 'POST',
    url: '/protected',
    headers,
    payload: { ok: true },
  });
  const health = await app.inject({
    method: 'GET',
    url: '/health',
  });

  assert.equal(first.statusCode, 200);
  assert.equal(second.statusCode, 429);
  assert.equal(second.json().error, 'rate_limit_exceeded');
  assert.equal(health.statusCode, 200);
  await app.close();
});

test('security headers are set on responses', async () => {
  const app = await makeApp();

  const response = await app.inject({
    method: 'GET',
    url: '/health',
  });

  assert.equal(response.headers['x-content-type-options'], 'nosniff');
  assert.equal(response.headers['x-frame-options'], 'DENY');
  assert.equal(response.headers['x-powered-by'], undefined);
  await app.close();
});

test('missing Symbol node URL returns service unavailable instead of internal error', async () => {
  const app = await makeApp();

  const response = await app.inject({
    method: 'POST',
    url: '/missing-node-url',
    headers: {
      authorization: `Bearer ${token}`,
    },
  });

  assert.equal(response.statusCode, 503);
  assert.equal(response.json().error, 'symbol_node_unavailable');
  await app.close();
});

test('logger options redact tokens and transaction payloads', () => {
  let output = '';
  const stream = {
    write(chunk: string) {
      output += chunk;
    },
  };
  const logger = pino(createLoggerOptions(), stream);

  logger.info({
    req: {
      headers: {
        authorization: `Bearer ${token}`,
        'x-symbol-engine-token': token,
      },
      body: {
        payload: 'ABCD'.repeat(64),
      },
    },
    body: {
      signedPayload: 'DCBA'.repeat(64),
      unsignedPayload: '1234'.repeat(64),
      qrPayload: {
        unsignedPayload: '5678'.repeat(64),
      },
    },
    SYMBOL_ENGINE_API_TOKEN: token,
    SYMBOL_ENGINE_DATABASE_URL: 'postgresql://user:password@example.test/db',
  }, 'redaction-test');

  assert.doesNotMatch(output, new RegExp(token));
  assert.doesNotMatch(output, /ABCDABCD/);
  assert.doesNotMatch(output, /DCBADCBA/);
  assert.doesNotMatch(output, /12341234/);
  assert.doesNotMatch(output, /56785678/);
  assert.doesNotMatch(output, /password@example/);
});
