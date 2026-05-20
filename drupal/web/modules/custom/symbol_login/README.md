# Symbol Login

Drupal 11 custom module for Symbol account authentication and per-user Symbol
account management.

This module depends on `symbol_engine` for SSS and aLice transaction-payload
verification. Username/password login remains available by default.

## Scope

Implemented:

- Adds `/symbol/login` as a provider choice page.
- Adds `/symbol/login/sss` and `/symbol/login/alice` provider-specific login routes.
- Adds "Continue with SSS" and "Continue with aLice" buttons to Drupal's existing user login form.
- Issues one-time short-lived challenges with `keyvalue.expirable`.
- Supports raw browser message-signature verification through `/symbol/login/challenge` and `/symbol/login/verify` when a compatible sign app exposes that API.
- Uses `symbol_engine` to build and verify account-verification transaction payloads for SSS and aLice.
- Auto-creates or updates Drupal users linked to `field_symbol_address`.
- Stores verified Symbol account fields on the Drupal user entity.
- Provides `/symbol/account` as "My Symbol Account" for viewing, refreshing, or disconnecting the linked Symbol account.
- Synchronizes configured Drupal roles from Symbol mosaics and metadata during login.
- Can optionally block password login, password reset, and public registration while preserving an emergency password permission.

Not implemented:

- Custody of Symbol accounts or private keys.
- Recovery of lost Symbol accounts.
- Reputation or risk scoring.
- Multi-account linking per Drupal user.

## Routes

| Route | Path | Purpose |
| --- | --- | --- |
| `symbol_login.login` | `/symbol/login` | Provider choice |
| `symbol_login.login_sss` | `/symbol/login/sss` | SSS login form |
| `symbol_login.login_alice` | `/symbol/login/alice` | aLice login form |
| `symbol_login.challenge` | `/symbol/login/challenge` | Raw message-signature challenge JSON endpoint |
| `symbol_login.verify` | `/symbol/login/verify` | Raw message-signature verify JSON endpoint |
| `symbol_login.sss_challenge` | `/symbol/login/sss/challenge` | Build SSS account-verification payload |
| `symbol_login.sss_verify` | `/symbol/login/sss/verify` | Verify signed SSS payload |
| `symbol_login.account` | `/symbol/account` | My Symbol Account |
| `symbol_login.settings` | `/admin/config/people/symbol-login` | Admin settings |

## Permissions

| Permission | Meaning |
| --- | --- |
| `administer symbol login` | Configure Symbol login and role synchronization |
| `use password login` | Keep password login available when password login is globally disabled |

## User Fields

The module creates and maintains these fields on Drupal user accounts:

- `field_symbol_network`
- `field_symbol_address`
- `field_symbol_public_key`
- `field_symbol_address_verified`
- `field_symbol_address_verified_at`
- `field_symbol_verification_method`
- `field_symbol_challenge_hash`
- `field_symbol_last_verified`

`field_symbol_address` is normalized to uppercase raw address format on user
save. Duplicate Symbol addresses across users are rejected.

## Configuration

Default config lives in `config/install/symbol_login.settings.yml`.

Key settings:

- `network_type`
- `rest_endpoints`
- `challenge_ttl_seconds`
- `request_timeout_seconds`
- `disable_password_login`
- `password_login_permission`
- `disable_password_reset`
- `disable_public_registration`
- `role_rules`

## Example Role Rule

```json
[
  {
    "role": "premium_member",
    "mosaic_id": "72C0212E67A08BCE",
    "minimum_amount": "1",
    "metadata_source_address": "TALICE2GMA34SAMPLEADDRESS000000000",
    "metadata_key": "0000000000000001",
    "metadata_value": "active",
    "enabled": true
  }
]
```

## Production Checks

- Configure `symbol_engine` first. SSS and aLice login require `symbol_engine.client`.
- Set at least one trusted HTTPS Symbol REST endpoint for role synchronization.
- Confirm address derivation against the deployed network.
- Confirm SSS returns a signed payload for the account-verification transaction.
- Confirm aLice displays a signed payload when no callback URL is used.
- Keep `SYMBOL_ENGINE_API_TOKEN` out of Drupal config.

## Testing

Run this module's tests from the repository root:

```bash
docker compose exec -T drupal sh -lc 'cd /opt/drupal && runuser -u www-data -- env SIMPLETEST_BASE_URL=http://127.0.0.1 SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal BROWSERTEST_OUTPUT_BASE_URL=http://127.0.0.1:8080 vendor/bin/phpunit -c phpunit.xml.dist --group symbol_login'
```
