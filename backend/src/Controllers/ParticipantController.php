<?php
/**
 * Participant Controller
 * Gerencia o cadastro unificado de participantes do evento (Guests, Staff, etc).
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'    && $id === 'categories' => listCategories(),
        $method === 'GET'    && $id === null => listParticipants($query),
        $method === 'POST'   && $id === null && $sub === null => createParticipant($body),
        $method === 'POST'   && $id === 'import' => importParticipants($body),
        $method === 'POST'   && $id === 'migrate' => migrateLegacyGuests(),
        $method === 'PUT'    && $id !== null => updateParticipant((int)$id, $body),
        $method === 'DELETE' && $id !== null => deleteParticipant((int)$id),
        default => jsonError('Endpoint de Participantes não encontrado.', 404),
    };
}

function listParticipants(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff', 'parking_staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSectorFromDb($db, $user);
    $canBypassSector = canBypassParticipantSectorAcl($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) jsonError('event_id é obrigatório.', 400);

    // Validação de acesso ao evento
    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) jsonError('Evento não encontrado.', 404);

    // Garante QR token para participantes antigos sem token.
    $stmtFixQr = $db->prepare("
        UPDATE event_participants ep
        SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || ep.id::text)
        FROM people p
        WHERE ep.person_id = p.id
          AND ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
          AND (ep.qr_token IS NULL OR TRIM(ep.qr_token) = '')
    ");
    $stmtFixQr->execute([
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId
    ]);

    $categoryId = $query['category_id'] ?? null;
    $assignedOnly = ($query['assigned_only'] ?? '0') === '1';
    $roleId = (int)($query['role_id'] ?? 0);
    $whereCat = $categoryId ? " AND ep.category_id = :cat_id" : "";
    $joinAssignments = $assignedOnly ? "JOIN workforce_assignments wa ON wa.participant_id = ep.id" : "";
    $whereRole = ($assignedOnly && $roleId > 0) ? " AND wa.role_id = :role_id" : "";

    $whereSector = "";
    if ($assignedOnly) {
        $requestedSector = normalizeParticipantSector((string)($query['sector'] ?? ''));
        $effectiveSector = null;
        if ($canBypassSector) {
            $effectiveSector = $requestedSector ?: null;
        } else {
            $effectiveSector = $userSector !== 'all' ? $userSector : ($requestedSector ?: null);
        }

        if ($effectiveSector) {
            $whereSector = " AND LOWER(COALESCE(wa.sector, '')) = :sector";
        }
    }

    $sql = "
        SELECT DISTINCT ep.id as participant_id, ep.category_id, ep.status, ep.qr_token, ep.created_at,
               p.id as person_id, p.name, p.email, p.document, p.phone,
               c.name as category_name, c.type as category_type
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        JOIN participant_categories c ON c.id = ep.category_id
        {$joinAssignments}
        WHERE ep.event_id = :evt_id AND p.organizer_id = :org_id
        $whereCat
        $whereSector
        $whereRole
        ORDER BY p.name ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':evt_id', $eventId, PDO::PARAM_INT);
    $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
    if ($categoryId) $stmt->bindValue(':cat_id', $categoryId, PDO::PARAM_INT);
    if ($assignedOnly) {
        $requestedSector = normalizeParticipantSector((string)($query['sector'] ?? ''));
        $effectiveSector = $canBypassSector ? ($requestedSector ?: null) : ($userSector !== 'all' ? $userSector : ($requestedSector ?: null));
        if ($effectiveSector) {
            $stmt->bindValue(':sector', $effectiveSector, PDO::PARAM_STR);
        }
        if ($roleId > 0) {
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
        }
    }

    $stmt->execute();
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createParticipant(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = $body['event_id'] ?? null;
    $categoryId = $body['category_id'] ?? null;
    $name = trim((string)($body['name'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $document = trim((string)($body['document'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));

    if (!$eventId || !$categoryId || !$name) {
        jsonError('Dados obrigatórios: event_id, category_id, name.', 400);
    }

    $db->beginTransaction();

    try {
        // Validação de evento e categoria
        $stmtVal = $db->prepare("SELECT e.id FROM events e JOIN participant_categories c ON c.organizer_id = e.organizer_id WHERE e.id = ? AND c.id = ? AND e.organizer_id = ?");
        $stmtVal->execute([$eventId, $categoryId, $organizerId]);
        if (!$stmtVal->fetchColumn()) {
            throw new \Exception('Evento ou Categoria inválidos para este organizador.');
        }

        // Busca ou cria pessoa
        // Tenta achar pelo documento se tiver (ou pelo email) dentro do tenant
        $personId = null;
        if ($document !== '') {
            $stmtFind = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ? LIMIT 1");
            $stmtFind->execute([$document, $organizerId]);
            $personId = $stmtFind->fetchColumn();
        } elseif ($email !== '') {
            $stmtFind = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ? LIMIT 1");
            $stmtFind->execute([$email, $organizerId]);
            $personId = $stmtFind->fetchColumn();
        }

        if (!$personId) {
            $stmtIns = $db->prepare("INSERT INTO people (name, email, document, phone, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id");
            $stmtIns->execute([$name, $email, $document, $phone, $organizerId]);
            $personId = $stmtIns->fetchColumn();
        } else {
            // Atualiza os dados da pessoa
            $stmtUpd = $db->prepare("UPDATE people SET name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
            $stmtUpd->execute([$name, $email, $phone, $personId]);
        }

        // Verifica se já está no evento
        $stmtCheck = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ?");
        $stmtCheck->execute([$eventId, $personId]);
        if ($stmtCheck->fetchColumn()) {
            throw new \Exception('Participante já está adicionado neste evento.');
        }

        $qrToken = 'PT_' . bin2hex(random_bytes(16));

        $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, qr_token, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
        $stmtEp->execute([$eventId, $personId, $categoryId, $qrToken]);
        $participantId = $stmtEp->fetchColumn();

        $db->commit();
        participantAudit(
            'participant.create',
            (int)$participantId,
            null,
            ['event_id' => (int)$eventId, 'person_id' => (int)$personId, 'category_id' => (int)$categoryId],
            $user,
            ['event_id' => (int)$eventId]
        );
        jsonSuccess(['participant_id' => $participantId, 'qr_token' => $qrToken], 'Participante adicionado com sucesso.', 201);

    } catch (\Exception $e) {
        $db->rollBack();
        jsonError($e->getMessage(), 400);
    }
}

function importParticipants(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = $body['event_id'] ?? null;
    $participants = $body['participants'] ?? [];

    if (!$eventId || !is_array($participants) || count($participants) === 0) {
        jsonError('Dados inválidos. O array de participantes e o event_id são obrigatórios.', 400);
    }

    $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
    $stmtEvent->execute([$eventId, $organizerId]);
    if (!$stmtEvent->fetchColumn()) {
        jsonError('Evento inválido para este organizador.', 403);
    }

    try {
        $db->beginTransaction();

        $successCount = 0;
        $errors = [];

        foreach ($participants as $index => $row) {
            $name = trim((string)($row['name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $document = trim((string)($row['document'] ?? ''));
            $phone = trim((string)($row['phone'] ?? ''));
            $categoryId = $row['category_id'] ?? null;

            if (!$name || !$categoryId) {
                $errors[] = "Linha " . ($index + 1) . ": Nome e category_id são obrigatórios.";
                continue;
            }

            // Valida categoria do tenant
            $stmtCat = $db->prepare("SELECT id FROM participant_categories WHERE id = ? AND organizer_id = ?");
            $stmtCat->execute([$categoryId, $organizerId]);
            if (!$stmtCat->fetchColumn()) {
                $errors[] = "Linha " . ($index + 1) . ": Categoria inválida.";
                continue;
            }

            $personId = null;
            if ($document !== '') {
                $stmtFind = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ? LIMIT 1");
                $stmtFind->execute([$document, $organizerId]);
                $personId = $stmtFind->fetchColumn();
            } elseif ($email !== '') {
                $stmtFind = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ? LIMIT 1");
                $stmtFind->execute([$email, $organizerId]);
                $personId = $stmtFind->fetchColumn();
            }

            if (!$personId) {
                $stmtIns = $db->prepare("INSERT INTO people (name, email, document, phone, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id");
                $stmtIns->execute([$name, $email, $document, $phone, $organizerId]);
                $personId = $stmtIns->fetchColumn();
            }

            $stmtCheck = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ?");
            $stmtCheck->execute([$eventId, $personId]);
            
            if (!$stmtCheck->fetchColumn()) {
                $qrToken = 'PT_' . bin2hex(random_bytes(16));
                $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, qr_token, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmtEp->execute([$eventId, $personId, $categoryId, $qrToken]);
                $successCount++;
            }
        }

        $db->commit();
        $skippedCount = count($participants) - $successCount - count($errors);
        participantAudit(
            'participant.import',
            (int)$eventId,
            null,
            ['imported' => $successCount, 'skipped' => $skippedCount, 'errors_count' => count($errors)],
            $user,
            ['event_id' => (int)$eventId]
        );

        jsonSuccess(['imported' => $successCount, 'skipped' => $skippedCount, 'errors' => $errors], "$successCount participantes importados, $skippedCount já existiam e " . count($errors) . " erros.");

    } catch (\Exception $e) {
        $db->rollBack();
        jsonError('Erro na importação em massa: ' . $e->getMessage(), 500);
    }
}

function updateParticipant(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassParticipantSectorAcl($user);
    $userSector = resolveUserSectorFromDb($db, $user);

    $name = trim((string)($body['name'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));
    $categoryId = $body['category_id'] ?? null;

    if (!$name || !$categoryId) {
        jsonError('Nome e Categoria são obrigatórios.', 400);
    }

    $db->beginTransaction();
    try {
        // Verifica se a inscrição pertence ao organizer
        $stmtCheck = $db->prepare("
            SELECT ep.id, ep.person_id, ep.event_id, ep.category_id, p.name AS current_name, p.email AS current_email, p.phone AS current_phone
            FROM event_participants ep
            JOIN people p ON p.id = ep.person_id
            JOIN events e ON e.id = ep.event_id
            WHERE ep.id = ? AND e.organizer_id = ?
        ");
        $stmtCheck->execute([$id, $organizerId]);
        $participant = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$participant) jsonError('Participante não encontrado.', 404);

        if (!$canBypassSector && $userSector !== 'all') {
            $stmtSector = $db->prepare("
                SELECT wa.id
                FROM workforce_assignments wa
                WHERE wa.participant_id = ? AND LOWER(COALESCE(wa.sector, '')) = ?
                LIMIT 1
            ");
            $stmtSector->execute([$id, $userSector]);
            if (!$stmtSector->fetchColumn()) {
                jsonError('Você não possui permissão para editar este participante.', 403);
            }
        }

        // Atualiza a pessoa
        $stmtP = $db->prepare("UPDATE people SET name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
        $stmtP->execute([$name, $email, $phone, $participant['person_id']]);

        // Atualiza a categoria (se for do tenant)
        $stmtCat = $db->prepare("SELECT id FROM participant_categories WHERE id = ? AND organizer_id = ?");
        $stmtCat->execute([$categoryId, $organizerId]);
        if (!$stmtCat->fetchColumn()) throw new \Exception('Categoria inválida.');

        $stmtEp = $db->prepare("UPDATE event_participants SET category_id = ?, updated_at = NOW() WHERE id = ?");
        $stmtEp->execute([$categoryId, $id]);

        $db->commit();
        participantAudit(
            'participant.update',
            $id,
            [
                'name' => $participant['current_name'],
                'email' => $participant['current_email'],
                'phone' => $participant['current_phone'],
                'category_id' => (int)$participant['category_id']
            ],
            [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'category_id' => (int)$categoryId
            ],
            $user,
            ['event_id' => (int)$participant['event_id']]
        );
        jsonSuccess([], 'Participante atualizado com sucesso.');
    } catch (\Exception $e) {
        $db->rollBack();
        jsonError($e->getMessage(), 400);
    }
}

function deleteParticipant(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassParticipantSectorAcl($user);
    $userSector = resolveUserSectorFromDb($db, $user);

    // Verifica se a inscrição pertence a um evento do tenant
    $stmtCheck = $db->prepare("
        SELECT ep.id, ep.event_id, ep.person_id, p.name, p.email
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        JOIN events e ON e.id = ep.event_id
        WHERE ep.id = ? AND e.organizer_id = ?
    ");
    $stmtCheck->execute([$id, $organizerId]);
    $participant = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$participant) jsonError('Participante não encontrado.', 404);

    if (!$canBypassSector && $userSector !== 'all') {
        $stmtSector = $db->prepare("
            SELECT wa.id
            FROM workforce_assignments wa
            WHERE wa.participant_id = ? AND LOWER(COALESCE(wa.sector, '')) = ?
            LIMIT 1
        ");
        $stmtSector->execute([$id, $userSector]);
        if (!$stmtSector->fetchColumn()) {
            jsonError('Você não possui permissão para remover este participante.', 403);
        }
    }

    $db->prepare("DELETE FROM event_participants WHERE id = ?")->execute([$id]);
    participantAudit(
        'participant.delete',
        $id,
        ['name' => $participant['name'], 'email' => $participant['email'], 'person_id' => (int)$participant['person_id']],
        null,
        $user,
        ['event_id' => (int)$participant['event_id']]
    );
    jsonSuccess([], 'Participante removido do evento com sucesso.');
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function listCategories(): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $stmt = $db->prepare("SELECT id, name, type FROM participant_categories WHERE organizer_id = ? OR organizer_id IS NULL ORDER BY id ASC");
    $stmt->execute([$organizerId]);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function canBypassParticipantSectorAcl(array $user): bool
{
    $role = strtolower((string)($user['role'] ?? ''));
    return $role === 'admin' || $role === 'organizer';
}

function resolveUserSectorFromDb(PDO $db, array $user): string
{
    $tokenSector = normalizeParticipantSector((string)($user['sector'] ?? ''));
    if ($tokenSector !== '') {
        return $tokenSector;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return 'all';
    }

    $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(sector), ''), 'all') FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $sector = $stmt->fetchColumn();
    return normalizeParticipantSector((string)$sector) ?: 'all';
}

function normalizeParticipantSector(string $value): string
{
    $v = strtolower(trim($value));
    return preg_replace('/\s+/', '_', $v);
}

function migrateLegacyGuests(): void
{
    $user = requireAuth(['admin']); // Apenas admin pode disparar migração global
    $db = Database::getInstance();

    try {
        $db->beginTransaction();

        // 1. Fetch ALL legacy guests
        $stmtG = $db->query("SELECT * FROM guests");
        $guests = $stmtG->fetchAll(PDO::FETCH_ASSOC);

        $migrated = 0;
        $skipped = 0;

        foreach ($guests as $g) {
            $orgId = (int)$g['organizer_id'];
            $eventId = (int)$g['event_id'];
            $name = $g['name'];
            $email = strtolower(trim($g['email']));
            $document = trim($g['document'] ?? '');

            // 2. Seed Categories for THIS organizer if none exist
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM participant_categories WHERE organizer_id = ?");
            $stmtCount->execute([$orgId]);
            if ($stmtCount->fetchColumn() == 0) {
                $cats = [
                    ['name' => 'Convidado VIP', 'type' => 'guest', 'organizer_id' => $orgId],
                    ['name' => 'Artista', 'type' => 'artist', 'organizer_id' => $orgId],
                    ['name' => 'DJ', 'type' => 'dj', 'organizer_id' => $orgId],
                    ['name' => 'Permuta', 'type' => 'permuta', 'organizer_id' => $orgId],
                    ['name' => 'Staff', 'type' => 'staff', 'organizer_id' => $orgId]
                ];
                $stmtIns = $db->prepare("INSERT INTO participant_categories (name, type, organizer_id) VALUES (?, ?, ?)");
                foreach ($cats as $c) {
                    $stmtIns->execute([$c['name'], $c['type'], $c['organizer_id']]);
                }
            }

            // 3. Get default category for this organizer
            $stmtCat = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? AND type = 'guest' LIMIT 1");
            $stmtCat->execute([$orgId]);
            $defaultCategoryId = $stmtCat->fetchColumn();
            if (!$defaultCategoryId) {
                 $stmtCat2 = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? LIMIT 1");
                 $stmtCat2->execute([$orgId]);
                 $defaultCategoryId = $stmtCat2->fetchColumn();
            }
            if (!$defaultCategoryId) continue;

            // 4. Find or Create Person
            $personId = null;
            if ($document !== '') {
                $stmtFind = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ? LIMIT 1");
                $stmtFind->execute([$document, $orgId]);
                $personId = $stmtFind->fetchColumn();
            } elseif ($email !== '') {
                $stmtFind = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ? LIMIT 1");
                $stmtFind->execute([$email, $orgId]);
                $personId = $stmtFind->fetchColumn();
            }

            if (!$personId) {
                $stmtInsP = $db->prepare("INSERT INTO people (name, email, document, phone, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
                $stmtInsP->execute([$name, $email, $document, $g['phone'], $orgId, $g['created_at']]);
                $personId = $stmtInsP->fetchColumn();
            }

            // 5. Check if already in event_participants
            $stmtCheck = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ?");
            $stmtCheck->execute([$eventId, $personId]);
            if ($stmtCheck->fetchColumn()) {
                $skipped++;
                continue;
            }

            // 6. Insert into event_participants
            $status = $g['status'] === 'presente' ? 'present' : 'expected';
            $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, status, qr_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtEp->execute([$eventId, $personId, $defaultCategoryId, $status, $g['qr_code_token'], $g['created_at'], $g['updated_at']]);
            $migrated++;
        }

        $db->commit();
        jsonSuccess(['migrated' => $migrated, 'skipped' => $skipped], "Migração concluída: $migrated convidados migrados.");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonError($e->getMessage());
    }
}

function participantAudit(string $action, int $participantId, $before, $after, array $user, array $extra = []): void
{
    if (!class_exists('AuditService')) return;

    AuditService::log(
        $action,
        'participant',
        $participantId,
        $before,
        $after,
        $user,
        'success',
        $extra
    );
}


