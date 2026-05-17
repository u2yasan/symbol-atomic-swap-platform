# Symbol P2P Ad Listing

Drupal module for non-custodial P2P advertisement listings between Symbol mosaics.

This module is not an order book and does not custody or lock assets. A listing is only an advertisement. Final settlement is delegated to the existing `symbol_atomic_swap` module.

## Scope

Implemented:

- Fixed-lot mosaic-for-mosaic advertisements.
- Seller creates a listing from their verified Symbol account.
- Seller balance is checked at listing creation.
- Seller balance can be checked manually before taking a listing.
- Cron refreshes seller balances for active listings.
- Mosaic metadata is checked for `transferable` and valid divisibility.
- Taker can take an active listing.
- Seller and taker balances are checked again before match.
- A matched listing creates a `symbol_atomic_swap_offer`.
- The existing atomic settlement flow handles QR generation, signing, announcement, and projection sync.

Not implemented:

- Partial fills.
- Floating prices.
- Price ratio listings.
- Auction listings.
- Bid negotiation.
- Asset locking at listing time.
- Reputation, rate limits, or failure counters.

## Design Rules

Listings are non-binding advertisements.

The module intentionally does not lock seller inventory during listing creation. This prevents Drupal from pretending it controls assets it does not custody.

To reduce stale-listing risk, buyers can manually check seller balance before taking a listing, and cron periodically refreshes seller balances for active listings.

The safety boundary is:

1. Listing creation checks seller balance and mosaic transferability.
2. Take flow rechecks seller balance, taker balance, and mosaic transferability.
3. Match creates an exact atomic settlement.
4. Atomic settlement prevents one-sided asset transfer during final execution.

Do not treat `seller_balance_checked_amount` as guaranteed inventory. It is only a point-in-time observation. The timestamp is displayed so buyers can judge staleness before accepting.

## Routes

| Route | Path | Purpose |
| --- | --- | --- |
| `symbol_p2p_ad_listing.list` | `/symbol-p2p/listings` | Browse listings |
| `symbol_p2p_ad_listing.add` | `/symbol-p2p/listings/add` | Create listing |
| `symbol_p2p_ad_listing.view` | `/symbol-p2p/listings/{listingId}` | View listing |
| `symbol_p2p_ad_listing.check_balance` | `/symbol-p2p/listings/{listingId}/check-balance` | Refresh seller balance |
| `symbol_p2p_ad_listing.take` | `/symbol-p2p/listings/{listingId}/take` | Match listing into atomic settlement |
| `symbol_p2p_ad_listing.cancel` | `/symbol-p2p/listings/{listingId}/cancel` | Admin cancel |

## Permissions

| Permission | Meaning |
| --- | --- |
| `view symbol p2p ad listings` | View list/detail pages |
| `create symbol p2p ad listings` | Create listings |
| `operate symbol p2p ad listings` | Take listings |
| `administer symbol p2p ad listings` | Cancel listings |

Taking a listing also requires `operate symbol atomic swap offers`, because the result is an atomic settlement that must be finalized through `symbol_atomic_swap`.

## State Model

Listing states:

```text
active
matching
matched
cancelled
```

State transitions:

```text
active -> matching -> matched
active -> cancelled
```

`matching` is an internal transient state used to claim an active listing before creating the backing atomic settlement.

## Data Model

Table: `symbol_p2p_ad_listing`

Key fields:

- `seller_uid`
- `seller_address`
- `seller_public_key`
- `network`
- `offered_mosaic_id`
- `offered_amount`
- `requested_mosaic_id`
- `requested_amount`
- `swap_window_minutes`
- `seller_balance_checked_amount`
- `seller_balance_checked_at`
- `matched_offer_id`

Amounts are stored as atomic integer strings, not display decimals.

## Create Flow

1. User must have a verified Symbol account from `symbol_atomic_swap`.
2. Seller enters offered mosaic, offered amount, requested mosaic, requested amount, and settlement window.
3. Module checks both mosaics via Symbol Engine metadata.
4. Module rejects non-transferable mosaics.
5. Module converts display amounts to atomic integer strings using mosaic divisibility.
6. Module checks seller balance for the offered mosaic.
7. Listing is saved as `active`.

No asset is locked.

## Balance Refresh

Manual refresh:

1. Buyer opens an active listing.
2. Buyer clicks `Check seller balance`.
3. Module checks the seller address balance for the offered mosaic.
4. Module updates `seller_balance_checked_amount` and `seller_balance_checked_at`.
5. UI reports whether the observed balance is sufficient for the listed amount.

Automatic refresh:

1. Drupal cron calls `symbol_p2p_ad_listing_cron()`.
2. The module selects active listings ordered by oldest balance check.
3. The module refreshes up to 50 listings per cron run.
4. Failures are logged and do not block other listings.

## Take Flow

1. Taker must have a verified Symbol account on the same network.
2. Taker cannot be the seller.
3. Buyer may manually refresh seller balance before submitting the take form.
4. Module rechecks offered and requested mosaic metadata.
5. Module rechecks seller balance for the offered mosaic.
6. Module checks taker balance for the requested mosaic.
7. Listing is claimed with `active -> matching`.
8. Module inserts a `symbol_atomic_swap_offer`.
9. Listing becomes `matched`.
10. User is redirected to the existing atomic settlement accept/finalization route.

The generated atomic settlement maps terms as:

```text
leg1: seller pays offered_mosaic_id/offered_amount to taker
leg2: taker pays requested_mosaic_id/requested_amount to seller
```

## Testing

Run Drupal tests:

```bash
make test-drupal
```

Run only this module's kernel test:

```bash
docker compose exec -T drupal sh -lc 'cd /opt/drupal && runuser -u www-data -- env SIMPLETEST_BASE_URL=http://127.0.0.1 SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal BROWSERTEST_OUTPUT_BASE_URL=http://127.0.0.1:8080 vendor/bin/phpunit -c phpunit.xml.dist web/modules/custom/symbol_p2p_ad_listing/tests/src/Kernel/AdListingRepositoryTest.php'
```

## Operational Risk

The main remaining risks are not atomic-settlement risks. They are marketplace risks:

- Seller spends assets after the last balance check.
- Taker spends assets before take.
- Repeated failed matches waste user time.
- Listings can still become stale between balance refresh and take submission.
- No reputation or rate limiting exists yet.

The next practical hardening step is to add failure counters and expiry for stale active listings.
