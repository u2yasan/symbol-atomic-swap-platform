# Symbol Atomic Swap Platform

Non-custodial Symbol mosaic atomic swap coordination platform.

The platform coordinates swap offers, unsigned transaction generation, QR-based external signing, transaction announcement, blockchain event monitoring, and finalized-state projection.

The blockchain is the source of truth. Drupal is a projection/cache layer.

## Documentation

- [System architecture](architecture/system-overview.md)
- [State machine](architecture/state-machine.md)
- [Finalization policy](architecture/finalization-policy.md)
- [Symbol Engine API contract](architecture/symbol-engine-api-contract.md)
- [Symbol Engine API usage](docs/api/symbol-engine-api.md)
- [Security requirements](docs/security/symbol-engine-security.md)
- [Event projection operations](docs/operations/event-projection.md)
- [Local development](docs/setup/local-development.md)

## Test Commands

Run all implemented backend tests:

```sh
make test
```

Run only Symbol Engine tests:

```sh
make test-symbol-engine
```

Run only Drupal Kernel/Functional tests:

```sh
make test-drupal
```

Rebuild Drupal caches:

```sh
make drush-cr
```

CI runs the same Docker-backed test path in
`.github/workflows/symbol-platform-test.yml`.

## Current Implementation Status

Implemented:

- Symbol Engine health endpoint
- protected Symbol Engine API routes
- Bearer token authentication
- PostgreSQL-backed swap intent and projection storage
- Aggregate Complete transaction serialization
- QR JSON payload generation
- signed payload semantic validation against stored swap intent
- transaction announcement endpoint
- Symbol WebSocket listener with reconnect/backoff
- transaction status reconciliation worker
- intent and projection read APIs
- event projection idempotency
- unsafe finalization transition rejection
- finalization height check
- production environment fail-fast validation for Engine API token and database URL
- production environment fail-fast validation for HTTPS Symbol REST and WSS Symbol WebSocket endpoints
- fail-fast validation for enabled listener and reconciler endpoint dependencies
- production startup preflight rejecting Symbol network mismatch
- production startup preflight rejecting unreachable Symbol WebSocket listener endpoint
- fail-fast validation for listener address format and network prefix
- listener address normalization and duplicate subscription prevention
- announcement APIs return explicit 503 when Symbol node URL is unavailable
- Symbol node transport failures return generic client-facing messages
- Symbol node announcement responses are normalized before storage and API return
- Symbol node reconciliation failures store normalized status codes only
- validation error responses omit rejected request values and serializer internals
- signed transaction verification hides decoder and SDK exception details
- bounded Symbol node announcement HTTP requests with configurable timeout
- bounded Symbol node REST read requests with configurable timeout
- hashed API token identifiers for rate limiting
- graceful shutdown closes HTTP server before database teardown
- Symbol node endpoint disclosure disabled by default in network metadata API
- Symbol listener connection logs omit node endpoint URLs
- Symbol listener warning/error logs omit raw WebSocket messages and payloads
- logger redaction covers Secret Lock values and Symbol node endpoint URLs
- production Engine API token weak-pattern rejection
- Drupal Engine client fail-closed validation for missing, short, or weak-pattern API tokens
- localhost-only default Docker Compose port binding for Drupal, Symbol Engine, PostgreSQL, and Redis
- Docker Compose healthchecks and readiness-gated service dependencies
- Symbol Engine production image healthcheck
- Symbol Engine Docker base image digest pinning
- Drupal Docker base image digest pinning
- PostgreSQL, Redis, and Composer Docker image digest pinning
- CI production Docker image build check for Symbol Engine
- CI production Docker image runtime smoke test for Symbol Engine
- CI Node dependency policy check rejecting floating `latest` and wildcard specs
- CI Docker image policy check rejecting unpinned runtime and build images
- CI GitHub Actions policy check rejecting unpinned action refs and floating runner labels
- CI Composer audit check for Drupal locked dependencies
- CI sensitive file policy check for `.env`, private key, and credential file tracking
- CI sensitive file policy check for weak Symbol Engine API tokens in runtime configuration
- CI repository hygiene check rejecting generated or installed dependency artifacts
- CI environment example policy check rejecting undocumented Docker Compose variables
- Aggregate Bonded unsigned payload build API with hash lock requirements
- Aggregate Bonded signed payload semantic verification
- Aggregate Bonded Hash Lock unsigned payload build, semantic verification, and node announcement endpoint
- Aggregate Bonded partial announcement endpoint and projection event dispatch
- Aggregate Bonded detached cosignature validation, announcement, and projection event dispatch
- Secret Lock / Secret Proof unsigned payload build, semantic verification, and node announcement endpoints
- Drupal client token forwarding
- Drupal swap offer CRUD UI
- Drupal swap offer QR generation flow
- Drupal signed payload submission flow
- Drupal verified transaction announcement flow
- Drupal offer projection synchronization
- automatic Drupal projection synchronization queue/cron
- Drupal local expiration handling for unannounced offers
- Drupal user-facing offer notifications
- Drupal outbound webhook notifications for offer notification events
- Drupal outbound email notifications for offer notification events
- Drupal Engine admin lookup and operations forms
- Drupal QR rendering for Engine `qrPayload`
- Drupal Kernel/Functional tests for Engine routes and client behavior

Not implemented yet:

- Remaining production hardening and end-to-end testnet validation.

Do not treat this repository as production-ready until the hardening items are implemented and tested.
