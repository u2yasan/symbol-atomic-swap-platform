import pg from 'pg';
import type { EngineEnv } from '../config/env.js';

export type Database = pg.Pool;

export function createDatabase(env: EngineEnv): Database {
  if (!env.SYMBOL_ENGINE_DATABASE_URL) {
    throw new Error('SYMBOL_ENGINE_DATABASE_URL is required for Symbol Engine persistence.');
  }

  return new pg.Pool({
    connectionString: env.SYMBOL_ENGINE_DATABASE_URL,
  });
}
