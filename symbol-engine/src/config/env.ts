import dotenv from 'dotenv';
import { z } from 'zod';

dotenv.config();

const weakApiTokenValues = new Set([
  'replace-with-at-least-32-random-characters',
  'change-me',
  'changeme',
  'development-token',
]);

const optionalUrlSchema = z.preprocess(
  (value) => value === '' ? undefined : value,
  z.string().url().optional(),
);

const optionalNonEmptyStringSchema = z.preprocess(
  (value) => value === '' ? undefined : value,
  z.string().optional(),
);

const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  SYMBOL_ENGINE_PORT: z.coerce.number().int().min(1).max(65535).default(3000),
  SYMBOL_ENGINE_API_TOKEN: optionalNonEmptyStringSchema,
  SYMBOL_ENGINE_DATABASE_URL: z.string().url().optional(),
  SYMBOL_ENGINE_LISTENER_ENABLED: z.enum(['true', 'false']).default('false').transform((value) => value === 'true'),
  SYMBOL_ENGINE_LISTENER_ADDRESSES: z.string().default('').transform((value) => value.split(',')
    .map((address) => address.trim())
    .filter((address) => address.length > 0)),
  SYMBOL_ENGINE_RECONCILER_ENABLED: z.enum(['true', 'false']).default('false').transform((value) => value === 'true'),
  SYMBOL_ENGINE_RECONCILER_INTERVAL_MS: z.coerce.number().int().min(5000).max(3600000).default(30000),
  SYMBOL_NETWORK: z.enum(['mainnet', 'testnet']).default('testnet'),
  SYMBOL_NODE_URL: optionalUrlSchema,
  SYMBOL_WS_URL: optionalUrlSchema,
}).superRefine((value, context) => {
  const token = value.SYMBOL_ENGINE_API_TOKEN;
  if (token && token.length < 32) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_ENGINE_API_TOKEN'],
      message: 'SYMBOL_ENGINE_API_TOKEN must be at least 32 characters.',
    });
  }

  if (token && weakApiTokenValues.has(token.toLowerCase())) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_ENGINE_API_TOKEN'],
      message: 'SYMBOL_ENGINE_API_TOKEN must not use a placeholder value.',
    });
  }

  if (value.NODE_ENV === 'production' && !token) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_ENGINE_API_TOKEN'],
      message: 'SYMBOL_ENGINE_API_TOKEN is required in production.',
    });
  }

  if (value.NODE_ENV === 'production' && !value.SYMBOL_ENGINE_DATABASE_URL) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_ENGINE_DATABASE_URL'],
      message: 'SYMBOL_ENGINE_DATABASE_URL is required in production.',
    });
  }
});

export type EngineEnv = z.infer<typeof envSchema>;

export function loadEnvFrom(input: NodeJS.ProcessEnv): EngineEnv {
  return envSchema.parse(input);
}

export function loadEnv(): EngineEnv {
  return loadEnvFrom(process.env);
}
