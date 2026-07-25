import type { BlockchainEvent } from '../dto/events.js';
import { blockchainEventSchema } from '../dto/events.js';
import type { EventRepository, ProjectionUpdate } from '../repository/eventRepository.js';
import type { TransactionProjection } from '../repository/projectionRepository.js';
import type { ProjectionState } from '../repository/types.js';

type StoredProjection = ProjectionUpdate;

export class InvalidStateTransitionError extends Error {
  public readonly statusCode = 409;
}

function eventKey(event: BlockchainEvent): string {
  return [
    event.network,
    event.transactionHash.toUpperCase(),
    event.eventType,
    event.blockHeight ?? 0,
    event.finalizedHeight ?? 0,
    event.statusCode ?? '',
  ].join(':');
}

function stateForEvent(event: BlockchainEvent): ProjectionState {
  switch (event.eventType) {
    case 'TransactionAnnounced':
      return 'announced';
    case 'TransactionUnconfirmed':
      return 'unconfirmed';
    case 'TransactionConfirmed':
      return 'confirmed';
    case 'TransactionFinalized':
      return 'finalized';
    case 'TransactionFailed':
      return 'failed';
    case 'TransactionRolledBack':
      return 'rolled_back';
    case 'PartialTransactionAdded':
      return 'partial_announced';
    case 'CosignatureReceived':
      return 'partial_cosigned';
  }
}

function assertAllowedTransition(current: ProjectionState | undefined, next: ProjectionState): void {
  if (current === 'finalized' && next !== 'finalized') {
    throw new InvalidStateTransitionError('finalized projection is immutable');
  }

  if (['failed', 'rolled_back'].includes(current ?? '') && next === 'finalized') {
    throw new InvalidStateTransitionError(`${current} projection cannot transition to finalized`);
  }

  if (next === 'finalized' && current !== 'confirmed' && current !== 'finalized') {
    throw new InvalidStateTransitionError('transaction must be confirmed before finalized');
  }
}

function unixSeconds(datetime: string): number {
  return Math.floor(Date.parse(datetime) / 1000);
}

export async function dispatchBlockchainEvent(
  input: unknown,
  repositories: {
    events: Pick<EventRepository, 'apply'>;
  },
): Promise<StoredProjection> {
  const event = blockchainEventSchema.parse(input);
  const key = eventKey(event);
  return repositories.events.apply(event, key, (existing: TransactionProjection | null) => {
    const nextState = stateForEvent(event);
    if (existing?.state === 'finalized' && existing.lastEventKey !== key) {
      throw new InvalidStateTransitionError('finalized projection is immutable');
    }
    assertAllowedTransition(existing?.state, nextState);

    return {
      transactionHash: event.transactionHash.toUpperCase(),
      network: event.network,
      state: nextState,
      lastEventKey: key,
      updatedAt: unixSeconds(event.observedAt),
      ...(event.blockHeight ? { blockHeight: event.blockHeight } : {}),
      ...(event.finalizedHeight ? { finalizedHeight: event.finalizedHeight } : {}),
    };
  });
}
