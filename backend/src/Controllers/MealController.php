<?php
/**
 * Meal Controller
 * Controle de fornecimento/baixa de refeições do staff durante turnos e dias do evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === 'balance' => getMealsBalance($query),
        $method === 'GET'  && $id === null => listMeals($query),
        $method === 'POST' && $id === null => registerMeal($body),
        default => jsonError('Endpoint de Refeições não encontrado.', 404),
    };
}

function getMealsBalance(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $hasRoleSettings = mealTableExists($db, 'workforce_role_settings');

    $eventId = (int)($query['event_id'] ?? 0);
    $eventDayId = (int)($query['event_day_id'] ?? 0);
    $eventShiftId = (int)($query['event_shift_id'] ?? 0);
    $sector = strtolower(trim((string)($query['sector'] ?? '')));
    $roleId = (int)($query['role_id'] ?? 0);
    $mealUnitCost = 0.0;

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 400);
    }
    if ($eventDayId <= 0) {
        jsonError('event_day_id é obrigatório para cálculo de saldo diário.', 400);
    }

    $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $stmtEvent->execute([$eventId, $organizerId]);
    if (!$stmtEvent->fetchColumn()) {
        jsonError('Evento não encontrado ou sem acesso.', 404);
    }

    if (columnExists($db, 'organizer_financial_settings', 'meal_unit_cost')) {
        $stmtMealCost = $db->prepare("
            SELECT COALESCE(meal_unit_cost, 0)
            FROM organizer_financial_settings
            WHERE organizer_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtMealCost->execute([$organizerId]);
        $mealUnitCost = (float)($stmtMealCost->fetchColumn() ?: 0);
    }

    $mealsExpr = $hasRoleSettings
        ? "COALESCE(wms.meals_per_day, wrs.meals_per_day, 4)"
        : "COALESCE(wms.meals_per_day, 4)";
    $roleSettingsJoin = $hasRoleSettings
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = :organizer_id"
        : "";

    $sql = "
        SELECT
            ep.id AS participant_id,
            ep.qr_token,
            p.name AS participant_name,
            COALESCE(wa.sector, 'geral') AS sector,
            r.id AS role_id,
            r.name AS role_name,
            {$mealsExpr}::int AS meals_per_day,
            COALESCE(day_meals.total_day, 0) AS consumed_day,
            COALESCE(shift_meals.total_shift, 0) AS consumed_shift
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        {$roleSettingsJoin}
        LEFT JOIN (
            SELECT participant_id, COUNT(*)::int AS total_day
            FROM participant_meals
            WHERE event_day_id = :event_day_id
            GROUP BY participant_id
        ) day_meals ON day_meals.participant_id = ep.id
        LEFT JOIN (
            SELECT participant_id, COUNT(*)::int AS total_shift
            FROM participant_meals
            WHERE event_day_id = :event_day_id
              " . ($eventShiftId > 0 ? "AND event_shift_id = :event_shift_id" : "") . "
            GROUP BY participant_id
        ) shift_meals ON shift_meals.participant_id = ep.id
        WHERE ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
    ";

    $params = [
        ':event_id' => $eventId,
        ':event_day_id' => $eventDayId,
        ':organizer_id' => $organizerId,
    ];
    if ($eventShiftId > 0) {
        $params[':event_shift_id'] = $eventShiftId;
    }
    if ($sector !== '') {
        $sql .= " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $params[':sector'] = $sector;
    }
    if ($roleId > 0) {
        $sql .= " AND wa.role_id = :role_id";
        $params[':role_id'] = $roleId;
    }
    $sql .= " ORDER BY r.name ASC, p.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $summary = [
        'members' => 0,
        'meals_per_day_total' => 0,
        'consumed_day_total' => 0,
        'remaining_day_total' => 0,
        'consumed_shift_total' => 0,
        'meal_unit_cost' => round($mealUnitCost, 2),
        'estimated_day_cost_total' => 0.0,
        'consumed_day_cost_total' => 0.0,
        'remaining_day_cost_total' => 0.0,
    ];

    $normalizedItems = array_map(function ($row) use (&$summary, $mealUnitCost) {
        $mealsPerDay = (int)$row['meals_per_day'];
        $consumedDay = (int)$row['consumed_day'];
        $consumedShift = (int)$row['consumed_shift'];
        $remainingDay = max(0, $mealsPerDay - $consumedDay);

        $summary['members']++;
        $summary['meals_per_day_total'] += $mealsPerDay;
        $summary['consumed_day_total'] += $consumedDay;
        $summary['remaining_day_total'] += $remainingDay;
        $summary['consumed_shift_total'] += $consumedShift;
        $summary['estimated_day_cost_total'] += ($mealsPerDay * $mealUnitCost);
        $summary['consumed_day_cost_total'] += ($consumedDay * $mealUnitCost);
        $summary['remaining_day_cost_total'] += ($remainingDay * $mealUnitCost);

        return [
            'participant_id' => (int)$row['participant_id'],
            'participant_name' => $row['participant_name'],
            'qr_token' => $row['qr_token'],
            'sector' => $row['sector'],
            'role_id' => (int)$row['role_id'],
            'role_name' => $row['role_name'],
            'meals_per_day' => $mealsPerDay,
            'consumed_day' => $consumedDay,
            'remaining_day' => $remainingDay,
            'consumed_shift' => $consumedShift,
            'meal_unit_cost' => round($mealUnitCost, 2),
            'estimated_day_cost' => round($mealsPerDay * $mealUnitCost, 2),
            'consumed_day_cost' => round($consumedDay * $mealUnitCost, 2),
            'remaining_day_cost' => round($remainingDay * $mealUnitCost, 2),
        ];
    }, $items);

    $summary['estimated_day_cost_total'] = round((float)$summary['estimated_day_cost_total'], 2);
    $summary['consumed_day_cost_total'] = round((float)$summary['consumed_day_cost_total'], 2);
    $summary['remaining_day_cost_total'] = round((float)$summary['remaining_day_cost_total'], 2);

    jsonSuccess([
        'filters' => [
            'event_id' => $eventId,
            'event_day_id' => $eventDayId,
            'event_shift_id' => $eventShiftId > 0 ? $eventShiftId : null,
            'sector' => $sector !== '' ? $sector : null,
            'role_id' => $roleId > 0 ? $roleId : null,
        ],
        'formulas' => [
            'estimated_day_cost_total' => 'meals_per_day_total * meal_unit_cost',
            'consumed_day_cost_total' => 'consumed_day_total * meal_unit_cost',
            'remaining_day_cost_total' => 'remaining_day_total * meal_unit_cost'
        ],
        'summary' => $summary,
        'items' => $normalizedItems,
    ], 'Saldo operacional de refeições carregado.');
}

function listMeals(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = $query['event_id'] ?? null;
    $eventDayId = $query['event_day_id'] ?? null;
    $eventShiftId = $query['event_shift_id'] ?? null;

    if (!$eventId && !$eventDayId) {
        jsonError('event_id ou event_day_id é obrigatório.', 400);
    }

    $sql = "
        SELECT pm.id, pm.consumed_at,
               ep.id as participant_id, p.name as person_name,
               ed.date as event_date,
               es.name as shift_name
        FROM participant_meals pm
        JOIN event_participants ep ON ep.id = pm.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN event_days ed ON ed.id = pm.event_day_id
        LEFT JOIN event_shifts es ON es.id = pm.event_shift_id
        WHERE p.organizer_id = :org_id
    ";

    $params = [':org_id' => $organizerId];

    if ($eventId) {
        $sql .= " AND ep.event_id = :evt_id";
        $params[':evt_id'] = $eventId;
    }
    if ($eventDayId) {
        $sql .= " AND pm.event_day_id = :day_id";
        $params[':day_id'] = $eventDayId;
    }
    if ($eventShiftId) {
        $sql .= " AND pm.event_shift_id = :shift_id";
        $params[':shift_id'] = $eventShiftId;
    }

    $sql .= " ORDER BY pm.consumed_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function registerMeal(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $participantId = $body['participant_id'] ?? null;
    $qrToken = trim((string)($body['qr_token'] ?? ''));
    $eventDayId = $body['event_day_id'] ?? null;
    $eventShiftId = $body['event_shift_id'] ?? null;

    if ((!$participantId && !$qrToken) || !$eventDayId) {
        jsonError('participant_id (ou qr_token) e event_day_id são obrigatórios.', 400);
    }

    // Validate Participant tenant
    $stmtPart = $db->prepare("
        SELECT ep.id FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE " . ($participantId ? "ep.id = :participant_id" : "ep.qr_token = :qr_token") . "
          AND e.organizer_id = :organizer_id
    ");
    $params = [':organizer_id' => $organizerId];
    if ($participantId) {
        $params[':participant_id'] = $participantId;
    } else {
        $params[':qr_token'] = $qrToken;
    }
    $stmtPart->execute($params);
    $resolvedParticipantId = $stmtPart->fetchColumn();
    if (!$resolvedParticipantId) jsonError('Participante inválido ou sem acesso.', 403);
    $participantId = (int)$resolvedParticipantId;

    // Validação por configuração: refeições por dia
    $cfg = mealResolveOperationalConfig($db, $participantId);
    $maxMealsPerDay = (int)($cfg['meals_per_day'] ?? 4);
    if ($maxMealsPerDay > 0) {
        $stmtCount = $db->prepare("
            SELECT COUNT(*)
            FROM participant_meals
            WHERE participant_id = ?
              AND event_day_id = ?
        ");
        $stmtCount->execute([$participantId, $eventDayId]);
        $countMeals = (int)$stmtCount->fetchColumn();
        if ($countMeals >= $maxMealsPerDay) {
            jsonError('Limite de refeições diárias deste membro foi atingido.', 409);
        }
    }

    // Adicionar Refeição
    $stmt = $db->prepare("INSERT INTO participant_meals (participant_id, event_day_id, event_shift_id, consumed_at) VALUES (?, ?, ?, NOW()) RETURNING id");
    $stmt->execute([$participantId, $eventDayId, $eventShiftId ?: null]);

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Refeição registrada e baixada com sucesso.', 201);
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
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

function mealResolveOperationalConfig(PDO $db, int $participantId): array
{
    $hasRoleSettings = mealTableExists($db, 'workforce_role_settings');
    $maxShiftsExpr = $hasRoleSettings
        ? "COALESCE(wms.max_shifts_event, wrs.max_shifts_event, 1)"
        : "COALESCE(wms.max_shifts_event, 1)";
    $mealsExpr = $hasRoleSettings
        ? "COALESCE(wms.meals_per_day, wrs.meals_per_day, 4)"
        : "COALESCE(wms.meals_per_day, 4)";
    $roleSettingsJoin = $hasRoleSettings
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id"
        : "";

    $stmt = $db->prepare("
        SELECT
            {$maxShiftsExpr}::int AS max_shifts_event,
            {$mealsExpr}::int AS meals_per_day
        FROM event_participants ep
        LEFT JOIN workforce_assignments wa ON wa.participant_id = ep.id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        {$roleSettingsJoin}
        WHERE ep.id = ?
        LIMIT 1
    ");
    $stmt->execute([$participantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'max_shifts_event' => (int)($row['max_shifts_event'] ?? 1),
        'meals_per_day' => (int)($row['meals_per_day'] ?? 4),
    ];
}

function mealTableExists(PDO $db, string $table): bool
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
