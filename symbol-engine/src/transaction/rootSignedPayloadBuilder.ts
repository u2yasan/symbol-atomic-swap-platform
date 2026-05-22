import { Hash256, PublicKey, Signature, utils } from 'symbol-sdk';
import { models, SymbolFacade, SymbolTransactionFactory, Verifier } from 'symbol-sdk/symbol';
import { z } from 'zod';
import { createSymbolFacadeForNetwork, deserializeTransactionForNetwork } from '../config/networkProfile.js';
import type { SwapIntentRecord } from '../repository/types.js';

const aggregateSignerSignatureSchema = z.object({
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  parentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signerPublicKey: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signature: z.string().regex(/^[0-9A-Fa-f]{128}$/),
});

export type RootSignedPayloadBuildResult = {
  accepted: boolean;
  reason: string;
  payload?: string;
  transactionHash?: string;
};

export function buildRootSignedPayloadFromAggregateSignerSignature(
  input: unknown,
  intent: SwapIntentRecord | null,
): RootSignedPayloadBuildResult {
  const parsed = aggregateSignerSignatureSchema.safeParse(input);
  if (!parsed.success) {
    return {
      accepted: false,
      reason: parsed.error.issues.map((issue) => issue.message).join('; '),
    };
  }

  if (!intent) {
    return { accepted: false, reason: 'swap intent not found' };
  }

  const intentHash = parsed.data.intentHash.toUpperCase();
  const parentHash = parsed.data.parentHash.toUpperCase();
  const signerPublicKey = parsed.data.signerPublicKey.toUpperCase();
  const signature = parsed.data.signature.toUpperCase();

  if (intentHash !== intent.intentHash) {
    return { accepted: false, reason: 'intent hash mismatch' };
  }

  if (intent.aggregateType !== 'aggregate_complete') {
    return { accepted: false, reason: 'root signed payload build currently requires aggregate complete intent' };
  }

  const aggregateSigner = intent.requiredCosigners[0]?.toUpperCase();
  if (signerPublicKey !== aggregateSigner) {
    return { accepted: false, reason: 'aggregate signer mismatch' };
  }

  if (!intent.unsignedPayload || intent.unsignedPayload.length % 2 !== 0) {
    return { accepted: false, reason: 'unsigned payload is unavailable' };
  }

  try {
    const { facade } = createSymbolFacadeForNetwork(intent.network);
    const { transaction } = deserializeTransactionForNetwork(utils.hexToUint8(intent.unsignedPayload), intent.network);
    if (transaction.type.value !== models.TransactionType.AGGREGATE_COMPLETE.value) {
      return { accepted: false, reason: 'transaction is not aggregate_complete' };
    }
    if (transaction.signerPublicKey.toString().toUpperCase() !== aggregateSigner) {
      return { accepted: false, reason: 'unsigned payload aggregate signer mismatch' };
    }

    transaction.signature = new models.Signature(utils.hexToUint8(signature));
    if (!facade.verifyTransaction(transaction, new Signature(signature))) {
      const verifier = new Verifier(new PublicKey(signerPublicKey));
      if (verifier.verify(new Hash256(parentHash).bytes, new Signature(signature))) {
        return { accepted: false, reason: 'desktop wallet JSON is a detached cosignature, not a root signed payload signature' };
      }
      return { accepted: false, reason: 'aggregate signer signature verification failed' };
    }

    const transactionHash = facade.hashTransaction(transaction).toString().toUpperCase();
    if (parentHash !== transactionHash) {
      return { accepted: false, reason: 'aggregate signer parent hash mismatch' };
    }

    const payload = utils.uint8ToHex(transaction.serialize()).toUpperCase();
    return {
      accepted: true,
      reason: 'root_signed_payload_build_passed',
      payload,
      transactionHash,
    };
  } catch {
    return { accepted: false, reason: 'root signed payload build failed' };
  }
}
