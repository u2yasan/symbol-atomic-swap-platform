import assert from 'node:assert/strict';
import test from 'node:test';
import { SymbolListener } from './symbolListener.js';

class OpeningWebSocket {
  public static current: OpeningWebSocket | null = null;
  private listeners = new Map<string, (...args: never[]) => void>();

  public constructor(_url: string) {
    OpeningWebSocket.current = this;
    setTimeout(() => this.emit('open'), 0);
  }

  public on(event: string, listener: (...args: never[]) => void): this {
    this.listeners.set(event, listener);
    return this;
  }

  public emit(event: string, ...args: unknown[]): void {
    this.listeners.get(event)?.(...args as never[]);
  }

  public send(_data: string): void {}

  public close(): void {}
}

test('SymbolListener connection log does not expose WebSocket URL', async () => {
  const logs: unknown[] = [];
  const listener = new SymbolListener({
    wsUrl: 'wss://symbol-node.example:3001/ws',
    network: 'testnet',
    addresses: ['TA6X4QW3IGPFUE3WPIWU3NRJDLNDAHHKUTU2DYA'],
    repositories: {
      events: {},
      projections: {},
    } as never,
    logger: {
      info: (payload: unknown) => logs.push(payload),
      warn: () => undefined,
      error: () => undefined,
    } as never,
    webSocketConstructor: OpeningWebSocket,
  });

  listener.start();
  await new Promise((resolve) => setTimeout(resolve, 0));
  listener.stop();

  assert.deepEqual(logs, [
    {
      addressCount: 1,
    },
  ]);
  assert.doesNotMatch(JSON.stringify(logs), /symbol-node\.example/);
});

test('SymbolListener warning and error logs do not expose external WebSocket data', async () => {
  const logs: unknown[] = [];
  const listener = new SymbolListener({
    wsUrl: 'wss://symbol-node.example:3001/ws',
    network: 'testnet',
    addresses: [],
    repositories: {
      events: {},
      projections: {},
    } as never,
    logger: {
      info: () => undefined,
      warn: (payload: unknown) => logs.push(payload),
      error: (payload: unknown) => logs.push(payload),
    } as never,
    webSocketConstructor: OpeningWebSocket,
    reconnectBaseMs: 60000,
  });

  listener.start();
  await new Promise((resolve) => setTimeout(resolve, 0));

  OpeningWebSocket.current?.emit('message', {
    toString: () => 'not-json-with-secret wss://symbol-node.example:3001/ws',
  });
  OpeningWebSocket.current?.emit('message', {
    toString: () => JSON.stringify({
      topic: 'finalizedBlock',
      data: {
        secret: 'do-not-log',
      },
    }),
  });
  OpeningWebSocket.current?.emit('error', new Error('wss://symbol-node.example:3001/ws failed'));
  OpeningWebSocket.current?.emit('close', 1006, {
    toString: () => 'wss://symbol-node.example:3001/ws closed',
  });

  await new Promise((resolve) => setTimeout(resolve, 0));
  listener.stop();

  const serialized = JSON.stringify(logs);
  assert.doesNotMatch(serialized, /symbol-node\.example/);
  assert.doesNotMatch(serialized, /do-not-log/);
  assert.deepEqual(logs, [
    {
      messageLength: 54,
    },
    {
      payloadType: 'object',
    },
    {
      errorName: 'Error',
    },
    {
      code: 1006,
      hasReason: true,
      reasonLength: 40,
    },
  ]);
});
