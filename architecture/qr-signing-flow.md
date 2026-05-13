# QR Signing Flow

## 1. Purpose

The project uses QR signing to avoid server-side private key custody.

---

## 2. Principle

The server may generate unsigned payloads.

The server must never sign on behalf of a user.

---

## 3. Flow

1. Drupal requests QR payload from Symbol Engine.
2. Symbol Engine builds unsigned transaction payload.
3. Symbol Engine converts payload into wallet-compatible QR format.
4. Drupal displays QR code.
5. User scans QR using wallet.
6. Wallet signs transaction.
7. Signed payload is returned through supported channel.
8. Symbol Engine verifies and announces signed transaction.

---

## 4. QR Payload Must Include

- network identifier
- transaction type
- unsigned payload
- deadline
- signer public key
- expected transaction hash if available
- callback or submission instructions if supported

---

## 5. Forbidden

- embedding private keys in QR
- storing signed payload as final success
- assuming QR scan means transaction execution
- assuming wallet signing means finalization
