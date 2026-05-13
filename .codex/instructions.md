# Codex Instructions

Project: symbol-atomic-swap-platform

Core principles:

- Symbol blockchain is the source of truth.
- Drupal is projection/cache/UI only.
- Never store private keys.
- Never treat unconfirmed transactions as completed swaps.
- Finalized transactions are the only irreversible state.
- All Symbol SDK logic must be implemented in symbol-engine using symbol-sdk/javascript.
- Use TypeScript strict mode.
- Drupal must communicate with symbol-engine via HTTP API or queue.
- Business logic must not be placed directly in Drupal controllers.
- Aggregate transactions are the default swap primitive.
- QR signing is mandatory.
