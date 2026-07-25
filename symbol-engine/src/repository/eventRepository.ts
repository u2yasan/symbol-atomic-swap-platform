import type { Database } from '../db/pool.js';
import type { BlockchainEvent } from '../dto/events.js';
import type { ProjectionState } from './types.js';
import type { TransactionProjection } from './projectionRepository.js';

export type ProjectionUpdate = {
  transactionHash: string;
  network: string;
  state: ProjectionState;
  lastEventKey: string;
  updatedAt: number;
  blockHeight?: number;
  finalizedHeight?: number;
};

type ProjectionRow = {
  transaction_hash: string;
  network: string;
  state: ProjectionState;
  last_event_key: string;
  updated_at: string;
  block_height: string | null;
  finalized_height: string | null;
};

function toProjection(row: ProjectionRow): TransactionProjection {
  return {
    transactionHash: row.transaction_hash,
    network: row.network,
    state: row.state,
    lastEventKey: row.last_event_key,
    updatedAt: Number(row.updated_at),
    ...(row.block_height ? { blockHeight: Number(row.block_height) } : {}),
    ...(row.finalized_height ? { finalizedHeight: Number(row.finalized_height) } : {}),
  };
}

export class EventRepository {
  public constructor(private readonly db: Database) {}

  /**
   * Persists an event and its projection as one serialized transaction.
   *
   * The advisory lock covers the no-projection-yet case where SELECT FOR UPDATE
   * has no row to lock. This prevents two first events for the same transaction
   * from both validating against an empty projection.
   */
  public async apply(
    event: BlockchainEvent,
    idempotencyKey: string,
    buildProjection: (existing: TransactionProjection | null) => ProjectionUpdate,
  ): Promise<TransactionProjection> {
    const client = await this.db.connect();
    try {
      await client.query('BEGIN');
      await client.query(
        'SELECT pg_advisory_xact_lock(hashtextextended($1, 0))',
        [`${event.network}:${event.transactionHash.toUpperCase()}`],
      );

      const existingResult = await client.query<ProjectionRow>(
        `SELECT *
         FROM transaction_projections
         WHERE network = $1 AND transaction_hash = $2
         FOR UPDATE`,
        [event.network, event.transactionHash.toUpperCase()],
      );
      const existing = existingResult.rows[0] ? toProjection(existingResult.rows[0]) : null;

      const eventResult = await client.query(
        `INSERT INTO blockchain_events (
          idempotency_key, transaction_hash, network, event_type, signer_public_key,
          block_height, finalized_height, status_code, observed_at, payload
        )
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
        ON CONFLICT (idempotency_key) DO NOTHING
        RETURNING id`,
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

      if (eventResult.rowCount === 0) {
        if (!existing) {
          throw new Error('idempotency record exists without projection');
        }
        await client.query('COMMIT');
        return existing;
      }

      const next = buildProjection(existing);
      const projectionResult = await client.query<ProjectionRow>(
        `INSERT INTO transaction_projections (
          network, transaction_hash, state, last_event_key, updated_at, block_height, finalized_height
        )
        VALUES ($1, $2, $3, $4, $5, $6, $7)
        ON CONFLICT (network, transaction_hash)
        DO UPDATE SET
          state = EXCLUDED.state,
          last_event_key = EXCLUDED.last_event_key,
          updated_at = EXCLUDED.updated_at,
          block_height = COALESCE(EXCLUDED.block_height, transaction_projections.block_height),
          finalized_height = COALESCE(EXCLUDED.finalized_height, transaction_projections.finalized_height)
        RETURNING *`,
        [
          next.network,
          next.transactionHash.toUpperCase(),
          next.state,
          next.lastEventKey,
          next.updatedAt,
          next.blockHeight ?? null,
          next.finalizedHeight ?? null,
        ],
      );
      const projection = toProjection(projectionResult.rows[0]!);
      await client.query('COMMIT');
      return projection;
    } catch (error) {
      await client.query('ROLLBACK');
      throw error;
    } finally {
      client.release();
    }
  }

}
