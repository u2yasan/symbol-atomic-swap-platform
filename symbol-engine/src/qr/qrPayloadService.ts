import type { QrPayload } from '../repository/types.js';

export function createQrPayload(input: {
  type?: QrPayload['type'];
  network: 'mainnet' | 'testnet';
  unsignedPayload: string;
  deadline: string;
  requiredCosigners: string[];
  callback?: string | null;
  intentHash: string;
  hashLock?: QrPayload['hashLock'];
}): QrPayload {
  return {
    type: input.type ?? 'symbol-aggregate-complete',
    network: input.network,
    unsignedPayload: input.unsignedPayload,
    deadline: input.deadline,
    requiredCosigners: input.requiredCosigners,
    callback: input.callback ?? null,
    intentHash: input.intentHash,
    ...(input.hashLock ? { hashLock: input.hashLock } : {}),
  };
}
