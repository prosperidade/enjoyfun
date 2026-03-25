<?php
/**
 * EventFinanceSupplierController
 * CRUD de fornecedores e contratos por evento.
 *
 * Subrecursos:
 *   suppliers → cadastro de fornecedores (escopo organizer)
 *   contracts → contratos vinculados a fornecedor por evento
 *
 * Endpoints suppliers:
 *   GET    /api/event-finance/suppliers
 *   POST   /api/event-finance/suppliers
 *   GET    /api/event-finance/suppliers/{id}
 *   PUT    /api/event-finance/suppliers/{id}
 *   PATCH  /api/event-finance/suppliers/{id}
 *
 * Endpoints contracts:
 *   GET    /api/event-finance/contracts?event_id=
 *   POST   /api/event-finance/contracts
 *   GET    /api/event-finance/contracts/{id}
 *   PUT    /api/event-finance/contracts/{id}
 *   PATCH  /api/event-finance/contracts/{id}
 */

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    if ($subresource === 'suppliers') {
        match (true) {
            $method === 'GET'  && $id === null => listSuppliers($query),
            $method === 'POST' && $id === null => createSupplier($body),
            $method === 'GET'  && $id !== null => getSupplier((int)$id),
            ($method === 'PUT' || $method === 'PATCH') && $id !== null => updateSupplier((int)$id, $body),
            default => jsonError('Endpoint de suppliers não encontrado.', 404),
        };
        return;
    }

    // contracts
    match (true) {
        $method === 'GET'  && $id === null => listContracts($query),
        $method === 'POST' && $id === null => createContract($body),
        $method === 'GET'  && $id !== null => getContract((int)$id),
        ($method === 'PUT' || $method === 'PATCH') && $id !== null => updateContract((int)$id, $body),
        default => jsonError('Endpoint de contracts não encontrado.', 404),
    };
}

// ── Suppliers ─────────────────────────────────────────────────────────────────

function listSuppliers(array $query): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $onlyActive = filter_var($query['active'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    $sql = "SELECT id, supplier_type, legal_name, trade_name, document_number,
                   pix_key, bank_name, bank_agency, bank_account,
                   contact_name, contact_email, contact_phone, notes, is_active,
                   created_at, updated_at
            FROM suppliers
            WHERE organizer_id = :organizer_id";
    if ($onlyActive) {
        $sql .= " AND is_active = TRUE";
    }
    $sql .= " ORDER BY legal_name";

    $stmt = $db->prepare($sql);
    $stmt->execute([':organizer_id' => $orgId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Fornecedores carregados.');
}

function getSupplier(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT id, supplier_type, legal_name, trade_name, document_number,
               pix_key, bank_name, bank_agency, bank_account,
               contact_name, contact_email, contact_phone, notes, is_active,
               created_at, updated_at
        FROM suppliers
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Fornecedor não encontrado.', 404);
    }
    jsonSuccess($row, 'Fornecedor carregado.');
}

function createSupplier(array $body): void
{
    $user      = requireAuth(['admin', 'organizer']);
    $db        = Database::getInstance();
    $orgId     = resolveOrganizerId($user);
    $legalName = trim((string)($body['legal_name'] ?? ''));

    if ($legalName === '') {
        jsonError('legal_name é obrigatório.', 422);
    }

    $doc = trim((string)($body['document_number'] ?? '')) ?: null;

    try {
        $stmt = $db->prepare("
            INSERT INTO suppliers
                (organizer_id, supplier_type, legal_name, trade_name, document_number,
                 pix_key, bank_name, bank_agency, bank_account,
                 contact_name, contact_email, contact_phone, notes)
            VALUES
                (:organizer_id, :supplier_type, :legal_name, :trade_name, :document_number,
                 :pix_key, :bank_name, :bank_agency, :bank_account,
                 :contact_name, :contact_email, :contact_phone, :notes)
            RETURNING *
        ");
        $stmt->execute([
            ':organizer_id'   => $orgId,
            ':supplier_type'  => trim((string)($body['supplier_type'] ?? '')) ?: null,
            ':legal_name'     => $legalName,
            ':trade_name'     => trim((string)($body['trade_name'] ?? '')) ?: null,
            ':document_number'=> $doc,
            ':pix_key'        => trim((string)($body['pix_key'] ?? '')) ?: null,
            ':bank_name'      => trim((string)($body['bank_name'] ?? '')) ?: null,
            ':bank_agency'    => trim((string)($body['bank_agency'] ?? '')) ?: null,
            ':bank_account'   => trim((string)($body['bank_account'] ?? '')) ?: null,
            ':contact_name'   => trim((string)($body['contact_name'] ?? '')) ?: null,
            ':contact_email'  => trim((string)($body['contact_email'] ?? '')) ?: null,
            ':contact_phone'  => trim((string)($body['contact_phone'] ?? '')) ?: null,
            ':notes'          => trim((string)($body['notes'] ?? '')) ?: null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_supplier_org_document')) {
            jsonError("Já existe um fornecedor com o CNPJ/CPF '{$doc}' cadastrado.", 409);
        }
        throw $e;
    }

    jsonSuccess($row, 'Fornecedor criado com sucesso.', 201);
}

function updateSupplier(int $id, array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $check = $db->prepare("SELECT id FROM suppliers WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Fornecedor não encontrado.', 404);
    }

    $textFields = ['supplier_type','legal_name','trade_name','document_number',
                   'pix_key','bank_name','bank_agency','bank_account',
                   'contact_name','contact_email','contact_phone','notes'];

    $fields = [];
    $params = [':id' => $id, ':organizer_id' => $orgId];

    foreach ($textFields as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "{$f} = :{$f}";
            $params[":{$f}"] = trim((string)$body[$f]) ?: null;
        }
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
            UPDATE suppliers
            SET " . implode(', ', $fields) . ", updated_at = NOW()
            WHERE id = :id AND organizer_id = :organizer_id
            RETURNING *
        ");
        $stmt->execute($params);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_supplier_org_document')) {
            jsonError('Já existe um fornecedor com este CNPJ/CPF cadastrado.', 409);
        }
        throw $e;
    }

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Fornecedor atualizado com sucesso.');
}

// ── Contracts ─────────────────────────────────────────────────────────────────

function listContracts(array $query): void
{
    $user    = requireAuth(['admin', 'organizer', 'manager']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = (int)($query['event_id'] ?? 0);

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para listar contratos.', 422);
    }

    $stmt = $db->prepare("
        SELECT sc.id, sc.supplier_id, s.legal_name AS supplier_name,
               sc.contract_number, sc.description, sc.total_amount,
               sc.signed_at, sc.valid_until, sc.status, sc.file_path, sc.notes,
               sc.created_at, sc.updated_at
        FROM supplier_contracts sc
        JOIN suppliers s ON s.id = sc.supplier_id
        WHERE sc.organizer_id = :organizer_id AND sc.event_id = :event_id
        ORDER BY sc.created_at DESC
    ");
    $stmt->execute([':organizer_id' => $orgId, ':event_id' => $eventId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Contratos carregados.');
}

function getContract(int $id): void
{
    $user  = requireAuth(['admin', 'organizer', 'manager']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("
        SELECT sc.*, s.legal_name AS supplier_name
        FROM supplier_contracts sc
        JOIN suppliers s ON s.id = sc.supplier_id
        WHERE sc.id = :id AND sc.organizer_id = :organizer_id
    ");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('Contrato não encontrado.', 404);
    }
    jsonSuccess($row, 'Contrato carregado.');
}

function createContract(array $body): void
{
    $user        = requireAuth(['admin', 'organizer']);
    $db          = Database::getInstance();
    $orgId       = resolveOrganizerId($user);
    $eventId     = (int)($body['event_id'] ?? 0);
    $supplierId  = (int)($body['supplier_id'] ?? 0);
    $description = trim((string)($body['description'] ?? ''));

    if ($eventId <= 0)    { jsonError('event_id é obrigatório.', 422); }
    if ($supplierId <= 0) { jsonError('supplier_id é obrigatório.', 422); }
    if ($description === '') { jsonError('description é obrigatório.', 422); }

    // Verifica que o supplier pertence ao organizer
    $chk = $db->prepare("SELECT id FROM suppliers WHERE id = :id AND organizer_id = :org");
    $chk->execute([':id' => $supplierId, ':org' => $orgId]);
    if (!$chk->fetch()) {
        jsonError('Fornecedor não encontrado.', 404);
    }

    $validStatuses = ['draft','active','completed','cancelled'];
    $status = trim((string)($body['status'] ?? 'draft'));
    if (!in_array($status, $validStatuses, true)) {
        jsonError('Status inválido. Use: ' . implode(', ', $validStatuses), 422);
    }

    $stmt = $db->prepare("
        INSERT INTO supplier_contracts
            (organizer_id, event_id, supplier_id, contract_number, description,
             total_amount, signed_at, valid_until, status, file_path, notes)
        VALUES
            (:organizer_id, :event_id, :supplier_id, :contract_number, :description,
             :total_amount, :signed_at, :valid_until, :status, :file_path, :notes)
        RETURNING *
    ");
    $stmt->execute([
        ':organizer_id'    => $orgId,
        ':event_id'        => $eventId,
        ':supplier_id'     => $supplierId,
        ':contract_number' => trim((string)($body['contract_number'] ?? '')) ?: null,
        ':description'     => $description,
        ':total_amount'    => max(0, (float)($body['total_amount'] ?? 0)),
        ':signed_at'       => trim((string)($body['signed_at'] ?? '')) ?: null,
        ':valid_until'     => trim((string)($body['valid_until'] ?? '')) ?: null,
        ':status'          => $status,
        ':file_path'       => trim((string)($body['file_path'] ?? '')) ?: null,
        ':notes'           => trim((string)($body['notes'] ?? '')) ?: null,
    ]);

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Contrato criado com sucesso.', 201);
}

function updateContract(int $id, array $body): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $check = $db->prepare("SELECT id FROM supplier_contracts WHERE id = :id AND organizer_id = :organizer_id");
    $check->execute([':id' => $id, ':organizer_id' => $orgId]);
    if (!$check->fetch()) {
        jsonError('Contrato não encontrado.', 404);
    }

    $fields = [];
    $params = [':id' => $id, ':organizer_id' => $orgId];

    $textFields = ['contract_number','description','signed_at','valid_until','file_path','notes'];
    foreach ($textFields as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "{$f} = :{$f}";
            $params[":{$f}"] = trim((string)$body[$f]) ?: null;
        }
    }
    if (array_key_exists('total_amount', $body)) {
        $val = (float)$body['total_amount'];
        if ($val < 0) { jsonError('total_amount não pode ser negativo.', 422); }
        $fields[] = 'total_amount = :total_amount';
        $params[':total_amount'] = $val;
    }
    if (array_key_exists('status', $body)) {
        $validStatuses = ['draft','active','completed','cancelled'];
        $status = trim((string)$body['status']);
        if (!in_array($status, $validStatuses, true)) {
            jsonError('Status inválido.', 422);
        }
        $fields[] = 'status = :status';
        $params[':status'] = $status;
    }

    if (empty($fields)) {
        jsonError('Nenhum campo válido para atualizar.', 422);
    }

    $stmt = $db->prepare("
        UPDATE supplier_contracts
        SET " . implode(', ', $fields) . ", updated_at = NOW()
        WHERE id = :id AND organizer_id = :organizer_id
        RETURNING *
    ");
    $stmt->execute($params);

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Contrato atualizado com sucesso.');
}
