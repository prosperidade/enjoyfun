<?php
/**
 * EnjoyFun 2.0 — User Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();
    match (true) {
        !$id && $method === 'GET'            => listUsers($db, $query),
        $id && $method === 'GET'             => getUser($db, (int)$id),
        $id && in_array($method,['PUT','PATCH']) => updateUser($db, (int)$id, $body),
        $id && $method === 'DELETE'          => deleteUser($db, (int)$id),
        default => Response::error('Route not found.', 404),
    };
}

function listUsers(PDO $db, array $q): void
{
    requireAuth(['admin']);
    $page    = max(1, (int)($q['page'] ?? 1));
    $perPage = min(100, (int)($q['per_page'] ?? 20));
    $offset  = ($page - 1) * $perPage;

    $stmt = $db->prepare('SELECT COUNT(*) FROM users');
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT id,name,email,phone,is_active,created_at FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$perPage, $offset]);
    Response::paginated($stmt->fetchAll(), $total, $page, $perPage);
}

function getUser(PDO $db, int $id): void
{
    $me = requireAuth();
    if ($me['sub'] !== $id) requireAuth(['admin']);
    $stmt = $db->prepare('SELECT id,name,email,phone,avatar_url,is_active,created_at FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) Response::error('User not found.', 404);
    Response::success($user);
}

function updateUser(PDO $db, int $id, array $body): void
{
    $me = requireAuth();
    if ($me['sub'] !== $id) requireAuth(['admin']);
    $allowed = ['name','phone','avatar_url'];
    $fields = $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) { $fields[] = "$f=?"; $params[] = $body[$f]; }
    }
    if (!empty($body['password']) && strlen($body['password']) >= 8) {
        $fields[] = 'password_hash=?';
        $params[] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    }
    if (!$fields) Response::error('Nothing to update.', 422);
    $params[] = $id;
    $db->prepare('UPDATE users SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    Response::success(null, 'User updated.');
}

function deleteUser(PDO $db, int $id): void
{
    requireAuth(['admin']);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    Response::success(null, 'User deleted.');
}
