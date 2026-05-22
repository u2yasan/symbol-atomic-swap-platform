import { createHash, randomUUID } from 'node:crypto';
import { PublicKey, utils } from 'symbol-sdk';
import { Address, descriptors, models, SymbolFacade } from 'symbol-sdk/symbol';
import { createSymbolFacadeForNetwork } from '../config/networkProfile.js';
import { aggregateBondedBuildRequestSchema } from '../dto/aggregateBonded.js';
import { canonicalize } from '../util/canonicalJson.js';
import { createQrPayload } from '../qr/qrPayloadService.js';
import type { NormalizedBondedSwapIntent, QrPayload } from '../repository/types.js';

export type AggregateBondedBuildResult = {
  intentId: string;
  correlationId: string;
  network: string;
  unsignedPayload: string;
  qrPayload: QrPayload;
  requiredCosigners: string[];
  deadline: string;
  intentHash: string;
  hashLock: {
    mosaicId: string;
    amount: string;
    duration: number;
  };
};

function toMosaicId(value: string): bigint {
  return BigInt(`0x${value}`);
}

export function normalizeAggregateBondedIntent(input: unknown): NormalizedBondedSwapIntent {
  const request = aggregateBondedBuildRequestSchema.parse(input);
  const legSigners = request.legs.map((leg) => leg.signerPublicKey.toUpperCase());
  const aggregateSigner = legSigners[1] ?? legSigners[0];
  if (!aggregateSigner) {
    throw new Error('aggregate bonded intent requires at least one signer');
  }
  const requiredCosigners = [
    aggregateSigner,
    ...legSigners.filter((signer) => signer !== aggregateSigner),
  ];

  return {
    ...request,
    aggregateType: 'aggregate_bonded',
    requiredCosigners,
    legs: request.legs.map((leg) => ({
      signerPublicKey: leg.signerPublicKey.toUpperCase(),
      recipientAddress: leg.recipientAddress,
      mosaicId: leg.mosaicId.toUpperCase(),
      amount: leg.amount,
    })),
    hashLock: {
      mosaicId: request.hashLock.mosaicId.toUpperCase(),
      amount: request.hashLock.amount,
      duration: request.hashLock.duration,
    },
  };
}

export function buildAggregateBonded(input: unknown): AggregateBondedBuildResult & { intent: NormalizedBondedSwapIntent } {
  const intent = normalizeAggregateBondedIntent(input);
  const { facade } = createSymbolFacadeForNetwork(intent.network);

  const embeddedTransactions = intent.legs.map((leg) => facade.createEmbeddedTransactionFromTypedDescriptor(
    new descriptors.TransferTransactionV1Descriptor(
      new Address(leg.recipientAddress),
      [
        new descriptors.UnresolvedMosaicDescriptor(
          new models.UnresolvedMosaicId(toMosaicId(leg.mosaicId)),
          new models.Amount(BigInt(leg.amount)),
        ),
      ],
      undefined,
    ),
    new PublicKey(leg.signerPublicKey),
  ));

  const transactionsHash = SymbolFacade.hashEmbeddedTransactions(embeddedTransactions);
  const aggregateTransaction = facade.createTransactionFromTypedDescriptor(
    new descriptors.AggregateBondedTransactionV3Descriptor(transactionsHash, embeddedTransactions),
    new PublicKey(intent.requiredCosigners[0]!),
    100,
    intent.deadlineHours * 60 * 60,
    Math.max(0, intent.requiredCosigners.length - 1),
  );

  if (intent.maxFee) {
    aggregateTransaction.fee = new models.Amount(BigInt(intent.maxFee));
  }

  const unsignedPayload = utils.uint8ToHex(aggregateTransaction.serialize()).toUpperCase();
  const deadline = aggregateTransaction.deadline.value.toString();
  const intentHash = createHash('sha256').update(canonicalize(intent)).digest('hex').toUpperCase();
  const qrPayload = createQrPayload({
    type: 'symbol-aggregate-bonded',
    network: intent.network,
    unsignedPayload,
    deadline,
    requiredCosigners: intent.requiredCosigners,
    intentHash,
    hashLock: intent.hashLock,
  });

  return {
    intentId: randomUUID(),
    correlationId: intent.correlationId,
    network: intent.network,
    unsignedPayload,
    qrPayload,
    requiredCosigners: intent.requiredCosigners,
    deadline,
    intentHash,
    hashLock: intent.hashLock,
    intent,
  };
}
