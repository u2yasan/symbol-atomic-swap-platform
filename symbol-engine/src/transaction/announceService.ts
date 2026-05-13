import { z } from 'zod';
import { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import { dispatchBlockchainEvent } from '../listener/eventDispatcher.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';

const announceRequestSchema = z.object({
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
});

export type AnnounceResult = {
  accepted: boolean;
  intentHash: string;
  transactionHash: string;
  nodeResponse: unknown;
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
  },
): Promise<AnnounceResult> {
  const request = announceRequestSchema.parse(input);

  if (!dependencies.nodeUrl) {
    throw new Error('SYMBOL_NODE_URL is required for transaction announcement.');
  }

  const intent = await dependencies.swapIntents.findByIntentHash(request.intentHash.toUpperCase());
  if (!intent || intent.state !== 'signed' || !intent.signedPayload || !intent.transactionHash) {
    throw new InvalidAnnouncementError('swap intent must be signed before announcement');
  }

  const response = await fetch(new URL('/transactions', dependencies.nodeUrl), {
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
