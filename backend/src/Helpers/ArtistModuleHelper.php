<?php

function resolveArtistOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }

    return (int)($user['organizer_id'] ?? 0);
}

function getArtistContext(array $allowedRoles = ['admin', 'organizer', 'manager', 'staff']): array
{
    $user = requireAuth($allowedRoles);
    $db = Database::getInstance();
    $organizerId = resolveArtistOrganizerId($user);

    if ($organizerId <= 0) {
        jsonError('Organizador invalido para o modulo de artistas.', 403);
    }

    return [$db, $organizerId, $user];
}

function artistKnownSubresources(): array
{
    return [
        'bookings',
        'logistics',
        'logistics-items',
        'timelines',
        'transfers',
        'alerts',
        'team',
        'files',
        'imports',
        'exports',
    ];
}

function artistRequiredTables(): array
{
    return [
        'artists',
        'event_artists',
        'artist_logistics',
        'artist_logistics_items',
        'artist_operational_timelines',
        'artist_transfer_estimations',
        'artist_operational_alerts',
        'artist_team_members',
        'artist_files',
        'artist_import_batches',
        'artist_import_rows',
    ];
}

function artistTableExists(PDO $db, string $table): bool
{
    static $cache = [];
    $table = trim(strtolower($table));
    if ($table === '') {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table
        )
    ");
    $stmt->execute([':table' => $table]);

    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

function artistColumnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $table = trim(strtolower($table));
    $column = trim(strtolower($column));
    if ($table === '' || $column === '') {
        return false;
    }

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table
              AND column_name = :column
        )
    ");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function artistModuleSchemaStatus(PDO $db): array
{
    $status = [];
    foreach (artistRequiredTables() as $table) {
        $status[] = [
            'table' => $table,
            'table_name' => $table,
            'exists' => artistTableExists($db, $table),
        ];
    }

    return $status;
}

function artistModuleSchemaReady(PDO $db): bool
{
    foreach (artistRequiredTables() as $table) {
        if (!artistTableExists($db, $table)) {
            return false;
        }
    }

    return true;
}

function artistEnsureModuleSchemaReady(PDO $db): void
{
    if (artistModuleSchemaReady($db)) {
        return;
    }

    $missing = [];
    foreach (artistModuleSchemaStatus($db) as $row) {
        if (empty($row['exists'])) {
            $missing[] = $row['table'];
        }
    }

    jsonError(
        'Estrutura do modulo de logistica de artistas indisponivel. Aplique a migration 035_artist_logistics_module.sql.',
        503,
        ['missing_tables' => $missing]
    );
}

function artistResolveEventId(PDO $db, int $organizerId, mixed $rawEventId, bool $required = false): ?int
{
    $eventId = (int)($rawEventId ?? 0);
    if ($eventId <= 0) {
        if ($required) {
            jsonError('event_id e obrigatorio.', 422);
        }

        return null;
    }

    $stmt = $db->prepare("
        SELECT id
        FROM public.events
        WHERE id = ?
          AND organizer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId, $organizerId]);

    if (!$stmt->fetchColumn()) {
        jsonError('Evento fora do escopo do organizador.', 403);
    }

    return $eventId;
}

function artistIsNumericIdentifier(?string $value): bool
{
    return $value !== null && ctype_digit((string)$value);
}

function artistNormalizeOptionalInt(mixed $value): ?int
{
    $normalized = (int)($value ?? 0);
    return $normalized > 0 ? $normalized : null;
}

function artistNormalizePagination(array $query, int $defaultPerPage = 20, int $maxPerPage = 100): array
{
    $page = max(1, (int)($query['page'] ?? 1));
    $perPage = max(1, min($maxPerPage, (int)($query['per_page'] ?? $defaultPerPage)));
    $offset = ($page - 1) * $perPage;

    return [
        'page' => $page,
        'per_page' => $perPage,
        'offset' => $offset,
    ];
}

function artistBuildPaginationMeta(int $page, int $perPage, int $total): array
{
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int)ceil($total / max($perPage, 1)),
    ];
}

function artistNormalizeOptionalText(mixed $value, ?int $maxLength = null): ?string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return null;
    }

    if ($maxLength !== null && $maxLength > 0) {
        $text = function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength)
            : substr($text, 0, $maxLength);
    }

    return $text;
}

function artistNormalizeDateString(mixed $value): ?string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($text))->format('Y-m-d');
    } catch (Throwable) {
        jsonError('Data invalida.', 422);
    }
}

function artistNormalizeTimestampString(mixed $value): ?string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable(str_replace('T', ' ', $text)))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        jsonError('Timestamp invalido.', 422);
    }
}

function artistNormalizeBoolean(mixed $value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }

    if ($value === 't' || $value === 'true' || $value === 1 || $value === '1') {
        return true;
    }

    if ($value === 'f' || $value === 'false' || $value === 0 || $value === '0') {
        return false;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function artistNormalizeMoney(mixed $value, bool $allowNull = true, string $fieldName = 'valor'): ?string
{
    if ($value === null || $value === '') {
        return $allowNull ? null : '0.00';
    }

    if (is_int($value) || is_float($value)) {
        $amount = (float)$value;
    } else {
        $raw = trim((string)$value);
        if ($raw === '') {
            return $allowNull ? null : '0.00';
        }

        $sanitized = preg_replace('/[^\d,\.\-]/', '', $raw) ?? '';
        if ($sanitized === '' || $sanitized === '-') {
            jsonError($fieldName . ' invalido.', 422);
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
                jsonError($fieldName . ' invalido.', 422);
            }
            $amount = (float)$normalized;
        }
    }

    if (!is_finite($amount) || $amount < 0) {
        jsonError($fieldName . ' invalido.', 422);
    }

    return number_format(round($amount, 2), 2, '.', '');
}

function artistNormalizePositiveQuantity(mixed $value, string $fieldName = 'quantity'): string
{
    if ($value === null || $value === '') {
        return '1.00';
    }

    $normalized = artistNormalizeMoney($value, false, $fieldName);
    if ((float)$normalized <= 0) {
        jsonError($fieldName . ' deve ser maior que zero.', 422);
    }

    return $normalized;
}

function artistNormalizeNonNegativeInt(mixed $value, bool $allowNull = true, string $fieldName = 'value'): ?int
{
    if ($value === null || $value === '') {
        return $allowNull ? null : 0;
    }

    if (!is_numeric($value)) {
        jsonError($fieldName . ' invalido.', 422);
    }

    $normalized = (int)$value;
    if ($normalized < 0) {
        jsonError($fieldName . ' invalido.', 422);
    }

    return $normalized;
}

function artistIsUniqueViolation(Throwable $error): bool
{
    return (string)$error->getCode() === '23505';
}
