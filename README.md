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
- Drupal client token forwarding
- Drupal Engine admin lookup and operations forms
- Drupal QR rendering for Engine `qrPayload`
- Drupal Kernel/Functional tests for Engine routes and client behavior

Not implemented yet:

- swap creation UI

Do not treat this repository as production-ready until the missing items are implemented and tested.
