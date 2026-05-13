import { timingSafeEqual } from 'node:crypto';
import type { FastifyReply, FastifyRequest } from 'fastify';

function secureCompare(left: string, right: string): boolean {
  const leftBuffer = Buffer.from(left);
  const rightBuffer = Buffer.from(right);

  if (leftBuffer.length !== rightBuffer.length) {
    return false;
  }

  return timingSafeEqual(leftBuffer, rightBuffer);
}

export function extractToken(request: FastifyRequest): string | null {
  const authorization = request.headers.authorization;
  if (authorization?.startsWith('Bearer ')) {
    return authorization.slice('Bearer '.length);
  }

  const header = request.headers['x-symbol-engine-token'];
  return typeof header === 'string' ? header : null;
}

export function createApiAuthHook(expectedToken: string | undefined) {
  return async function apiAuthHook(request: FastifyRequest, reply: FastifyReply): Promise<void> {
    if (request.url === '/health') {
      return;
    }

    if (!expectedToken) {
      await reply.code(503).send({
        error: 'symbol_engine_api_token_missing',
        message: 'SYMBOL_ENGINE_API_TOKEN must be configured before protected API routes can be used.',
      });
      return;
    }

    const suppliedToken = extractToken(request);
    if (!suppliedToken || !secureCompare(suppliedToken, expectedToken)) {
      await reply.code(401).send({
        error: 'unauthorized',
        message: 'A valid Symbol Engine API token is required.',
      });
    }
  };
}
