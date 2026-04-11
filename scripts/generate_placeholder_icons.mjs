// Gera PNGs solidos minimos sem dependencias.
// PNG = 8 chunks: signature + IHDR + IDAT + IEND. Cor solida com texto via canvas? Nao temos.
// Estrategia: produzir PNG solido (cor accent) usando builder manual.
import { writeFileSync, mkdirSync } from 'node:fs';
import { deflateSync } from 'node:zlib';
import { dirname } from 'node:path';

function crc32(buf) {
  let table = crc32.table;
  if (!table) {
    table = new Uint32Array(256);
    for (let i = 0; i < 256; i++) {
      let c = i;
      for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
      table[i] = c >>> 0;
    }
    crc32.table = table;
  }
  let crc = 0xffffffff;
  for (let i = 0; i < buf.length; i++) crc = (table[(crc ^ buf[i]) & 0xff] ^ (crc >>> 8)) >>> 0;
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length, 0);
  const typeBuf = Buffer.from(type, 'ascii');
  const crcBuf = Buffer.alloc(4);
  crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
  return Buffer.concat([len, typeBuf, data, crcBuf]);
}

function makeSolidPng(size, r, g, b) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8;   // bit depth
  ihdr[9] = 2;   // color type RGB
  ihdr[10] = 0;
  ihdr[11] = 0;
  ihdr[12] = 0;

  // raw scanlines: filter byte 0 + RGB pixels
  const rowLen = 1 + size * 3;
  const raw = Buffer.alloc(rowLen * size);
  for (let y = 0; y < size; y++) {
    raw[y * rowLen] = 0;
    for (let x = 0; x < size; x++) {
      const off = y * rowLen + 1 + x * 3;
      raw[off] = r;
      raw[off + 1] = g;
      raw[off + 2] = b;
    }
  }
  const idatData = deflateSync(raw);
  return Buffer.concat([sig, chunk('IHDR', ihdr), chunk('IDAT', idatData), chunk('IEND', Buffer.alloc(0))]);
}

const ACCENT = [233, 69, 96];   // #E94560
const DARK = [10, 10, 10];      // #0A0A0A

const targets = [
  // Web PWA
  ['frontend/public/icon-192.png', 192, ACCENT],
  ['frontend/public/icon-512.png', 512, ACCENT],
  // Mobile Expo
  ['enjoyfun-app/assets/icon.png', 1024, ACCENT],
  ['enjoyfun-app/assets/adaptive-icon.png', 1024, ACCENT],
  ['enjoyfun-app/assets/splash.png', 1242, DARK],
  ['enjoyfun-app/assets/favicon.png', 48, ACCENT],
];

for (const [path, size, color] of targets) {
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, makeSolidPng(size, ...color));
  console.log('wrote', path, size + 'x' + size);
}
