import { PublicKey, Signature, utils } from 'symbol-sdk';
import { Address, descriptors, models, SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { z } from 'zod';
import { addressSchema, publicKeySchema } from '../dto/aggregateComplete.js';

const challengeSchema = z.string().min(32).max(1024);
const signedPayloadSchema = z.string().regex(/^[0-9A-Fa-f]+$/, 'payload must be hex').min(2);

const accountVerificationBuildSchema = z.object({
  network: z.enum(['mainnet', 'testnet']),
  address: addressSchema,
  signerPublicKey: publicKeySchema,
  challenge: challengeSchema,
  deadlineHours: z.number().int().min(1).max(6).default(1),
});

const accountVerificationVerifySchema = accountVerificationBuildSchema.omit({ deadlineHours: true }).extend({
  payload: signedPayloadSchema,
});

export type AccountVerificationBuildResult = {
  network: 'mainnet' | 'testnet';
  address: string;
  signerPublicKey: string;
  challenge: string;
  unsignedPayload: string;
  deadline: string;
};

export type AccountVerificationResult = {
  accepted: boolean;
  reason: string;
  signerPublicKey?: string;
  transactionHash?: string;
};

function networkByte(network: 'mainnet' | 'testnet'): number {
  return network === 'mainnet' ? 104 : 152;
}

function readMessage(transaction: unknown): string {
  const message = (transaction as { message?: Uint8Array | string }).message;
  if (message === undefined) {
    return '';
  }
  if (typeof message === 'string') {
    return message;
  }
  return new TextDecoder().decode(message);
}

export function buildAccountVerificationPayload(input: unknown): AccountVerificationBuildResult {
  const request = accountVerificationBuildSchema.parse(input);
  const facade = new SymbolFacade(request.network);
  const transaction = facade.createTransactionFromTypedDescriptor(
    new descriptors.TransferTransactionV1Descriptor(
      new Address(request.address),
      [],
      request.challenge,
    ),
    new PublicKey(request.signerPublicKey),
    0,
    request.deadlineHours * 60 * 60,
  );

  transaction.fee = new models.Amount(0n);

  return {
    network: request.network,
    address: request.address,
    signerPublicKey: request.signerPublicKey.toUpperCase(),
    challenge: request.challenge,
    unsignedPayload: utils.uint8ToHex(transaction.serialize()).toUpperCase(),
    deadline: transaction.deadline.value.toString(),
  };
}

export function verifyAccountVerificationPayload(input: unknown): AccountVerificationResult {
  const parsed = accountVerificationVerifySchema.safeParse(input);
  if (!parsed.success) {
    return {
      accepted: false,
      reason: parsed.error.issues.map((issue) => issue.message).join('; '),
    };
  }
  if (parsed.data.payload.length % 2 !== 0) {
    return { accepted: false, reason: 'payload hex length must be even' };
  }

  try {
    const request = parsed.data;
    const transaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(request.payload));
    const facade = new SymbolFacade(request.network);
    const signerPublicKey = transaction.signerPublicKey.toString().toUpperCase();

    if (transaction.type.value !== models.TransactionType.TRANSFER.value) {
      return { accepted: false, reason: 'transaction is not transfer' };
    }
    if (transaction.network.value !== networkByte(request.network)) {
      return { accepted: false, reason: 'network mismatch' };
    }
    if (signerPublicKey !== request.signerPublicKey.toUpperCase()) {
      return { accepted: false, reason: 'signer public key mismatch' };
    }
    if (facade.network.publicKeyToAddress(new PublicKey(signerPublicKey)).toString() !== request.address) {
      return { accepted: false, reason: 'signer address mismatch' };
    }

    const transfer = transaction as unknown as {
      recipientAddress: { bytes: Uint8Array };
      mosaics?: unknown[];
      fee: { value: bigint };
      signature: { bytes: Uint8Array };
    };
    if (utils.uint8ToHex(transfer.recipientAddress.bytes).toUpperCase() !== utils.uint8ToHex(new Address(request.address).bytes).toUpperCase()) {
      return { accepted: false, reason: 'recipient address mismatch' };
    }
    if ((transfer.mosaics ?? []).length !== 0) {
      return { accepted: false, reason: 'verification transaction must not contain mosaics' };
    }
    if (transfer.fee.value !== 0n) {
      return { accepted: false, reason: 'verification transaction fee must be zero' };
    }
    if (readMessage(transaction) !== request.challenge) {
      return { accepted: false, reason: 'challenge mismatch' };
    }

    const signatureBytes = transfer.signature.bytes;
    if (!signatureBytes.some((byte) => byte !== 0)) {
      return { accepted: false, reason: 'transaction signature is missing' };
    }
    if (!facade.verifyTransaction(transaction, new Signature(signatureBytes))) {
      return { accepted: false, reason: 'transaction signature verification failed' };
    }

    return {
      accepted: true,
      reason: 'account_verification_passed',
      signerPublicKey,
      transactionHash: facade.hashTransaction(transaction).toString().toUpperCase(),
    };
  } catch {
    return { accepted: false, reason: 'account verification failed' };
  }
}
