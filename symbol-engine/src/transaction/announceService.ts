import { z } from 'zod';
import { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import { dispatchBlockchainEvent } from '../listener/eventDispatcher.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';
import { putJsonToSymbolNode } from './symbolNodeHttp.js';
import { publicSymbolNodeResponse, type PublicSymbolNodeResponse } from './symbolNodeResponse.js';

const announceRequestSchema = z.object({
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
});

export type AnnounceResult = {
  accepted: boolean;
  intentHash: string;
  transactionHash: string;
  nodeResponse: PublicSymbolNodeResponse;
};

export class InvalidAnnouncementError extends Error {
  public readonly statusCode = 409;
}

export async function announceVerifiedTransaction(
  input: unknown,
  dependencies: {
    nodeUrl: string | undefined;
    swapIntents: SwapIntentRepository;
    events: EventRepository;
    projections: ProjectionRepository;
    nodeRequestTimeoutMs?: number;
  },
): Promise<AnnounceResult> {
  const request = announceRequestSchema.parse(input);

  if (!dependencies.nodeUrl) {
    throw new SymbolNodeUnavailableError('SYMBOL_NODE_URL is required for transaction announcement.');
  }

  const intent = await dependencies.swapIntents.findByIntentHash(request.intentHash.toUpperCase());
  if (!intent || intent.state !== 'signed' || !intent.signedPayload || !intent.transactionHash) {
    throw new InvalidAnnouncementError('swap intent must be signed before announcement');
  }

  if (intent.aggregateType !== 'aggregate_complete') {
    throw new InvalidAnnouncementError('aggregate bonded intent requires partial announcement');
  }

  const response = await putJsonToSymbolNode(dependencies.nodeUrl, '/transactions', {
    payload: intent.signedPayload,
  }, dependencies.nodeRequestTimeoutMs ? { timeoutMs: dependencies.nodeRequestTimeoutMs } : {});

  const nodeResponse = await publicSymbolNodeResponse(response);

  if (!response.ok) {
    await dependencies.swapIntents.markFailed(intent.intentHash, nodeResponse);
    await dispatchBlockchainEvent({
      transactionHash: intent.transactionHash,
      network: intent.network,
      eventType: 'TransactionFailed',
      statusCode: `NODE_${response.status}`,
      observedAt: new Date().toISOString(),
    }, dependencies);
    throw new InvalidAnnouncementError('symbol node rejected transaction announcement');
  }

  await dependencies.swapIntents.markAnnounced(intent.intentHash, nodeResponse);
  await dispatchBlockchainEvent({
    transactionHash: intent.transactionHash,
    network: intent.network,
    eventType: 'TransactionAnnounced',
    observedAt: new Date().toISOString(),
  }, dependencies);

  return {
    accepted: true,
    intentHash: intent.intentHash,
    transactionHash: intent.transactionHash,
    nodeResponse,
  };
}
