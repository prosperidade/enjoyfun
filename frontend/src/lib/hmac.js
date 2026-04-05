/**
 * EnjoyFun — HMAC-SHA256 signing for offline payloads (C07)
 *
 * Uses the Web Crypto API (crypto.subtle) so no external dependencies are needed.
 * The signing key is derived from the JWT access token via HKDF so the raw token
 * is never used directly as key material.
 */

const HMAC_ALGO = 'HMAC';
const HASH_ALGO = 'SHA-256';
const HKDF_INFO = 'enjoyfun-offline-hmac-v1';

const encoder = new TextEncoder();

/**
 * Derive a CryptoKey suitable for HMAC-SHA256 from the raw JWT string.
 * We import the JWT bytes as HKDF key material and derive a fixed-length
 * HMAC key so the actual token never leaks into signature output.
 */
async function deriveHmacKey(jwtToken) {
  if (!jwtToken || typeof jwtToken !== 'string') {
    throw new Error('HMAC key derivation requires a valid JWT token.');
  }

  const rawKeyMaterial = await crypto.subtle.importKey(
    'raw',
    encoder.encode(jwtToken),
    'HKDF',
    false,
    ['deriveKey'],
  );

  return crypto.subtle.deriveKey(
    {
      name: 'HKDF',
      hash: HASH_ALGO,
      salt: encoder.encode('enjoyfun'),
      info: encoder.encode(HKDF_INFO),
    },
    rawKeyMaterial,
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
 * @param {string} jwtToken - The current access token (used for key derivation).
 * @returns {Promise<string>} Hex-encoded HMAC signature.
 */
export async function signPayload(payload, jwtToken) {
  const key = await deriveHmacKey(jwtToken);
  const data = encoder.encode(canonicalize(payload));
  const signature = await crypto.subtle.sign(HMAC_ALGO, key, data);
  return bufferToHex(signature);
}

/**
 * Verify a payload's HMAC-SHA256 signature.
 *
 * @param {object} payload   - The offline sale/transaction payload.
 * @param {string} signature - The hex-encoded HMAC to verify.
 * @param {string} jwtToken  - The access token that was used for signing.
 * @returns {Promise<boolean>}
 */
export async function verifyPayload(payload, signature, jwtToken) {
  const key = await deriveHmacKey(jwtToken);
  const data = encoder.encode(canonicalize(payload));
  const sigBuffer = new Uint8Array(
    signature.match(/.{1,2}/g).map((byte) => parseInt(byte, 16)),
  );
  return crypto.subtle.verify(HMAC_ALGO, key, sigBuffer, data);
}
