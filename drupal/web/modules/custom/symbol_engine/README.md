# Symbol Engine

Drupal 11 shared integration module for the external Symbol Engine service.

This module owns the reusable Symbol Engine client, shared lookup/admin screens,
address derivation, account public-key resolution, and QR/copy assets used by
Symbol-facing Drupal modules.

## Scope

Implemented:

- Stores non-secret Engine connection settings.
- Reads `SYMBOL_ENGINE_API_TOKEN` from the environment.
- Provides the `symbol_engine.client` service.
- Provides `symbol_engine.address_deriver`.
- Provides `symbol_engine.account_public_key_resolver`.
- Provides Engine lookup and manual operations admin pages.
- Provides shared QR/copy rendering assets for Symbol payloads.
- Supports account-verification payload build and signed payload verification for SSS and aLice login flows.

Not implemented:

- Drupal-side custody of private keys.
- Drupal-side transaction signing.
- Marketplace, settlement, or login UI ownership. Those live in dependent modules.

## Routes

| Route | Path | Purpose |
| --- | --- | --- |
| `symbol_engine.network` | `/symbol-engine/network` | Read Engine network status |
| `symbol_engine.intent` | `/symbol-engine/intent/{intentHash}` | Read Engine intent |
| `symbol_engine.projection` | `/symbol-engine/projection/{network}/{transactionHash}` | Read Engine projection |
| `symbol_engine.settings` | `/admin/config/services/symbol-engine/settings` | Admin settings |
| `symbol_engine.lookup` | `/admin/config/services/symbol-engine/lookup` | Admin lookup form |
| `symbol_engine.operations` | `/admin/config/services/symbol-engine/operations` | Admin operations form |

## Permissions

| Permission | Meaning |
| --- | --- |
| `administer symbol engine` | Configure and operate Symbol Engine integration |

The public read endpoints use Drupal's `access content` permission. Do not put
secret material in Engine responses exposed through these routes.

## Configuration

Default config lives in `config/install/symbol_engine.settings.yml`.

Config keys:

- `engine_base_url`
- `engine_timeout`

Environment variables:

- `SYMBOL_ENGINE_API_TOKEN` is required for authenticated Engine calls.
- `SYMBOL_ENGINE_BASE_URL` can override Drupal config.
- `SYMBOL_ENGINE_TIMEOUT` can override Drupal config.

The API token must stay out of Drupal config.

## Consumers

- `symbol_login` uses Engine account-verification payload build/verify flows.
- `symbol_atomic_swap` uses Engine transaction build, verification, announcement, hash-lock, projection, and lookup flows.
- `symbol_p2p_ad_listing` uses Engine mosaic metadata and balance checks through the shared client.

## Testing

Run this module's tests from the repository root:

```bash
docker compose exec -T drupal sh -lc 'cd /opt/drupal && runuser -u www-data -- env SIMPLETEST_BASE_URL=http://127.0.0.1 SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal BROWSERTEST_OUTPUT_BASE_URL=http://127.0.0.1:8080 vendor/bin/phpunit -c phpunit.xml.dist --group symbol_engine'
```
