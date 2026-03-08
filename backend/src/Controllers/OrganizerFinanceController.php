<?php
/**
 * Organizer Finance Controller
 * Consolida gateways e configurações financeiras por organizer.
 */

require_once BASE_PATH . '/src/Services/PaymentGatewayService.php';
require_once BASE_PATH . '/src/Services/FinancialSettingsService.php';

use EnjoyFun\Services\PaymentGatewayService;
use EnjoyFun\Services\FinancialSettingsService;

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
    [$db, $organizerId] = getFinanceContext();
    try {
        $gateway = PaymentGatewayService::createGateway($db, $organizerId, $body);
        jsonSuccess($gateway, 'Gateway criado com sucesso.', 201);
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }
}

function updatePaymentGateway(int $gatewayId, array $body): void
{
    [$db, $organizerId] = getFinanceContext();
    try {
        $gateway = PaymentGatewayService::updateGateway($db, $organizerId, $gatewayId, $body);
        jsonSuccess($gateway, 'Gateway atualizado com sucesso.');
    } catch (\InvalidArgumentException $e) {
        $code = str_contains(strtolower($e->getMessage()), 'não encontrado') ? 404 : 422;
        jsonError($e->getMessage(), $code);
    }
}

function deletePaymentGateway(int $gatewayId): void
{
    [$db, $organizerId] = getFinanceContext();
    try {
        PaymentGatewayService::deleteGateway($db, $organizerId, $gatewayId);
        jsonSuccess([], 'Gateway excluído com sucesso.');
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 404);
    }
}

function setPrimaryGateway(int $gatewayId): void
{
    [$db, $organizerId] = getFinanceContext();
    try {
        $gateway = PaymentGatewayService::setPrimaryGateway($db, $organizerId, $gatewayId);
        jsonSuccess($gateway, 'Gateway principal definido com sucesso.');
    } catch (\InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }
}

function setGatewayStatus(int $gatewayId, array $body): void
{
    [$db, $organizerId] = getFinanceContext();
    $isActive = filter_var($body['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    try {
        $gateway = PaymentGatewayService::setGatewayActive($db, $organizerId, $gatewayId, $isActive);
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
    [$db, $organizerId] = getFinanceContext();
    $settings = FinancialSettingsService::saveSettings($db, $organizerId, $body);
    jsonSuccess($settings, 'Configurações financeiras atualizadas.');
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
    ]);
}

function updateFinanceConfig(array $body): void
{
    [$db, $organizerId] = getFinanceContext();
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

        jsonSuccess([
            'gateway' => $updatedGateway,
            'currency' => $settings['currency'] ?? 'BRL',
            'tax_rate' => $settings['tax_rate'] ?? 0.0,
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

    $sql = "
        SELECT
            wa.id AS assignment_id,
            ep.event_id,
            wa.sector,
            wa.role_id,
            r.name AS role_name,
            ep.id AS participant_id,
            p.name AS participant_name,
            COALESCE(wms.payment_amount, 0) AS payment_amount,
            COALESCE(NULLIF(wms.max_shifts_event, 0), 1) AS max_shifts_event,
            COALESCE(wms.shift_hours, 8) AS shift_hours,
            COALESCE(wms.meals_per_day, 4) AS meals_per_day
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN events e ON e.id = ep.event_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
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
    $totalEstimatedPayment = 0.0;
    $totalEstimatedHours = 0.0;
    $totalEstimatedMeals = 0;
    $bySector = [];
    $byRole = [];
    $items = [];

    foreach ($rows as $row) {
        $sector = normalizeSector((string)($row['sector'] ?? '')) ?: 'geral';
        $roleName = (string)($row['role_name'] ?? 'Sem Cargo');
        $paymentAmount = (float)$row['payment_amount'];
        $maxShifts = (int)$row['max_shifts_event'];
        $shiftHours = (float)$row['shift_hours'];
        $mealsPerDay = (int)$row['meals_per_day'];

        $estimatedPayment = round($paymentAmount * $maxShifts, 2);
        $estimatedHours = round($maxShifts * $shiftHours, 2);
        $estimatedMeals = $maxShifts * $mealsPerDay;

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
            'estimated_payment_total' => $estimatedPayment,
            'estimated_hours_total' => $estimatedHours,
            'estimated_meals_total' => $estimatedMeals,
        ];

        $totalMembers++;
        $totalEstimatedPayment += $estimatedPayment;
        $totalEstimatedHours += $estimatedHours;
        $totalEstimatedMeals += $estimatedMeals;

        if (!isset($bySector[$sector])) {
            $bySector[$sector] = [
                'sector' => $sector,
                'members' => 0,
                'estimated_payment_total' => 0.0,
                'estimated_hours_total' => 0.0,
                'estimated_meals_total' => 0,
            ];
        }
        $bySector[$sector]['members']++;
        $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
        $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
        $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;

        $roleKey = $sector . '::' . $roleName;
        if (!isset($byRole[$roleKey])) {
            $byRole[$roleKey] = [
                'sector' => $sector,
                'role_name' => $roleName,
                'members' => 0,
                'estimated_payment_total' => 0.0,
                'estimated_hours_total' => 0.0,
                'estimated_meals_total' => 0,
            ];
        }
        $byRole[$roleKey]['members']++;
        $byRole[$roleKey]['estimated_payment_total'] += $estimatedPayment;
        $byRole[$roleKey]['estimated_hours_total'] += $estimatedHours;
        $byRole[$roleKey]['estimated_meals_total'] += $estimatedMeals;
    }

    $bySector = array_values(array_map(function ($row) {
        $row['estimated_payment_total'] = round((float)$row['estimated_payment_total'], 2);
        $row['estimated_hours_total'] = round((float)$row['estimated_hours_total'], 2);
        return $row;
    }, $bySector));

    $byRole = array_values(array_map(function ($row) {
        $row['estimated_payment_total'] = round((float)$row['estimated_payment_total'], 2);
        $row['estimated_hours_total'] = round((float)$row['estimated_hours_total'], 2);
        return $row;
    }, $byRole));

    usort($bySector, fn($a, $b) => strcmp($a['sector'], $b['sector']));
    usort($byRole, function ($a, $b) {
        $c = strcmp($a['sector'], $b['sector']);
        return $c !== 0 ? $c : strcmp($a['role_name'], $b['role_name']);
    });

    jsonSuccess([
        'filters' => [
            'event_id' => $eventId > 0 ? $eventId : null,
            'role_id' => $roleId > 0 ? $roleId : null,
            'sector' => $effectiveSector !== '' ? $effectiveSector : null,
        ],
        'formulas' => [
            'estimated_payment_total' => 'payment_amount * max_shifts_event',
            'estimated_hours_total' => 'max_shifts_event * shift_hours',
            'estimated_meals_total' => 'max_shifts_event * meals_per_day'
        ],
        'summary' => [
            'members' => $totalMembers,
            'estimated_payment_total' => round($totalEstimatedPayment, 2),
            'estimated_hours_total' => round($totalEstimatedHours, 2),
            'estimated_meals_total' => $totalEstimatedMeals,
        ],
        'by_sector' => $bySector,
        'by_role' => $byRole,
        'items' => $items
    ], 'Conector financeiro de equipe carregado com sucesso.');
}

function getFinanceContext(): array
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    return [$db, $organizerId];
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

