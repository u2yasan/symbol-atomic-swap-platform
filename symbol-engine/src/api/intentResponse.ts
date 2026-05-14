import type { SwapIntentRecord } from '../repository/types.js';

export function intentResponse(intent: SwapIntentRecord) {
  return {
    id: intent.id,
    correlationId: intent.correlationId,
    network: intent.network,
    intentHash: intent.intentHash,
    state: intent.state,
    aggregateType: intent.aggregateType,
    unsignedPayload: intent.unsignedPayload,
    qrPayload: intent.qrPayload,
    requiredCosigners: intent.requiredCosigners,
    intent: intent.intent,
    transactionHash: intent.transactionHash,
  };
}
