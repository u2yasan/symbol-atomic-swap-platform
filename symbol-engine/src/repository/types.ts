import type { AggregateCompleteBuildRequest } from '../dto/aggregateComplete.js';
import type { AggregateBondedBuildRequest } from '../dto/aggregateBonded.js';

export type SwapIntentState = 'created' | 'signed' | 'announced' | 'partial_announced' | 'partial_cosigned' | 'failed';
export type AggregateType = 'aggregate_complete' | 'aggregate_bonded';
export type ProjectionState =
  | 'announced'
  | 'unconfirmed'
  | 'confirmed'
  | 'finalized'
  | 'failed'
  | 'rolled_back'
  | 'partial_announced'
  | 'partial_cosigned';

export type NormalizedCompleteSwapIntent = AggregateCompleteBuildRequest & {
  aggregateType: 'aggregate_complete';
  requiredCosigners: string[];
};

export type NormalizedBondedSwapIntent = AggregateBondedBuildRequest & {
  aggregateType: 'aggregate_bonded';
  requiredCosigners: string[];
};

export type NormalizedSwapIntent = NormalizedCompleteSwapIntent | NormalizedBondedSwapIntent;

export type QrPayload = {
  type: 'symbol-aggregate-complete' | 'symbol-aggregate-bonded';
  network: string;
  unsignedPayload: string;
  deadline: string;
  requiredCosigners: string[];
  callback: string | null;
  intentHash: string;
  hashLock?: {
    mosaicId: string;
    amount: string;
    duration: number;
  };
};

export type SwapIntentRecord = {
  id: string;
  correlationId: string;
  network: string;
  intentHash: string;
  state: SwapIntentState;
  aggregateType: AggregateType;
  unsignedPayload: string;
  qrPayload: QrPayload;
  requiredCosigners: string[];
  intent: NormalizedSwapIntent;
  signedPayload: string | null;
  transactionHash: string | null;
  nodeResponse: unknown | null;
};
