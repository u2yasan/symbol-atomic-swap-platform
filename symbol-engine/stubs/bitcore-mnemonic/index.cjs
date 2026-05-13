'use strict';

class DisabledMnemonic {
  static Words = {
    ENGLISH: [],
  };

  constructor() {
    throw new Error('Mnemonic/BIP39 usage is disabled in Symbol Engine. This service must never handle wallet secrets.');
  }
}

module.exports = DisabledMnemonic;
