import { z } from 'zod';

export const publicKeySchema = z.string().regex(/^[0-9A-Fa-f]{64}$/, 'public key must be 32-byte hex');
export const addressSchema = z.string().min(39).max(46);
export const mosaicIdSchema = z.string().regex(/^[0-9A-Fa-f]{16}$/, 'mosaic id must be 8-byte hex');
export const integerStringSchema = z.string().regex(/^[1-9][0-9]*$/, 'value must be a positive integer string');

export const aggregateTransferLegSchema = z.object({
  signerPublicKey: publicKeySchema,
  recipientAddress: addressSchema,
  mosaicId: mosaicIdSchema,
  amount: integerStringSchema,
});

export const aggregateCompleteBuildRequestSchema = z.object({
  network: z.enum(['mainnet', 'testnet']),
  deadlineHours: z.number().int().min(1).max(6),
  maxFee: integerStringSchema.optional(),
  legs: z.array(aggregateTransferLegSchema).length(2),
  correlationId: z.string().min(8).max(128),
}).superRefine((value, context) => {
  const [first, second] = value.legs;
  if (!first || !second) {
    return;
  }

  if (first.signerPublicKey.toLowerCase() === second.signerPublicKey.toLowerCase()) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['legs'],
      message: 'aggregate complete swap requires two distinct signer public keys',
    });
  }
});

export type AggregateCompleteBuildRequest = z.infer<typeof aggregateCompleteBuildRequestSchema>;
