<?php
/**
 * CLI audit for Meals.
 *
 * Usage:
 *   php backend/scripts/audit_meals.php
 *   php backend/scripts/audit_meals.php recent --limit=50 --event=1
 *   php backend/scripts/audit_meals.php integrity --event=1
 *   php backend/scripts/audit_meals.php outside-day --event=1 --limit=50
 *   php backend/scripts/audit_meals.php summary
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

loadEnv(BASE_PATH . '/.env');

$mode = strtolower((string)($argv[1] ?? 'recent'));
$options = parseOptions(array_slice($argv, 2));

try {
    switch ($mode) {
        case 'recent':
            runRecentAudit(connectDatabase(), $options);
            break;
        case 'integrity':
            runIntegrityAudit(connectDatabase(), $options);
            break;
        case 'outside-day':
        case 'outside_operational_day':
            runOutsideOperationalDayAudit(connectDatabase(), $options);
            break;
        case 'summary':
            runSummaryAudit(connectDatabase(), $options);
            break;
        case 'help':
        case '--help':
        case '-h':
            printUsage();
            break;
        default:
            fwrite(STDERR, "Unknown mode: {$mode}\n\n");
            printUsage();
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Meals audit failed: " . $e->getMessage() . "\n");
    exit(1);
}

function connectDatabase(): PDO
{
    if (!extension_loaded('pdo_pgsql') && !extension_loaded('pgsql')) {
        throw new RuntimeException(
            'Database connection failed: PHP CLI sem pdo_pgsql/pgsql. ' .
            'Instale a extensao PostgreSQL no PHP ou rode a auditoria via psql.'
        );
    }

    $host = requiredEnv('DB_HOST');
    $port = requiredEnv('DB_PORT');
    $name = requiredEnv('DB_NAME');
    $user = requiredEnv('DB_USER');
    $pass = requiredEnv('DB_PASS');

    $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
    }
}

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false) {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    }

    $value = trim((string)$value);
    if ($value === '') {
        throw new RuntimeException("Database configuration missing: {$name}");
    }

    return $value;
}

function loadEnv(string $envFile): void
{
    if (!file_exists($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        [$name, $value] = [trim($parts[0]), trim(trim($parts[1]), "\"'")];
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
}

function parseOptions(array $args): array
{
    $options = [
        'event' => null,
        'organizer' => null,
        'limit' => 100,
    ];

    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        $key = strtolower(trim((string)($parts[0] ?? '')));
        $value = trim((string)($parts[1] ?? '1'));

        switch ($key) {
            case 'event':
            case 'event_id':
                $options['event'] = max(0, (int)$value) ?: null;
                break;
            case 'organizer':
            case 'organizer_id':
                $options['organizer'] = max(0, (int)$value) ?: null;
                break;
            case 'limit':
                $options['limit'] = max(1, min(500, (int)$value ?: 100));
                break;
        }
    }

    return $options;
}

function runRecentAudit(PDO $db, array $options): void
{
    $filters = [];
    $params = [];

    if (($options['event'] ?? null) !== null) {
        $filters[] = 'e.id = :event_id';
        $params[':event_id'] = (int)$options['event'];
    }
    if (($options['organizer'] ?? null) !== null) {
        $filters[] = 'e.organizer_id = :organizer_id';
        $params[':organizer_id'] = (int)$options['organizer'];
    }

    $whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
    $limit = (int)($options['limit'] ?? 100);

    $sql = "
        SELECT
            pm.id,
            pm.consumed_at,
            p.name AS participant_name,
            ep.id AS participant_id,
            e.id AS event_id,
            e.name AS event_name,
            e.organizer_id,
            ed.id AS event_day_id,
            ed.date AS event_date,
            es.id AS event_shift_id,
            es.name AS shift_name
        FROM participant_meals pm
        LEFT JOIN event_participants ep ON ep.id = pm.participant_id
        LEFT JOIN people p ON p.id = ep.person_id
        LEFT JOIN event_days ed ON ed.id = pm.event_day_id
        LEFT JOIN events e ON e.id = ed.event_id
        LEFT JOIN event_shifts es
               ON es.id = pm.event_shift_id
              AND (pm.event_day_id IS NULL OR es.event_day_id = pm.event_day_id)
        {$whereSql}
        ORDER BY pm.consumed_at DESC, pm.id DESC
        LIMIT {$limit}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Meals Audit: recent\n";
    echo "===================\n\n";

    if (!$rows) {
        echo "No meal records found for the selected scope.\n";
        return;
    }

    printf(
        "%-5s | %-19s | %-24s | %-7s | %-7s | %-7s | %-18s\n",
        'ID',
        'Consumed At',
        'Participant',
        'Event',
        'Day',
        'Shift',
        'Shift Name'
    );
    echo str_repeat('-', 108) . "\n";

    foreach ($rows as $row) {
        printf(
            "%-5s | %-19s | %-24s | %-7s | %-7s | %-7s | %-18s\n",
            (string)($row['id'] ?? ''),
            (string)($row['consumed_at'] ?? ''),
            truncate((string)($row['participant_name'] ?? '[missing participant]'), 24),
            (string)($row['event_id'] ?? '-'),
            (string)($row['event_day_id'] ?? '-'),
            (string)($row['event_shift_id'] ?? '-'),
            truncate((string)($row['shift_name'] ?? '-'), 18)
        );
    }
}

function runIntegrityAudit(PDO $db, array $options): void
{
    $scope = buildScopeClauses($options);

    echo "Meals Audit: integrity\n";
    echo "======================\n\n";

    $checks = [
        [
            'label' => 'null_event_day',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                {$scope['participant_event_join']}
                WHERE pm.event_day_id IS NULL
                {$scope['participant_event_where']}
            ",
        ],
        [
            'label' => 'orphan_participant',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                LEFT JOIN event_participants ep ON ep.id = pm.participant_id
                LEFT JOIN event_days ed ON ed.id = pm.event_day_id
                {$scope['joins']}
                WHERE ep.id IS NULL
                {$scope['where_suffix']}
            ",
        ],
        [
            'label' => 'orphan_day',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                LEFT JOIN event_days ed ON ed.id = pm.event_day_id
                {$scope['joins']}
                WHERE ed.id IS NULL
                {$scope['where_suffix']}
            ",
        ],
        [
            'label' => 'meal_service_event_mismatch',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                JOIN event_days ed ON ed.id = pm.event_day_id
                JOIN events e ON e.id = ed.event_id
                LEFT JOIN event_meal_services ems ON ems.id = pm.meal_service_id
                WHERE pm.meal_service_id IS NOT NULL
                  AND ems.id IS NOT NULL
                  AND ems.event_id <> ed.event_id
                {$scope['event_where']}
            ",
        ],
        [
            'label' => 'missing_shift_reference',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                LEFT JOIN event_days ed ON ed.id = pm.event_day_id
                LEFT JOIN event_shifts es ON es.id = pm.event_shift_id
                {$scope['event_join']}
                WHERE pm.event_shift_id IS NOT NULL
                  AND es.id IS NULL
                {$scope['where_suffix_no_extra']}
            ",
        ],
        [
            'label' => 'shift_day_mismatch',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                JOIN event_days ed ON ed.id = pm.event_day_id
                JOIN event_shifts es ON es.id = pm.event_shift_id
                {$scope['event_join']}
                WHERE pm.event_shift_id IS NOT NULL
                  AND es.event_day_id <> pm.event_day_id
                {$scope['where_suffix_no_extra']}
            ",
        ],
        [
            'label' => 'participant_day_event_mismatch',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                JOIN event_participants ep ON ep.id = pm.participant_id
                JOIN event_days ed ON ed.id = pm.event_day_id
                JOIN events e ON e.id = ed.event_id
                WHERE ep.event_id <> ed.event_id
                {$scope['event_where']}
            ",
        ],
        [
            'label' => 'meal_without_any_assignment',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                JOIN event_participants ep ON ep.id = pm.participant_id
                JOIN event_days ed ON ed.id = pm.event_day_id
                JOIN events e ON e.id = ed.event_id
                LEFT JOIN workforce_assignments wa ON wa.participant_id = pm.participant_id
                WHERE wa.id IS NULL
                {$scope['event_where']}
            ",
        ],
        [
            'label' => 'meal_without_shift_assignment_when_shifted',
            'sql' => "
                SELECT COUNT(*)::int
                FROM participant_meals pm
                WHERE pm.event_shift_id IS NOT NULL
                  AND NOT EXISTS (
                        SELECT 1
                        FROM workforce_assignments wa
                        LEFT JOIN event_shifts es_scope ON es_scope.id = wa.event_shift_id
                        JOIN event_participants ep_scope ON ep_scope.id = wa.participant_id
                        JOIN event_days ed_scope ON ed_scope.id = pm.event_day_id
                        JOIN events e ON e.id = ed_scope.event_id
                        WHERE wa.participant_id = pm.participant_id
                          AND ep_scope.event_id = ed_scope.event_id
                          AND (
                                wa.event_shift_id IS NULL
                                OR wa.event_shift_id = pm.event_shift_id
                                OR es_scope.event_day_id = pm.event_day_id
                          )
                        {$scope['event_where']}
                  )
            ",
        ],
        [
            'label' => 'consumed_at_outside_operational_day',
            'sql' => buildOutsideOperationalDayCountSql($scope['event_where']),
        ],
    ];

    foreach ($checks as $check) {
        $stmt = $db->prepare($check['sql']);
        $stmt->execute($scope['params']);
        $count = (int)$stmt->fetchColumn();
        printf("%-36s : %d\n", $check['label'], $count);
    }
}

function runOutsideOperationalDayAudit(PDO $db, array $options): void
{
    $filters = [];
    $params = [];

    if (($options['event'] ?? null) !== null) {
        $filters[] = 'e.id = :event_id';
        $params[':event_id'] = (int)$options['event'];
    }
    if (($options['organizer'] ?? null) !== null) {
        $filters[] = 'e.organizer_id = :organizer_id';
        $params[':organizer_id'] = (int)$options['organizer'];
    }

    $whereSql = $filters ? (' AND ' . implode(' AND ', $filters)) : '';
    $limit = (int)($options['limit'] ?? 100);

    $sql = buildOutsideOperationalDayDetailsSql($whereSql, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Meals Audit: outside operational day\n";
    echo "===================================\n\n";

    if (!$rows) {
        echo "No meal records outside the operational day were found for the selected scope.\n";
        return;
    }

    printf(
        "%-5s | %-19s | %-7s | %-7s | %-7s | %-10s | %-18s | %-14s | %-24s\n",
        'ID',
        'Consumed At',
        'Event',
        'Day',
        'Match',
        'Matches',
        'Class',
        'Participant',
        'Participant Name'
    );
    echo str_repeat('-', 129) . "\n";

    foreach ($rows as $row) {
        printf(
            "%-5s | %-19s | %-7s | %-7s | %-7s | %-10s | %-18s | %-14s | %-24s\n",
            (string)($row['meal_id'] ?? ''),
            (string)($row['consumed_at'] ?? ''),
            (string)($row['event_id'] ?? '-'),
            (string)($row['current_event_day_id'] ?? '-'),
            (string)($row['matched_event_day_id'] ?? '-'),
            (string)($row['matched_day_count'] ?? '0'),
            truncate((string)($row['issue_class'] ?? ''), 18),
            (string)($row['participant_id'] ?? '-'),
            truncate((string)($row['participant_name'] ?? ''), 24)
        );
    }

    echo "\nDetails:\n";
    foreach ($rows as $row) {
        $currentWindow = trim(sprintf(
            '%s -> %s',
            (string)($row['current_day_starts_at'] ?? '-'),
            (string)($row['current_day_ends_at'] ?? '-')
        ));
        $matchedWindow = trim(sprintf(
            '%s -> %s',
            (string)($row['matched_day_starts_at'] ?? '-'),
            (string)($row['matched_day_ends_at'] ?? '-')
        ));
        printf(
            "- meal_id=%s class=%s current_day=%s(%s) matched_day=%s(%s) current_window=%s matched_window=%s service=%s shift=%s matched_ids=%s\n",
            (string)($row['meal_id'] ?? ''),
            (string)($row['issue_class'] ?? ''),
            (string)($row['current_event_day_id'] ?? '-'),
            (string)($row['current_event_day_date'] ?? '-'),
            (string)($row['matched_event_day_id'] ?? '-'),
            (string)($row['matched_event_day_date'] ?? '-'),
            $currentWindow,
            $matchedWindow,
            (string)($row['meal_service_code'] ?? '-'),
            (string)($row['event_shift_id'] ?? '-'),
            (string)($row['matched_event_day_ids'] ?? '-')
        );
    }
}

function runSummaryAudit(PDO $db, array $options): void
{
    $filters = [];
    $params = [];

    if (($options['event'] ?? null) !== null) {
        $filters[] = 'e.id = :event_id';
        $params[':event_id'] = (int)$options['event'];
    }
    if (($options['organizer'] ?? null) !== null) {
        $filters[] = 'e.organizer_id = :organizer_id';
        $params[':organizer_id'] = (int)$options['organizer'];
    }

    $whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

    $sql = "
        SELECT
            e.id AS event_id,
            e.name AS event_name,
            ed.id AS event_day_id,
            ed.date AS event_date,
            COUNT(pm.id)::int AS meals_total,
            COUNT(DISTINCT pm.participant_id)::int AS participants_total
        FROM event_days ed
        JOIN events e ON e.id = ed.event_id
        LEFT JOIN participant_meals pm ON pm.event_day_id = ed.id
        {$whereSql}
        GROUP BY e.id, e.name, ed.id, ed.date
        ORDER BY e.id ASC, ed.date ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Meals Audit: summary\n";
    echo "====================\n\n";

    if (!$rows) {
        echo "No event-day rows found for the selected scope.\n";
        return;
    }

    printf(
        "%-7s | %-24s | %-7s | %-12s | %-11s | %-12s\n",
        'Event',
        'Event Name',
        'Day ID',
        'Day Date',
        'Meals',
        'Participants'
    );
    echo str_repeat('-', 88) . "\n";

    foreach ($rows as $row) {
        printf(
            "%-7s | %-24s | %-7s | %-12s | %-11s | %-12s\n",
            (string)($row['event_id'] ?? ''),
            truncate((string)($row['event_name'] ?? ''), 24),
            (string)($row['event_day_id'] ?? ''),
            (string)($row['event_date'] ?? ''),
            (string)($row['meals_total'] ?? '0'),
            (string)($row['participants_total'] ?? '0')
        );
    }
}

function buildScopeClauses(array $options): array
{
    $params = [];
    $eventFilters = [];
    $participantEventFilters = [];

    if (($options['event'] ?? null) !== null) {
        $eventFilters[] = 'e.id = :event_id';
        $participantEventFilters[] = 'e_scope.id = :event_id';
        $params[':event_id'] = (int)$options['event'];
    }
    if (($options['organizer'] ?? null) !== null) {
        $eventFilters[] = 'e.organizer_id = :organizer_id';
        $participantEventFilters[] = 'e_scope.organizer_id = :organizer_id';
        $params[':organizer_id'] = (int)$options['organizer'];
    }

    $eventWhere = $eventFilters ? (' AND ' . implode(' AND ', $eventFilters)) : '';
    $participantEventWhere = $participantEventFilters ? (' AND ' . implode(' AND ', $participantEventFilters)) : '';

    return [
        'params' => $params,
        'joins' => "
            LEFT JOIN events e ON e.id = ed.event_id
        ",
        'event_join' => "
            JOIN events e ON e.id = ed.event_id
        ",
        'where_suffix' => $eventWhere,
        'where_suffix_no_extra' => $eventWhere,
        'event_where' => $eventWhere,
        'participant_event_join' => "
            LEFT JOIN event_participants ep_scope ON ep_scope.id = pm.participant_id
            LEFT JOIN events e_scope ON e_scope.id = ep_scope.event_id
        ",
        'participant_event_where' => $participantEventWhere,
    ];
}

function truncate(string $value, int $length): string
{
    if (strlen($value) <= $length) {
        return $value;
    }

    return substr($value, 0, max(0, $length - 3)) . '...';
}

function printUsage(): void
{
    echo "Meals audit usage:\n";
    echo "  php backend/scripts/audit_meals.php [recent|integrity|outside-day|summary] [--event=ID] [--organizer=ID] [--limit=100]\n";
}

function buildOutsideOperationalDayCountSql(string $eventWhere): string
{
    return "
        WITH meal_scope AS (
            SELECT
                pm.id,
                pm.consumed_at,
                pm.event_day_id AS current_event_day_id,
                ed.event_id
            FROM participant_meals pm
            JOIN event_days ed ON ed.id = pm.event_day_id
            JOIN events e ON e.id = ed.event_id
            WHERE 1 = 1
            {$eventWhere}
        ),
        matched_days AS (
            SELECT
                ms.id,
                COUNT(ed_match.id)::int AS matched_day_count,
                MIN(ed_match.id) AS matched_event_day_id
            FROM meal_scope ms
            LEFT JOIN event_days ed_match
                   ON ed_match.event_id = ms.event_id
                  AND ms.consumed_at IS NOT NULL
                  AND (
                        (ed_match.starts_at IS NOT NULL AND ed_match.ends_at IS NOT NULL AND ms.consumed_at >= ed_match.starts_at AND ms.consumed_at <= ed_match.ends_at)
                        OR ((ed_match.starts_at IS NULL OR ed_match.ends_at IS NULL) AND ms.consumed_at::date = ed_match.date)
                  )
            GROUP BY ms.id
        )
        SELECT COUNT(*)::int
        FROM meal_scope ms
        LEFT JOIN matched_days md ON md.id = ms.id
        WHERE ms.consumed_at IS NULL
           OR COALESCE(md.matched_day_count, 0) = 0
           OR COALESCE(md.matched_day_count, 0) > 1
           OR (COALESCE(md.matched_day_count, 0) = 1 AND md.matched_event_day_id <> ms.current_event_day_id)
    ";
}

function buildOutsideOperationalDayDetailsSql(string $eventWhere, int $limit): string
{
    return "
        WITH meal_scope AS (
            SELECT
                pm.id AS meal_id,
                pm.participant_id,
                pm.meal_service_id,
                pm.event_shift_id,
                pm.consumed_at,
                ep.event_id,
                p.name AS participant_name,
                e.name AS event_name,
                ed.id AS current_event_day_id,
                ed.date AS current_event_day_date,
                ed.starts_at AS current_day_starts_at,
                ed.ends_at AS current_day_ends_at,
                ems.service_code AS meal_service_code
            FROM participant_meals pm
            JOIN event_participants ep ON ep.id = pm.participant_id
            LEFT JOIN people p ON p.id = ep.person_id
            JOIN event_days ed ON ed.id = pm.event_day_id
            JOIN events e ON e.id = ed.event_id
            LEFT JOIN event_meal_services ems ON ems.id = pm.meal_service_id
            WHERE ep.event_id = ed.event_id
            {$eventWhere}
        ),
        matched_days AS (
            SELECT
                ms.meal_id,
                COUNT(ed_match.id)::int AS matched_day_count,
                MIN(ed_match.id) AS matched_event_day_id,
                MIN(ed_match.date) AS matched_event_day_date,
                MIN(ed_match.starts_at) AS matched_day_starts_at,
                MIN(ed_match.ends_at) AS matched_day_ends_at,
                STRING_AGG(ed_match.id::text, ',' ORDER BY COALESCE(ed_match.starts_at, ed_match.date::timestamp), ed_match.id) AS matched_event_day_ids
            FROM meal_scope ms
            LEFT JOIN event_days ed_match
                   ON ed_match.event_id = ms.event_id
                  AND ms.consumed_at IS NOT NULL
                  AND (
                        (ed_match.starts_at IS NOT NULL AND ed_match.ends_at IS NOT NULL AND ms.consumed_at >= ed_match.starts_at AND ms.consumed_at <= ed_match.ends_at)
                        OR ((ed_match.starts_at IS NULL OR ed_match.ends_at IS NULL) AND ms.consumed_at::date = ed_match.date)
                  )
            GROUP BY ms.meal_id
        ),
        classified AS (
            SELECT
                ms.*,
                COALESCE(md.matched_day_count, 0) AS matched_day_count,
                md.matched_event_day_id,
                md.matched_event_day_date,
                md.matched_day_starts_at,
                md.matched_day_ends_at,
                md.matched_event_day_ids,
                CASE
                    WHEN ms.consumed_at IS NULL THEN 'missing_consumed_at'
                    WHEN COALESCE(md.matched_day_count, 0) = 0 THEN 'no_operational_day_match'
                    WHEN COALESCE(md.matched_day_count, 0) > 1 THEN 'ambiguous_operational_day_match'
                    WHEN md.matched_event_day_id <> ms.current_event_day_id THEN 'wrong_event_day_reference'
                    ELSE 'ok'
                END AS issue_class
            FROM meal_scope ms
            LEFT JOIN matched_days md ON md.meal_id = ms.meal_id
        )
        SELECT
            meal_id,
            consumed_at,
            event_id,
            event_name,
            participant_id,
            participant_name,
            meal_service_id,
            meal_service_code,
            event_shift_id,
            current_event_day_id,
            current_event_day_date,
            current_day_starts_at,
            current_day_ends_at,
            matched_day_count,
            matched_event_day_id,
            matched_event_day_date,
            matched_day_starts_at,
            matched_day_ends_at,
            matched_event_day_ids,
            issue_class
        FROM classified
        WHERE issue_class <> 'ok'
        ORDER BY consumed_at DESC NULLS LAST, meal_id DESC
        LIMIT {$limit}
    ";
}
