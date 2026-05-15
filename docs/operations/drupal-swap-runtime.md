# Drupal Swap Runtime Procedure

## Purpose

This procedure describes the operational flow for a real Drupal-managed Symbol
Atomic Swap offer:

```text
create offer -> external signing -> verify signed payload -> announce -> projection sync
```

Drupal is not a wallet. Drupal must never receive private keys, mnemonics,
wallet passwords, or signing secrets.

## Preconditions

Confirm the local or production stack is healthy:

```sh
curl -I http://127.0.0.1:8080
curl -s http://127.0.0.1:3000/health
```

Apply Drupal updates and rebuild caches after deployment:

```sh
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush updatedb -y'
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush cache:rebuild'
```

Required runtime configuration:

```text
SYMBOL_ENGINE_BASE_URL=<internal Symbol Engine URL>
SYMBOL_ENGINE_API_TOKEN=<32+ character random token>
SYMBOL_NETWORK=testnet or mainnet
SYMBOL_NODE_URL=<Symbol REST node URL required for announce>
SYMBOL_NODE_WS_URL=<Symbol WebSocket node URL required for listener/projection>
```

Drupal must have an operator account with:

```text
view symbol atomic swap offers
create symbol atomic swap offers
operate symbol atomic swap offers
```

## Account And Balance Requirements

Use accounts that are valid for the selected network.

For Aggregate Complete offers:

- the aggregate transaction signer pays the network fee
- the aggregate signer is the first signer public key in `requiredCosigners`
- every transfer sender must hold the mosaic it transfers
- testnet offers require testnet XYM for fees
- mainnet offers require real XYM and real mosaic balances

If the signer accounts have no XYM, `Verify signed payload` can still pass, but
`Announce transaction` is expected to fail at the Symbol node.

Do not use UI fixture public keys for real signing. They have no matching
private keys in this project.

## 1. Create Offer

Login as the Drupal operator.

Open:

```text
/symbol-atomic-swap/offers/add
```

Enter:

- a unique `Correlation ID` for the selected network
- real signer public keys controlled by external wallets or signing tools
- recipient addresses for the selected network
- mosaic IDs and amounts that the sender accounts can actually transfer

Expected result:

- state becomes `qr_generated`
- `Intent hash` is displayed
- `QR payload` is available
- `Submit signed payload` is visible

If the offer is saved as `draft`, the Engine build failed. Fix the validation or
Engine configuration before attempting signing.

## 2. External Signing

Open the offer detail page:

```text
/symbol-atomic-swap/offers/{offerId}
```

Sign outside Drupal:

1. Scan the displayed QR with a phone or signing device.
2. The QR opens a Drupal payload page URL.
3. On the payload page, copy either `QR scan text` for a compatible signing
   tool, or copy `Unsigned payload` for a signer that accepts raw transaction
   payload HEX.
4. If the signer cannot consume the payload page, open `QR payload` on the offer
   detail page and copy the `unsignedPayload` value.
5. Verify the transaction details in the signer before signing:
   - network
   - Aggregate Complete transaction type
   - signer public keys
   - recipient addresses
   - mosaic IDs
   - amounts
   - max fee when explicitly set
6. Sign with every account listed in `requiredCosigners`.
7. Export the final signed transaction payload as even-length HEX.

Only the final signed payload HEX returns to Drupal. No signing secret returns to
Drupal.

For local test signing with Symbol SDK, create the aggregate signer root signed
payload from the copied `Unsigned payload`:

```sh
cd symbol-engine
UNSIGNED_PAYLOAD='PASTE_UNSIGNED_PAYLOAD_HEX' \
SIGNER_PRIVATE_KEY='PASTE_AGGREGATE_SIGNER_PRIVATE_KEY_HEX' \
npm run sign:root-payload
```

Copy only the JSON output `payload` value. Use it as follows:

- paste it into `Submit signed payload` only when the payload already contains
  every required signature
- paste it into `Assemble signed payload` as the root signed transaction payload
  when detached cosignature JSON has been stored separately

When Symbol Desktop Wallet returns detached cosignature JSON instead of a final
signed payload:

1. Open `/symbol-atomic-swap/offers/{offerId}/submit-cosignature`.
2. Paste the Desktop Wallet JSON containing `parentHash`, `signature`, and
   `signerPublicKey`.
3. Submit it for verification and storage.
4. Repeat until every non-root cosigner has been collected.
5. Open `/symbol-atomic-swap/offers/{offerId}/assemble-signed-payload`.
6. Paste the root signed transaction payload HEX created by the aggregate
   signer.
7. Submit. Engine attaches the stored cosignatures, verifies the final payload,
   and marks the offer `signed` when semantic verification passes.

Do not paste cosignature JSON into `Signed payload`. That field only accepts a
complete signed transaction payload HEX.

For local iPhone testing, do not expect a phone to open `127.0.0.1` on the Mac.
Use a Drupal URL that the phone can reach, or copy the payload from the desktop
browser.

## 3. Verify Signed Payload

Open:

```text
/symbol-atomic-swap/offers/{offerId}/submit-signed-payload
```

Paste the signed payload HEX into `Signed payload`, then submit.

Expected success:

- Engine returns `semantic_verification_passed`
- Drupal state becomes `signed`
- transaction hash is stored and displayed
- signed payload body is not displayed
- `Announce transaction` becomes visible

Expected rejection examples:

| Reason | Action |
| --- | --- |
| `payload must be hex` | Copy the signed payload as raw HEX, not JSON. |
| `payload hex length must be even` | Remove truncated or malformed characters. |
| `transaction signature is missing` | Sign the payload before submitting. |
| `missing required signer ...` | Collect every required cosignature. |
| `unexpected signer ...` | Rebuild the offer with the correct signer public keys. |
| `embedded transfer intent mismatch` | Reject the payload and inspect signer output. |
| `network mismatch` | Use accounts and signer settings for the selected network. |

Do not announce a payload that fails semantic verification.

## 4. Announce Transaction

Before announcing, confirm:

- offer state is `signed`
- transaction hash is present
- `SYMBOL_NODE_URL` is configured
- the aggregate signer has enough XYM for fees
- transfer senders have the transferred mosaics
- the transaction deadline has not expired

Open:

```text
/symbol-atomic-swap/offers/{offerId}/announce
```

Submit the confirmation form.

Expected success:

- Drupal state becomes `announced`
- transaction hash remains visible
- signed payload body remains hidden
- projection sync becomes available

If the Symbol node rejects the transaction, treat the blockchain result as
authoritative. Common causes are insufficient balance, expired deadline,
duplicate transaction, node unavailability, or a network mismatch.

## 5. Projection Sync

Projection state is how Drupal catches up with chain state.

Manual sync:

```text
/symbol-atomic-swap/offers/{offerId}/sync-projection
```

Automatic sync:

```sh
docker compose exec drupal sh -lc 'cd /opt/drupal && vendor/bin/drush cron'
```

Expected progression:

```text
announced -> unconfirmed -> confirmed -> finalized
```

Failure progression:

```text
announced -> failed
announced -> rolled_back
```

Operational rule:

- `confirmed` is not complete
- only `finalized` is complete
- `failed` and `rolled_back` are terminal error states
- finalized offers must not be downgraded by stale reads

## 6. Post-Announcement Checks

Open:

```text
/symbol-atomic-swap/offers/{offerId}
/symbol-atomic-swap/transactions
/symbol-atomic-swap/notifications
```

Confirm:

- state matches the latest projection
- transaction hash is visible
- finalized transactions show completed in transaction history
- failed, rolled back, expired, confirmed, and finalized notifications appear
- raw signed payload and raw node response bodies are not displayed

## Recovery Rules

| Situation | Action |
| --- | --- |
| Build failed and offer is `draft` | Fix inputs or Engine availability, then edit and rebuild. |
| Duplicate correlation ID | Use a new correlation ID for that network. |
| QR cannot be scanned | Hard reload the browser and confirm the QR payload is visible. |
| Signer cannot parse QR URL | Open the URL and copy `Unsigned payload`, or copy `unsignedPayload` from `QR payload`. |
| Verify failed | Do not announce. Re-sign from the current unsigned payload. |
| Announce failed because of funds | Fund the signer accounts, then rebuild/sign if deadline expired. |
| Announce failed because node is unavailable | Restore `SYMBOL_NODE_URL`, then retry while deadline is valid. |
| Projection is stale | Run manual sync or Drupal cron. |

## Security Rules

- Drupal stores local offer projections, intent hash, unsigned payload, QR
  payload, transaction hash, and state.
- Drupal must not store private keys, mnemonics, wallet passwords, or signing
  secrets.
- Drupal must not display the signed payload body after verification.
- Symbol Engine semantic verification is required before announcement.
- The blockchain is the source of truth after announcement.
