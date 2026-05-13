import type { AggregateCompleteBuildRequest } from '../dto/aggregateComplete.js';

export type SwapIntentState = 'created' | 'signed' | 'announced' | 'failed';
export type ProjectionState =
  | 'announced'
  | 'unconfirmed'
  | 'confirmed'
  | 'finalized'
  | 'failed'
  | 'rolled_back'
  | 'partial_announced'
  | 'partial_cosigned';

export type NormalizedSwapIntent = AggregateCompleteBuildRequest & {
  aggregateType: 'aggregate_complete';
  requiredCosigners: string[];
};

export type QrPayload = {
  type: 'symbol-aggregate-complete';
  network: 'mainnet' | 'testnet';
  unsignedPayload: string;
  deadline: string;
  requiredCosigners: string[];
  callback: string | null;
  intentHash: string;
};

export type SwapIntentRecord = {
  id: string;
  correlationId: string;
  network: 'mainnet' | 'testnet';
  intentHash: string;
  state: SwapIntentState;
  aggregateType: 'aggregate_complete';
  unsignedPayload: string;
  qrPayload: QrPayload;
  requiredCosigners: string[];
  intent: NormalizedSwapIntent;
  signedPayload: string | null;
  transactionHash: string | null;
  nodeResponse: unknown | null;
};
