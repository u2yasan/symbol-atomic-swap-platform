export type PublicSymbolNodeResponse = {
  status: number;
  code?: string;
  message?: string;
};

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null ? value as Record<string, unknown> : {};
}

function readString(...values: unknown[]): string | undefined {
  for (const value of values) {
    if (typeof value === 'string' && value.length > 0) {
      return value.slice(0, 200);
    }
  }
  return undefined;
}

export async function publicSymbolNodeResponse(response: Response): Promise<PublicSymbolNodeResponse> {
  const body = asRecord(await response.json().catch(() => null));
  const meta = asRecord(body.meta);
  const status = asRecord(body.status);
  const code = readString(body.code, status.code, meta.code);
  const message = readString(body.message, status.message, meta.message);
  const normalized: PublicSymbolNodeResponse = {
    status: response.status,
  };

  if (code) {
    normalized.code = code;
  }

  if (message) {
    normalized.message = message;
  }

  return normalized;
}
