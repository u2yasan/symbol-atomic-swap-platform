import type { FastifyInstance } from 'fastify';
import { buildAggregateBonded } from '../aggregate/aggregateBondedBuilder.js';
import { buildAggregateComplete } from '../aggregate/aggregateCompleteBuilder.js';
import { dispatchBlockchainEvent } from '../listener/eventDispatcher.js';
import { isTransactionFinalized } from '../monitor/finalizationMonitor.js';
import { verifyRootSignedPayload, verifySignedPayload } from '../transaction/signedPayloadVerifier.js';
import { verifyCosignature } from '../transaction/cosignatureVerifier.js';
import { assembleCompleteSignedPayload } from '../transaction/completePayloadAssembler.js';
import { buildRootSignedPayloadFromAggregateSignerSignature } from '../transaction/rootSignedPayloadBuilder.js';
import { announceVerifiedTransaction } from '../transaction/announceService.js';
import { announceSignedHashLock, buildHashLockTransaction } from '../transaction/hashLockService.js';
import { announcePartialAggregateBonded } from '../transaction/partialAnnouncementService.js';
import { announceAggregateBondedCosignature } from '../transaction/cosignatureService.js';
import { SymbolRestClient } from '../transaction/symbolRestClient.js';
import {
  announceSignedSecretLock,
  announceSignedSecretProof,
  buildSecretLockTransaction,
  buildSecretProofTransaction,
} from '../transaction/secretLockService.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import { accountPublicKeyParamsSchema, intentParamsSchema, projectionParamsSchema } from '../dto/readModels.js';
import { LARGE_PAYLOAD_BODY_LIMIT_BYTES, SMALL_BODY_LIMIT_BYTES } from './security.js';
import { intentResponse } from './intentResponse.js';

export type RouteDependencies = {
  network: 'mainnet' | 'testnet';
  nodeUrl: string | undefined;
  nodeRequestTimeoutMs: number;
  repositories: {
    swapIntents: SwapIntentRepository;
    events: EventRepository;
    projections: ProjectionRepository;
  };
};

export async function registerRoutes(app: FastifyInstance, dependencies: RouteDependencies): Promise<void> {
  app.get('/v1/projections/:network/:transactionHash', async (request, reply) => {
    const params = projectionParamsSchema.parse(request.params);
    const projection = await dependencies.repositories.projections.find(params.network, params.transactionHash);
    if (!projection) {
      return reply.code(404).send({
        error: 'projection_not_found',
      });
    }

    return reply.send(projection);
  });

  app.get('/v1/intents/:intentHash', async (request, reply) => {
    const params = intentParamsSchema.parse(request.params);
    const intent = await dependencies.repositories.swapIntents.findByIntentHash(params.intentHash.toUpperCase());
    if (!intent) {
      return reply.code(404).send({
        error: 'intent_not_found',
      });
    }

    return reply.send(intentResponse(intent));
  });

  app.get('/v1/accounts/:network/:address/public-key', async (request, reply) => {
    const params = accountPublicKeyParamsSchema.parse(request.params);
    if (params.network !== dependencies.network) {
      return reply.code(400).send({
        error: 'network_mismatch',
      });
    }
    if (!dependencies.nodeUrl) {
      return reply.code(503).send({
        error: 'symbol_node_unavailable',
      });
    }

    const lookup = await new SymbolRestClient(
      dependencies.nodeUrl,
      fetch,
      dependencies.nodeRequestTimeoutMs,
    ).getAccountPublicKey(params.address);
    if (!lookup.found) {
      return reply.code(404).send({
        error: 'account_public_key_not_found',
      });
    }

    return reply.send(lookup);
  });

  app.post('/v1/aggregate-complete/build', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const result = buildAggregateComplete(request.body);
    await dependencies.repositories.swapIntents.create({
      id: result.intentId,
      correlationId: result.correlationId,
      network: result.network,
      intentHash: result.intentHash,
      aggregateType: 'aggregate_complete',
      unsignedPayload: result.unsignedPayload,
      qrPayload: result.qrPayload,
      requiredCosigners: result.requiredCosigners,
      intent: result.intent,
    });

    const { intent: _intent, ...response } = result;
    return reply.code(201).send(response);
  });

  app.post('/v1/aggregate-bonded/build', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const result = buildAggregateBonded(request.body);
    await dependencies.repositories.swapIntents.create({
      id: result.intentId,
      correlationId: result.correlationId,
      network: result.network,
      intentHash: result.intentHash,
      aggregateType: 'aggregate_bonded',
      unsignedPayload: result.unsignedPayload,
      qrPayload: result.qrPayload,
      requiredCosigners: result.requiredCosigners,
      intent: result.intent,
    });

    const { intent: _intent, ...response } = result;
    return reply.code(201).send(response);
  });

  app.post('/v1/transactions/verify-signed-payload', {
    bodyLimit: LARGE_PAYLOAD_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const intentHash = typeof request.body === 'object'
      && request.body !== null
      && 'intentHash' in request.body
      && typeof request.body.intentHash === 'string'
      ? request.body.intentHash.toUpperCase()
      : '';
    const intent = intentHash ? await dependencies.repositories.swapIntents.findByIntentHash(intentHash) : null;
    const result = verifySignedPayload(request.body, intent);
    if (result.accepted && intent && result.transactionHash) {
      const payload = (request.body as { payload: string }).payload;
      await dependencies.repositories.swapIntents.markSigned(intent.intentHash, payload.toUpperCase(), result.transactionHash);
    }
    return reply.code(result.accepted ? 200 : 400).send(result);
  });

  app.post('/v1/transactions/verify-root-signed-payload', {
    bodyLimit: LARGE_PAYLOAD_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const intentHash = typeof request.body === 'object'
      && request.body !== null
      && 'intentHash' in request.body
      && typeof request.body.intentHash === 'string'
      ? request.body.intentHash.toUpperCase()
      : '';
    const intent = intentHash ? await dependencies.repositories.swapIntents.findByIntentHash(intentHash) : null;
    const result = verifyRootSignedPayload(request.body, intent);
    return reply.code(result.accepted ? 200 : 400).send(result);
  });

  app.post('/v1/transactions/verify-cosignature', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const intentHash = typeof request.body === 'object'
      && request.body !== null
      && 'intentHash' in request.body
      && typeof request.body.intentHash === 'string'
      ? request.body.intentHash.toUpperCase()
      : '';
    const intent = intentHash ? await dependencies.repositories.swapIntents.findByIntentHash(intentHash) : null;
    const result = verifyCosignature(request.body, intent);
    return reply.code(result.accepted ? 200 : 400).send(result);
  });

  app.post('/v1/transactions/assemble-complete-payload', {
    bodyLimit: LARGE_PAYLOAD_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const intentHash = typeof request.body === 'object'
      && request.body !== null
      && 'intentHash' in request.body
      && typeof request.body.intentHash === 'string'
      ? request.body.intentHash.toUpperCase()
      : '';
    const intent = intentHash ? await dependencies.repositories.swapIntents.findByIntentHash(intentHash) : null;
    const result = assembleCompleteSignedPayload(request.body, intent);
    if (result.accepted && intent && result.payload && result.transactionHash) {
      await dependencies.repositories.swapIntents.markSigned(intent.intentHash, result.payload, result.transactionHash);
    }
    return reply.code(result.accepted ? 200 : 400).send(result);
  });

  app.post('/v1/transactions/root-signed-payload', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const intentHash = typeof request.body === 'object'
      && request.body !== null
      && 'intentHash' in request.body
      && typeof request.body.intentHash === 'string'
      ? request.body.intentHash.toUpperCase()
      : '';
    const intent = intentHash ? await dependencies.repositories.swapIntents.findByIntentHash(intentHash) : null;
    const result = buildRootSignedPayloadFromAggregateSignerSignature(request.body, intent);
    if (result.accepted && intent && result.payload && result.transactionHash) {
      await dependencies.repositories.swapIntents.markSigned(intent.intentHash, result.payload, result.transactionHash);
    }
    return reply.code(result.accepted ? 200 : 400).send(result);
  });

  app.post('/v1/transactions/announce', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 20,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const result = await announceVerifiedTransaction(request.body, {
      nodeUrl: dependencies.nodeUrl,
      nodeRequestTimeoutMs: dependencies.nodeRequestTimeoutMs,
      swapIntents: dependencies.repositories.swapIntents,
      events: dependencies.repositories.events,
      projections: dependencies.repositories.projections,
    });
    return reply.code(202).send(result);
  });

  app.post('/v1/transactions/announce-partial', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 20,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const result = await announcePartialAggregateBonded(request.body, {
      nodeUrl: dependencies.nodeUrl,
      nodeRequestTimeoutMs: dependencies.nodeRequestTimeoutMs,
      swapIntents: dependencies.repositories.swapIntents,
      events: dependencies.repositories.events,
      projections: dependencies.repositories.projections,
    });
    return reply.code(202).send(result);
  });

  app.post('/v1/transactions/cosignature', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const result = await announceAggregateBondedCosignature(request.body, {
      nodeUrl: dependencies.nodeUrl,
      nodeRequestTimeoutMs: dependencies.nodeRequestTimeoutMs,
      swapIntents: dependencies.repositories.swapIntents,
      events: dependencies.repositories.events,
      projections: dependencies.repositories.projections,
    });
    return reply.code(202).send(result);
  });

  app.post('/v1/hash-lock/build', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const result = await buildHashLockTransaction(request.body, dependencies.repositories.swapIntents);
    return reply.code(201).send(result);
  });

  app.post('/v1/hash-lock/announce', {
    bodyLimit: LARGE_PAYLOAD_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 20,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const result = await announceSignedHashLock(request.body, {
      nodeUrl: dependencies.nodeUrl,
      nodeRequestTimeoutMs: dependencies.nodeRequestTimeoutMs,
      swapIntents: dependencies.repositories.swapIntents,
    });
    return reply.code(202).send(result);
  });

  app.post('/v1/secret-lock/build', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    return reply.code(201).send(buildSecretLockTransaction(request.body));
  });

  app.post('/v1/secret-lock/announce', {
    bodyLimit: LARGE_PAYLOAD_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 20,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    return reply.code(202).send(await announceSignedSecretLock(
      request.body,
      dependencies.nodeUrl,
      dependencies.nodeRequestTimeoutMs,
    ));
  });

  app.post('/v1/secret-proof/build', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 30,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    return reply.code(201).send(buildSecretProofTransaction(request.body));
  });

  app.post('/v1/secret-proof/announce', {
    bodyLimit: LARGE_PAYLOAD_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 20,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    return reply.code(202).send(await announceSignedSecretProof(
      request.body,
      dependencies.nodeUrl,
      dependencies.nodeRequestTimeoutMs,
    ));
  });

  app.post('/v1/events', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
    config: {
      rateLimit: {
        max: 60,
        timeWindow: '1 minute',
      },
    },
  }, async (request, reply) => {
    const projection = await dispatchBlockchainEvent(request.body, dependencies.repositories);
    return reply.code(202).send(projection);
  });

  app.post('/v1/finalization/check', {
    bodyLimit: SMALL_BODY_LIMIT_BYTES,
  }, async (request, reply) => {
    return reply.send({
      finalized: isTransactionFinalized(request.body),
    });
  });
}
