<?php
/**
 * EventFinanceCategoryController
 * CRUD de categorias de custo do evento.
 * Escopo: organizer (não requer event_id, é global do organizer).
 *
 * Endpoints:
 *   GET    /api/event-finance/categories
 *   POST   /api/event-finance/categories
 *   GET    /api/event-finance/categories/{id}
 *   PUT    /api/event-finance/categories/{id}
 *   PATCH  /api/event-finance/categories/{id}
 */

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null => listCategories($query),
        $method === 'POST' && $id === null => createCategory($body),
        $method === 'GET'  && $id !== null => getCategory((int)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null => updateCategory((int)$id, $body),
        default => jsonError('Endpoint de categories não encontrado.', 404),
    };
}

function listCategories(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db   = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $onlyActive = filter_var($query['active'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

    $sql = "SELECT id, name, code, description, is_active, created_at, updated_at
            FROM event_cost_categories
            WHERE organizer_id = :organizer_id";
    if ($onlyActive) {
        $sql .= " AND is_active = TRUE";
    }
    $sql .= " ORDER BY name";

    $stmt = $db->prepare($sql);
    $stmt->execute([':organizer_id' => $orgId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess($rows, 'Categorias carregadas.');
}

function getCategory(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT id, name, code, description, is_active, created_at, updated_at
        FROM event_cost_categories
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Categoria não encontrada.', 404);
    }

    jsonSuccess($row, 'Categoria carregada.');
}

function createCategory(array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        jsonError('O campo name é obrigatório.', 422);
    }

    $code        = trim((string)($body['code'] ?? '')) ?: null;
    $description = trim((string)($body['description'] ?? '')) ?: null;

    try {
        $stmt = $db->prepare("
            INSERT INTO event_cost_categories (organizer_id, name, code, description)
            VALUES (:organizer_id, :name, :code, :description)
            RETURNING id, name, code, description, is_active, created_at, updated_at
        ");
        $stmt->execute([
            ':organizer_id' => $orgId,
            ':name'         => $name,
            ':code'         => $code,
            ':description'  => $description,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_cost_category_org_name')) {
            jsonError("Já existe uma categoria com o nome '{$name}'.", 409);
        }
        throw $e;
    }

    jsonSuccess($row, 'Categoria criada com sucesso.', 201);
}

function updateCategory(int $id, array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    // Verifica existência
    $check = $db->prepare("SELECT id FROM event_cost_categories WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Categoria não encontrada.', 404);
    }

    $fields = [];
    $params = [':id' => $id, ':organizer_id' => $orgId];

    if (array_key_exists('name', $body)) {
        $name = trim((string)$body['name']);
        if ($name === '') {
            jsonError('O campo name não pode ser vazio.', 422);
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
    if (array_key_exists('is_active', $body)) {
        $fields[] = 'is_active = :is_active';
        $params[':is_active'] = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    if (empty($fields)) {
        jsonError('Nenhum campo válido para atualizar.', 422);
    }

    $setClause = implode(', ', $fields);

    try {
        $stmt = $db->prepare("
            UPDATE event_cost_categories
            SET {$setClause}, updated_at = NOW()
            WHERE id = :id AND organizer_id = :organizer_id
            RETURNING id, name, code, description, is_active, created_at, updated_at
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_cost_category_org_name')) {
            jsonError('Já existe uma categoria com este nome.', 409);
        }
        throw $e;
    }

    jsonSuccess($row, 'Categoria atualizada com sucesso.');
}
