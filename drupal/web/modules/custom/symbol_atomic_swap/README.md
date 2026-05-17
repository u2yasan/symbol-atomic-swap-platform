## Symbol Atomic Swap

Drupal is a read/proxy layer for Symbol Engine. It must not hold private keys
and must not sign Symbol transactions.

### Routes

- `GET /symbol-atomic-swap/health`
- `/symbol-atomic-swap/offers`
- `/symbol-atomic-swap/offers/add`
- `/symbol-atomic-swap/offers/{offerId}`
- `/symbol-atomic-swap/offers/{offerId}/edit`
- `/symbol-atomic-swap/offers/{offerId}/cancel`
- `/symbol-atomic-swap/offers/{offerId}/submit-signed-payload`
- `/symbol-atomic-swap/offers/{offerId}/announce`
- `/symbol-atomic-swap/offers/{offerId}/sync-projection`
- `/symbol-atomic-swap/offers/{offerId}/delete`
- `GET /symbol-atomic-swap/engine/network`
- `GET /symbol-atomic-swap/engine/intent/{intentHash}`
- `GET /symbol-atomic-swap/engine/projection/{network}/{transactionHash}`
- `/admin/config/services/symbol-atomic-swap/engine`
- `/admin/config/services/symbol-atomic-swap/engine/operations`

The admin page is a read-only lookup surface for Engine network, intent, and
projection state.

The operations page can build unsigned Aggregate Complete payloads, submit
signed payloads for Engine semantic verification, and announce already verified
transactions. Drupal does not sign and does not accept private key material.
When an Engine response contains `qrPayload`, Drupal renders it as a QR code in
the browser without external CDN assets.

The settlement UI persists agreed atomic settlement terms, calls Symbol Engine
to build unsigned payloads, stores the resulting intent hash and QR payload, and
renders the QR payload on the settlement view. It is for final settlement after
P2P trade terms are already agreed, not for listing or matching. It can also
submit a signed payload to Symbol Engine for semantic verification and announce
a verified transaction.
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
