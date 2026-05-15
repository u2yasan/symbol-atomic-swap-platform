import type { Database } from '../db/pool.js';
import type { ProjectionState } from './types.js';

export type TransactionProjection = {
  transactionHash: string;
  network: string;
  state: ProjectionState;
  lastEventKey: string;
  updatedAt: string;
  blockHeight?: number;
  finalizedHeight?: number;
};

type ProjectionRow = {
  transaction_hash: string;
  network: string;
  state: ProjectionState;
  last_event_key: string;
  updated_at: Date;
  block_height: string | null;
  finalized_height: string | null;
};

function toProjection(row: ProjectionRow): TransactionProjection {
  return {
    transactionHash: row.transaction_hash,
    network: row.network,
    state: row.state,
    lastEventKey: row.last_event_key,
    updatedAt: row.updated_at.toISOString(),
    ...(row.block_height ? { blockHeight: Number(row.block_height) } : {}),
    ...(row.finalized_height ? { finalizedHeight: Number(row.finalized_height) } : {}),
  };
}

export class ProjectionRepository {
  public constructor(private readonly db: Database) {}

  public async find(network: string, transactionHash: string): Promise<TransactionProjection | null> {
    const result = await this.db.query<ProjectionRow>(
      'SELECT * FROM transaction_projections WHERE network = $1 AND transaction_hash = $2',
      [network, transactionHash.toUpperCase()],
    );

    return result.rows[0] ? toProjection(result.rows[0]) : null;
  }

  public async findConfirmedAtOrBelow(network: string, finalizedHeight: number): Promise<TransactionProjection[]> {
    const result = await this.db.query<ProjectionRow>(
      `SELECT *
       FROM transaction_projections
       WHERE network = $1
         AND state = 'confirmed'
         AND block_height IS NOT NULL
         AND block_height <= $2
       ORDER BY block_height ASC, transaction_hash ASC`,
      [network, finalizedHeight],
    );

    return result.rows.map(toProjection);
  }

  public async findReconciliationCandidates(network: string): Promise<TransactionProjection[]> {
    const result = await this.db.query<ProjectionRow>(
      `SELECT *
       FROM transaction_projections
       WHERE network = $1
         AND (
           state IN ('announced', 'unconfirmed', 'confirmed')
           OR (state = 'failed' AND last_event_key LIKE '%:TransactionFailed:0:0:Success')
         )
       ORDER BY updated_at ASC
       LIMIT 500`,
      [network],
    );

    return result.rows.map(toProjection);
  }

  public async upsert(input: {
    network: string;
    transactionHash: string;
    state: ProjectionState;
    lastEventKey: string;
    updatedAt: string;
    blockHeight?: number;
    finalizedHeight?: number;
  }): Promise<TransactionProjection> {
    const result = await this.db.query<ProjectionRow>(
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
        input.network,
        input.transactionHash.toUpperCase(),
        input.state,
        input.lastEventKey,
        input.updatedAt,
        input.blockHeight ?? null,
        input.finalizedHeight ?? null,
      ],
    );

    return toProjection(result.rows[0]!);
  }
}
