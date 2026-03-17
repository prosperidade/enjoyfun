<?php

function workforceHelperTableExists(PDO $db, string $table): bool
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

function workforceHelperColumnExists(PDO $db, string $table, string $column): bool
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
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function workforceEventRolesReady(PDO $db): bool
{
    return workforceHelperTableExists($db, 'workforce_event_roles')
        && workforceHelperColumnExists($db, 'workforce_event_roles', 'public_id')
        && workforceHelperColumnExists($db, 'workforce_event_roles', 'event_id')
        && workforceHelperColumnExists($db, 'workforce_event_roles', 'role_id');
}

function workforceAssignmentsHaveEventRoleColumns(PDO $db): bool
{
    return workforceHelperColumnExists($db, 'workforce_assignments', 'event_role_id')
        && workforceHelperColumnExists($db, 'workforce_assignments', 'root_manager_event_role_id');
}

function workforceAssignmentsHavePublicId(PDO $db): bool
{
    return workforceHelperColumnExists($db, 'workforce_assignments', 'public_id');
}

function workforceInferCostBucketSql(string $roleNameExpr): string
{
    return "CASE
                WHEN LOWER(COALESCE({$roleNameExpr}, '')) ~ '(gerente|diretor|coordenador|supervisor|lider|chefe|gestor|manager)'
                    THEN 'managerial'
                ELSE 'operational'
            END";
}

function workforceBuildOperationalConfigSqlParts(
    PDO $db,
    string $assignmentAlias,
    string $memberAlias,
    string $legacyAlias,
    string $eventAlias,
    string $roleNameExpr
): array {
    $hasLegacySettings = workforceHelperTableExists($db, 'workforce_role_settings');
    $hasEventRoles = workforceEventRolesReady($db) && workforceAssignmentsHaveEventRoleColumns($db);

    $eventJoin = $hasEventRoles
        ? "LEFT JOIN workforce_event_roles {$eventAlias} ON {$eventAlias}.id = {$assignmentAlias}.event_role_id"
        : '';

    $maxShiftsExpr = "COALESCE({$memberAlias}.max_shifts_event"
        . ($hasEventRoles ? ", {$eventAlias}.max_shifts_event" : '')
        . ($hasLegacySettings ? ", {$legacyAlias}.max_shifts_event" : '')
        . ", 1)";
    $shiftHoursExpr = "COALESCE({$memberAlias}.shift_hours"
        . ($hasEventRoles ? ", {$eventAlias}.shift_hours" : '')
        . ($hasLegacySettings ? ", {$legacyAlias}.shift_hours" : '')
        . ", 8)";
    $mealsExpr = "COALESCE({$memberAlias}.meals_per_day"
        . ($hasEventRoles ? ", {$eventAlias}.meals_per_day" : '')
        . ($hasLegacySettings ? ", {$legacyAlias}.meals_per_day" : '')
        . ", 4)";
    $paymentExpr = "COALESCE({$memberAlias}.payment_amount"
        . ($hasEventRoles ? ", {$eventAlias}.payment_amount" : '')
        . ($hasLegacySettings ? ", {$legacyAlias}.payment_amount" : '')
        . ", 0)";

    $bucketSources = [];
    if ($hasEventRoles) {
        $bucketSources[] = "NULLIF(TRIM(COALESCE({$eventAlias}.cost_bucket, '')), '')";
    }
    if ($hasLegacySettings) {
        $bucketSources[] = "NULLIF(TRIM(COALESCE({$legacyAlias}.cost_bucket, '')), '')";
    }
    $bucketSources[] = workforceInferCostBucketSql($roleNameExpr);
    $bucketExpr = "COALESCE(" . implode(', ', $bucketSources) . ")";

    $sourceExpr = "CASE
            WHEN {$memberAlias}.participant_id IS NOT NULL THEN 'member_override'"
        . ($hasEventRoles ? "
            WHEN {$eventAlias}.id IS NOT NULL THEN 'event_role'" : '')
        . ($hasLegacySettings ? "
            WHEN {$legacyAlias}.role_id IS NOT NULL THEN 'role_settings'" : '')
        . "
            ELSE 'default'
        END";

    return [
        'has_event_roles' => $hasEventRoles,
        'has_legacy_settings' => $hasLegacySettings,
        'event_role_join' => $eventJoin,
        'max_shifts_expr' => $maxShiftsExpr,
        'shift_hours_expr' => $shiftHoursExpr,
        'meals_expr' => $mealsExpr,
        'payment_expr' => $paymentExpr,
        'bucket_expr' => $bucketExpr,
        'source_expr' => $sourceExpr,
    ];
}

function workforceNormalizeAuthorityLevel(string $value): string
{
    $normalized = strtolower(trim($value));
    $allowed = ['none', 'table_manager', 'directive', 'organizer_delegate'];
    return in_array($normalized, $allowed, true) ? $normalized : 'none';
}

function workforceIsOperationalCollectionRoleName(string $roleName): bool
{
    $normalizedName = strtolower(trim($roleName));
    if ($normalizedName === '') {
        return false;
    }

    return (bool)preg_match('/^(equipe|time|staff)\b/u', $normalizedName);
}

function workforceResolveRoleClass(string $roleName, string $costBucket = ''): string
{
    $normalizedName = strtolower(trim($roleName));
    $normalizedBucket = strtolower(trim($costBucket));

    if (workforceIsOperationalCollectionRoleName($normalizedName)) {
        return 'operational';
    }

    if ($normalizedName !== '') {
        if (preg_match('/\b(gerente|diretor|manager|gestor)\b/u', $normalizedName)) {
            return 'manager';
        }
        if (preg_match('/\bcoordenador\b/u', $normalizedName)) {
            return 'coordinator';
        }
        if (preg_match('/\b(supervisor|lider|chefe)\b/u', $normalizedName)) {
            return 'supervisor';
        }
    }

    return $normalizedBucket === 'managerial' ? 'manager' : 'operational';
}

function workforceHasLeadershipIdentity(array $row): bool
{
    $leaderUserId = (int)($row['leader_user_id'] ?? 0);
    $leaderParticipantId = (int)($row['leader_participant_id'] ?? 0);
    $leaderName = trim((string)($row['leader_name'] ?? $row['leader_participant_name'] ?? ''));
    $leaderCpf = trim((string)($row['leader_cpf'] ?? ''));

    return $leaderUserId > 0
        || $leaderParticipantId > 0
        || ($leaderName !== '' && $leaderCpf !== '');
}

function workforceNormalizeEventRoleRow(array $row): array
{
    $normalized = $row;
    foreach ([
        'id',
        'organizer_id',
        'event_id',
        'role_id',
        'parent_event_role_id',
        'root_event_role_id',
        'leader_user_id',
        'leader_participant_id',
        'max_shifts_event',
        'meals_per_day',
        'sort_order',
    ] as $intKey) {
        if (array_key_exists($intKey, $normalized) && $normalized[$intKey] !== null) {
            $normalized[$intKey] = (int)$normalized[$intKey];
        }
    }

    foreach (['shift_hours', 'payment_amount'] as $floatKey) {
        if (array_key_exists($floatKey, $normalized) && $normalized[$floatKey] !== null) {
            $normalized[$floatKey] = (float)$normalized[$floatKey];
        }
    }

    $normalized['is_active'] = workforceNormalizePgBool($normalized['is_active'] ?? true);
    $normalized['is_placeholder'] = workforceNormalizePgBool($normalized['is_placeholder'] ?? false);
    $normalized['public_id'] = (string)($normalized['public_id'] ?? '');
    $normalized['role_class'] = (string)($normalized['role_class'] ?? 'operational');
    $normalized['authority_level'] = (string)($normalized['authority_level'] ?? 'none');
    $normalized['cost_bucket'] = (string)($normalized['cost_bucket'] ?? 'operational');
    $normalized['sector'] = (string)($normalized['sector'] ?? '');
    $normalized['leader_name'] = (string)($normalized['leader_name'] ?? '');
    $normalized['leader_cpf'] = (string)($normalized['leader_cpf'] ?? '');
    $normalized['leader_phone'] = (string)($normalized['leader_phone'] ?? '');

    return $normalized;
}

function workforceNormalizePgBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 't', 'true', 'y', 'yes'], true);
}

function workforceFetchEventRoleById(PDO $db, int $organizerId, int $eventRoleId): ?array
{
    if (!workforceEventRolesReady($db) || $eventRoleId <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT wer.*
        FROM workforce_event_roles wer
        WHERE wer.id = :id
          AND wer.organizer_id = :organizer_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $eventRoleId,
        ':organizer_id' => $organizerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? workforceNormalizeEventRoleRow($row) : null;
}

function workforceFetchEventRoleByPublicId(PDO $db, int $organizerId, string $publicId, int $eventId = 0): ?array
{
    if (!workforceEventRolesReady($db) || trim($publicId) === '') {
        return null;
    }

    $sql = "
        SELECT wer.*
        FROM workforce_event_roles wer
        WHERE wer.public_id = :public_id
          AND wer.organizer_id = :organizer_id
    ";
    $params = [
        ':public_id' => trim($publicId),
        ':organizer_id' => $organizerId,
    ];
    if ($eventId > 0) {
        $sql .= " AND wer.event_id = :event_id";
        $params[':event_id'] = $eventId;
    }
    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? workforceNormalizeEventRoleRow($row) : null;
}

function workforceFindEventRoleByStructure(
    PDO $db,
    int $organizerId,
    int $eventId,
    int $roleId,
    string $sector,
    ?int $parentEventRoleId
): ?array {
    if (!workforceEventRolesReady($db) || $eventId <= 0 || $roleId <= 0) {
        return null;
    }

    $sector = strtolower(trim($sector));
    $parentFilter = $parentEventRoleId === null
        ? 'wer.parent_event_role_id IS NULL'
        : 'wer.parent_event_role_id = :parent_event_role_id';
    $params = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':role_id' => $roleId,
        ':sector' => $sector,
    ];
    if ($parentEventRoleId !== null) {
        $params[':parent_event_role_id'] = $parentEventRoleId;
    }

    $stmt = $db->prepare("
        SELECT wer.*
        FROM workforce_event_roles wer
        WHERE wer.organizer_id = :organizer_id
          AND wer.event_id = :event_id
          AND wer.role_id = :role_id
          AND LOWER(COALESCE(wer.sector, '')) = :sector
          AND {$parentFilter}
          AND wer.is_active = true
        ORDER BY wer.id ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? workforceNormalizeEventRoleRow($row) : null;
}

function workforceBuildPreferredAssignmentJoinSql(PDO $db, string $participantExpr, string $alias = 'wa'): string
{
    $selectColumns = [
        'wa_src.id',
        'wa_src.participant_id',
        'wa_src.role_id',
        'wa_src.sector',
        'wa_src.event_shift_id',
        'wa_src.manager_user_id',
    ];
    $orderClauses = [];

    if (workforceAssignmentsHaveEventRoleColumns($db)) {
        $selectColumns[] = 'wa_src.event_role_id';
        $selectColumns[] = 'wa_src.root_manager_event_role_id';
        $orderClauses[] = 'CASE WHEN COALESCE(wa_src.event_role_id, 0) > 0 THEN 0 ELSE 1 END';
        $orderClauses[] = 'CASE WHEN COALESCE(wa_src.root_manager_event_role_id, 0) > 0 THEN 0 ELSE 1 END';
    } else {
        $selectColumns[] = 'NULL::integer AS event_role_id';
        $selectColumns[] = 'NULL::integer AS root_manager_event_role_id';
    }

    $orderClauses[] = 'wa_src.id ASC';

    return "
        LEFT JOIN LATERAL (
            SELECT " . implode(",\n                   ", $selectColumns) . "
            FROM workforce_assignments wa_src
            WHERE wa_src.participant_id = {$participantExpr}
            ORDER BY " . implode(', ', $orderClauses) . "
            LIMIT 1
        ) {$alias} ON TRUE
    ";
}

function workforceResolveParticipantOperationalConfig(PDO $db, int $participantId): array
{
    $parts = workforceBuildOperationalConfigSqlParts($db, 'wa', 'wms', 'wrs', 'wer', 'r.name');
    $legacyJoin = $parts['has_legacy_settings']
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id"
        : '';
    $memberJoin = workforceHelperTableExists($db, 'workforce_member_settings')
        ? "LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id"
        : "LEFT JOIN LATERAL (
                SELECT
                    NULL::integer AS participant_id,
                    NULL::integer AS max_shifts_event,
                    NULL::numeric AS shift_hours,
                    NULL::integer AS meals_per_day,
                    NULL::numeric AS payment_amount
           ) wms ON TRUE";
    $preferredAssignmentJoin = workforceBuildPreferredAssignmentJoinSql($db, 'ep.id', 'wa');

    $stmt = $db->prepare("
        SELECT
            ep.id AS participant_id,
            {$parts['max_shifts_expr']}::int AS max_shifts_event,
            {$parts['shift_hours_expr']}::numeric AS shift_hours,
            {$parts['meals_expr']}::int AS meals_per_day,
            {$parts['payment_expr']}::numeric AS payment_amount,
            {$parts['bucket_expr']}::varchar AS cost_bucket,
            {$parts['source_expr']} AS source
        FROM event_participants ep
        {$preferredAssignmentJoin}
        LEFT JOIN workforce_roles r ON r.id = wa.role_id
        {$memberJoin}
        {$parts['event_role_join']}
        {$legacyJoin}
        WHERE ep.id = :participant_id
        LIMIT 1
    ");
    $stmt->execute([':participant_id' => $participantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'participant_id' => $participantId,
        'max_shifts_event' => (int)($row['max_shifts_event'] ?? 1),
        'shift_hours' => (float)($row['shift_hours'] ?? 8),
        'meals_per_day' => (int)($row['meals_per_day'] ?? 4),
        'payment_amount' => (float)($row['payment_amount'] ?? 0),
        'cost_bucket' => (string)($row['cost_bucket'] ?? 'operational'),
        'source' => (string)($row['source'] ?? 'default'),
    ];
}
