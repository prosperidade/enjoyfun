<?php
/**
 * EventFinanceDispatcher — Portão de entrada do módulo /api/event-finance
 *
 * Responsabilidades:
 *   1. Autenticar via JWT
 *   2. Extrair organizer_id (NUNCA aceitar do cliente)
 *   3. Roteamento por $id (subrecurso) para o controller correto
 *
 * Rota raiz: /api/event-finance
 * Subrecursos: categories | cost-centers | budgets | budget-lines |
 *              suppliers  | contracts    | payables | payments |
 *              attachments | summary | imports | exports
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // ── Mapa de subrecurso → arquivo do controller ────────────────────────────
    $map = [
        'categories'   => __DIR__ . '/EventFinanceCategoryController.php',
        'cost-centers' => __DIR__ . '/EventFinanceCostCenterController.php',
        'budgets'      => __DIR__ . '/EventFinanceBudgetController.php',
        'budget-lines' => __DIR__ . '/EventFinanceBudgetController.php',   // mesmo controller
        'suppliers'    => __DIR__ . '/EventFinanceSupplierController.php',
        'contracts'    => __DIR__ . '/EventFinanceSupplierController.php',  // mesmo controller
        'payables'     => __DIR__ . '/EventFinancePayableController.php',
        'payments'     => __DIR__ . '/EventFinancePaymentController.php',
        'attachments'  => __DIR__ . '/EventFinanceAttachmentController.php',
        'summary'      => __DIR__ . '/EventFinanceSummaryController.php',
        'imports'      => __DIR__ . '/EventFinanceImportController.php',
        'exports'      => __DIR__ . '/EventFinanceExportController.php',
    ];

    $subresource = $id ?? '';

    if ($subresource === '' || !isset($map[$subresource])) {
        $valid = implode(', ', array_keys($map));
        jsonError("Subrecurso '{$subresource}' não encontrado em /api/event-finance. Disponíveis: {$valid}", 404);
    }

    $file = $map[$subresource];
    if (!file_exists($file)) {
        jsonError("Controller do subrecurso '{$subresource}' não implementado.", 501);
    }

    // Passa o contexto adiante — $id já foi consumido como subrecurso
    // Os controllers recebem: method, $sub como "id", $subId como "sub"
    require_once $file;
    dispatchEventFinance($method, $subresource, $sub, $subId, $body, $query);
}

/**
 * Helper comum a todos os controllers de Event Finance.
 */
if (!function_exists('resolveOrganizerId')) {
    function resolveOrganizerId(array $user): int
    {
        if (!empty($user['organizer_id'])) {
            return (int)$user['organizer_id'];
        }
        jsonError('Acesso negado: organizer_id não encontrado no token.', 403);
    }
}
