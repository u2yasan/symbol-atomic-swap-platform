import { Hash256, PublicKey, Signature } from 'symbol-sdk';
import { Verifier } from 'symbol-sdk/symbol';
import { z } from 'zod';
import type { SwapIntentRecord } from '../repository/types.js';

const cosignatureVersionSchema = z.union([
  z.literal(0),
  z.literal('0'),
  z.literal('0n'),
  z.object({
    lower: z.union([z.literal(0), z.literal('0')]),
    higher: z.union([z.literal(0), z.literal('0')]),
  }),
]).optional();

const cosignatureVerificationSchema = z.object({
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  parentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signerPublicKey: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  signature: z.string().regex(/^[0-9A-Fa-f]{128}$/),
  version: cosignatureVersionSchema,
});

export type CosignatureVerificationResult = {
  accepted: boolean;
  reason: string;
  intentHash?: string;
  parentHash?: string;
  signerPublicKey?: string;
  trustedParentHash?: boolean;
};

export function verifyCosignature(input: unknown, intent: SwapIntentRecord | null): CosignatureVerificationResult {
  const parsed = cosignatureVerificationSchema.safeParse(input);
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
    return { accepted: false, reason: 'cosignature collection currently requires aggregate complete intent' };
  }

  const aggregateSigner = intent.requiredCosigners[0]?.toUpperCase();
  if (signerPublicKey === aggregateSigner) {
    return { accepted: false, reason: 'aggregate signer must submit root signed payload' };
  }

  const expectedCosigners = new Set(intent.requiredCosigners.slice(1).map((cosigner) => cosigner.toUpperCase()));
  if (!expectedCosigners.has(signerPublicKey)) {
    return { accepted: false, reason: 'unexpected cosignature signer' };
  }

  if (intent.transactionHash && parentHash !== intent.transactionHash.toUpperCase()) {
    return { accepted: false, reason: 'cosignature parent hash mismatch' };
  }

  const verifier = new Verifier(new PublicKey(signerPublicKey));
  if (!verifier.verify(new Hash256(parentHash).bytes, new Signature(signature))) {
    return { accepted: false, reason: 'cosignature verification failed' };
  }

  return {
    accepted: true,
    reason: 'cosignature_verification_passed',
    intentHash: intent.intentHash,
    parentHash,
    signerPublicKey,
    trustedParentHash: Boolean(intent.transactionHash),
  };
}
