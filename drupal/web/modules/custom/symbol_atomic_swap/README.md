## Symbol Atomic Swap

Atomic Settlements Drupal UI and local projection storage. Shared Symbol Engine
connection, lookup, and operation surfaces live in the `symbol_engine` module.

### Routes

- `GET /symbol-atomic-swap/health`
- `/symbol-atomic-swap/settlements`
- `/symbol-atomic-swap/settlements/add`
- `/symbol-atomic-swap/settlements/{offerId}`
- `/symbol-atomic-swap/settlements/{offerId}/edit`
- `/symbol-atomic-swap/settlements/{offerId}/cancel`
- `/symbol-atomic-swap/settlements/{offerId}/submit-signed-payload`
- `/symbol-atomic-swap/settlements/{offerId}/announce`
- `/symbol-atomic-swap/settlements/{offerId}/sync-projection`
- `/symbol-atomic-swap/settlements/{offerId}/delete`

The settlement UI persists agreed atomic settlement terms, calls Symbol Engine
to build unsigned payloads, stores the resulting intent hash and QR payload, and
exposes signing data through the dedicated payload page. It is for final
settlement after P2P trade terms are already agreed, not for listing or
matching. It can also submit a signed payload to Symbol Engine for semantic
verification and announce a verified transaction.
Drupal stores transaction hashes and state transitions, but not signed payload
bodies. Settlement records can be manually synced from Symbol Engine projections for
confirmed, finalized, failed, or rolled back state. Settlement records are local
projections; blockchain state remains authoritative.

Drupal cron queues non-terminal settlements with transaction hashes for automatic
projection synchronization. The queue worker reads Symbol Engine projection
state and applies the same finalized-state transition protections as the manual
sync action.

Drupal cron also expires stale local settlements that have not been announced before
their configured deadline. Announced transactions are not expired locally; they
must move through Symbol Engine projection state instead.

Makers and takers can cancel local settlements before transaction announcement.
Cancellation sets the settlement state to `cancelled`; it does not reverse or
modify any announced Symbol transaction.

Settlement notifications are stored in Drupal and displayed on the settlement view.
Expiration, confirmed, finalized, failed, and rolled back events create
deduplicated notifications. Outbound email or webhook delivery is not implemented.

### Tests

The module has Kernel and Functional coverage under `tests/src`.

Run from the Drupal project root after installing Drupal dev dependencies:

```sh
vendor/bin/phpunit -c phpunit.xml.dist --group symbol_atomic_swap
```

The current Docker image installs production Drupal dependencies only. PHPUnit
requires Drupal core development dependencies and is not available in the image
by default.
