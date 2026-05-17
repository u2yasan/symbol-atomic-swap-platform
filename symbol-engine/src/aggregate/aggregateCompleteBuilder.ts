import { createHash, randomUUID } from 'node:crypto';
import { PublicKey, utils } from 'symbol-sdk';
import { Address, descriptors, models, SymbolFacade } from 'symbol-sdk/symbol';
import { aggregateCompleteBuildRequestSchema } from '../dto/aggregateComplete.js';
import { canonicalize } from '../util/canonicalJson.js';
import { createQrPayload } from '../qr/qrPayloadService.js';
import type { QrPayload, NormalizedSwapIntent } from '../repository/types.js';

export type AggregateCompleteBuildResult = {
  intentId: string;
  correlationId: string;
  network: 'mainnet' | 'testnet';
  unsignedPayload: string;
  qrPayload: QrPayload;
  requiredCosigners: string[];
  deadline: string;
  intentHash: string;
};

function toMosaicId(value: string): bigint {
  return BigInt(`0x${value}`);
}

export function normalizeAggregateCompleteIntent(input: unknown): NormalizedSwapIntent {
  const request = aggregateCompleteBuildRequestSchema.parse(input);
  const legSigners = [...new Set(request.legs.map((leg) => leg.signerPublicKey.toUpperCase()))];
  const aggregateSigner = request.aggregateSignerPublicKey?.toUpperCase() ?? legSigners[0]!;
  const requiredCosigners = [
    aggregateSigner,
    ...legSigners.filter((signer) => signer !== aggregateSigner),
  ];

  return {
    ...request,
    aggregateSignerPublicKey: aggregateSigner,
    aggregateType: 'aggregate_complete',
    requiredCosigners,
    legs: request.legs.map((leg) => ({
      signerPublicKey: leg.signerPublicKey.toUpperCase(),
      recipientAddress: leg.recipientAddress,
      mosaicId: leg.mosaicId.toUpperCase(),
      amount: leg.amount,
    })),
  };
}

export function buildAggregateComplete(input: unknown): AggregateCompleteBuildResult & { intent: NormalizedSwapIntent } {
  const intent = normalizeAggregateCompleteIntent(input);
  const facade = new SymbolFacade(intent.network);

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
    new descriptors.AggregateCompleteTransactionV3Descriptor(transactionsHash, embeddedTransactions),
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
    network: intent.network,
    unsignedPayload,
    deadline,
    requiredCosigners: intent.requiredCosigners,
    intentHash,
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
    intent,
  };
}
