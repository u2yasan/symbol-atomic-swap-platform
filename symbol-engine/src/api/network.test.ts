import assert from 'node:assert/strict';
import test from 'node:test';
import { networkResponse } from './network.js';

test('networkResponse hides Symbol node endpoints by default', () => {
  assert.deepEqual(networkResponse({
    network: 'testnet',
    exposeNodeEndpoints: false,
    nodeUrl: 'https://node.example.test',
    wsUrl: 'wss://node.example.test/ws',
  }), {
    network: 'testnet',
  });
});

test('networkResponse includes Symbol node endpoints only when explicitly enabled', () => {
  assert.deepEqual(networkResponse({
    network: 'testnet',
    exposeNodeEndpoints: true,
    nodeUrl: 'https://node.example.test',
    wsUrl: 'wss://node.example.test/ws',
  }), {
    network: 'testnet',
    nodeUrl: 'https://node.example.test',
    wsUrl: 'wss://node.example.test/ws',
  });
});
