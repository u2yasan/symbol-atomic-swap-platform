import assert from 'node:assert/strict';
import test from 'node:test';
import { handleShutdownSignal, shutdownSymbolEngine } from '../serverLifecycle.js';

test('shutdownSymbolEngine stops workers before closing HTTP server and database', async () => {
  const calls: string[] = [];

  await shutdownSymbolEngine({
    listener: {
      stop: () => calls.push('listener.stop'),
    },
    reconciler: {
      stop: () => calls.push('reconciler.stop'),
    },
    app: {
      close: async () => {
        calls.push('app.close');
      },
    },
    db: {
      end: async () => {
        calls.push('db.end');
      },
    },
  });

  assert.deepEqual(calls, [
    'listener.stop',
    'reconciler.stop',
    'app.close',
    'db.end',
  ]);
});

test('shutdownSymbolEngine closes database even when HTTP close fails', async () => {
  const calls: string[] = [];

  await assert.rejects(() => shutdownSymbolEngine({
    app: {
      close: async () => {
        calls.push('app.close');
        throw new Error('close failed');
      },
    },
    db: {
      end: async () => {
        calls.push('db.end');
      },
    },
  }), /close failed/);

  assert.deepEqual(calls, [
    'app.close',
    'db.end',
  ]);
});

test('handleShutdownSignal exits with zero after successful shutdown', async () => {
  const exitCodes: number[] = [];
  const errors: unknown[] = [];

  handleShutdownSignal({
    shutdown: async () => undefined,
    logError: (error) => errors.push(error),
    exit: (code) => exitCodes.push(code),
  });

  await new Promise((resolve) => setTimeout(resolve, 0));

  assert.deepEqual(exitCodes, [0]);
  assert.deepEqual(errors, []);
});

test('handleShutdownSignal logs and exits nonzero after failed shutdown', async () => {
  const exitCodes: number[] = [];
  const errors: unknown[] = [];
  const failure = new Error('shutdown failed');

  handleShutdownSignal({
    shutdown: async () => {
      throw failure;
    },
    logError: (error) => errors.push(error),
    exit: (code) => exitCodes.push(code),
  });

  await new Promise((resolve) => setTimeout(resolve, 0));

  assert.deepEqual(exitCodes, [1]);
  assert.deepEqual(errors, [failure]);
});
