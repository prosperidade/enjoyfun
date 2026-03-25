<?php
/**
 * EventFinanceCostCenterController
 * CRUD de centros de custo do evento.
 * Escopo: evento (event_id obrigatório).
 *
 * Endpoints:
 *   GET    /api/event-finance/cost-centers?event_id=
 *   POST   /api/event-finance/cost-centers
 *   GET    /api/event-finance/cost-centers/{id}
 *   PUT    /api/event-finance/cost-centers/{id}
 *   PATCH  /api/event-finance/cost-centers/{id}
 */

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null => listCostCenters($query),
        $method === 'POST' && $id === null => createCostCenter($body),
        $method === 'GET'  && $id !== null => getCostCenter((int)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null => updateCostCenter((int)$id, $body),
        default => jsonError('Endpoint de cost-centers não encontrado.', 404),
    };
}

function listCostCenters(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para listar centros de custo.', 422);
    }

    $onlyActive = filter_var($query['active'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

    $sql = "SELECT id, event_id, name, code, budget_limit, description, is_active, created_at, updated_at
            FROM event_cost_centers
            WHERE organizer_id = :organizer_id AND event_id = :event_id";
    if ($onlyActive) {
        $sql .= " AND is_active = TRUE";
    }
    $sql .= " ORDER BY name";

    $stmt = $db->prepare($sql);
    $stmt->execute([':organizer_id' => $orgId, ':event_id' => $eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Centros de custo carregados.');
}

function getCostCenter(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT id, event_id, name, code, budget_limit, description, is_active, created_at, updated_at
        FROM event_cost_centers
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Centro de custo não encontrado.', 404);
    }
    jsonSuccess($row, 'Centro de custo carregado.');
}

function createCostCenter(array $body): void
{
    $user    = requireAuth(['admin', 'organizer']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        jsonError('O campo name é obrigatório.', 422);
    }

    $code        = trim((string)($body['code'] ?? '')) ?: null;
    $description = trim((string)($body['description'] ?? '')) ?: null;
    $budgetLimit = isset($body['budget_limit']) ? (float)$body['budget_limit'] : null;
    if ($budgetLimit !== null && $budgetLimit < 0) {
        jsonError('budget_limit não pode ser negativo.', 422);
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO event_cost_centers (organizer_id, event_id, name, code, budget_limit, description)
            VALUES (:organizer_id, :event_id, :name, :code, :budget_limit, :description)
            RETURNING id, event_id, name, code, budget_limit, description, is_active, created_at, updated_at
        ");
        $stmt->execute([
            ':organizer_id' => $orgId,
            ':event_id'     => $eventId,
            ':name'         => $name,
            ':code'         => $code,
            ':budget_limit' => $budgetLimit,
            ':description'  => $description,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_cost_center_event_name')) {
            jsonError("Já existe um centro de custo com o nome '{$name}' neste evento.", 409);
        }
        throw $e;
    }

    jsonSuccess($row, 'Centro de custo criado com sucesso.', 201);
}

function updateCostCenter(int $id, array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $check = $db->prepare("SELECT id FROM event_cost_centers WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Centro de custo não encontrado.', 404);
    }

    $fields = [];
    $params = [':id' => $id, ':organizer_id' => $orgId];

    if (array_key_exists('name', $body)) {
        $name = trim((string)$body['name']);
        if ($name === '') {
            jsonError('name não pode ser vazio.', 422);
        }
        $fields[] = 'name = :name';
        $params[':name'] = $name;
    }
    if (array_key_exists('code', $body)) {
        $fields[] = 'code = :code';
        $params[':code'] = trim((string)$body['code']) ?: null;
    }
    if (array_key_exists('description', $body)) {
        $fields[] = 'description = :description';
        $params[':description'] = trim((string)$body['description']) ?: null;
    }
    if (array_key_exists('budget_limit', $body)) {
        $limit = $body['budget_limit'] !== null ? (float)$body['budget_limit'] : null;
        if ($limit !== null && $limit < 0) {
            jsonError('budget_limit não pode ser negativo.', 422);
        }
        $fields[] = 'budget_limit = :budget_limit';
        $params[':budget_limit'] = $limit;
    }
    if (array_key_exists('is_active', $body)) {
        $fields[] = 'is_active = :is_active';
        $params[':is_active'] = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    if (empty($fields)) {
        jsonError('Nenhum campo válido para atualizar.', 422);
    }

    try {
        $stmt = $db->prepare("
            UPDATE event_cost_centers
            SET " . implode(', ', $fields) . ", updated_at = NOW()
            WHERE id = :id AND organizer_id = :organizer_id
            RETURNING id, event_id, name, code, budget_limit, description, is_active, created_at, updated_at
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_cost_center_event_name')) {
            jsonError('Já existe um centro de custo com este nome neste evento.', 409);
        }
        throw $e;
    }

    jsonSuccess($row, 'Centro de custo atualizado com sucesso.');
}
