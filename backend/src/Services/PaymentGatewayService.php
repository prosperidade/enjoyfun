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
        $selectColumns = self::selectColumns($db);
        $stmt = $db->prepare("
            SELECT {$selectColumns}
            FROM organizer_payment_gateways
            WHERE organizer_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$organizerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mapped = array_map(fn($row) => self::mapGatewayRow($row), $rows);
        usort($mapped, function ($a, $b) {
            $ap = self::toBool($a['is_primary'] ?? false);
            $bp = self::toBool($b['is_primary'] ?? false);
            if ($ap === $bp) {
                return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
            }
            return $ap ? -1 : 1;
        });
        return $mapped;
    }

    public static function getGatewayById(PDO $db, int $organizerId, int $gatewayId): ?array
    {
        $selectColumns = self::selectColumns($db);
        $stmt = $db->prepare("
            SELECT {$selectColumns}
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
        $selectColumns = self::selectColumns($db);
        $stmt = $db->prepare("
            SELECT {$selectColumns}
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

        $schema = self::gatewaySchema($db);
        if ($schema['has_is_primary'] && $schema['has_environment']) {
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
        } else {
            $stmt = $db->prepare("
                INSERT INTO organizer_payment_gateways (
                    organizer_id, provider, credentials, is_active, updated_at
                )
                VALUES (?, ?, ?::jsonb, ?, NOW())
                RETURNING id
            ");
            $stmt->execute([
                $organizerId,
                $provider,
                json_encode($storedCredentials, JSON_UNESCAPED_UNICODE),
                $isActive
            ]);
        }
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

        $schema = self::gatewaySchema($db);
        if ($schema['has_is_primary'] && $schema['has_environment']) {
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
        } else {
            $stmt = $db->prepare("
                UPDATE organizer_payment_gateways
                SET provider = ?, credentials = ?::jsonb, is_active = ?, updated_at = NOW()
                WHERE id = ? AND organizer_id = ?
            ");
            $stmt->execute([
                $provider,
                json_encode($storedCredentials, JSON_UNESCAPED_UNICODE),
                $isActive,
                $gatewayId,
                $organizerId
            ]);
        }

        if ($isPrimary) {
            self::setPrimaryGateway($db, $organizerId, $gatewayId);
        } elseif (!$isActive && $schema['has_is_primary']) {
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

        $schema = self::gatewaySchema($db);
        if ($schema['has_is_primary']) {
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
        } else {
            $db->prepare("
                UPDATE organizer_payment_gateways
                SET is_active = CASE WHEN id = ? THEN TRUE ELSE is_active END, updated_at = NOW()
                WHERE organizer_id = ?
            ")->execute([$gatewayId, $organizerId]);
        }

        // Mantém o legado no JSON credentials (flags) para compatibilidade.
        $rowsStmt = $db->prepare("
            SELECT id, credentials, " . ($schema['has_environment'] ? "environment" : "NULL::varchar AS environment") . "
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
        $schema = self::gatewaySchema($db);
        if ($schema['has_is_primary']) {
            $stmt = $db->prepare("
                UPDATE organizer_payment_gateways
                SET is_active = ?, is_primary = CASE WHEN ? = FALSE THEN FALSE ELSE is_primary END, updated_at = NOW()
                WHERE id = ? AND organizer_id = ?
            ");
            $stmt->execute([$isActive, $isActive, $gatewayId, $organizerId]);
        } else {
            $stmt = $db->prepare("
                UPDATE organizer_payment_gateways
                SET is_active = ?, updated_at = NOW()
                WHERE id = ? AND organizer_id = ?
            ");
            $stmt->execute([$isActive, $gatewayId, $organizerId]);
        }
        if ($stmt->rowCount() <= 0) {
            throw new InvalidArgumentException('Gateway não encontrado para atualizar status.');
        }

        if (!$isActive) {
            $raw = self::getRawGateway($db, $organizerId, $gatewayId);
            if ($raw) {
                $decoded = self::decodeStoredCredentials($raw['credentials'] ?? []);
                $secrets = $decoded['secrets'] ?? [];
                $env = self::normalizeEnvironment((string)($raw['environment'] ?? ($decoded['flags']['environment'] ?? 'production')));
                $stored = self::buildStoredCredentials($secrets, false, $env);
                $db->prepare("
                    UPDATE organizer_payment_gateways
                    SET credentials = ?::jsonb, updated_at = NOW()
                    WHERE id = ? AND organizer_id = ?
                ")->execute([json_encode($stored, JSON_UNESCAPED_UNICODE), $gatewayId, $organizerId]);
            }
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
        $selectColumns = self::selectColumns($db);
        $stmt = $db->prepare("
            SELECT {$selectColumns}
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

    private static function selectColumns(PDO $db): string
    {
        $schema = self::gatewaySchema($db);
        $isPrimaryExpr = $schema['has_is_primary'] ? 'is_primary' : 'NULL::boolean AS is_primary';
        $environmentExpr = $schema['has_environment'] ? 'environment' : 'NULL::varchar AS environment';
        return "id, provider, credentials, is_active, {$isPrimaryExpr}, {$environmentExpr}, created_at, updated_at";
    }

    private static function gatewaySchema(PDO $db): array
    {
        static $cache = [];
        $key = spl_object_hash($db);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $cache[$key] = [
            'has_is_primary' => self::columnExists($db, 'organizer_payment_gateways', 'is_primary'),
            'has_environment' => self::columnExists($db, 'organizer_payment_gateways', 'environment'),
        ];
        return $cache[$key];
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
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
        $base = trim((string)(
            getenv('FINANCE_CREDENTIALS_KEY')
            ?: getenv('JWT_SECRET')
            ?: getenv('APP_KEY')
            ?: ''
        ));

        if ($base === '') {
            throw new \RuntimeException('Finance encryption key is not configured. Set FINANCE_CREDENTIALS_KEY, JWT_SECRET, or APP_KEY in env.');
        }

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

    // ── Charge Operations (Asaas Integration) ──────────────────────────────────

    private const PLATFORM_FEE_RATE = 0.01; // 1% EnjoyFun
    private const ASAAS_API_SANDBOX = 'https://sandbox.asaas.com/api/v3';
    private const ASAAS_API_PRODUCTION = 'https://www.asaas.com/api/v3';

    /**
     * Calculate the 1% / 99% split for a given amount.
     */
    public static function calculateSplit(float $amount): array
    {
        $platformFee = round($amount * self::PLATFORM_FEE_RATE, 2);
        $organizerAmount = round($amount - $platformFee, 2);

        return [
            'platform_fee' => $platformFee,
            'organizer_amount' => $organizerAmount,
        ];
    }

    /**
     * Create a charge via Asaas (PIX or BOLETO).
     *
     * @param PDO   $db
     * @param int   $organizerId  From JWT
     * @param array $params       Keys: amount, description, customer_name, customer_cpf,
     *                            customer_email, billing_type (PIX|BOLETO),
     *                            event_id, sale_id, idempotency_key, due_date
     * @return array Charge record
     */
    public static function createCharge(PDO $db, int $organizerId, array $params): array
    {
        $amount = (float)($params['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount deve ser maior que zero.');
        }

        $billingType = strtoupper(trim((string)($params['billing_type'] ?? 'PIX')));
        if (!in_array($billingType, ['PIX', 'BOLETO', 'CREDIT_CARD'], true)) {
            throw new InvalidArgumentException('billing_type deve ser PIX, BOLETO ou CREDIT_CARD.');
        }

        $customerName = trim((string)($params['customer_name'] ?? ''));
        $customerCpf = preg_replace('/\D/', '', (string)($params['customer_cpf'] ?? ''));
        $customerEmail = trim((string)($params['customer_email'] ?? ''));
        $description = trim((string)($params['description'] ?? ''));
        $eventId = isset($params['event_id']) ? (int)$params['event_id'] : null;
        $saleId = isset($params['sale_id']) ? (int)$params['sale_id'] : null;
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        $dueDate = trim((string)($params['due_date'] ?? date('Y-m-d', strtotime('+3 days'))));

        if ($customerName === '' || $customerCpf === '') {
            throw new InvalidArgumentException('customer_name e customer_cpf sao obrigatorios.');
        }

        // Idempotency: check for existing charge with same key
        if ($idempotencyKey !== '') {
            $existing = self::findChargeByIdempotencyKey($db, $organizerId, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        $split = self::calculateSplit($amount);

        // Resolve gateway credentials for Asaas
        $apiKey = self::resolveAsaasApiKey($db, $organizerId);
        $baseUrl = self::resolveAsaasBaseUrl($db, $organizerId);

        // Create customer on Asaas (or find existing)
        $customerId = self::ensureAsaasCustomer($apiKey, $baseUrl, $customerName, $customerCpf, $customerEmail);

        // Create the charge on Asaas
        $chargePayload = [
            'customer' => $customerId,
            'billingType' => $billingType,
            'value' => $amount,
            'dueDate' => $dueDate,
            'description' => $description !== '' ? $description : 'EnjoyFun charge',
        ];

        $response = self::asaasPost($apiKey, $baseUrl, '/payments', $chargePayload);

        if (!isset($response['id'])) {
            throw new \RuntimeException('Asaas nao retornou ID da cobranca. Response: ' . json_encode($response));
        }

        // Extract PIX/boleto data from response
        $pixCode = null;
        $boletoUrl = null;

        if ($billingType === 'PIX' && isset($response['id'])) {
            $pixData = self::asaasGet($apiKey, $baseUrl, '/payments/' . $response['id'] . '/pixQrCode');
            $pixCode = $pixData['payload'] ?? ($pixData['encodedImage'] ?? null);
        }

        if ($billingType === 'BOLETO') {
            $boletoUrl = $response['bankSlipUrl'] ?? null;
        }

        // Insert into local database
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO payment_charges (
                    organizer_id, event_id, sale_id, external_id, gateway,
                    amount, platform_fee, organizer_amount, status, billing_type,
                    pix_code, boleto_url, due_date, idempotency_key, created_at, updated_at
                ) VALUES (
                    :organizer_id, :event_id, :sale_id, :external_id, 'asaas',
                    :amount, :platform_fee, :organizer_amount, :status, :billing_type,
                    :pix_code, :boleto_url, :due_date, :idempotency_key, NOW(), NOW()
                )
                RETURNING id
            ");
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':event_id' => $eventId > 0 ? $eventId : null,
                ':sale_id' => $saleId > 0 ? $saleId : null,
                ':external_id' => $response['id'],
                ':amount' => $amount,
                ':platform_fee' => $split['platform_fee'],
                ':organizer_amount' => $split['organizer_amount'],
                ':status' => self::mapAsaasStatus($response['status'] ?? 'PENDING'),
                ':billing_type' => $billingType,
                ':pix_code' => $pixCode,
                ':boleto_url' => $boletoUrl,
                ':due_date' => $dueDate,
                ':idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            ]);
            $chargeId = (int)$stmt->fetchColumn();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Audit log
        if (class_exists('AuditService')) {
            \AuditService::log(
                'payment.charge.created',
                'payment_charge',
                $chargeId,
                null,
                [
                    'external_id' => $response['id'],
                    'amount' => $amount,
                    'billing_type' => $billingType,
                    'platform_fee' => $split['platform_fee'],
                    'organizer_amount' => $split['organizer_amount'],
                ],
                null,
                'success',
                [
                    'organizer_id' => $organizerId,
                    'event_id' => $eventId,
                ]
            );
        }

        return self::getChargeById($db, $organizerId, $chargeId);
    }

    /**
     * Get the status of a charge by its local ID or external ID.
     */
    public static function getChargeStatus(PDO $db, int $organizerId, string $chargeId): array
    {
        // Try local ID first, then external_id
        $stmt = $db->prepare("
            SELECT id, organizer_id, event_id, sale_id, external_id, gateway,
                   amount, platform_fee, organizer_amount, status, billing_type,
                   pix_code, boleto_url, due_date, paid_at, idempotency_key,
                   webhook_event_ids, created_at, updated_at
            FROM payment_charges
            WHERE organizer_id = :organizer_id
              AND (id::text = :charge_id OR external_id = :charge_id2)
            LIMIT 1
        ");
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':charge_id' => $chargeId,
            ':charge_id2' => $chargeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Cobranca nao encontrada.');
        }

        // Optionally refresh from gateway
        if (in_array($row['status'], ['pending'], true) && $row['external_id']) {
            try {
                $apiKey = self::resolveAsaasApiKey($db, $organizerId);
                $baseUrl = self::resolveAsaasBaseUrl($db, $organizerId);
                $remote = self::asaasGet($apiKey, $baseUrl, '/payments/' . $row['external_id']);
                $newStatus = self::mapAsaasStatus($remote['status'] ?? '');

                if ($newStatus !== $row['status']) {
                    $paidAt = null;
                    if (in_array($newStatus, ['confirmed', 'received'], true)) {
                        $paidAt = $remote['confirmedDate'] ?? $remote['paymentDate'] ?? date('Y-m-d H:i:s');
                    }

                    self::updateChargeStatus($db, (int)$row['id'], $newStatus, $paidAt);
                    $row['status'] = $newStatus;
                    if ($paidAt) {
                        $row['paid_at'] = $paidAt;
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal: return cached status
                error_log('[PaymentGatewayService] Failed to refresh charge status: ' . $e->getMessage());
            }
        }

        return self::formatChargeRow($row);
    }

    /**
     * Process a webhook notification from Asaas.
     * Validates HMAC signature, checks idempotency, updates charge status.
     *
     * @param PDO    $db
     * @param array  $payload   Webhook body
     * @param string $signature Webhook HMAC signature header
     * @return array  Processing result
     */
    public static function processWebhook(PDO $db, array $payload, string $signature): array
    {
        $webhookToken = trim((string)getenv('ASAAS_WEBHOOK_TOKEN'));
        if ($webhookToken === '') {
            throw new \RuntimeException('ASAAS_WEBHOOK_TOKEN nao configurado.');
        }

        // Validate HMAC signature
        $rawBody = $GLOBALS['ENJOYFUN_RAW_BODY'] ?? json_encode($payload);
        $expectedSignature = hash_hmac('sha256', $rawBody, $webhookToken);

        if (!hash_equals($expectedSignature, $signature)) {
            if (class_exists('AuditService')) {
                \AuditService::log(
                    defined('\\AuditService::WEBHOOK_REJECTED') ? \AuditService::WEBHOOK_REJECTED : 'webhook.rejected',
                    'payment_webhook',
                    null,
                    null,
                    ['reason' => 'invalid_signature'],
                    null,
                    'failure',
                    ['metadata' => ['event' => $payload['event'] ?? 'unknown']]
                );
            }
            throw new \RuntimeException('Assinatura do webhook invalida.');
        }

        $event = (string)($payload['event'] ?? '');
        $webhookEventId = (string)($payload['id'] ?? '');
        $paymentData = $payload['payment'] ?? [];
        $externalId = (string)($paymentData['id'] ?? '');

        if ($externalId === '') {
            throw new InvalidArgumentException('Webhook sem payment.id.');
        }

        // Find the charge by external_id
        $stmt = $db->prepare("
            SELECT id, organizer_id, status, webhook_event_ids
            FROM payment_charges
            WHERE external_id = :external_id
            FOR UPDATE
        ");

        $db->beginTransaction();
        try {
            $stmt->execute([':external_id' => $externalId]);
            $charge = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$charge) {
                $db->rollBack();
                return [
                    'processed' => false,
                    'reason' => 'charge_not_found',
                    'external_id' => $externalId,
                ];
            }

            // Idempotency: check if this webhook event was already processed
            $processedIds = $charge['webhook_event_ids'];
            if (is_string($processedIds)) {
                // Parse PostgreSQL array
                $processedIds = self::parsePgArray($processedIds);
            }
            if (!is_array($processedIds)) {
                $processedIds = [];
            }

            if ($webhookEventId !== '' && in_array($webhookEventId, $processedIds, true)) {
                $db->rollBack();
                return [
                    'processed' => false,
                    'reason' => 'already_processed',
                    'webhook_event_id' => $webhookEventId,
                ];
            }

            // Map the new status
            $asaasStatus = (string)($paymentData['status'] ?? '');
            $newStatus = self::mapAsaasStatus($asaasStatus);
            $previousStatus = $charge['status'];

            $paidAt = null;
            if (in_array($newStatus, ['confirmed', 'received'], true)) {
                $paidAt = $paymentData['confirmedDate'] ?? $paymentData['paymentDate'] ?? date('Y-m-d H:i:s');
            }

            // Append webhook event ID
            $processedIds[] = $webhookEventId;

            $updateStmt = $db->prepare("
                UPDATE payment_charges
                SET status = :status,
                    paid_at = COALESCE(:paid_at, paid_at),
                    webhook_event_ids = :webhook_ids,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':status' => $newStatus,
                ':paid_at' => $paidAt,
                ':webhook_ids' => self::toPgArray($processedIds),
                ':id' => (int)$charge['id'],
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Audit log
        if (class_exists('AuditService')) {
            \AuditService::log(
                defined('\\AuditService::WEBHOOK_VALIDATED') ? \AuditService::WEBHOOK_VALIDATED : 'webhook.validated',
                'payment_charge',
                (int)$charge['id'],
                ['status' => $previousStatus],
                ['status' => $newStatus, 'event' => $event],
                null,
                'success',
                [
                    'organizer_id' => (int)$charge['organizer_id'],
                    'metadata' => [
                        'webhook_event_id' => $webhookEventId,
                        'asaas_status' => $asaasStatus,
                        'external_id' => $externalId,
                    ],
                ]
            );
        }

        return [
            'processed' => true,
            'charge_id' => (int)$charge['id'],
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'webhook_event_id' => $webhookEventId,
        ];
    }

    /**
     * List charges for an organizer, optionally filtered by event_id or status.
     */
    public static function listCharges(PDO $db, int $organizerId, array $filters = []): array
    {
        $where = ['organizer_id = :organizer_id'];
        $params = [':organizer_id' => $organizerId];

        if (!empty($filters['event_id'])) {
            $where[] = 'event_id = :event_id';
            $params[':event_id'] = (int)$filters['event_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);
        $stmt = $db->prepare("
            SELECT id, organizer_id, event_id, sale_id, external_id, gateway,
                   amount, platform_fee, organizer_amount, status, billing_type,
                   pix_code, boleto_url, due_date, paid_at, idempotency_key,
                   created_at, updated_at
            FROM payment_charges
            WHERE {$whereClause}
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([self::class, 'formatChargeRow'], $rows);
    }

    // ── Private Charge Helpers ─────────────────────────────────────────────────

    private static function getChargeById(PDO $db, int $organizerId, int $chargeId): array
    {
        $stmt = $db->prepare("
            SELECT id, organizer_id, event_id, sale_id, external_id, gateway,
                   amount, platform_fee, organizer_amount, status, billing_type,
                   pix_code, boleto_url, due_date, paid_at, idempotency_key,
                   created_at, updated_at
            FROM payment_charges
            WHERE id = :id AND organizer_id = :organizer_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $chargeId, ':organizer_id' => $organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Cobranca nao encontrada.');
        }

        return self::formatChargeRow($row);
    }

    private static function findChargeByIdempotencyKey(PDO $db, int $organizerId, string $key): ?array
    {
        $stmt = $db->prepare("
            SELECT id, organizer_id, event_id, sale_id, external_id, gateway,
                   amount, platform_fee, organizer_amount, status, billing_type,
                   pix_code, boleto_url, due_date, paid_at, idempotency_key,
                   created_at, updated_at
            FROM payment_charges
            WHERE organizer_id = :organizer_id AND idempotency_key = :key
            LIMIT 1
        ");
        $stmt->execute([':organizer_id' => $organizerId, ':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::formatChargeRow($row) : null;
    }

    private static function updateChargeStatus(PDO $db, int $chargeId, string $status, ?string $paidAt = null): void
    {
        $stmt = $db->prepare("
            UPDATE payment_charges
            SET status = :status,
                paid_at = COALESCE(:paid_at, paid_at),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':paid_at' => $paidAt,
            ':id' => $chargeId,
        ]);
    }

    private static function formatChargeRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'organizer_id' => (int)$row['organizer_id'],
            'event_id' => $row['event_id'] !== null ? (int)$row['event_id'] : null,
            'sale_id' => $row['sale_id'] !== null ? (int)$row['sale_id'] : null,
            'external_id' => $row['external_id'] ?? null,
            'gateway' => $row['gateway'] ?? 'asaas',
            'amount' => (float)$row['amount'],
            'platform_fee' => (float)$row['platform_fee'],
            'organizer_amount' => (float)$row['organizer_amount'],
            'status' => $row['status'],
            'billing_type' => $row['billing_type'],
            'pix_code' => $row['pix_code'] ?? null,
            'boleto_url' => $row['boleto_url'] ?? null,
            'due_date' => $row['due_date'] ?? null,
            'paid_at' => $row['paid_at'] ?? null,
            'idempotency_key' => $row['idempotency_key'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Resolve the Asaas API key for the given organizer.
     * Priority: organizer gateway credentials > environment variable.
     */
    private static function resolveAsaasApiKey(PDO $db, int $organizerId): string
    {
        // Try organizer-specific gateway first
        $gateway = self::findByProvider($db, $organizerId, 'asaas');
        if ($gateway) {
            $raw = self::getRawGateway($db, $organizerId, (int)$gateway['id']);
            if ($raw) {
                $decoded = self::decodeStoredCredentials($raw['credentials'] ?? []);
                $apiKey = trim((string)($decoded['secrets']['api_key'] ?? ''));
                if ($apiKey !== '') {
                    return $apiKey;
                }
            }
        }

        // Fallback to environment
        $envKey = trim((string)getenv('ASAAS_API_KEY'));
        if ($envKey === '') {
            throw new \RuntimeException('ASAAS_API_KEY nao configurado para o organizador nem no ambiente.');
        }

        return $envKey;
    }

    /**
     * Resolve the Asaas base URL based on the organizer's gateway environment setting.
     */
    private static function resolveAsaasBaseUrl(PDO $db, int $organizerId): string
    {
        $gateway = self::findByProvider($db, $organizerId, 'asaas');
        if ($gateway && ($gateway['environment'] ?? 'production') === 'sandbox') {
            return self::ASAAS_API_SANDBOX;
        }

        $env = strtolower(trim((string)getenv('ASAAS_ENVIRONMENT')));
        if ($env === 'sandbox') {
            return self::ASAAS_API_SANDBOX;
        }

        return self::ASAAS_API_PRODUCTION;
    }

    /**
     * Ensure a customer exists on Asaas. Creates if not found by CPF.
     */
    private static function ensureAsaasCustomer(string $apiKey, string $baseUrl, string $name, string $cpf, string $email): string
    {
        // Search existing customer by CPF
        $existing = self::asaasGet($apiKey, $baseUrl, '/customers?cpfCnpj=' . urlencode($cpf));
        if (!empty($existing['data']) && is_array($existing['data'])) {
            return (string)$existing['data'][0]['id'];
        }

        // Create new customer
        $customerPayload = [
            'name' => $name,
            'cpfCnpj' => $cpf,
        ];
        if ($email !== '') {
            $customerPayload['email'] = $email;
        }

        $response = self::asaasPost($apiKey, $baseUrl, '/customers', $customerPayload);
        if (!isset($response['id'])) {
            throw new \RuntimeException('Falha ao criar cliente no Asaas: ' . json_encode($response));
        }

        return (string)$response['id'];
    }

    /**
     * Map Asaas payment status to local status.
     */
    private static function mapAsaasStatus(string $asaasStatus): string
    {
        return match (strtoupper($asaasStatus)) {
            'PENDING' => 'pending',
            'CONFIRMED' => 'confirmed',
            'RECEIVED' => 'received',
            'OVERDUE' => 'overdue',
            'REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS' => 'refunded',
            'RECEIVED_IN_CASH' => 'received',
            'DELETED' => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * POST request to Asaas API.
     */
    private static function asaasPost(string $apiKey, string $baseUrl, string $path, array $data): array
    {
        $url = rtrim($baseUrl, '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'access_token: ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Erro de conexao com Asaas: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta invalida do Asaas (HTTP ' . $httpCode . '): ' . substr((string)$response, 0, 500));
        }

        if ($httpCode >= 400) {
            $errors = $decoded['errors'] ?? [];
            $msg = !empty($errors) ? json_encode($errors) : ($decoded['message'] ?? 'Erro desconhecido');
            throw new \RuntimeException('Asaas API error (HTTP ' . $httpCode . '): ' . $msg);
        }

        return $decoded;
    }

    /**
     * GET request to Asaas API.
     */
    private static function asaasGet(string $apiKey, string $baseUrl, string $path): array
    {
        $url = rtrim($baseUrl, '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'access_token: ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Erro de conexao com Asaas: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta invalida do Asaas (HTTP ' . $httpCode . '): ' . substr((string)$response, 0, 500));
        }

        if ($httpCode >= 400) {
            $errors = $decoded['errors'] ?? [];
            $msg = !empty($errors) ? json_encode($errors) : ($decoded['message'] ?? 'Erro desconhecido');
            throw new \RuntimeException('Asaas API error (HTTP ' . $httpCode . '): ' . $msg);
        }

        return $decoded;
    }

    /**
     * Parse a PostgreSQL text array literal into a PHP array.
     */
    private static function parsePgArray(string $pgArray): array
    {
        $pgArray = trim($pgArray);
        if ($pgArray === '{}' || $pgArray === '') {
            return [];
        }

        // Remove outer braces
        $inner = substr($pgArray, 1, -1);
        if ($inner === '' || $inner === false) {
            return [];
        }

        return array_map(function ($item) {
            return trim($item, '"');
        }, str_getcsv($inner));
    }

    /**
     * Convert a PHP array to a PostgreSQL text array literal.
     */
    private static function toPgArray(array $items): string
    {
        if (empty($items)) {
            return '{}';
        }

        $escaped = array_map(function ($item) {
            return '"' . str_replace('"', '\\"', (string)$item) . '"';
        }, $items);

        return '{' . implode(',', $escaped) . '}';
    }
}

