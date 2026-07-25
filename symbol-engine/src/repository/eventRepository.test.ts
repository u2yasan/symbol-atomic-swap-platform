import assert from 'node:assert/strict';
import test from 'node:test';
import type { Database } from '../db/pool.js';
import type { BlockchainEvent } from '../dto/events.js';
import { EventRepository } from './eventRepository.js';

const transactionHash = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

const event: BlockchainEvent = {
  transactionHash,
  network: 'testnet',
  eventType: 'TransactionConfirmed',
  blockHeight: 10,
  observedAt: '2026-05-12T15:21:00.000Z',
};

function fakeDatabase(options: { projectionWriteFails?: boolean } = {}) {
  const queries: string[] = [];
  let released = false;
  const client = {
    query: async (sql: string) => {
      const normalized = sql.replace(/\s+/g, ' ').trim();
      queries.push(normalized);
      if (normalized.includes('FROM transaction_projections') && normalized.endsWith('FOR UPDATE')) {
        return { rows: [], rowCount: 0 };
      }
      if (normalized.startsWith('INSERT INTO blockchain_events')) {
        return { rows: [{ id: '1' }], rowCount: 1 };
      }
      if (normalized.startsWith('INSERT INTO transaction_projections')) {
        if (options.projectionWriteFails) {
          throw new Error('projection write failed');
        }
        return {
          rows: [{
            transaction_hash: transactionHash,
            network: 'testnet',
            state: 'confirmed',
            last_event_key: 'event-key',
            updated_at: '1778599260',
            block_height: '10',
            finalized_height: null,
          }],
          rowCount: 1,
        };
      }
      return { rows: [], rowCount: null };
    },
    release: () => {
      released = true;
    },
  };
  const db = {
    connect: async () => client,
  } as unknown as Database;
  return { db, queries, wasReleased: () => released };
}

test('EventRepository atomically locks, inserts event, and updates projection', async () => {
  const fake = fakeDatabase();
  const repository = new EventRepository(fake.db);

  const result = await repository.apply(event, 'event-key', () => ({
    transactionHash,
    network: 'testnet',
    state: 'confirmed',
    lastEventKey: 'event-key',
    updatedAt: 1778599260,
    blockHeight: 10,
  }));

  assert.equal(result.state, 'confirmed');
  assert.equal(fake.queries[0], 'BEGIN');
  assert.match(fake.queries[1]!, /pg_advisory_xact_lock/);
  assert.match(fake.queries[2]!, /FOR UPDATE$/);
  assert.match(fake.queries[3]!, /^INSERT INTO blockchain_events/);
  assert.match(fake.queries[4]!, /^INSERT INTO transaction_projections/);
  assert.equal(fake.queries[5], 'COMMIT');
  assert.equal(fake.wasReleased(), true);
});

test('EventRepository rolls back the event when projection persistence fails', async () => {
  const fake = fakeDatabase({ projectionWriteFails: true });
  const repository = new EventRepository(fake.db);

  await assert.rejects(
    repository.apply(event, 'event-key', () => ({
      transactionHash,
      network: 'testnet',
      state: 'confirmed',
      lastEventKey: 'event-key',
      updatedAt: 1778599260,
      blockHeight: 10,
    })),
    /projection write failed/,
  );

  assert.equal(fake.queries.at(-1), 'ROLLBACK');
  assert.equal(fake.wasReleased(), true);
});
