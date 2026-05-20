# Symbol Engine

Drupal 11 shared integration module for the Symbol Engine service.

## Behavior

- Stores non-secret Engine connection settings.
- Reads `SYMBOL_ENGINE_API_TOKEN` from the environment.
- Provides the `symbol_engine.client` service.
- Supports account verification payload build and signed payload verification for SSS and aLice login flows.
- Keeps legacy fallback reads from `symbol_atomic_swap.settings` during migration.

## Required environment

```text
SYMBOL_ENGINE_API_TOKEN
SYMBOL_ENGINE_BASE_URL
SYMBOL_ENGINE_TIMEOUT
```

`SYMBOL_ENGINE_BASE_URL` and `SYMBOL_ENGINE_TIMEOUT` are optional when Drupal config is set. The API token must stay out of Drupal config.
