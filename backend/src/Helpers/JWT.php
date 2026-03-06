<?php
/**
 * EnjoyFun — JWT Helper (RS256)
 *
 * Migração de HS256 → RS256:
 * - Chave PRIVADA: apenas o servidor assina tokens
 * - Chave PÚBLICA: verifica tokens (pode ficar nos PDVs sem risco)
 */
class JWT
{
    public static function encode(array $payload, int $ttlSeconds = 3600): string
    {
        $now     = time();
        $payload = array_merge($payload, ['iat' => $now, 'exp' => $now + $ttlSeconds]);

        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body   = self::b64url(json_encode($payload));

        $privateKey = self::getPrivateKey();
        $key = openssl_pkey_get_private($privateKey);
        if (!$key) {
            throw new \RuntimeException('JWT: chave privada inválida.');
        }

        $signature = '';
        openssl_sign("$header.$body", $signature, $key, OPENSSL_ALGO_SHA256);

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
        if (($headerData['alg'] ?? '') !== 'RS256') {
            error_log("❌ [JWT] Algoritmo inválido: " . ($headerData['alg'] ?? 'ausente'));
            return null;
        }

        try {
            $publicKey = self::getPublicKey();
            $key = openssl_pkey_get_public($publicKey);
            if (!$key) {
                error_log("❌ [JWT] Falha ao ler chave pública.");
                return null;
            }

            $valid = openssl_verify(
                "$header.$body",
                self::b64urlDecode($sig),
                $key,
                OPENSSL_ALGO_SHA256
            );

            if ($valid !== 1) {
                error_log("❌ [JWT] Assinatura inválida! OPENSSL CODE: " . openssl_error_string());
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

    public static function getPrivateKey(): string
    {
        $path = getenv('JWT_PRIVATE_KEY_PATH') ?: __DIR__ . '/../../../secrets/jwt_private.pem';
        if (!file_exists($path)) {
            throw new \RuntimeException("JWT: chave privada não encontrada em '$path'.");
        }
        return file_get_contents($path);
    }

    public static function getPublicKey(): string
    {
        $path = getenv('JWT_PUBLIC_KEY_PATH') ?: __DIR__ . '/../../../secrets/jwt_public.pem';
        if (!file_exists($path)) {
            throw new \RuntimeException("JWT: chave pública não encontrada em '$path'.");
        }
        return file_get_contents($path);
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