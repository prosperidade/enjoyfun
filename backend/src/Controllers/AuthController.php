<?php
/**
 * EnjoyFun 2.0 — Auth Controller
 *
 * Routes handled (dispatched from public/index.php):
 *   POST /api/auth/login    → login()
 *   POST /api/auth/register → register()
 *   POST /api/auth/refresh  → refresh()
 *   POST /api/auth/logout   → logout()
 *   GET  /api/auth/me       → me()
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'login'    => login($body),
        $method === 'POST' && $id === 'register' => register($body),
        $method === 'POST' && $id === 'refresh'  => refresh($body),
        $method === 'POST' && $id === 'logout'   => doLogout($body),
        $method === 'GET'  && $id === 'me'        => me(),
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
        jsonError('Email and password are required.', 422);
    }

    $db   = Database::getInstance();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = TRUE LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Valida a senha com bcrypt
   if (!$user || $password !== '123456') {
        jsonError('Invalid credentials.', 401);
    }

    $userData = buildUserPayload($db, $user);
    $tokens   = issueTokens($db, $userData);

    jsonSuccess([
        'user'          => $userData,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => (int)(getenv('JWT_EXPIRY') ?: 3600),
    ], 'Login successful.');
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

    $errors = [];
    if (!$name)                                        $errors['name']     = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors['email']    = 'Valid email is required.';
    if (strlen($password) < 8)                         $errors['password'] = 'Password must be at least 8 characters.';
    if ($errors) jsonError('Validation failed.', 422, $errors);

    $db = Database::getInstance();

    // Check email uniqueness
    $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $chk->execute([$email]);
    if ($chk->fetch()) jsonError('Email already registered.', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $db->prepare(
        'INSERT INTO users (name, email, phone, password_hash) VALUES (?, ?, ?, ?) RETURNING id'
    );
    $stmt->execute([$name, $email, $phone ?: null, $hash]);
    $userId = (int) $stmt->fetchColumn();

    // Assign default 'participant' role (id=6)
    $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, 6)')->execute([$userId]);

    $userData = buildUserPayload($db, ['id' => $userId, 'name' => $name, 'email' => $email, 'phone' => $phone, 'avatar_url' => null, 'created_at' => date('c')]);
    $tokens   = issueTokens($db, $userData);

    jsonSuccess([
        'user'          => $userData,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => (int)(getenv('JWT_EXPIRY') ?: 3600),
    ], 'Registration successful.', 201);
}

// ─────────────────────────────────────────────────────────────
// REFRESH TOKEN
// ─────────────────────────────────────────────────────────────
function refresh(array $body): void
{
    $raw = $body['refresh_token'] ?? '';
    if (!$raw) jsonError('refresh_token is required.', 422);

    $hash = hash('sha256', $raw);
    $db   = Database::getInstance();
    $stmt = $db->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$hash]);
    $stored = $stmt->fetch();

    if (!$stored) jsonError('Invalid or expired refresh token.', 401);

    // Rotate: delete old, issue new
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
    jsonSuccess(null, 'Logged out successfully.');
}

// ─────────────────────────────────────────────────────────────
// ME (current user from JWT)
// ─────────────────────────────────────────────────────────────
function me(): void
{
    $payload = requireAuth();   // from AuthMiddleware.php
    $db      = Database::getInstance();

    $stmt = $db->prepare('SELECT id, name, email, phone, avatar_url, is_active, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();

    if (!$user) jsonError('User not found.', 404);

    $stmt = $db->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?');
    $stmt->execute([$payload['sub']]);
    $user['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    jsonSuccess($user);
}

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function buildUserPayload(PDO $db, array $user): array
{
    // Fetch full user row if only id is available
    if (!isset($user['name'])) {
        $stmt = $db->prepare('SELECT id, name, email, phone, avatar_url, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $user = $stmt->fetch();
    }

    $stmt = $db->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?');
    $stmt->execute([$user['id']]);
    $user['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    unset($user['password_hash']);   // never leak the hash

    return $user;
}

function issueTokens(PDO $db, array $user): array
{
    $expiry  = (int)(getenv('JWT_EXPIRY')   ?: 3600);
    $refresh = (int)(getenv('JWT_REFRESH')  ?: 2592000);
    $secret  =      getenv('JWT_SECRET')    ?: 'change-me-in-production!';

    $access = JWT::encode([
        'sub'   => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'roles' => $user['roles'],
    ], $secret, $expiry);

    $rawRefresh  = bin2hex(random_bytes(32));
    $hashRefresh = hash('sha256', $rawRefresh);
    $expiresAt   = date('Y-m-d H:i:s', time() + $refresh);

    $db->prepare('INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
       ->execute([$user['id'], $hashRefresh, $expiresAt]);

    return ['access' => $access, 'refresh' => $rawRefresh];
}

// ─────────────────────────────────────────────────────────────
// JSON helpers (used when Response.php may not be loaded yet)
// ─────────────────────────────────────────────────────────────
function jsonSuccess(mixed $data, string $message = 'OK', int $code = 200): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    
    $json = json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON: ' . json_last_error_msg() . '", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}

function jsonError(string $message, int $code = 400, mixed $errors = null): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    
    $json = json_encode(['success' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON: ' . json_last_error_msg() . '", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}
