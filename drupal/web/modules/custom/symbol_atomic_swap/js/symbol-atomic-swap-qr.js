(function (Drupal, once) {
  'use strict';

  const alignmentCenters = [
    null, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
    [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
    [6, 30, 54], [6, 32, 58], [6, 34, 62], [6, 26, 46, 66],
    [6, 26, 48, 70], [6, 26, 50, 74], [6, 30, 54, 78],
    [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90],
    [6, 28, 50, 72, 94], [6, 26, 50, 74, 98],
    [6, 30, 54, 78, 102], [6, 28, 54, 80, 106],
    [6, 32, 58, 84, 110], [6, 30, 58, 86, 114],
    [6, 34, 62, 90, 118], [6, 26, 50, 74, 98, 122],
    [6, 30, 54, 78, 102, 126], [6, 26, 52, 78, 104, 130],
    [6, 30, 56, 82, 108, 134], [6, 34, 60, 86, 112, 138],
    [6, 30, 58, 86, 114, 142], [6, 34, 62, 90, 118, 146],
    [6, 30, 54, 78, 102, 126, 150],
    [6, 24, 50, 76, 102, 128, 154],
    [6, 28, 54, 80, 106, 132, 158],
    [6, 32, 58, 84, 110, 136, 162],
    [6, 26, 54, 82, 110, 138, 166],
    [6, 30, 58, 86, 114, 142, 170],
  ];

  const rsBlocksL = [
    null, [[1, 26, 19]], [[1, 44, 34]], [[1, 70, 55]], [[1, 100, 80]],
    [[1, 134, 108]], [[2, 86, 68]], [[2, 98, 78]], [[2, 121, 97]],
    [[2, 146, 116]], [[2, 86, 68], [2, 87, 69]], [[4, 101, 81]],
    [[2, 116, 92], [2, 117, 93]], [[4, 133, 107]],
    [[3, 145, 115], [1, 146, 116]], [[5, 109, 87], [1, 110, 88]],
    [[5, 122, 98], [1, 123, 99]], [[1, 135, 107], [5, 136, 108]],
    [[5, 150, 120], [1, 151, 121]], [[3, 141, 113], [4, 142, 114]],
    [[3, 135, 107], [5, 136, 108]], [[4, 144, 116], [4, 145, 117]],
    [[2, 139, 111], [7, 140, 112]], [[4, 151, 121], [5, 152, 122]],
    [[6, 147, 117], [4, 148, 118]], [[8, 132, 106], [4, 133, 107]],
    [[10, 142, 114], [2, 143, 115]], [[8, 152, 122], [4, 153, 123]],
    [[3, 147, 117], [10, 148, 118]], [[7, 146, 116], [7, 147, 117]],
    [[5, 145, 115], [10, 146, 116]], [[13, 145, 115], [3, 146, 116]],
    [[17, 145, 115]], [[17, 145, 115], [1, 146, 116]],
    [[13, 145, 115], [6, 146, 116]], [[12, 151, 121], [7, 152, 122]],
    [[6, 151, 121], [14, 152, 122]], [[17, 152, 122], [4, 153, 123]],
    [[4, 152, 122], [18, 153, 123]], [[20, 147, 117], [4, 148, 118]],
    [[19, 148, 118], [6, 149, 119]],
  ];

  const glog = new Array(256);
  const gexp = new Array(512);
  let x = 1;
  for (let i = 0; i < 255; i++) {
    gexp[i] = x;
    glog[x] = i;
    x <<= 1;
    if (x & 0x100) {
      x ^= 0x11d;
    }
  }
  for (let i = 255; i < 512; i++) {
    gexp[i] = gexp[i - 255];
  }

  function gmul(a, b) {
    return a === 0 || b === 0 ? 0 : gexp[glog[a] + glog[b]];
  }

  function generatorPoly(degree) {
    let poly = [1];
    for (let i = 0; i < degree; i++) {
      const next = new Array(poly.length + 1).fill(0);
      for (let j = 0; j < poly.length; j++) {
        next[j] ^= poly[j];
        next[j + 1] ^= gmul(poly[j], gexp[i]);
      }
      poly = next;
    }
    return poly;
  }

  function reedSolomon(data, degree) {
    const gen = generatorPoly(degree);
    const result = data.concat(new Array(degree).fill(0));
    for (let i = 0; i < data.length; i++) {
      const coef = result[i];
      if (coef === 0) {
        continue;
      }
      for (let j = 0; j < gen.length; j++) {
        result[i + j] ^= gmul(gen[j], coef);
      }
    }
    return result.slice(result.length - degree);
  }

  class BitBuffer {
    constructor() {
      this.bits = [];
    }
    put(value, length) {
      for (let i = length - 1; i >= 0; i--) {
        this.bits.push(((value >>> i) & 1) === 1);
      }
    }
    toBytes() {
      const bytes = [];
      for (let i = 0; i < this.bits.length; i += 8) {
        let value = 0;
        for (let j = 0; j < 8; j++) {
          value = (value << 1) | (this.bits[i + j] ? 1 : 0);
        }
        bytes.push(value);
      }
      return bytes;
    }
  }

  function dataCapacity(version) {
    return rsBlocksL[version].reduce((sum, group) => sum + group[0] * group[2], 0);
  }

  function chooseVersion(byteLength) {
    for (let version = 1; version <= 40; version++) {
      const ccBits = version < 10 ? 8 : 16;
      if (4 + ccBits + byteLength * 8 <= dataCapacity(version) * 8) {
        return version;
      }
    }
    throw new Error('QR payload exceeds version 40-L capacity.');
  }

  function makeDataCodewords(bytes, version) {
    const capacity = dataCapacity(version);
    const buffer = new BitBuffer();
    buffer.put(4, 4);
    buffer.put(bytes.length, version < 10 ? 8 : 16);
    bytes.forEach((byte) => buffer.put(byte, 8));
    const remaining = capacity * 8 - buffer.bits.length;
    buffer.put(0, Math.min(4, remaining));
    while (buffer.bits.length % 8 !== 0) {
      buffer.put(0, 1);
    }
    const data = buffer.toBytes();
    for (let pad = 0; data.length < capacity; pad++) {
      data.push(pad % 2 === 0 ? 0xec : 0x11);
    }
    return data;
  }

  function interleave(data, version) {
    const blocks = [];
    let offset = 0;
    rsBlocksL[version].forEach((group) => {
      const [count, totalCount, dataCount] = group;
      for (let i = 0; i < count; i++) {
        const dc = data.slice(offset, offset + dataCount);
        offset += dataCount;
        blocks.push({ data: dc, ec: reedSolomon(dc, totalCount - dataCount) });
      }
    });

    const result = [];
    const maxData = Math.max(...blocks.map((block) => block.data.length));
    const maxEc = Math.max(...blocks.map((block) => block.ec.length));
    for (let i = 0; i < maxData; i++) {
      blocks.forEach((block) => {
        if (i < block.data.length) {
          result.push(block.data[i]);
        }
      });
    }
    for (let i = 0; i < maxEc; i++) {
      blocks.forEach((block) => {
        if (i < block.ec.length) {
          result.push(block.ec[i]);
        }
      });
    }
    return result;
  }

  function createMatrix(version) {
    const size = version * 4 + 17;
    const modules = Array.from({ length: size }, () => new Array(size).fill(false));
    const reserved = Array.from({ length: size }, () => new Array(size).fill(false));

    function set(row, col, dark, reserve = true) {
      if (row < 0 || col < 0 || row >= size || col >= size) {
        return;
      }
      modules[row][col] = dark;
      if (reserve) {
        reserved[row][col] = true;
      }
    }

    function finder(row, col) {
      for (let r = -1; r <= 7; r++) {
        for (let c = -1; c <= 7; c++) {
          const rr = row + r;
          const cc = col + c;
          if (rr < 0 || cc < 0 || rr >= size || cc >= size) {
            continue;
          }
          const dark = r >= 0 && r <= 6 && c >= 0 && c <= 6 &&
            (r === 0 || r === 6 || c === 0 || c === 6 || (r >= 2 && r <= 4 && c >= 2 && c <= 4));
          set(rr, cc, dark);
        }
      }
    }

    finder(0, 0);
    finder(0, size - 7);
    finder(size - 7, 0);

    for (let i = 8; i < size - 8; i++) {
      set(6, i, i % 2 === 0);
      set(i, 6, i % 2 === 0);
    }

    alignmentCenters[version].forEach((row) => {
      alignmentCenters[version].forEach((col) => {
        if (reserved[row][col]) {
          return;
        }
        for (let r = -2; r <= 2; r++) {
          for (let c = -2; c <= 2; c++) {
            set(row + r, col + c, Math.max(Math.abs(r), Math.abs(c)) !== 1);
          }
        }
      });
    });

    set(4 * version + 9, 8, true);
    for (let i = 0; i < 9; i++) {
      if (i !== 6) {
        reserved[8][i] = true;
        reserved[i][8] = true;
      }
    }
    for (let i = 0; i < 8; i++) {
      reserved[8][size - 1 - i] = true;
      reserved[size - 1 - i][8] = true;
    }

    if (version >= 7) {
      const bits = versionBits(version);
      for (let i = 0; i < 18; i++) {
        const dark = ((bits >>> i) & 1) === 1;
        set(Math.floor(i / 3), size - 11 + (i % 3), dark);
        set(size - 11 + (i % 3), Math.floor(i / 3), dark);
      }
    }

    return { modules, reserved, size };
  }

  function versionBits(version) {
    let data = version << 12;
    for (let i = 17; i >= 12; i--) {
      if (((data >>> i) & 1) !== 0) {
        data ^= 0x1f25 << (i - 12);
      }
    }
    return (version << 12) | data;
  }

  function formatBits(mask) {
    let data = (1 << 3) | mask;
    let bits = data << 10;
    for (let i = 14; i >= 10; i--) {
      if (((bits >>> i) & 1) !== 0) {
        bits ^= 0x537 << (i - 10);
      }
    }
    return ((data << 10) | bits) ^ 0x5412;
  }

  function mask(maskId, row, col) {
    switch (maskId) {
      case 0: return (row + col) % 2 === 0;
      case 1: return row % 2 === 0;
      case 2: return col % 3 === 0;
      case 3: return (row + col) % 3 === 0;
      case 4: return (Math.floor(row / 2) + Math.floor(col / 3)) % 2 === 0;
      case 5: return ((row * col) % 2) + ((row * col) % 3) === 0;
      case 6: return (((row * col) % 2) + ((row * col) % 3)) % 2 === 0;
      default: return (((row + col) % 2) + ((row * col) % 3)) % 2 === 0;
    }
  }

  function placeData(base, codewords, maskId) {
    const modules = base.modules.map((row) => row.slice());
    let bitIndex = 0;
    let upward = true;
    for (let col = base.size - 1; col > 0; col -= 2) {
      if (col === 6) {
        col--;
      }
      for (let i = 0; i < base.size; i++) {
        const row = upward ? base.size - 1 - i : i;
        for (let c = 0; c < 2; c++) {
          const cc = col - c;
          if (base.reserved[row][cc]) {
            continue;
          }
          const byte = codewords[Math.floor(bitIndex / 8)] || 0;
          let dark = ((byte >>> (7 - (bitIndex % 8))) & 1) === 1;
          if (mask(maskId, row, cc)) {
            dark = !dark;
          }
          modules[row][cc] = dark;
          bitIndex++;
        }
      }
      upward = !upward;
    }
    placeFormat(modules, base.size, maskId);
    return modules;
  }

  function placeFormat(modules, size, maskId) {
    const bits = formatBits(maskId);
    for (let i = 0; i < 15; i++) {
      const dark = ((bits >>> i) & 1) === 1;
      if (i < 6) {
        modules[8][i] = dark;
      }
      else if (i < 8) {
        modules[8][i + 1] = dark;
      }
      else {
        modules[8][size - 15 + i] = dark;
      }

      if (i < 8) {
        modules[size - i - 1][8] = dark;
      }
      else if (i < 9) {
        modules[15 - i][8] = dark;
      }
      else {
        modules[14 - i][8] = dark;
      }
    }
  }

  function penalty(modules) {
    const size = modules.length;
    let score = 0;
    for (let axis = 0; axis < 2; axis++) {
      for (let i = 0; i < size; i++) {
        let runColor = false;
        let runLength = 0;
        for (let j = 0; j < size; j++) {
          const dark = axis === 0 ? modules[i][j] : modules[j][i];
          if (j === 0 || dark !== runColor) {
            if (runLength >= 5) {
              score += 3 + runLength - 5;
            }
            runColor = dark;
            runLength = 1;
          }
          else {
            runLength++;
          }
        }
        if (runLength >= 5) {
          score += 3 + runLength - 5;
        }
      }
    }
    for (let r = 0; r < size - 1; r++) {
      for (let c = 0; c < size - 1; c++) {
        const dark = modules[r][c];
        if (modules[r + 1][c] === dark && modules[r][c + 1] === dark && modules[r + 1][c + 1] === dark) {
          score += 3;
        }
      }
    }
    const pattern = [true, false, true, true, true, false, true, false, false, false, false];
    for (let axis = 0; axis < 2; axis++) {
      for (let i = 0; i < size; i++) {
        for (let j = 0; j <= size - 11; j++) {
          let match = true;
          for (let k = 0; k < 11; k++) {
            if ((axis === 0 ? modules[i][j + k] : modules[j + k][i]) !== pattern[k]) {
              match = false;
              break;
            }
          }
          if (match) {
            score += 40;
          }
        }
      }
    }
    let darkCount = 0;
    modules.forEach((row) => row.forEach((dark) => {
      if (dark) {
        darkCount++;
      }
    }));
    score += Math.floor(Math.abs((darkCount * 100 / (size * size)) - 50) / 5) * 10;
    return score;
  }

  function encode(text) {
    const bytes = Array.from(new TextEncoder().encode(text));
    const version = chooseVersion(bytes.length);
    const codewords = interleave(makeDataCodewords(bytes, version), version);
    const base = createMatrix(version);
    let best = null;
    for (let maskId = 0; maskId < 8; maskId++) {
      const modules = placeData(base, codewords, maskId);
      const score = penalty(modules);
      if (!best || score < best.score) {
        best = { modules, score };
      }
    }
    return best.modules;
  }

  function draw(container) {
    const payload = container.getAttribute('data-qr-payload');
    if (!payload) {
      return;
    }

    try {
      const modules = encode(payload);
      const quiet = 4;
      const scale = Math.max(2, Math.floor(520 / (modules.length + quiet * 2)));
      const size = (modules.length + quiet * 2) * scale;
      const canvas = document.createElement('canvas');
      canvas.className = 'symbol-atomic-swap-qr__canvas';
      canvas.width = size;
      canvas.height = size;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, size, size);
      ctx.fillStyle = '#000';
      modules.forEach((row, r) => row.forEach((dark, c) => {
        if (dark) {
          ctx.fillRect((c + quiet) * scale, (r + quiet) * scale, scale, scale);
        }
      }));
      container.appendChild(canvas);
    }
    catch (error) {
      const message = document.createElement('p');
      message.className = 'symbol-atomic-swap-qr__error';
      message.textContent = error instanceof Error ? error.message : 'QR render failed.';
      container.appendChild(message);
    }
  }

  Drupal.behaviors.symbolAtomicSwapQr = {
    attach(context) {
      once('symbol-atomic-swap-qr', '.symbol-atomic-swap-qr[data-qr-payload]', context).forEach(draw);
    },
  };

  Drupal.behaviors.symbolAtomicSwapCopy = {
    attach(context) {
      once('symbol-atomic-swap-copy', '[data-symbol-copy]', context).forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          const value = button.getAttribute('data-symbol-copy') || '';
          if (!value || !navigator.clipboard) {
            return;
          }
          await navigator.clipboard.writeText(value);
          button.textContent = 'Copied';
          window.setTimeout(() => {
            button.textContent = 'Copy';
          }, 1500);
        });
      });
    },
  };
})(Drupal, once);
