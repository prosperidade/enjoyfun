<?php
/**
 * EnjoyFun 2.0 — Auth Controller (White Label Ready)
 * Routes handled (dispatched from public/index.php)
 */

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

    if (!$email || !$password) {
        jsonError('Email e senha são obrigatórios.', 422);
    }

    $db   = Database::getInstance();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = TRUE LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── Diagnóstico detalhado no log do PHP ──────────────────────
    if (!$user) {
        error_log("[LOGIN FAIL] Usuário NÃO encontrado no banco para e-mail: {$email}");
    } else {
        error_log("[LOGIN] Usuário encontrado: id={$user['id']} role={$user['role']} is_active={$user['is_active']}");
        $hashOk = password_verify($password, $user['password'] ?? '');
        error_log("[LOGIN] password_verify resultado: " . ($hashOk ? 'OK' : 'FALHOU') . " | hash_len=" . strlen($user['password'] ?? ''));
    }

    $passwordOk = $user && password_verify($password, $user['password'] ?? '');

    if (!$user || !$passwordOk) {
        AuditService::logFailure(
            AuditService::USER_LOGIN_FAILED,
            'user',
            null,
            'Credenciais inválidas',
            null,
            ['metadata' => ['email_tentado' => $email, 'usuario_encontrado' => (bool)$user]]
        );
        jsonError('Credenciais inválidas.', 401);
    }

    $userData = buildUserPayload($db, $user);

    // ── Limpa refresh tokens antigos para evitar conflito ────────
    try {
        $db->prepare('DELETE FROM refresh_tokens WHERE user_id = ?')->execute([$userData['id']]);
    } catch (\Throwable $e) {
        error_log("[LOGIN] Aviso ao limpar refresh tokens: " . $e->getMessage());
    }

    $tokens = issueTokens($db, $userData);

    AuditService::log(
        AuditService::USER_LOGIN,
        'user',
        $userData['id'],
        null,
        ['email' => $userData['email'], 'role' => $userData['role']],
        ['sub' => $userData['id'], 'email' => $userData['email']],
        'success'
    );

    jsonSuccess([
        'user'          => $userData,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => (int)(getenv('JWT_EXPIRY') ?: 3600),
    ], 'Login realizado com sucesso.');
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

    jsonSuccess([
        'user'          => $userData,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => (int)(getenv('JWT_EXPIRY') ?: 3600),
    ], 'Registro realizado com sucesso.', 201);
}

// ─────────────────────────────────────────────────────────────
// REFRESH TOKEN
// ─────────────────────────────────────────────────────────────
function refresh(array $body): void
{
    $raw = $body['refresh_token'] ?? '';
    if (!$raw) jsonError('refresh_token é obrigatório.', 422);

    $hash = hash('sha256', $raw);
    $db   = Database::getInstance();
    $stmt = $db->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$hash]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stored) {
        AuditService::logFailure(
            AuditService::USER_LOGIN_FAILED,
            'refresh_token',
            null,
            'Refresh token inválido ou expirado',
            null
        );
        jsonError('Token de atualização inválido ou expirado.', 401);
    }

    $db->prepare('DELETE FROM refresh_tokens WHERE id = ?')->execute([$stored['id']]);

    $userData = buildUserPayload($db, ['id' => $stored['user_id']]);
    if (!$userData || empty($userData['id'])) {
        jsonError('Usuário do refresh token não encontrado.', 401);
    }
    $isActive = filter_var($userData['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($isActive === false) {
        jsonError('Usuário inativo.', 403);
    }

    $tokens   = issueTokens($db, $userData);

    jsonSuccess([
        'user'          => $userData,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => (int)(getenv('JWT_EXPIRY') ?: 3600),
    ]);
}

// ─────────────────────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────────────────────
function doLogout(array $body): void
{
    $raw = $body['refresh_token'] ?? '';
    if ($raw) {
        $hash = hash('sha256', $raw);
        Database::getInstance()->prepare('DELETE FROM refresh_tokens WHERE token_hash = ?')->execute([$hash]);
    }
    jsonSuccess(null, 'Logout realizado com sucesso.');
}

// ─────────────────────────────────────────────────────────────
// REQUEST ACCESS CODE (Passwordless Step 1)
// ─────────────────────────────────────────────────────────────
function requestAccessCode(array $body): void
{
    $identifier  = trim($body['identifier']   ?? '');
    $organizerId = (int)($body['organizer_id'] ?? 0);

    if (!$identifier || $organizerId <= 0) {
        jsonError('Identificador e organizer_id são obrigatórios.', 422);
    }

    // Gera OTP seguro de 6 dígitos
    $otp       = (string) random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutos

    try {
        $db = Database::getInstance();

        // Remove OTPs anteriores do mesmo identificador
        $db->prepare('DELETE FROM otp_codes WHERE identifier = ?')->execute([$identifier]);

        $stmt = $db->prepare('INSERT INTO otp_codes (identifier, code, expires_at, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$identifier, $otp, $expiresAt]);

        // ── Busca configurações de mensageria do organizador ──────
        $stmtCfg = $db->prepare('
            SELECT resend_api_key, email_sender, wa_api_url, wa_token, wa_instance
            FROM organizer_settings
            WHERE organizer_id = ?
            LIMIT 1
        ');
        $stmtCfg->execute([$organizerId]);
        $cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC) ?: [];

        // ── Dispatch real ─────────────────────────────────────────
        if (str_contains($identifier, '@')) {
            // E-MAIL via Resend
            $apiKey  = $cfg['resend_api_key'] ?? getenv('RESEND_API_KEY') ?: '';
            $fromEmail = $cfg['email_sender']  ?? getenv('EMAIL_SENDER')   ?: 'no-reply@enjoyfun.com.br';

            if ($apiKey) {
                require_once BASE_PATH . '/src/Services/EmailService.php';
                $GLOBALS['year'] = date('Y');
                $sent = \EnjoyFun\Services\EmailService::sendOTP($identifier, $otp, $apiKey, $fromEmail);
                if (!$sent) {
                    error_log("[OTP-EMAIL] Falha ao enviar via Resend para {$identifier}");
                }
            } else {
                // Fallback: log (sem chave configurada)
                error_log("[OTP-EMAIL-MOCK] Para: {$identifier} | Código: {$otp}");
            }
        } else {
            // WHATSAPP via Evolution API / Z-API
            $waUrl      = rtrim($cfg['wa_api_url'] ?? getenv('WA_API_URL')   ?: '', '/');
            $waToken    = $cfg['wa_token']    ?? getenv('WA_TOKEN')    ?: '';
            $waInstance = $cfg['wa_instance'] ?? getenv('WA_INSTANCE') ?: '';

            if ($waUrl && $waToken && $waInstance) {
                $phoneClean = preg_replace('/\D/', '', $identifier);
                // Garante formato internacional (55 + DDD + número)
                if (!str_starts_with($phoneClean, '55')) {
                    $phoneClean = '55' . $phoneClean;
                }

                $waPayload = json_encode([
                    'number'  => $phoneClean . '@s.whatsapp.net',
                    'options' => ['delay' => 1200, 'presence' => 'composing'],
                    'textMessage' => [
                        'text' => "🎟️ *EnjoyFun*\n\nSeu código de acesso é: *{$otp}*\n\nExpira em 10 minutos. Não compartilhe com ninguém.",
                    ],
                ]);

                $ch = curl_init("{$waUrl}/message/sendText/{$waInstance}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $waPayload,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        "apikey: {$waToken}",
                    ],
                    CURLOPT_TIMEOUT => 10,
                ]);
                $waResp   = curl_exec($ch);
                $waStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($waStatus < 200 || $waStatus >= 300) {
                    error_log("[OTP-WA] Falha HTTP {$waStatus}: {$waResp}");
                } else {
                    error_log("[OTP-WA] Enviado para {$identifier}");
                }
            } else {
                // Fallback: log (sem credenciais)
                error_log("[OTP-WA-MOCK] Para: {$identifier} | Código: {$otp}");
            }
        }

        jsonSuccess(['success' => true], 'Código enviado com sucesso.');
    } catch (Exception $e) {
        error_log('Erro ao gerar OTP: ' . $e->getMessage());
        jsonError('Erro interno ao enviar código.', 500);
    }
}

// ─────────────────────────────────────────────────────────────
// VERIFY ACCESS CODE (Passwordless Step 2 — Login/Cadastro)
// ─────────────────────────────────────────────────────────────
function verifyAccessCode(array $body): void
{
    $identifier  = trim($body['identifier']   ?? '');
    $code        = trim($body['code']         ?? '');
    $organizerId = (int)($body['organizer_id'] ?? 0);

    if (!$identifier || !$code || $organizerId <= 0) {
        jsonError('Identificador, código e organizer_id são obrigatórios.', 422);
    }

    try {
        $db = Database::getInstance();

        // 1. Busca e valida o OTP (deve existir e não ter expirado)
        $stmtOtp = $db->prepare('SELECT id FROM otp_codes WHERE identifier = ? AND code = ? AND expires_at > NOW() LIMIT 1');
        $stmtOtp->execute([$identifier, $code]);
        $otp = $stmtOtp->fetch(PDO::FETCH_ASSOC);

        if (!$otp) {
            jsonError('Código inválido ou expirado.', 401);
        }

        // 2. Invalida o OTP após uso (sem reuso possível)
        $db->prepare('DELETE FROM otp_codes WHERE id = ?')->execute([$otp['id']]);

        // 3. Determina se é e-mail ou telefone para a busca
        $isEmail = str_contains($identifier, '@');
        $field   = $isEmail ? 'email' : 'phone';

        // 4. Busca ou cria o usuário cliente
        $stmtUser = $db->prepare("SELECT * FROM users WHERE {$field} = ? AND organizer_id = ? LIMIT 1");
        $stmtUser->execute([$identifier, $organizerId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Novo cliente: cria automaticamente
            $stmtInsert = $db->prepare("
                INSERT INTO users (name, {$field}, role, organizer_id, is_active, created_at)
                VALUES (?, ?, 'customer', ?, true, NOW())
                RETURNING id
            ");
            $stmtInsert->execute(['Cliente', $identifier, $organizerId]);
            $newId = (int) $stmtInsert->fetchColumn();
            $user  = ['id' => $newId, 'name' => 'Cliente', $field => $identifier, 'role' => 'customer', 'organizer_id' => $organizerId];
        }

        // 5. Gera tokens JWT
        $userData = buildUserPayload($db, $user);
        $tokens   = issueTokens($db, $userData);

        jsonSuccess([
            'user'          => $userData,
            'access_token'  => $tokens['access'],
            'refresh_token' => $tokens['refresh'],
            'expires_in'    => (int)(getenv('JWT_EXPIRY') ?: 3600),
        ], 'Acesso concedido.');

    } catch (Exception $e) {
        error_log('Erro ao verificar OTP: ' . $e->getMessage());
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

function issueTokens(PDO $db, array $user): array
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

    $db->prepare('INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
       ->execute([$user['id'], $hashRefresh, $expiresAt]);

    return ['access' => $access, 'refresh' => $rawRefresh];
}
