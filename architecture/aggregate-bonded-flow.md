# Aggregate Bonded Flow

## 1. Purpose

Aggregate Bonded supports asynchronous multi-party signing.

This is the preferred model for more realistic DEX-style coordination.

---

## 2. Flow

1. Maker creates offer.
2. Symbol Engine builds Aggregate Bonded transaction.
3. Maker signs transaction.
4. Hash Lock transaction is announced.
5. Aggregate Bonded transaction is announced as partial.
6. Taker cosigns.
7. Required cosignatures are collected.
8. Transaction is confirmed.
9. Transaction is finalized.
10. Drupal projection is updated.

---

## 3. Important Notes

Aggregate Bonded requires Hash Lock.

Hash Lock requires temporary XYM lock.

Drupal must clearly explain this to users before signing.

---

## 4. States

Additional states for Aggregate Bonded:

- hash_lock_announced
- hash_lock_confirmed
- partial_announced
- partial_cosigned
- partial_completed

These states are not needed for initial Aggregate Complete MVP.
