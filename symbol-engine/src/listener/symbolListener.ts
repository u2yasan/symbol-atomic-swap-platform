import WebSocket from 'ws';
import type { FastifyBaseLogger } from 'fastify';
import { dispatchBlockchainEvent } from './eventDispatcher.js';
import { normalizeFinalizedBlockHeight, normalizeSymbolWebSocketEvent } from './eventNormalizer.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';

type SymbolNetwork = 'mainnet' | 'testnet';

type ListenerRepositories = {
  events: EventRepository;
  projections: ProjectionRepository;
};

export type SymbolListenerOptions = {
  wsUrl: string;
  network: SymbolNetwork;
  addresses: string[];
  repositories: ListenerRepositories;
  logger: FastifyBaseLogger;
  reconnectBaseMs?: number;
  reconnectMaxMs?: number;
};

export class SymbolListener {
  private socket: WebSocket | null = null;
  private stopped = false;
  private reconnectAttempt = 0;
  private reconnectTimer: NodeJS.Timeout | null = null;

  public constructor(private readonly options: SymbolListenerOptions) {}

  public start(): void {
    this.stopped = false;
    this.connect();
  }

  public stop(): void {
    this.stopped = true;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    this.socket?.close();
    this.socket = null;
  }

  private connect(): void {
    this.socket = new WebSocket(this.options.wsUrl);

    this.socket.on('open', () => {
      this.reconnectAttempt = 0;
      this.options.logger.info({ wsUrl: this.options.wsUrl }, 'symbol listener connected');
    });

    this.socket.on('message', (data) => {
      void this.handleMessage(data.toString());
    });

    this.socket.on('close', (code, reason) => {
      this.options.logger.warn({ code, reason: reason.toString() }, 'symbol listener disconnected');
      this.scheduleReconnect();
    });

    this.socket.on('error', (error) => {
      this.options.logger.error({ error }, 'symbol listener websocket error');
    });
  }

  private scheduleReconnect(): void {
    if (this.stopped || this.reconnectTimer) {
      return;
    }

    const base = this.options.reconnectBaseMs ?? 1000;
    const max = this.options.reconnectMaxMs ?? 30000;
    const delay = Math.min(max, base * (2 ** this.reconnectAttempt));
    this.reconnectAttempt += 1;

    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
  }

  private async handleMessage(message: string): Promise<void> {
    let parsed: unknown;
    try {
      parsed = JSON.parse(message);
    } catch {
      this.options.logger.warn({ message }, 'symbol listener received invalid json');
      return;
    }

    const record = typeof parsed === 'object' && parsed !== null ? parsed as Record<string, unknown> : {};
    if (typeof record.uid === 'string') {
      this.subscribe(record.uid);
      return;
    }

    if (typeof record.topic !== 'string') {
      return;
    }

    try {
      if (record.topic === 'finalizedBlock') {
        await this.handleFinalizedBlock(record.data);
        return;
      }

      const event = normalizeSymbolWebSocketEvent({
        topic: record.topic,
        payload: record.data,
        network: this.options.network,
      });

      if (event) {
        await dispatchBlockchainEvent(event, this.options.repositories);
      }
    } catch (error) {
      this.options.logger.error({ error, topic: record.topic }, 'symbol listener failed to process event');
    }
  }

  private subscribe(uid: string): void {
    this.sendSubscription(uid, 'finalizedBlock');

    for (const address of this.options.addresses) {
      this.sendSubscription(uid, `unconfirmedAdded/${address}`);
      this.sendSubscription(uid, `confirmedAdded/${address}`);
      this.sendSubscription(uid, `status/${address}`);
      this.sendSubscription(uid, `partialAdded/${address}`);
      this.sendSubscription(uid, `cosignature/${address}`);
    }

    this.options.logger.info({
      addressCount: this.options.addresses.length,
    }, 'symbol listener subscriptions sent');
  }

  private sendSubscription(uid: string, channel: string): void {
    this.socket?.send(JSON.stringify({
      uid,
      subscribe: channel,
    }));
  }

  private async handleFinalizedBlock(payload: unknown): Promise<void> {
    const finalizedHeight = normalizeFinalizedBlockHeight(payload);
    if (!finalizedHeight) {
      this.options.logger.warn({ payload }, 'symbol listener ignored finalizedBlock without height');
      return;
    }

    const confirmed = await this.options.repositories.projections.findConfirmedAtOrBelow(
      this.options.network,
      finalizedHeight,
    );

    for (const projection of confirmed) {
      await dispatchBlockchainEvent({
        transactionHash: projection.transactionHash,
        network: this.options.network,
        eventType: 'TransactionFinalized',
        blockHeight: projection.blockHeight,
        finalizedHeight,
        observedAt: new Date().toISOString(),
      }, this.options.repositories);
    }
  }
}
