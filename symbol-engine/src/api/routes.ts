import type { FastifyInstance } from 'fastify';
import { buildAggregateComplete } from '../aggregate/aggregateCompleteBuilder.js';
import { dispatchBlockchainEvent } from '../listener/eventDispatcher.js';
import { isTransactionFinalized } from '../monitor/finalizationMonitor.js';
import { verifySignedPayload } from '../transaction/signedPayloadVerifier.js';
import { announceVerifiedTransaction } from '../transaction/announceService.js';
import type { SwapIntentRepository } from '../repository/swapIntentRepository.js';
import type { EventRepository } from '../repository/eventRepository.js';
import type { ProjectionRepository } from '../repository/projectionRepository.js';
import { intentParamsSchema, projectionParamsSchema } from '../dto/readModels.js';

export type RouteDependencies = {
  nodeUrl: string | undefined;
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

    return reply.send({
      id: intent.id,
      correlationId: intent.correlationId,
      network: intent.network,
      intentHash: intent.intentHash,
      state: intent.state,
      aggregateType: intent.aggregateType,
      unsignedPayload: intent.unsignedPayload,
      qrPayload: intent.qrPayload,
      requiredCosigners: intent.requiredCosigners,
      intent: intent.intent,
      transactionHash: intent.transactionHash,
    });
  });

  app.post('/v1/aggregate-complete/build', async (request, reply) => {
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

  app.post('/v1/transactions/verify-signed-payload', async (request, reply) => {
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

  app.post('/v1/transactions/announce', async (request, reply) => {
    const result = await announceVerifiedTransaction(request.body, {
      nodeUrl: dependencies.nodeUrl,
      swapIntents: dependencies.repositories.swapIntents,
      events: dependencies.repositories.events,
      projections: dependencies.repositories.projections,
    });
    return reply.code(202).send(result);
  });

  app.post('/v1/events', async (request, reply) => {
    const projection = await dispatchBlockchainEvent(request.body, dependencies.repositories);
    return reply.code(202).send(projection);
  });

  app.post('/v1/finalization/check', async (request, reply) => {
    return reply.send({
      finalized: isTransactionFinalized(request.body),
    });
  });
}
