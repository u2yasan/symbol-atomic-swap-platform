import assert from 'node:assert/strict';
import test from 'node:test';
import { SymbolListener } from './symbolListener.js';

class OpeningWebSocket {
  private openListener: (() => void) | null = null;

  public constructor(_url: string) {
    setTimeout(() => this.openListener?.(), 0);
  }

  public on(event: string, listener: (...args: never[]) => void): this {
    if (event === 'open') {
      this.openListener = listener;
    }
    return this;
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
