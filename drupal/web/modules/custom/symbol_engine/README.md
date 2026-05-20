# Symbol Engine

Drupal 11 shared integration module for the Symbol Engine service.

## Behavior

- Stores non-secret Engine connection settings.
- Reads `SYMBOL_ENGINE_API_TOKEN` from the environment.
- Provides the `symbol_engine.client` service.
- Provides address derivation and account public-key resolver services.
- Provides Engine lookup and manual operations admin pages.
- Provides shared QR/copy rendering assets for Symbol payloads.
- Supports account verification payload build and signed payload verification for SSS and aLice login flows.

## Routes

- `GET /symbol-engine/network`
- `GET /symbol-engine/intent/{intentHash}`
- `GET /symbol-engine/projection/{network}/{transactionHash}`
- `/admin/config/services/symbol-engine/settings`
- `/admin/config/services/symbol-engine/lookup`
- `/admin/config/services/symbol-engine/operations`

## Required environment

```text
SYMBOL_ENGINE_API_TOKEN
SYMBOL_ENGINE_BASE_URL
SYMBOL_ENGINE_TIMEOUT
```

`SYMBOL_ENGINE_BASE_URL` and `SYMBOL_ENGINE_TIMEOUT` are optional when Drupal config is set. The API token must stay out of Drupal config.
