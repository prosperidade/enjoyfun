<?php
/**
 * EnjoyFun 2.0 — Auth Controller
 * Routes:
 *   POST /api/auth/register
 *   POST /api/auth/login
 *   POST /api/auth/refresh
 *   POST /api/auth/logout
 *   GET  /api/auth/me
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();

    // Determine action from $id segment (e.g. /auth/login -> id='login')
    $action = $id ?? '';

    match (true) {
        $method === 'POST' && $action === 'register' => authRegister($db, $body),
        $method === 'POST' && $action === 'login'    => authLogin($db, $body),
        $method === 'POST' && $action === 'refresh'  => authRefresh($db, $body),
        $method === 'POST' && $action === 'logout'   => authLogout($db, $body),
        $method === 'GET'  && $action === 'me'       => authMe($db),
        default => Response::error("Auth endpoint '$action' not found.", 404),
    };
}

// ── Register ──────────────────────────────────────────────────────────────────
function authRegister(PDO $db, array $body): void
{
    $name     = trim($body['name']     ?? '');
    $email    = trim($body['email']    ?? '');
    $phone    = trim($body['phone']    ?? '');
    $password = $body['password']      ?? '';

    $errors = [];
    if (!$name)                         $errors['name']     = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Valid email required.';
    if (strlen($password) < 8)          $errors['password'] = 'Password must be at least 8 characters.';

    if ($errors) Response::error('Validation failed.', 422, $errors);

    // Check duplicate email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) Response::error('Email already registered.', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $db->prepare('INSERT INTO users (name, email, phone, password_hash) VALUES (?,?,?,?)');
    $stmt->execute([$name, $email ?: null, $phone ?: null, $hash]);
    $userId = (int) $db->lastInsertId();

    // Assign default 'participant' role (id = 6)
    $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,6)')->execute([$userId]);

    $user = fetchUser($db, $userId);
    $tokens = generateTokens($db, $user);

    Response::success([
        'user'          => $user,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => JWT_EXPIRY,
    ], 'Registration successful.', 201);
}

// ── Login ─────────────────────────────────────────────────────────────────────
function authLogin(PDO $db, array $body): void
{
    $email    = trim($body['email']    ?? '');
    $password = $body['password']      ?? '';

    if (!$email || !$password) Response::error('Email and password required.', 422);

    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        Response::error('Invalid credentials.', 401);
    }

    $userData = fetchUser($db, $user['id']);
    $tokens   = generateTokens($db, $userData);

    Response::success([
        'user'          => $userData,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => JWT_EXPIRY,
    ], 'Login successful.');
}

// ── Refresh ───────────────────────────────────────────────────────────────────
function authRefresh(PDO $db, array $body): void
{
    $refreshToken = $body['refresh_token'] ?? '';
    if (!$refreshToken) Response::error('Refresh token required.', 422);

    $tokenHash = hash('sha256', $refreshToken);
    $stmt = $db->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$tokenHash]);
    $stored = $stmt->fetch();

    if (!$stored) Response::error('Invalid or expired refresh token.', 401);

    $userData = fetchUser($db, $stored['user_id']);
    $tokens   = generateTokens($db, $userData);

    // Invalidate old refresh token
    $db->prepare('DELETE FROM refresh_tokens WHERE id = ?')->execute([$stored['id']]);

    Response::success([
        'user'          => $userData,
        'access_token'  => $tokens['access'],
        'refresh_token' => $tokens['refresh'],
        'expires_in'    => JWT_EXPIRY,
    ]);
}

// ── Logout ────────────────────────────────────────────────────────────────────
function authLogout(PDO $db, array $body): void
{
    $tokenHash = hash('sha256', $body['refresh_token'] ?? '');
    $db->prepare('DELETE FROM refresh_tokens WHERE token_hash = ?')->execute([$tokenHash]);
    Response::success(null, 'Logged out.');
}

// ── Me ────────────────────────────────────────────────────────────────────────
function authMe(PDO $db): void
{
    $payload = requireAuth();
    $user    = fetchUser($db, $payload['sub']);
    if (!$user) Response::error('User not found.', 404);
    Response::success($user);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fetchUser(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT id, name, email, phone, avatar_url, is_active, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) return null;

    $stmt = $db->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?');
    $stmt->execute([$id]);
    $user['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $user;
}

function generateTokens(PDO $db, array $user): array
{
    $payload = [
        'sub'   => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'roles' => $user['roles'],
    ];

    $access  = JWT::encode($payload, JWT_SECRET, JWT_EXPIRY);

    // Refresh token: random bytes stored as hash
    $raw     = bin2hex(random_bytes(32));
    $hash    = hash('sha256', $raw);
    $expires = date('Y-m-d H:i:s', time() + JWT_REFRESH);

    $db->prepare('INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?,?,?)')
       ->execute([$user['id'], $hash, $expires]);

    return ['access' => $access, 'refresh' => $raw];
}
