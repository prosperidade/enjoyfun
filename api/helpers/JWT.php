<?php
/**
 * EnjoyFun 2.0 — JWT Helper (no external library needed)
 * Implements HS256 signed tokens.
 */

class JWT
{
    // ---------- Encode --------------------------------------------------
    public static function encode(array $payload, string $secret, int $expiry = 0): string
    {
        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));

        $payload['iat'] = time();
        if ($expiry > 0) {
            $payload['exp'] = time() + $expiry;
        }

        $body = self::base64UrlEncode(json_encode($payload));
        $sig  = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$body", $secret, true)
        );

        return "$header.$body.$sig";
    }

    // ---------- Decode / Verify -----------------------------------------
    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid JWT structure.');
        }

        [$header, $body, $sig] = $parts;

        // Verify signature
        $expected = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$body", $secret, true)
        );
        if (!hash_equals($expected, $sig)) {
            throw new \RuntimeException('Invalid JWT signature.');
        }

        $payload = json_decode(self::base64UrlDecode($body), true);

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new \RuntimeException('JWT token has expired.');
        }

        return $payload;
    }

    // ---------- Helpers -------------------------------------------------
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    // ---------- Extract from Authorization header -----------------------
    public static function fromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }
}
