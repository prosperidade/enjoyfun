<?php
/**
 * EnjoyFun - JWT Helper (RS256 Asymmetric)
 * Padrao Enterprise: Chave Privada assina, Chave Publica valida.
 */
class JWT
{
    private const ALGO = 'RS256';
    private const ISSUER = 'enjoyfun-core-auth';

    public static function encode(array $payload, int $ttlSeconds = 3600): string
    {
        self::assertOpenSslAvailable();

        $now = time();
        $payload = array_merge([
            'iss' => self::ISSUER,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ], $payload);

        $header = self::b64url(self::jsonEncode(['alg' => self::ALGO, 'typ' => 'JWT', 'kid' => 'enjoyfun-rs256-v1']));
        $body = self::b64url(self::jsonEncode($payload));

        $privateKey = self::getPrivateKey();

        $signature = '';
        if (!openssl_sign("$header.$body", $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new Exception("Falha ao assinar JWT com OpenSSL.");
        }

        return "$header.$body." . self::b64url($signature);
    }

    public static function decode(string $token): ?array
    {
        if (!self::isOpenSslAvailable()) {
            error_log("❌ [JWT] OpenSSL indisponivel no runtime para validar RS256.");
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log("❌ [JWT] Token mal formado.");
            return null;
        }

        [$header, $body, $sig] = $parts;

        $headerData = json_decode(self::b64urlDecode($header), true);
        if (!is_array($headerData)) {
            error_log("❌ [JWT] Header invalido.");
            return null;
        }
        if (($headerData['alg'] ?? '') !== self::ALGO) {
            error_log("❌ [JWT] Algoritmo recusado. Esperado RS256.");
            return null;
        }
        if (($headerData['typ'] ?? '') !== 'JWT') {
            error_log("❌ [JWT] Tipo de token invalido.");
            return null;
        }

        try {
            $publicKey = self::getPublicKey();
            $signatureDecoded = self::b64urlDecode($sig);

            $isValid = openssl_verify("$header.$body", $signatureDecoded, $publicKey, OPENSSL_ALGO_SHA256);

            if ($isValid !== 1) {
                error_log("❌ [JWT] Assinatura RSA inválida!");
                return null;
            }

            $payload = json_decode(self::b64urlDecode($body), true);
            if (!is_array($payload)) {
                error_log("❌ [JWT] Payload invalido.");
                return null;
            }

            if (!isset($payload['exp']) || $payload['exp'] < time()) {
                error_log("❌ [JWT] Token expirado.");
                return null;
            }
            if (($payload['iss'] ?? '') !== self::ISSUER) {
                error_log("❌ [JWT] Emissor (iss) inválido.");
                return null;
            }

            return $payload;
        } catch (Throwable $e) {
            error_log("❌ [JWT] Exception no decode RSA: " . $e->getMessage());
            return null;
        }
    }

    public static function fromHeader(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function getPrivateKey()
    {
        $privateKey = openssl_pkey_get_private(self::loadPem('private.pem', 'JWT_PRIVATE_KEY'));
        if ($privateKey === false) {
            throw new Exception('Falha ao carregar JWT private key.');
        }

        return $privateKey;
    }

    private static function getPublicKey()
    {
        $publicKey = openssl_pkey_get_public(self::loadPem('public.pem', 'JWT_PUBLIC_KEY'));
        if ($publicKey === false) {
            throw new Exception('Falha ao carregar JWT public key.');
        }

        return $publicKey;
    }

    private static function resolveKeyCandidates(string $filename): array
    {
        $candidates = [];

        if (defined('BASE_PATH')) {
            $candidates[] = BASE_PATH . '/' . $filename;
            $candidates[] = dirname(BASE_PATH) . '/' . $filename;
        }

        $candidates[] = dirname(__DIR__, 3) . '/' . $filename;
        $candidates[] = dirname(__DIR__, 2) . '/' . $filename;

        return array_values(array_unique($candidates));
    }

    private static function loadPem(string $filename, string $envName): string
    {
        foreach (self::resolveKeyCandidates($filename) as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            if (is_string($contents) && trim($contents) !== '') {
                return $contents;
            }
        }

        $envValue = str_replace('\n', "\n", trim((string)getenv($envName)));
        if ($envValue !== '') {
            return $envValue;
        }

        throw new Exception("{$envName} nao configurado e {$filename} nao encontrado.");
    }

    private static function jsonEncode(array $value): string
    {
        $encoded = json_encode($value);
        if (!is_string($encoded)) {
            throw new Exception('Falha ao serializar JWT.');
        }

        return $encoded;
    }

    private static function isOpenSslAvailable(): bool
    {
        return extension_loaded('openssl')
            && function_exists('openssl_sign')
            && function_exists('openssl_verify')
            && function_exists('openssl_pkey_get_private')
            && function_exists('openssl_pkey_get_public')
            && defined('OPENSSL_ALGO_SHA256');
    }

    private static function assertOpenSslAvailable(): void
    {
        if (!self::isOpenSslAvailable()) {
            throw new Exception('OpenSSL extension is required for RS256 JWT.');
        }
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
