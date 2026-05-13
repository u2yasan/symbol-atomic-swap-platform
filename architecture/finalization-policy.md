# Finalization Policy

## 1. Purpose

This document defines when the platform may treat a swap as completed.

---

## 2. Policy

Only finalized transactions are completed swaps.

The following are not completed swaps:

- QR generated
- signed
- announced
- unconfirmed
- confirmed

---

## 3. UI Labels

Use clear labels:

| State | User-facing label |
|---|---|
| announced | Submitted |
| unconfirmed | Seen by network |
| confirmed | Included in block |
| finalized | Completed |
| failed | Failed |
| expired | Expired |
| rolled_back | Rolled back |

---

## 4. Database Updates

Drupal may update projection states as events arrive.

However, irreversible business logic must only run after finalization.

Examples of finalized-only actions:

- completed swap history
- permanent receipt
- accounting export
- settlement notification
- downstream business process trigger

---

## 5. Rollback Handling

If a confirmed transaction disappears before finalization, mark it as `rolled_back`.

Do not delete the record.

Create an event record explaining the transition.
