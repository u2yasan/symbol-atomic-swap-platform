import assert from 'node:assert/strict';
import test from 'node:test';
import { ZodError } from 'zod';
import { loadEnvFrom } from './env.js';

const validProductionEnv = {
  NODE_ENV: 'production',
  SYMBOL_ENGINE_API_TOKEN: 'f3b9c1a84e7d42fa9c05b8d63e2a71cb',
  SYMBOL_ENGINE_DATABASE_URL: 'postgresql://drupal:drupal@postgres:5432/drupal',
  SYMBOL_ENGINE_LISTENER_ENABLED: 'false',
  SYMBOL_ENGINE_RECONCILER_ENABLED: 'false',
};

test('loadEnvFrom accepts production environment with required secrets', () => {
  const env = loadEnvFrom(validProductionEnv);

  assert.equal(env.NODE_ENV, 'production');
  assert.equal(env.SYMBOL_ENGINE_API_TOKEN, validProductionEnv.SYMBOL_ENGINE_API_TOKEN);
  assert.equal(env.SYMBOL_ENGINE_DATABASE_URL, validProductionEnv.SYMBOL_ENGINE_DATABASE_URL);
});

test('loadEnvFrom rejects production environment without API token', () => {
  assert.throws(() => loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_ENGINE_API_TOKEN: '',
  }), (error) => {
    assert.ok(error instanceof ZodError);
    assert.match(error.message, /SYMBOL_ENGINE_API_TOKEN is required in production/);
    return true;
  });
});

test('loadEnvFrom rejects placeholder API token', () => {
  assert.throws(() => loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_ENGINE_API_TOKEN: 'replace-with-at-least-32-random-characters',
  }), (error) => {
    assert.ok(error instanceof ZodError);
    assert.match(error.message, /must not use a placeholder value/);
    return true;
  });
});

test('loadEnvFrom rejects repeated production API token patterns', () => {
  assert.throws(() => loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_ENGINE_API_TOKEN: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  }), (error) => {
    assert.ok(error instanceof ZodError);
    assert.match(error.message, /must look randomly generated|must not use a repeated pattern/);
    return true;
  });
});

test('loadEnvFrom rejects common production API token fixture', () => {
  assert.throws(() => loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_ENGINE_API_TOKEN: '0123456789abcdef0123456789abcdef',
  }), (error) => {
    assert.ok(error instanceof ZodError);
    assert.match(error.message, /must not use a placeholder value/);
    return true;
  });
});

test('loadEnvFrom rejects production environment without database URL', () => {
  assert.throws(() => loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_ENGINE_DATABASE_URL: '',
  }), (error) => {
    assert.ok(error instanceof ZodError);
    assert.match(error.message, /SYMBOL_ENGINE_DATABASE_URL is required in production/);
    return true;
  });
});

test('loadEnvFrom rejects insecure production Symbol node URL', () => {
  assert.throws(() => loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_NODE_URL: 'http://symbol-node.example:3000',
  }), (error) => {
    assert.ok(error instanceof ZodError);
    assert.match(error.message, /SYMBOL_NODE_URL must use https in production/);
    return true;
  });
});

test('loadEnvFrom rejects insecure production Symbol WebSocket URL', () => {
  assert.throws(() => loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_WS_URL: 'ws://symbol-node.example:3000/ws',
  }), (error) => {
    assert.ok(error instanceof ZodError);
    assert.match(error.message, /SYMBOL_WS_URL must use wss in production/);
    return true;
  });
});

test('loadEnvFrom accepts secure production Symbol endpoints', () => {
  const env = loadEnvFrom({
    ...validProductionEnv,
    SYMBOL_NODE_URL: 'https://symbol-node.example:3001',
    SYMBOL_WS_URL: 'wss://symbol-node.example:3001/ws',
  });

  assert.equal(env.SYMBOL_NODE_URL, 'https://symbol-node.example:3001');
  assert.equal(env.SYMBOL_WS_URL, 'wss://symbol-node.example:3001/ws');
});
