export type NetworkResponseInput = {
  network: 'mainnet' | 'testnet';
  exposeNodeEndpoints: boolean;
  nodeUrl?: string | undefined;
  wsUrl?: string | undefined;
};

export function networkResponse(input: NetworkResponseInput): {
  network: 'mainnet' | 'testnet';
  nodeUrl?: string | null;
  wsUrl?: string | null;
} {
  if (!input.exposeNodeEndpoints) {
    return {
      network: input.network,
    };
  }

  return {
    network: input.network,
    nodeUrl: input.nodeUrl ?? null,
    wsUrl: input.wsUrl ?? null,
  };
}
