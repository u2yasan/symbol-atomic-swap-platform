import { dispatchBlockchainEvent } from '../listener/eventDispatcher.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { SwapIntentRecord } from '../repository/types.js';
import type { SymbolRestClient, SymbolStatusLookup, SymbolTransactionLookup } from './symbolRestClient.js';

export type ReconciliationRepositories = {
  swapIntents: Pick<SwapIntentRepository, 'findByTransactionHash' | 'findReconciliationCandidates' | 'markFailed'>;
  events: Pick<EventRepository, 'insert'>;
  projections: Pick<ProjectionRepository, 'find' | 'findReconciliationCandidates' | 'findConfirmedAtOrBelow' | 'upsert'>;
};

export type ReconciliationCandidate = {
  transactionHash: string;
  network: string;
  intent?: SwapIntentRecord;
};

export type ReconciliationClient = Pick<SymbolRestClient, 'getFinalizedHeight'> & {
  getTransactionStatus(transactionHash: string): Promise<SymbolStatusLookup>;
  getConfirmedTransaction(transactionHash: string): Promise<SymbolTransactionLookup>;
  getUnconfirmedTransaction(transactionHash: string): Promise<SymbolTransactionLookup>;
};

function publicStatusFailureResponse(status: SymbolStatusLookup): { code: string } {
  return {
    code: status.code ?? 'unknown_transaction_failure',
  };
}

function isFailureStatusCode(code: string | undefined): boolean {
  return Boolean(code && code.toLowerCase() !== 'success');
}

function uniqueCandidates(candidates: ReconciliationCandidate[]): ReconciliationCandidate[] {
  const seen = new Set<string>();
  const unique: ReconciliationCandidate[] = [];

  for (const candidate of candidates) {
    const key = `${candidate.network}:${candidate.transactionHash.toUpperCase()}`;
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    unique.push({
      ...candidate,
      transactionHash: candidate.transactionHash.toUpperCase(),
    });
  }

  return unique;
}

export async function collectReconciliationCandidates(input: {
  network: string;
  repositories: ReconciliationRepositories;
}): Promise<ReconciliationCandidate[]> {
  const [intents, projections] = await Promise.all([
    input.repositories.swapIntents.findReconciliationCandidates(),
    input.repositories.projections.findReconciliationCandidates(input.network),
  ]);

  return uniqueCandidates([
    ...intents
      .filter((intent) => intent.transactionHash)
      .map((intent) => ({
        transactionHash: intent.transactionHash!,
        network: intent.network,
        intent,
      })),
    ...projections.map((projection) => ({
      transactionHash: projection.transactionHash,
      network: input.network,
    })),
  ]);
}

export async function reconcileTransactionStatus(input: {
  candidate: ReconciliationCandidate;
  finalizedHeight: number | null;
  client: ReconciliationClient;
  repositories: ReconciliationRepositories;
  observedAt?: string;
}): Promise<'confirmed' | 'unconfirmed' | 'failed' | 'finalized' | 'missing'> {
  const observedAt = input.observedAt ?? new Date().toISOString();

  const status = await input.client.getTransactionStatus(input.candidate.transactionHash);
  if (status.found && isFailureStatusCode(status.code)) {
    if (input.candidate.intent) {
      await input.repositories.swapIntents.markFailed(input.candidate.intent.intentHash, publicStatusFailureResponse(status));
    }
    await dispatchBlockchainEvent({
      transactionHash: input.candidate.transactionHash,
      network: input.candidate.network,
      eventType: 'TransactionFailed',
      statusCode: status.code,
      observedAt,
    }, input.repositories);
    return 'failed';
  }

  const confirmed = await input.client.getConfirmedTransaction(input.candidate.transactionHash);
  if (confirmed.found) {
    if (!confirmed.blockHeight) {
      throw new Error('confirmed transaction lookup returned no block height');
    }

    await dispatchBlockchainEvent({
      transactionHash: input.candidate.transactionHash,
      network: input.candidate.network,
      eventType: 'TransactionConfirmed',
      blockHeight: confirmed.blockHeight,
      observedAt,
    }, input.repositories);

    if (input.finalizedHeight !== null && confirmed.blockHeight <= input.finalizedHeight) {
      await dispatchBlockchainEvent({
        transactionHash: input.candidate.transactionHash,
        network: input.candidate.network,
        eventType: 'TransactionFinalized',
        blockHeight: confirmed.blockHeight,
        finalizedHeight: input.finalizedHeight,
        observedAt,
      }, input.repositories);
      return 'finalized';
    }

    return 'confirmed';
  }

  const unconfirmed = await input.client.getUnconfirmedTransaction(input.candidate.transactionHash);
  if (unconfirmed.found) {
    await dispatchBlockchainEvent({
      transactionHash: input.candidate.transactionHash,
      network: input.candidate.network,
      eventType: 'TransactionUnconfirmed',
      observedAt,
    }, input.repositories);
    return 'unconfirmed';
  }

  return 'missing';
}

export async function reconcileTransactionProjection(input: {
  network: string;
  transactionHash: string;
  client: ReconciliationClient;
  repositories: ReconciliationRepositories;
  observedAt?: string;
}): Promise<{
  result: 'confirmed' | 'unconfirmed' | 'failed' | 'finalized' | 'missing';
  projection: Awaited<ReturnType<ProjectionRepository['find']>>;
}> {
  const transactionHash = input.transactionHash.toUpperCase();
  const [intent, finalizedHeight] = await Promise.all([
    input.repositories.swapIntents.findByTransactionHash(input.network, transactionHash),
    input.client.getFinalizedHeight(),
  ]);
  const reconciliationInput = {
    candidate: {
      transactionHash,
      network: input.network,
      ...(intent ? { intent } : {}),
    },
    finalizedHeight,
    client: input.client,
    repositories: input.repositories,
    ...(input.observedAt === undefined ? {} : { observedAt: input.observedAt }),
  };
  const result = await reconcileTransactionStatus(reconciliationInput);
  const projection = await input.repositories.projections.find(input.network, transactionHash);
  return { result, projection };
}
