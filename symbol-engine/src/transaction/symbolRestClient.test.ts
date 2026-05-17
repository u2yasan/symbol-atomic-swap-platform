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
  const cause = new Error('connection reset from https://node.example.test');
  const client = new SymbolRestClient('https://node.example.test', async () => {
    throw cause;
  });

  await assert.rejects(() => client.getNetworkProperties(), (error) => {
    assert.ok(error instanceof SymbolNodeUnavailableError);
    assert.equal(error.statusCode, 503);
    assert.equal(error.message, 'Symbol node request failed.');
    assert.equal(error.cause, cause);
    return true;
  });
});

test('SymbolRestClient resolves account public key from address', async () => {
  let requestedUrl = '';
  const publicKey = 'A'.repeat(64);
  const client = new SymbolRestClient('https://node.example.test', async (input) => {
    requestedUrl = String(input);
    return jsonResponse({
      account: {
        address: 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
        publicKey,
      },
    });
  });

  const result = await client.getAccountPublicKey('TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ');

  assert.equal(requestedUrl, 'https://node.example.test/accounts/TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ');
  assert.deepEqual(result, {
    found: true,
    address: 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
    publicKey,
  });
});

test('SymbolRestClient treats unannounced account public key as not found', async () => {
  const client = new SymbolRestClient('https://node.example.test', async () => jsonResponse({
    account: {
      publicKey: '0'.repeat(64),
    },
  }));

  assert.deepEqual(await client.getAccountPublicKey('TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ'), {
    found: false,
    address: 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
  });
});

test('SymbolRestClient resolves mosaic divisibility and aliases', async () => {
  const requested: string[] = [];
  const client = new SymbolRestClient('https://node.example.test', async (input, init) => {
    requested.push(`${init?.method ?? 'GET'} ${String(input)}`);
    if (String(input).endsWith('/mosaics/72C0212E67A08BCE')) {
      return jsonResponse({
        mosaic: {
          id: '72C0212E67A08BCE',
          flags: 2,
          divisibility: 6,
        },
      });
    }
    return jsonResponse({
      mosaicNames: [
        {
          mosaicId: '72C0212E67A08BCE',
          names: ['symbol.xym'],
        },
      ],
    });
  });

  assert.deepEqual(await client.getMosaicMetadata('72c0212e67a08bce'), {
    found: true,
    mosaicId: '72C0212E67A08BCE',
    divisibility: 6,
    transferable: true,
    aliases: ['symbol.xym'],
  });
  assert.deepEqual(requested, [
    'GET https://node.example.test/mosaics/72C0212E67A08BCE',
    'POST https://node.example.test/namespaces/mosaic/names',
  ]);
});

test('SymbolRestClient resolves non-transferable mosaic flag', async () => {
  const client = new SymbolRestClient('https://node.example.test', async (input) => {
    if (String(input).endsWith('/mosaics/72C0212E67A08BCF')) {
      return jsonResponse({
        mosaic: {
          id: '72C0212E67A08BCF',
          flags: 0,
          divisibility: 2,
        },
      });
    }
    return jsonResponse({
      mosaicNames: [],
    });
  });

  assert.deepEqual(await client.getMosaicMetadata('72c0212e67a08bcf'), {
    found: true,
    mosaicId: '72C0212E67A08BCF',
    divisibility: 2,
    transferable: false,
    aliases: [],
  });
});

test('SymbolRestClient resolves account mosaic balance', async () => {
  const client = new SymbolRestClient('https://node.example.test', async () => jsonResponse({
    account: {
      mosaics: [
        {
          id: '72C0212E67A08BCE',
          amount: '719328436',
        },
      ],
    },
  }));

  assert.deepEqual(await client.getAccountMosaicBalance('TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ', '72c0212e67a08bce'), {
    found: true,
    address: 'TAEF3VF4OYCKPSSJQAAN4FS2WAZLC6IKKCE3UIQ',
    mosaicId: '72C0212E67A08BCE',
    amount: '719328436',
  });
});
