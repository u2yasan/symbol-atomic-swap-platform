import { z } from 'zod';
import { networkKeySchema } from '../config/networkProfile.js';
import { transactionHashSchema } from './events.js';

export const projectionParamsSchema = z.object({
  network: networkKeySchema,
  transactionHash: transactionHashSchema,
});

export const intentParamsSchema = z.object({
  intentHash: transactionHashSchema,
});

export const accountPublicKeyParamsSchema = z.object({
  network: networkKeySchema,
  address: z.string().regex(/^[A-Z2-7]{39}$/),
});

export const mosaicMetadataParamsSchema = z.object({
  network: networkKeySchema,
  mosaicId: z.string().regex(/^[0-9A-Fa-f]{16}$/),
});
