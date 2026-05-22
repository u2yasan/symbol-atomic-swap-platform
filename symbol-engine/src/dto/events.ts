import { z } from 'zod';
import { networkKeySchema } from '../config/networkProfile.js';

export const transactionHashSchema = z.string().regex(/^[0-9A-Fa-f]{64}$/, 'transaction hash must be 32-byte hex');
export const publicKeySchema = z.string().regex(/^[0-9A-Fa-f]{64}$/, 'public key must be 32-byte hex');

export const blockchainEventSchema = z.object({
  transactionHash: transactionHashSchema,
  network: networkKeySchema,
  eventType: z.enum([
    'TransactionAnnounced',
    'TransactionUnconfirmed',
    'TransactionConfirmed',
    'TransactionFinalized',
    'TransactionFailed',
    'TransactionRolledBack',
    'PartialTransactionAdded',
    'CosignatureReceived',
  ]),
  signerPublicKey: publicKeySchema.optional(),
  blockHeight: z.number().int().positive().optional(),
  finalizedHeight: z.number().int().positive().optional(),
  statusCode: z.string().max(128).optional(),
  observedAt: z.string().datetime(),
});

export type BlockchainEvent = z.infer<typeof blockchainEventSchema>;
