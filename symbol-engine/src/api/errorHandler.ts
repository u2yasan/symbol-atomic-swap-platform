import type { FastifyError, FastifyReply, FastifyRequest } from 'fastify';
import { ZodError } from 'zod';
import { InvalidStateTransitionError } from '../listener/eventDispatcher.js';
import { InvalidAnnouncementError } from '../transaction/announceService.js';
import { InvalidHashLockError } from '../transaction/hashLockService.js';
import { InvalidSecretLockError } from '../transaction/secretLockService.js';
import { SymbolNodeUnavailableError } from '../transaction/symbolNodeErrors.js';

function hasClientStatusCode(error: FastifyError): error is FastifyError & { statusCode: number } {
  return typeof error.statusCode === 'number' && error.statusCode >= 400 && error.statusCode < 500;
}

function clientErrorCode(error: FastifyError): string {
  if (error.statusCode === 413) {
    return 'payload_too_large';
  }

  if (error.statusCode === 429) {
    return 'rate_limit_exceeded';
  }

  return 'request_error';
}

export function handleApiError(error: FastifyError, _request: FastifyRequest, reply: FastifyReply) {
  if (error instanceof ZodError) {
    return reply.code(400).send({
      error: 'validation_failed',
      issues: error.issues,
    });
  }

  if (error instanceof InvalidStateTransitionError) {
    return reply.code(error.statusCode).send({
      error: 'invalid_state_transition',
      message: error.message,
    });
  }

  if (error instanceof InvalidAnnouncementError) {
    return reply.code(error.statusCode).send({
      error: 'invalid_announcement',
      message: error.message,
    });
  }

  if (error instanceof InvalidHashLockError) {
    return reply.code(error.statusCode).send({
      error: 'invalid_hash_lock',
      message: error.message,
    });
  }

  if (error instanceof InvalidSecretLockError) {
    return reply.code(error.statusCode).send({
      error: 'invalid_secret_lock',
      message: error.message,
    });
  }

  if (error instanceof SymbolNodeUnavailableError) {
    return reply.code(error.statusCode).send({
      error: 'symbol_node_unavailable',
      message: error.message,
    });
  }

  if (hasClientStatusCode(error)) {
    return reply.code(error.statusCode).send({
      error: clientErrorCode(error),
      message: error.message,
    });
  }

  reply.server.log.error(error);
  return reply.code(500).send({
    error: 'internal_error',
  });
}
