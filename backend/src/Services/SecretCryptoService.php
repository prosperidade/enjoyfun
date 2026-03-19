<?php
namespace EnjoyFun\Services;

use RuntimeException;

final class SecretCryptoService
{
    private const PREFIX = 'efsec:v1:';
    private const CIPHER = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    public static function encrypt(?string $plain, string $scope = 'default'): string
    {
        $plain = trim((string)$plain);
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $ciphertext = openssl_encrypt($plain, self::CIPHER, self::encryptionKey($scope), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Falha ao cifrar dado sensivel.');
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, self::macKey($scope), true);
        return self::PREFIX . base64_encode($iv . $mac . $ciphertext);
    }

    public static function decrypt(?string $value, string $scope = 'default'): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (!self::isEncrypted($value)) {
            return $value;
        }

        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) <= (self::IV_LENGTH + 32)) {
            throw new RuntimeException('Payload cifrado invalido.');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $mac = substr($payload, self::IV_LENGTH, 32);
        $ciphertext = substr($payload, self::IV_LENGTH + 32);
        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, self::macKey($scope), true);

        if (!hash_equals($expectedMac, $mac)) {
            throw new RuntimeException('Assinatura do payload cifrado invalida.');
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::encryptionKey($scope), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Falha ao decifrar dado sensivel.');
        }

        return $plain;
    }

    public static function isEncrypted(?string $value): bool
    {
        return str_starts_with(trim((string)$value), self::PREFIX);
    }

    private static function encryptionKey(string $scope): string
    {
        return hash_hmac('sha256', 'enc:' . $scope, self::baseSecret(), true);
    }

    private static function macKey(string $scope): string
    {
        return hash_hmac('sha256', 'mac:' . $scope, self::baseSecret(), true);
    }

    private static function baseSecret(): string
    {
        $base = trim((string)(
            getenv('MESSAGING_CREDENTIALS_KEY')
            ?: getenv('SENSITIVE_DATA_KEY')
            ?: getenv('JWT_SECRET')
            ?: getenv('APP_KEY')
            ?: ''
        ));

        if ($base === '') {
            throw new RuntimeException('Chave de cifragem sensivel ausente no ambiente.');
        }

        return $base;
    }
}
