# Transaction Flow

## 1. Overview

This document defines the transaction lifecycle between Drupal, Symbol Engine, external wallet, and Symbol blockchain.

---

## 2. Main Flow

```text
User
  -> Drupal
  -> Symbol Engine
  -> Wallet QR signing
  -> Symbol Engine
  -> Symbol Blockchain
  -> Symbol Engine Listener
  -> Drupal Projection Update
```

---

## 3. Steps

1. User creates a swap offer in Drupal.
2. Drupal validates business-level input.
3. Drupal stores a projection record with state `created`.
4. Drupal requests Symbol Engine to build an aggregate transaction.
5. Symbol Engine validates blockchain-level input.
6. Symbol Engine builds the transaction using symbol-sdk/javascript.
7. Symbol Engine returns unsigned transaction payload and QR payload.
8. User signs the payload externally using wallet.
9. Signed payload is submitted to Symbol Engine.
10. Symbol Engine verifies payload structure.
11. Symbol Engine announces transaction.
12. Listener detects unconfirmed/confirmed/finalized events.
13. Symbol Engine notifies Drupal.
14. Drupal updates projection state.

---

## 4. Responsibility Boundary

Drupal must not serialize Symbol transactions.

Symbol Engine must not own user authentication or business UI.

---

## 5. Transaction Hash

The transaction hash is the cross-system identifier.

Every transaction projection must store:

- transaction hash
- signer public key
- network identifier
- deadline
- current state
- confirmed height
- finalized height
