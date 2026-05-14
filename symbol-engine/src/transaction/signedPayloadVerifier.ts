import { z } from 'zod';
import { PublicKey, Signature, utils } from 'symbol-sdk';
import { Address, models, SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import type { SwapIntentRecord } from '../repository/types.js';

const signedPayloadVerificationSchema = z.object({
  payload: z.string().regex(/^[0-9A-Fa-f]+$/, 'payload must be hex').min(2),
  intentHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
});

export type SignedPayloadVerificationRequest = z.infer<typeof signedPayloadVerificationSchema>;

export type SignedPayloadVerificationResult = {
  accepted: boolean;
  reason: string;
  transactionHash?: string;
};

function hexAddress(address: string): string {
  return utils.uint8ToHex(new Address(address).bytes).toUpperCase();
}

function readMosaicId(mosaicId: unknown): string {
  const value = (mosaicId as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid mosaic id');
  }
  return value.toString(16).toUpperCase().padStart(16, '0');
}

function readAmount(amount: unknown): string {
  const value = (amount as { value?: bigint }).value;
  if (typeof value !== 'bigint') {
    throw new Error('invalid amount');
  }
  return value.toString();
}

export function verifySignedPayload(input: unknown, intent: SwapIntentRecord | null): SignedPayloadVerificationResult {
  const parsed = signedPayloadVerificationSchema.safeParse(input);
  if (!parsed.success) {
    return {
      accepted: false,
      reason: parsed.error.issues.map((issue) => issue.message).join('; '),
    };
  }

  if (parsed.data.payload.length % 2 !== 0) {
    return {
      accepted: false,
      reason: 'payload hex length must be even',
    };
  }

  if (!intent) {
    return {
      accepted: false,
      reason: 'swap intent not found',
    };
  }

  if (parsed.data.intentHash.toUpperCase() !== intent.intentHash) {
    return {
      accepted: false,
      reason: 'intent hash mismatch',
    };
  }

  try {
    const transaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(parsed.data.payload));
    const facade = new SymbolFacade(intent.network);
    const expectedTransactionType = intent.aggregateType === 'aggregate_complete'
      ? models.TransactionType.AGGREGATE_COMPLETE
      : models.TransactionType.AGGREGATE_BONDED;

    if (transaction.type.value !== expectedTransactionType.value) {
      return { accepted: false, reason: `transaction is not ${intent.aggregateType}` };
    }

    if (transaction.network.value !== (intent.network === 'mainnet' ? 104 : 152)) {
      return { accepted: false, reason: 'network mismatch' };
    }

    const embeddedTransactions = (transaction as { transactions?: unknown[] }).transactions ?? [];
    if (embeddedTransactions.length !== intent.intent.legs.length) {
      return { accepted: false, reason: 'embedded transfer count mismatch' };
    }

    const expectedSignerSet = new Set(intent.requiredCosigners.map((cosigner) => cosigner.toUpperCase()));
    const actualSignerSet = new Set<string>();
    actualSignerSet.add(transaction.signerPublicKey.toString().toUpperCase());

    for (const cosignature of (transaction as { cosignatures?: Array<{ signerPublicKey: { toString(): string } }> }).cosignatures ?? []) {
      actualSignerSet.add(cosignature.signerPublicKey.toString().toUpperCase());
    }

    if (intent.aggregateType === 'aggregate_complete') {
      for (const expectedSigner of expectedSignerSet) {
        if (!actualSignerSet.has(expectedSigner)) {
          return { accepted: false, reason: `missing required signer ${expectedSigner}` };
        }
      }
    } else if (transaction.signerPublicKey.toString().toUpperCase() !== intent.requiredCosigners[0]) {
      return { accepted: false, reason: 'aggregate bonded signer mismatch' };
    }

    for (const actualSigner of actualSignerSet) {
      if (!expectedSignerSet.has(actualSigner)) {
        return { accepted: false, reason: `unexpected signer ${actualSigner}` };
      }
    }

    const expectedLegs = intent.intent.legs.map((leg) => ({
      signerPublicKey: leg.signerPublicKey.toUpperCase(),
      recipientAddressHex: hexAddress(leg.recipientAddress),
      mosaicId: leg.mosaicId.toUpperCase(),
      amount: leg.amount,
    })).sort((left, right) => left.signerPublicKey.localeCompare(right.signerPublicKey));

    const actualLegs = embeddedTransactions.map((embedded) => {
      const transfer = embedded as {
        type: { value: number };
        signerPublicKey: { toString(): string };
        recipientAddress: { bytes: Uint8Array };
        mosaics?: Array<{ mosaicId: unknown; amount: unknown }>;
      };

      if (transfer.type.value !== models.TransactionType.TRANSFER.value) {
        throw new Error('embedded transaction is not transfer');
      }

      if ((transfer.mosaics ?? []).length !== 1) {
        throw new Error('embedded transfer must contain exactly one mosaic');
      }

      const mosaic = transfer.mosaics![0]!;
      return {
        signerPublicKey: transfer.signerPublicKey.toString().toUpperCase(),
        recipientAddressHex: utils.uint8ToHex(transfer.recipientAddress.bytes).toUpperCase(),
        mosaicId: readMosaicId(mosaic.mosaicId),
        amount: readAmount(mosaic.amount),
      };
    }).sort((left, right) => left.signerPublicKey.localeCompare(right.signerPublicKey));

    if (JSON.stringify(actualLegs) !== JSON.stringify(expectedLegs)) {
      return { accepted: false, reason: 'embedded transfer intent mismatch' };
    }

    if (intent.intent.maxFee && transaction.fee.value.toString() !== intent.intent.maxFee) {
      return { accepted: false, reason: 'max fee mismatch' };
    }

    const signatureBytes = transaction.signature.bytes;
    const hasSignature = signatureBytes.some((byte: number) => byte !== 0);
    if (!hasSignature) {
      return { accepted: false, reason: 'transaction signature is missing' };
    }

    if (!facade.verifyTransaction(transaction, new Signature(signatureBytes))) {
      return { accepted: false, reason: 'transaction signature verification failed' };
    }

    return {
      accepted: true,
      reason: 'semantic_verification_passed',
      transactionHash: facade.hashTransaction(transaction).toString().toUpperCase(),
    };
  } catch (error) {
    return {
      accepted: false,
      reason: error instanceof Error ? error.message : 'signed payload verification failed',
    };
  }
}
