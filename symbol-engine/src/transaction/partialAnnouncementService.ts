import { z } from 'zod';
import { dispatchBlockchainEvent } from '../listener/eventDispatcher.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import { InvalidAnnouncementError } from './announceService.js';

const partialAnnouncementRequestSchema = z.object({
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
});

export type PartialAnnouncementResult = {
  accepted: true;
  intentHash: string;
  transactionHash: string;
  nodeResponse: unknown;
};

export async function announcePartialAggregateBonded(
  input: unknown,
  dependencies: {
    nodeUrl: string | undefined;
    swapIntents: SwapIntentRepository;
    events: EventRepository;
    projections: ProjectionRepository;
  },
): Promise<PartialAnnouncementResult> {
  const request = partialAnnouncementRequestSchema.parse(input);

  if (!dependencies.nodeUrl) {
    throw new Error('SYMBOL_NODE_URL is required for partial transaction announcement.');
  }

  const intent = await dependencies.swapIntents.findByIntentHash(request.intentHash.toUpperCase());
  if (!intent || intent.aggregateType !== 'aggregate_bonded') {
    throw new InvalidAnnouncementError('partial announcement requires aggregate bonded intent');
  }

  if (intent.state !== 'signed' || !intent.signedPayload || !intent.transactionHash) {
    throw new InvalidAnnouncementError('aggregate bonded intent must be signed before partial announcement');
  }

  const response = await fetch(new URL('/transactions/partial', dependencies.nodeUrl), {
    method: 'PUT',
    headers: {
      'content-type': 'application/json',
    },
    body: JSON.stringify({ payload: intent.signedPayload }),
  });

  const nodeResponse = await response.json().catch(() => ({ status: response.status }));

  if (!response.ok) {
    await dependencies.swapIntents.markFailed(intent.intentHash, nodeResponse);
    await dispatchBlockchainEvent({
      transactionHash: intent.transactionHash,
      network: intent.network,
      eventType: 'TransactionFailed',
      statusCode: `NODE_${response.status}`,
      observedAt: new Date().toISOString(),
    }, dependencies);
    throw new InvalidAnnouncementError('symbol node rejected partial transaction announcement');
  }

  await dependencies.swapIntents.markPartialAnnounced(intent.intentHash, nodeResponse);
  await dispatchBlockchainEvent({
    transactionHash: intent.transactionHash,
    network: intent.network,
    eventType: 'PartialTransactionAdded',
    observedAt: new Date().toISOString(),
  }, dependencies);

  return {
    accepted: true,
    intentHash: intent.intentHash,
    transactionHash: intent.transactionHash,
    nodeResponse,
  };
}
