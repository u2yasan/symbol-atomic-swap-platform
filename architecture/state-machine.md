# State Machine

## 1. Purpose

This document defines the allowed lifecycle states for swap offers and swap transactions.

The state machine exists to prevent unsafe assumptions, especially treating unconfirmed transactions as completed swaps.

---

## 2. Swap Offer States

```text
draft
  -> created
  -> qr_generated
  -> signed
  -> announced
  -> unconfirmed
  -> confirmed
  -> finalized
```

Failure and terminal states:

```text
expired
cancelled
failed
rolled_back
```

---

## 3. State Definitions

### draft

The swap offer exists locally but is not yet ready for signing.

### created

The swap offer has been created in Drupal as a projection record.

### qr_generated

Symbol Engine has generated a transaction payload and QR signing payload.

### signed

The required external wallet signature has been collected.

### announced

The signed transaction payload has been submitted to a Symbol node.

### unconfirmed

The transaction is seen in the unconfirmed transaction stream.

This state must not be treated as completed.

### confirmed

The transaction has been included in a block.

This is stronger than unconfirmed but still not irreversible.

### finalized

The transaction is included in a finalized block.

This is the only state that represents an irreversible completed swap.

### expired

The transaction or offer deadline has passed.

### cancelled

The user or system cancelled the offer before finalization.

### failed

The transaction failed validation or was rejected by the network.

### rolled_back

A previously confirmed but non-finalized transaction is no longer part of the canonical chain.

---

## 4. Forbidden Transitions

The following transitions are forbidden:

```text
unconfirmed -> finalized
announced -> finalized
created -> finalized
failed -> finalized
expired -> finalized
cancelled -> finalized
```

A transaction may only enter `finalized` after finalized block verification.

---

## 5. Finalized State Immutability

Once a swap reaches `finalized`, it must not be mutated as a normal update.

If correction is required, create a correction event rather than changing historical state directly.
