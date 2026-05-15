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
                status.textContent = result.publicKey ? Drupal.t('Account verified.') : '';
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
        update();
      });

      once('symbol-atomic-swap-taker-address', '[data-symbol-taker-address-form]', context).forEach((form) => {
        const network = form.getAttribute('data-symbol-taker-network') || 'testnet';
        const address = form.querySelector('[data-symbol-taker-address]');
        const publicKey = form.querySelector('[data-symbol-taker-public-key]');
        const status = form.querySelector('[data-symbol-taker-address-status]');
        if (!address || !publicKey) {
          return;
        }

        let timer = 0;
        const update = () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(async () => {
            const rawAddress = normalizeAddress(address.value);
            const expectedPrefix = network === 'mainnet' ? 'N' : 'T';
            publicKey.value = '';
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
              publicKey.value = result.publicKey || '';
              if (status) {
                status.textContent = result.publicKey ? Drupal.t('Public key resolved.') : '';
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
    },
  };
})(Drupal, once);
