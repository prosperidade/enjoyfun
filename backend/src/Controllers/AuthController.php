<?php
/**
 * EnjoyFun 2.0 — Auth Controller (White Label Ready)
 * Routes handled (dispatched from public/index.php)
 */

require_once BASE_PATH . '/src/Services/OrganizerMessagingConfigService.php';
require_once BASE_PATH . '/src/Services/EventLookupService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'login'        => login($body),
        $method === 'POST' && $id === 'register'     => register($body),
        $method === 'POST' && $id === 'refresh'      => refresh($body),
        $method === 'POST' && $id === 'logout'       => doLogout($body),
        $method === 'GET'  && $id === 'me'           => me(),
        $method === 'POST' && $id === 'request-code' => requestAccessCode($body),
        $method === 'POST' && $id === 'verify-code'  => verifyAccessCode($body),
        default => jsonError("Auth endpoint not found: {$method} /auth/{$id}", 404),
    };
}

// ─────────────────────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────────────────────
function login(array $body): void
{
    $email    = strtolower(trim($body['email'] ?? ''));
    $password =      $body['password'] ?? '';
    $correlationId = authCorrelationId();

    if (!$email || !$password) {
        jsonError('Email e senha são obrigatórios.', 422);
    }

    $db   = Database::getInstance();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = TRUE LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $passwordOk = $user && password_verify($password, $user['password'] ?? '');

    if (!$user || !$passwordOk) {
        error_log("[AUTH][{$correlationId}] Falha de login.");
        AuditService::logFailure(
            AuditService::USER_LOGIN_FAILED,
            'user',
            null,
            'Credenciais inválidas',
            null,
            ['metadata' => ['correlation_id' => $correlationId]]
        );
        jsonError('Credenciais inválidas.', 401);
    }

    $userData = buildUserPayload($db, $user);

    $tokens = issueTokens($db, $userData);

    AuditService::log(
        AuditService::USER_LOGIN,
        'user',
        $userData['id'],
        null,
        ['email' => $userData['email'], 'role' => $userData['role'], 'correlation_id' => $correlationId],
        ['sub' => $userData['id'], 'email' => $userData['email']],
        'success'
    );

    authRespondWithTokens($userData, $tokens, 'Login realizado com sucesso.');
}

// ─────────────────────────────────────────────────────────────
// REGISTER
// ─────────────────────────────────────────────────────────────
function register(array $body): void
{
    $name     = trim($body['name']     ?? '');
    $email    = trim($body['email']    ?? '');
    $password =      $body['password'] ?? '';
    $phone    = trim($body['phone']    ?? '');
    $cpf      = trim($body['cpf']      ?? '');

    $errors = [];
    if (!$name) $errors['name'] = 'Nome é obrigatório.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email inválido.';
    if (strlen($password) < 8) $errors['password'] = 'A senha deve ter pelo menos 8 caracteres.';
    if (!$phone) $errors['phone'] = 'Telefone é obrigatório.';
    if (!$cpf)   $errors['cpf']   = 'CPF é obrigatório.';

    if ($errors) jsonError('Falha na validação.', 422, $errors);

    $db = Database::getInstance();

    $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $chk->execute([$email]);
    if ($chk->fetch()) jsonError('Este email já está registrado.', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Organizer: auto-registro público cria conta como 'organizer'
    $stmt = $db->prepare('
        INSERT INTO users (name, email, phone, cpf, password, role, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, true, NOW())
        RETURNING id
    ');
    $stmt->execute([$name, $email, $phone, $cpf, $hash, 'organizer']);
    $userId = (int) $stmt->fetchColumn();

    $userData = buildUserPayload($db, ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'organizer']);
    $tokens   = issueTokens($db, $userData);

    authRespondWithTokens($userData, $tokens, 'Registro realizado com sucesso.', 201);
}

// ─────────────────────────────────────────────────────────────
// REFRESH TOKEN
// ─────────────────────────────────────────────────────────────
function refresh(array $body): void
{
    $raw = trim((string)($body['refresh_token'] ?? authRefreshTokenFromCookie()));
    $correlationId = authCorrelationId();
    if (!$raw) jsonError('refresh_token é obrigatório.', 422);

    $hash = hash('sha256', $raw);
    $db   = Database::getInstance();
    $schema = authRefreshTokenSchema($db);
    $query = 'SELECT * FROM refresh_tokens WHERE token_hash = ? AND expires_at > NOW()';
    if (!empty($schema['tracking'])) {
        $query .= ' AND revoked_at IS NULL';
    }
    $query .= ' ORDER BY id DESC LIMIT 1';
    $stmt = $db->prepare($query);
    $stmt->execute([$hash]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stored) {
        error_log("[AUTH][{$correlationId}] Refresh token inválido.");
        AuditService::logFailure(
            AuditService::USER_LOGIN_FAILED,
            'refresh_token',
            null,
            'Refresh token inválido ou expirado',
            null
            ,
            ['metadata' => ['correlation_id' => $correlationId]]
        );
        jsonError('Token de atualização inválido ou expirado.', 401);
    }

    authRevokeRefreshToken($db, (int)$stored['id']);

    $userData = buildUserPayload($db, ['id' => $stored['user_id']]);
    if (!$userData || empty($userData['id'])) {
        jsonError('Usuário do refresh token não encontrado.', 401);
    }
    $isActive = filter_var($userData['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($isActive === false) {
        jsonError('Usuário inativo.', 403);
    }

    $tokens   = issueTokens($db, $userData, authRefreshContext([
        'session_id' => $stored['session_id'] ?? null,
        'device_id' => $stored['device_id'] ?? null,
        'user_agent' => $stored['user_agent'] ?? null,
        'ip_address' => $stored['ip_address'] ?? null,
    ]));

    authRespondWithTokens($userData, $tokens);
}

// ─────────────────────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────────────────────
function doLogout(array $body): void
{
    $raw = trim((string)($body['refresh_token'] ?? authRefreshTokenFromCookie()));
    if ($raw) {
        $hash = hash('sha256', $raw);
        $db = Database::getInstance();
        $schema = authRefreshTokenSchema($db);
        if (!empty($schema['tracking'])) {
            $db->prepare('
                UPDATE refresh_tokens
                SET revoked_at = COALESCE(revoked_at, NOW()),
                    last_used_at = CASE WHEN last_used_at IS NULL THEN NOW() ELSE last_used_at END
                WHERE token_hash = ? AND revoked_at IS NULL
            ')->execute([$hash]);
        } else {
            $db->prepare('DELETE FROM refresh_tokens WHERE token_hash = ?')->execute([$hash]);
        }
    }
    authClearAccessCookie();
    authClearRefreshCookie();
    jsonSuccess(null, 'Logout realizado com sucesso.');
}

function resolveOtpSecret(): string
{
    $secret = trim((string)(
        getenv('OTP_PEPPER')
        ?: getenv('JWT_SECRET')
        ?: getenv('APP_KEY')
        ?: ''
    ));

    if ($secret === '') {
        throw new RuntimeException('OTP secret is not configured.');
    }

    return $secret;
}

function hashOtpCode(string $identifier, string $code): string
{
    return hash_hmac('sha256', strtolower(trim($identifier)) . '|' . trim($code), resolveOtpSecret());
}

function isDevelopmentEnvironment(): bool
{
    return strtolower(trim((string)(getenv('APP_ENV') ?: $_ENV['APP_ENV'] ?? 'development'))) === 'development';
}

function authShouldLogOtpMockCode(): bool
{
    $raw = strtolower(trim((string)(getenv('AUTH_LOG_OTP_MOCK_CODE') ?: '0')));
    return in_array($raw, ['1', 'true', 'on', 'yes'], true);
}

function maskOtpDestination(string $identifier): string
{
    $value = trim($identifier);
    if ($value === '') {
        return '';
    }

    if (str_contains($value, '@')) {
        [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
        $local = strlen($local) <= 2 ? str_repeat('*', strlen($local)) : substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 0));
        return $local . ($domain !== '' ? '@' . $domain : '');
    }

    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return '***';
    }

    if (strlen($digits) <= 4) {
        return str_repeat('*', strlen($digits));
    }

    return str_repeat('*', max(strlen($digits) - 4, 0)) . substr($digits, -4);
}

function deleteOtpById(PDO $db, ?int $otpId): void
{
    if (($otpId ?? 0) <= 0) {
        return;
    }

    $db->prepare('DELETE FROM otp_codes WHERE id = ?')->execute([(int)$otpId]);
}

function deliverOtpCode(string $identifier, string $otp, array $cfg): array
{
    if (str_contains($identifier, '@')) {
        $apiKey = $cfg['resend_api_key'] ?? getenv('RESEND_API_KEY') ?: '';
        $fromEmail = $cfg['email_sender'] ?? getenv('EMAIL_SENDER') ?: 'no-reply@enjoyfun.com.br';

        if ($apiKey) {
            require_once BASE_PATH . '/src/Services/EmailService.php';
            $sent = \EnjoyFun\Services\EmailService::sendOTP($identifier, $otp, $apiKey, $fromEmail);
            if (!$sent) {
                throw new RuntimeException('Falha ao enviar codigo por e-mail.');
            }

            return ['channel' => 'email', 'delivery_status' => 'sent'];
        }

        if (!isDevelopmentEnvironment()) {
            throw new RuntimeException('Canal de e-mail indisponivel para envio de codigo.');
        }

        $masked = maskOtpDestination($identifier);
        $suffix = authShouldLogOtpMockCode() ? " | Código: {$otp}" : '';
        error_log("[OTP-EMAIL-MOCK] Destino: {$masked}{$suffix}");
        return ['channel' => 'email', 'delivery_status' => 'mocked'];
    }

    $waUrl = rtrim($cfg['wa_api_url'] ?? getenv('WA_API_URL') ?: '', '/');
    $waToken = $cfg['wa_token'] ?? getenv('WA_TOKEN') ?: '';
    $waInstance = $cfg['wa_instance'] ?? getenv('WA_INSTANCE') ?: '';

    if ($waUrl && $waToken && $waInstance) {
        $phoneClean = preg_replace('/\D/', '', $identifier);
        if (!str_starts_with($phoneClean, '55')) {
            $phoneClean = '55' . $phoneClean;
        }

        $waPayload = json_encode([
            'number' => $phoneClean . '@s.whatsapp.net',
            'options' => ['delay' => 1200, 'presence' => 'composing'],
            'textMessage' => [
                'text' => "🎟️ *EnjoyFun*\n\nSeu código de acesso é: *{$otp}*\n\nExpira em 10 minutos. Não compartilhe com ninguém.",
            ],
        ]);

        $ch = curl_init("{$waUrl}/message/sendText/{$waInstance}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $waPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "apikey: {$waToken}",
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $waResp = curl_exec($ch);
        $waStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($waStatus < 200 || $waStatus >= 300) {
            error_log("[OTP-WA] Falha HTTP {$waStatus}: {$waResp}");
            throw new RuntimeException('Falha ao enviar codigo por WhatsApp.');
        }

        error_log("[OTP-WA] Enviado para {$identifier}");
        return ['channel' => 'whatsapp', 'delivery_status' => 'sent'];
    }

    if (!isDevelopmentEnvironment()) {
        throw new RuntimeException('Canal de WhatsApp indisponivel para envio de codigo.');
    }

    $masked = maskOtpDestination($identifier);
    $suffix = authShouldLogOtpMockCode() ? " | Código: {$otp}" : '';
    error_log("[OTP-WA-MOCK] Destino: {$masked}{$suffix}");
    return ['channel' => 'whatsapp', 'delivery_status' => 'mocked'];
}

function otpMatchesStoredCode(string $identifier, string $plainCode, string $storedCode): bool
{
    $candidateHash = hashOtpCode($identifier, $plainCode);
    if (strlen($storedCode) === 64 && ctype_xdigit($storedCode)) {
        return hash_equals(strtolower($storedCode), strtolower($candidateHash));
    }

    return hash_equals((string)$storedCode, trim($plainCode));
}

function resolveCustomerAuthScope(PDO $db, array $body): array
{
    $organizerId = (int)($body['organizer_id'] ?? 0);
    $eventId = (int)($body['event_id'] ?? 0);
    $eventSlug = trim((string)($body['event_slug'] ?? $body['slug'] ?? ''));

    if ($eventId > 0 || $eventSlug !== '') {
        $event = EventLookupService::resolvePublicEvent(
            $db,
            $eventId > 0 ? $eventId : null,
            $eventSlug !== '' ? $eventSlug : null
        );
        if (!$event) {
            throw new RuntimeException('Evento inválido para autenticação do cliente.', 404);
        }

        return [
            'organizer_id' => (int)($event['organizer_id'] ?? 0),
            'event_id' => (int)($event['id'] ?? 0),
            'event_slug' => (string)($event['slug'] ?? ''),
        ];
    }

    if ($organizerId <= 0) {
        throw new RuntimeException('Identificador e organizer_id/evento são obrigatórios.', 422);
    }

    return [
        'organizer_id' => $organizerId,
        'event_id' => null,
        'event_slug' => null,
    ];
}

function buildOtpCustomerPlaceholderEmail(string $identifier, int $organizerId): string
{
    $normalized = preg_replace('/\D+/', '', $identifier) ?? '';
    if ($normalized === '') {
        $normalized = substr(sha1(strtolower(trim($identifier))), 0, 16);
    }

    return sprintf('customer-org%d-%s@otp.enjoyfun.local', $organizerId, $normalized);
}

function buildOtpCustomerPasswordHash(): string
{
    return password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
}

// ─────────────────────────────────────────────────────────────
// REQUEST ACCESS CODE (Passwordless Step 1)
// ─────────────────────────────────────────────────────────────
function requestAccessCode(array $body): void
{
    $identifier  = trim($body['identifier']   ?? '');
    if (!$identifier) {
        jsonError('Identificador é obrigatório.', 422);
    }

    $otp = (string) random_int(100000, 999999);
    $otpHash = hashOtpCode($identifier, $otp);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);
    $otpId = null;

    try {
        $db = Database::getInstance();
        $scope = resolveCustomerAuthScope($db, $body);
        $organizerId = (int)($scope['organizer_id'] ?? 0);
        if ($organizerId <= 0) {
            jsonError('Organizer inválido para autenticação do cliente.', 422);
        }
        if (!empty($scope['event_id']) && function_exists('setCurrentRequestEventId')) {
            setCurrentRequestEventId((int)$scope['event_id']);
        }
        $db->prepare('DELETE FROM otp_codes WHERE identifier = ?')->execute([$identifier]);

        $stmt = $db->prepare('INSERT INTO otp_codes (identifier, code, expires_at, created_at) VALUES (?, ?, ?, NOW()) RETURNING id');
        $stmt->execute([$identifier, $otpHash, $expiresAt]);
        $otpId = (int)$stmt->fetchColumn();

        $cfg = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $organizerId);

        $delivery = deliverOtpCode($identifier, $otp, $cfg);

        jsonSuccess([
            'success' => true,
            'delivery_status' => $delivery['delivery_status'] ?? 'sent',
            'channel' => $delivery['channel'] ?? (str_contains($identifier, '@') ? 'email' : 'whatsapp'),
        ], 'Código enviado com sucesso.');
    } catch (Exception $e) {
        if ($otpId !== null) {
            try {
                deleteOtpById($db ?? Database::getInstance(), $otpId);
            } catch (Throwable $cleanupError) {
                error_log('Falha ao limpar OTP apos erro de envio: ' . $cleanupError->getMessage());
            }
        }
        error_log('Erro ao gerar OTP: ' . $e->getMessage());
        $status = (int)$e->getCode();
        if ($status >= 400 && $status < 600) {
            jsonError($e->getMessage(), $status);
        }
        jsonError('Nao foi possivel enviar o codigo agora. Tente novamente.', 503);
    }
}

// ─────────────────────────────────────────────────────────────
// VERIFY ACCESS CODE (Passwordless Step 2 — Login/Cadastro)
// ─────────────────────────────────────────────────────────────
function verifyAccessCode(array $body): void
{
    $identifier  = trim($body['identifier']   ?? '');
    $code        = trim($body['code']         ?? '');
    if (!$identifier || !$code) {
        jsonError('Identificador e código são obrigatórios.', 422);
    }

    try {
        $db = Database::getInstance();
        $scope = resolveCustomerAuthScope($db, $body);
        $organizerId = (int)($scope['organizer_id'] ?? 0);
        if ($organizerId <= 0) {
            jsonError('Organizer inválido para autenticação do cliente.', 422);
        }
        if (!empty($scope['event_id']) && function_exists('setCurrentRequestEventId')) {
            setCurrentRequestEventId((int)$scope['event_id']);
        }

        $stmtOtp = $db->prepare('SELECT id, code FROM otp_codes WHERE identifier = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 5');
        $stmtOtp->execute([$identifier]);
        $otpRows = $stmtOtp->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $otp = null;
        foreach ($otpRows as $row) {
            if (otpMatchesStoredCode($identifier, $code, (string)($row['code'] ?? ''))) {
                $otp = $row;
                break;
            }
        }

        if (!$otp) {
            jsonError('Código inválido ou expirado.', 401);
        }

        deleteOtpById($db, (int)($otp['id'] ?? 0));

        $isEmail = str_contains($identifier, '@');
        $field   = $isEmail ? 'email' : 'phone';

        $stmtUser = $db->prepare("SELECT * FROM users WHERE {$field} = ? AND organizer_id = ? LIMIT 1");
        $stmtUser->execute([$identifier, $organizerId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $email = $isEmail ? $identifier : buildOtpCustomerPlaceholderEmail($identifier, $organizerId);
            $phone = $isEmail ? null : $identifier;
            $passwordHash = buildOtpCustomerPasswordHash();
            $stmtInsert = $db->prepare("
                INSERT INTO users (name, email, password, phone, role, organizer_id, is_active, created_at)
                VALUES (?, ?, ?, ?, 'customer', ?, true, NOW())
                RETURNING id
            ");
            $stmtInsert->execute(['Cliente', $email, $passwordHash, $phone, $organizerId]);
            $newId = (int) $stmtInsert->fetchColumn();
            $user  = [
                'id' => $newId,
                'name' => 'Cliente',
                'email' => $email,
                'phone' => $phone,
                'role' => 'customer',
                'organizer_id' => $organizerId,
            ];
        }

        $userData = buildUserPayload($db, $user);
        $tokens   = issueTokens($db, $userData);

        authRespondWithTokens($userData, $tokens, 'Acesso concedido.');

    } catch (Exception $e) {
        error_log('Erro ao verificar OTP: ' . $e->getMessage());
        $status = (int)$e->getCode();
        if ($status >= 400 && $status < 600) {
            jsonError($e->getMessage(), $status);
        }
        jsonError('Erro interno ao verificar código.', 500);
    }
}

// ─────────────────────────────────────────────────────────────
// ME (Current user)
// ─────────────────────────────────────────────────────────────
function me(): void
{
    $payload = requireAuth();
    $db      = Database::getInstance();

    // Puxa as colunas novas: role e organizer_id
    $stmt = $db->prepare('SELECT id, name, email, phone, avatar_url, is_active, organizer_id, role, sector, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$payload['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) jsonError('Usuário não encontrado.', 404);

    // Mantém o array 'roles' para compatibilidade com o frontend antigo, mas usando a coluna real
    $user['roles'] = [$user['role']];

    jsonSuccess($user);
}

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function buildUserPayload(PDO $db, array $user): array
{
    // Se faltarem dados essenciais, busca no banco
    if (!isset($user['name']) || !isset($user['role'])) {
        $stmt = $db->prepare('SELECT id, name, email, phone, avatar_url, organizer_id, role, sector, is_active, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // A role vem direto da coluna `role` da tabela `users`
    $user['role'] = $user['role'] ?? 'customer'; 
    $user['roles'] = [$user['role']]; // Backwards compatibility com React

    unset($user['password_hash']);
    unset($user['password']);

    return $user;
}

function issueTokens(PDO $db, array $user, array $context = []): array
{
    $expiry  = (int)(getenv('JWT_EXPIRY')  ?: 3600);
    $refresh = (int)(getenv('JWT_REFRESH') ?: 2592000);

    // HS256: a chave simétrica é usada no HMAC via getenv('JWT_SECRET')
    $access = JWT::encode([
        'sub'          => $user['id'],
        'name'         => $user['name'],
        'email'        => $user['email'],
        'roles'        => $user['roles'],
        'role'         => $user['role'],
        'sector'       => $user['sector'] ?? 'all',
        'organizer_id' => $user['organizer_id'] ?? null, // A CHAVE MESTRA DO WHITE LABEL!
    ], $expiry);

    $rawRefresh  = bin2hex(random_bytes(32));
    $hashRefresh = hash('sha256', $rawRefresh);
    $expiresAt   = date('Y-m-d H:i:s', time() + $refresh);
    $refreshContext = authRefreshContext($context);
    $schema = authRefreshTokenSchema($db);

    if (!empty($schema['tracking'])) {
        $db->prepare('
            INSERT INTO refresh_tokens (
                user_id, token_hash, expires_at, session_id, device_id, user_agent, ip_address, last_used_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ')->execute([
            $user['id'],
            $hashRefresh,
            $expiresAt,
            $refreshContext['session_id'],
            $refreshContext['device_id'],
            $refreshContext['user_agent'] !== '' ? $refreshContext['user_agent'] : null,
            $refreshContext['ip_address'] !== '' ? $refreshContext['ip_address'] : null,
        ]);
    } else {
        $db->prepare('INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
           ->execute([$user['id'], $hashRefresh, $expiresAt]);
    }

    return ['access' => $access, 'refresh' => $rawRefresh];
}

function authCorrelationId(): string
{
    if (function_exists('generateCorrelationId')) {
        return (string)generateCorrelationId();
    }

    try {
        return bin2hex(random_bytes(8));
    } catch (\Throwable $e) {
        return substr(md5((string)microtime(true)), 0, 16);
    }
}

function authRespondWithTokens(array $userData, array $tokens, string $message = '', int $code = 200): never
{
    if (authShouldUseAccessCookie()) {
        authSetAccessCookie((string)($tokens['access'] ?? ''));
    }

    if (authShouldUseRefreshCookie()) {
        authSetRefreshCookie((string)($tokens['refresh'] ?? ''));
    }

    jsonSuccess([
        'user' => $userData,
        'access_token' => authShouldUseAccessCookie() ? '' : $tokens['access'],
        'access_transport' => authShouldUseAccessCookie() ? 'cookie' : 'body',
        'refresh_token' => authShouldUseRefreshCookie() ? '' : $tokens['refresh'],
        'refresh_transport' => authShouldUseRefreshCookie() ? 'cookie' : 'body',
        'expires_in' => (int)(getenv('JWT_EXPIRY') ?: 3600),
    ], $message, $code);
}

function authRefreshContext(array $overrides = []): array
{
    $deviceId = trim((string)($overrides['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? 'browser')));
    if ($deviceId === '') {
        $deviceId = 'browser';
    }

    $sessionId = trim((string)($overrides['session_id'] ?? ''));
    if ($sessionId === '') {
        try {
            $sessionId = 'sess_' . bin2hex(random_bytes(12));
        } catch (\Throwable $e) {
            $sessionId = 'sess_' . substr(md5((string)microtime(true)), 0, 24);
        }
    }

    return [
        'session_id' => $sessionId,
        'device_id' => $deviceId,
        'user_agent' => trim((string)($overrides['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))),
        'ip_address' => trim((string)($overrides['ip_address'] ?? authClientIp())),
    ];
}

function authRefreshTokenSchema(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'session_id' => authRefreshTokenColumnExists($db, 'session_id'),
        'device_id' => authRefreshTokenColumnExists($db, 'device_id'),
        'user_agent' => authRefreshTokenColumnExists($db, 'user_agent'),
        'ip_address' => authRefreshTokenColumnExists($db, 'ip_address'),
        'last_used_at' => authRefreshTokenColumnExists($db, 'last_used_at'),
        'revoked_at' => authRefreshTokenColumnExists($db, 'revoked_at'),
    ];
    $cache['tracking'] = $cache['session_id']
        && $cache['device_id']
        && $cache['user_agent']
        && $cache['ip_address']
        && $cache['last_used_at']
        && $cache['revoked_at'];

    return $cache;
}

function authRefreshTokenColumnExists(PDO $db, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'refresh_tokens'
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':column' => $column]);
    $cache[$column] = (bool)$stmt->fetchColumn();

    return $cache[$column];
}

function authRevokeRefreshToken(PDO $db, int $refreshTokenId): void
{
    if ($refreshTokenId <= 0) {
        return;
    }

    $schema = authRefreshTokenSchema($db);
    if (!empty($schema['tracking'])) {
        $db->prepare('
            UPDATE refresh_tokens
            SET revoked_at = COALESCE(revoked_at, NOW()),
                last_used_at = CASE WHEN last_used_at IS NULL THEN NOW() ELSE last_used_at END
            WHERE id = ?
        ')->execute([$refreshTokenId]);
        return;
    }

    $db->prepare('DELETE FROM refresh_tokens WHERE id = ?')->execute([$refreshTokenId]);
}

function authClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return trim(explode(',', $value)[0]);
        }
    }

    return '';
}

function authShouldUseRefreshCookie(): bool
{
    $raw = strtolower(trim((string)(getenv('AUTH_REFRESH_COOKIE_MODE') ?: '1')));
    return !in_array($raw, ['0', 'false', 'off', 'no'], true);
}

function authShouldUseAccessCookie(): bool
{
    $raw = strtolower(trim((string)(getenv('AUTH_ACCESS_COOKIE_MODE') ?: '0')));
    return !in_array($raw, ['0', 'false', 'off', 'no'], true);
}

function authAccessCookieName(): string
{
    $name = trim((string)(getenv('AUTH_ACCESS_COOKIE_NAME') ?: 'enjoyfun_access_token'));
    return $name !== '' ? $name : 'enjoyfun_access_token';
}

function authRefreshCookieName(): string
{
    $name = trim((string)(getenv('AUTH_REFRESH_COOKIE_NAME') ?: 'enjoyfun_refresh_token'));
    return $name !== '' ? $name : 'enjoyfun_refresh_token';
}

function authAccessTokenFromCookie(): string
{
    if (!authShouldUseAccessCookie()) {
        return '';
    }

    return trim((string)($_COOKIE[authAccessCookieName()] ?? ''));
}

function authRefreshCookieFromGlobals(): string
{
    return trim((string)($_COOKIE[authRefreshCookieName()] ?? ''));
}

function authRefreshTokenFromCookie(): string
{
    if (!authShouldUseRefreshCookie()) {
        return '';
    }

    return authRefreshCookieFromGlobals();
}

function authRefreshCookieSameSite(): string
{
    $sameSite = trim((string)(getenv('AUTH_COOKIE_SAMESITE') ?: 'Lax'));
    $sameSite = ucfirst(strtolower($sameSite));
    return in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax';
}

function authCookieIsSecure(): bool
{
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    return strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

function authRefreshCookieOptions(int $expiresAt): array
{
    $options = [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => authCookieIsSecure() || authRefreshCookieSameSite() === 'None',
        'httponly' => true,
        'samesite' => authRefreshCookieSameSite(),
    ];

    $domain = trim((string)(getenv('AUTH_COOKIE_DOMAIN') ?: ''));
    if ($domain !== '') {
        $options['domain'] = $domain;
    }

    return $options;
}

function authSetRefreshCookie(string $refreshToken): void
{
    if (!authShouldUseRefreshCookie() || $refreshToken === '') {
        return;
    }

    setcookie(
        authRefreshCookieName(),
        $refreshToken,
        authRefreshCookieOptions(time() + (int)(getenv('JWT_REFRESH') ?: 2592000))
    );
    $_COOKIE[authRefreshCookieName()] = $refreshToken;
}

function authSetAccessCookie(string $accessToken): void
{
    if (!authShouldUseAccessCookie() || $accessToken === '') {
        return;
    }

    setcookie(
        authAccessCookieName(),
        $accessToken,
        authRefreshCookieOptions(time() + (int)(getenv('JWT_EXPIRY') ?: 3600))
    );
    $_COOKIE[authAccessCookieName()] = $accessToken;
}

function authClearAccessCookie(): void
{
    setcookie(authAccessCookieName(), '', authRefreshCookieOptions(time() - 3600));
    unset($_COOKIE[authAccessCookieName()]);
}

function authClearRefreshCookie(): void
{
    setcookie(authRefreshCookieName(), '', authRefreshCookieOptions(time() - 3600));
    unset($_COOKIE[authRefreshCookieName()]);
}
