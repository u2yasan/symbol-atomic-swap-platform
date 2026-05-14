import assert from 'node:assert/strict';
import test from 'node:test';
import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';
import { SymbolRestClient } from './symbolRestClient.js';

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: {
      'content-type': 'application/json',
    },
    ...init,
  });
}

test('SymbolRestClient sends REST requests with abort signal', async () => {
  let requestedUrl = '';
  let signalConfigured = false;
  const client = new SymbolRestClient('https://node.example.test', async (input, init) => {
    requestedUrl = String(input);
    signalConfigured = init?.signal instanceof AbortSignal;
    return jsonResponse({
      latestFinalizedBlock: {
        height: 123,
      },
    });
  }, 5000);

  const finalizedHeight = await client.getFinalizedHeight();

  assert.equal(finalizedHeight, 123);
  assert.equal(requestedUrl, 'https://node.example.test/chain/info');
  assert.equal(signalConfigured, true);
});

test('SymbolRestClient converts timeout to Symbol node unavailable error', async () => {
  const client = new SymbolRestClient('https://node.example.test', async (_input, init) => new Promise<Response>((_resolve, reject) => {
    init?.signal?.addEventListener('abort', () => {
      reject(new Error('aborted'));
    });
  }), 1);

  await assert.rejects(() => client.getFinalizedHeight(), (error) => {
    assert.ok(error instanceof SymbolNodeUnavailableError);
    assert.equal(error.statusCode, 503);
    assert.match(error.message, /timed out after 1ms/);
    return true;
  });
});

test('SymbolRestClient converts transport failure to Symbol node unavailable error', async () => {
  const client = new SymbolRestClient('https://node.example.test', async () => {
    throw new Error('connection reset');
  });

  await assert.rejects(() => client.getNetworkProperties(), (error) => {
    assert.ok(error instanceof SymbolNodeUnavailableError);
    assert.equal(error.statusCode, 503);
    assert.match(error.message, /connection reset/);
    return true;
  });
});
