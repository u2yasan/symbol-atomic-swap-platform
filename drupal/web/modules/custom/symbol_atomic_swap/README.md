## Symbol Atomic Swap

Drupal is a read/proxy layer for Symbol Engine. It must not hold private keys
and must not sign Symbol transactions.

### Routes

- `GET /symbol-atomic-swap/health`
- `GET /symbol-atomic-swap/engine/network`
- `GET /symbol-atomic-swap/engine/intent/{intentHash}`
- `GET /symbol-atomic-swap/engine/projection/{network}/{transactionHash}`
- `/admin/config/services/symbol-atomic-swap/engine`
- `/admin/config/services/symbol-atomic-swap/engine/operations`

The admin page is a read-only lookup surface for Engine network, intent, and
projection state.

The operations page can build unsigned Aggregate Complete payloads, submit
signed payloads for Engine semantic verification, and announce already verified
transactions. Drupal does not sign and does not accept private key material.
When an Engine response contains `qrPayload`, Drupal renders it as a QR code in
the browser without external CDN assets.

### Tests

The module has Kernel and Functional coverage under `tests/src`.

Run from the Drupal project root after installing Drupal dev dependencies:

```sh
vendor/bin/phpunit -c phpunit.xml.dist --group symbol_atomic_swap
```

The current Docker image installs production Drupal dependencies only. PHPUnit
requires Drupal core development dependencies and is not available in the image
by default.
