import type { Database } from '../db/pool.js';
import type { AggregateType, QrPayload, SwapIntentRecord, SwapIntentState, NormalizedSwapIntent } from './types.js';

type SwapIntentRow = {
  id: string;
  correlation_id: string;
  network: string;
  intent_hash: string;
  state: SwapIntentState;
  aggregate_type: AggregateType;
  unsigned_payload: string;
  qr_payload: QrPayload;
  required_cosigners: string[];
  intent: NormalizedSwapIntent;
  signed_payload: string | null;
  transaction_hash: string | null;
  node_response: unknown | null;
};

function toRecord(row: SwapIntentRow): SwapIntentRecord {
  return {
    id: row.id,
    correlationId: row.correlation_id,
    network: row.network,
    intentHash: row.intent_hash,
    state: row.state,
    aggregateType: row.aggregate_type,
    unsignedPayload: row.unsigned_payload,
    qrPayload: row.qr_payload,
    requiredCosigners: row.required_cosigners,
    intent: row.intent,
    signedPayload: row.signed_payload,
    transactionHash: row.transaction_hash,
    nodeResponse: row.node_response,
  };
}

export class SwapIntentRepository {
  public constructor(private readonly db: Database) {}

  public async create(input: {
    id: string;
    correlationId: string;
    network: string;
    intentHash: string;
    aggregateType: AggregateType;
    unsignedPayload: string;
    qrPayload: QrPayload;
    requiredCosigners: string[];
    intent: NormalizedSwapIntent;
  }): Promise<SwapIntentRecord> {
    const result = await this.db.query<SwapIntentRow>(
      `INSERT INTO swap_intents (
        id, correlation_id, network, intent_hash, state, aggregate_type,
        unsigned_payload, qr_payload, required_cosigners, intent
      )
      VALUES ($1, $2, $3, $4, 'created', $5, $6, $7, $8, $9)
      RETURNING *`,
      [
        input.id,
        input.correlationId,
        input.network,
        input.intentHash,
        input.aggregateType,
        input.unsignedPayload,
        JSON.stringify(input.qrPayload),
        JSON.stringify(input.requiredCosigners),
        JSON.stringify(input.intent),
      ],
    );

    return toRecord(result.rows[0]!);
  }

  public async findByIntentHash(intentHash: string): Promise<SwapIntentRecord | null> {
    const result = await this.db.query<SwapIntentRow>('SELECT * FROM swap_intents WHERE intent_hash = $1', [intentHash]);
    return result.rows[0] ? toRecord(result.rows[0]) : null;
  }

  public async findById(id: string): Promise<SwapIntentRecord | null> {
    const result = await this.db.query<SwapIntentRow>('SELECT * FROM swap_intents WHERE id = $1', [id]);
    return result.rows[0] ? toRecord(result.rows[0]) : null;
  }

  public async findByTransactionHash(network: string, transactionHash: string): Promise<SwapIntentRecord | null> {
    const result = await this.db.query<SwapIntentRow>(
      'SELECT * FROM swap_intents WHERE network = $1 AND transaction_hash = $2',
      [network, transactionHash.toUpperCase()],
    );
    return result.rows[0] ? toRecord(result.rows[0]) : null;
  }

  public async findReconciliationCandidates(): Promise<SwapIntentRecord[]> {
    const result = await this.db.query<SwapIntentRow>(
      `SELECT *
       FROM swap_intents
       WHERE state IN ('signed', 'announced', 'partial_announced', 'partial_cosigned')
         AND transaction_hash IS NOT NULL
       ORDER BY updated_at ASC
       LIMIT 500`,
    );

    return result.rows.map(toRecord);
  }

  public async markSigned(intentHash: string, signedPayload: string, transactionHash: string): Promise<SwapIntentRecord> {
    const result = await this.db.query<SwapIntentRow>(
      `UPDATE swap_intents
       SET state = 'signed', signed_payload = $2, transaction_hash = $3, updated_at = now()
       WHERE intent_hash = $1
       RETURNING *`,
      [intentHash, signedPayload, transactionHash],
    );

    if (!result.rows[0]) {
      throw new Error('swap intent not found');
    }

    return toRecord(result.rows[0]);
  }

  public async markAnnounced(intentHash: string, nodeResponse: unknown): Promise<SwapIntentRecord> {
    const result = await this.db.query<SwapIntentRow>(
      `UPDATE swap_intents
       SET state = 'announced', node_response = $2, updated_at = now()
       WHERE intent_hash = $1 AND state = 'signed'
       RETURNING *`,
      [intentHash, JSON.stringify(nodeResponse)],
    );

    if (!result.rows[0]) {
      throw new Error('swap intent must be signed before announcement');
    }

    return toRecord(result.rows[0]);
  }

  public async markPartialAnnounced(intentHash: string, nodeResponse: unknown): Promise<SwapIntentRecord> {
    const result = await this.db.query<SwapIntentRow>(
      `UPDATE swap_intents
       SET state = 'partial_announced', node_response = $2, updated_at = now()
       WHERE intent_hash = $1 AND state = 'signed' AND aggregate_type = 'aggregate_bonded'
       RETURNING *`,
      [intentHash, JSON.stringify(nodeResponse)],
    );

    if (!result.rows[0]) {
      throw new Error('aggregate bonded intent must be signed before partial announcement');
    }

    return toRecord(result.rows[0]);
  }

  public async markPartialCosigned(intentHash: string, nodeResponse: unknown): Promise<SwapIntentRecord> {
    const result = await this.db.query<SwapIntentRow>(
      `UPDATE swap_intents
       SET state = 'partial_cosigned', node_response = $2, updated_at = now()
       WHERE intent_hash = $1 AND state IN ('partial_announced', 'partial_cosigned') AND aggregate_type = 'aggregate_bonded'
       RETURNING *`,
      [intentHash, JSON.stringify(nodeResponse)],
    );

    if (!result.rows[0]) {
      throw new Error('aggregate bonded intent must be partial announced before cosignature submission');
    }

    return toRecord(result.rows[0]);
  }

  public async markFailed(intentHash: string, nodeResponse: unknown): Promise<SwapIntentRecord | null> {
    const result = await this.db.query<SwapIntentRow>(
      `UPDATE swap_intents
       SET state = 'failed', node_response = $2, updated_at = now()
       WHERE intent_hash = $1
       RETURNING *`,
      [intentHash, JSON.stringify(nodeResponse)],
    );

    return result.rows[0] ? toRecord(result.rows[0]) : null;
  }
}
