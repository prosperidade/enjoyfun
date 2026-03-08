<?php
/**
 * EnjoyFun — JWT Helper (HS256)
 *
 * Implementação nativa sem dependência da extensão OpenSSL
 * Ideal para hospedagem em ambiente Windows restrito
 */
class JWT
{
    public static function encode(array $payload, int $ttlSeconds = 3600): string
    {
        $now     = time();
        $payload = array_merge($payload, ['iat' => $now, 'exp' => $now + $ttlSeconds]);

        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body   = self::b64url(json_encode($payload));

        $secret = self::getSecretKey();
        $signature = hash_hmac('sha256', "$header.$body", $secret, true);

        return "$header.$body." . self::b64url($signature);
    }

    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log("❌ [JWT] Token mal formado (não tem 3 partes)");
            return null;
        }

        [$header, $body, $sig] = $parts;

        // Verifica algoritmo
        $headerData = json_decode(self::b64urlDecode($header), true);
        if (($headerData['alg'] ?? '') !== 'HS256') {
            error_log("❌ [JWT] Algoritmo inválido: " . ($headerData['alg'] ?? 'ausente'));
            return null;
        }

        try {
            $secret = self::getSecretKey();
            
            // Recalcula a assinatura
            $expectedSig = hash_hmac('sha256', "$header.$body", $secret, true);

            // Comparação segura contra tempo
            if (!hash_equals($expectedSig, self::b64urlDecode($sig))) {
                error_log("❌ [JWT] Assinatura inválida (HS256 recusado)!");
                return null;
            }

            $payload = json_decode(self::b64urlDecode($body), true);
            if (!$payload) {
                error_log("❌ [JWT] Falha ao decodificar payload JSON.");
                return null;
            }
            if ($payload['exp'] < time()) {
                error_log("❌ [JWT] Token expirado! Exp: " . date('Y-m-d H:i:s', $payload['exp']));
                return null;
            }

            return $payload;
        } catch (Throwable $e) {
            error_log("❌ [JWT] Exception no decode: " . $e->getMessage());
            return null;
        }
    }

    public static function fromHeader(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return $m[1];
        return null;
    }

    public static function getSecretKey(): string
    {
        $secret = getenv('JWT_SECRET');
        if (!$secret) {
            throw new Exception("JWT_SECRET is required but not set in the environment.");
        }
        return $secret;
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