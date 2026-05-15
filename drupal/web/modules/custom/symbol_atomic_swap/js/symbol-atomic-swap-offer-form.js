(function (Drupal, once) {
  'use strict';

  function normalizePublicKey(value) {
    return String(value || '').replace(/\s+/g, '').toUpperCase();
  }

  Drupal.behaviors.symbolAtomicSwapOfferForm = {
    attach(context) {
      once('symbol-atomic-swap-maker-address', '[data-symbol-maker-address-form]', context).forEach((form) => {
        const network = form.querySelector('[data-symbol-maker-network]');
        const publicKey = form.querySelector('[data-symbol-maker-public-key]');
        const address = form.querySelector('[data-symbol-maker-recipient-address]');
        if (!network || !publicKey || !address) {
          return;
        }

        let timer = 0;
        const update = () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(async () => {
            const key = normalizePublicKey(publicKey.value);
            if (!/^[0-9A-F]{64}$/.test(key)) {
              address.value = '';
              return;
            }

            const url = Drupal.url(`symbol-atomic-swap/address/${network.value}/${key}`);
            try {
              const response = await fetch(url, {
                headers: { accept: 'application/json' },
                credentials: 'same-origin',
              });
              if (!response.ok) {
                address.value = '';
                return;
              }
              const result = await response.json();
              address.value = result.address || '';
            }
            catch (error) {
              address.value = '';
            }
          }, 150);
        };

        publicKey.addEventListener('input', update);
        publicKey.addEventListener('change', update);
        network.addEventListener('change', update);
        update();
      });
    },
  };
})(Drupal, once);
