import type { FastifyBaseLogger } from 'fastify';
import { collectReconciliationCandidates, reconcileTransactionStatus } from '../transaction/transactionStatusService.js';
import { SymbolRestClient } from '../transaction/symbolRestClient.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';

type SymbolNetwork = 'mainnet' | 'testnet';

export type TransactionReconcilerOptions = {
  network: SymbolNetwork;
  nodeUrl: string;
  intervalMs: number;
  repositories: {
    swapIntents: SwapIntentRepository;
    events: EventRepository;
    projections: ProjectionRepository;
  };
  logger: FastifyBaseLogger;
  client?: SymbolRestClient;
};

export class TransactionReconciler {
  private timer: NodeJS.Timeout | null = null;
  private running = false;
  private stopped = true;
  private readonly client: SymbolRestClient;

  public constructor(private readonly options: TransactionReconcilerOptions) {
    this.client = options.client ?? new SymbolRestClient(options.nodeUrl);
  }

  public start(): void {
    this.stopped = false;
    void this.runOnce();
    this.timer = setInterval(() => {
      void this.runOnce();
    }, this.options.intervalMs);
  }

  public stop(): void {
    this.stopped = true;
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  public async runOnce(): Promise<void> {
    if (this.stopped) {
      return;
    }

    if (this.running) {
      this.options.logger.warn('transaction reconciler skipped overlapping run');
      return;
    }

    this.running = true;
    try {
      const finalizedHeight = await this.client.getFinalizedHeight();
      const candidates = await collectReconciliationCandidates({
        network: this.options.network,
        repositories: this.options.repositories,
      });

      const summary: Record<string, number> = {};
      for (const candidate of candidates) {
        try {
          const result = await reconcileTransactionStatus({
            candidate,
            finalizedHeight,
            client: this.client,
            repositories: this.options.repositories,
          });
          summary[result] = (summary[result] ?? 0) + 1;
        } catch (error) {
          this.options.logger.error({
            error,
            transactionHash: candidate.transactionHash,
          }, 'transaction reconciler failed candidate');
        }
      }

      this.options.logger.info({
        candidateCount: candidates.length,
        finalizedHeight,
        summary,
      }, 'transaction reconciler run completed');
    } finally {
      this.running = false;
    }
  }
}
