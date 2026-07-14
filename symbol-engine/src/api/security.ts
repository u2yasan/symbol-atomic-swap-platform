import { createHash } from 'node:crypto';
import helmet from '@fastify/helmet';
import rateLimit from '@fastify/rate-limit';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import type { LoggerOptions } from 'pino';
import { extractToken, secureCompare } from './auth.js';

export const DEFAULT_BODY_LIMIT_BYTES = 64 * 1024;
export const SMALL_BODY_LIMIT_BYTES = 8 * 1024;
export const LARGE_PAYLOAD_BODY_LIMIT_BYTES = 256 * 1024;

export const DEFAULT_RATE_LIMIT_MAX = 120;
export const DEFAULT_RATE_LIMIT_WINDOW = '1 minute';

const REDACTED = '[REDACTED]';

export function createLoggerOptions(): LoggerOptions {
  return {
    redact: {
      censor: REDACTED,
      paths: [
        'req.headers.authorization',
        'req.headers.x-symbol-engine-token',
        'req.body.payload',
        'req.body.signedPayload',
        'req.body.unsignedPayload',
        'req.body.qrPayload',
        'req.body.secret',
        'req.body.proof',
        'body.payload',
        'body.signedPayload',
        'body.unsignedPayload',
        'body.qrPayload',
        'body.secret',
        'body.proof',
        'payload',
        'signedPayload',
        'unsignedPayload',
        'qrPayload',
        'secret',
        'proof',
        'nodeUrl',
        'wsUrl',
        'authorization',
        'x-symbol-engine-token',
        'SYMBOL_ENGINE_API_TOKEN',
        'SYMBOL_ENGINE_DATABASE_URL',
        'SYMBOL_NODE_URL',
        'SYMBOL_WS_URL',
        '*.payload',
        '*.signedPayload',
        '*.unsignedPayload',
        '*.qrPayload',
        '*.secret',
        '*.proof',
        '*.nodeUrl',
        '*.wsUrl',
      ],
    },
  };
}

function tokenRateLimitKey(token: string): string {
  return createHash('sha256').update(token).digest('hex');
}

/**
 * Builds the rate-limit key generator.
 *
 * A dedicated per-token bucket is only granted once the supplied token matches
 * the configured API token. Unauthenticated or invalid-token requests all fall
 * back to a per-IP bucket, so an attacker cannot escape the limit by rotating
 * random Bearer values (each of which would otherwise mint a fresh bucket).
 */
export function createRateLimitKey(expectedToken: string | undefined) {
  return function rateLimitKey(request: FastifyRequest): string {
    const token = extractToken(request);
    if (token && expectedToken && secureCompare(token, expectedToken)) {
      return `token:${tokenRateLimitKey(token)}`;
    }

    return `ip:${request.ip}`;
  };
}

export async function registerSecurity(
  app: FastifyInstance,
  expectedToken?: string,
): Promise<void> {
  await app.register(helmet, {
    contentSecurityPolicy: false,
    crossOriginEmbedderPolicy: false,
    hidePoweredBy: true,
    noSniff: true,
    frameguard: {
      action: 'deny',
    },
  });

  await app.register(rateLimit, {
    global: true,
    max: DEFAULT_RATE_LIMIT_MAX,
    timeWindow: DEFAULT_RATE_LIMIT_WINDOW,
    keyGenerator: createRateLimitKey(expectedToken),
    allowList: (request) => request.url === '/health',
    errorResponseBuilder: (_request, context) => ({
      statusCode: context.statusCode,
      error: 'rate_limit_exceeded',
      message: 'Too many requests.',
    }),
  });
}
