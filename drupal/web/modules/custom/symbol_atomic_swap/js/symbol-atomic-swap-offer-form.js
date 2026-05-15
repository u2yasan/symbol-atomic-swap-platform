(function (Drupal, once) {
  'use strict';

  function normalizeAddress(value) {
    return String(value || '').replace(/\s+/g, '').toUpperCase();
  }

  Drupal.behaviors.symbolAtomicSwapOfferForm = {
    attach(context) {
      once('symbol-atomic-swap-maker-address', '[data-symbol-maker-address-form]', context).forEach((form) => {
        const network = form.querySelector('[data-symbol-maker-network]');
        const address = form.querySelector('[data-symbol-maker-address]');
        const publicKey = form.querySelector('[data-symbol-maker-public-key]');
        const status = form.querySelector('[data-symbol-maker-address-status]');
        if (!network || !address || !publicKey) {
          return;
        }

        let timer = 0;
        const update = () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(async () => {
            const rawAddress = normalizeAddress(address.value);
            const expectedPrefix = network.value === 'mainnet' ? 'N' : 'T';
            publicKey.value = '';
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
        network.addEventListener('change', update);
        update();
      });
    },
  };
})(Drupal, once);
