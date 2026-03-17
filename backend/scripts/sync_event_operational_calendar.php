<?php
/**
 * Backfill for event_days/event_shifts from events.starts_at/ends_at.
 *
 * Usage:
 *   php backend/scripts/sync_event_operational_calendar.php --event=7 --apply
 *   php backend/scripts/sync_event_operational_calendar.php --organizer=3 --apply
 *   php backend/scripts/sync_event_operational_calendar.php --all --apply
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

loadEnv(BASE_PATH . '/.env');

$options = parseOptions(array_slice($argv, 1));

if (!$options['apply']) {
    fwrite(STDOUT, "Dry-run mode. Add --apply to persist changes.\n\n");
}

try {
    $db = connectDatabase();
    runSync($db, $options);
} catch (Throwable $e) {
    fwrite(STDERR, "Calendar sync failed: " . $e->getMessage() . "\n");
    exit(1);
}

function connectDatabase(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'enjoyfun';
    $user = getenv('DB_USER') ?: 'postgres';
    $pass = getenv('DB_PASS') ?: '070998';

    $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
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
        'all' => false,
        'apply' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--apply') {
            $options['apply'] = true;
            continue;
        }
        if ($arg === '--all') {
            $options['all'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        $key = strtolower(trim((string)($parts[0] ?? '')));
        $value = trim((string)($parts[1] ?? ''));

        switch ($key) {
            case 'event':
            case 'event_id':
                $options['event'] = max(0, (int)$value) ?: null;
                break;
            case 'organizer':
            case 'organizer_id':
                $options['organizer'] = max(0, (int)$value) ?: null;
                break;
        }
    }

    if (!$options['all'] && $options['event'] === null && $options['organizer'] === null) {
        throw new InvalidArgumentException('Use --event=ID, --organizer=ID or --all.');
    }

    return $options;
}

function runSync(PDO $db, array $options): void
{
    $events = loadTargetEvents($db, $options);
    if ($events === []) {
        fwrite(STDOUT, "No events found for the selected scope.\n");
        return;
    }

    fwrite(STDOUT, "Event operational calendar sync\n");
    fwrite(STDOUT, "===============================\n\n");

    foreach ($events as $event) {
        syncSingleEvent($db, $event, (bool)$options['apply']);
    }
}

function loadTargetEvents(PDO $db, array $options): array
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

    $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
    $sql = "
        SELECT
            e.id,
            e.organizer_id,
            e.name,
            e.starts_at,
            e.ends_at
        FROM events e
        {$where}
        ORDER BY e.id ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function syncSingleEvent(PDO $db, array $event, bool $apply): void
{
    $eventId = (int)($event['id'] ?? 0);
    $name = (string)($event['name'] ?? '');
    $calendar = buildDerivedEventDaysCalendar($event);

    if ($eventId <= 0 || $calendar === []) {
        fwrite(STDOUT, "[skip] Event {$eventId} {$name}: invalid window.\n");
        return;
    }

    $currentDays = loadCurrentDays($db, $eventId);
    $currentSignature = buildCalendarSignature($currentDays);
    $targetSignature = buildCalendarSignature($calendar);

    if ($currentSignature === $targetSignature) {
        fwrite(STDOUT, "[ok]   Event {$eventId} {$name}: calendar already in sync.\n");
        return;
    }

    if (eventOperationalCalendarHasLiveDependencies($db, $eventId)) {
        fwrite(STDOUT, "[skip] Event {$eventId} {$name}: live dependencies found.\n");
        return;
    }

    fwrite(
        STDOUT,
        sprintf(
            "[plan] Event %d %s: %d current day(s) -> %d derived day(s).\n",
            $eventId,
            $name,
            count($currentDays),
            count($calendar)
        )
    );

    if (!$apply) {
        return;
    }

    $db->beginTransaction();
    try {
        $stmtDeleteShifts = $db->prepare("
            DELETE FROM event_shifts
            WHERE event_day_id IN (
                SELECT id
                FROM event_days
                WHERE event_id = ?
            )
        ");
        $stmtDeleteShifts->execute([$eventId]);

        $stmtDeleteDays = $db->prepare("DELETE FROM event_days WHERE event_id = ?");
        $stmtDeleteDays->execute([$eventId]);

        $stmtCreateDay = $db->prepare("
            INSERT INTO event_days (event_id, date, starts_at, ends_at, created_at)
            VALUES (?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtCreateShift = $db->prepare("
            INSERT INTO event_shifts (event_day_id, name, starts_at, ends_at, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");

        foreach ($calendar as $item) {
            $stmtCreateDay->execute([
                $eventId,
                $item['date'],
                $item['starts_at'],
                $item['ends_at'],
            ]);
            $eventDayId = (int)$stmtCreateDay->fetchColumn();
            $stmtCreateShift->execute([
                $eventDayId,
                'Turno Único',
                $item['starts_at'],
                $item['ends_at'],
            ]);
        }

        $db->commit();
        fwrite(STDOUT, "[done] Event {$eventId} {$name}: calendar synchronized.\n");
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function loadCurrentDays(PDO $db, int $eventId): array
{
    $stmt = $db->prepare("
        SELECT
            ed.date,
            ed.starts_at,
            ed.ends_at
        FROM event_days ed
        WHERE ed.event_id = ?
        ORDER BY ed.date ASC, ed.id ASC
    ");
    $stmt->execute([$eventId]);
    return $stmt->fetchAll() ?: [];
}

function buildCalendarSignature(array $days): string
{
    $normalized = array_map(
        static fn(array $day): string => implode('|', [
            (string)($day['date'] ?? ''),
            normalizeDateTimeValue($day['starts_at'] ?? null),
            normalizeDateTimeValue($day['ends_at'] ?? null),
        ]),
        $days
    );

    return implode(';', $normalized);
}

function normalizeDateTimeValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable((string)$value))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return (string)$value;
    }
}

function buildDerivedEventDaysCalendar(array $payload): array
{
    $startsAt = trim((string)($payload['starts_at'] ?? ''));
    if ($startsAt === '') {
        return [];
    }

    try {
        $eventStart = new DateTimeImmutable($startsAt);
    } catch (Throwable) {
        return [];
    }

    $endsAt = trim((string)($payload['ends_at'] ?? ''));
    try {
        $eventEnd = $endsAt !== '' ? new DateTimeImmutable($endsAt) : $eventStart;
    } catch (Throwable) {
        $eventEnd = $eventStart;
    }
    if ($eventEnd < $eventStart) {
        $eventEnd = $eventStart;
    }

    $currentDay = $eventStart->setTime(0, 0, 0);
    $lastDay = $eventEnd->setTime(0, 0, 0);
    $startDateKey = $eventStart->format('Y-m-d');
    $endDateKey = $eventEnd->format('Y-m-d');
    $days = [];

    while ($currentDay <= $lastDay) {
        $dateKey = $currentDay->format('Y-m-d');
        $dayStart = $dateKey === $startDateKey
            ? $eventStart
            : $currentDay->setTime(0, 0, 0);
        $dayEnd = $dateKey === $endDateKey
            ? $eventEnd
            : $currentDay->setTime(23, 59, 59);
        if ($dayEnd < $dayStart) {
            $dayEnd = $dayStart;
        }

        $days[] = [
            'date' => $dateKey,
            'starts_at' => $dayStart->format('Y-m-d H:i:s'),
            'ends_at' => $dayEnd->format('Y-m-d H:i:s'),
        ];

        $currentDay = $currentDay->modify('+1 day');
    }

    return $days;
}

function eventOperationalCalendarHasLiveDependencies(PDO $db, int $eventId): bool
{
    $stmtMeals = $db->prepare("
        SELECT COUNT(*)
        FROM participant_meals pm
        JOIN event_days ed ON ed.id = pm.event_day_id
        WHERE ed.event_id = ?
    ");
    $stmtMeals->execute([$eventId]);
    if ((int)$stmtMeals->fetchColumn() > 0) {
        return true;
    }

    $stmtAssignments = $db->prepare("
        SELECT COUNT(*)
        FROM workforce_assignments wa
        JOIN event_shifts es ON es.id = wa.event_shift_id
        JOIN event_days ed ON ed.id = es.event_day_id
        WHERE ed.event_id = ?
    ");
    $stmtAssignments->execute([$eventId]);
    if ((int)$stmtAssignments->fetchColumn() > 0) {
        return true;
    }

    return false;
}
