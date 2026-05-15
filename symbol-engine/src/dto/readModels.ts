import { z } from 'zod';
import { transactionHashSchema } from './events.js';

export const projectionParamsSchema = z.object({
  network: z.enum(['mainnet', 'testnet']),
  transactionHash: transactionHashSchema,
});

export const intentParamsSchema = z.object({
  intentHash: transactionHashSchema,
});

export const accountPublicKeyParamsSchema = z.object({
  network: z.enum(['mainnet', 'testnet']),
  address: z.string().regex(/^[NT][A-Z2-7]{38}$/),
});
