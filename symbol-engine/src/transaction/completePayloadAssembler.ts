import { Hash256, PublicKey, Signature, utils } from 'symbol-sdk';
import { models, SymbolFacade, SymbolTransactionFactory, Verifier } from 'symbol-sdk/symbol';
import { z } from 'zod';
import type { SwapIntentRecord } from '../repository/types.js';
import { verifySignedPayload } from './signedPayloadVerifier.js';

const cosignatureInputSchema = z.object({
  parentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signerPublicKey: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signature: z.string().regex(/^[0-9A-Fa-f]{128}$/),
});

const assembleCompletePayloadSchema = z.object({
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  rootSignedPayload: z.string().regex(/^[0-9A-Fa-f]+$/).min(2),
  cosignatures: z.array(cosignatureInputSchema).max(16),
});

export type AssembleCompletePayloadResult = {
  accepted: boolean;
  reason: string;
  payload?: string;
  transactionHash?: string;
};

export function assembleCompleteSignedPayload(input: unknown, intent: SwapIntentRecord | null): AssembleCompletePayloadResult {
  const parsed = assembleCompletePayloadSchema.safeParse(input);
  if (!parsed.success) {
    return {
      accepted: false,
      reason: parsed.error.issues.map((issue) => issue.message).join('; '),
    };
  }

  if (!intent) {
    return { accepted: false, reason: 'swap intent not found' };
  }

  if (parsed.data.intentHash.toUpperCase() !== intent.intentHash) {
    return { accepted: false, reason: 'intent hash mismatch' };
  }

  if (intent.aggregateType !== 'aggregate_complete') {
    return { accepted: false, reason: 'assembly currently requires aggregate complete intent' };
  }

  if (parsed.data.rootSignedPayload.length % 2 !== 0) {
    return { accepted: false, reason: 'root signed payload hex length must be even' };
  }

  try {
    const facade = new SymbolFacade(intent.network);
    const transaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(parsed.data.rootSignedPayload));
    if (transaction.type.value !== models.TransactionType.AGGREGATE_COMPLETE.value) {
      return { accepted: false, reason: 'transaction is not aggregate_complete' };
    }

    const aggregateSigner = intent.requiredCosigners[0]?.toUpperCase();
    if (transaction.signerPublicKey.toString().toUpperCase() !== aggregateSigner) {
      return { accepted: false, reason: 'aggregate signer mismatch' };
    }

    const signatureBytes = transaction.signature.bytes;
    if (!signatureBytes.some((byte: number) => byte !== 0)) {
      return { accepted: false, reason: 'root transaction signature is missing' };
    }
    if (!facade.verifyTransaction(transaction, new Signature(signatureBytes))) {
      return { accepted: false, reason: 'root transaction signature verification failed' };
    }

    const transactionHash = facade.hashTransaction(transaction).toString().toUpperCase();
    const expectedCosigners = new Set(intent.requiredCosigners.slice(1).map((cosigner) => cosigner.toUpperCase()));
    const seen = new Set<string>();
    const cosignatures = [];

    for (const inputCosignature of parsed.data.cosignatures) {
      const parentHash = inputCosignature.parentHash.toUpperCase();
      const signerPublicKey = inputCosignature.signerPublicKey.toUpperCase();
      const signature = inputCosignature.signature.toUpperCase();

      if (parentHash !== transactionHash) {
        return { accepted: false, reason: 'cosignature parent hash mismatch' };
      }
      if (!expectedCosigners.has(signerPublicKey)) {
        return { accepted: false, reason: 'unexpected cosignature signer' };
      }
      if (seen.has(signerPublicKey)) {
        return { accepted: false, reason: 'duplicate cosignature signer' };
      }
      if (!new Verifier(new PublicKey(signerPublicKey)).verify(new Hash256(parentHash).bytes, new Signature(signature))) {
        return { accepted: false, reason: 'cosignature verification failed' };
      }

      const cosignature = new models.Cosignature();
      cosignature.version = 0n;
      cosignature.signerPublicKey = new models.PublicKey(utils.hexToUint8(signerPublicKey));
      cosignature.signature = new models.Signature(utils.hexToUint8(signature));
      cosignatures.push(cosignature);
      seen.add(signerPublicKey);
    }

    for (const expectedCosigner of expectedCosigners) {
      if (!seen.has(expectedCosigner)) {
        return { accepted: false, reason: `missing required signer ${expectedCosigner}` };
      }
    }

    (transaction as unknown as { cosignatures: unknown[] }).cosignatures = cosignatures;
    const payload = utils.uint8ToHex(transaction.serialize()).toUpperCase();
    const verification = verifySignedPayload({
      intentHash: intent.intentHash,
      payload,
    }, intent);
    if (!verification.accepted || !verification.transactionHash) {
      return {
        accepted: false,
        reason: verification.reason,
      };
    }

    return {
      accepted: true,
      reason: 'assembled_payload_verification_passed',
      payload,
      transactionHash: verification.transactionHash,
    };
  } catch {
    return { accepted: false, reason: 'signed payload assembly failed' };
  }
}
