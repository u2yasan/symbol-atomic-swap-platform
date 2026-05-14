import { SymbolNodeUnavailableError } from './symbolNodeErrors.js';

type FetchLike = typeof fetch;

export type SymbolNodeRequestOptions = {
  fetcher?: FetchLike;
  timeoutMs?: number;
};

export async function putJsonToSymbolNode(
  nodeUrl: string,
  path: string,
  body: unknown,
  options: SymbolNodeRequestOptions = {},
): Promise<Response> {
  const timeoutMs = options.timeoutMs ?? 10000;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    return await (options.fetcher ?? fetch)(new URL(path, nodeUrl), {
      method: 'PUT',
      headers: {
        'content-type': 'application/json',
      },
      body: JSON.stringify(body),
      signal: controller.signal,
    });
  } catch (error) {
    if (controller.signal.aborted) {
      throw new SymbolNodeUnavailableError(`Symbol node request timed out after ${timeoutMs}ms.`);
    }

    const message = error instanceof Error ? error.message : 'request failed';
    throw new SymbolNodeUnavailableError(`Symbol node request failed: ${message}`);
  } finally {
    clearTimeout(timeout);
  }
}
