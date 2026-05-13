import { z } from 'zod';

const finalizationCheckSchema = z.object({
  transactionHash: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  confirmedHeight: z.number().int().positive(),
  finalizedHeight: z.number().int().positive(),
});

export type FinalizationCheck = z.infer<typeof finalizationCheckSchema>;

export function isTransactionFinalized(input: unknown): boolean {
  const check = finalizationCheckSchema.parse(input);
  return check.confirmedHeight <= check.finalizedHeight;
}
