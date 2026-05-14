# Symbol Engine API

Symbol Engine is the only service that may build, validate, announce, and monitor Symbol blockchain transactions.

Drupal must treat Symbol Engine as an internal protected API.

## Base URL

Local Docker default:

```text
http://symbol-engine:3000
```

Override with:

```text
SYMBOL_ENGINE_BASE_URL
```

## Authentication

All routes except `GET /health` require:

```text
Authorization: Bearer <SYMBOL_ENGINE_API_TOKEN>
```

Missing or invalid request token returns `401`.

If `SYMBOL_ENGINE_API_TOKEN` is not configured on Symbol Engine, protected routes fail closed with `503`.

Validation failures return `400 validation_failed`. Each issue contains only `code`, `path`, and `message`; rejected request values are not echoed.

## Public Health Check

```http
GET /health
```

Returns minimal service status.

Example:

```json
{
  "status": "ok",
  "service": "symbol-engine",
  "network": "testnet"
}
```

This route must not expose node URLs, credentials, private keys, tokens, or internal infrastructure details.

## Network Info

```http
GET /v1/network
Authorization: Bearer <token>
```

Returns the configured Symbol network.

Node endpoints are hidden by default. Set `SYMBOL_ENGINE_EXPOSE_NODE_ENDPOINTS=true` only when an internal operational client explicitly needs the configured REST and WebSocket URLs.

Example:

```json
{
  "network": "testnet"
}
```

## Intent Read

```http
GET /v1/intents/{intentHash}
Authorization: Bearer <token>
```

Returns a stored swap intent without signed payload or node response.

Missing intent returns:

```http
404 Not Found
```

```json
{
  "error": "intent_not_found"
}
```

## Projection Read

```http
GET /v1/projections/{network}/{transactionHash}
Authorization: Bearer <token>
```

Returns current transaction projection:

```json
{
  "transactionHash": "CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC",
  "network": "testnet",
  "state": "confirmed",
  "lastEventKey": "testnet:CCCC...",
  "updatedAt": "2026-05-13T10:00:00.000Z",
  "blockHeight": 33
}
```

Missing projection returns:

```http
404 Not Found
```

```json
{
  "error": "projection_not_found"
}
```

## Aggregate Complete Build Validation

```http
POST /v1/aggregate-complete/build
Authorization: Bearer <token>
Content-Type: application/json
```

Request:

```json
{
  "network": "testnet",
  "deadlineHours": 2,
  "correlationId": "swap-0001",
  "legs": [
    {
      "signerPublicKey": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
      "recipientAddress": "TAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
      "mosaicId": "72C0212E67A08BCE",
      "amount": "100"
    },
    {
      "signerPublicKey": "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB",
      "recipientAddress": "TBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB",
      "mosaicId": "72C0212E67A08BCE",
      "amount": "200"
    }
  ]
}
```

Current response:

```json
{
  "intentId": "f0d14dd6-bec7-4274-9bf4-abd53c2023a4",
  "correlationId": "swap-0001",
  "network": "testnet",
  "unsignedPayload": "680100...",
  "qrPayload": {
    "type": "symbol-aggregate-complete",
    "network": "testnet",
    "unsignedPayload": "680100...",
    "deadline": "111359644483",
    "requiredCosigners": [
      "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
      "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB"
    ],
    "callback": null,
    "intentHash": "7B212236CC3B54437C663EFF17444F4E8DF03A85AE7DB61B8821BD30C5DEBABF"
  },
  "requiredCosigners": [
    "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
    "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB"
  ],
  "deadline": "111359644483",
  "intentHash": "7B212236CC3B54437C663EFF17444F4E8DF03A85AE7DB61B8821BD30C5DEBABF"
}
```

The route persists the normalized swap intent in Symbol Engine PostgreSQL.

## Signed Payload Semantic Verification

```http
POST /v1/transactions/verify-signed-payload
Authorization: Bearer <token>
Content-Type: application/json
```

Request:

```json
{
  "payload": "680100...",
  "intentHash": "7B212236CC3B54437C663EFF17444F4E8DF03A85AE7DB61B8821BD30C5DEBABF"
}
```

Response:

```json
{
  "accepted": true,
  "reason": "semantic_verification_passed",
  "transactionHash": "CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC"
}
```

Symbol SDK semantic verification compares decoded transaction contents against the stored swap intent:

- network
- transaction type
- signer public keys
- recipients
- mosaic IDs
- amounts
- deadline
- max fee
- transaction hash

## Transaction Announcement

```http
POST /v1/transactions/announce
Authorization: Bearer <token>
Content-Type: application/json
```

Request:

```json
{
  "intentHash": "7B212236CC3B54437C663EFF17444F4E8DF03A85AE7DB61B8821BD30C5DEBABF"
}
```

The intent must already be semantically verified and marked `signed`.

Successful announcement creates an `announced` projection event.

## Event Projection

```http
POST /v1/events
Authorization: Bearer <token>
Content-Type: application/json
```

Example confirmed event:

```json
{
  "transactionHash": "CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC",
  "network": "testnet",
  "eventType": "TransactionConfirmed",
  "blockHeight": 10,
  "observedAt": "2026-05-12T15:21:00.000Z"
}
```

Example finalized event:

```json
{
  "transactionHash": "CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC",
  "network": "testnet",
  "eventType": "TransactionFinalized",
  "finalizedHeight": 10,
  "observedAt": "2026-05-12T15:22:00.000Z"
}
```

Forbidden transition example:

```text
TransactionFinalized without prior TransactionConfirmed
```

Returns:

```http
409 Conflict
```

```json
{
  "error": "invalid_state_transition",
  "message": "transaction must be confirmed before finalized"
}
```

## Finalization Check

```http
POST /v1/finalization/check
Authorization: Bearer <token>
Content-Type: application/json
```

Request:

```json
{
  "transactionHash": "CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC",
  "confirmedHeight": 10,
  "finalizedHeight": 10
}
```

Response:

```json
{
  "finalized": true
}
```

A transaction is finalized only when:

```text
confirmedHeight <= finalizedHeight
```

Confirmed-only transactions must not be treated as completed swaps.
