# Drupal Web UI Check Procedure

## Purpose

This procedure verifies the Drupal UI for Symbol Atomic Swap operations.

It checks:

- access control
- owner scoping
- offer CRUD screens
- QR payload display
- signed payload verification UI
- announcement and projection sync UI
- transaction history
- notifications
- admin Engine dashboard
- non-secret settings

Do not use private keys, mnemonics, wallet passwords, or signing secrets during
this check. Drupal must never receive signing secrets.

## Prerequisites

Start the local stack:

```sh
docker compose up -d
```

Verify Drupal and Symbol Engine health:

```sh
curl -I http://127.0.0.1:8080
curl -s http://127.0.0.1:3000/health
```

Apply Drupal database updates and rebuild routing/cache before opening the UI:

```sh
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush updatedb -y'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush cache:rebuild'
```

Required custom tables must exist after updates:

```text
symbol_atomic_swap_offer
symbol_atomic_swap_notification
```

Required environment:

```text
SYMBOL_ENGINE_BASE_URL=http://symbol-engine:3000
SYMBOL_ENGINE_API_TOKEN=<32+ character random token>
SYMBOL_NETWORK=testnet
```

If protected Engine operations are checked, `SYMBOL_ENGINE_API_TOKEN` must match
the token configured in Symbol Engine.

Run the automated baseline first:

```sh
make test-drupal
```

Expected result:

```text
OK
```

## Test Accounts

Create Drupal roles first, then assign those roles to test users.

Recommended roles:

| Role | Required permissions |
| --- | --- |
| `symbol_swap_viewer` | `view symbol atomic swap offers` |
| `symbol_swap_operator` | `view symbol atomic swap offers`, `create symbol atomic swap offers`, `operate symbol atomic swap offers` |
| `symbol_swap_admin` | `view symbol atomic swap offers`, `create symbol atomic swap offers`, `operate symbol atomic swap offers`, `administer symbol atomic swap offers`, `administer site configuration` |

Create the roles with Drush:

```sh
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush role:create symbol_swap_viewer "Symbol Swap Viewer"'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush role:create symbol_swap_operator "Symbol Swap Operator"'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush role:create symbol_swap_admin "Symbol Swap Admin"'
```

Assign permissions through the Drupal UI:

```text
/admin/people/permissions
```

This is safer than hand-editing config because it shows exactly which
permissions are enabled for each role.

Then create or use three Drupal users:

| Account | Required permissions |
| --- | --- |
| Viewer A | `view symbol atomic swap offers` |
| Operator A | `view symbol atomic swap offers`, `create symbol atomic swap offers`, `operate symbol atomic swap offers` |
| Admin | `view symbol atomic swap offers`, `create symbol atomic swap offers`, `operate symbol atomic swap offers`, `administer symbol atomic swap offers`, `administer site configuration` |

Create the users with Drush. Set passwords through environment variables so
the passwords are not written into shell history as command arguments:

```sh
export SYMBOL_SWAP_VIEWER_PASSWORD='<set-local-password>'
export SYMBOL_SWAP_OPERATOR_PASSWORD='<set-local-password>'
export SYMBOL_SWAP_ADMIN_PASSWORD='<set-local-password>'

docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:create viewer_a --mail=viewer-a@example.test'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:create operator_a --mail=operator-a@example.test'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:create swap_admin --mail=swap-admin@example.test'

docker compose exec -e SYMBOL_SWAP_VIEWER_PASSWORD drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:password viewer_a "$SYMBOL_SWAP_VIEWER_PASSWORD"'
docker compose exec -e SYMBOL_SWAP_OPERATOR_PASSWORD drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:password operator_a "$SYMBOL_SWAP_OPERATOR_PASSWORD"'
docker compose exec -e SYMBOL_SWAP_ADMIN_PASSWORD drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:password swap_admin "$SYMBOL_SWAP_ADMIN_PASSWORD"'
```

Assign:

| Account | Role |
| --- | --- |
| Viewer A | `symbol_swap_viewer` |
| Operator A | `symbol_swap_operator` |
| Admin | `symbol_swap_admin` |

Assign the roles with Drush:

```sh
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:role:add symbol_swap_viewer viewer_a'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:role:add symbol_swap_operator operator_a'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush user:role:add symbol_swap_admin swap_admin'
```

Use separate accounts for owner-scope checks. Do not use user 1 for all checks;
that hides owner-scope failures.

## Test Data

Use testnet-only values unless the environment is explicitly configured for
mainnet.

Example form values:

```text
Offer label: UI smoke offer
Network: Testnet
Correlation ID: ui-smoke-0001
Deadline hours: 2
Max fee: empty

Transfer leg 1 signer public key:
D04AB232742BB4AB3A1368BD4615E4E6D0224AB71A016BAF8520A332C9778737
Transfer leg 1 recipient address:
TCD4NC5VIE2EEB3BCV5JRLBNJXYDW5Q5JK547MI
Transfer leg 1 mosaic ID:
72C0212E67A08BCE
Transfer leg 1 amount:
100

Transfer leg 2 signer public key:
A09AA5F47A6759802FF955F8DC2D2A14A5C99D23BE97F864127FF9383455A4F0
Transfer leg 2 recipient address:
TCOUCADEQEZXJBPY2E54DIWVKGQQUGNAJTZ6VXY
Transfer leg 2 mosaic ID:
72C0212E67A08BCE
Transfer leg 2 amount:
200
```

These are UI validation fixtures. They are not real wallet instructions.

## 1. Anonymous Access

1. Open `/symbol-atomic-swap/offers` while logged out.
2. Open `/symbol-atomic-swap/notifications` while logged out.
3. Open `/symbol-atomic-swap/transactions` while logged out.
4. Open `/admin/config/services/symbol-atomic-swap/engine` while logged out.

Expected:

- offer, notification, transaction, and admin pages return `403`
- `/symbol-atomic-swap/health` remains accessible

## 2. Offer List

Login as `Operator A`.

Open:

```text
/symbol-atomic-swap/offers
```

Expected:

- page loads
- `Create swap offer` is visible
- filters are visible:
  - Search
  - State
  - Network
- Owner UID filter is not visible to non-admin users
- no offers owned by other users are visible

Login as `Admin` and open the same page.

Expected:

- Owner UID filter is visible
- admin can see all offers

## 3. Create Offer

Login as `Operator A`.

Open:

```text
/symbol-atomic-swap/offers/add
```

Submit the values listed in [Test Data](#test-data).

Expected:

- invalid field values are rejected before submit
- duplicate signer public keys are rejected
- mainnet is rejected when mainnet UI operations are disabled in settings
- on successful Engine build, Drupal redirects to the offer detail page
- state becomes `qr_generated`
- QR code renders
- QR payload details are visible
- intent hash is displayed with a `Copy` button
- no private key, mnemonic, wallet password, or signing secret field exists

If Symbol Engine is unavailable:

- offer is saved as draft
- UI shows a build failure message
- no signed payload is stored

## 4. Offer Detail

Open the created offer detail page:

```text
/symbol-atomic-swap/offers/{offerId}
```

Expected sections:

- Summary
- Transfer legs
- Projection
- QR payload
- Public offer JSON

Expected security behavior:

- signed payload body is not shown
- node response body is not shown
- long hashes wrap without breaking layout
- hash copy buttons do not submit forms
- only valid operations for the current state are visible

Expected state behavior for `qr_generated`:

- `Submit signed payload` is visible to the owner with operate permission
- `Announce transaction` is not visible
- `Sync projection` is not visible until a transaction hash exists

## 5. Owner Scope

Login as a different non-admin user.

Open:

```text
/symbol-atomic-swap/offers
/symbol-atomic-swap/offers/{offerId}
/symbol-atomic-swap/offers/{offerId}/submit-signed-payload
/symbol-atomic-swap/offers/{offerId}/announce
/symbol-atomic-swap/offers/{offerId}/sync-projection
```

Expected:

- list does not show another user’s offer
- direct offer detail access returns `403`
- direct operation routes return `403`

Login as `Admin`.

Expected:

- admin can view, edit, operate, and delete any offer

## 6. Signed Payload Form

Login as the offer owner with operate permission.

Open:

```text
/symbol-atomic-swap/offers/{offerId}/submit-signed-payload
```

Expected:

- intent hash is displayed
- current offer state is displayed
- signed payload textarea is visible
- help text states whitespace is ignored and hex is uppercased
- oversized payloads are rejected
- odd-length or non-hex payloads are rejected
- Engine rejection displays only a stable reason, not decoder or SDK exception details

After a valid Engine-accepted signed payload:

- state becomes `signed`
- transaction hash is stored and displayed
- signed payload body is not displayed
- `Announce transaction` becomes visible
- `Sync projection` becomes visible

## 7. Announcement

Open:

```text
/symbol-atomic-swap/offers/{offerId}/announce
```

Expected:

- only signed offers with valid intent hash and transaction hash can be announced
- invalid states show an error and do not call Engine
- successful announcement changes state to `announced`
- transaction hash remains visible
- signed payload body remains hidden

If Symbol node is unavailable:

- UI shows failure
- low-level node transport details are not exposed

## 8. Projection Sync

Open:

```text
/symbol-atomic-swap/offers/{offerId}/sync-projection
```

Expected:

- only non-terminal chain-tracked offers with valid transaction hash can sync
- `Projection` section shows:
  - projection state
  - block height
  - finalized height
  - projection updated at
  - manual sync allowed
  - automatic sync eligible
- admin also sees projection queue status

State expectations:

| Engine projection state | Drupal UI expectation |
| --- | --- |
| `unconfirmed` | shown as not completed |
| `confirmed` | shown as not completed |
| `finalized` | shown as completed and terminal |
| `failed` | shown as terminal error state |
| `rolled_back` | shown as terminal error state |

Confirmed is not completed. Only finalized is completed.

## 9. Transaction History

Open:

```text
/symbol-atomic-swap/transactions
```

Expected:

- only offers with transaction hashes are listed
- non-admin users see only their own transactions
- admin sees all transactions
- transaction hash has a `Copy` button
- `Completed` is `Yes` only when state is `finalized`
- `confirmed` and `unconfirmed` are not treated as completed
- filters work:
  - Search
  - State
  - Network

## 10. Notifications

Open:

```text
/symbol-atomic-swap/notifications
```

Expected:

- unread count is displayed
- severity is visible
- read/unread state is visible
- non-admin users see only notifications for their own offers
- admin sees all notifications
- `Unread only` filter works
- `Mark read` marks a single notification read
- `Mark all read` marks only the current user’s notifications unless admin

Trigger conditions to verify when possible:

- expiration creates warning notification
- confirmed creates status notification
- finalized creates status notification
- failed creates error notification
- rolled_back creates error notification

## 11. Admin Settings

Login as `Admin`.

Open:

```text
/admin/config/services/symbol-atomic-swap/settings
```

Expected:

- Engine base URL can be configured
- Engine timeout accepts only bounded numeric values
- API token value is never displayed
- API token state shows configured or missing
- notification email can be configured
- webhook URL can be configured
- webhook token value is never displayed
- webhook token state shows configured or not configured
- mainnet UI operations are disabled unless explicitly enabled

Security requirement:

- do not store API tokens or webhook bearer tokens in Drupal config
- secrets must remain environment-managed

## 12. Engine Dashboard

Open:

```text
/admin/config/services/symbol-atomic-swap/engine
```

Expected:

- dashboard section is visible
- `Read health` works without API token
- `Read network` requires a valid API token
- intent lookup validates 64-character hex intent hash
- projection lookup validates network and 64-character hex transaction hash
- Engine error responses do not leak credentials or internal infrastructure secrets

## 13. Manual Engine Operations

Open:

```text
/admin/config/services/symbol-atomic-swap/engine/operations
```

Expected:

- build unsigned transaction works only with valid input
- QR renders when Engine returns `qrPayload`
- verify signed payload validates even-length hex
- announce validates intent hash
- Drupal does not sign
- Drupal does not ask for private key material

This page is admin-only and should be treated as an operational diagnostic
surface, not an end-user workflow.

## 14. Responsive UI Check

Check the following pages at desktop and mobile widths:

- `/symbol-atomic-swap/offers`
- `/symbol-atomic-swap/offers/{offerId}`
- `/symbol-atomic-swap/transactions`
- `/symbol-atomic-swap/notifications`

Expected:

- long hashes wrap
- copy buttons do not overlap text
- QR canvas fits the viewport
- tables remain readable enough for operational use
- no text overlaps controls

## 15. Regression Commands

Run after manual UI checks:

```sh
make test-drupal
git diff --check
```

Expected:

- Drupal tests pass
- no whitespace errors

## Failure Handling

If any check fails:

1. Record the route, account, permission set, and offer ID.
2. Record the current offer state and transaction hash presence.
3. Check whether the issue is UI-only or a route access bypass.
4. Treat any owner-scope bypass, secret exposure, or finalized-state mutation as a security bug.
5. Do not proceed to mainnet testing until the bug is fixed and covered by Functional or Kernel tests.
