<?php
/**
 * Meal Controller
 * Controle de fornecimento/baixa de refeições do staff durante turnos e dias do evento.
 */

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';

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
    mealEnsureMealsReadSchema($db);

    $hasRoleSettings = mealTableExists($db, 'workforce_role_settings');
    $hasMemberSettings = mealTableExists($db, 'workforce_member_settings');
    $hasMealUnitCostColumn = columnExists($db, 'organizer_financial_settings', 'meal_unit_cost');

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

    mealResolveEventContext($db, $organizerId, $eventId, $eventDayId, $eventShiftId);
    $eventScope = mealBuildEventScopeDiagnostics($db, $eventId, $eventDayId, $eventShiftId);

    if ($hasMealUnitCostColumn) {
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

    $roleConfigSelect = $hasRoleSettings
        ? "
            COUNT(DISTINCT COALESCE(wer.meals_per_day, wrs.meals_per_day, 4))::int AS distinct_role_meals_count,
            CASE
                WHEN COUNT(DISTINCT COALESCE(wer.meals_per_day, wrs.meals_per_day, 4)) = 1
                    THEN MAX(COALESCE(wer.meals_per_day, wrs.meals_per_day, 4))
                ELSE NULL
            END::int AS single_role_meals_per_day,
            MAX(CASE WHEN wer.id IS NOT NULL OR wrs.role_id IS NOT NULL THEN 1 ELSE 0 END)::int AS has_any_role_settings
        "
        : "
            1::int AS distinct_role_meals_count,
            4::int AS single_role_meals_per_day,
            0::int AS has_any_role_settings
        ";
    $eventRoleJoin = workforceEventRolesReady($db) && workforceAssignmentsHaveEventRoleColumns($db)
        ? "LEFT JOIN workforce_event_roles wer ON wer.id = scope.event_role_id"
        : "LEFT JOIN LATERAL (SELECT NULL::integer AS id, NULL::integer AS meals_per_day) wer ON TRUE";
    $roleConfigJoin = $hasRoleSettings
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = scope.role_id AND wrs.organizer_id = :organizer_id"
        : "";

    $params = [
        ':event_id' => $eventId,
        ':event_day_id' => $eventDayId,
        ':event_shift_id' => $eventShiftId > 0 ? $eventShiftId : null,
        ':organizer_id' => $organizerId,
    ];

    $sql = "
        WITH event_scope AS (
            SELECT
                wa.id,
                wa.participant_id,
                wa.role_id,
                " . (workforceAssignmentsHaveEventRoleColumns($db) ? "wa.event_role_id" : "NULL::integer AS event_role_id") . ",
                COALESCE(NULLIF(TRIM(COALESCE(wa.sector, '')), ''), 'geral') AS sector,
                es.id AS shift_id,
                es.name AS shift_name
            FROM workforce_assignments wa
            JOIN event_participants ep_scope ON ep_scope.id = wa.participant_id
            JOIN people p_scope ON p_scope.id = ep_scope.person_id
            LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
            WHERE ep_scope.event_id = :event_id
              AND p_scope.organizer_id = :organizer_id
    ";
    if ($sector !== '') {
        $sql .= " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $params[':sector'] = $sector;
    }
    if ($roleId > 0) {
        $sql .= " AND wa.role_id = :role_id";
        $params[':role_id'] = $roleId;
    }
    $sql .= "
        ),
        preferred_scope AS (
            SELECT *
            FROM event_scope scope
            WHERE CAST(:event_shift_id AS integer) IS NOT NULL
              AND scope.shift_id = CAST(:event_shift_id AS integer)
        ),
        assignment_scope AS (
            SELECT *
            FROM preferred_scope
            UNION ALL
            SELECT scope.*
            FROM event_scope scope
            WHERE CAST(:event_shift_id AS integer) IS NULL
               OR NOT EXISTS (
                    SELECT 1
                    FROM preferred_scope preferred
                    WHERE preferred.participant_id = scope.participant_id
               )
        ),
        assignment_rollup AS (
            SELECT
                scope.participant_id,
                COUNT(*)::int AS assignments_in_scope,
                CASE WHEN COUNT(*) > 1 THEN 1 ELSE 0 END::int AS has_multiple_assignments,
                COUNT(DISTINCT scope.role_id)::int AS distinct_roles_count,
                CASE WHEN COUNT(DISTINCT scope.role_id) > 1 THEN 1 ELSE 0 END::int AS has_multiple_roles,
                CASE WHEN COUNT(DISTINCT scope.role_id) = 1 THEN MAX(scope.role_id) ELSE NULL END AS single_role_id,
                COUNT(DISTINCT scope.sector)::int AS distinct_sectors_count,
                CASE WHEN COUNT(DISTINCT scope.sector) > 1 THEN 1 ELSE 0 END::int AS has_multiple_sectors,
                CASE WHEN COUNT(DISTINCT scope.sector) = 1 THEN MAX(scope.sector) ELSE NULL END AS single_sector,
                COUNT(DISTINCT COALESCE(scope.shift_id, 0))::int AS distinct_shift_keys_count,
                CASE WHEN COUNT(DISTINCT COALESCE(scope.shift_id, 0)) > 1 THEN 1 ELSE 0 END::int AS has_multiple_shifts,
                CASE WHEN COUNT(DISTINCT COALESCE(scope.shift_id, 0)) = 1 THEN MAX(scope.shift_id) ELSE NULL END AS single_shift_id,
                CASE
                    WHEN COUNT(DISTINCT COALESCE(scope.shift_id, 0)) = 1 AND MAX(scope.shift_id) IS NOT NULL
                        THEN MAX(scope.shift_name)
                    ELSE NULL
                END AS single_shift_name
            FROM assignment_scope scope
            GROUP BY scope.participant_id
        ),
        role_config_rollup AS (
            SELECT
                scope.participant_id,
                {$roleConfigSelect}
            FROM assignment_scope scope
            {$eventRoleJoin}
            {$roleConfigJoin}
            GROUP BY scope.participant_id
        )
        SELECT
            ep.id AS participant_id,
            ep.qr_token,
            p.name AS participant_name,
            ar.assignments_in_scope,
            ar.has_multiple_assignments,
            ar.has_multiple_roles,
            ar.has_multiple_sectors,
            ar.has_multiple_shifts,
            ar.single_role_id AS role_id,
            r.name AS role_name,
            ar.single_sector AS sector,
            ar.single_shift_id AS shift_id,
            ar.single_shift_name AS shift_name,
            CASE
                WHEN wms.participant_id IS NOT NULL THEN COALESCE(wms.meals_per_day, 4)
                WHEN ar.assignments_in_scope > 1 AND COALESCE(rcr.distinct_role_meals_count, 1) > 1 THEN NULL
                WHEN COALESCE(rcr.distinct_role_meals_count, 1) = 1 THEN COALESCE(rcr.single_role_meals_per_day, 4)
                ELSE 4
            END::int AS meals_per_day,
            CASE
                WHEN wms.participant_id IS NOT NULL THEN 'member_override'
                WHEN ar.assignments_in_scope > 1 AND COALESCE(rcr.distinct_role_meals_count, 1) > 1 THEN 'ambiguous'
                WHEN COALESCE(rcr.distinct_role_meals_count, 1) = 1 AND COALESCE(rcr.has_any_role_settings, 0) = 1 THEN 'role_settings'
                ELSE 'default'
            END AS config_source,
            CASE
                WHEN wms.participant_id IS NULL
                 AND ar.assignments_in_scope > 1
                 AND COALESCE(rcr.distinct_role_meals_count, 1) > 1
                    THEN 1
                ELSE 0
            END::int AS has_ambiguous_baseline,
            COALESCE(day_meals.total_day, 0) AS consumed_day,
            COALESCE(shift_meals.total_shift, 0) AS consumed_shift
        FROM assignment_rollup ar
        JOIN event_participants ep ON ep.id = ar.participant_id
        JOIN people p ON p.id = ep.person_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        LEFT JOIN role_config_rollup rcr ON rcr.participant_id = ep.id
        LEFT JOIN workforce_roles r ON r.id = ar.single_role_id
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
              AND (CAST(:event_shift_id AS integer) IS NULL OR event_shift_id = CAST(:event_shift_id AS integer))
            GROUP BY participant_id
        ) shift_meals ON shift_meals.participant_id = ep.id
        WHERE ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
        ORDER BY p.name ASC
    ";

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
    $configSources = [
        'member_override' => 0,
        'role_settings' => 0,
        'default' => 0,
        'ambiguous' => 0,
    ];

    $normalizedItems = array_map(function ($row) use (&$summary, &$configSources, $mealUnitCost) {
        $mealsPerDay = $row['meals_per_day'] !== null ? (int)$row['meals_per_day'] : null;
        $consumedDay = (int)$row['consumed_day'];
        $consumedShift = (int)$row['consumed_shift'];
        $remainingDay = $mealsPerDay !== null ? max(0, $mealsPerDay - $consumedDay) : null;
        $configSource = (string)($row['config_source'] ?? 'default');
        $assignmentsInScope = (int)($row['assignments_in_scope'] ?? 0);
        $hasAmbiguousBaseline = ((int)($row['has_ambiguous_baseline'] ?? 0)) > 0;
        if (!array_key_exists($configSource, $configSources)) {
            $configSource = 'default';
        }

        $summary['members']++;
        $summary['consumed_day_total'] += $consumedDay;
        $summary['consumed_shift_total'] += $consumedShift;
        $summary['consumed_day_cost_total'] += ($consumedDay * $mealUnitCost);
        $configSources[$configSource]++;
        if ($mealsPerDay !== null) {
            $summary['meals_per_day_total'] += $mealsPerDay;
            $summary['estimated_day_cost_total'] += ($mealsPerDay * $mealUnitCost);
        }
        if ($remainingDay !== null) {
            $summary['remaining_day_total'] += $remainingDay;
            $summary['remaining_day_cost_total'] += ($remainingDay * $mealUnitCost);
        }

        return [
            'participant_id' => (int)$row['participant_id'],
            'participant_name' => $row['participant_name'],
            'qr_token' => $row['qr_token'],
            'assignments_in_scope' => $assignmentsInScope,
            'has_multiple_assignments' => ((int)($row['has_multiple_assignments'] ?? 0)) > 0,
            'has_multiple_roles' => ((int)($row['has_multiple_roles'] ?? 0)) > 0,
            'has_multiple_sectors' => ((int)($row['has_multiple_sectors'] ?? 0)) > 0,
            'has_multiple_shifts' => ((int)($row['has_multiple_shifts'] ?? 0)) > 0,
            'sector' => $row['sector'] !== null ? $row['sector'] : null,
            'role_id' => $row['role_id'] !== null ? (int)$row['role_id'] : null,
            'role_name' => $row['role_name'] ?? null,
            'shift_id' => $row['shift_id'] !== null ? (int)$row['shift_id'] : null,
            'shift_name' => $row['shift_name'] ?? null,
            'meals_per_day' => $mealsPerDay,
            'config_source' => $configSource,
            'baseline_status' => $hasAmbiguousBaseline ? 'ambiguous' : 'resolved',
            'has_ambiguous_baseline' => $hasAmbiguousBaseline,
            'consumed_day' => $consumedDay,
            'remaining_day' => $remainingDay,
            'consumed_shift' => $consumedShift,
            'meal_unit_cost' => round($mealUnitCost, 2),
            'estimated_day_cost' => $mealsPerDay !== null ? round($mealsPerDay * $mealUnitCost, 2) : null,
            'consumed_day_cost' => round($consumedDay * $mealUnitCost, 2),
            'remaining_day_cost' => $remainingDay !== null ? round($remainingDay * $mealUnitCost, 2) : null,
        ];
    }, $items);

    $summary['estimated_day_cost_total'] = round((float)$summary['estimated_day_cost_total'], 2);
    $summary['consumed_day_cost_total'] = round((float)$summary['consumed_day_cost_total'], 2);
    $summary['remaining_day_cost_total'] = round((float)$summary['remaining_day_cost_total'], 2);

    $operationalSummary = [
        'members' => (int)$summary['members'],
        'meals_per_day_total' => (int)$summary['meals_per_day_total'],
        'consumed_day_total' => (int)$summary['consumed_day_total'],
        'remaining_day_total' => (int)$summary['remaining_day_total'],
        'consumed_shift_total' => (int)$summary['consumed_shift_total'],
        'ambiguous_baseline_members' => (int)($configSources['ambiguous'] ?? 0),
    ];
    $projectionSummary = [
        'enabled' => $hasMealUnitCostColumn,
        'meal_unit_cost' => round($mealUnitCost, 2),
        'estimated_day_cost_total' => round((float)$summary['estimated_day_cost_total'], 2),
        'consumed_day_cost_total' => round((float)$summary['consumed_day_cost_total'], 2),
        'remaining_day_cost_total' => round((float)$summary['remaining_day_cost_total'], 2),
    ];
    $diagnostics = mealBuildBalanceDiagnostics(
        $eventScope,
        $configSources,
        $operationalSummary,
        $hasMemberSettings,
        $hasRoleSettings,
        $hasMealUnitCostColumn,
        $mealUnitCost
    );

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
        'operational_summary' => $operationalSummary,
        'projection_summary' => $projectionSummary,
        'diagnostics' => $diagnostics,
        'summary' => $summary,
        'items' => $normalizedItems,
    ], 'Saldo operacional de refeições carregado.');
}

function listMeals(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($query['event_id'] ?? 0);
    $eventDayId = (int)($query['event_day_id'] ?? 0);
    $eventShiftId = (int)($query['event_shift_id'] ?? 0);

    if ($eventId <= 0 && $eventDayId <= 0) {
        jsonError('event_id ou event_day_id é obrigatório.', 400);
    }

    mealResolveEventContext(
        $db,
        $organizerId,
        $eventId > 0 ? $eventId : null,
        $eventDayId > 0 ? $eventDayId : null,
        $eventShiftId > 0 ? $eventShiftId : null
    );

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

    if ($eventId > 0) {
        $sql .= " AND ep.event_id = :evt_id";
        $params[':evt_id'] = $eventId;
    }
    if ($eventDayId > 0) {
        $sql .= " AND pm.event_day_id = :day_id";
        $params[':day_id'] = $eventDayId;
    }
    if ($eventShiftId > 0) {
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
    $eventDayId = (int)($body['event_day_id'] ?? 0);
    $eventShiftId = (int)($body['event_shift_id'] ?? 0);

    if ((!$participantId && !$qrToken) || $eventDayId <= 0) {
        jsonError('participant_id (ou qr_token) e event_day_id são obrigatórios.', 400);
    }

    // Validate Participant tenant and recover event context from participant itself.
    $stmtPart = $db->prepare("
        SELECT ep.id, ep.event_id
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE " . ($participantId ? "ep.id = :participant_id" : "ep.qr_token = :qr_token") . "
          AND e.organizer_id = :organizer_id
        LIMIT 1
    ");
    $params = [':organizer_id' => $organizerId];
    if ($participantId) {
        $params[':participant_id'] = $participantId;
    } else {
        $params[':qr_token'] = $qrToken;
    }
    $stmtPart->execute($params);
    $participant = $stmtPart->fetch(PDO::FETCH_ASSOC);
    if (!$participant) jsonError('Participante inválido ou sem acesso.', 403);
    $participantId = (int)$participant['id'];
    $participantEventId = (int)$participant['event_id'];
    $normalizedEventShiftId = $eventShiftId > 0 ? $eventShiftId : null;

    mealResolveEventContext($db, $organizerId, $participantEventId, $eventDayId, $normalizedEventShiftId);

    try {
        $db->beginTransaction();
        mealAcquireParticipantDayQuotaLock($db, $participantId, $eventDayId);

        $cfg = mealResolveOperationalConfig($db, $participantId, $normalizedEventShiftId);
        if (($cfg['resolution_status'] ?? 'resolved') === 'ambiguous') {
            throw new Exception(
                'Cota de refeições ambígua para este participante neste recorte. Há múltiplos assignments com baselines diferentes e o sistema não fará escolha automática. Harmonize os cargos ou configure override por membro.',
                409
            );
        }

        $maxMealsPerDay = (int)($cfg['meals_per_day'] ?? 4);
        mealAssertDailyQuotaAvailable($db, $participantId, $eventDayId, $maxMealsPerDay);

        $stmt = $db->prepare("
            INSERT INTO participant_meals (participant_id, event_day_id, event_shift_id, consumed_at)
            VALUES (?, ?, ?, NOW())
            RETURNING id
        ");
        $stmt->execute([$participantId, $eventDayId, $normalizedEventShiftId]);
        $mealId = (int)$stmt->fetchColumn();

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $code = (int)$e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
    }

    jsonSuccess(['id' => $mealId], 'Refeição registrada e baixada com sucesso.', 201);
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

function mealResolveEventContext(PDO $db, int $organizerId, ?int $eventId, ?int $eventDayId, ?int $eventShiftId): array
{
    $resolvedEventId = $eventId !== null && $eventId > 0 ? $eventId : null;
    $resolvedEventDayId = $eventDayId !== null && $eventDayId > 0 ? $eventDayId : null;
    $resolvedEventShiftId = $eventShiftId !== null && $eventShiftId > 0 ? $eventShiftId : null;

    if ($resolvedEventId !== null) {
        $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmtEvent->execute([$resolvedEventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) {
            jsonError('Evento não encontrado ou sem acesso.', 404);
        }
    }

    if ($resolvedEventDayId !== null) {
        $stmtDay = $db->prepare("
            SELECT ed.id, ed.event_id
            FROM event_days ed
            JOIN events e ON e.id = ed.event_id
            WHERE ed.id = ? AND e.organizer_id = ?
            LIMIT 1
        ");
        $stmtDay->execute([$resolvedEventDayId, $organizerId]);
        $day = $stmtDay->fetch(PDO::FETCH_ASSOC);
        if (!$day) {
            jsonError('event_day_id inválido ou fora do contexto operacional do organizador.', 404);
        }

        $dayEventId = (int)$day['event_id'];
        if ($resolvedEventId !== null && $dayEventId !== $resolvedEventId) {
            jsonError('event_day_id não pertence ao event_id informado.', 400);
        }

        $resolvedEventId = $dayEventId;
    }

    if ($resolvedEventShiftId !== null) {
        $stmtShift = $db->prepare("
            SELECT es.id, es.event_day_id, ed.event_id
            FROM event_shifts es
            JOIN event_days ed ON ed.id = es.event_day_id
            JOIN events e ON e.id = ed.event_id
            WHERE es.id = ? AND e.organizer_id = ?
            LIMIT 1
        ");
        $stmtShift->execute([$resolvedEventShiftId, $organizerId]);
        $shift = $stmtShift->fetch(PDO::FETCH_ASSOC);
        if (!$shift) {
            jsonError('event_shift_id inválido ou fora do contexto operacional do organizador.', 404);
        }

        $shiftDayId = (int)$shift['event_day_id'];
        $shiftEventId = (int)$shift['event_id'];

        if ($resolvedEventDayId !== null && $shiftDayId !== $resolvedEventDayId) {
            jsonError('event_shift_id não pertence ao event_day_id informado.', 400);
        }

        if ($resolvedEventId !== null && $shiftEventId !== $resolvedEventId) {
            jsonError('event_shift_id não pertence ao event_id informado.', 400);
        }

        $resolvedEventDayId = $shiftDayId;
        $resolvedEventId = $shiftEventId;
    }

    return [
        'event_id' => $resolvedEventId,
        'event_day_id' => $resolvedEventDayId,
        'event_shift_id' => $resolvedEventShiftId,
    ];
}

function mealEnsureMealsReadSchema(PDO $db): void
{
    $requiredTables = [
        'event_days',
        'event_participants',
        'participant_meals',
        'people',
        'workforce_assignments',
        'workforce_roles',
    ];

    foreach ($requiredTables as $table) {
        if (!mealTableExists($db, $table)) {
            jsonError("Base operacional de refeições indisponível: tabela obrigatória '{$table}' ausente.", 409);
        }
    }
}

function mealBuildEventScopeDiagnostics(PDO $db, int $eventId, int $eventDayId, int $eventShiftId): array
{
    $stmtDayCount = $db->prepare("SELECT COUNT(*) FROM event_days WHERE event_id = ?");
    $stmtDayCount->execute([$eventId]);
    $eventDaysCount = (int)$stmtDayCount->fetchColumn();

    $stmtShiftEventCount = $db->prepare("
        SELECT COUNT(*)
        FROM event_shifts es
        JOIN event_days ed ON ed.id = es.event_day_id
        WHERE ed.event_id = ?
    ");
    $stmtShiftEventCount->execute([$eventId]);
    $eventShiftsCount = (int)$stmtShiftEventCount->fetchColumn();

    $stmtShiftDayCount = $db->prepare("SELECT COUNT(*) FROM event_shifts WHERE event_day_id = ?");
    $stmtShiftDayCount->execute([$eventDayId]);
    $selectedDayShiftCount = (int)$stmtShiftDayCount->fetchColumn();

    return [
        'event_days_count' => $eventDaysCount,
        'event_shifts_count' => $eventShiftsCount,
        'selected_day_shift_count' => $selectedDayShiftCount,
        'selected_shift_id' => $eventShiftId > 0 ? $eventShiftId : null,
    ];
}

function mealBuildBalanceDiagnostics(
    array $eventScope,
    array $configSources,
    array $operationalSummary,
    bool $hasMemberSettings,
    bool $hasRoleSettings,
    bool $hasMealUnitCostColumn,
    float $mealUnitCost
): array {
    $issues = [];

    if (($eventScope['event_days_count'] ?? 0) <= 0) {
        $issues[] = 'event_has_no_days';
    }
    if (($eventScope['event_shifts_count'] ?? 0) <= 0) {
        $issues[] = 'event_has_no_shifts';
    }
    if (($eventScope['selected_day_shift_count'] ?? 0) <= 0) {
        $issues[] = 'selected_day_has_no_shifts';
    }
    if (($operationalSummary['members'] ?? 0) <= 0) {
        $issues[] = 'no_assignments_in_scope';
    }
    if (($configSources['default'] ?? 0) > 0) {
        $issues[] = 'members_using_default_meal_fallback';
    }
    if (($configSources['ambiguous'] ?? 0) > 0) {
        $issues[] = 'ambiguous_meal_baseline_in_scope';
    }
    if (($operationalSummary['consumed_day_total'] ?? 0) <= 0) {
        $issues[] = 'no_real_meal_consumption_for_day';
    }
    if (!$hasMealUnitCostColumn) {
        $issues[] = 'meal_unit_cost_schema_unavailable';
    } elseif ($mealUnitCost <= 0) {
        $issues[] = 'meal_unit_cost_not_configured';
    }

    $status = empty($issues) ? 'ready' : ((($operationalSummary['members'] ?? 0) > 0) ? 'partial' : 'insufficient');

    return [
        'status' => $status,
        'issues' => $issues,
        'schema' => [
            'member_settings_table' => $hasMemberSettings,
            'role_settings_table' => $hasRoleSettings,
            'meal_unit_cost_column' => $hasMealUnitCostColumn,
        ],
        'event' => [
            'event_days_count' => (int)($eventScope['event_days_count'] ?? 0),
            'event_shifts_count' => (int)($eventScope['event_shifts_count'] ?? 0),
            'selected_day_shift_count' => (int)($eventScope['selected_day_shift_count'] ?? 0),
            'selected_shift_id' => $eventScope['selected_shift_id'] ?? null,
        ],
        'configuration' => [
            'members_using_member_settings' => (int)($configSources['member_override'] ?? 0),
            'members_using_role_settings' => (int)($configSources['role_settings'] ?? 0),
            'members_using_default_fallback' => (int)($configSources['default'] ?? 0),
            'members_with_ambiguous_baseline' => (int)($configSources['ambiguous'] ?? 0),
        ],
        'consumption' => [
            'has_real_consumption' => (($operationalSummary['consumed_day_total'] ?? 0) > 0),
            'consumed_day_total' => (int)($operationalSummary['consumed_day_total'] ?? 0),
            'consumed_shift_total' => (int)($operationalSummary['consumed_shift_total'] ?? 0),
        ],
        'finance' => [
            'projection_enabled' => $hasMealUnitCostColumn,
            'meal_unit_cost_available' => $hasMealUnitCostColumn,
            'meal_unit_cost_configured' => $hasMealUnitCostColumn && $mealUnitCost > 0,
            'meal_unit_cost' => round($mealUnitCost, 2),
        ],
    ];
}

function mealResolveOperationalConfig(PDO $db, int $participantId, ?int $eventShiftId = null): array
{
    $participantConfig = workforceResolveParticipantOperationalConfig($db, $participantId);
    if (($participantConfig['source'] ?? 'default') === 'event_role') {
        return [
            'max_shifts_event' => (int)($participantConfig['max_shifts_event'] ?? 1),
            'meals_per_day' => (int)($participantConfig['meals_per_day'] ?? 4),
            'config_source' => 'event_role',
            'resolution_status' => 'resolved',
            'assignments_in_scope' => 1,
            'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
        ];
    }

    $hasRoleSettings = mealTableExists($db, 'workforce_role_settings');
    $roleConfigSelect = $hasRoleSettings
        ? "
            COUNT(DISTINCT COALESCE(wer.meals_per_day, wrs.meals_per_day, 4))::int AS distinct_role_meals_count,
            CASE
                WHEN COUNT(DISTINCT COALESCE(wer.meals_per_day, wrs.meals_per_day, 4)) = 1
                    THEN MAX(COALESCE(wer.meals_per_day, wrs.meals_per_day, 4))
                ELSE NULL
            END::int AS single_role_meals_per_day,
            MAX(CASE WHEN wer.id IS NOT NULL OR wrs.role_id IS NOT NULL THEN 1 ELSE 0 END)::int AS has_any_role_settings,
            CASE
                WHEN COUNT(DISTINCT COALESCE(wer.max_shifts_event, wrs.max_shifts_event, 1)) = 1
                    THEN MAX(COALESCE(wer.max_shifts_event, wrs.max_shifts_event, 1))
                ELSE NULL
            END::int AS single_role_max_shifts_event
        "
        : "
            1::int AS distinct_role_meals_count,
            4::int AS single_role_meals_per_day,
            0::int AS has_any_role_settings,
            1::int AS single_role_max_shifts_event
        ";
    $eventRoleJoin = workforceEventRolesReady($db) && workforceAssignmentsHaveEventRoleColumns($db)
        ? "LEFT JOIN workforce_event_roles wer ON wer.id = scope.event_role_id"
        : "LEFT JOIN LATERAL (SELECT NULL::integer AS id, NULL::integer AS meals_per_day, NULL::integer AS max_shifts_event) wer ON TRUE";
    $roleSettingsJoin = $hasRoleSettings
        ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = scope.role_id AND wrs.organizer_id = :organizer_id"
        : "";

    $sql = "
        WITH event_scope AS (
            SELECT wa.role_id, wa.event_shift_id,
                   " . (workforceAssignmentsHaveEventRoleColumns($db) ? "wa.event_role_id" : "NULL::integer AS event_role_id") . "
            FROM workforce_assignments wa
            WHERE wa.participant_id = :participant_id
        ),
        preferred_scope AS (
            SELECT scope.role_id, scope.event_role_id
            FROM event_scope scope
            WHERE :event_shift_id IS NOT NULL
              AND scope.event_shift_id = :event_shift_id
        ),
        assignment_scope AS (
            SELECT role_id, event_role_id
            FROM preferred_scope
            UNION ALL
            SELECT role_id, event_role_id
            FROM event_scope
            WHERE NOT EXISTS (SELECT 1 FROM preferred_scope)
        ),
        role_config_rollup AS (
            SELECT
                COUNT(*)::int AS assignments_in_scope,
                {$roleConfigSelect}
            FROM assignment_scope scope
            {$eventRoleJoin}
            {$roleSettingsJoin}
        )
        SELECT
            CASE WHEN wms.participant_id IS NOT NULL THEN 1 ELSE 0 END::int AS has_member_settings,
            COALESCE(wms.max_shifts_event, 1)::int AS member_max_shifts_event,
            COALESCE(wms.meals_per_day, 4)::int AS member_meals_per_day,
            COALESCE(rcr.assignments_in_scope, 0)::int AS assignments_in_scope,
            COALESCE(rcr.distinct_role_meals_count, 1)::int AS distinct_role_meals_count,
            COALESCE(rcr.single_role_meals_per_day, 4)::int AS single_role_meals_per_day,
            COALESCE(rcr.single_role_max_shifts_event, 1)::int AS single_role_max_shifts_event,
            COALESCE(rcr.has_any_role_settings, 0)::int AS has_any_role_settings
        FROM event_participants ep
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        LEFT JOIN role_config_rollup rcr ON TRUE
        WHERE ep.id = :participant_id
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':participant_id' => $participantId,
        ':event_shift_id' => $eventShiftId,
        ':organizer_id' => $organizerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $hasMemberSettings = ((int)($row['has_member_settings'] ?? 0)) > 0;
    $assignmentsInScope = (int)($row['assignments_in_scope'] ?? 0);
    $distinctRoleMealsCount = (int)($row['distinct_role_meals_count'] ?? 1);
    $hasAnyRoleSettings = ((int)($row['has_any_role_settings'] ?? 0)) > 0;
    $isAmbiguous = !$hasMemberSettings && $assignmentsInScope > 1 && $distinctRoleMealsCount > 1;

    if ($hasMemberSettings) {
        return [
            'max_shifts_event' => (int)($row['member_max_shifts_event'] ?? 1),
            'meals_per_day' => (int)($row['member_meals_per_day'] ?? 4),
            'config_source' => 'member_override',
            'resolution_status' => 'resolved',
            'assignments_in_scope' => $assignmentsInScope,
            'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
        ];
    }

    if ($isAmbiguous) {
        return [
            'max_shifts_event' => 1,
            'meals_per_day' => null,
            'config_source' => 'ambiguous',
            'resolution_status' => 'ambiguous',
            'assignments_in_scope' => $assignmentsInScope,
            'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
        ];
    }

    if ($assignmentsInScope > 0 && $hasAnyRoleSettings) {
        return [
            'max_shifts_event' => (int)($row['single_role_max_shifts_event'] ?? 1),
            'meals_per_day' => (int)($row['single_role_meals_per_day'] ?? 4),
            'config_source' => 'role_settings',
            'resolution_status' => 'resolved',
            'assignments_in_scope' => $assignmentsInScope,
            'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
        ];
    }

    return [
        'max_shifts_event' => 1,
        'meals_per_day' => 4,
        'config_source' => 'default',
        'resolution_status' => 'resolved',
        'assignments_in_scope' => $assignmentsInScope,
        'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
    ];
}

function mealAcquireParticipantDayQuotaLock(PDO $db, int $participantId, int $eventDayId): void
{
    $stmt = $db->prepare('SELECT pg_advisory_xact_lock(?, ?)');
    $stmt->execute([$participantId, $eventDayId]);
}

function mealAssertDailyQuotaAvailable(PDO $db, int $participantId, int $eventDayId, int $maxMealsPerDay): void
{
    if ($maxMealsPerDay <= 0) {
        return;
    }

    $stmtCount = $db->prepare("
        SELECT COUNT(*)
        FROM participant_meals
        WHERE participant_id = ?
          AND event_day_id = ?
    ");
    $stmtCount->execute([$participantId, $eventDayId]);
    $countMeals = (int)$stmtCount->fetchColumn();

    if ($countMeals >= $maxMealsPerDay) {
        throw new Exception('Limite de refeições diárias deste membro foi atingido.', 409);
    }
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
