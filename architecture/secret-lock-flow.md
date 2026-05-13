# Secret Lock Flow
# Symbol Atomic Swap Platform

---

# 1. Purpose

This document defines the Secret Lock / Secret Proof flow
used for asynchronous atomic swaps.

Secret Lock is required for:

- cross-chain swaps
- public/private chain swaps
- trust-minimized asynchronous settlement
- offline coordination
- time-limited swap recovery

This mechanism prevents unilateral asset theft.

---

# 2. Core Concept

A Secret Lock transaction locks mosaics using:

- secret hash
- proof algorithm
- duration

Assets can only be claimed by revealing the original proof.

---

# 3. High-Level Flow

```text
Party A
  |
  | generate random secret
  |
  | hash(secret)
  |
  | SecretLockTransaction
  |
  v

Symbol Chain A
  |
  | locked assets
  |
  v

Party B
  |
  | observes secret lock
  |
  | creates matching lock
  |
  v

Symbol Chain B
  |
  | locked assets
  |
  v

Party A
  |
  | reveals proof
  |
  v

Party B learns proof
  |
  | unlocks counterpart assets
  |
  v

Atomic swap completed
```

---

# 4. Secret Generation

## 4.1 Requirements

Secrets must be:

- cryptographically random
- unique
- never reused
- generated client-side

---

## 4.2 Secret Size

Recommended:

- 32 bytes random

---

## 4.3 Hash Algorithm

Supported:

- SHA3-256

Preferred:
SHA3-256

---

# 5. Secret Lock Components

## 5.1 SecretLockTransaction

Contains:

- recipient
- mosaic
- amount
- duration
- hashAlgorithm
- secret

---

## 5.2 SecretProofTransaction

Contains:

- hashAlgorithm
- secret
- proof

Proof reveals unlock material.

---

# 6. Transaction Lifecycle

## Phase 1 — Secret Generation

1. Client generates random proof
2. SHA3-256 hash created
3. Secret stored client-side only

Forbidden:
Server-generated secrets.

---

## Phase 2 — Lock Creation

Party A:

1. creates SecretLockTransaction
2. signs externally
3. announces transaction

State:

- lock_pending
- lock_confirmed
- lock_finalized

---

## Phase 3 — Counterparty Lock

Party B:

1. validates incoming lock
2. creates matching lock
3. signs externally
4. announces transaction

---

## Phase 4 — Proof Reveal

Party A:

1. submits SecretProofTransaction
2. reveals proof
3. unlocks Party B assets

---

## Phase 5 — Counterparty Redemption

Party B:

1. extracts proof from chain
2. submits matching SecretProofTransaction
3. unlocks Party A assets

---

# 7. Timeout Recovery

If counterparty disappears:

- lock expires
- locked mosaics refunded automatically

This prevents permanent fund loss.

---

# 8. State Machine

Allowed states:

- secret_generated
- lock_created
- lock_announced
- lock_unconfirmed
- lock_confirmed
- lock_finalized
- proof_revealed
- redeemed
- expired
- refunded
- failed

---

# 9. Drupal Responsibilities

Drupal must:

- store projection state
- track lock status
- display swap progress
- manage expiration timers
- notify users

Drupal must NOT:

- generate secrets
- store proofs permanently
- perform signing

---

# 10. Symbol Engine Responsibilities

Symbol Engine must:

- generate tx payloads
- validate hash algorithms
- monitor lock events
- monitor proof events
- update state transitions
- detect expiration

---

# 11. Blockchain Event Sources

Required listeners:

- confirmedAdded
- finalizedBlock
- status

Additionally monitor:

- SecretLockTransaction
- SecretProofTransaction

---

# 12. Replayability

The entire swap lifecycle must be reconstructable from:

- SecretLockTransaction
- SecretProofTransaction
- finalized blocks

Database state alone is insufficient.

---

# 13. Security Requirements

Forbidden:

- server-side proof generation
- predictable secrets
- reusable secrets
- plaintext proof logging

Required:

- cryptographically secure randomness
- finalized verification
- expiration monitoring

---

# 14. Cross-Chain Extension

Future architecture supports:

- Symbol public ↔ Symbol private
- Symbol ↔ EVM
- Symbol ↔ Bitcoin-style HTLC

This document intentionally separates:

- coordination layer
- settlement layer

---

# 15. Important Constraints

Secret Lock swaps are asynchronous.

This means:

- temporary partial states exist
- expiration handling is mandatory
- UX complexity increases significantly

Secret Lock should NOT be the MVP.

Aggregate Complete swaps are recommended first.

---

# 16. Development Recommendation

Recommended implementation order:

1. Aggregate Complete
2. Aggregate Bonded
3. Secret Lock
4. Cross-chain swaps

Attempting Secret Lock first will significantly increase complexity.

---

# 17. Anti-Patterns

Forbidden:

- trusting DB expiration timers alone
- assuming proof delivery
- storing reusable proofs
- synchronous blocking flows
- server-side signing

---

# 18. Philosophy

Secret Lock is not merely a transaction type.

It is a distributed coordination protocol
for trust-minimized asynchronous settlement.
