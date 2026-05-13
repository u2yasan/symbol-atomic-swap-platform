# Event-Driven Architecture

## 1. Purpose

This project must synchronize with Symbol blockchain using blockchain events.

---

## 2. Event Sources

Symbol Engine must subscribe to:

- unconfirmedAdded
- confirmedAdded
- partialAdded
- cosignature
- finalizedBlock
- status

---

## 3. Internal Events

Symbol Engine should normalize blockchain events into internal events:

- TransactionAnnounced
- TransactionUnconfirmed
- TransactionConfirmed
- TransactionFinalized
- TransactionFailed
- TransactionRolledBack
- PartialTransactionAdded
- CosignatureReceived

---

## 4. Drupal Projection Updates

Drupal receives normalized events and updates local projection records.

Drupal must not interpret raw blockchain WebSocket events directly.

---

## 5. Event Replay

The system should support rebuilding Drupal projections from stored blockchain references.

Minimum replay inputs:

- transaction hash
- block height
- signer public key
- mosaic IDs
- event type
- event timestamp

---

## 6. Queue Usage

Redis queue or Drupal Queue API may be used for asynchronous updates.

Long-running WebSocket listeners must remain in Symbol Engine, not Drupal.
