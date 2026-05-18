# Test Public Release Procedure

## Purpose

Use this procedure before exposing the test-public Drupal URL to external
testers. This is not a mainnet production checklist.

The test-public environment is testnet-only. Drupal remains a projection/cache
layer and must never receive private keys, mnemonics, wallet passwords, or
signing secrets.

## Release Gates

Run the automated gates before deployment:

```sh
make test
make test-drupal
make audit-drupal-dependencies
make build-symbol-engine-production
make smoke-symbol-engine-production
```

Reject the release if any gate fails.

## Environment Requirements

Use a separate database and separate files volume for test-public. Do not share
production data.

Required environment:

```text
SYMBOL_NETWORK=testnet
SYMBOL_NODE_URL=<HTTPS testnet REST node>
SYMBOL_WS_URL=<WSS testnet WebSocket node>
SYMBOL_ENGINE_BASE_URL=http://symbol-engine:3000
SYMBOL_ENGINE_API_TOKEN=<32+ character random token>
SYMBOL_ENGINE_EXPOSE_NODE_ENDPOINTS=false
DRUPAL_BIND_ADDRESS=127.0.0.1 or private reverse-proxy interface
SYMBOL_ENGINE_BIND_ADDRESS=127.0.0.1
POSTGRES_BIND_ADDRESS=127.0.0.1
REDIS_BIND_ADDRESS=127.0.0.1
```

Terminate HTTPS at the reverse proxy. Do not expose Symbol Engine, PostgreSQL,
or Redis directly to the internet.

In Drupal settings, keep `Enable mainnet UI operations` disabled. With this
disabled, new Symbol account verification, P2P listings, and P2P take flow are
testnet-only.

## Deployment Procedure

Deploy the build, then always run:

```sh
make drush-updb
make drush-cr
```

This is mandatory. Route and form definitions are cache-backed in Drupal, and
stale route caches can keep old controller/form definitions alive after deploy.

Verify health:

```sh
curl -I https://<test-public-host>/
curl -I https://<test-public-host>/symbol-atomic-swap/health
docker compose exec -T drupal sh -lc 'cd /opt/drupal && vendor/bin/drush cron'
docker compose exec -T drupal sh -lc 'cd /opt/drupal && vendor/bin/drush queue:list | grep symbol_atomic_swap_projection_sync'
docker compose logs --tail=100 drupal
docker compose logs --tail=100 symbol-engine
```

## Roles And Access

URL sharing is allowed, but operations require login.

Recommended roles:

| Role | Permissions |
| --- | --- |
| `symbol_public_viewer` | `view symbol p2p ad listings`, `view symbol atomic swap offers` |
| `symbol_test_operator` | viewer permissions plus `create symbol p2p ad listings`, `operate symbol p2p ad listings`, `create symbol atomic swap offers`, `operate symbol atomic swap offers` |
| `symbol_test_admin` | operator permissions plus both administer permissions and `administer site configuration` |

Anonymous users may view public listing pages only if intentionally granted
`view symbol p2p ad listings`. Never grant create, operate, or administer
permissions to anonymous or broadly shared roles.

Create at least:

```text
seller tester
taker tester
admin operator
```

Seller and taker must register and verify separate testnet Symbol accounts in
`/symbol-atomic-swap/account`. Fund those accounts from a testnet faucet before
E2E testing.

## Manual E2E Checklist

Run these scenarios before sharing the URL:

1. Seller creates a P2P listing at `/symbol-p2p/listings/add`.
2. Taker opens `/symbol-p2p/listings`, checks seller balance, and takes the listing.
3. Aggregate Complete settlement reaches signed, announced, and finalized or confirmed projection state.
4. Aggregate Bonded settlement completes hash lock build, hash lock announce, partial announce, maker cosignature, and projection sync.
5. Cancelled, failed, expired, or rolled-back settlement releases the listing when it is still before `expires_at`.
6. Listing with insufficient seller balance moves to `insufficient_balance` and does not automatically return to `active`.
7. Non-admin users see only settlements related to their Drupal account or verified Symbol public key.
8. Bonded workflow displays public keys and Engine API payloads, not derived addresses, for command-line steps.

## Operational Checks

Before public testing:

- Restore a database backup into a disposable environment once.
- Confirm Drupal cron runs on schedule.
- Confirm Symbol Engine logs do not expose protected tokens, private data, or node endpoint secrets.
- Confirm tester accounts have no admin permissions unless explicitly required.

Failure response:

```sh
docker compose exec -T drupal sh -lc 'cd /opt/drupal && vendor/bin/drush state:set system.maintenance_mode 1 --input-format=integer'
make drush-cr
# Rotate SYMBOL_ENGINE_API_TOKEN in the environment, restart services, then rebuild Drupal cache.
# Stop the host cron or scheduler that invokes Drupal cron if queue processing must pause.
```

Tester instructions:

- Use testnet only.
- Never paste private keys, mnemonics, wallet passwords, or signing secrets into Drupal.
- Before signing, compare payload details, signer public key, mosaic IDs, amounts, and network.
- Report listing ID, settlement ID, transaction hash, and exact time when a flow fails.
