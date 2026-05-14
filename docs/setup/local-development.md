# Local Development

## Required Environment

Create `.env` from `.env.example`.

Required values:

```text
DRUPAL_PORT=8080
POSTGRES_DB=drupal
POSTGRES_USER=drupal
POSTGRES_PASSWORD=drupal

SYMBOL_ENGINE_PORT=3000
SYMBOL_ENGINE_API_TOKEN=<random-token>
SYMBOL_ENGINE_DATABASE_URL=postgresql://drupal:drupal@postgres:5432/drupal
SYMBOL_ENGINE_LISTENER_ENABLED=false
SYMBOL_ENGINE_LISTENER_ADDRESSES=
SYMBOL_ENGINE_RECONCILER_ENABLED=false
SYMBOL_ENGINE_RECONCILER_INTERVAL_MS=30000
SYMBOL_ENGINE_BASE_URL=http://symbol-engine:3000
SYMBOL_NETWORK=testnet
SYMBOL_NODE_URL=https://sym-test-01.opening-line.jp:3001
SYMBOL_WS_URL=wss://sym-test-01.opening-line.jp:3001/ws
```

Generate a local API token:

```sh
openssl rand -hex 32
```

Do not reuse this token outside local development.

## Install Symbol Engine Dependencies

```sh
cd symbol-engine
npm install
```

## Typecheck

```sh
cd symbol-engine
npm run typecheck
```

## Build

```sh
cd symbol-engine
npm run build
```

## Run With Docker Compose

```sh
docker compose up
```

Drupal default:

```text
http://localhost:8080
```

Symbol Engine default:

```text
http://localhost:3000
```

## Manual API Checks

Health is public:

```sh
curl -s http://127.0.0.1:3000/health
```

Protected route without token must fail:

```sh
curl -i http://127.0.0.1:3000/v1/network
```

Protected route with token:

```sh
curl -s \
  -H "Authorization: Bearer $SYMBOL_ENGINE_API_TOKEN" \
  http://127.0.0.1:3000/v1/network
```

## Optional Listener

Enable the Symbol WebSocket listener:

```text
SYMBOL_ENGINE_LISTENER_ENABLED=true
SYMBOL_ENGINE_LISTENER_ADDRESSES=TCHBDENCLKEBILBPWP3JPB2XNY64OE7PYHHE32I
```

`finalizedBlock` is subscribed globally.

Transaction channels are subscribed per configured address:

- `unconfirmedAdded/<address>`
- `confirmedAdded/<address>`
- `status/<address>`
- `partialAdded/<address>`
- `cosignature/<address>`

## Optional Reconciler

Enable transaction status reconciliation:

```text
SYMBOL_ENGINE_RECONCILER_ENABLED=true
SYMBOL_ENGINE_RECONCILER_INTERVAL_MS=30000
```

The reconciler runs once at startup and then at the configured interval.

It checks pending transaction hashes through Symbol node REST and updates projection state when the node reports:

- confirmed transaction
- unconfirmed transaction
- transaction status failure
- finalized height covering a confirmed transaction

REST 404 is not treated as failure.

## Current Implementation Scope

Current implementation covers:

- API authentication
- request validation
- PostgreSQL-backed persistence
- Aggregate Complete transaction serialization
- QR JSON payload generation
- signed payload semantic validation
- transaction announcement endpoint
- WebSocket listener with reconnect/backoff
- raw Symbol event normalization
- transaction status reconciliation worker
- event idempotency
- unsafe finalization transition rejection
- finalization height check
- Drupal UI for swap creation and operation
- production fail-fast validation for HTTPS/WSS Symbol endpoints
- production startup preflight for Symbol node network mismatch

## Test Commands

Start the stack before running integration-style tests:

```sh
docker compose up -d
```

Run Symbol Engine tests:

```sh
make test-symbol-engine
```

Run Drupal Kernel/Functional tests:

```sh
make test-drupal
```

Run both:

```sh
make test
```

The Drupal test target runs inside the Drupal container so the PHP process and
browser test site can both reach PostgreSQL through the Docker service name
`postgres`.
