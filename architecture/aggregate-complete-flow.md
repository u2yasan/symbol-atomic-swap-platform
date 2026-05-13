# Aggregate Complete Flow

## 1. Purpose

Aggregate Complete is the initial MVP transaction type for same-chain mosaic atomic swaps.

It is simpler than Aggregate Bonded and suitable for proving the non-custodial QR signing flow.

---

## 2. Use Case

Two parties exchange Symbol mosaics on the same network.

Example:

```text
Alice sends Mosaic A to Bob.
Bob sends Mosaic B to Alice.
```

Both transfers are wrapped inside one aggregate transaction.

---

## 3. Flow

1. Drupal receives swap terms.
2. Drupal sends terms to Symbol Engine.
3. Symbol Engine builds an Aggregate Complete transaction.
4. Required cosignatures are identified.
5. QR payload is generated.
6. Wallet signs the transaction.
7. Signed payload is returned.
8. Symbol Engine announces transaction.
9. Finalization monitor updates the swap state.

---

## 4. MVP Limitation

Aggregate Complete is acceptable for MVP but does not fully solve asynchronous counterparty signing.

For more realistic DEX behavior, Aggregate Bonded must be added later.

---

## 5. Required Validation

Symbol Engine must validate:

- network type
- mosaic IDs
- amount values
- recipient addresses
- signer public keys
- deadline
- fee configuration

Drupal must validate:

- user permissions
- offer ownership
- UI-level constraints
- duplicate submissions
