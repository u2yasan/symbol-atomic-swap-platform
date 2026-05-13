# Event Projection Operations

Drupal database state is a projection.

Symbol blockchain is the source of truth.

## Event Sources

Symbol Engine must normalize blockchain events into internal events:

- `TransactionAnnounced`
- `TransactionUnconfirmed`
- `TransactionConfirmed`
- `TransactionFinalized`
- `TransactionFailed`
- `TransactionRolledBack`
- `PartialTransactionAdded`
- `CosignatureReceived`

Drupal must not consume raw Symbol WebSocket events directly.

## WebSocket Listener

Symbol Engine subscribes to `finalizedBlock` globally.

Transaction-specific channels are address-scoped and require `SYMBOL_ENGINE_LISTENER_ADDRESSES`.

Supported channels:

- `unconfirmedAdded/<address>`
- `confirmedAdded/<address>`
- `status/<address>`
- `partialAdded/<address>`
- `cosignature/<address>`
- `finalizedBlock`

The listener reconnects with exponential backoff.

Raw events are normalized before they enter the projection pipeline.

`finalizedBlock` does not finalize transactions directly from raw hashes. It finds existing `confirmed` projections where:

```text
confirmed block height <= finalized height
```

Those projections are then moved through the same `TransactionFinalized` event path.

## Reconciliation

The reconciliation worker compensates for listener downtime and missed WebSocket events.

It checks:

- signed intents with transaction hash
- announced intents with transaction hash
- announced projections
- unconfirmed projections
- confirmed projections

It uses Symbol REST endpoints:

- `GET /transactionStatus/{hash}`
- `GET /transactions/confirmed/{hash}`
- `GET /transactions/unconfirmed/{hash}`
- `GET /chain/info`

Rules:

- status error creates `TransactionFailed`
- confirmed transaction creates `TransactionConfirmed`
- confirmed height covered by finalized height creates `TransactionFinalized`
- unconfirmed transaction creates `TransactionUnconfirmed`
- REST 404 is ignored
- overlapping worker runs are skipped

## Idempotency Key

Events must be idempotent by:

```text
network + transactionHash + eventType + blockHeight + finalizedHeight + statusCode
```

Repeated delivery of the same event must return the existing projection without changing state.

## Allowed Completion Path

The safe completion path is:

```text
announced -> unconfirmed -> confirmed -> finalized
```

`finalized` requires prior `confirmed`.

Direct transitions to `finalized` are rejected.

## Forbidden Transitions

Reject with `409 Conflict`:

- `created -> finalized`
- `announced -> finalized`
- `unconfirmed -> finalized`
- `failed -> finalized`
- `rolled_back -> finalized`
- `expired -> finalized`
- `cancelled -> finalized`

## Rollback Handling

If a confirmed transaction disappears before finalization:

1. Mark projection as `rolled_back`.
2. Keep the transaction record.
3. Create an event record explaining the transition.
4. Do not delete history.

## Finalized Immutability

Once a projection reaches `finalized`, normal updates are forbidden.

Allowed follow-up:

```text
correction event
```

Forbidden follow-up:

```text
direct overwrite of finalized state
```

## Replay Requirements

Projection rebuild requires at minimum:

- transaction hash
- network
- event type
- block height
- finalized height
- signer public key when available
- observed timestamp
- status code for failed transactions

If this data is not persisted, replay is not possible.
