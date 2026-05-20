# Symbol Atomic Swap

Drupal 11 module for Atomic Settlements UI and local projection storage.

Shared Symbol Engine connection, lookup, and operation surfaces live in the
`symbol_engine` module. Verified Symbol account ownership lives in
`symbol_login`.

## Scope

Implemented:

- Creates and manages atomic settlement terms.
- Uses the current user's verified Symbol Login account as maker or taker.
- Builds unsigned aggregate complete or aggregate bonded payloads through Symbol Engine.
- Stores intent hashes, QR payloads, transaction hashes, local state, cosignatures, and notifications.
- Supports SSS and aLice signing workflows through external app payloads.
- Supports root-signed aggregate bonded, hash-lock signing, partial announcement, and cosignature submission.
- Announces verified signed payloads through Symbol Engine.
- Syncs local settlement projections from Symbol Engine.
- Queues non-terminal announced settlements for cron projection sync.
- Expires unannounced local settlements after their configured deadline.
- Stores and displays local settlement notifications.
- Can deliver best-effort notification email and webhook messages when configured.

Not implemented:

- Private-key custody.
- Drupal-side signing.
- Reversal of announced Symbol transactions.
- Order book or P2P listing ownership. Listings live in `symbol_p2p_ad_listing`.

## Routes

| Route | Path | Purpose |
| --- | --- | --- |
| `symbol_atomic_swap.health` | `/symbol-atomic-swap/health` | Health endpoint |
| `symbol_atomic_swap.offer_list` | `/symbol-atomic-swap/settlements` | List settlements |
| `symbol_atomic_swap.offer_add` | `/symbol-atomic-swap/settlements/add` | Create settlement |
| `symbol_atomic_swap.offer_view` | `/symbol-atomic-swap/settlements/{offerId}` | View settlement |
| `symbol_atomic_swap.offer_qr_payload` | `/symbol-atomic-swap/settlements/{offerId}/qr-payload/{intentHash}` | Admin payload inspection |
| `symbol_atomic_swap.offer_edit` | `/symbol-atomic-swap/settlements/{offerId}/edit` | Edit draft settlement |
| `symbol_atomic_swap.offer_accept` | `/symbol-atomic-swap/settlements/{offerId}/accept` | Taker finalization |
| `symbol_atomic_swap.offer_cancel` | `/symbol-atomic-swap/settlements/{offerId}/cancel` | Cancel local settlement |
| `symbol_atomic_swap.offer_submit_signed_payload` | `/symbol-atomic-swap/settlements/{offerId}/submit-signed-payload` | Submit signed payload |
| `symbol_atomic_swap.offer_sign_with_external_app` | `/symbol-atomic-swap/settlements/{offerId}/sign-with-external-app` | SSS/aLice signing route |
| `symbol_atomic_swap.offer_submit_aggregate_signer_json` | `/symbol-atomic-swap/settlements/{offerId}/submit-aggregate-signer-json` | Submit aggregate signer JSON |
| `symbol_atomic_swap.offer_submit_cosignature` | `/symbol-atomic-swap/settlements/{offerId}/submit-cosignature` | Submit bonded cosignature |
| `symbol_atomic_swap.offer_cosign_with_external_app` | `/symbol-atomic-swap/settlements/{offerId}/cosign-with-external-app` | External cosign route |
| `symbol_atomic_swap.offer_assemble_signed_payload` | `/symbol-atomic-swap/settlements/{offerId}/assemble-signed-payload` | Assemble signed payload |
| `symbol_atomic_swap.offer_announce` | `/symbol-atomic-swap/settlements/{offerId}/announce` | Announce complete transaction |
| `symbol_atomic_swap.offer_bonded_partial_announce` | `/symbol-atomic-swap/settlements/{offerId}/bonded-partial-announce` | Announce bonded partial transaction |
| `symbol_atomic_swap.offer_sync_projection` | `/symbol-atomic-swap/settlements/{offerId}/sync-projection` | Manual projection sync |
| `symbol_atomic_swap.offer_delete` | `/symbol-atomic-swap/settlements/{offerId}/delete` | Delete local settlement |
| `symbol_atomic_swap.public_key_from_address` | `/symbol-atomic-swap/public-key/{network}/{address}` | Resolve public key |
| `symbol_atomic_swap.mosaic_metadata` | `/symbol-atomic-swap/mosaic/{network}/{mosaicId}` | Read mosaic metadata |
| `symbol_atomic_swap.account_verification` | `/symbol-atomic-swap/account` | Legacy redirect to `/symbol/account` |
| `symbol_atomic_swap.transaction_history` | `/symbol-atomic-swap/transactions` | Settlement transaction history |
| `symbol_atomic_swap.notification_list` | `/symbol-atomic-swap/notifications` | User notifications |
| `symbol_atomic_swap.notification_mark_read` | `/symbol-atomic-swap/notifications/{notificationId}/mark-read` | Mark one notification read |
| `symbol_atomic_swap.notification_mark_all_read` | `/symbol-atomic-swap/notifications/mark-all-read` | Mark all notifications read |
| `symbol_atomic_swap.settings` | `/admin/config/services/symbol-atomic-swap/settings` | Notification settings |

## Permissions

| Permission | Meaning |
| --- | --- |
| `view symbol atomic swap offers` | View permitted settlements and notification pages |
| `create symbol atomic swap offers` | Create atomic settlements |
| `operate symbol atomic swap offers` | Submit, announce, and operate settlement actions |
| `administer symbol atomic swap offers` | Administer all atomic settlements |

Most settlement routes use custom access checks in addition to these
permissions. Ownership, verified Symbol account public keys, settlement state,
and admin status are part of route access.

## State and Security Rules

- Drupal stores local projections. Blockchain state is authoritative.
- Signed payload bodies are not stored after submission.
- Intent hash, root transaction hash, and public settlement JSON are hidden from non-admin users.
- Local cancellation does not reverse or modify announced Symbol transactions.
- Announced transactions move through Symbol Engine projection state.
- Unannounced local settlements can expire locally after the configured deadline.
- Aggregate bonded hash locks are funded by the taker. The hash lock is returned if the bonded transaction succeeds; it is not returned if the transaction fails.

## Notifications

Settlement notifications are stored in Drupal and displayed on the settlement
view and notification pages.

Generated events include:

- Expiration
- Confirmed
- Finalized
- Failed
- Rolled back

Outbound email and webhook delivery are implemented as best-effort optional
delivery. Leave the recipient or URL empty to disable outbound delivery.

Environment overrides:

- `SYMBOL_ATOMIC_SWAP_NOTIFICATION_EMAIL`
- `SYMBOL_ATOMIC_SWAP_WEBHOOK_URL`
- `SYMBOL_ATOMIC_SWAP_WEBHOOK_TIMEOUT`

## Testing

Run this module's tests from the repository root:

```bash
make test-drupal
```

`make test-drupal` currently runs the `symbol_atomic_swap` PHPUnit group.

Direct equivalent:

```bash
docker compose exec -T drupal sh -lc 'cd /opt/drupal && runuser -u www-data -- env SIMPLETEST_BASE_URL=http://127.0.0.1 SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal BROWSERTEST_OUTPUT_BASE_URL=http://127.0.0.1:8080 vendor/bin/phpunit -c phpunit.xml.dist --group symbol_atomic_swap'
```
