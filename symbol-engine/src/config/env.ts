import dotenv from 'dotenv';
import { z } from 'zod';

dotenv.config();

const weakApiTokenValues = new Set([
  '0123456789abcdef0123456789abcdef',
  'replace-with-at-least-32-random-characters',
  'change-me',
  'changeme',
  'development-token',
]);

function isRepeatedPattern(value: string): boolean {
  for (let length = 1; length <= Math.floor(value.length / 2); length += 1) {
    if (value.length % length !== 0) {
      continue;
    }

    const pattern = value.slice(0, length);
    if (pattern.repeat(value.length / length) === value) {
      return true;
    }
  }

  return false;
}

function uniqueCharacterCount(value: string): number {
  return new Set(value).size;
}

function isSecureHttpUrl(value: string | undefined): boolean {
  return value === undefined || new URL(value).protocol === 'https:';
}

function isSecureWebSocketUrl(value: string | undefined): boolean {
  return value === undefined || new URL(value).protocol === 'wss:';
}

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
  SYMBOL_ENGINE_EXPOSE_NODE_ENDPOINTS: z.enum(['true', 'false']).default('false').transform((value) => value === 'true'),
  SYMBOL_NODE_REQUEST_TIMEOUT_MS: z.coerce.number().int().min(1000).max(60000).default(10000),
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

  if (value.NODE_ENV === 'production' && token && uniqueCharacterCount(token) < 8) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_ENGINE_API_TOKEN'],
      message: 'SYMBOL_ENGINE_API_TOKEN must look randomly generated.',
    });
  }

  if (value.NODE_ENV === 'production' && token && isRepeatedPattern(token)) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_ENGINE_API_TOKEN'],
      message: 'SYMBOL_ENGINE_API_TOKEN must not use a repeated pattern.',
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

  if (value.NODE_ENV === 'production' && !isSecureHttpUrl(value.SYMBOL_NODE_URL)) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_NODE_URL'],
      message: 'SYMBOL_NODE_URL must use https in production.',
    });
  }

  if (value.NODE_ENV === 'production' && !isSecureWebSocketUrl(value.SYMBOL_WS_URL)) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['SYMBOL_WS_URL'],
      message: 'SYMBOL_WS_URL must use wss in production.',
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
