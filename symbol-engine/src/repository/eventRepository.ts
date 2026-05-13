import type { Database } from '../db/pool.js';
import type { BlockchainEvent } from '../dto/events.js';

export class DuplicateEventError extends Error {}

export class EventRepository {
  public constructor(private readonly db: Database) {}

  public async insert(event: BlockchainEvent, idempotencyKey: string): Promise<void> {
    try {
      await this.db.query(
        `INSERT INTO blockchain_events (
          idempotency_key, transaction_hash, network, event_type, signer_public_key,
          block_height, finalized_height, status_code, observed_at, payload
        )
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
        [
          idempotencyKey,
          event.transactionHash.toUpperCase(),
          event.network,
          event.eventType,
          event.signerPublicKey?.toUpperCase() ?? null,
          event.blockHeight ?? null,
          event.finalizedHeight ?? null,
          event.statusCode ?? null,
          event.observedAt,
          JSON.stringify(event),
        ],
      );
    } catch (error) {
      if (
        typeof error === 'object'
        && error !== null
        && 'code' in error
        && (error as { code?: string }).code === '23505'
      ) {
        throw new DuplicateEventError('duplicate blockchain event');
      }
      throw error;
    }
  }
}
