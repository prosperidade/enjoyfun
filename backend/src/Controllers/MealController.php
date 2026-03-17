<?php
/**
 * Meal Controller
 * Controle de fornecimento/baixa de refeições do staff durante turnos e dias do evento.
 */

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/../Services/MealsDomainService.php';

use EnjoyFun\Services\MealsDomainService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === 'balance' => getMealsBalance($query),
        $method === 'GET'  && $id === 'services' => getMealServices($query),
        $method === 'GET'  && $id === null => listMeals($query),
        $method === 'POST' && $id === 'standalone-qrs' => generateStandaloneMealQr($body),
        $method === 'POST' && $id === 'external-qr' => generateExternalMealQr($body),
        $method === 'POST' && $id === null => registerMeal($body),
        $method === 'PUT'  && $id === 'services' => updateMealServices($body),
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
    $mealServiceId = (int)($query['meal_service_id'] ?? 0);
    $referenceTime = trim((string)($query['reference_time'] ?? ''));
    $sector = strtolower(trim((string)($query['sector'] ?? '')));
    $roleId = (int)($query['role_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 400);
    }
    if ($eventDayId <= 0) {
        jsonError('event_day_id é obrigatório para cálculo de saldo diário.', 400);
    }

    try {
        MealsDomainService::resolveEventContext($db, $organizerId, $eventId, $eventDayId, $eventShiftId > 0 ? $eventShiftId : null);
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
    }
    $eventScope = mealBuildEventScopeDiagnostics($db, $eventId, $eventDayId, $eventShiftId);
    $costContext = MealsDomainService::buildCostContext($db, $organizerId, $eventId);
    $mealServices = $costContext['services'] ?? [];
    $activeMealServices = $costContext['active_services'] ?? [];
    $mealUnitCost = (float)($costContext['fallback_unit_cost'] ?? 0);
    try {
        $selectedMealService = MealsDomainService::resolveMealServiceSelection(
            $db,
            $organizerId,
            $eventId,
            $mealServiceId > 0 ? $mealServiceId : null,
            null,
            $referenceTime !== '' ? $referenceTime : null
        );
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
    }
    $selectedMealServiceId = (int)($selectedMealService['id'] ?? 0);
    $selectedMealServiceCost = round((float)($selectedMealService['unit_cost'] ?? 0), 2);

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
        ':meal_service_id' => $selectedMealServiceId > 0 ? $selectedMealServiceId : null,
        ':legacy_meal_unit_cost' => $mealUnitCost,
        ':organizer_id' => $organizerId,
    ];
    $hasRoleSettings = mealTableExists($db, 'workforce_role_settings');
    $roleConfigJoinFilter = $hasRoleSettings
        ? "LEFT JOIN workforce_role_settings wrs_filter ON wrs_filter.role_id = wa.role_id AND wrs_filter.organizer_id = :organizer_id"
        : "";

    $configParts = workforceBuildOperationalConfigSqlParts($db, 'wa', 'wms_filter', 'wrs_filter', 'wer_filter', 'wr_filter.name');

    $sql = "
        WITH event_scope AS (
            SELECT
                wa.id,
                wa.participant_id,
                wa.role_id,
                " . (workforceAssignmentsHaveEventRoleColumns($db) ? "wa.event_role_id" : "NULL::integer AS event_role_id") . ",
                COALESCE(NULLIF(TRIM(COALESCE(wa.sector, '')), ''), 'geral') AS sector,
                es.id AS shift_id,
                es.name AS shift_name,
                wr_filter.name AS role_name,
                {$configParts['bucket_expr']} AS raw_cost_bucket
            FROM workforce_assignments wa
            JOIN event_participants ep_scope ON ep_scope.id = wa.participant_id
            JOIN people p_scope ON p_scope.id = ep_scope.person_id
            LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
            JOIN workforce_roles wr_filter ON wr_filter.id = wa.role_id
            {$configParts['event_role_join']}
            {$roleConfigJoinFilter}
            WHERE ep_scope.event_id = :event_id
              AND p_scope.organizer_id = :organizer_id
              AND (
                    wa.event_shift_id IS NULL
                    OR es.event_day_id = :event_day_id
                  )
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
                COUNT(DISTINCT scope.raw_cost_bucket)::int AS distinct_cost_buckets_count,
                CASE WHEN COUNT(DISTINCT scope.raw_cost_bucket) = 1 THEN MAX(scope.raw_cost_bucket) ELSE NULL END AS single_cost_bucket,
                MAX(CASE WHEN scope.raw_cost_bucket = 'managerial' THEN 1 ELSE 0 END)::int AS has_managerial_assignments,
                MAX(CASE WHEN scope.raw_cost_bucket = 'operational' THEN 1 ELSE 0 END)::int AS has_operational_assignments,
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
            ep.event_id AS event_id,
            ep.qr_token,
            p.name AS participant_name,
            ar.assignments_in_scope,
            ar.has_multiple_assignments,
            ar.has_multiple_roles,
            ar.has_multiple_sectors,
            ar.has_multiple_shifts,
            ar.single_cost_bucket AS cost_bucket,
            ar.has_managerial_assignments,
            ar.has_operational_assignments,
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
            COALESCE(day_meals.total_day_cost, 0) AS consumed_day_cost,
            COALESCE(service_meals.total_service, 0) AS consumed_service,
            COALESCE(service_meals.total_service_cost, 0) AS consumed_service_cost
        FROM assignment_rollup ar
        JOIN event_participants ep ON ep.id = ar.participant_id
        JOIN people p ON p.id = ep.person_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        LEFT JOIN role_config_rollup rcr ON rcr.participant_id = ep.id
        LEFT JOIN workforce_roles r ON r.id = ar.single_role_id
        LEFT JOIN (
            SELECT
                pm.participant_id,
                COUNT(*)::int AS total_day,
                COALESCE(SUM(COALESCE(pm.unit_cost_applied, ems.unit_cost, :legacy_meal_unit_cost)), 0)::numeric AS total_day_cost
            FROM participant_meals pm
            LEFT JOIN event_meal_services ems ON ems.id = pm.meal_service_id
            WHERE pm.event_day_id = :event_day_id
            GROUP BY pm.participant_id
        ) day_meals ON day_meals.participant_id = ep.id
        LEFT JOIN (
            SELECT
                pm.participant_id,
                COUNT(*)::int AS total_service,
                COALESCE(SUM(COALESCE(pm.unit_cost_applied, ems.unit_cost, :legacy_meal_unit_cost)), 0)::numeric AS total_service_cost
            FROM participant_meals pm
            LEFT JOIN event_meal_services ems ON ems.id = pm.meal_service_id
            WHERE pm.event_day_id = :event_day_id
              AND (
                    CAST(:meal_service_id AS integer) IS NULL
                    OR pm.meal_service_id = CAST(:meal_service_id AS integer)
                  )
            GROUP BY participant_id
        ) service_meals ON service_meals.participant_id = ep.id
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
        'consumed_service_total' => 0,
        'selected_service_members_total' => 0,
        'meal_unit_cost' => round($mealUnitCost, 2),
        'selected_meal_service_unit_cost' => $selectedMealServiceCost,
        'estimated_day_cost_total' => 0.0,
        'consumed_day_cost_total' => 0.0,
        'remaining_day_cost_total' => 0.0,
        'selected_service_estimated_cost_total' => 0.0,
        'selected_service_consumed_cost_total' => 0.0,
    ];
    $configSources = [
        'member_override' => 0,
        'role_settings' => 0,
        'default' => 0,
        'ambiguous' => 0,
    ];

    $normalizedItems = array_map(function ($row) use (&$summary, &$configSources, $mealUnitCost, $activeMealServices, $selectedMealService, $selectedMealServiceCost) {
        $mealsPerDay = $row['meals_per_day'] !== null ? (int)$row['meals_per_day'] : null;
        $consumedDay = (int)$row['consumed_day'];
        $consumedDayCost = round((float)($row['consumed_day_cost'] ?? 0), 2);
        $consumedService = (int)($row['consumed_service'] ?? 0);
        $consumedServiceCost = round((float)($row['consumed_service_cost'] ?? 0), 2);
        $remainingDay = $mealsPerDay !== null ? max(0, $mealsPerDay - $consumedDay) : null;
        $estimatedDayCost = $mealsPerDay !== null
            ? MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost)
            : null;
        $selectedServiceAllowed = $mealsPerDay !== null
            ? mealIsSelectedServiceAllowed($mealsPerDay, $selectedMealService)
            : false;
        $remainingDayCost = $estimatedDayCost !== null ? max(0, round($estimatedDayCost - $consumedDayCost, 2)) : null;
        $configSource = (string)($row['config_source'] ?? 'default');
        $assignmentsInScope = (int)($row['assignments_in_scope'] ?? 0);
        $hasAmbiguousBaseline = ((int)($row['has_ambiguous_baseline'] ?? 0)) > 0;
        $hasManagerialAssignments = ((int)($row['has_managerial_assignments'] ?? 0)) > 0;
        $hasOperationalAssignments = ((int)($row['has_operational_assignments'] ?? 0)) > 0;
        $costBucket = strtolower(trim((string)($row['cost_bucket'] ?? '')));
        if ($costBucket === '') {
            $costBucket = $hasManagerialAssignments && $hasOperationalAssignments
                ? 'mixed'
                : ($hasManagerialAssignments ? 'managerial' : 'operational');
        }
        $roleName = (string)($row['role_name'] ?? '');
        $roleClass = $roleName !== ''
            ? workforceResolveRoleClass($roleName, $costBucket === 'mixed' ? '' : $costBucket)
            : ((((int)($row['has_multiple_roles'] ?? 0)) > 0) ? 'mixed' : 'operational');
        if (!array_key_exists($configSource, $configSources)) {
            $configSource = 'default';
        }

        $summary['members']++;
        $summary['consumed_day_total'] += $consumedDay;
        $summary['consumed_service_total'] += $consumedService;
        $summary['consumed_day_cost_total'] += $consumedDayCost;
        $summary['selected_service_consumed_cost_total'] += $consumedServiceCost;
        $configSources[$configSource]++;
        if ($mealsPerDay !== null) {
            $summary['meals_per_day_total'] += $mealsPerDay;
            $summary['estimated_day_cost_total'] += $estimatedDayCost ?? 0;
        }
        if ($remainingDay !== null) {
            $summary['remaining_day_total'] += $remainingDay;
            $summary['remaining_day_cost_total'] += $remainingDayCost ?? 0;
        }
        if ($selectedServiceAllowed) {
            $summary['selected_service_members_total']++;
            $summary['selected_service_estimated_cost_total'] += $selectedMealServiceCost;
        }

        return [
            'participant_id' => (int)$row['participant_id'],
            'event_id' => (int)($row['event_id'] ?? $eventId),
            'participant_name' => $row['participant_name'],
            'qr_token' => $row['qr_token'],
            'assignments_in_scope' => $assignmentsInScope,
            'has_multiple_assignments' => ((int)($row['has_multiple_assignments'] ?? 0)) > 0,
            'has_multiple_roles' => ((int)($row['has_multiple_roles'] ?? 0)) > 0,
            'has_multiple_sectors' => ((int)($row['has_multiple_sectors'] ?? 0)) > 0,
            'has_multiple_shifts' => ((int)($row['has_multiple_shifts'] ?? 0)) > 0,
            'cost_bucket' => $costBucket,
            'role_class' => $roleClass,
            'sector' => $row['sector'] !== null ? $row['sector'] : null,
            'role_id' => $row['role_id'] !== null ? (int)$row['role_id'] : null,
            'role_name' => $roleName !== '' ? $roleName : null,
            'shift_id' => $row['shift_id'] !== null ? (int)$row['shift_id'] : null,
            'shift_name' => $row['shift_name'] ?? null,
            'meals_per_day' => $mealsPerDay,
            'config_source' => $configSource,
            'baseline_status' => $hasAmbiguousBaseline ? 'ambiguous' : 'resolved',
            'has_ambiguous_baseline' => $hasAmbiguousBaseline,
            'consumed_day' => $consumedDay,
            'remaining_day' => $remainingDay,
            'consumed_service' => $consumedService,
            'selected_service_allowed' => $selectedServiceAllowed,
            'meal_unit_cost' => round($mealUnitCost, 2),
            'selected_meal_service_unit_cost' => $selectedMealServiceCost,
            'estimated_day_cost' => $estimatedDayCost,
            'consumed_day_cost' => $consumedDayCost,
            'remaining_day_cost' => $remainingDayCost,
            'consumed_service_cost' => $consumedServiceCost,
        ];
    }, $items);

    $summary['estimated_day_cost_total'] = round((float)$summary['estimated_day_cost_total'], 2);
    $summary['consumed_day_cost_total'] = round((float)$summary['consumed_day_cost_total'], 2);
    $summary['remaining_day_cost_total'] = round((float)$summary['remaining_day_cost_total'], 2);
    $summary['consumed_shift_total'] = (int)$summary['consumed_service_total'];

    $operationalSummary = [
        'members' => (int)$summary['members'],
        'meals_per_day_total' => (int)$summary['meals_per_day_total'],
        'consumed_day_total' => (int)$summary['consumed_day_total'],
        'remaining_day_total' => (int)$summary['remaining_day_total'],
        'consumed_service_total' => (int)$summary['consumed_service_total'],
        'consumed_shift_total' => (int)$summary['consumed_service_total'],
        'ambiguous_baseline_members' => (int)($configSources['ambiguous'] ?? 0),
    ];
    $projectionSummary = [
        'enabled' => $hasMealUnitCostColumn || !empty($activeMealServices),
        'meal_unit_cost' => round($mealUnitCost, 2),
        'selected_meal_service_unit_cost' => $selectedMealServiceCost,
        'estimated_day_cost_total' => round((float)$summary['estimated_day_cost_total'], 2),
        'consumed_day_cost_total' => round((float)$summary['consumed_day_cost_total'], 2),
        'remaining_day_cost_total' => round((float)$summary['remaining_day_cost_total'], 2),
        'selected_service_estimated_cost_total' => round((float)$summary['selected_service_estimated_cost_total'], 2),
        'selected_service_consumed_cost_total' => round((float)$summary['selected_service_consumed_cost_total'], 2),
    ];
    $diagnostics = mealBuildBalanceDiagnostics(
        $eventScope,
        $configSources,
        $operationalSummary,
        $hasMemberSettings,
        $hasRoleSettings,
        $hasMealUnitCostColumn,
        $mealUnitCost,
        !empty($activeMealServices)
    );

    jsonSuccess([
        'filters' => [
            'event_id' => $eventId,
            'event_day_id' => $eventDayId,
            'event_shift_id' => $eventShiftId > 0 ? $eventShiftId : null,
            'meal_service_id' => $selectedMealServiceId > 0 ? $selectedMealServiceId : null,
            'sector' => $sector !== '' ? $sector : null,
            'role_id' => $roleId > 0 ? $roleId : null,
        ],
        'formulas' => [
            'estimated_day_cost_total' => 'sum(daily_cost_by_member_from_active_meal_services)',
            'consumed_day_cost_total' => 'sum(unit_cost_applied_das_baixas_do_dia)',
            'remaining_day_cost_total' => 'estimated_day_cost_total - consumed_day_cost_total'
        ],
        'meal_services' => $mealServices,
        'selected_meal_service' => $selectedMealService,
        'operational_summary' => $operationalSummary,
        'projection_summary' => $projectionSummary,
        'diagnostics' => $diagnostics,
        'summary' => $summary,
        'items' => $normalizedItems,
    ], 'Saldo operacional de refeições carregado.');
}

function getMealServices(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 400);
    }

    try {
        MealsDomainService::resolveEventContext($db, $organizerId, $eventId, null, null);
        $services = MealsDomainService::ensureEventMealServices($db, $organizerId, $eventId);
        jsonSuccess([
            'event_id' => $eventId,
            'services' => $services,
            'service_costs_label' => MealsDomainService::buildServiceCostLabel($services),
        ], 'Serviços de refeição carregados.');
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
    }
}

function updateMealServices(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);
    $services = $body['services'] ?? [];

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 400);
    }

    try {
        $db->beginTransaction();
        $savedServices = MealsDomainService::saveEventMealServices($db, $organizerId, $eventId, is_array($services) ? $services : []);
        $db->commit();
        jsonSuccess([
            'event_id' => $eventId,
            'services' => $savedServices,
            'service_costs_label' => MealsDomainService::buildServiceCostLabel($savedServices),
        ], 'Serviços de refeição atualizados.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $code = (int)$e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
    }
}

function listMeals(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($query['event_id'] ?? 0);
    $eventDayId = (int)($query['event_day_id'] ?? 0);
    $eventShiftId = (int)($query['event_shift_id'] ?? 0);
    $mealServiceId = (int)($query['meal_service_id'] ?? 0);
    $limit = max(1, min(100, (int)($query['limit'] ?? 30)));

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
               ep.id as participant_id,
               ep.event_id as event_id,
               pm.event_day_id,
               pm.event_shift_id,
               p.name as person_name,
               ed.date as event_date,
               es.name as shift_name,
               ems.id as meal_service_id,
               ems.service_code as meal_service_code,
               ems.label as meal_service_label,
               COALESCE(pm.unit_cost_applied, ems.unit_cost, 0) AS unit_cost_applied
        FROM participant_meals pm
        JOIN event_participants ep ON ep.id = pm.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN event_days ed ON ed.id = pm.event_day_id
        LEFT JOIN event_shifts es ON es.id = pm.event_shift_id
        LEFT JOIN event_meal_services ems ON ems.id = pm.meal_service_id
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
    if ($mealServiceId > 0) {
        $sql .= " AND pm.meal_service_id = :meal_service_id";
        $params[':meal_service_id'] = $mealServiceId;
    }

    $sql .= " ORDER BY pm.consumed_at DESC LIMIT {$limit}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function registerMeal(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $participantId = isset($body['participant_id']) ? (int)$body['participant_id'] : null;
    $qrToken = trim((string)($body['qr_token'] ?? ''));
    $eventDayId = (int)($body['event_day_id'] ?? 0);
    $eventShiftId = (int)($body['event_shift_id'] ?? 0);
    $mealServiceId = (int)($body['meal_service_id'] ?? 0);
    $mealServiceCode = trim((string)($body['meal_service_code'] ?? ''));
    $sector = strtolower(trim((string)($body['sector'] ?? '')));
    $offlineRequestId = trim((string)($body['offline_request_id'] ?? ''));
    $consumedAt = trim((string)($body['consumed_at'] ?? ''));

    if ((!$participantId && !$qrToken) || $eventDayId <= 0) {
        jsonError('participant_id (ou qr_token) e event_day_id são obrigatórios.', 400);
    }

    try {
        $db->beginTransaction();
        $result = MealsDomainService::registerOperationalMealByReference(
            $db,
            $organizerId,
            $participantId,
            $qrToken !== '' ? $qrToken : null,
            $eventDayId,
            $eventShiftId > 0 ? $eventShiftId : null,
            $sector !== '' ? $sector : null,
            $mealServiceId > 0 ? $mealServiceId : null,
            $mealServiceCode !== '' ? $mealServiceCode : null,
            $offlineRequestId !== '' ? $offlineRequestId : null,
            $consumedAt !== '' ? $consumedAt : null
        );
        $db->commit();
        $mealId = (int)($result['id'] ?? 0);
        $alreadyProcessed = !empty($result['already_processed']);
        $mealService = $result['meal_service'] ?? null;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $code = (int)$e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
    }

    jsonSuccess([
        'id' => $mealId,
        'already_processed' => $alreadyProcessed,
        'meal_service' => $mealService,
    ], $alreadyProcessed ? 'Refeição offline já havia sido sincronizada anteriormente.' : 'Refeição registrada e baixada com sucesso.', $alreadyProcessed ? 200 : 201);
}

function generateStandaloneMealQr(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $participantId = (int)($body['participant_id'] ?? 0);
    if ($participantId <= 0) {
        jsonError('participant_id é obrigatório para gerar QR operacional avulso.', 400);
    }

    $participantStmt = $db->prepare("
        SELECT
            ep.id,
            ep.event_id,
            ep.qr_token,
            p.name AS participant_name,
            p.email,
            p.phone
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        JOIN events e ON e.id = ep.event_id
        WHERE ep.id = :participant_id
          AND e.organizer_id = :organizer_id
        LIMIT 1
    ");
    $participantStmt->execute([
        ':participant_id' => $participantId,
        ':organizer_id' => $organizerId,
    ]);
    $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) {
        jsonError('Participante não encontrado no contexto do organizador.', 404);
    }

    $hasRoleSettings = mealTableExists($db, 'workforce_role_settings');
    $assignmentStmt = $db->prepare("
        SELECT
            wa.id,
            COALESCE(TRIM(COALESCE(wa.sector, '')), '') AS sector,
            wr.name AS role_name,
            " . ($hasRoleSettings ? "COALESCE(wrs.cost_bucket, 'operational')" : "'operational'") . " AS configured_cost_bucket
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN events e ON e.id = ep.event_id
        JOIN workforce_roles wr ON wr.id = wa.role_id
        " . ($hasRoleSettings ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = wa.role_id AND wrs.organizer_id = e.organizer_id" : "") . "
        WHERE wa.participant_id = :participant_id
          AND e.organizer_id = :organizer_id
        ORDER BY wa.id ASC
    ");
    $assignmentStmt->execute([
        ':participant_id' => $participantId,
        ':organizer_id' => $organizerId,
    ]);
    $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($assignments)) {
        jsonError('Participante não possui assignment no Workforce para receber QR avulso do Meals.', 422);
    }

    foreach ($assignments as $assignment) {
        if (trim((string)($assignment['sector'] ?? '')) !== '') {
            jsonError('Participante já possui setor definido no Workforce. QR avulso não se aplica.', 422);
        }

        $costBucket = mealNormalizeCostBucket(
            (string)($assignment['configured_cost_bucket'] ?? ''),
            (string)($assignment['role_name'] ?? '')
        );
        if ($costBucket !== 'operational') {
            jsonError('QR avulso do Meals é restrito a membros operacionais.', 422);
        }
    }

    $previousToken = trim((string)($participant['qr_token'] ?? ''));
    mealEnsureParticipantQrToken($db, $participantId);

    $participantStmt->execute([
        ':participant_id' => $participantId,
        ':organizer_id' => $organizerId,
    ]);
    $refreshedParticipant = $participantStmt->fetch(PDO::FETCH_ASSOC);
    $qrToken = trim((string)($refreshedParticipant['qr_token'] ?? ''));
    if ($qrToken === '') {
        jsonError('Falha ao gerar o QR operacional avulso para este participante.', 500);
    }

    jsonSuccess([
        'participant_id' => (int)($refreshedParticipant['id'] ?? 0),
        'event_id' => (int)($refreshedParticipant['event_id'] ?? 0),
        'participant_name' => (string)($refreshedParticipant['participant_name'] ?? ''),
        'email' => (string)($refreshedParticipant['email'] ?? ''),
        'phone' => (string)($refreshedParticipant['phone'] ?? ''),
        'qr_token' => $qrToken,
        'invite_path' => '/invite?token=' . rawurlencode($qrToken),
        'created_now' => $previousToken === '',
    ], 'QR operacional avulso pronto para compartilhamento.');
}

function generateExternalMealQr(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($body['event_id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $mealsPerDay = (int)($body['meals_per_day'] ?? 4);
    $validDays = (int)($body['valid_days'] ?? 1);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 400);
    }
    if ($name === '') {
        jsonError('name é obrigatório para colaborador externo.', 400);
    }
    if ($mealsPerDay < 1 || $mealsPerDay > 10) {
        jsonError('meals_per_day deve ser entre 1 e 10.', 400);
    }
    if ($validDays < 1 || $validDays > 30) {
        jsonError('valid_days deve ser entre 1 e 30.', 400);
    }

    // Validar que o evento pertence ao organizador
    $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
    $stmtEvent->execute([$eventId, $organizerId]);
    if (!$stmtEvent->fetchColumn()) {
        jsonError('Evento não encontrado ou sem acesso.', 404);
    }

    $db->beginTransaction();
    try {
        // Buscar ou criar role de colaborador externo para este organizador
        $stmtRole = $db->prepare("
            SELECT id FROM workforce_roles
            WHERE name = 'Colaborador Externo' AND organizer_id = ?
            LIMIT 1
        ");
        $stmtRole->execute([$organizerId]);
        $roleId = (int)($stmtRole->fetchColumn() ?: 0);
        if ($roleId <= 0) {
            $roleColumns = ['name', 'organizer_id'];
            $roleValues = ["'Colaborador Externo'", ':organizer_id'];
            $roleParams = [':organizer_id' => $organizerId];
            if (columnExists($db, 'workforce_roles', 'sector')) {
                $roleColumns[] = 'sector';
                $roleValues[] = ':sector';
                $roleParams[':sector'] = 'externo';
            }
            if (columnExists($db, 'workforce_roles', 'created_at')) {
                $roleColumns[] = 'created_at';
                $roleValues[] = 'NOW()';
            }
            if (columnExists($db, 'workforce_roles', 'updated_at')) {
                $roleColumns[] = 'updated_at';
                $roleValues[] = 'NOW()';
            }

            $stmtCreateRole = $db->prepare(sprintf(
                'INSERT INTO workforce_roles (%s) VALUES (%s) RETURNING id',
                implode(', ', $roleColumns),
                implode(', ', $roleValues)
            ));
            $stmtCreateRole->execute($roleParams);
            $roleId = (int)$stmtCreateRole->fetchColumn();
        }

        // Criar pessoa
        $stmtPerson = $db->prepare("
            INSERT INTO people (name, phone, email, organizer_id, created_at, updated_at)
            VALUES (:name, :phone, :email, :organizer_id, NOW(), NOW())
            RETURNING id
        ");
        $stmtPerson->execute([
            ':name' => $name,
            ':phone' => $phone !== '' ? $phone : null,
            ':email' => $email !== '' ? $email : null,
            ':organizer_id' => $organizerId,
        ]);
        $personId = (int)$stmtPerson->fetchColumn();

        // Fetch category_id for event_participants
        $stmtStaff = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? AND type = 'staff' LIMIT 1");
        $stmtStaff->execute([$organizerId]);
        $categoryId = (int)($stmtStaff->fetchColumn() ?: 0);
        if ($categoryId <= 0) {
            $stmtAny = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? ORDER BY id ASC LIMIT 1");
            $stmtAny->execute([$organizerId]);
            $categoryId = (int)($stmtAny->fetchColumn() ?: 0);
        }
        if ($categoryId <= 0) {
            throw new Exception('Nenhuma categoria de participantes cadastrada para este organizador.', 422);
        }

        // Criar event_participant com QR token
        $qrToken = bin2hex(random_bytes(20));
        $stmtParticipant = $db->prepare("
            INSERT INTO event_participants (person_id, event_id, category_id, qr_token, created_at)
            VALUES (:person_id, :event_id, :category_id, :qr_token, NOW())
            RETURNING id
        ");
        $stmtParticipant->execute([
            ':person_id' => $personId,
            ':event_id' => $eventId,
            ':category_id' => $categoryId,
            ':qr_token' => $qrToken,
        ]);
        $participantId = (int)$stmtParticipant->fetchColumn();

        // Criar workforce_assignment mínimo (sem setor, sem turno)
        $stmtAssign = $db->prepare("
            INSERT INTO workforce_assignments (participant_id, role_id, sector, created_at)
            VALUES (:participant_id, :role_id, 'externo', NOW())
        ");
        $stmtAssign->execute([
            ':participant_id' => $participantId,
            ':role_id' => $roleId,
        ]);

        // Configurar meals_per_day e max_shifts_event no member_settings
        $hasMemberSettings = mealTableExists($db, 'workforce_member_settings');
        if ($hasMemberSettings) {
            $hasMaxShifts = columnExists($db, 'workforce_member_settings', 'max_shifts_event');
            
            if ($hasMaxShifts) {
                $stmtSettings = $db->prepare("
                    INSERT INTO workforce_member_settings (participant_id, meals_per_day, max_shifts_event, created_at, updated_at)
                    VALUES (:participant_id, :meals_per_day, :max_shifts_event, NOW(), NOW())
                    ON CONFLICT (participant_id) DO UPDATE
                        SET meals_per_day = EXCLUDED.meals_per_day, 
                            max_shifts_event = EXCLUDED.max_shifts_event, 
                            updated_at = NOW()
                ");
                $stmtSettings->execute([
                    ':participant_id' => $participantId,
                    ':meals_per_day' => $mealsPerDay,
                    ':max_shifts_event' => $validDays,
                ]);
            } else {
                $stmtSettings = $db->prepare("
                    INSERT INTO workforce_member_settings (participant_id, meals_per_day, created_at, updated_at)
                    VALUES (:participant_id, :meals_per_day, NOW(), NOW())
                    ON CONFLICT (participant_id) DO UPDATE
                        SET meals_per_day = EXCLUDED.meals_per_day, updated_at = NOW()
                ");
                $stmtSettings->execute([
                    ':participant_id' => $participantId,
                    ':meals_per_day' => $mealsPerDay,
                ]);
            }
        }

        $db->commit();

        jsonSuccess([
            'participant_id' => $participantId,
            'event_id' => $eventId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'meals_per_day' => $mealsPerDay,
            'valid_days' => $validDays,
            'qr_token' => $qrToken,
            'invite_path' => '/invite?token=' . rawurlencode($qrToken),
        ], 'QR de colaborador externo gerado com sucesso.', 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $code = (int)$e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
    }
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
    float $mealUnitCost,
    bool $hasActiveMealServices
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
    if (!$hasActiveMealServices) {
        $issues[] = 'event_has_no_meal_services';
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
            'projection_enabled' => $hasMealUnitCostColumn || $hasActiveMealServices,
            'meal_unit_cost_available' => $hasMealUnitCostColumn,
            'meal_unit_cost_configured' => $hasMealUnitCostColumn && $mealUnitCost > 0,
            'meal_unit_cost' => round($mealUnitCost, 2),
            'event_meal_services_configured' => $hasActiveMealServices,
        ],
    ];
}

function mealResolveOperationalConfig(
    PDO $db,
    int $organizerId,
    int $participantId,
    int $eventDayId,
    ?int $eventShiftId = null,
    ?string $sector = null
): array
{
    $hasRoleSettings = mealTableExists($db, 'workforce_role_settings');
    $hasMemberSettings = mealTableExists($db, 'workforce_member_settings');
    $normalizedSector = strtolower(trim((string)($sector ?? '')));
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
    $memberSettingsJoin = $hasMemberSettings
        ? "LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id"
        : "LEFT JOIN LATERAL (
                SELECT
                    NULL::integer AS participant_id,
                    NULL::integer AS max_shifts_event,
                    NULL::integer AS meals_per_day
           ) wms ON TRUE";
    $sectorScopeSql = '';
    $params = [
        ':participant_id' => $participantId,
        ':event_day_id' => $eventDayId,
        ':event_shift_id' => $eventShiftId,
        ':organizer_id' => $organizerId,
    ];
    if ($normalizedSector !== '') {
        $sectorScopeSql = " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $params[':sector'] = $normalizedSector;
    }

    $sql = "
        WITH event_scope AS (
            SELECT wa.role_id, wa.event_shift_id,
                   " . (workforceAssignmentsHaveEventRoleColumns($db) ? "wa.event_role_id" : "NULL::integer AS event_role_id") . "
            FROM workforce_assignments wa
            LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
            WHERE wa.participant_id = :participant_id
              AND (
                    wa.event_shift_id IS NULL
                    OR es.event_day_id = :event_day_id
                  )
              {$sectorScopeSql}
        ),
        preferred_scope AS (
            SELECT scope.role_id, scope.event_role_id
            FROM event_scope scope
            WHERE CAST(:event_shift_id AS integer) IS NOT NULL
              AND scope.event_shift_id = CAST(:event_shift_id AS integer)
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
        {$memberSettingsJoin}
        LEFT JOIN role_config_rollup rcr ON TRUE
        WHERE ep.id = :participant_id
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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

function mealEnsureParticipantQrToken(PDO $db, int $participantId): void
{
    $stmt = $db->prepare("
        UPDATE event_participants
        SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || id::text)
        WHERE id = ?
          AND (qr_token IS NULL OR TRIM(qr_token) = '')
    ");
    $stmt->execute([$participantId]);
}

function mealNormalizeCostBucket(string $value, string $roleName = ''): string
{
    if (workforceIsOperationalCollectionRoleName($roleName)) {
        return 'operational';
    }

    $normalized = strtolower(trim($value));
    if ($normalized === 'managerial' || $normalized === 'operational') {
        return $normalized;
    }

    $name = strtolower(trim($roleName));
    foreach (['gerente', 'diretor', 'coordenador', 'supervisor', 'lider', 'chefe', 'gestor', 'manager'] as $hint) {
        if ($name !== '' && str_contains($name, $hint)) {
            return 'managerial';
        }
    }

    return 'operational';
}

function mealIsSelectedServiceAllowed(?int $mealsPerDay, ?array $selectedMealService): bool
{
    if ($mealsPerDay === null || $mealsPerDay <= 0 || !$selectedMealService) {
        return false;
    }

    $serviceCode = strtolower(trim((string)($selectedMealService['service_code'] ?? '')));
    $sortOrder = (int)($selectedMealService['sort_order'] ?? 0);
    $rank = match ($serviceCode) {
        'breakfast' => 1,
        'lunch' => 2,
        'afternoon_snack' => 3,
        'dinner' => 4,
        default => match (true) {
            $sortOrder <= 10 => 1,
            $sortOrder <= 20 => 2,
            $sortOrder <= 30 => 3,
            default => 4,
        },
    };

    return $rank <= $mealsPerDay;
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
