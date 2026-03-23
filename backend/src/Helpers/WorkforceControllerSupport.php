<?php

require_once BASE_PATH . '/src/Services/CardIssuanceService.php';

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function ensureWorkforceBulkCardIssuanceEnabled(): void
{
    if (workforceBulkCardIssuanceEnabled()) {
        return;
    }

    jsonError('Emissão em massa de cartões ainda não está habilitada neste ambiente.', 403);
}

function workforceBulkCardIssuanceEnabled(): bool
{
    $raw = getenv('FEATURE_WORKFORCE_BULK_CARD_ISSUANCE');
    if ($raw === false || $raw === null) {
        $raw = $_ENV['FEATURE_WORKFORCE_BULK_CARD_ISSUANCE'] ?? $_SERVER['FEATURE_WORKFORCE_BULK_CARD_ISSUANCE'] ?? '';
    }

    $normalized = strtolower(trim((string)$raw));
    return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
}

function resolveCardIssuanceParticipantIds(array $body): array
{
    $raw = $body['participant_ids'] ?? $body['participants'] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $item) {
        if (is_array($item)) {
            $candidate = $item['participant_id'] ?? $item['id'] ?? null;
        } else {
            $candidate = $item;
        }

        $participantId = (int)$candidate;
        if ($participantId > 0) {
            $ids[$participantId] = $participantId;
        }
    }

    return array_values($ids);
}

function normalizeCardIssuanceOptionalInt(mixed $value): ?int
{
    $normalized = (int)($value ?? 0);
    return $normalized > 0 ? $normalized : null;
}

function normalizeCardIssuanceSourceModule(mixed $value): string
{
    $source = trim((string)$value);
    if ($source === '') {
        return CardIssuanceService::SOURCE_WORKFORCE_BULK;
    }

    $source = preg_replace('/[^a-z0-9_\-]/i', '_', strtolower($source)) ?? CardIssuanceService::SOURCE_WORKFORCE_BULK;
    $source = trim($source, '_');

    return $source !== '' ? substr($source, 0, 50) : CardIssuanceService::SOURCE_WORKFORCE_BULK;
}

function normalizeCardIssuanceMoney(mixed $value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    if (is_int($value) || is_float($value)) {
        $amount = (float)$value;
    } else {
        $raw = trim((string)$value);
        if ($raw === '') {
            return 0.0;
        }

        $sanitized = preg_replace('/[^\d,\.\-]/', '', $raw) ?? '';
        if ($sanitized === '') {
            throw new RuntimeException('initial_balance inválido.', 422);
        }

        $lastComma = strrpos($sanitized, ',');
        $lastDot = strrpos($sanitized, '.');
        $decimalPos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        if ($decimalPos >= 0) {
            $integerPart = preg_replace('/[^\d\-]/', '', substr($sanitized, 0, $decimalPos)) ?? '';
            $fractionPart = preg_replace('/\D/', '', substr($sanitized, $decimalPos + 1)) ?? '';
            $normalized = ($integerPart === '' || $integerPart === '-') ? '0' : $integerPart;
            $amount = (float)($normalized . '.' . $fractionPart);
        } else {
            $normalized = preg_replace('/[^\d\-]/', '', $sanitized) ?? '';
            if ($normalized === '' || $normalized === '-') {
                throw new RuntimeException('initial_balance inválido.', 422);
            }
            $amount = (float)$normalized;
        }
    }

    if (!is_finite($amount) || $amount < 0) {
        throw new RuntimeException('initial_balance inválido.', 422);
    }

    return round($amount, 2);
}

function canBypassSectorAcl(array $user): bool
{
    $role = strtolower((string)($user['role'] ?? ''));
    return $role === 'admin' || $role === 'organizer';
}

function resolveUserSector(PDO $db, array $user): string
{
    $sectorFromToken = normalizeSector((string)($user['sector'] ?? ''));
    if ($sectorFromToken) {
        return $sectorFromToken;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return 'all';
    }

    $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(sector), ''), 'all') FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $sector = $stmt->fetchColumn();

    return normalizeSector((string)$sector) ?: 'all';
}

function normalizeSector(string $value): string
{
    $v = strtolower(trim($value));
    return preg_replace('/\s+/', '_', $v);
}

function inferSectorFromFileName(string $fileName): ?string
{
    $name = normalizeSector(pathinfo($fileName, PATHINFO_FILENAME));
    if ($name === '') {
        return null;
    }

    $map = [
        'bar' => ['bar', 'bebidas', 'drink'],
        'food' => ['food', 'cozinha', 'kitchen', 'alimento', 'alimentacao'],
        'shop' => ['shop', 'loja', 'merch', 'store'],
        'parking' => ['parking', 'estacionamento'],
        'acessos' => ['acesso', 'acessos', 'entrada', 'portaria', 'bilheteria'],
        'seguranca' => ['seguranca', 'security', 'apoio'],
        'limpeza' => ['limpeza', 'cleaning', 'zeladoria'],
    ];

    foreach ($map as $sector => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                return $sector;
            }
        }
    }

    return null;
}

function normalizeSectorInferenceToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = strtr($normalized, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ]);
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
    return trim((string)$normalized, '_');
}

function ensureSectorDefaultRole(PDO $db, int $organizerId, string $sector): int
{
    $defaultRoleName = 'Equipe ' . strtoupper($sector);
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("SELECT id FROM workforce_roles WHERE organizer_id = ? AND LOWER(name) = LOWER(?) AND LOWER(COALESCE(sector, '')) = ? LIMIT 1");
        $stmt->execute([$organizerId, $defaultRoleName, normalizeSector($sector)]);
    } else {
        $stmt = $db->prepare("SELECT id FROM workforce_roles WHERE organizer_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$organizerId, $defaultRoleName]);
    }
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmtIns = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, sector, created_at) VALUES (?, ?, ?, NOW()) RETURNING id");
        $stmtIns->execute([$organizerId, $defaultRoleName, normalizeSector($sector)]);
    } else {
        $stmtIns = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, created_at) VALUES (?, ?, NOW()) RETURNING id");
        $stmtIns->execute([$organizerId, $defaultRoleName]);
    }
    return (int)$stmtIns->fetchColumn();
}

function formatSectorLabel(string $sector): string
{
    $normalized = normalizeSector($sector);
    if ($normalized === '') {
        return 'Geral';
    }

    $words = array_filter(explode('_', $normalized), static fn(string $item): bool => $item !== '');
    $words = array_map(static function (string $word): string {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }
        return ucfirst($word);
    }, $words);

    return implode(' ', $words);
}

function ensureSectorManagerRole(PDO $db, int $organizerId, string $sector): int
{
    $sector = normalizeSector($sector);
    if ($sector === '') {
        $sector = 'geral';
    }

    $roleName = 'Gerente de ' . formatSectorLabel($sector);
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("
            SELECT id
            FROM workforce_roles
            WHERE organizer_id = ?
              AND LOWER(name) = LOWER(?)
              AND LOWER(COALESCE(sector, '')) = ?
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $roleName, $sector]);
    } else {
        $stmt = $db->prepare("
            SELECT id
            FROM workforce_roles
            WHERE organizer_id = ?
              AND LOWER(name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $roleName]);
    }
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmtInsert = $db->prepare("
            INSERT INTO workforce_roles (organizer_id, name, sector, created_at)
            VALUES (?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsert->execute([$organizerId, $roleName, $sector]);
    } else {
        $stmtInsert = $db->prepare("
            INSERT INTO workforce_roles (organizer_id, name, created_at)
            VALUES (?, ?, NOW())
            RETURNING id
        ");
        $stmtInsert->execute([$organizerId, $roleName]);
    }

    return (int)$stmtInsert->fetchColumn();
}

function getRoleById(PDO $db, int $organizerId, int $roleId): ?array
{
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("SELECT id, name, sector FROM workforce_roles WHERE id = ? AND organizer_id = ? LIMIT 1");
    } else {
        $stmt = $db->prepare("SELECT id, name, NULL::varchar AS sector FROM workforce_roles WHERE id = ? AND organizer_id = ? LIMIT 1");
    }
    $stmt->execute([$roleId, $organizerId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    return $role ?: null;
}

function findRoleByNameAndSector(PDO $db, int $organizerId, string $roleName, string $sector = ''): ?array
{
    $roleName = trim($roleName);
    $sector = normalizeSector($sector);
    if ($roleName === '') {
        return null;
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("
            SELECT id, name, sector
            FROM workforce_roles
            WHERE organizer_id = ?
              AND LOWER(name) = LOWER(?)
              AND LOWER(COALESCE(sector, '')) = ?
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $roleName, $sector]);
    } else {
        $stmt = $db->prepare("
            SELECT id, name, NULL::varchar AS sector
            FROM workforce_roles
            WHERE organizer_id = ?
              AND LOWER(name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $roleName]);
    }

    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    return $role ?: null;
}

function ensureRoleByNameAndSector(PDO $db, int $organizerId, string $roleName, string $sector = ''): array
{
    $existing = findRoleByNameAndSector($db, $organizerId, $roleName, $sector);
    if ($existing) {
        return $existing;
    }

    $roleName = trim($roleName);
    $sector = normalizeSector($sector);
    if ($roleName === '') {
        jsonError('Nome do cargo é obrigatório.', 422);
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("
            INSERT INTO workforce_roles (organizer_id, name, sector, created_at)
            VALUES (?, ?, ?, NOW())
            RETURNING id, name, sector
        ");
        $stmt->execute([$organizerId, $roleName, $sector]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO workforce_roles (organizer_id, name, created_at)
            VALUES (?, ?, NOW())
            RETURNING id, name, NULL::varchar AS sector
        ");
        $stmt->execute([$organizerId, $roleName]);
    }

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => 0,
        'name' => $roleName,
        'sector' => $sector,
    ];
}

function resolveEditableRoleForEventRole(PDO $db, int $organizerId, array $currentRole, array $payload): array
{
    $requestedRoleId = (int)($payload['role_id'] ?? $currentRole['id'] ?? 0);
    $baseRole = getRoleById($db, $organizerId, $requestedRoleId);
    if (!$baseRole) {
        jsonError('Cargo não encontrado.', 404);
    }

    $desiredRoleName = trim((string)($payload['role_name'] ?? $baseRole['name'] ?? $currentRole['name'] ?? ''));
    $desiredSector = normalizeSector((string)($payload['sector'] ?? $baseRole['sector'] ?? $currentRole['sector'] ?? ''));

    if ($desiredRoleName === '') {
        jsonError('Nome do cargo é obrigatório.', 422);
    }

    $baseName = trim((string)($baseRole['name'] ?? ''));
    $baseSector = normalizeSector((string)($baseRole['sector'] ?? ''));
    $sameName = function_exists('mb_strtolower')
        ? mb_strtolower($desiredRoleName, 'UTF-8') === mb_strtolower($baseName, 'UTF-8')
        : strtolower($desiredRoleName) === strtolower($baseName);

    if ($sameName && $desiredSector === $baseSector) {
        return $baseRole;
    }

    return ensureRoleByNameAndSector($db, $organizerId, $desiredRoleName, $desiredSector);
}

function resolveRoleCostBucket(PDO $db, int $organizerId, array $role): string
{
    $roleId = (int)($role['id'] ?? 0);
    if ($roleId > 0 && tableExists($db, 'workforce_role_settings')) {
        $stmt = $db->prepare("
            SELECT cost_bucket
            FROM workforce_role_settings
            WHERE role_id = ? AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$roleId, $organizerId]);
        $value = $stmt->fetchColumn();
        if (is_string($value) && trim($value) !== '') {
            return normalizeCostBucket($value, (string)($role['name'] ?? ''));
        }
    }

    return normalizeCostBucket((string)($role['cost_bucket'] ?? ''), (string)($role['name'] ?? ''));
}

function inferSectorFromRoleName(string $roleName): string
{
    $name = normalizeSector($roleName);
    if ($name === '') {
        return '';
    }

    $prefixes = [
        'gerente_de_',
        'diretor_de_',
        'coordenador_de_',
        'supervisor_de_',
        'lider_de_',
        'chefe_de_',
        'equipe_de_',
        'time_de_'
    ];
    foreach ($prefixes as $p) {
        if (str_starts_with($name, $p)) {
            $name = substr($name, strlen($p));
            break;
        }
    }

    $suffixes = ['_senior', '_junior', '_pleno', '_noturno', '_diurno'];
    foreach ($suffixes as $s) {
        if (str_ends_with($name, $s)) {
            $name = substr($name, 0, -strlen($s));
        }
    }

    return trim($name, '_');
}

function resolveDefaultCategoryId(PDO $db, int $organizerId): int
{
    $stmtStaff = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? AND type = 'staff' LIMIT 1");
    $stmtStaff->execute([$organizerId]);
    $staffId = $stmtStaff->fetchColumn();
    if ($staffId) {
        return (int)$staffId;
    }

    $stmtAny = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? ORDER BY id ASC LIMIT 1");
    $stmtAny->execute([$organizerId]);
    $anyId = $stmtAny->fetchColumn();
    if (!$anyId) {
        jsonError('Nenhuma categoria de participantes cadastrada para este organizador.', 422);
    }
    return (int)$anyId;
}

function categoryBelongsToOrganizer(PDO $db, int $categoryId, int $organizerId): bool
{
    $stmt = $db->prepare("SELECT id FROM participant_categories WHERE id = ? AND organizer_id = ? LIMIT 1");
    $stmt->execute([$categoryId, $organizerId]);
    return (bool)$stmt->fetchColumn();
}

function findPersonId(PDO $db, int $organizerId, string $document, string $email): ?int
{
    if ($document !== '') {
        $stmt = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$document, $organizerId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    if ($email !== '') {
        $stmt = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$email, $organizerId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    return null;
}

function findEventParticipantId(PDO $db, int $eventId, int $personId): ?int
{
    $stmt = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ? LIMIT 1");
    $stmt->execute([$eventId, $personId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function columnExists(PDO $db, string $table, string $column): bool
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

function tableExists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = :table
        LIMIT 1
    ");
    $stmt->execute([':table' => $table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}
