export type SymbolNetwork = 'mainnet' | 'testnet';

export const networkIdentifiers: Record<SymbolNetwork, number> = {
  mainnet: 104,
  testnet: 152,
};

export function getNetworkIdentifier(network: SymbolNetwork): number {
  return networkIdentifiers[network];
}
