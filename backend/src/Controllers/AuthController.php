<?php
/**
 * EnjoyFun 2.0 — Auth Controller (White Label Ready)
 * Routes handled (dispatched from public/index.php)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'login'    => login($body),
        $method === 'POST' && $id === 'register' => register($body),
        $method === 'POST' && $id === 'refresh'  => refresh($body),
        $method === 'POST' && $id === 'logout'   => doLogout($body),
        $method === 'GET'  && $id === 'me'       => me(),
        default => jsonError("Auth endpoint not found: {$method} /auth/{$id}", 404),
    };
}

// ─────────────────────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────────────────────
function login(array $body): void
{
    $email    = trim($body['email']    ?? '');
    $password =      $body['password'] ?? '';

    if (!$email || !$password) {
        jsonError('Email e senha são obrigatórios.', 422);
    }

    $db   = Database::getInstance();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = TRUE LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'] ?? '')) {
        AuditService::logFailure(
            AuditService::USER_LOGIN_FAILED,
            'user',
            null,
            'Credenciais inválidas',
            null,
            ['metadata' => ['email_tentado' => $email]]
        );
        jsonError('Credenciais inválidas.', 401);
    }

    $userData = buildUserPayload($db, $user);
    $tokens   = issueTokens($db, $userData);

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

    if (!$stored) jsonError('Token de atualização inválido ou expirado.', 401);

    $db->prepare('DELETE FROM refresh_tokens WHERE id = ?')->execute([$stored['id']]);

    $userData = buildUserPayload($db, ['id' => $stored['user_id']]);
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
// ME (Current user)
// ─────────────────────────────────────────────────────────────
function me(): void
{
    $payload = requireAuth();
    $db      = Database::getInstance();

    // Puxa as colunas novas: role e organizer_id
    $stmt = $db->prepare('SELECT id, name, email, phone, avatar_url, is_active, organizer_id, role, created_at FROM users WHERE id = ? LIMIT 1');
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
        $stmt = $db->prepare('SELECT id, name, email, phone, avatar_url, organizer_id, role, created_at FROM users WHERE id = ? LIMIT 1');
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

    // RS256: a chave privada é carregada dentro do JWT::encode
    $access = JWT::encode([
        'sub'          => $user['id'],
        'name'         => $user['name'],
        'email'        => $user['email'],
        'roles'        => $user['roles'],
        'role'         => $user['role'],
        'organizer_id' => $user['organizer_id'] ?? null, // A CHAVE MESTRA DO WHITE LABEL!
    ], $expiry);

    $rawRefresh  = bin2hex(random_bytes(32));
    $hashRefresh = hash('sha256', $rawRefresh);
    $expiresAt   = date('Y-m-d H:i:s', time() + $refresh);

    $db->prepare('INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
       ->execute([$user['id'], $hashRefresh, $expiresAt]);

    return ['access' => $access, 'refresh' => $rawRefresh];
}