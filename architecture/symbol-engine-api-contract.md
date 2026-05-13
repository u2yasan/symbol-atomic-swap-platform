# Symbol Engine API Contract

## 1. Authentication

All Symbol Engine API routes except `/health` require:

```text
Authorization: Bearer <SYMBOL_ENGINE_API_TOKEN>
```

`SYMBOL_ENGINE_API_TOKEN` must be at least 32 characters.

If the token is missing from server configuration, protected routes must fail closed with `503`.

If the supplied token is absent or invalid, protected routes must return `401`.

## 2. Public Route

`GET /health` is public.

It must not expose node URLs, secrets, credentials, or internal configuration.

## 3. Aggregate Complete Build

`POST /v1/aggregate-complete/build`

The request must validate:

- network
- deadline
- two distinct signer public keys
- recipient addresses
- mosaic IDs
- positive integer amounts
- correlation ID

The route must serialize an unsigned Aggregate Complete transaction and persist the normalized swap intent.

The response must include:

- intent ID
- correlation ID
- network
- unsigned payload
- QR JSON payload
- required cosigners
- deadline
- intent hash

## 3.1 Read APIs

`GET /v1/intents/{intentHash}`

Returns stored intent metadata for Drupal UI.

Must not return:

- signed payload
- node response
- secrets
- authorization metadata

`GET /v1/projections/{network}/{transactionHash}`

Returns current transaction projection.

Missing records must return `404`.

## 4. Signed Payload Verification

`POST /v1/transactions/verify-signed-payload`

Minimum structural checks:

- payload is hex
- payload byte length is valid
- intent hash is supplied
- matching stored intent exists

SDK-level verification must compare decoded transaction contents against the stored swap intent:

- network
- signer public keys
- recipients
- mosaic IDs
- amounts
- deadline
- max fee
- transaction hash

Verification success is the only path that may mark a swap intent `signed`.

## 4.1 Transaction Announcement

`POST /v1/transactions/announce`

Only verified signed intents may be announced.

The route must:

- load the signed payload by intent hash
- submit it to `PUT /transactions` on the configured Symbol node
- mark the intent `announced` on node success
- create a failed event on node rejection
- avoid logging full signed payloads

## 5. Event Projection

`POST /v1/events`

Events must be idempotent by:

```text
network + transactionHash + eventType + blockHeight + finalizedHeight + statusCode
```

Finalized projections are immutable.

Forbidden transitions must return `409`, not `500`.

## 6. Finalization Check

`POST /v1/finalization/check`

A transaction is finalized only when:

```text
confirmedHeight <= finalizedHeight
```

Confirmed-only transactions are not completed swaps.
