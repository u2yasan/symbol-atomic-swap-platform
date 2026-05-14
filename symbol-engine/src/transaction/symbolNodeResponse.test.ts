import assert from 'node:assert/strict';
import test from 'node:test';
import { publicSymbolNodeResponse } from './symbolNodeResponse.js';

test('publicSymbolNodeResponse keeps only public status, code, and message', async () => {
  const response = new Response(JSON.stringify({
    code: 'Success',
    message: 'accepted',
    payload: 'A'.repeat(256),
    nested: {
      url: 'https://node.example.test',
    },
  }), {
    status: 202,
    headers: {
      'content-type': 'application/json',
    },
  });

  assert.deepEqual(await publicSymbolNodeResponse(response), {
    status: 202,
    code: 'Success',
    message: 'accepted',
  });
});

test('publicSymbolNodeResponse truncates upstream strings', async () => {
  const response = new Response(JSON.stringify({
    status: {
      code: 'Failure_Core_Past_Deadline',
      message: 'X'.repeat(300),
    },
  }), { status: 409 });

  const normalized = await publicSymbolNodeResponse(response);

  assert.equal(normalized.status, 409);
  assert.equal(normalized.code, 'Failure_Core_Past_Deadline');
  assert.equal(normalized.message?.length, 200);
});

test('publicSymbolNodeResponse handles non-json responses', async () => {
  const response = new Response('not-json-with-secret', { status: 503 });

  assert.deepEqual(await publicSymbolNodeResponse(response), {
    status: 503,
  });
});
