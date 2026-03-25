<?php
/**
 * EventFinanceImportController
 * Importação em lote de dados financeiros.
 *
 * Fluxo obrigatório (doc 01_Regras_Compartilhadas.md §9):
 *   1. POST /imports/preview  → parse, valida, grava batch, retorna preview
 *   2. POST /imports/confirm  → aplica linhas válidas, fecha batch
 *   3. GET  /imports/{id}     → consulta batch existente
 *
 * Tipos suportados: payables | suppliers | budget_lines
 */

function dispatchEventFinance(string $method, string $subresource, ?string $id, ?string $sub, array $body, array $query): void
{
    match (true) {
        $method === 'POST' && $id === 'preview' => previewImport($body),
        $method === 'POST' && $id === 'confirm' => confirmImport($body),
        $method === 'GET'  && $id !== null && $id !== 'preview' && $id !== 'confirm' => getImportBatch((int)$id),
        default => jsonError('Endpoint de imports não encontrado. Use /imports/preview, /imports/confirm ou /imports/{id}.', 404),
    };
}

function previewImport(array $body): void
{
    $user    = requireAuth(['admin', 'organizer']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $eventId = !empty($body['event_id']) ? (int)$body['event_id'] : null;

    $importType     = trim((string)($body['import_type'] ?? ''));
    $sourceFilename = trim((string)($body['source_filename'] ?? ''));
    $rows           = $body['rows'] ?? [];

    $validTypes = ['payables', 'suppliers', 'budget_lines'];
    if (!in_array($importType, $validTypes, true)) {
        jsonError('import_type inválido. Use: ' . implode(', ', $validTypes), 422);
    }
    if ($sourceFilename === '') {
        jsonError('source_filename é obrigatório.', 422);
    }
    if (!is_array($rows) || empty($rows)) {
        jsonError('rows deve ser um array não vazio.', 422);
    }

    // Pré-carrega IDs válidos para validação pesada no preview, evitando FK Violations no confirm
    $context = ['categories' => [], 'cost_centers' => [], 'budgets' => []];
    if ($importType === 'payables' || $importType === 'budget_lines') {
        $context['categories'] = $db->query("SELECT id FROM event_cost_categories WHERE organizer_id = $orgId AND is_active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
        if ($eventId !== null) {
            $context['cost_centers'] = $db->query("SELECT id FROM event_cost_centers WHERE event_id = $eventId AND is_active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
            $context['budgets']      = $db->query("SELECT id FROM event_budgets WHERE event_id = $eventId AND is_active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    // Parse e validação das linhas
    $parsedRows   = [];
    $validCount   = 0;
    $invalidCount = 0;
    $errorSummary = [];

    foreach ($rows as $i => $rawRow) {
        $rowNum  = $i + 1;
        $errors  = validateImportRow($importType, $rawRow, $orgId, $eventId, $db, $context);
        $status  = empty($errors) ? 'valid' : 'invalid';

        if ($status === 'valid') {
            $validCount++;
        } else {
            $invalidCount++;
            $errorSummary[] = ['row' => $rowNum, 'errors' => $errors];
        }

        $parsedRows[] = [
            'row_number'          => $rowNum,
            'row_status'          => $status,
            'raw_payload'         => $rawRow,
            'normalized_payload'  => $status === 'valid' ? $rawRow : null,
            'error_messages'      => empty($errors) ? null : $errors,
        ];
    }

    // Grava o batch
    $batchStmt = $db->prepare("
        INSERT INTO financial_import_batches
            (organizer_id, event_id, import_type, source_filename, status, preview_payload, error_summary)
        VALUES
            (:organizer_id, :event_id, :import_type, :source_filename, 'pending', :preview, :errors)
        RETURNING id, status, created_at
    ");
    $batchStmt->execute([
        ':organizer_id'   => $orgId,
        ':event_id'       => $eventId,
        ':import_type'    => $importType,
        ':source_filename'=> $sourceFilename,
        ':preview'        => json_encode($parsedRows),
        ':errors'         => json_encode($errorSummary),
    ]);
    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
    $batchId = (int)$batch['id'];

    // Grava linhas
    foreach ($parsedRows as $pr) {
        $rowStmt = $db->prepare("
            INSERT INTO financial_import_rows
                (batch_id, row_number, row_status, raw_payload, normalized_payload, error_messages)
            VALUES
                (:batch_id, :row_number, :row_status, :raw_payload, :normalized_payload, :error_messages)
        ");
        $rowStmt->execute([
            ':batch_id'           => $batchId,
            ':row_number'         => $pr['row_number'],
            ':row_status'         => $pr['row_status'],
            ':raw_payload'        => json_encode($pr['raw_payload']),
            ':normalized_payload' => $pr['normalized_payload'] !== null ? json_encode($pr['normalized_payload']) : null,
            ':error_messages'     => $pr['error_messages'] !== null ? json_encode($pr['error_messages']) : null,
        ]);
    }

    jsonSuccess([
        'batch_id'     => $batchId,
        'import_type'  => $importType,
        'total_rows'   => count($rows),
        'valid'        => $validCount,
        'invalid'      => $invalidCount,
        'errors'       => $errorSummary,
        'preview'      => $parsedRows,
        'can_confirm'  => $validCount > 0,
    ], 'Preview de importação concluído. Confirme para aplicar as linhas válidas.');
}

function confirmImport(array $body): void
{
    $user    = requireAuth(['admin', 'organizer']);
    $db      = Database::getInstance();
    $orgId   = resolveOrganizerId($user);
    $batchId = (int)($body['batch_id'] ?? 0);

    if ($batchId <= 0) {
        jsonError('batch_id é obrigatório.', 422);
    }

    // Carrega o batch
    $checkStmt = $db->prepare("
        SELECT * FROM financial_import_batches
        WHERE id = :id AND organizer_id = :organizer_id
    ");
    $checkStmt->execute([':id' => $batchId, ':organizer_id' => $orgId]);
    $batch = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        jsonError('Batch de importação não encontrado.', 404);
    }
    if ($batch['status'] === 'done') {
        jsonError('Este batch já foi aplicado.', 409);
    }
    if ($batch['status'] === 'failed') {
        jsonError('Este batch falhou. Crie um novo preview.', 409);
    }

    // Carrega linhas válidas
    $rowsStmt = $db->prepare("
        SELECT id, row_number, normalized_payload
        FROM financial_import_rows
        WHERE batch_id = :batch_id AND row_status = 'valid'
    ");
    $rowsStmt->execute([':batch_id' => $batchId]);
    $validRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($validRows)) {
        jsonError('Nenhuma linha válida para aplicar neste batch.', 422);
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE financial_import_batches SET status = 'processing', updated_at = NOW() WHERE id = :id")
           ->execute([':id' => $batchId]);

        $applied = 0;
        $skipped = 0;

        foreach ($validRows as $row) {
            $payload = json_decode((string)$row['normalized_payload'], true) ?? [];
            
            $db->exec("SAVEPOINT import_row");
            try {
                $createdId = applyImportRow($batch['import_type'], $payload, $orgId, $batch['event_id'], $db);

                if ($createdId !== null) {
                    $db->prepare("UPDATE financial_import_rows SET row_status = 'applied', created_record_id = :cid, updated_at = NOW() WHERE id = :id")
                       ->execute([':cid' => $createdId, ':id' => $row['id']]);
                    $applied++;
                } else {
                    $db->prepare("UPDATE financial_import_rows SET row_status = 'skipped', updated_at = NOW() WHERE id = :id")
                       ->execute([':id' => $row['id']]);
                    $skipped++;
                }
                $db->exec("RELEASE SAVEPOINT import_row");
            } catch (\PDOException $e) {
                // Erro de banco (ex: FK Violation), faz rollback apenas desta linha usando SAVEPOINT
                $db->exec("ROLLBACK TO SAVEPOINT import_row");
                $db->prepare("UPDATE financial_import_rows SET row_status = 'invalid', error_messages = :err, updated_at = NOW() WHERE id = :id")
                   ->execute([':id' => $row['id'], ':err' => json_encode(['Erro no banco (Linha não importada): ' . $e->getMessage()])]);
                $skipped++;
            }
        }

        $db->prepare("UPDATE financial_import_batches SET status = 'done', confirmed_at = NOW(), updated_at = NOW() WHERE id = :id")
           ->execute([':id' => $batchId]);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->prepare("UPDATE financial_import_batches SET status = 'failed', updated_at = NOW() WHERE id = :id")
           ->execute([':id' => $batchId]);
        throw $e;
    }

    jsonSuccess([
        'batch_id' => $batchId,
        'applied'  => $applied,
        'skipped'  => $skipped,
    ], 'Importação aplicada com sucesso.');
}

function getImportBatch(int $id): void
{
    $user  = requireAuth(['admin', 'organizer']);
    $db    = Database::getInstance();
    $orgId = resolveOrganizerId($user);

    $stmt = $db->prepare("SELECT * FROM financial_import_batches WHERE id = :id AND organizer_id = :organizer_id");
    $stmt->execute([':id' => $id, ':organizer_id' => $orgId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        jsonError('Batch de importação não encontrado.', 404);
    }

    $rowsStmt = $db->prepare("
        SELECT id, row_number, row_status, raw_payload, error_messages, created_record_id
        FROM financial_import_rows WHERE batch_id = :batch_id ORDER BY row_number
    ");
    $rowsStmt->execute([':batch_id' => $id]);
    $batch['rows'] = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess($batch, 'Batch de importação carregado.');
}

// ── Validação e aplicação por tipo ────────────────────────────────────────────

function validateImportRow(string $type, array $row, int $orgId, ?int $eventId, PDO $db, array $context = []): array
{
    $errors = [];
    if ($type === 'payables') {
        if ($eventId === null)           { $errors[] = 'O lote precisa estar vinculado a um Evento.'; }
        if (empty($row['description']))  { $errors[] = 'description é obrigatório.'; }
        if (empty($row['amount']))       { $errors[] = 'amount é obrigatório.'; }
        if (empty($row['due_date']))     { $errors[] = 'due_date é obrigatório.'; }
        
        if (empty($row['category_id'])) { 
            $errors[] = 'category_id é obrigatório.'; 
        } elseif (!in_array((int)$row['category_id'], $context['categories'] ?? [])) { 
            $errors[] = "category_id '{$row['category_id']}' não existe ou está inativa para o organizer."; 
        }
        
        if (empty($row['cost_center_id'])) { 
            $errors[] = 'cost_center_id é obrigatório.'; 
        } elseif (!in_array((int)$row['cost_center_id'], $context['cost_centers'] ?? [])) { 
            $errors[] = "cost_center_id '{$row['cost_center_id']}' não existe ou inativo neste evento."; 
        }
    } elseif ($type === 'suppliers') {
        if (empty($row['legal_name'])) { $errors[] = 'legal_name é obrigatório.'; }
    } elseif ($type === 'budget_lines') {
        if ($eventId === null)             { $errors[] = 'O lote precisa estar vinculado a um Evento.'; }
        
        if (empty($row['category_id'])) { 
            $errors[] = 'category_id é obrigatório.'; 
        } elseif (!in_array((int)$row['category_id'], $context['categories'] ?? [])) { 
            $errors[] = "category_id '{$row['category_id']}' não existe ou está inativa."; 
        }
        
        if (empty($row['cost_center_id'])) { 
            $errors[] = 'cost_center_id é obrigatório.'; 
        } elseif (!in_array((int)$row['cost_center_id'], $context['cost_centers'] ?? [])) { 
            $errors[] = "cost_center_id '{$row['cost_center_id']}' não existe ou inativo neste evento."; 
        }
        
        if (empty($row['budget_id'])) { 
            $errors[] = 'budget_id é obrigatório.'; 
        } elseif (!in_array((int)$row['budget_id'], $context['budgets'] ?? [])) { 
            $errors[] = "budget_id '{$row['budget_id']}' não existe ou inativo neste evento."; 
        }
    }
    return $errors;
}

function applyImportRow(string $type, array $payload, int $orgId, ?int $eventId, PDO $db): ?int
{
    if ($type === 'payables' && $eventId !== null) {
        require_once __DIR__ . '/../Helpers/EventFinanceStatusHelper.php';
        $amount  = (float)($payload['amount'] ?? 0);
        $dueDate = (string)($payload['due_date'] ?? date('Y-m-d'));
        $status  = calculatePayableStatus($amount, 0, $dueDate);

        $stmt = $db->prepare("
            INSERT INTO event_payables
                (organizer_id, event_id, category_id, cost_center_id, description,
                 amount, paid_amount, remaining_amount, due_date, source_type, status)
            VALUES
                (:organizer_id, :event_id, :category_id, :cost_center_id, :description,
                 :amount, 0, :amount, :due_date, 'internal', :status)
            RETURNING id
        ");
        $stmt->execute([
            ':organizer_id'   => $orgId,
            ':event_id'       => $eventId,
            ':category_id'    => (int)$payload['category_id'],
            ':cost_center_id' => (int)$payload['cost_center_id'],
            ':description'    => $payload['description'],
            ':amount'         => $amount,
            ':due_date'       => $dueDate,
            ':status'         => $status,
        ]);
        return (int)$stmt->fetchColumn();
    }

    if ($type === 'suppliers') {
        $stmt = $db->prepare("
            INSERT INTO suppliers (organizer_id, legal_name, document_number)
            VALUES (:org, :name, :doc)
            ON CONFLICT DO NOTHING
            RETURNING id
        ");
        $stmt->execute([
            ':org'  => $orgId,
            ':name' => $payload['legal_name'],
            ':doc'  => !empty($payload['document_number']) ? $payload['document_number'] : null,
        ]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    if ($type === 'budget_lines' && $eventId !== null) {
        $stmt = $db->prepare("
            INSERT INTO event_budget_lines 
                (organizer_id, event_id, budget_id, category_id, cost_center_id, description, budgeted_amount)
            VALUES 
                (:org, :event, :budget, :cat, :cc, :desc, :amt)
            ON CONFLICT DO NOTHING
            RETURNING id
        ");
        $stmt->execute([
            ':org'    => $orgId,
            ':event'  => $eventId,
            ':budget' => (int)$payload['budget_id'],
            ':cat'    => (int)$payload['category_id'],
            ':cc'     => (int)$payload['cost_center_id'],
            ':desc'   => $payload['description'] ?? null,
            ':amt'    => (float)($payload['budgeted_amount'] ?? 0),
        ]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    return null;
}
