import type { Database } from './pool.js';

const migrations = [
  {
    id: '0001_core_tables',
    sql: `
      CREATE TABLE IF NOT EXISTS symbol_engine_migrations (
        id text PRIMARY KEY,
        applied_at timestamptz NOT NULL DEFAULT now()
      );

      CREATE TABLE IF NOT EXISTS swap_intents (
        id text PRIMARY KEY,
        correlation_id text NOT NULL,
        network text NOT NULL,
        intent_hash text NOT NULL UNIQUE,
        state text NOT NULL,
        aggregate_type text NOT NULL,
        unsigned_payload text NOT NULL,
        qr_payload jsonb NOT NULL,
        required_cosigners jsonb NOT NULL,
        intent jsonb NOT NULL,
        signed_payload text,
        transaction_hash text,
        node_response jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
      );

      CREATE INDEX IF NOT EXISTS swap_intents_correlation_id_idx
        ON swap_intents (correlation_id);

      CREATE INDEX IF NOT EXISTS swap_intents_transaction_hash_idx
        ON swap_intents (transaction_hash);

      CREATE TABLE IF NOT EXISTS blockchain_events (
        id bigserial PRIMARY KEY,
        idempotency_key text NOT NULL UNIQUE,
        transaction_hash text NOT NULL,
        network text NOT NULL,
        event_type text NOT NULL,
        signer_public_key text,
        block_height bigint,
        finalized_height bigint,
        status_code text,
        observed_at timestamptz NOT NULL,
        payload jsonb NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
      );

      CREATE TABLE IF NOT EXISTS transaction_projections (
        network text NOT NULL,
        transaction_hash text NOT NULL,
        state text NOT NULL,
        last_event_key text NOT NULL,
        block_height bigint,
        finalized_height bigint,
        updated_at timestamptz NOT NULL,
        PRIMARY KEY (network, transaction_hash)
      );
    `,
  },
];

export async function runMigrations(db: Database): Promise<void> {
  await db.query(`
    CREATE TABLE IF NOT EXISTS symbol_engine_migrations (
      id text PRIMARY KEY,
      applied_at timestamptz NOT NULL DEFAULT now()
    )
  `);

  for (const migration of migrations) {
    await db.query('BEGIN');
    try {
      const existing = await db.query('SELECT id FROM symbol_engine_migrations WHERE id = $1', [migration.id]);
      if (existing.rowCount === 0) {
        await db.query(migration.sql);
        await db.query('INSERT INTO symbol_engine_migrations (id) VALUES ($1)', [migration.id]);
      }
      await db.query('COMMIT');
    } catch (error) {
      await db.query('ROLLBACK');
      throw error;
    }
  }
}
