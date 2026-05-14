import { Hash256, PublicKey, Signature } from 'symbol-sdk';
import { Verifier } from 'symbol-sdk/symbol';
import { z } from 'zod';
import { dispatchBlockchainEvent } from '../listener/eventDispatcher.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import { InvalidAnnouncementError } from './announceService.js';
import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';
import { putJsonToSymbolNode } from './symbolNodeHttp.js';

const cosignatureAnnouncementSchema = z.object({
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  parentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signerPublicKey: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signature: z.string().regex(/^[0-9A-Fa-f]{128}$/),
  version: z.union([z.literal(0), z.literal('0'), z.literal('0n')]).optional(),
});

export type CosignatureAnnouncementResult = {
  accepted: true;
  intentHash: string;
  transactionHash: string;
  signerPublicKey: string;
  nodeResponse: unknown;
};

export async function announceAggregateBondedCosignature(
  input: unknown,
  dependencies: {
    nodeUrl: string | undefined;
    swapIntents: SwapIntentRepository;
    events: EventRepository;
    projections: ProjectionRepository;
    nodeRequestTimeoutMs?: number;
  },
): Promise<CosignatureAnnouncementResult> {
  const request = cosignatureAnnouncementSchema.parse(input);

  if (!dependencies.nodeUrl) {
    throw new SymbolNodeUnavailableError('SYMBOL_NODE_URL is required for cosignature announcement.');
  }

  const intent = await dependencies.swapIntents.findByIntentHash(request.intentHash.toUpperCase());
  if (!intent || intent.aggregateType !== 'aggregate_bonded') {
    throw new InvalidAnnouncementError('cosignature submission requires aggregate bonded intent');
  }

  if (!['partial_announced', 'partial_cosigned'].includes(intent.state) || !intent.transactionHash) {
    throw new InvalidAnnouncementError('aggregate bonded intent must be partial announced before cosignature submission');
  }

  const parentHash = request.parentHash.toUpperCase();
  const signerPublicKey = request.signerPublicKey.toUpperCase();
  const signature = request.signature.toUpperCase();
  if (parentHash !== intent.transactionHash) {
    throw new InvalidAnnouncementError('cosignature parent hash mismatch');
  }

  const aggregateSigner = intent.requiredCosigners[0]?.toUpperCase();
  const expectedCosigners = new Set(intent.requiredCosigners.slice(1).map((cosigner) => cosigner.toUpperCase()));
  if (!expectedCosigners.has(signerPublicKey)) {
    const reason = signerPublicKey === aggregateSigner
      ? 'aggregate signer already signed the bonded transaction'
      : 'unexpected cosignature signer';
    throw new InvalidAnnouncementError(reason);
  }

  const verifier = new Verifier(new PublicKey(signerPublicKey));
  if (!verifier.verify(new Hash256(parentHash).bytes, new Signature(signature))) {
    throw new InvalidAnnouncementError('cosignature verification failed');
  }

  const body = {
    parentHash,
    signature,
    signerPublicKey,
    version: '0',
  };
  const response = await putJsonToSymbolNode(
    dependencies.nodeUrl,
    '/transactions/cosignature',
    body,
    dependencies.nodeRequestTimeoutMs ? { timeoutMs: dependencies.nodeRequestTimeoutMs } : {},
  );
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
    throw new InvalidAnnouncementError('symbol node rejected cosignature announcement');
  }

  await dependencies.swapIntents.markPartialCosigned(intent.intentHash, nodeResponse);
  await dispatchBlockchainEvent({
    transactionHash: intent.transactionHash,
    network: intent.network,
    eventType: 'CosignatureReceived',
    signerPublicKey,
    observedAt: new Date().toISOString(),
  }, dependencies);

  return {
    accepted: true,
    intentHash: intent.intentHash,
    transactionHash: intent.transactionHash,
    signerPublicKey,
    nodeResponse,
  };
}
