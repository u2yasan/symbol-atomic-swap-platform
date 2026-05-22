import { z } from 'zod';
import { networkKeySchema } from '../config/networkProfile.js';
import { aggregateTransferLegSchema, integerStringSchema, mosaicIdSchema } from './aggregateComplete.js';

export const hashLockRequirementsSchema = z.object({
  mosaicId: mosaicIdSchema,
  amount: integerStringSchema,
  duration: z.number().int().min(1).max(5760),
});

export const aggregateBondedBuildRequestSchema = z.object({
  network: networkKeySchema,
  deadlineHours: z.number().int().min(1).max(48),
  maxFee: integerStringSchema.optional(),
  legs: z.array(aggregateTransferLegSchema).length(2),
  correlationId: z.string().min(8).max(128),
  hashLock: hashLockRequirementsSchema,
}).superRefine((value, context) => {
  const [first, second] = value.legs;
  if (!first || !second) {
    return;
  }

  if (first.signerPublicKey.toLowerCase() === second.signerPublicKey.toLowerCase()) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['legs'],
      message: 'aggregate bonded swap requires two distinct signer public keys',
    });
  }
});

export type AggregateBondedBuildRequest = z.infer<typeof aggregateBondedBuildRequestSchema>;
