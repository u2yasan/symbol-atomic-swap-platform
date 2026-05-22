import { publicNetworkProfile, type SymbolNetworkProfile } from '../config/networkProfile.js';

export type NetworkResponseInput = {
  network: string;
  exposeNodeEndpoints: boolean;
  nodeUrl?: string | undefined;
  wsUrl?: string | undefined;
  profiles?: SymbolNetworkProfile[];
};

export function networkResponse(input: NetworkResponseInput): {
  network: string;
  nodeUrl?: string | null;
  wsUrl?: string | null;
  profiles?: ReturnType<typeof publicNetworkProfile>[];
} {
  if (!input.exposeNodeEndpoints) {
    const response: ReturnType<typeof networkResponse> = {
      network: input.network,
    };
    if (input.profiles) {
      response.profiles = input.profiles.map(publicNetworkProfile);
    }
    return response;
  }

  const response: ReturnType<typeof networkResponse> = {
    network: input.network,
    nodeUrl: input.nodeUrl ?? null,
    wsUrl: input.wsUrl ?? null,
  };
  if (input.profiles) {
    response.profiles = input.profiles.map(publicNetworkProfile);
  }
  return response;
}
