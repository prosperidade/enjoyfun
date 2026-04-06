/**
 * EnjoyFun — HMAC-SHA256 signing for offline payloads (C07)
 *
 * Uses the Web Crypto API (crypto.subtle) so no external dependencies are needed.
 *
 * The signing key is provided by the backend at login/refresh as a hex-encoded
 * HKDF-derived key (from JWT_SECRET). This ensures both sides use the same key
 * material for HMAC computation.
 */

import { getHmacKey } from './session';

const HMAC_ALGO = 'HMAC';
const HASH_ALGO = 'SHA-256';

const encoder = new TextEncoder();

/**
 * Convert a hex string to an ArrayBuffer.
 */
function hexToBuffer(hex) {
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < hex.length; i += 2) {
    bytes[i / 2] = parseInt(hex.substring(i, i + 2), 16);
  }
  return bytes.buffer;
}

/**
 * Import the server-provided HMAC key (hex string) as a CryptoKey.
 *
 * The key is already derived via HKDF on the backend from JWT_SECRET,
 * so we import it directly — no client-side derivation needed.
 */
async function importHmacKey(hmacKeyHex) {
  if (!hmacKeyHex || typeof hmacKeyHex !== 'string' || hmacKeyHex.length === 0) {
    throw new Error('HMAC key not available in session. Re-login required.');
  }

  return crypto.subtle.importKey(
    'raw',
    hexToBuffer(hmacKeyHex),
    { name: HMAC_ALGO, hash: HASH_ALGO },
    false,
    ['sign', 'verify'],
  );
}

/**
 * Convert an ArrayBuffer to a hex string.
 */
function bufferToHex(buffer) {
  return Array.from(new Uint8Array(buffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Produce a canonical JSON string for signing.
 * Keys are sorted to guarantee deterministic output.
 */
function canonicalize(payload) {
  return JSON.stringify(payload, Object.keys(payload).sort());
}

/**
 * Sign a payload object with HMAC-SHA256.
 *
 * @param {object} payload  - The offline sale/transaction payload.
 * @param {string} [hmacKeyHex] - Optional hex-encoded HMAC key. If omitted,
 *                                reads from session storage automatically.
 * @returns {Promise<string>} Hex-encoded HMAC signature.
 */
export async function signPayload(payload, hmacKeyHex) {
  const keyHex = hmacKeyHex || getHmacKey();
  if (!keyHex) {
    console.warn('[HMAC] hmac_key not available in session — signing skipped.');
    return null;
  }
  const key = await importHmacKey(keyHex);
  const data = encoder.encode(canonicalize(payload));
  const signature = await crypto.subtle.sign(HMAC_ALGO, key, data);
  return bufferToHex(signature);
}

/**
 * Verify a payload's HMAC-SHA256 signature.
 *
 * @param {object} payload   - The offline sale/transaction payload.
 * @param {string} signature - The hex-encoded HMAC to verify.
 * @param {string} [hmacKeyHex] - Optional hex-encoded HMAC key. If omitted,
 *                                reads from session storage automatically.
 * @returns {Promise<boolean>}
 */
export async function verifyPayload(payload, signature, hmacKeyHex) {
  const keyHex = hmacKeyHex || getHmacKey();
  if (!keyHex) {
    console.warn('[HMAC] hmac_key not available in session — verification skipped.');
    return false;
  }
  const key = await importHmacKey(keyHex);
  const data = encoder.encode(canonicalize(payload));
  const sigBuffer = new Uint8Array(
    signature.match(/.{1,2}/g).map((byte) => parseInt(byte, 16)),
  );
  return crypto.subtle.verify(HMAC_ALGO, key, sigBuffer, data);
}
