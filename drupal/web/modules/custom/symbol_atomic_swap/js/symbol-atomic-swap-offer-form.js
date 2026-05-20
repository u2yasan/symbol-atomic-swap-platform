(function (Drupal, once) {
  'use strict';

  function normalizeAddress(value) {
    return String(value || '').replace(/\s+/g, '').toUpperCase();
  }

  const CURRENCY_MOSAIC_IDS = {
    mainnet: '6BED913FA20223F8',
    testnet: '72C0212E67A08BCE',
  };

  function normalizeHex(value) {
    return String(value || '').replace(/\s+/g, '').toUpperCase();
  }

  function updateDefaultMosaics(form, networkValue) {
    const nextMosaicId = CURRENCY_MOSAIC_IDS[networkValue] || CURRENCY_MOSAIC_IDS.testnet;
    const knownMosaicIds = Object.values(CURRENCY_MOSAIC_IDS);
    form.querySelectorAll('[data-symbol-default-mosaic]').forEach((field) => {
      const current = normalizeHex(field.value);
      if (!current || knownMosaicIds.includes(current)) {
        field.value = nextMosaicId;
        field.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  }

  function formatMosaicAmount(amountValue, divisibility) {
    const raw = String(amountValue || '').replace(/\s+/g, '');
    const amount = /^[0-9]+$/.test(raw) ? BigInt(raw) : 0n;
    const scale = 10n ** BigInt(divisibility);
    const whole = amount / scale;
    const fraction = String(amount % scale).padStart(divisibility, '0');
    return divisibility > 0 ? `${whole}.${fraction}` : String(whole);
  }

  function normalizeHumanAmount(value, divisibility) {
    const raw = String(value || '').replace(/\s+/g, '');
    const match = raw.match(/^(0|[1-9][0-9]*)(?:\.([0-9]*))?$/);
    if (!match) {
      return raw;
    }
    const whole = match[1];
    const fraction = String(match[2] || '').slice(0, divisibility).padEnd(divisibility, '0');
    return divisibility > 0 ? `${whole}.${fraction}` : whole;
  }

  function statusText(result) {
    const alias = Array.isArray(result.aliases) && result.aliases.length ? result.aliases[0] : 'N/A';
    const divisibility = Number.isInteger(result.divisibility) ? result.divisibility : null;
    const balance = result.balance === null || result.balance === undefined || divisibility === null
      ? 'N/A'
      : formatMosaicAmount(result.balance, divisibility);
    const transferError = mosaicTransferError(result);
    const text = Drupal.t('Alias: @alias. Divisibility: @divisibility. Balance: @balance', {
      '@alias': alias,
      '@divisibility': divisibility === null ? 'N/A' : String(divisibility),
      '@balance': balance,
    });
    return transferError ? `${text}. ${transferError}` : text;
  }

  function mosaicTransferError(result) {
    return result && result.transferable === false
      ? Drupal.t('This mosaic is not transferable and cannot be used in an atomic settlement.')
      : '';
  }

  function bindMosaicMetadata(form, network) {
    form.querySelectorAll('[data-symbol-mosaic-id]').forEach((mosaicField) => {
      const container = mosaicField.closest('fieldset') || form;
      const status = container.querySelector('[data-symbol-mosaic-status]');
      const amount = container.querySelector('[data-symbol-mosaic-amount]');
      if (!status) {
        return;
      }

      let timer = 0;
      let lastKey = '';
      let lastResult = null;
      const render = () => {
        if (lastResult) {
          status.textContent = statusText(lastResult);
          mosaicField.setCustomValidity(mosaicTransferError(lastResult));
        }
      };
      const normalizeAmountField = () => {
        if (amount && lastResult && Number.isInteger(lastResult.divisibility) && amount.value !== '') {
          amount.value = normalizeHumanAmount(amount.value, lastResult.divisibility);
        }
      };
      const update = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(async () => {
          const mosaicId = normalizeHex(mosaicField.value);
          status.textContent = '';
          mosaicField.setCustomValidity('');
          if (!/^[0-9A-F]{16}$/.test(mosaicId)) {
            return;
          }

          const key = `${network.value}:${mosaicId}`;
          if (key === lastKey && lastResult) {
            render();
            return;
          }

          try {
            const response = await fetch(Drupal.url(`symbol-atomic-swap/mosaic/${network.value}/${mosaicId}`), {
              headers: { accept: 'application/json' },
              credentials: 'same-origin',
            });
            if (!response.ok) {
              lastKey = key;
              lastResult = {
                mosaicId,
                aliases: [],
                divisibility: null,
              };
              render();
              return;
            }
            lastKey = key;
            lastResult = await response.json();
            render();
          }
          catch (error) {
            status.textContent = Drupal.t('Mosaic metadata lookup failed.');
          }
        }, 150);
      };

      mosaicField.addEventListener('input', update);
      mosaicField.addEventListener('change', update);
      if (amount) {
        amount.addEventListener('change', normalizeAmountField);
        amount.addEventListener('blur', normalizeAmountField);
      }
      network.addEventListener('change', () => {
        lastKey = '';
        lastResult = null;
        update();
      });
      update();
    });
  }

  function bindAggregateDeadlineDefaults(container) {
    const deadline = container.querySelector('[data-symbol-aggregate-deadline-hours]');
    const complete = container.querySelector('input[name="transaction[aggregate_type]"][value="aggregate_complete"]');
    const bonded = container.querySelector('input[name="transaction[aggregate_type]"][value="aggregate_bonded"]');
    if (!deadline || !complete || !bonded) {
      return;
    }

    const completeHours = container.getAttribute('data-symbol-aggregate-complete-deadline-hours') || '6';
    const bondedHours = container.getAttribute('data-symbol-aggregate-bonded-deadline-hours') || '48';
    const update = () => {
      deadline.value = bonded.checked ? bondedHours : completeHours;
      deadline.dispatchEvent(new Event('input', { bubbles: true }));
      deadline.dispatchEvent(new Event('change', { bubbles: true }));
    };

    complete.addEventListener('change', update);
    bonded.addEventListener('change', update);
    update();
  }

  Drupal.behaviors.symbolAtomicSwapOfferForm = {
    attach(context) {
      once('symbol-atomic-swap-maker-address', '[data-symbol-maker-address-form]', context).forEach((form) => {
        const network = form.querySelector('[data-symbol-maker-network]');
        const address = form.querySelector('[data-symbol-maker-address]');
        const status = form.querySelector('[data-symbol-maker-address-status]');
        if (!network || !address) {
          return;
        }

        let timer = 0;
        const update = () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(async () => {
            const rawAddress = normalizeAddress(address.value);
            const expectedPrefix = network.value === 'mainnet' ? 'N' : 'T';
            if (status) {
              status.textContent = '';
            }
            if (!new RegExp(`^${expectedPrefix}[A-Z2-7]{38}$`).test(rawAddress)) {
              return;
            }

            const url = Drupal.url(`symbol-atomic-swap/public-key/${network.value}/${rawAddress}`);
            try {
              const response = await fetch(url, {
                headers: { accept: 'application/json' },
                credentials: 'same-origin',
              });
              if (!response.ok) {
                if (status) {
                  status.textContent = Drupal.t('No public key was found for this address on the selected network.');
                }
                return;
              }
              const result = await response.json();
              if (status) {
                status.textContent = result.publicKey ? '' : Drupal.t('No public key was found for this address on the selected network.');
              }
            }
            catch (error) {
              if (status) {
                status.textContent = Drupal.t('Public key lookup failed.');
              }
            }
          }, 150);
        };

        address.addEventListener('input', update);
        address.addEventListener('change', update);
        network.addEventListener('change', () => {
          updateDefaultMosaics(form, network.value);
          update();
        });
        updateDefaultMosaics(form, network.value);
        bindMosaicMetadata(form, network);
        update();
      });

      once('symbol-atomic-swap-taker-address', '[data-symbol-taker-address-form]', context).forEach((form) => {
        const network = form.getAttribute('data-symbol-taker-network') || 'testnet';
        const address = form.querySelector('[data-symbol-taker-address]');
        const status = form.querySelector('[data-symbol-taker-address-status]');
        if (!address) {
          return;
        }

        let timer = 0;
        const update = () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(async () => {
            const rawAddress = normalizeAddress(address.value);
            const expectedPrefix = network === 'mainnet' ? 'N' : 'T';
            if (status) {
              status.textContent = '';
            }
            if (!new RegExp(`^${expectedPrefix}[A-Z2-7]{38}$`).test(rawAddress)) {
              return;
            }

            try {
              const response = await fetch(Drupal.url(`symbol-atomic-swap/public-key/${network}/${rawAddress}`), {
                headers: { accept: 'application/json' },
                credentials: 'same-origin',
              });
              if (!response.ok) {
                if (status) {
                  status.textContent = Drupal.t('No public key was found for this address on the selected network.');
                }
                return;
              }
              const result = await response.json();
              if (status) {
                status.textContent = result.publicKey ? '' : Drupal.t('No public key was found for this address on the selected network.');
              }
            }
            catch (error) {
              if (status) {
                status.textContent = Drupal.t('Public key lookup failed.');
              }
            }
          }, 150);
        };

        address.addEventListener('input', update);
        address.addEventListener('change', update);
        update();
      });

      once('symbol-atomic-swap-aggregate-deadline', '[data-symbol-aggregate-deadline-settings]', context).forEach(bindAggregateDeadlineDefaults);
    },
  };
})(Drupal, once);
