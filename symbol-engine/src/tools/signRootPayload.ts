import { PrivateKey, Signature, utils } from 'symbol-sdk';
import { SymbolFacade, SymbolTransactionFactory } from 'symbol-sdk/symbol';

type Options = {
  network: 'mainnet' | 'testnet';
  unsignedPayload: string;
  privateKey: string;
};

function readOption(name: string): string | undefined {
  const prefix = `--${name}=`;
  const value = process.argv.find((argument) => argument.startsWith(prefix));
  return value ? value.slice(prefix.length) : undefined;
}

function requiredHex(value: string, label: string, length?: number): string {
  const normalized = value.replace(/\s+/g, '').toUpperCase();
  if (!/^[0-9A-F]+$/.test(normalized)) {
    throw new Error(`${label} must be hex`);
  }
  if (normalized.length % 2 !== 0) {
    throw new Error(`${label} must be even-length hex`);
  }
  if (length !== undefined && normalized.length !== length) {
    throw new Error(`${label} must be ${length} hex characters`);
  }
  return normalized;
}

function options(): Options {
  const network = readOption('network') ?? process.env.SYMBOL_NETWORK ?? 'testnet';
  if (network !== 'mainnet' && network !== 'testnet') {
    throw new Error('network must be mainnet or testnet');
  }

  const unsignedPayload = readOption('unsigned-payload') ?? process.env.UNSIGNED_PAYLOAD ?? '';
  const privateKey = readOption('private-key') ?? process.env.SIGNER_PRIVATE_KEY ?? '';
  if (unsignedPayload === '') {
    throw new Error('unsigned payload is required via --unsigned-payload or UNSIGNED_PAYLOAD');
  }
  if (privateKey === '') {
    throw new Error('signer private key is required via --private-key or SIGNER_PRIVATE_KEY');
  }

  return {
    network,
    unsignedPayload: requiredHex(unsignedPayload, 'unsigned payload'),
    privateKey: requiredHex(privateKey, 'signer private key', 64),
  };
}

function main(): void {
  const parsed = options();
  const facade = new SymbolFacade(parsed.network);
  const account = facade.createAccount(new PrivateKey(parsed.privateKey));
  const transaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(parsed.unsignedPayload));
  const expectedSigner = transaction.signerPublicKey.toString().toUpperCase();
  const actualSigner = account.publicKey.toString().toUpperCase();

  if (expectedSigner !== actualSigner) {
    throw new Error(`signer public key mismatch: payload=${expectedSigner} privateKey=${actualSigner}`);
  }

  const signature = account.signTransaction(transaction);
  const signedPayloadJson = SymbolTransactionFactory.attachSignature(transaction, signature);
  const signedPayload = JSON.parse(signedPayloadJson) as { payload?: unknown };
  if (typeof signedPayload.payload !== 'string') {
    throw new Error('signed payload is missing');
  }

  const signedTransaction = SymbolTransactionFactory.deserialize(utils.hexToUint8(signedPayload.payload));
  if (!facade.verifyTransaction(signedTransaction, new Signature(signature.bytes))) {
    throw new Error('signed payload verification failed');
  }

  console.log(JSON.stringify({
    network: parsed.network,
    signerPublicKey: actualSigner,
    transactionHash: facade.hashTransaction(signedTransaction).toString().toUpperCase(),
    payload: signedPayload.payload.toUpperCase(),
  }, null, 2));
}

try {
  main();
}
catch (error) {
  const message = error instanceof Error ? error.message : 'unknown signing error';
  console.error(`sign:root-payload failed: ${message}`);
  process.exit(1);
}
