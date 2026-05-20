(function (Drupal, drupalSettings, once) {
  'use strict';

  function setStatus(form, message, state) {
    const container = form.querySelector('[data-symbol-login-status]');
    const label = form.querySelector('[data-symbol-login-message]');
    if (container) {
      container.setAttribute('data-symbol-login-status', state);
    }
    if (label) {
      label.textContent = message;
    }
  }

  function toHex(value) {
    if (!value) {
      return '';
    }
    if (typeof value === 'string') {
      return value.startsWith('0x') ? value.slice(2) : value;
    }
    if (value instanceof Uint8Array || Array.isArray(value)) {
      return Array.from(value, function (byte) {
        return Number(byte).toString(16).padStart(2, '0');
      }).join('');
    }
    return '';
  }

  async function readJsonResponse(response, fallbackMessage, options) {
    const allowHtmlRedirect = Boolean(options && options.allowHtmlRedirect);
    const text = await response.text();
    let body = null;
    if (text) {
      try {
        body = JSON.parse(text);
      }
      catch (error) {
        if (allowHtmlRedirect && response.ok && /^\s*<!DOCTYPE\s+html/i.test(text)) {
          return {
            ok: true,
            redirect: response.redirected && response.url ? response.url : '/user',
          };
        }
        const summary = text.replace(/\s+/g, ' ').trim().slice(0, 180);
        throw new Error(fallbackMessage + ' HTTP ' + response.status + ': ' + (summary || 'non-JSON response'));
      }
    }
    if (!response.ok) {
      throw new Error((body && (body.error || body.message)) || fallbackMessage);
    }
    if (!body) {
      throw new Error(fallbackMessage + ': empty response');
    }
    return body;
  }

  async function requestChallenge() {
    const response = await fetch(drupalSettings.symbolLogin.challengeUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
      },
    });
    return readJsonResponse(response, 'チャレンジ発行に失敗しました。');
  }

  async function requestSssChallenge(address, publicKey) {
    const response = await fetch(drupalSettings.symbolLogin.sssChallengeUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        address: address,
        publicKey: publicKey,
      }),
    });
    return readJsonResponse(response, 'SSS署名用ペイロードの生成に失敗しました。');
  }

  function providerLabel(providerType) {
    return providerType === 'alice' ? 'aLice' : 'SSS';
  }

  function resolveProvider(providerType) {
    if (providerType === 'alice') {
      return window.alice || null;
    }
    return window.SSS || window.sss || null;
  }

  function providerAddress(provider) {
    return provider.activeAddress || provider.address || '';
  }

  function providerPublicKey(provider) {
    return toHex(provider.activePublicKey || provider.activePublicAccountPublicKey || provider.publicKey || '');
  }

  async function signSssPayload() {
    const provider = resolveProvider('sss');
    if (!provider) {
      throw new Error('SSSが見つかりません。ウォレット拡張を有効にしてください。');
    }
    if (typeof provider.setTransactionByPayload !== 'function' || typeof provider.requestSign !== 'function') {
      throw new Error('このSSS APIはトランザクションペイロード署名に対応していません。');
    }

    const address = providerAddress(provider);
    const publicKey = providerPublicKey(provider);
    if (!address || !publicKey) {
      throw new Error('SSSのアクティブアカウント address/publicKey を取得できません。');
    }

    const challenge = await requestSssChallenge(address, publicKey);
    const unsignedPayload = toHex(challenge.unsignedPayload).toUpperCase();
    if (!unsignedPayload) {
      throw new Error('SSS署名用ペイロードが空です。');
    }

    provider.setTransactionByPayload(unsignedPayload);
    const signed = await provider.requestSign();
    const payload = toHex(signed?.payload || signed?.signedPayload || signed).toUpperCase();
    if (!payload) {
      throw new Error('SSS署名結果にpayloadが含まれていません。');
    }

    return {
      challenge: challenge,
      payload: payload,
    };
  }

  async function signMessage(message, providerType) {
    const provider = resolveProvider(providerType);
    const label = providerLabel(providerType);
    if (!provider) {
      throw new Error(label + 'が見つかりません。ウォレット拡張を有効にしてください。');
    }

    let result = null;
    if (typeof provider.signMessage === 'function') {
      result = await provider.signMessage(message);
    }
    else if (typeof provider.setMessage === 'function' && typeof provider.requestSignMessage === 'function') {
      provider.setMessage(message);
      result = await provider.requestSignMessage();
    }
    else if (typeof provider.requestSignMessage === 'function') {
      result = await provider.requestSignMessage(message);
    }
    else {
      throw new Error(label + 'のメッセージ署名APIが見つかりません。トランザクション署名画面ではログインできません。');
    }

    const address = result?.address || provider.activeAddress || provider.address || '';
    const publicKey = result?.publicKey || provider.activePublicKey || provider.publicKey || '';
    const signature = result?.signature || result?.payload || result?.signedMessage || '';

    if (!address || !publicKey || !signature) {
      throw new Error('署名結果にaddress/publicKey/signatureが含まれていません。');
    }

    return {
      address: address,
      publicKey: toHex(publicKey),
      signature: toHex(signature),
    };
  }

  async function verify(challenge, signed) {
    const response = await fetch(drupalSettings.symbolLogin.verifyUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        challengeId: challenge.id,
        address: signed.address,
        publicKey: signed.publicKey,
        signature: signed.signature,
      }),
    });

    const body = await readJsonResponse(response, 'Symbolログインに失敗しました。', { allowHtmlRedirect: true });
    if (!body.ok) {
      throw new Error(body.error || 'Symbolログインに失敗しました。');
    }
    return body;
  }

  async function verifySss(challenge, payload) {
    const response = await fetch(drupalSettings.symbolLogin.sssVerifyUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        challengeId: challenge.id,
        payload: payload,
      }),
    });

    const body = await readJsonResponse(response, 'SSSログインに失敗しました。', { allowHtmlRedirect: true });
    if (!body.ok) {
      throw new Error(body.error || 'SSSログインに失敗しました。');
    }
    return body;
  }

  Drupal.behaviors.symbolLogin = {
    attach(context) {
      once('symbol-login', '[data-symbol-login-trigger]', context).forEach(function (button) {
        const form = button.closest('form');
        const providerType = button.getAttribute('data-symbol-login-provider') || drupalSettings.symbolLogin.provider || 'sss';
        const label = providerLabel(providerType);
        button.addEventListener('click', async function (event) {
          event.preventDefault();
          button.disabled = true;
          try {
            setStatus(form, 'チャレンジを発行しています。', 'pending');
            let result = null;
            if (providerType === 'sss') {
              setStatus(form, 'SSS署名用ペイロードを生成しています。', 'pending');
              const signedPayload = await signSssPayload();
              setStatus(form, 'SSS署名ペイロードを検証しています。', 'pending');
              result = await verifySss(signedPayload.challenge, signedPayload.payload);
            }
            else {
              const challenge = await requestChallenge();
              setStatus(form, label + 'で署名してください。', 'pending');
              const signed = await signMessage(challenge.message, providerType);
              setStatus(form, '署名を検証しています。', 'pending');
              result = await verify(challenge, signed);
            }
            window.location.assign(result.redirect || '/user');
          }
          catch (error) {
            setStatus(form, error.message || 'Symbolログインに失敗しました。', 'error');
            button.disabled = false;
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
