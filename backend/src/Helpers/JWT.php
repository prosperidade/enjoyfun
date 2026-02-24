<?php
/**
 * EnjoyFun 2.0 — JWT Helper (pure PHP, no Composer)
 * Algorithm: HS256
 */
class JWT
{
    public static function encode(array $payload, string $secret, int $ttlSeconds = 3600): string
    {
        $now     = time();
        $payload = array_merge($payload, ['iat' => $now, 'exp' => $now + $ttlSeconds]);

        $header  = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = self::b64url(json_encode($payload));
        $sig     = self::b64url(hash_hmac('sha256', "$header.$body", $secret, true));

        return "$header.$body.$sig";
    }

    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $body, $sig] = $parts;
        $expected = self::b64url(hash_hmac('sha256', "$header.$body", $secret, true));

        if (!hash_equals($expected, $sig)) return null;

        $payload = json_decode(self::b64urlDecode($body), true);
        if (!$payload || $payload['exp'] < time()) return null;

        return $payload;
    }

    public static function fromHeader(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return $m[1];
        return null;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
