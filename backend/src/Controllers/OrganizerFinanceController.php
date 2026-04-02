<?php
/**
 * Organizer Finance Controller
 * Consolida gateways e configurações financeiras por organizer.
 */

require_once BASE_PATH . '/src/Services/PaymentGatewayService.php';
require_once BASE_PATH . '/src/Services/FinancialSettingsService.php';
require_once BASE_PATH . '/src/Services/FinanceWorkforceCostService.php';
require_once __DIR__ . '/../Helpers/WorkforceControllerSupport.php';

use EnjoyFun\Services\PaymentGatewayService;
use EnjoyFun\Services\FinancialSettingsService;
use EnjoyFun\Services\FinanceWorkforceCostService;

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

    try {
        $report = FinanceWorkforceCostService::buildReport(
            $db,
            $organizerId,
            (int)($query['event_id'] ?? 0),
            (int)($query['role_id'] ?? 0),
            (string)($query['sector'] ?? ''),
            $canBypassSector,
            $userSector
        );
        jsonSuccess($report, 'Conector financeiro de equipe carregado com sucesso.');
    } catch (\Throwable $e) {
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

function getFinanceContext(): array
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    return [$db, $organizerId, $user];
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
