# Symbol Atomic Swap Platform
# System Architecture Overview

Project Name: `symbol-atomic-swap-platform`

---

## 1. Purpose

This project provides a non-custodial atomic swap coordination platform for Symbol blockchain mosaics.

The platform is **not** a centralized exchange. It coordinates swap offers, aggregate transaction generation, QR-based signing, transaction announcement, blockchain event monitoring, and finalized-state synchronization.

The blockchain remains the source of truth.

---

## 2. Core Principles

### 2.1 Blockchain Source of Truth

Symbol blockchain is the authoritative state.

Drupal database is projection/cache only.

Balances, ownership, and transaction finality must always be reproducible from blockchain events.

### 2.2 No Custody

The platform must never store private keys, mnemonics, wallet passwords, or signing secrets.

All signatures must occur externally via wallet applications.

Supported signing methods:

- QR signing
- mobile wallet signing
- offline signing

### 2.3 Finalization-Driven Consistency

Unconfirmed transactions are **not** completed swaps.

Only finalized transactions are considered irreversible.

System state transitions must respect these separate states:

- unconfirmed
- confirmed
- finalized

### 2.4 Event-Driven Architecture

System synchronization must be driven by Symbol blockchain events.

Polling-only architectures are forbidden.

### 2.5 SDK Strategy

Blockchain logic must use:

```text
symbol-sdk/javascript
```

PHP SDK usage is prohibited for blockchain core logic.

Reason: JavaScript SDK is closer to the upstream Symbol SDK development path and is better suited for WebSocket/listener-driven services.

---

## 3. High-Level Architecture

```text
+--------------------------------------------------+
|                  Drupal 11                       |
|--------------------------------------------------|
| UI                                               |
| Authentication                                   |
| Admin                                            |
| Swap Offer Management                            |
| Projection Database                              |
| Notifications                                    |
| Queue Workers                                    |
+------------------------+-------------------------+
                         |
                         | REST / Queue
                         v
+--------------------------------------------------+
|                Symbol Engine                     |
|--------------------------------------------------|
| Fastify API                                      |
| Aggregate Transaction Builder                    |
| QR Payload Generator                             |
| Listener/WebSocket                               |
| Finalization Monitor                             |
| Transaction Announcer                            |
| Event Dispatcher                                 |
+------------------------+-------------------------+
                         |
                         v
+--------------------------------------------------+
|                Symbol Blockchain                 |
+--------------------------------------------------+
```

---

## 4. System Components

## 4.1 Drupal

Responsibilities:

- UI
- user management
- swap offer CRUD
- transaction history view
- admin panel
- queue processing
- notification dispatching
- projection persistence

Non-responsibilities:

- transaction serialization
- private key handling
- blockchain listening
- blockchain finality calculation
- direct blockchain state authority

## 4.2 Symbol Engine

Responsibilities:

- aggregate transaction construction
- payload serialization
- QR payload generation
- listener management
- WebSocket connections
- blockchain event parsing
- finalized state monitoring
- transaction announcement
- transaction status normalization

## 4.3 Symbol Blockchain

Responsibilities:

- immutable transaction ledger
- aggregate execution
- transaction confirmation
- finalization
- hash locking
- secret locking

---

## 5. Transaction Lifecycle

1. User creates swap offer.
2. Drupal stores projection record.
3. Drupal requests transaction build from Symbol Engine.
4. Symbol Engine creates aggregate transaction.
5. QR payload is generated.
6. User signs externally.
7. Signed payload is returned.
8. Symbol Engine announces transaction.
9. Listener detects blockchain events.
10. Finalized event updates Drupal projection.

---

## 6. Transaction States

Allowed states:

- draft
- created
- signed
- announced
- unconfirmed
- confirmed
- finalized
- expired
- failed
- rolled_back

Forbidden:

- treating unconfirmed as finalized
- treating Drupal DB state as blockchain truth
- mutating finalized state except by explicit correction event

---

## 7. Infrastructure

### Development

- Docker Compose
- Drupal 11
- PHP 8.4
- Node.js 22+
- PostgreSQL
- Redis
- Symbol Engine using TypeScript strict mode

### Production

Recommended:

- Nginx
- PHP-FPM
- Node.js Symbol Engine
- Redis
- PostgreSQL
- reverse proxy
- TLS termination
- isolated service network

---

## 8. Security Model

Forbidden:

- private key storage
- mnemonic storage
- server-side signing
- mutable finalized state
- duplicated transaction serializers
- PHP-based blockchain listener

Required:

- QR signing
- external wallet signing
- finalized verification
- event replay capability
- strict input validation
- transaction hash verification

---

## 9. Event Sources

The system must subscribe to:

- confirmedAdded
- unconfirmedAdded
- partialAdded
- cosignature
- finalizedBlock
- status

---

## 10. Database Philosophy

The database is not authoritative.

Database contents must be rebuildable from blockchain events.

This architecture follows an event-sourced projection model.

---

## 11. Future Extensions

Planned future capabilities:

- Aggregate Bonded swaps
- Secret Lock swaps
- cross-chain swaps
- public/private chain swaps
- RWA token swaps
- multi-party aggregate coordination
- AI-assisted transaction coordination

---

## 12. Anti-Patterns

The following are prohibited:

- PHP blockchain listeners
- synchronous long-poll loops
- direct balance mutation in DB
- duplicated transaction serializers
- treating DB state as truth
- storing transaction success before finalization
- storing private keys in Drupal Key API for user swaps

---

## 13. Development Philosophy

The platform is not a CRUD application.

It is an event-driven blockchain transaction coordination system.

Consistency and replayability are more important than temporary UX speed.
