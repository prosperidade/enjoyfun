<?php

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listGuests($query),
        $method === 'GET' && $id === 'ticket' => getGuestTicket($query),
        $method === 'POST' && $id === 'import' => importGuests(),
        $method === 'POST' && $id === 'checkin' => checkinGuest($body),
        default => jsonError('Rota de convidados não encontrada.', 404),
    };
}

function listGuests(array $query): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizador inválido.', 403);
    }

    $eventId = isset($query['event_id']) ? (int)$query['event_id'] : 0;
    $search = trim((string)($query['search'] ?? ''));
    $page = max(1, (int)($query['page'] ?? 1));
    $limit = max(1, min(100, (int)($query['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    try {
        $db = Database::getInstance();

        $where = ['g.organizer_id = ?'];
        $params = [$organizerId];

        if ($eventId > 0) {
            $where[] = 'g.event_id = ?';
            $params[] = $eventId;
        }

        if ($search !== '') {
            $where[] = '(LOWER(g.name) LIKE LOWER(?) OR LOWER(g.email) LIKE LOWER(?))';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countSql = "
            SELECT COUNT(*)
            FROM guests g
            JOIN events e ON e.id = g.event_id
            {$whereSql}
        ";

        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $listSql = "
            SELECT g.id, g.organizer_id, g.event_id, g.name, g.email, g.phone, g.document,
                   g.status, g.qr_code_token, g.metadata, g.created_at,
                   e.name AS event_name
            FROM guests g
            JOIN events e ON e.id = g.event_id
            {$whereSql}
            ORDER BY g.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $listParams = [...$params, $limit, $offset];
        $stmt = $db->prepare($listSql);
        $stmt->execute($listParams);
        $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess([
            'items' => $guests,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
            ],
        ]);
    } catch (Throwable $e) {
        jsonError('Erro ao listar convidados: ' . $e->getMessage(), 500);
    }
}

function getGuestTicket(array $query): void
{
    $token = trim((string)($query['token'] ?? ''));

    if ($token === '') {
        jsonError('Token é obrigatório.', 422);
    }

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT g.name AS guest_name,
                   g.status,
                   g.qr_code_token,
                   e.id AS event_id,
                   e.name AS event_name,
                   COALESCE(e.event_date::text, e.starts_at::date::text) AS event_date,
                   COALESCE(e.banner_url, '') AS logo_url
            FROM guests g
            JOIN events e ON e.id = g.event_id
            WHERE g.qr_code_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            jsonError('Convite não encontrado.', 404);
        }

        jsonSuccess($ticket);
    } catch (Throwable $e) {
        jsonError('Erro ao carregar convite: ' . $e->getMessage(), 500);
    }
}

function checkinGuest(array $body): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $token = trim((string)($body['token'] ?? ''));

    if ($organizerId <= 0) {
        jsonError('Organizador inválido.', 403);
    }

    if ($token === '') {
        jsonError('Token é obrigatório.', 422);
    }

    try {
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id, status FROM guests WHERE qr_code_token = ? AND organizer_id = ? LIMIT 1');
        $stmt->execute([$token, $organizerId]);
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$guest) {
            jsonError('Convidado não encontrado.', 404);
        }

        if (($guest['status'] ?? '') === 'presente') {
            jsonError('Check-in já realizado', 400);
        }

        $updateStmt = $db->prepare("UPDATE guests SET status = 'presente' WHERE id = ?");
        $updateStmt->execute([(int)$guest['id']]);

        jsonSuccess(['id' => (int)$guest['id'], 'status' => 'presente'], 'Check-in realizado com sucesso.');
    } catch (Throwable $e) {
        jsonError('Erro ao realizar check-in: ' . $e->getMessage(), 500);
    }
}

function importGuests(): void
{
    $operator = requireAuth();
    $organizerId = (int)($operator['organizer_id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizador inválido.', 403);
    }

    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 422);
    }

    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        jsonError('Arquivo CSV é obrigatório no campo "file".', 422);
    }

    try {
        $db = Database::getInstance();

        $eventStmt = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $eventStmt->execute([$eventId, $organizerId]);

        if (!$eventStmt->fetch(PDO::FETCH_ASSOC)) {
            jsonError('Evento inválido para este organizador.', 403);
        }

        $csvPath = $_FILES['file']['tmp_name'];
        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            jsonError('Não foi possível abrir o arquivo CSV.', 422);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            jsonError('CSV vazio ou inválido.', 422);
        }

        $headerMap = array_map(static fn ($column) => strtolower(trim((string)$column)), $header);

        $requiredFields = ['name', 'email'];
        foreach ($requiredFields as $requiredField) {
            if (!in_array($requiredField, $headerMap, true)) {
                fclose($handle);
                jsonError("CSV precisa da coluna '{$requiredField}'.", 422);
            }
        }

        $indexOf = static function (string $column) use ($headerMap): ?int {
            $index = array_search($column, $headerMap, true);
            return $index === false ? null : $index;
        };

        $nameIndex = $indexOf('name');
        $emailIndex = $indexOf('email');
        $phoneIndex = $indexOf('phone');
        $documentIndex = $indexOf('document');
        $statusIndex = $indexOf('status');

        $upsertSql = "
            INSERT INTO guests (
                organizer_id, event_id, name, email, phone, document, status, qr_code_token, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
            ON CONFLICT (event_id, email)
            DO UPDATE SET
                name = EXCLUDED.name,
                phone = EXCLUDED.phone,
                document = EXCLUDED.document,
                status = EXCLUDED.status,
                metadata = EXCLUDED.metadata
            RETURNING id
        ";
        $upsertStmt = $db->prepare($upsertSql);

        $db->beginTransaction();

        $processed = 0;
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            $processed++;

            $name = trim((string)($row[$nameIndex] ?? ''));
            $email = trim((string)($row[$emailIndex] ?? ''));
            $phone = $phoneIndex !== null ? trim((string)($row[$phoneIndex] ?? '')) : null;
            $document = $documentIndex !== null ? trim((string)($row[$documentIndex] ?? '')) : null;
            $status = $statusIndex !== null ? trim((string)($row[$statusIndex] ?? '')) : 'esperado';

            if ($name === '' || $email === '') {
                $skipped++;
                $errors[] = "Linha {$line}: nome e email são obrigatórios.";
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = "Linha {$line}: e-mail inválido ({$email}).";
                continue;
            }

            if (!in_array($status, ['esperado', 'presente'], true)) {
                $status = 'esperado';
            }

            $qrCodeToken = bin2hex(random_bytes(16));
            $metadata = json_encode(['imported_line' => $line], JSON_UNESCAPED_UNICODE);

            $upsertStmt->execute([
                $organizerId,
                $eventId,
                $name,
                strtolower($email),
                $phone ?: null,
                $document ?: null,
                $status,
                $qrCodeToken,
                $metadata,
            ]);

            $imported++;
        }

        fclose($handle);
        $db->commit();

        jsonSuccess([
            'processed' => $processed,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50),
        ], 'Importação concluída com sucesso.');
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro na importação de convidados: ' . $e->getMessage(), 500);
    }
}
