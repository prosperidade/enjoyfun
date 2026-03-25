<?php
/**
 * EventFinanceBudgetController
 * Gerencia orçamentos (cabeçalho) e linhas de orçamento.
 *
 * Subrecursos atendidos por este controller (via Dispatcher):
 *   budgets      → cabeçalho do orçamento
 *   budget-lines → linhas individuais
 *
 * Endpoints:
 *   GET    /api/event-finance/budgets?event_id=
 *   POST   /api/event-finance/budgets
 *   GET    /api/event-finance/budgets/{id}
 *   PUT    /api/event-finance/budgets/{id}
 *   PATCH  /api/event-finance/budgets/{id}
 *
 *   GET    /api/event-finance/budget-lines?event_id=
 *   POST   /api/event-finance/budget-lines
 *   GET    /api/event-finance/budget-lines/{id}
 *   PUT    /api/event-finance/budget-lines/{id}
 *   PATCH  /api/event-finance/budget-lines/{id}
 *   DELETE /api/event-finance/budget-lines/{id}
 */

require_once __DIR__ . '/../Helpers/EventFinanceBudgetHelper.php';

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    if ($subresource === 'budgets') {
        match (true) {
            $method === 'GET'  && $id === null => listBudgets($query),
            $method === 'POST' && $id === null => createBudget($body),
            $method === 'GET'  && $id !== null => getBudget((int)$id),
            ($method === 'PUT' || $method === 'PATCH') && $id !== null => updateBudget((int)$id, $body),
            default => jsonError('Endpoint de budgets não encontrado.', 404),
        };
        return;
    }

    // budget-lines
    match (true) {
        $method === 'GET'    && $id === null => listBudgetLines($query),
        $method === 'POST'   && $id === null => createBudgetLine($body),
        $method === 'GET'    && $id !== null => getBudgetLine((int)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null => updateBudgetLine((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteBudgetLine((int)$id),
        default => jsonError('Endpoint de budget-lines não encontrado.', 404),
    };
}

// ── Budgets ───────────────────────────────────────────────────────────────────

function listBudgets(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    $stmt = $db->prepare("
        SELECT id, event_id, name, total_budget, notes, is_active, created_at, updated_at
        FROM event_budgets
        WHERE organizer_id = :organizer_id AND event_id = :event_id
        ORDER BY name
    ");
    $stmt->execute([':organizer_id' => $orgId, ':event_id' => $eventId]);
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enriquecer cada orçamento com o summary
    foreach ($budgets as &$b) {
        $summary = getBudgetSummary($db, (int)$b['id'], $orgId);
        $b['summary'] = $summary ?: null;
    }

    jsonSuccess($budgets, 'Orçamentos carregados.');
}

function getBudget(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT id, event_id, name, total_budget, notes, is_active, created_at, updated_at
        FROM event_budgets
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Orçamento não encontrado.', 404);
    }

    $row['summary'] = getBudgetSummary($db, $id, $orgId);
    $row['lines']   = getBudgetLinesWithActuals($db, $id, $orgId);

    jsonSuccess($row, 'Orçamento carregado.');
}

function createBudget(array $body): void
{
    $user    = requireAuth(['admin', 'organizer']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    $name        = trim((string)($body['name'] ?? 'Orçamento principal'));
    $totalBudget = max(0, (float)($body['total_budget'] ?? 0));
    $notes       = trim((string)($body['notes'] ?? '')) ?: null;

    try {
        $stmt = $db->prepare("
            INSERT INTO event_budgets (organizer_id, event_id, name, total_budget, notes)
            VALUES (:organizer_id, :event_id, :name, :total_budget, :notes)
            RETURNING id, event_id, name, total_budget, notes, is_active, created_at, updated_at
        ");
        $stmt->execute([
            ':organizer_id' => $orgId,
            ':event_id'     => $eventId,
            ':name'         => $name,
            ':total_budget' => $totalBudget,
            ':notes'        => $notes,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_event_budget_event')) {
            jsonError('Este evento já possui um orçamento cadastrado.', 409);
        }
        throw $e;
    }

    jsonSuccess($row, 'Orçamento criado com sucesso.', 201);
}

function updateBudget(int $id, array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $check = $db->prepare("SELECT id FROM event_budgets WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Orçamento não encontrado.', 404);
    }

    $fields = [];
    $params = [':id' => $id, ':organizer_id' => $orgId];

    if (array_key_exists('name', $body)) {
        $fields[] = 'name = :name';
        $params[':name'] = trim((string)$body['name']) ?: 'Orçamento principal';
    }
    if (array_key_exists('total_budget', $body)) {
        $val = (float)$body['total_budget'];
        if ($val < 0) {
            jsonError('total_budget não pode ser negativo.', 422);
        }
        $fields[] = 'total_budget = :total_budget';
        $params[':total_budget'] = $val;
    }
    if (array_key_exists('notes', $body)) {
        $fields[] = 'notes = :notes';
        $params[':notes'] = trim((string)$body['notes']) ?: null;
    }
    if (array_key_exists('is_active', $body)) {
        $fields[] = 'is_active = :is_active';
        $params[':is_active'] = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    if (empty($fields)) {
        jsonError('Nenhum campo válido para atualizar.', 422);
    }

    $stmt = $db->prepare("
        UPDATE event_budgets
        SET " . implode(', ', $fields) . ", updated_at = NOW()
        WHERE id = :id AND organizer_id = :organizer_id
        RETURNING id, event_id, name, total_budget, notes, is_active, created_at, updated_at
    ");
    $stmt->execute($params);

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Orçamento atualizado com sucesso.');
}

// ── Budget Lines ──────────────────────────────────────────────────────────────

function listBudgetLines(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    $stmt = $db->prepare("
        SELECT bl.id, bl.budget_id, bl.category_id, c.name AS category_name,
               bl.cost_center_id, cc.name AS cost_center_name,
               bl.description, bl.budgeted_amount, bl.notes, bl.created_at, bl.updated_at
        FROM event_budget_lines bl
        JOIN event_cost_categories c  ON c.id  = bl.category_id
        JOIN event_cost_centers    cc ON cc.id = bl.cost_center_id
        WHERE bl.organizer_id = :organizer_id AND bl.event_id = :event_id
        ORDER BY c.name, cc.name, bl.description
    ");
    $stmt->execute([':organizer_id' => $orgId, ':event_id' => $eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Linhas de orçamento carregadas.');
}

function getBudgetLine(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT bl.id, bl.budget_id, bl.category_id, c.name AS category_name,
               bl.cost_center_id, cc.name AS cost_center_name,
               bl.description, bl.budgeted_amount, bl.notes, bl.created_at, bl.updated_at
        FROM event_budget_lines bl
        JOIN event_cost_categories c  ON c.id  = bl.category_id
        JOIN event_cost_centers    cc ON cc.id = bl.cost_center_id
        WHERE bl.id = :id AND bl.organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Linha de orçamento não encontrada.', 404);
    }
    jsonSuccess($row, 'Linha de orçamento carregada.');
}

function createBudgetLine(array $body): void
{
    $user    = requireAuth(['admin', 'organizer']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    $budgetId     = (int)($body['budget_id'] ?? 0);
    $categoryId   = (int)($body['category_id'] ?? 0);
    $costCenterId = (int)($body['cost_center_id'] ?? 0);
    $amount       = (float)($body['budgeted_amount'] ?? 0);

    if ($budgetId <= 0)     { jsonError('budget_id é obrigatório.', 422); }
    if ($categoryId <= 0)   { jsonError('category_id é obrigatório.', 422); }
    if ($costCenterId <= 0) { jsonError('cost_center_id é obrigatório.', 422); }
    if ($amount < 0)        { jsonError('budgeted_amount não pode ser negativo.', 422); }

    $description = trim((string)($body['description'] ?? '')) ?: null;
    $notes       = trim((string)($body['notes'] ?? '')) ?: null;

    try {
        $stmt = $db->prepare("
            INSERT INTO event_budget_lines
                (organizer_id, event_id, budget_id, category_id, cost_center_id, description, budgeted_amount, notes)
            VALUES
                (:organizer_id, :event_id, :budget_id, :category_id, :cost_center_id, :description, :budgeted_amount, :notes)
            RETURNING id, budget_id, category_id, cost_center_id, description, budgeted_amount, notes, created_at, updated_at
        ");
        $stmt->execute([
            ':organizer_id'    => $orgId,
            ':event_id'        => $eventId,
            ':budget_id'       => $budgetId,
            ':category_id'     => $categoryId,
            ':cost_center_id'  => $costCenterId,
            ':description'     => $description,
            ':budgeted_amount' => $amount,
            ':notes'           => $notes,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_budget_line_unique')) {
            jsonError('Já existe uma linha idêntica neste orçamento (mesma categoria, centro e descrição).', 409);
        }
        throw $e;
    }

    jsonSuccess($row, 'Linha de orçamento criada com sucesso.', 201);
}

function updateBudgetLine(int $id, array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $check = $db->prepare("SELECT id FROM event_budget_lines WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Linha de orçamento não encontrada.', 404);
    }

    $fields = [];
    $params = [':id' => $id, ':organizer_id' => $orgId];

    if (array_key_exists('budgeted_amount', $body)) {
        $val = (float)$body['budgeted_amount'];
        if ($val < 0) {
            jsonError('budgeted_amount não pode ser negativo.', 422);
        }
        $fields[] = 'budgeted_amount = :budgeted_amount';
        $params[':budgeted_amount'] = $val;
    }
    if (array_key_exists('description', $body)) {
        $fields[] = 'description = :description';
        $params[':description'] = trim((string)$body['description']) ?: null;
    }
    if (array_key_exists('notes', $body)) {
        $fields[] = 'notes = :notes';
        $params[':notes'] = trim((string)$body['notes']) ?: null;
    }

    if (empty($fields)) {
        jsonError('Nenhum campo válido para atualizar.', 422);
    }

    $stmt = $db->prepare("
        UPDATE event_budget_lines
        SET " . implode(', ', $fields) . ", updated_at = NOW()
        WHERE id = :id AND organizer_id = :organizer_id
        RETURNING id, budget_id, category_id, cost_center_id, description, budgeted_amount, notes, created_at, updated_at
    ");
    $stmt->execute($params);

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Linha de orçamento atualizada com sucesso.');
}

function deleteBudgetLine(int $id): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    // Verificar se há contas a pagar associadas que impediriam exclusão
    $check = $db->prepare("SELECT id FROM event_budget_lines WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Linha de orçamento não encontrada.', 404);
    }

    $stmt = $db->prepare("DELETE FROM event_budget_lines WHERE id = :id AND organizer_id = :organizer_id");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);

    jsonSuccess(null, 'Linha de orçamento removida com sucesso.');
}
