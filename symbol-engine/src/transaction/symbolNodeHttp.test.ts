import assert from 'node:assert/strict';
import test from 'node:test';
import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';
import { putJsonToSymbolNode } from './symbolNodeHttp.js';

test('putJsonToSymbolNode sends JSON PUT with abort signal', async () => {
  let requestedUrl = '';
  let requestedInit: RequestInit | undefined;
  const response = await putJsonToSymbolNode('https://node.example.test', '/transactions', {
    payload: 'ABCD',
  }, {
    fetcher: async (input, init) => {
      requestedUrl = String(input);
      requestedInit = init;
      return new Response(JSON.stringify({ message: 'ok' }), {
        status: 202,
        headers: {
          'content-type': 'application/json',
        },
      });
    },
    timeoutMs: 5000,
  });

  assert.equal(response.status, 202);
  assert.equal(requestedUrl, 'https://node.example.test/transactions');
  assert.equal(requestedInit?.method, 'PUT');
  assert.equal((requestedInit?.headers as Record<string, string>)['content-type'], 'application/json');
  assert.equal(requestedInit?.body, JSON.stringify({ payload: 'ABCD' }));
  assert.ok(requestedInit?.signal instanceof AbortSignal);
});

test('putJsonToSymbolNode converts timeout to Symbol node unavailable error', async () => {
  await assert.rejects(() => putJsonToSymbolNode('https://node.example.test', '/transactions', {
    payload: 'ABCD',
  }, {
    fetcher: async (_input, init) => new Promise<Response>((_resolve, reject) => {
      init?.signal?.addEventListener('abort', () => {
        reject(new Error('aborted'));
      });
    }),
    timeoutMs: 1,
  }), (error) => {
    assert.ok(error instanceof SymbolNodeUnavailableError);
    assert.equal(error.statusCode, 503);
    assert.match(error.message, /timed out after 1ms/);
    return true;
  });
});

test('putJsonToSymbolNode converts fetch failure to Symbol node unavailable error', async () => {
  const cause = new Error('connection reset from https://node.example.test');

  await assert.rejects(() => putJsonToSymbolNode('https://node.example.test', '/transactions', {
    payload: 'ABCD',
  }, {
    fetcher: async () => {
      throw cause;
    },
  }), (error) => {
    assert.ok(error instanceof SymbolNodeUnavailableError);
    assert.equal(error.statusCode, 503);
    assert.equal(error.message, 'Symbol node request failed.');
    assert.equal(error.cause, cause);
    return true;
  });
});
