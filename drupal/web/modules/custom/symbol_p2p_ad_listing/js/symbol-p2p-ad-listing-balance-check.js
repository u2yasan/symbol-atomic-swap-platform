(function (Drupal, once) {
  'use strict';

  let csrfTokenPromise = null;

  function csrfToken() {
    if (!csrfTokenPromise) {
      csrfTokenPromise = fetch(Drupal.url('session/token'), {
        credentials: 'same-origin',
        headers: { accept: 'text/plain' },
      }).then((response) => {
        if (!response.ok) {
          throw new Error('csrf_token_failed');
        }
        return response.text();
      });
    }
    return csrfTokenPromise;
  }

  function setStatus(container, message, state) {
    const status = container.querySelector('[data-symbol-p2p-balance-check-status]');
    if (!status) {
      return;
    }
    status.textContent = message;
    status.classList.toggle('messages', Boolean(message) && state !== 'checking');
    status.classList.toggle('messages--warning', state === 'insufficient');
    status.classList.toggle('messages--error', state === 'error');
    status.classList.toggle('symbol-p2p-balance-insufficient', state === 'insufficient');
  }

  function hideTakeListing(container) {
    const root = container.closest('[data-symbol-p2p-listing-view]') || document;
    root.querySelectorAll('[data-symbol-p2p-take-listing]').forEach((link) => {
      link.hidden = true;
      link.style.display = 'none';
      link.setAttribute('aria-hidden', 'true');
      link.setAttribute('tabindex', '-1');
    });
    root.querySelectorAll('[data-symbol-p2p-back-on-insufficient]').forEach((link) => {
      link.hidden = false;
      link.style.display = '';
      link.setAttribute('aria-hidden', 'false');
      link.removeAttribute('tabindex');
    });
  }

  async function checkBalance(container) {
    const url = container.getAttribute('data-symbol-p2p-balance-check-url');
    if (!url) {
      return;
    }

    setStatus(container, Drupal.t('Checking...'), 'checking');
    try {
      const token = await csrfToken();
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          accept: 'application/json',
          'X-CSRF-Token': token,
        },
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        setStatus(container, result.message || Drupal.t('Balance check failed.'), 'error');
        return;
      }
      if (result.sufficient) {
        setStatus(container, '', 'sufficient');
        return;
      }
      setStatus(container, Drupal.t('Insufficient'), 'insufficient');
      hideTakeListing(container);
    }
    catch (error) {
      setStatus(container, Drupal.t('Balance check failed.'), 'error');
    }
  }

  Drupal.behaviors.symbolP2pAdListingBalanceCheck = {
    attach(context) {
      once('symbol-p2p-ad-listing-balance-check', '[data-symbol-p2p-balance-check]', context).forEach((container) => {
        checkBalance(container);
      });
    },
  };
})(Drupal, once);
