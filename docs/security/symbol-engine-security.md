# Symbol Engine Security Requirements

## Non-Custodial Boundary

The platform must never store:

- private keys
- mnemonics
- wallet passwords
- signing secrets
- reusable proofs

Drupal and Symbol Engine may generate unsigned payloads.

Only the user's external wallet may sign.

Symbol Engine intentionally overrides `bitcore-mnemonic` with a local disabled stub.

Reason:

- `symbol-sdk` depends on mnemonic/BIP32 support transitively.
- this service must not generate or process mnemonics
- the original mnemonic dependency chain pulls vulnerable cryptographic packages that are unnecessary for server-side transaction coordination

Any attempt to use mnemonic/BIP39 functionality in Symbol Engine must fail closed.

## Internal API Authentication

Protected Symbol Engine routes require:

```text
Authorization: Bearer <SYMBOL_ENGINE_API_TOKEN>
```

Token rules:

- minimum 32 characters
- must not be a placeholder, repeated pattern, or low-variety string
- generated from cryptographically secure randomness
- stored in environment variables or a secret manager
- shared only between Drupal and Symbol Engine
- never committed to Git
- never logged

Drupal must reject missing, shorter-than-32-character, placeholder, repeated-pattern, or low-variety tokens before making protected Symbol Engine requests.

Local token generation:

```sh
openssl rand -hex 32
```

## Fail-Closed Behavior

If `SYMBOL_ENGINE_API_TOKEN` is missing on Symbol Engine, protected routes must return `503`.

This prevents accidentally running protected endpoints without authentication.

In production, Symbol node endpoints must use encrypted transport:

- `SYMBOL_NODE_URL` must use `https://`
- `SYMBOL_WS_URL` must use `wss://`

Plain HTTP/WebSocket endpoints are acceptable only for non-production local or isolated test environments.

## Public Health Route

`GET /health` is public for service monitoring.

It must not return:

- Symbol node URL
- WebSocket URL
- API token
- database DSN
- hostnames not needed for health monitoring
- secrets

## Signed Payload Rules

Signed payload acceptance is not proof of successful swap execution.

Before announcement, the decoded transaction must match the original swap intent:

- expected network
- expected signer public keys
- expected recipients
- expected mosaics
- expected amounts
- expected deadline
- expected max fee
- expected transaction hash when available

Reject payloads that contain extra transfers, changed recipients, changed mosaic IDs, changed amounts, or unexpected signer public keys.

## Finalization Rules

The only completed swap state is `finalized`.

Do not run irreversible business logic for:

- QR generated
- signed
- announced
- unconfirmed
- confirmed

Finalized records are immutable.

Corrections require a correction event, not direct mutation.

## Logging Rules

Never log:

- API tokens
- signed payload bodies in full
- private data from wallet callbacks
- Secret Lock proofs
- authorization headers

Log only stable identifiers:

- transaction hash
- correlation ID
- event type
- block height
- finalized height
- state transition result
