<?php
/**
 * Organizer Finance Controller
 * Consolida gateways e configurações financeiras por organizer.
 */

require_once BASE_PATH . '/src/Services/PaymentGatewayService.php';
require_once BASE_PATH . '/src/Services/FinancialSettingsService.php';
require_once BASE_PATH . '/src/Services/MealsDomainService.php';
require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';

use EnjoyFun\Services\PaymentGatewayService;
use EnjoyFun\Services\FinancialSettingsService;
use EnjoyFun\Services\MealsDomainService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $numericSub = $sub !== null && ctype_digit((string)$sub);

    match (true) {
        $method === 'GET'  && $id === 'workforce-costs' => getWorkforceCosts($query),

        // Gateways CRUD
        $method === 'GET'    && $id === 'gateways' && $sub === null => listPaymentGateways(),
        $method === 'POST'   && $id === 'gateways' && $sub === null => createPaymentGateway($body),
        $method === 'PUT'    && $id === 'gateways' && $numericSub && $subId === null => updatePaymentGateway((int)$sub, $body),
        $method === 'DELETE' && $id === 'gateways' && $numericSub && $subId === null => deletePaymentGateway((int)$sub),
        $method === 'PATCH'  && $id === 'gateways' && $numericSub && $subId === 'primary' => setPrimaryGateway((int)$sub),
        $method === 'PATCH'  && $id === 'gateways' && $numericSub && ($subId === 'active' || $subId === 'status') => setGatewayStatus((int)$sub, $body),
        $method === 'POST'   && $id === 'gateways' && $sub === 'test' => testGatewayConnectionEndpoint($body, null),
        $method === 'POST'   && $id === 'gateways' && $numericSub && $subId === 'test' => testGatewayConnectionEndpoint($body, (int)$sub),

        // Financial settings (isolado)
        $method === 'GET'  && $id === 'settings' => getFinancialSettings(),
        $method === 'PUT'  && $id === 'settings' => updateFinancialSettings($body),

        // Compatibilidade com frontend atual
        $method === 'GET'  && $id === null => getFinanceConfig(),
        $method === 'PUT'  && $id === null => updateFinanceConfig($body),
        $method === 'POST' && $id === 'test' => testGatewayLegacy($body),

        default => jsonError('Finance endpoint não encontrado.', 404),
    };
}

function listPaymentGateways(): void
{
    [$db, $organizerId] = getFinanceContext();
    $gateways = PaymentGatewayService::listGateways($db, $organizerId);
    jsonSuccess($gateways, 'Gateways carregados com sucesso.');
}

function createPaymentGateway(array $body): void
{
    [$db, $organizerId, $user] = getFinanceContext();
    try {
        $gateway = PaymentGatewayService::createGateway($db, $organizerId, $body);
        financeAudit('finance.gateway.create', 'organizer_payment_gateways', $gateway['id'] ?? null, null, $gateway, $user);
        jsonSuccess($gateway, 'Gateway criado com sucesso.', 201);
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }
}

function updatePaymentGateway(int $gatewayId, array $body): void
{
    [$db, $organizerId, $user] = getFinanceContext();
    try {
        $before = PaymentGatewayService::getGatewayById($db, $organizerId, $gatewayId);
        $gateway = PaymentGatewayService::updateGateway($db, $organizerId, $gatewayId, $body);
        financeAudit('finance.gateway.update', 'organizer_payment_gateways', $gatewayId, $before, $gateway, $user);
        jsonSuccess($gateway, 'Gateway atualizado com sucesso.');
    } catch (\InvalidArgumentException $e) {
        $code = str_contains(strtolower($e->getMessage()), 'não encontrado') ? 404 : 422;
        jsonError($e->getMessage(), $code);
    }
}

function deletePaymentGateway(int $gatewayId): void
{
    [$db, $organizerId, $user] = getFinanceContext();
    try {
        $before = PaymentGatewayService::getGatewayById($db, $organizerId, $gatewayId);
        PaymentGatewayService::deleteGateway($db, $organizerId, $gatewayId);
        financeAudit('finance.gateway.delete', 'organizer_payment_gateways', $gatewayId, $before, null, $user);
        jsonSuccess([], 'Gateway excluído com sucesso.');
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 404);
    }
}

function setPrimaryGateway(int $gatewayId): void
{
    [$db, $organizerId, $user] = getFinanceContext();
    try {
        $gateway = PaymentGatewayService::setPrimaryGateway($db, $organizerId, $gatewayId);
        financeAudit('finance.gateway.set_primary', 'organizer_payment_gateways', $gatewayId, null, $gateway, $user);
        jsonSuccess($gateway, 'Gateway principal definido com sucesso.');
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }
}

function setGatewayStatus(int $gatewayId, array $body): void
{
    [$db, $organizerId, $user] = getFinanceContext();
    $isActive = filter_var($body['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    try {
        $before = PaymentGatewayService::getGatewayById($db, $organizerId, $gatewayId);
        $gateway = PaymentGatewayService::setGatewayActive($db, $organizerId, $gatewayId, $isActive);
        financeAudit('finance.gateway.set_status', 'organizer_payment_gateways', $gatewayId, $before, $gateway, $user);
        jsonSuccess($gateway, 'Status do gateway atualizado com sucesso.');
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 404);
    }
}

function testGatewayConnectionEndpoint(array $body, ?int $gatewayId): void
{
    [$db, $organizerId] = getFinanceContext();
    try {
        $result = PaymentGatewayService::testGatewayConnection($db, $organizerId, $body, $gatewayId);
        if (($result['connected'] ?? false) === true) {
            jsonSuccess($result, 'Teste de conexão concluído com sucesso.');
        }
        jsonError($result['message'] ?? 'Falha no teste de conexão.', 422);
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }
}

function getFinancialSettings(): void
{
    [$db, $organizerId] = getFinanceContext();
    $settings = FinancialSettingsService::getSettings($db, $organizerId);
    jsonSuccess($settings, 'Configurações financeiras carregadas.');
}

function updateFinancialSettings(array $body): void
{
    [$db, $organizerId, $user] = getFinanceContext();
    try {
        $before = FinancialSettingsService::getSettings($db, $organizerId);
        $settings = FinancialSettingsService::saveSettings($db, $organizerId, $body);
        financeAudit('finance.settings.update', 'organizer_financial_settings', $settings['id'] ?? null, $before, $settings, $user);
        jsonSuccess($settings, 'Configurações financeiras atualizadas.');
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }
}

function testGatewayLegacy(array $body): void
{
    testGatewayConnectionEndpoint($body, null);
}

function getFinanceConfig(): void
{
    [$db, $organizerId] = getFinanceContext();

    $gateways = PaymentGatewayService::listGateways($db, $organizerId);
    $settings = FinancialSettingsService::getSettings($db, $organizerId);

    $primaryGateway = null;
    foreach ($gateways as $g) {
        if (!empty($g['is_primary'])) {
            $primaryGateway = $g;
            break;
        }
    }
    if (!$primaryGateway) {
        foreach ($gateways as $g) {
            if (!empty($g['is_active'])) {
                $primaryGateway = $g;
                break;
            }
        }
    }

    jsonSuccess([
        'gateways' => $gateways,
        'gateway_provider' => $primaryGateway['provider'] ?? 'mercadopago',
        'gateway_active' => $primaryGateway['is_active'] ?? false,
        'credentials' => $primaryGateway['credentials'] ?? ['has_token' => false, 'public_key' => ''],
        'currency' => $settings['currency'] ?? 'BRL',
        'tax_rate' => $settings['tax_rate'] ?? 0.0,
        'meal_unit_cost' => $settings['meal_unit_cost'] ?? 0.0,
    ]);
}

function updateFinanceConfig(array $body): void
{
    [$db, $organizerId, $user] = getFinanceContext();
    $db->beginTransaction();
    try {
        $provider = (string)($body['gateway_provider'] ?? $body['provider'] ?? '');
        $updatedGateway = null;

        if ($provider !== '') {
            $existing = PaymentGatewayService::findByProvider($db, $organizerId, $provider);
            if ($existing) {
                $updatedGateway = PaymentGatewayService::updateGateway($db, $organizerId, (int)$existing['id'], $body);
            } else {
                $updatedGateway = PaymentGatewayService::createGateway($db, $organizerId, $body);
            }

            $wantPrimary = filter_var($body['is_primary'] ?? $body['is_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($wantPrimary && isset($updatedGateway['id'])) {
                $updatedGateway = PaymentGatewayService::setPrimaryGateway($db, $organizerId, (int)$updatedGateway['id']);
            }
        }

        $settings = FinancialSettingsService::saveSettings($db, $organizerId, $body);
        $db->commit();
        financeAudit('finance.config.update', 'organizer_finance', $organizerId, null, [
            'gateway' => $updatedGateway,
            'settings' => $settings
        ], $user);

        jsonSuccess([
            'gateway' => $updatedGateway,
            'currency' => $settings['currency'] ?? 'BRL',
            'tax_rate' => $settings['tax_rate'] ?? 0.0,
            'meal_unit_cost' => $settings['meal_unit_cost'] ?? 0.0,
        ], 'Configurações financeiras salvas com sucesso.');
    } catch (\InvalidArgumentException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError($e->getMessage(), 422);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao salvar as configurações financeiras: ' . $e->getMessage(), 500);
    }
}

function getWorkforceCosts(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $eventId = (int)($query['event_id'] ?? 0);
    $roleId = (int)($query['role_id'] ?? 0);
    $requestedSector = normalizeSector((string)($query['sector'] ?? ''));
    $effectiveSector = $canBypassSector ? $requestedSector : ($userSector !== 'all' ? $userSector : $requestedSector);
    $hasRoleSettings = tableExists($db, 'workforce_role_settings');
    $hasMealUnitCost = columnExists($db, 'organizer_financial_settings', 'meal_unit_cost');
    $hasEventRoles = workforceEventRolesReady($db) && workforceAssignmentsHaveEventRoleColumns($db);
    $hasParticipantCheckins = tableExists($db, 'participant_checkins');
    $presenceByParticipantId = [];

    $mealUnitCost = 0.0;
    if ($hasMealUnitCost) {
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
    $mealCostContext = $eventId > 0
        ? MealsDomainService::buildCostContext($db, $organizerId, $eventId)
        : [
            'services' => [],
            'active_services' => [],
            'fallback_unit_cost' => $mealUnitCost,
        ];
    $activeMealServices = $mealCostContext['active_services'] ?? [];
    $mealServicesCostLabel = MealsDomainService::buildServiceCostLabel($mealCostContext['services'] ?? []);

    if ($hasParticipantCheckins) {
        $sqlPresence = "
            SELECT DISTINCT ep.id AS participant_id
            FROM event_participants ep
            JOIN events e ON e.id = ep.event_id
            LEFT JOIN participant_checkins pc
              ON pc.participant_id = ep.id
             AND LOWER(COALESCE(pc.action, '')) = 'check-in'
            WHERE e.organizer_id = :organizer_id
              AND (
                LOWER(COALESCE(ep.status, '')) = 'present'
                OR pc.id IS NOT NULL
              )
        ";
        $paramsPresence = [':organizer_id' => $organizerId];
        if ($eventId > 0) {
            $sqlPresence .= " AND ep.event_id = :event_id";
            $paramsPresence[':event_id'] = $eventId;
        }

        $stmtPresence = $db->prepare($sqlPresence);
        $stmtPresence->execute($paramsPresence);
        foreach ($stmtPresence->fetchAll(PDO::FETCH_ASSOC) ?: [] as $presenceRow) {
            $presenceByParticipantId[(int)($presenceRow['participant_id'] ?? 0)] = true;
        }
        $presentMembersTotal = 0;
    }

    // Regra de domínio: valor/configuração de cargo não deve ser herdado pelos trabalhadores.
    // Custos de trabalhadores vêm apenas de workforce_member_settings (ou default operacional).
    $maxShiftsExpr = $hasEventRoles
        ? "COALESCE(wms.max_shifts_event, wer.max_shifts_event, 1)"
        : "COALESCE(wms.max_shifts_event, 1)";
    $shiftHoursExpr = $hasEventRoles
        ? "COALESCE(wms.shift_hours, wer.shift_hours, 8)"
        : "COALESCE(wms.shift_hours, 8)";
    $mealsExpr = $hasEventRoles
        ? "COALESCE(wms.meals_per_day, wer.meals_per_day, 4)"
        : "COALESCE(wms.meals_per_day, 4)";
    $paymentExpr = $hasEventRoles
        ? "COALESCE(wms.payment_amount, wer.payment_amount, 0)"
        : "COALESCE(wms.payment_amount, 0)";
    $bucketExpr = "'operational'";
    $sql = "
        SELECT
            wa.id AS assignment_id,
            ep.event_id,
            wa.sector,
            wa.role_id,
            r.name AS role_name,
            ep.id AS participant_id,
            p.name AS participant_name,
            {$paymentExpr}::numeric AS payment_amount,
            {$maxShiftsExpr}::int AS max_shifts_event,
            {$shiftHoursExpr}::numeric AS shift_hours,
            {$mealsExpr}::int AS meals_per_day,
            {$bucketExpr}::varchar AS cost_bucket
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN events e ON e.id = ep.event_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        " . ($hasEventRoles ? "LEFT JOIN workforce_event_roles wer ON wer.id = wa.event_role_id" : "") . "
        WHERE e.organizer_id = :organizer_id
    ";

    $params = [':organizer_id' => $organizerId];
    if ($eventId > 0) {
        $sql .= " AND ep.event_id = :event_id";
        $params[':event_id'] = $eventId;
    }
    if ($roleId > 0) {
        $sql .= " AND wa.role_id = :role_id";
        $params[':role_id'] = $roleId;
    }
    if ($effectiveSector !== '') {
        $sql .= " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        $params[':sector'] = $effectiveSector;
    }
    $sql .= " ORDER BY wa.sector ASC, r.name ASC, p.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalMembers = 0;
    $plannedMembersTotal = 0;
    $filledMembersTotal = 0;
    $presentMembersTotal = null;
    $leadershipPositionsTotal = 0;
    $leadershipFilledTotal = 0;
    $leadershipPlaceholderTotal = 0;
    $operationalMembersTotal = 0;
    $totalEstimatedPayment = 0.0;
    $totalEstimatedHours = 0.0;
    $totalEstimatedMeals = 0;
    $totalEstimatedMealsCost = 0.0;
    $managerialRolesPaymentTotal = 0.0;
    $operationalMembersPaymentTotal = 0.0;
    $bySector = [];
    $byRole = [];
    $byRoleManagerial = [];
    $operationalMembers = [];
    $items = [];

    $ensureSectorRow = static function (array &$collection, string $sector) use ($hasParticipantCheckins): void {
        if (!isset($collection[$sector])) {
            $collection[$sector] = [
                'sector' => $sector,
                'members' => 0,
                'planned_members_total' => 0,
                'filled_members_total' => 0,
                'present_members_total' => $hasParticipantCheckins ? 0 : null,
                'leadership_positions_total' => 0,
                'leadership_filled_total' => 0,
                'leadership_placeholder_total' => 0,
                'operational_members_total' => 0,
                'estimated_payment_total' => 0.0,
                'estimated_hours_total' => 0.0,
                'estimated_meals_total' => 0,
                'estimated_meals_cost_total' => 0.0,
            ];
        }
    };

    foreach ($rows as $row) {
        $sector = normalizeSector((string)($row['sector'] ?? '')) ?: 'geral';
        $roleName = (string)($row['role_name'] ?? 'Sem Cargo');
        $paymentAmount = (float)$row['payment_amount'];
        $maxShifts = (int)$row['max_shifts_event'];
        $shiftHours = (float)$row['shift_hours'];
        $mealsPerDay = (int)$row['meals_per_day'];
        $costBucket = 'operational';

        $estimatedPayment = round($paymentAmount * $maxShifts, 2);
        $estimatedHours = round($maxShifts * $shiftHours, 2);
        $estimatedMeals = $maxShifts * $mealsPerDay;
        $estimatedMealsCost = round(
            $maxShifts * MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost),
            2
        );
        $participantIsPresent = !empty($presenceByParticipantId[(int)($row['participant_id'] ?? 0)]);

        $items[] = [
            'assignment_id' => (int)$row['assignment_id'],
            'event_id' => (int)$row['event_id'],
            'participant_id' => (int)$row['participant_id'],
            'participant_name' => (string)$row['participant_name'],
            'sector' => $sector,
            'role_id' => (int)$row['role_id'],
            'role_name' => $roleName,
            'payment_amount' => $paymentAmount,
            'max_shifts_event' => $maxShifts,
            'shift_hours' => $shiftHours,
            'meals_per_day' => $mealsPerDay,
            'cost_bucket' => $costBucket,
            'estimated_payment_total' => $estimatedPayment,
            'estimated_hours_total' => $estimatedHours,
            'estimated_meals_total' => $estimatedMeals,
            'estimated_meals_cost_total' => $estimatedMealsCost,
            'is_present' => $participantIsPresent,
        ];

        $totalMembers++;
        $plannedMembersTotal++;
        $filledMembersTotal++;
        if ($hasParticipantCheckins && $participantIsPresent) {
            $presentMembersTotal++;
        }
        $totalEstimatedPayment += $estimatedPayment;
        $totalEstimatedHours += $estimatedHours;
        $totalEstimatedMeals += $estimatedMeals;
        $totalEstimatedMealsCost += $estimatedMealsCost;
        $operationalMembersPaymentTotal += $estimatedPayment;
        $operationalMembersTotal++;
        $operationalMembers[] = end($items);

        $ensureSectorRow($bySector, $sector);
        $bySector[$sector]['members']++;
        $bySector[$sector]['planned_members_total']++;
        $bySector[$sector]['filled_members_total']++;
        if ($hasParticipantCheckins && $participantIsPresent) {
            $bySector[$sector]['present_members_total']++;
        }
        $bySector[$sector]['operational_members_total']++;
        $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
        $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
        $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
        $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

        $roleKey = $sector . '::' . $roleName;
        if (!isset($byRole[$roleKey])) {
            $byRole[$roleKey] = [
                'sector' => $sector,
                'role_name' => $roleName,
                'cost_bucket' => $costBucket,
                'members' => 0,
                'planned_members_total' => 0,
                'filled_members_total' => 0,
                'leadership_positions_total' => 0,
                'leadership_filled_total' => 0,
                'leadership_placeholder_total' => 0,
                'operational_members_total' => 0,
                'estimated_payment_total' => 0.0,
                'estimated_hours_total' => 0.0,
                'estimated_meals_total' => 0,
                'estimated_meals_cost_total' => 0.0,
            ];
        }
        $byRole[$roleKey]['members']++;
        $byRole[$roleKey]['planned_members_total']++;
        $byRole[$roleKey]['filled_members_total']++;
        $byRole[$roleKey]['operational_members_total']++;
        $byRole[$roleKey]['estimated_payment_total'] += $estimatedPayment;
        $byRole[$roleKey]['estimated_hours_total'] += $estimatedHours;
        $byRole[$roleKey]['estimated_meals_total'] += $estimatedMeals;
        $byRole[$roleKey]['estimated_meals_cost_total'] += $estimatedMealsCost;
    }

    // Cargos gerenciais/diretivos entram como baseline próprio de custo (1 posição por cargo),
    // sem herdar esta configuração para os trabalhadores.
    if ($eventId > 0 && workforceEventRolesReady($db)) {
        $sqlManagerial = "
            SELECT
                wer.id AS event_role_id,
                wer.role_id,
                wr.name AS role_name,
                COALESCE(NULLIF(TRIM(wer.sector), ''), 'geral') AS sector,
                COALESCE(wer.cost_bucket, '') AS cost_bucket,
                wer.leader_user_id,
                wer.leader_participant_id,
                COALESCE(wer.leader_name, '') AS leader_name,
                COALESCE(wer.leader_cpf, '') AS leader_cpf,
                COALESCE(wer.is_placeholder, false) AS is_placeholder,
                COALESCE(wer.role_class, '') AS role_class,
                COALESCE(wer.payment_amount, 0)::numeric AS payment_amount,
                COALESCE(wer.max_shifts_event, 1)::int AS max_shifts_event,
                COALESCE(wer.shift_hours, 8)::numeric AS shift_hours,
                COALESCE(wer.meals_per_day, 0)::int AS meals_per_day
            FROM workforce_event_roles wer
            JOIN workforce_roles wr ON wr.id = wer.role_id
            WHERE wer.organizer_id = :organizer_id
              AND wer.event_id = :event_id
              AND wer.is_active = true
        ";
        $paramsManagerial = [
            ':organizer_id' => $organizerId,
            ':event_id' => $eventId,
        ];
        if ($roleId > 0) {
            $sqlManagerial .= " AND wer.role_id = :role_id";
            $paramsManagerial[':role_id'] = $roleId;
        }
        if ($effectiveSector !== '') {
            $sqlManagerial .= " AND LOWER(COALESCE(wer.sector, '')) = :sector";
            $paramsManagerial[':sector'] = $effectiveSector;
        }
        $sqlManagerial .= " ORDER BY wer.sector ASC, wr.name ASC";

        $stmtManagerial = $db->prepare($sqlManagerial);
        $stmtManagerial->execute($paramsManagerial);
        $managerialRows = $stmtManagerial->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($managerialRows as $mRow) {
            $sector = normalizeSector((string)($mRow['sector'] ?? '')) ?: 'geral';
            $roleName = (string)($mRow['role_name'] ?? 'Cargo Gerencial');
            $costBucket = normalizeCostBucket((string)($mRow['cost_bucket'] ?? ''), $roleName);
            if ($costBucket !== 'managerial') {
                continue;
            }
            $paymentAmount = (float)($mRow['payment_amount'] ?? 0);
            $maxShifts = (int)($mRow['max_shifts_event'] ?? 1);
            $shiftHours = (float)($mRow['shift_hours'] ?? 8);
            $mealsPerDay = (int)($mRow['meals_per_day'] ?? 0);
            $isFilled = workforceHasLeadershipIdentity($mRow);
            $isPlaceholder = !$isFilled;
            $leaderParticipantId = (int)($mRow['leader_participant_id'] ?? 0);
            $leaderIsPresent = $leaderParticipantId > 0 && !empty($presenceByParticipantId[$leaderParticipantId]);

            $estimatedPayment = round($paymentAmount * $maxShifts, 2);
            $estimatedHours = round($maxShifts * $shiftHours, 2);
            $estimatedMeals = $maxShifts * $mealsPerDay;
            $estimatedMealsCost = round(
                $maxShifts * MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost),
                2
            );

            $totalMembers++;
            $plannedMembersTotal++;
            $totalEstimatedPayment += $estimatedPayment;
            $totalEstimatedHours += $estimatedHours;
            $totalEstimatedMeals += $estimatedMeals;
            $totalEstimatedMealsCost += $estimatedMealsCost;
            $managerialRolesPaymentTotal += $estimatedPayment;
            $leadershipPositionsTotal++;
            if ($isFilled) {
                $filledMembersTotal++;
                $leadershipFilledTotal++;
            }
            if ($hasParticipantCheckins && $leaderIsPresent) {
                $presentMembersTotal++;
            }
            if ($isPlaceholder) {
                $leadershipPlaceholderTotal++;
            }

            $ensureSectorRow($bySector, $sector);
            $bySector[$sector]['members']++;
            $bySector[$sector]['planned_members_total']++;
            $bySector[$sector]['leadership_positions_total']++;
            if ($isFilled) {
                $bySector[$sector]['filled_members_total']++;
                $bySector[$sector]['leadership_filled_total']++;
            }
            if ($hasParticipantCheckins && $leaderIsPresent) {
                $bySector[$sector]['present_members_total']++;
            }
            if ($isPlaceholder) {
                $bySector[$sector]['leadership_placeholder_total']++;
            }
            $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
            $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
            $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
            $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

            $byRoleManagerial[] = [
                'sector' => $sector,
                'event_role_id' => (int)($mRow['event_role_id'] ?? 0),
                'role_id' => (int)($mRow['role_id'] ?? 0),
                'role_name' => $roleName,
                'role_class' => (string)($mRow['role_class'] ?? ''),
                'cost_bucket' => $costBucket,
                'members' => 1,
                'planned_members_total' => 1,
                'filled_members_total' => $isFilled ? 1 : 0,
                'leadership_positions_total' => 1,
                'leadership_filled_total' => $isFilled ? 1 : 0,
                'leadership_placeholder_total' => $isPlaceholder ? 1 : 0,
                'operational_members_total' => 0,
                'present_members_total' => $hasParticipantCheckins ? ($leaderIsPresent ? 1 : 0) : null,
                'estimated_payment_total' => $estimatedPayment,
                'estimated_hours_total' => $estimatedHours,
                'estimated_meals_total' => $estimatedMeals,
                'estimated_meals_cost_total' => $estimatedMealsCost,
            ];
        }
    } elseif ($hasRoleSettings) {
        $sqlManagerial = "
            SELECT
                wr.id AS role_id,
                wr.name AS role_name,
                COALESCE(NULLIF(TRIM(wr.sector), ''), 'geral') AS sector,
                COALESCE(wrs.cost_bucket, '') AS cost_bucket,
                COALESCE(wrs.payment_amount, 0)::numeric AS payment_amount,
                COALESCE(wrs.max_shifts_event, 1)::int AS max_shifts_event,
                COALESCE(wrs.shift_hours, 8)::numeric AS shift_hours,
                COALESCE(wrs.meals_per_day, 0)::int AS meals_per_day
            FROM workforce_role_settings wrs
            JOIN workforce_roles wr ON wr.id = wrs.role_id
            WHERE wrs.organizer_id = :organizer_id
        ";
        $paramsManagerial = [':organizer_id' => $organizerId];
        if ($roleId > 0) {
            $sqlManagerial .= " AND wr.id = :role_id";
            $paramsManagerial[':role_id'] = $roleId;
        }
        if ($effectiveSector !== '') {
            $sqlManagerial .= " AND LOWER(COALESCE(wr.sector, '')) = :sector";
            $paramsManagerial[':sector'] = $effectiveSector;
        }
        $sqlManagerial .= " ORDER BY wr.sector ASC, wr.name ASC";

        $stmtManagerial = $db->prepare($sqlManagerial);
        $stmtManagerial->execute($paramsManagerial);
        $managerialRows = $stmtManagerial->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($managerialRows as $mRow) {
            $sector = normalizeSector((string)($mRow['sector'] ?? '')) ?: 'geral';
            $roleName = (string)($mRow['role_name'] ?? 'Cargo Gerencial');
            $costBucket = normalizeCostBucket((string)($mRow['cost_bucket'] ?? ''), $roleName);
            if ($costBucket !== 'managerial') {
                continue;
            }
            $paymentAmount = (float)($mRow['payment_amount'] ?? 0);
            $maxShifts = (int)($mRow['max_shifts_event'] ?? 1);
            $shiftHours = (float)($mRow['shift_hours'] ?? 8);
            $mealsPerDay = (int)($mRow['meals_per_day'] ?? 0);

            $estimatedPayment = round($paymentAmount * $maxShifts, 2);
            $estimatedHours = round($maxShifts * $shiftHours, 2);
            $estimatedMeals = $maxShifts * $mealsPerDay;
            $estimatedMealsCost = round(
                $maxShifts * MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost),
                2
            );

            $totalMembers++;
            $plannedMembersTotal++;
            $filledMembersTotal++;
            $totalEstimatedPayment += $estimatedPayment;
            $totalEstimatedHours += $estimatedHours;
            $totalEstimatedMeals += $estimatedMeals;
            $totalEstimatedMealsCost += $estimatedMealsCost;
            $managerialRolesPaymentTotal += $estimatedPayment;
            $leadershipPositionsTotal++;
            $leadershipFilledTotal++;

            $ensureSectorRow($bySector, $sector);
            $bySector[$sector]['members']++;
            $bySector[$sector]['planned_members_total']++;
            $bySector[$sector]['filled_members_total']++;
            $bySector[$sector]['leadership_positions_total']++;
            $bySector[$sector]['leadership_filled_total']++;
            $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
            $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
            $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
            $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

            $byRoleManagerial[] = [
                'sector' => $sector,
                'role_id' => (int)($mRow['role_id'] ?? 0),
                'role_name' => $roleName,
                'cost_bucket' => $costBucket,
                'members' => 1,
                'planned_members_total' => 1,
                'filled_members_total' => 1,
                'leadership_positions_total' => 1,
                'leadership_filled_total' => 1,
                'leadership_placeholder_total' => 0,
                'operational_members_total' => 0,
                'present_members_total' => null,
                'estimated_payment_total' => $estimatedPayment,
                'estimated_hours_total' => $estimatedHours,
                'estimated_meals_total' => $estimatedMeals,
                'estimated_meals_cost_total' => $estimatedMealsCost,
            ];
        }
    }

    $bySector = array_values(array_map(function ($row) {
        $row['members'] = (int)($row['planned_members_total'] ?? $row['members'] ?? 0);
        $row['estimated_payment_total'] = round((float)$row['estimated_payment_total'], 2);
        $row['estimated_hours_total'] = round((float)$row['estimated_hours_total'], 2);
        $row['estimated_meals_cost_total'] = round((float)($row['estimated_meals_cost_total'] ?? 0), 2);
        return $row;
    }, $bySector));

    $byRole = array_values(array_map(function ($row) {
        $row['members'] = (int)($row['planned_members_total'] ?? $row['members'] ?? 0);
        $row['estimated_payment_total'] = round((float)$row['estimated_payment_total'], 2);
        $row['estimated_hours_total'] = round((float)$row['estimated_hours_total'], 2);
        $row['estimated_meals_cost_total'] = round((float)($row['estimated_meals_cost_total'] ?? 0), 2);
        return $row;
    }, $byRole));
    $byRoleManagerial = array_values(array_map(function ($row) {
        $row['members'] = (int)($row['planned_members_total'] ?? $row['members'] ?? 0);
        $row['estimated_payment_total'] = round((float)$row['estimated_payment_total'], 2);
        $row['estimated_hours_total'] = round((float)$row['estimated_hours_total'], 2);
        $row['estimated_meals_cost_total'] = round((float)($row['estimated_meals_cost_total'] ?? 0), 2);
        return $row;
    }, $byRoleManagerial));

    usort($bySector, fn($a, $b) => strcmp($a['sector'], $b['sector']));
    usort($byRole, function ($a, $b) {
        $c = strcmp($a['sector'], $b['sector']);
        return $c !== 0 ? $c : strcmp($a['role_name'], $b['role_name']);
    });
    usort($byRoleManagerial, function ($a, $b) {
        $c = strcmp($a['sector'], $b['sector']);
        return $c !== 0 ? $c : strcmp($a['role_name'], $b['role_name']);
    });

    $estimatedMealsCostTotal = round($totalEstimatedMealsCost, 2);
    $estimatedTotalCost = round($totalEstimatedPayment + $estimatedMealsCostTotal, 2);

    jsonSuccess([
        'filters' => [
            'event_id' => $eventId > 0 ? $eventId : null,
            'role_id' => $roleId > 0 ? $roleId : null,
            'sector' => $effectiveSector !== '' ? $effectiveSector : null,
        ],
        'formulas' => [
            'estimated_payment_total' => 'payment_amount * max_shifts_event',
            'estimated_hours_total' => 'max_shifts_event * shift_hours',
            'estimated_meals_total' => 'max_shifts_event * meals_per_day',
            'estimated_meals_cost_total' => 'max_shifts_event * custo_diario_das_refeicoes_ativas_do_evento',
            'estimated_total_cost' => 'estimated_payment_total + estimated_meals_cost_total'
        ],
        'summary' => [
            'members' => $plannedMembersTotal,
            'planned_members_total' => $plannedMembersTotal,
            'filled_members_total' => $filledMembersTotal,
            'present_members_total' => $presentMembersTotal,
            'leadership_positions_total' => $leadershipPositionsTotal,
            'leadership_filled_total' => $leadershipFilledTotal,
            'leadership_placeholder_total' => $leadershipPlaceholderTotal,
            'operational_members_total' => $operationalMembersTotal,
            'estimated_payment_total' => round($totalEstimatedPayment, 2),
            'estimated_hours_total' => round($totalEstimatedHours, 2),
            'estimated_meals_total' => $totalEstimatedMeals,
            'meal_unit_cost' => round($mealUnitCost, 2),
            'meal_services_costs_label' => $mealServicesCostLabel,
            'event_meal_services' => $mealCostContext['services'] ?? [],
            'estimated_meals_cost_total' => $estimatedMealsCostTotal,
            'estimated_total_cost' => $estimatedTotalCost,
            'managerial_roles_payment_total' => round($managerialRolesPaymentTotal, 2),
            'operational_members_payment_total' => round($operationalMembersPaymentTotal, 2),
        ],
        'by_sector' => $bySector,
        'by_role' => $byRole,
        'by_role_managerial' => $byRoleManagerial,
        'operational_members' => $operationalMembers,
        'items' => $items
    ], 'Conector financeiro de equipe carregado com sucesso.');
}

function getFinanceContext(): array
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    return [$db, $organizerId, $user];
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function canBypassSectorAcl(array $user): bool
{
    $role = strtolower((string)($user['role'] ?? ''));
    return $role === 'admin' || $role === 'organizer';
}

function resolveUserSector(PDO $db, array $user): string
{
    $sectorFromToken = normalizeSector((string)($user['sector'] ?? ''));
    if ($sectorFromToken !== '') {
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

function normalizeCostBucket(string $value, string $roleName = ''): string
{
    $inferred = inferCostBucketFromRoleName($roleName);
    if ($inferred === 'managerial') {
        return 'managerial';
    }
    $normalized = strtolower(trim($value));
    if ($normalized === 'managerial' || $normalized === 'operational') {
        return $normalized;
    }
    return $inferred;
}

function inferCostBucketFromRoleName(string $roleName): string
{
    $name = strtolower(trim($roleName));
    if (workforceIsOperationalCollectionRoleName($name)) {
        return 'operational';
    }
    $managerialHints = ['gerente', 'diretor', 'coordenador', 'supervisor', 'lider', 'chefe', 'gestor', 'manager'];
    foreach ($managerialHints as $hint) {
        if ($name !== '' && str_contains($name, $hint)) {
            return 'managerial';
        }
    }
    return 'operational';
}

function financeAudit(string $action, string $entityType, $entityId, $before, $after, array $user): void
{
    if (!class_exists('AuditService')) {
        return;
    }
    AuditService::log(
        $action,
        $entityType,
        $entityId,
        $before,
        $after,
        $user,
        'success',
        ['metadata' => ['module' => 'organizer-finance']]
    );
}
