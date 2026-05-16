(function (Drupal, once) {
  'use strict';

  function normalizeHex(value) {
    return String(value || '').replace(/\s+/g, '').toUpperCase();
  }

  function signedPayloadFromResult(result) {
    if (!result || typeof result !== 'object') {
      return '';
    }
    return normalizeHex(result.payload || result.transactionPayload || result.signedPayload || '');
  }

  function hexFromResult(result, keys) {
    if (!result || typeof result !== 'object') {
      return '';
    }
    for (const key of keys) {
      if (result[key]) {
        return normalizeHex(result[key]);
      }
    }
    return '';
  }

  function setStatus(container, message, isError) {
    const status = container.querySelector('[data-symbol-sss-status]');
    if (!status) {
      return;
    }
    status.textContent = message;
    status.classList.toggle('messages', Boolean(message));
    status.classList.toggle('messages--error', Boolean(message && isError));
    status.classList.toggle('messages--status', Boolean(message && !isError));
  }

  function activePublicKey() {
    return normalizeHex(window.SSS && (window.SSS.activePublicKey || window.SSS.activePublicAccountPublicKey || ''));
  }

  Drupal.behaviors.symbolAtomicSwapSssSign = {
    attach(context) {
      once('symbol-atomic-swap-bonded-hash-lock', '[data-symbol-bonded-hash-lock-announce="1"]', context).forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          const container = button.closest('[data-symbol-sss-container]');
          if (!container) {
            return;
          }

          const payloadField = container.querySelector('[data-symbol-hash-lock-signed-payload]');
          const submitTrigger = container.querySelector('[data-symbol-bonded-submit-trigger]');
          const unsignedPayload = normalizeHex(container.getAttribute('data-symbol-hash-lock-unsigned-payload'));
          const requiredSigner = normalizeHex(container.getAttribute('data-symbol-sss-required-signer'));
          if (!payloadField || !submitTrigger || !unsignedPayload) {
            setStatus(container, Drupal.t('Unsigned hash lock payload is not available.'), true);
            return;
          }

          if (!window.SSS || typeof window.SSS.setTransactionByPayload !== 'function' || typeof window.SSS.requestSign !== 'function') {
            setStatus(container, Drupal.t('SSS Extension is not available in this browser profile.'), true);
            return;
          }
          const activeSigner = activePublicKey();
          if (requiredSigner && activeSigner && activeSigner !== requiredSigner) {
            setStatus(container, Drupal.t('SSS is using a different account. Switch SSS to the taker account that funds the hash lock.'), true);
            return;
          }

          button.disabled = true;
          setStatus(container, Drupal.t('Waiting for SSS hash lock signature approval.'), false);
          try {
            window.SSS.setTransactionByPayload(unsignedPayload);
            const signed = await window.SSS.requestSign();
            const signedPayload = signedPayloadFromResult(signed);
            if (!signedPayload || signedPayload.length % 2 !== 0 || !/^[0-9A-F]+$/.test(signedPayload)) {
              setStatus(container, Drupal.t('SSS did not return a valid signed hash lock payload.'), true);
              return;
            }
            payloadField.value = signedPayload;
            setStatus(container, Drupal.t('Hash lock signed. Waiting for hash lock confirmation before partial announcement.'), false);
            if (typeof payloadField.form.requestSubmit === 'function') {
              payloadField.form.requestSubmit(submitTrigger);
            }
            else {
              submitTrigger.click();
            }
          }
          catch (error) {
            setStatus(container, Drupal.t('SSS hash lock signature request was cancelled or failed.'), true);
          }
          finally {
            button.disabled = false;
          }
        });
      });

      once('symbol-atomic-swap-sss-sign', '[data-symbol-sss-sign]', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const container = button.closest('[data-symbol-sss-container]');
          if (!container) {
            return;
          }

          const payloadField = container.querySelector('[data-symbol-sss-signed-payload]');
          const unsignedPayload = normalizeHex(container.getAttribute('data-symbol-sss-unsigned-payload'));
          const requiredSigner = normalizeHex(container.getAttribute('data-symbol-sss-required-signer'));
          if (!payloadField || !unsignedPayload) {
            setStatus(container, Drupal.t('Unsigned payload is not available.'), true);
            return;
          }

          if (!window.SSS || typeof window.SSS.setTransactionByPayload !== 'function' || typeof window.SSS.requestSign !== 'function') {
            setStatus(container, Drupal.t('SSS Extension is not available in this browser profile.'), true);
            return;
          }
          const activeSigner = activePublicKey();
          if (requiredSigner && activeSigner && activeSigner !== requiredSigner) {
            setStatus(container, Drupal.t('SSS is using a different account. Switch SSS to the required signing account.'), true);
            return;
          }

          button.disabled = true;
          setStatus(container, Drupal.t('Waiting for SSS signature approval.'), false);
          try {
            window.SSS.setTransactionByPayload(unsignedPayload);
            const signed = await window.SSS.requestSign();
            const signedPayload = signedPayloadFromResult(signed);
            if (!signedPayload || signedPayload.length % 2 !== 0 || !/^[0-9A-F]+$/.test(signedPayload)) {
              setStatus(container, Drupal.t('SSS did not return a valid signed payload.'), true);
              return;
            }
            payloadField.value = signedPayload;
            payloadField.dispatchEvent(new Event('input', { bubbles: true }));
            setStatus(container, Drupal.t('SSS signed payload was copied into the form. Submit it to verify.'), false);
          }
          catch (error) {
            setStatus(container, Drupal.t('SSS signature request was cancelled or failed.'), true);
          }
          finally {
            button.disabled = false;
          }
        });
      });

      once('symbol-atomic-swap-sss-cosign', '[data-symbol-sss-cosign]', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const container = button.closest('[data-symbol-sss-container]');
          if (!container) {
            return;
          }

          const payloadField = container.querySelector('[data-symbol-sss-cosignature-json]');
          const parentHashField = container.querySelector('[data-symbol-sss-parent-hash]');
          const unsignedPayload = normalizeHex(container.getAttribute('data-symbol-sss-unsigned-payload'));
          const requiredSigner = normalizeHex(container.getAttribute('data-symbol-sss-required-signer'));
          const autoSubmit = container.getAttribute('data-symbol-sss-cosign-auto-submit') === '1';
          if (!payloadField || !parentHashField || !unsignedPayload) {
            setStatus(container, Drupal.t('Unsigned payload is not available.'), true);
            return;
          }

          if (!window.SSS || typeof window.SSS.setTransactionByPayload !== 'function' || typeof window.SSS.requestSignCosignatureTransaction !== 'function') {
            setStatus(container, Drupal.t('SSS Extension cosignature API is not available in this browser profile.'), true);
            return;
          }
          const activeSigner = activePublicKey();
          if (requiredSigner && activeSigner && activeSigner !== requiredSigner) {
            setStatus(container, Drupal.t('SSS is using a different account. Switch SSS to the expected cosigner account.'), true);
            return;
          }

          button.disabled = true;
          setStatus(container, Drupal.t('Waiting for SSS cosignature approval.'), false);
          try {
            window.SSS.setTransactionByPayload(unsignedPayload);
            const cosigned = await window.SSS.requestSignCosignatureTransaction();
            const signature = hexFromResult(cosigned, ['signature']);
            const signerPublicKey = hexFromResult(cosigned, ['signerPublicKey', 'publicKey']) || normalizeHex(window.SSS.activePublicKey || '');
            const parentHash = hexFromResult(cosigned, ['parentHash', 'hash', 'transactionHash']) || normalizeHex(parentHashField.value);
            if (!signature || !signerPublicKey || !parentHash) {
              setStatus(container, Drupal.t('SSS did not return enough cosignature data. Fill parent hash and signer public key manually, then verify.'), true);
              return;
            }
            if (requiredSigner && signerPublicKey !== requiredSigner) {
              setStatus(container, Drupal.t('SSS returned a cosignature from a different account. Switch SSS to the expected cosigner account and sign again.'), true);
              return;
            }
            payloadField.value = JSON.stringify({
              parentHash,
              signerPublicKey,
              signature,
              version: { lower: 0, higher: 0 },
            }, null, 2);
            payloadField.dispatchEvent(new Event('input', { bubbles: true }));
            if (autoSubmit) {
              const submitTrigger = container.querySelector('[data-symbol-sss-cosign-submit]');
              setStatus(container, Drupal.t('SSS cosignature was created. Announcing aggregate bonded cosignature.'), false);
              if (container.requestSubmit && submitTrigger) {
                container.requestSubmit(submitTrigger);
              }
              else if (submitTrigger) {
                submitTrigger.click();
              }
              return;
            }
            setStatus(container, Drupal.t('SSS cosignature JSON was copied into the form. Verify it before assembling.'), false);
          }
          catch (error) {
            setStatus(container, Drupal.t('SSS cosignature request was cancelled or failed.'), true);
          }
          finally {
            button.disabled = false;
          }
        });
      });
    },
  };
})(Drupal, once);
