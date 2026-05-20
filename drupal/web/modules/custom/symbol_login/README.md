# Symbol Login

Drupal 11 custom module for Symbol address signature login.

Requires the `symbol_engine` module for SSS and aLice transaction payload based login.

## Behavior

- Adds `/symbol/login` as a provider choice page and `/symbol/login/sss` and `/symbol/login/alice` as provider-specific signature login routes.
- Issues one-time short-lived login challenges with `keyvalue.expirable`.
- Verifies Ed25519 signatures server-side with PHP sodium.
- Uses `symbol_engine` to build and verify Symbol account verification payloads for SSS and aLice.
- Derives the Symbol address from the submitted public key and configured network type.
- Auto-creates Drupal users linked to `field_symbol_address`.
- Synchronizes configured Drupal roles from Symbol mosaics and metadata during login.
- Adds "Continue with SSS" and "Continue with aLice" external authentication buttons to the existing Drupal login form.
- Keeps Drupal username and password login, password reset, and public registration available by default.
- Can optionally block password login for normal users while keeping UID 1 and users with `use password login` available as an emergency path.

## Required verification before production

- Confirm the symbol sign app browser API used in `js/symbol-login.js` returns raw message signatures, public key, and address in the expected shape.
- Confirm the address derivation hash behavior against official Symbol SDK test vectors for the deployed network.
- Configure at least two trusted HTTPS Symbol REST endpoints.
- Run Drupal Kernel/Functional tests inside a real Drupal 11 installation.

## Example role rule

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
