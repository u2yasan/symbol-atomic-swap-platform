import dotenv from 'dotenv';
import { z } from 'zod';

dotenv.config();

const envSchema = z.object({
  SYMBOL_ENGINE_PORT: z.coerce.number().int().min(1).max(65535).default(3000),
  SYMBOL_ENGINE_API_TOKEN: z.string().min(32).optional(),
  SYMBOL_ENGINE_DATABASE_URL: z.string().url().optional(),
  SYMBOL_ENGINE_LISTENER_ENABLED: z.enum(['true', 'false']).default('false').transform((value) => value === 'true'),
  SYMBOL_ENGINE_LISTENER_ADDRESSES: z.string().default('').transform((value) => value.split(',')
    .map((address) => address.trim())
    .filter((address) => address.length > 0)),
  SYMBOL_ENGINE_RECONCILER_ENABLED: z.enum(['true', 'false']).default('false').transform((value) => value === 'true'),
  SYMBOL_ENGINE_RECONCILER_INTERVAL_MS: z.coerce.number().int().min(5000).max(3600000).default(30000),
  SYMBOL_NETWORK: z.enum(['mainnet', 'testnet']).default('testnet'),
  SYMBOL_NODE_URL: z.string().url().optional(),
  SYMBOL_WS_URL: z.string().url().optional(),
});

export type EngineEnv = z.infer<typeof envSchema>;

export function loadEnv(): EngineEnv {
  return envSchema.parse(process.env);
}
