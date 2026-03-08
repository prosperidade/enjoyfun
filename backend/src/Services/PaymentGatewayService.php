<?php
namespace EnjoyFun\Services;

use PDO;
use DateTimeImmutable;
use InvalidArgumentException;

class PaymentGatewayService
{
    private const PROVIDERS = ['mercadopago', 'pagseguro', 'asaas', 'pagarme', 'infinitypay'];
    private const SECRET_FIELDS = [
        'access_token',
        'public_key',
        'token',
        'api_key',
        'client_id',
        'client_secret',
        'secret_key',
        'webhook_secret',
        'merchant_id'
    ];

    public static function listGateways(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare("
            SELECT id, provider, credentials, is_active, is_primary, environment, created_at, updated_at
            FROM organizer_payment_gateways
            WHERE organizer_id = ?
            ORDER BY is_primary DESC, id ASC
        ");
        $stmt->execute([$organizerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($row) => self::mapGatewayRow($row), $rows);
    }

    public static function getGatewayById(PDO $db, int $organizerId, int $gatewayId): ?array
    {
        $stmt = $db->prepare("
            SELECT id, provider, credentials, is_active, is_primary, environment, created_at, updated_at
            FROM organizer_payment_gateways
            WHERE id = ? AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$gatewayId, $organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::mapGatewayRow($row) : null;
    }

    public static function findByProvider(PDO $db, int $organizerId, string $provider): ?array
    {
        $provider = self::normalizeProvider($provider);
        $stmt = $db->prepare("
            SELECT id, provider, credentials, is_active, is_primary, environment, created_at, updated_at
            FROM organizer_payment_gateways
            WHERE organizer_id = ? AND provider = ?
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::mapGatewayRow($row) : null;
    }

    public static function createGateway(PDO $db, int $organizerId, array $payload): array
    {
        $provider = self::normalizeProvider((string)($payload['provider'] ?? $payload['gateway_provider'] ?? ''));
        if ($provider === '') {
            throw new InvalidArgumentException('provider é obrigatório e deve ser suportado.');
        }

        if (self::findByProvider($db, $organizerId, $provider)) {
            throw new InvalidArgumentException('Já existe gateway cadastrado para este provider. Use edição.');
        }

        $isActive = self::toBool($payload['is_active'] ?? $payload['gateway_active'] ?? true);
        $isPrimary = self::toBool($payload['is_primary'] ?? $payload['is_principal'] ?? false);
        $environment = self::normalizeEnvironment((string)($payload['environment'] ?? 'production'));

        $credentials = self::extractCredentialInput($payload);
        $storedCredentials = self::buildStoredCredentials($credentials, $isPrimary, $environment);

        $stmt = $db->prepare("
            INSERT INTO organizer_payment_gateways (
                organizer_id, provider, credentials, is_active, is_primary, environment, updated_at
            )
            VALUES (?, ?, ?::jsonb, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmt->execute([
            $organizerId,
            $provider,
            json_encode($storedCredentials, JSON_UNESCAPED_UNICODE),
            $isActive,
            $isPrimary,
            $environment
        ]);
        $gatewayId = (int)$stmt->fetchColumn();

        if ($isPrimary) {
            self::setPrimaryGateway($db, $organizerId, $gatewayId);
        }

        return self::getGatewayById($db, $organizerId, $gatewayId) ?? [];
    }

    public static function updateGateway(PDO $db, int $organizerId, int $gatewayId, array $payload): array
    {
        $current = self::getRawGateway($db, $organizerId, $gatewayId);
        if (!$current) {
            throw new InvalidArgumentException('Gateway não encontrado.');
        }

        $provider = isset($payload['provider']) || isset($payload['gateway_provider'])
            ? self::normalizeProvider((string)($payload['provider'] ?? $payload['gateway_provider']))
            : (string)$current['provider'];
        if ($provider === '') {
            throw new InvalidArgumentException('provider inválido.');
        }

        if ($provider !== (string)$current['provider']) {
            $sameProvider = self::findByProvider($db, $organizerId, $provider);
            if ($sameProvider && (int)$sameProvider['id'] !== $gatewayId) {
                throw new InvalidArgumentException('Já existe gateway com este provider.');
            }
        }

        $isActive = array_key_exists('is_active', $payload) || array_key_exists('gateway_active', $payload)
            ? self::toBool($payload['is_active'] ?? $payload['gateway_active'])
            : self::toBool($current['is_active']);

        $decodedCurrent = self::decodeStoredCredentials($current['credentials'] ?? []);
        $existingSecrets = $decodedCurrent['secrets'] ?? [];
        $incoming = self::extractCredentialInput($payload);
        $finalSecrets = $existingSecrets;
        foreach ($incoming as $k => $v) {
            if ($v === null || $v === '' || strpos($v, '...') !== false || strpos($v, '*') !== false || $v === 'dummy_token_to_test_backend') {
                continue;
            }
            $finalSecrets[$k] = $v;
        }

        $isPrimary = array_key_exists('is_primary', $payload) || array_key_exists('is_principal', $payload)
            ? self::toBool($payload['is_primary'] ?? $payload['is_principal'])
            : self::toBool($current['is_primary'] ?? ($decodedCurrent['flags']['is_primary'] ?? false));

        $environment = array_key_exists('environment', $payload)
            ? self::normalizeEnvironment((string)$payload['environment'])
            : self::normalizeEnvironment((string)($current['environment'] ?? ($decodedCurrent['flags']['environment'] ?? 'production')));

        $storedCredentials = self::buildStoredCredentials($finalSecrets, $isPrimary, $environment);

        $stmt = $db->prepare("
            UPDATE organizer_payment_gateways
            SET provider = ?, credentials = ?::jsonb, is_active = ?, is_primary = ?, environment = ?, updated_at = NOW()
            WHERE id = ? AND organizer_id = ?
        ");
        $stmt->execute([
            $provider,
            json_encode($storedCredentials, JSON_UNESCAPED_UNICODE),
            $isActive,
            $isPrimary,
            $environment,
            $gatewayId,
            $organizerId
        ]);

        if ($isPrimary) {
            self::setPrimaryGateway($db, $organizerId, $gatewayId);
        } elseif (!$isActive) {
            // Um gateway inativo não pode seguir marcado como principal.
            $db->prepare("
                UPDATE organizer_payment_gateways
                SET is_primary = FALSE, updated_at = NOW()
                WHERE id = ? AND organizer_id = ?
            ")->execute([$gatewayId, $organizerId]);
        }

        return self::getGatewayById($db, $organizerId, $gatewayId) ?? [];
    }

    public static function deleteGateway(PDO $db, int $organizerId, int $gatewayId): void
    {
        $stmt = $db->prepare("DELETE FROM organizer_payment_gateways WHERE id = ? AND organizer_id = ?");
        $stmt->execute([$gatewayId, $organizerId]);
        if ($stmt->rowCount() <= 0) {
            throw new InvalidArgumentException('Gateway não encontrado para exclusão.');
        }
    }

    public static function setPrimaryGateway(PDO $db, int $organizerId, int $gatewayId): array
    {
        $existsStmt = $db->prepare("SELECT id FROM organizer_payment_gateways WHERE id = ? AND organizer_id = ? LIMIT 1");
        $existsStmt->execute([$gatewayId, $organizerId]);
        if (!(int)$existsStmt->fetchColumn()) {
            throw new InvalidArgumentException('Gateway alvo para principal não encontrado.');
        }

        // Zera principal de todos no tenant.
        $db->prepare("
            UPDATE organizer_payment_gateways
            SET is_primary = FALSE, updated_at = NOW()
            WHERE organizer_id = ?
        ")->execute([$organizerId]);

        // Define principal e força ativo.
        $db->prepare("
            UPDATE organizer_payment_gateways
            SET is_primary = TRUE, is_active = TRUE, updated_at = NOW()
            WHERE organizer_id = ? AND id = ?
        ")->execute([$organizerId, $gatewayId]);

        // Mantém o legado no JSON credentials (flags) para compatibilidade.
        $rowsStmt = $db->prepare("
            SELECT id, credentials, environment
            FROM organizer_payment_gateways
            WHERE organizer_id = ?
            ORDER BY id ASC
        ");
        $rowsStmt->execute([$organizerId]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $decoded = self::decodeStoredCredentials($row['credentials'] ?? []);
            $secrets = $decoded['secrets'] ?? [];
            $env = self::normalizeEnvironment((string)($row['environment'] ?? ($decoded['flags']['environment'] ?? 'production')));
            $stored = self::buildStoredCredentials($secrets, $id === $gatewayId, $env);
            $db->prepare("
                UPDATE organizer_payment_gateways
                SET credentials = ?::jsonb
                WHERE organizer_id = ? AND id = ?
            ")->execute([json_encode($stored, JSON_UNESCAPED_UNICODE), $organizerId, $id]);
        }

        return self::getGatewayById($db, $organizerId, $gatewayId) ?? [];
    }

    public static function setGatewayActive(PDO $db, int $organizerId, int $gatewayId, bool $isActive): array
    {
        $stmt = $db->prepare("
            UPDATE organizer_payment_gateways
            SET is_active = ?, is_primary = CASE WHEN ? = FALSE THEN FALSE ELSE is_primary END, updated_at = NOW()
            WHERE id = ? AND organizer_id = ?
        ");
        $stmt->execute([$isActive, $isActive, $gatewayId, $organizerId]);
        if ($stmt->rowCount() <= 0) {
            throw new InvalidArgumentException('Gateway não encontrado para atualizar status.');
        }
        return self::getGatewayById($db, $organizerId, $gatewayId) ?? [];
    }

    public static function testGatewayConnection(PDO $db, int $organizerId, array $payload, ?int $gatewayId = null): array
    {
        $provider = '';
        $credentials = [];

        if ($gatewayId !== null && $gatewayId > 0) {
            $raw = self::getRawGateway($db, $organizerId, $gatewayId);
            if (!$raw) {
                throw new InvalidArgumentException('Gateway não encontrado para teste.');
            }
            $provider = (string)$raw['provider'];
            $decoded = self::decodeStoredCredentials($raw['credentials'] ?? []);
            $credentials = $decoded['secrets'] ?? [];
        } else {
            $provider = self::normalizeProvider((string)($payload['provider'] ?? $payload['gateway_provider'] ?? ''));
            if ($provider === '') {
                throw new InvalidArgumentException('provider é obrigatório para testar conexão.');
            }

            $incoming = self::extractCredentialInput($payload);
            if (!empty($incoming)) {
                $credentials = $incoming;
            } else {
                $existing = self::findByProvider($db, $organizerId, $provider);
                if ($existing) {
                    $raw = self::getRawGateway($db, $organizerId, (int)$existing['id']);
                    $decoded = self::decodeStoredCredentials($raw['credentials'] ?? []);
                    $credentials = $decoded['secrets'] ?? [];
                }
            }
        }

        $required = self::requiredFieldsByProvider($provider);
        $provided = array_keys(array_filter($credentials, fn($v) => trim((string)$v) !== ''));

        $missing = array_values(array_filter($required, fn($f) => empty($credentials[$f])));
        $connected = count($missing) === 0;
        $message = $connected
            ? 'Conexão validada em modo seguro (sem transação real).'
            : ('Credenciais incompletas para este provider: ' . implode(', ', $missing));

        return [
            'provider' => $provider,
            'connected' => $connected,
            'status' => $connected ? 'ok' : 'error',
            'mode' => 'validation_only',
            'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'required_fields' => $required,
            'provided_fields' => $provided,
            'message' => $message
        ];
    }

    private static function getRawGateway(PDO $db, int $organizerId, int $gatewayId): ?array
    {
        $stmt = $db->prepare("
            SELECT id, provider, credentials, is_active, is_primary, environment, created_at, updated_at
            FROM organizer_payment_gateways
            WHERE id = ? AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$gatewayId, $organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (is_string($row['credentials'] ?? null)) {
            $decoded = json_decode($row['credentials'], true);
            $row['credentials'] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    private static function mapGatewayRow(array $row): array
    {
        $decoded = self::decodeStoredCredentials($row['credentials'] ?? []);
        $flags = $decoded['flags'] ?? [];
        $secrets = $decoded['secrets'] ?? [];

        $isPrimary = self::toBool($row['is_primary'] ?? ($flags['is_primary'] ?? false));
        $environment = self::normalizeEnvironment((string)($row['environment'] ?? ($flags['environment'] ?? 'production')));

        return [
            'id' => (int)$row['id'],
            'provider' => (string)$row['provider'],
            'is_active' => self::toBool($row['is_active'] ?? false),
            'is_primary' => $isPrimary,
            'is_principal' => $isPrimary,
            'environment' => $environment,
            'credentials' => [
                'has_token' => self::hasAnyCredential($secrets),
                'public_key' => self::maskSensitive($secrets['public_key'] ?? ''),
                'access_token' => null
            ],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null
        ];
    }

    private static function hasAnyCredential(array $secrets): bool
    {
        foreach ($secrets as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function extractCredentialInput(array $payload): array
    {
        $source = $payload['credentials'] ?? $payload;
        if (!is_array($source)) {
            return [];
        }
        $out = [];
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $source)) {
                $out[$field] = trim((string)$source[$field]);
            }
        }
        return $out;
    }

    private static function buildStoredCredentials(array $secrets, bool $isPrimary, string $environment): array
    {
        $storedSecrets = [];
        foreach ($secrets as $k => $v) {
            if ($v === null || trim((string)$v) === '') {
                continue;
            }
            $storedSecrets[$k] = self::encryptValue((string)$v);
        }

        return [
            'version' => 2,
            'secure' => $storedSecrets,
            'flags' => [
                'is_primary' => $isPrimary,
                'environment' => $environment
            ]
        ];
    }

    private static function decodeStoredCredentials($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            $value = [];
        }

        if (isset($value['version']) && isset($value['secure']) && is_array($value['secure'])) {
            $secrets = [];
            foreach ($value['secure'] as $k => $enc) {
                $secrets[$k] = self::decryptValue((string)$enc);
            }
            return [
                'secrets' => $secrets,
                'flags' => is_array($value['flags'] ?? null) ? $value['flags'] : []
            ];
        }

        $secrets = [];
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $value)) {
                $secrets[$field] = (string)$value[$field];
            }
        }
        return [
            'secrets' => $secrets,
            'flags' => [
                'is_primary' => self::toBool($value['is_primary'] ?? $value['is_principal'] ?? false),
                'environment' => self::normalizeEnvironment((string)($value['environment'] ?? 'production'))
            ]
        ];
    }

    private static function requiredFieldsByProvider(string $provider): array
    {
        return match ($provider) {
            'mercadopago' => ['access_token'],
            'pagseguro' => ['access_token'],
            'asaas' => ['api_key'],
            'pagarme' => ['api_key'],
            'infinitypay' => ['access_token'],
            default => ['access_token'],
        };
    }

    private static function normalizeProvider(string $provider): string
    {
        $p = strtolower(trim($provider));
        $map = [
            'mercado_pago' => 'mercadopago',
            'mercado-pago' => 'mercadopago',
            'mercado pago' => 'mercadopago',
            'pagar.me' => 'pagarme',
            'infinity_pay' => 'infinitypay',
            'infinity-pay' => 'infinitypay',
        ];
        $p = $map[$p] ?? $p;
        if ($p === '' || !in_array($p, self::PROVIDERS, true)) {
            return '';
        }
        return $p;
    }

    private static function normalizeEnvironment(string $environment): string
    {
        $env = strtolower(trim($environment));
        return in_array($env, ['sandbox', 'production'], true) ? $env : 'production';
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function encryptValue(string $plain): string
    {
        $key = self::encryptionKey();
        $iv = random_bytes(16);
        $cipherRaw = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipherRaw === false) {
            return 'v1:' . base64_encode($plain);
        }
        return 'v1:' . base64_encode($iv . $cipherRaw);
    }

    private static function decryptValue(string $cipher): string
    {
        if (strpos($cipher, 'v1:') !== 0) {
            return $cipher;
        }
        $raw = base64_decode(substr($cipher, 3), true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $plain = openssl_decrypt($enc, 'AES-256-CBC', self::encryptionKey(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    private static function encryptionKey(): string
    {
        $base = (string)(
            getenv('FINANCE_CREDENTIALS_KEY')
            ?: getenv('JWT_SECRET')
            ?: getenv('APP_KEY')
            ?: 'enjoyfun-finance-local-key'
        );
        return hash('sha256', $base, true);
    }

    private static function maskSensitive(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 8) {
            return '***';
        }
        return substr($value, 0, 4) . '...' . substr($value, -4);
    }
}

