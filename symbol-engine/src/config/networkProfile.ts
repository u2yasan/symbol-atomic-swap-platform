import { Hash256 } from 'symbol-sdk';
import { Network, models, SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';
import { z } from 'zod';

export type SymbolNetworkProfile = {
  key: string;
  label: string;
  networkIdentifier: number;
  addressPrefix: string;
  generationHashSeed: string;
  epochAdjustment: number;
  nodeUrl?: string;
  wsUrl?: string;
  currencyMosaicId?: string;
  currencyDivisibility?: number;
  enabledForSwap: boolean;
  allowInsecureTransport: boolean;
};

export const networkKeySchema = z.string().regex(/^[A-Za-z0-9_-]{3,64}$/, 'network key must be 3-64 safe characters');

const profileSchema = z.object({
  key: networkKeySchema,
  label: z.string().min(1).max(128),
  networkIdentifier: z.number().int().min(0).max(255),
  addressPrefix: z.string().regex(/^[A-Z2-7]$/),
  generationHashSeed: z.string().regex(/^[0-9A-Fa-f]{64}$/),
  epochAdjustment: z.number().int().min(0),
  nodeUrl: z.string().url().optional(),
  wsUrl: z.string().url().optional(),
  currencyMosaicId: z.string().regex(/^[0-9A-Fa-f]{16}$/).optional(),
  currencyDivisibility: z.number().int().min(0).max(6).optional(),
  enabledForSwap: z.boolean().default(true),
  allowInsecureTransport: z.boolean().default(false),
}).superRefine((profile, context) => {
  if ((profile.currencyMosaicId === undefined) !== (profile.currencyDivisibility === undefined)) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['currencyMosaicId'],
      message: 'currencyMosaicId and currencyDivisibility must be configured together.',
    });
  }
});

const profileArraySchema = z.array(profileSchema).min(1).superRefine((profiles, context) => {
  const keys = new Set<string>();
  const identifiers = new Set<number>();
  for (const profile of profiles) {
    const normalizedKey = profile.key.toLowerCase();
    if (keys.has(normalizedKey)) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['key'],
        message: `duplicate network profile key: ${profile.key}`,
      });
    }
    keys.add(normalizedKey);
    if (identifiers.has(profile.networkIdentifier)) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['networkIdentifier'],
        message: `duplicate network identifier: ${profile.networkIdentifier}`,
      });
    }
    identifiers.add(profile.networkIdentifier);
  }
});

const defaultMainnetProfile: SymbolNetworkProfile = {
  key: 'mainnet',
  label: 'Mainnet',
  networkIdentifier: 0x68,
  addressPrefix: 'N',
  generationHashSeed: '57F7DA205008026C776CB6AED843393F04CD458E0AA2D9F1D5F31A402072B2D6',
  epochAdjustment: 1_615_853_185,
  currencyMosaicId: '6BED913FA20223F8',
  currencyDivisibility: 6,
  enabledForSwap: true,
  allowInsecureTransport: false,
};

const defaultTestnetProfile: SymbolNetworkProfile = {
  key: 'testnet',
  label: 'Testnet',
  networkIdentifier: 0x98,
  addressPrefix: 'T',
  generationHashSeed: '49D6E1CE276A85B70EAFE52349AACCA389302E7A9754BCF1221E79494FC665A4',
  epochAdjustment: 1_667_250_467,
  currencyMosaicId: '72C0212E67A08BCE',
  currencyDivisibility: 6,
  enabledForSwap: true,
  allowInsecureTransport: false,
};

function normalizeProfile(profile: z.infer<typeof profileSchema>): SymbolNetworkProfile {
  const normalized: SymbolNetworkProfile = {
    key: profile.key.toLowerCase(),
    label: profile.label,
    networkIdentifier: profile.networkIdentifier,
    addressPrefix: profile.addressPrefix.toUpperCase(),
    generationHashSeed: profile.generationHashSeed.toUpperCase(),
    epochAdjustment: profile.epochAdjustment,
    enabledForSwap: profile.enabledForSwap,
    allowInsecureTransport: profile.allowInsecureTransport,
  };
  if (profile.nodeUrl !== undefined) {
    normalized.nodeUrl = profile.nodeUrl;
  }
  if (profile.wsUrl !== undefined) {
    normalized.wsUrl = profile.wsUrl;
  }
  if (profile.currencyMosaicId !== undefined) {
    normalized.currencyMosaicId = profile.currencyMosaicId.toUpperCase();
  }
  if (profile.currencyDivisibility !== undefined) {
    normalized.currencyDivisibility = profile.currencyDivisibility;
  }

  return normalized;
}

export function loadNetworkProfilesFromEnv(input: NodeJS.ProcessEnv): SymbolNetworkProfile[] {
  const raw = input.SYMBOL_NETWORK_PROFILES_JSON;
  const defaults = [
    {
      ...defaultMainnetProfile,
      ...(input.SYMBOL_NETWORK === 'mainnet' && input.SYMBOL_NODE_URL ? { nodeUrl: input.SYMBOL_NODE_URL } : {}),
      ...(input.SYMBOL_NETWORK === 'mainnet' && input.SYMBOL_WS_URL ? { wsUrl: input.SYMBOL_WS_URL } : {}),
    },
    {
      ...defaultTestnetProfile,
      ...((!input.SYMBOL_NETWORK || input.SYMBOL_NETWORK === 'testnet') && input.SYMBOL_NODE_URL ? { nodeUrl: input.SYMBOL_NODE_URL } : {}),
      ...((!input.SYMBOL_NETWORK || input.SYMBOL_NETWORK === 'testnet') && input.SYMBOL_WS_URL ? { wsUrl: input.SYMBOL_WS_URL } : {}),
    },
  ];

  if (!raw || raw.trim() === '') {
    return defaults;
  }

  const parsed = JSON.parse(raw) as unknown;
  return profileArraySchema.parse(parsed).map(normalizeProfile);
}

export function findNetworkProfile(network: string, profiles = loadNetworkProfilesFromEnv(process.env)): SymbolNetworkProfile {
  const key = network.toLowerCase();
  const profile = profiles.find((candidate) => candidate.key === key);
  if (!profile) {
    throw new Error(`network_not_supported:${network}`);
  }
  return profile;
}

export function createSymbolNetwork(profile: SymbolNetworkProfile): Network {
  return new Network(
    profile.key,
    profile.networkIdentifier,
    new Date(profile.epochAdjustment * 1000),
    new Hash256(profile.generationHashSeed),
  );
}

export function createSymbolFacadeForNetwork(network: string, profiles?: SymbolNetworkProfile[]) {
  const profile = findNetworkProfile(network, profiles);
  return {
    profile,
    facade: new SymbolFacade(createSymbolNetwork(profile)),
  };
}

const TRANSACTION_NETWORK_BYTE_OFFSET = 109;

export function deserializeTransactionForNetwork(payload: Uint8Array, network: string, profiles?: SymbolNetworkProfile[]) {
  const profile = findNetworkProfile(network, profiles);
  const patched = new Uint8Array(payload);
  if (profile.networkIdentifier !== 104 && profile.networkIdentifier !== 152) {
    patched[TRANSACTION_NETWORK_BYTE_OFFSET] = 152;
  }
  const transaction = SymbolTransactionFactory.deserialize(patched);
  transaction.network = new models.NetworkType(profile.networkIdentifier);
  return {
    transaction,
    profile,
  };
}

export function publicNetworkProfile(profile: SymbolNetworkProfile) {
  return {
    key: profile.key,
    label: profile.label,
    networkIdentifier: profile.networkIdentifier,
    addressPrefix: profile.addressPrefix,
    currencyMosaicId: profile.currencyMosaicId ?? null,
    currencyDivisibility: profile.currencyDivisibility ?? null,
    enabledForSwap: profile.enabledForSwap,
  };
}
