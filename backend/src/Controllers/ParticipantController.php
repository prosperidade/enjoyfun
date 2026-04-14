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
        $method === 'POST'   && $id === 'backfill-qrs' => backfillParticipantQrs($body),
        $method === 'POST'   && $id === 'bulk-delete' => bulkDeleteParticipants($body),
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
    $pagination = enjoyNormalizePagination($query, 50, 500);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) jsonError('event_id é obrigatório.', 400);

    // Validação de acesso ao evento
    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) jsonError('Evento não encontrado.', 404);

    $categoryId = $query['category_id'] ?? null;
    $assignedOnly = ($query['assigned_only'] ?? '0') === '1';
    $roleId = (int)($query['role_id'] ?? 0);
    $joinAssignments = $assignedOnly ? "JOIN workforce_assignments wa ON wa.participant_id = ep.id AND wa.event_id = ep.event_id" : "";
    $search = trim((string)($query['search'] ?? ''));

    $whereParts = [
        'ep.event_id = :evt_id',
        'p.organizer_id = :org_id',
    ];
    if ($assignedOnly) {
        $requestedSector = normalizeParticipantSector((string)($query['sector'] ?? ''));
        $effectiveSector = null;
        if ($canBypassSector) {
            $effectiveSector = $requestedSector ?: null;
        } else {
            $effectiveSector = $userSector !== 'all' ? $userSector : ($requestedSector ?: null);
        }

        if ($effectiveSector) {
            $whereParts[] = "LOWER(COALESCE(wa.sector, '')) = :sector";
        }
        if ($roleId > 0) {
            $whereParts[] = 'wa.role_id = :role_id';
        }
    }

    if ($categoryId) {
        $whereParts[] = 'ep.category_id = :cat_id';
    }

    if ($search !== '') {
        $whereParts[] = "(
            LOWER(p.name) LIKE LOWER(:search)
            OR LOWER(COALESCE(p.email, '')) LIKE LOWER(:search)
            OR COALESCE(p.document, '') LIKE :search
            OR COALESCE(p.phone, '') LIKE :search
        )";
    }

    $fromSql = "
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        JOIN participant_categories c ON c.id = ep.category_id
        {$joinAssignments}
        WHERE " . implode(' AND ', $whereParts);

    $countStmt = $db->prepare("SELECT COUNT(DISTINCT ep.id) {$fromSql}");
    $dataStmt = $db->prepare("
        SELECT DISTINCT ep.id as participant_id, ep.category_id, ep.status, ep.qr_token, ep.created_at,
               CASE WHEN ep.qr_token IS NULL OR TRIM(ep.qr_token) = '' THEN true ELSE false END AS qr_token_missing,
               p.id as person_id, p.name, p.email, p.document, p.phone,
               c.name as category_name, c.type as category_type
        {$fromSql}
        ORDER BY p.name ASC
        LIMIT :limit OFFSET :offset
    ");

    foreach ([$countStmt, $dataStmt] as $stmt) {
        $stmt->bindValue(':evt_id', $eventId, PDO::PARAM_INT);
        $stmt->bindValue(':org_id', $organizerId, PDO::PARAM_INT);
        if ($categoryId) {
            $stmt->bindValue(':cat_id', $categoryId, PDO::PARAM_INT);
        }
        if ($search !== '') {
            $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
        }
    }
    if ($assignedOnly) {
        $requestedSector = normalizeParticipantSector((string)($query['sector'] ?? ''));
        $effectiveSector = $canBypassSector ? ($requestedSector ?: null) : ($userSector !== 'all' ? $userSector : ($requestedSector ?: null));
        if ($effectiveSector) {
            $countStmt->bindValue(':sector', $effectiveSector, PDO::PARAM_STR);
            $dataStmt->bindValue(':sector', $effectiveSector, PDO::PARAM_STR);
        }
        if ($roleId > 0) {
            $countStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $dataStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
        }
    }

    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    enjoyBindPagination($dataStmt, $pagination);
    $dataStmt->execute();
    $participants = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    $missingQrCount = 0;

    foreach ($participants as &$participant) {
        $isMissingQr = filter_var($participant['qr_token_missing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $participant['qr_token_missing'] = $isMissingQr;
        if ($isMissingQr) {
            $missingQrCount++;
        }
    }
    unset($participant);

    $message = $missingQrCount > 0
        ? "Existem {$missingQrCount} participante(s) sem QR token. A listagem não faz mais backfill automático; use POST /participants/backfill-qrs para regularizar o evento."
        : '';

    jsonPaginated($participants, $total, $pagination['page'], $pagination['per_page'], $message);
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

        $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, qr_token, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id");
        $stmtEp->execute([$eventId, $personId, $categoryId, $qrToken, $organizerId]);
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

    $stmtCategories = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ?");
    $stmtCategories->execute([$organizerId]);
    $validCategoryIds = array_fill_keys(
        array_map('intval', $stmtCategories->fetchAll(PDO::FETCH_COLUMN)),
        true
    );

    try {
        $db->beginTransaction();

        $successCount = 0;
        $errors = [];

        foreach ($participants as $index => $row) {
            $name = trim((string)($row['name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $document = trim((string)($row['document'] ?? ''));
            $phone = trim((string)($row['phone'] ?? ''));
            $categoryId = (int)($row['category_id'] ?? 0);

            if (!$name || !$categoryId) {
                $errors[] = "Linha " . ($index + 1) . ": Nome e category_id são obrigatórios.";
                continue;
            }

            if (!isset($validCategoryIds[$categoryId])) {
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
                $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, qr_token, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmtEp->execute([$eventId, $personId, $categoryId, $qrToken, $organizerId]);
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
    if (function_exists('setCurrentRequestEventId')) {
        setCurrentRequestEventId((int)($participant['event_id'] ?? 0));
    }

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

    $hasAssignments = participantTableExists($db, 'workforce_assignments');
    $hasMemberSettings = participantTableExists($db, 'workforce_member_settings');
    $hasCheckins = participantTableExists($db, 'participant_checkins');
    $hasMeals = participantTableExists($db, 'participant_meals');

    try {
        $db->beginTransaction();

        // H03 FIX: scope child-table deletes via subquery to prevent cross-tenant IDOR
        $scopedSubquery = "SELECT ep.id FROM event_participants ep JOIN events e ON e.id = ep.event_id WHERE ep.id = ? AND e.organizer_id = ?";

        if ($hasAssignments) {
            $db->prepare("DELETE FROM workforce_assignments WHERE participant_id IN ({$scopedSubquery})")->execute([$id, $organizerId]);
        }
        if ($hasMemberSettings) {
            $db->prepare("DELETE FROM workforce_member_settings WHERE participant_id IN ({$scopedSubquery})")->execute([$id, $organizerId]);
        }
        if ($hasCheckins) {
            $db->prepare("DELETE FROM participant_checkins WHERE participant_id IN ({$scopedSubquery})")->execute([$id, $organizerId]);
        }
        if ($hasMeals) {
            $db->prepare("DELETE FROM participant_meals WHERE participant_id IN ({$scopedSubquery})")->execute([$id, $organizerId]);
        }

        // H03 FIX: include organizer_id filter in the actual DELETE for defense-in-depth
        $delStmt = $db->prepare("
            DELETE FROM event_participants
            WHERE id = ?
              AND event_id IN (SELECT id FROM events WHERE organizer_id = ?)
        ");
        $delStmt->execute([$id, $organizerId]);
        if ($delStmt->rowCount() <= 0) {
            throw new RuntimeException('Participante não encontrado ou acesso negado.', 404);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao excluir participante: ' . $e->getMessage(), 500);
    }

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

function bulkDeleteParticipants(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassParticipantSectorAcl($user);
    $userSector = resolveUserSectorFromDb($db, $user);
    $ids = $body['ids'] ?? [];

    if (!is_array($ids) || count($ids) === 0) {
        jsonError('Informe ao menos um participante para exclusão em massa.', 422);
    }

    $normalized = [];
    foreach ($ids as $value) {
        if (is_numeric($value)) {
            $id = (int)$value;
            if ($id > 0) $normalized[] = $id;
        }
    }
    $normalized = array_values(array_unique($normalized));

    if (count($normalized) === 0) {
        jsonError('IDs inválidos para exclusão em massa.', 422);
    }

    if (count($normalized) > 500) {
        jsonError('Limite máximo de 500 participantes por operação.', 422);
    }

    $hasAssignments = participantTableExists($db, 'workforce_assignments');
    $hasMemberSettings = participantTableExists($db, 'workforce_member_settings');
    $hasCheckins = participantTableExists($db, 'participant_checkins');
    $hasMeals = participantTableExists($db, 'participant_meals');

    $stmtCheck = $db->prepare("
        SELECT ep.id, ep.event_id, ep.person_id, p.name, p.email
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        JOIN events e ON e.id = ep.event_id
        WHERE ep.id = ? AND e.organizer_id = ?
        LIMIT 1
    ");
    $stmtSector = $db->prepare("
        SELECT wa.id
        FROM workforce_assignments wa
        WHERE wa.participant_id = ? AND LOWER(COALESCE(wa.sector, '')) = ?
        LIMIT 1
    ");
    // H03 FIX: scope all deletes via subquery to enforce organizer_id at the SQL level
    $scopedSubquery = "SELECT ep.id FROM event_participants ep JOIN events e ON e.id = ep.event_id WHERE ep.id = ? AND e.organizer_id = ?";
    $stmtDelAssignments = $hasAssignments
        ? $db->prepare("DELETE FROM workforce_assignments WHERE participant_id IN ({$scopedSubquery})")
        : null;
    $stmtDelMemberSettings = $hasMemberSettings
        ? $db->prepare("DELETE FROM workforce_member_settings WHERE participant_id IN ({$scopedSubquery})")
        : null;
    $stmtDelCheckins = $hasCheckins
        ? $db->prepare("DELETE FROM participant_checkins WHERE participant_id IN ({$scopedSubquery})")
        : null;
    $stmtDelMeals = $hasMeals
        ? $db->prepare("DELETE FROM participant_meals WHERE participant_id IN ({$scopedSubquery})")
        : null;
    $stmtDelParticipant = $db->prepare("DELETE FROM event_participants WHERE id = ? AND event_id IN (SELECT id FROM events WHERE organizer_id = ?)");

    $deleted = 0;
    $notFound = [];
    $forbidden = [];
    $failed = [];

    foreach ($normalized as $participantId) {
        $stmtCheck->execute([$participantId, $organizerId]);
        $participant = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$participant) {
            $notFound[] = $participantId;
            continue;
        }

        if (!$canBypassSector && $userSector !== 'all') {
            if (!$hasAssignments) {
                $forbidden[] = $participantId;
                continue;
            }
            $stmtSector->execute([$participantId, $userSector]);
            if (!$stmtSector->fetchColumn()) {
                $forbidden[] = $participantId;
                continue;
            }
        }

        try {
            $db->beginTransaction();

            // H03 FIX: all deletes now scoped by organizer_id for defense-in-depth
            if ($stmtDelAssignments) $stmtDelAssignments->execute([$participantId, $organizerId]);
            if ($stmtDelMemberSettings) $stmtDelMemberSettings->execute([$participantId, $organizerId]);
            if ($stmtDelCheckins) $stmtDelCheckins->execute([$participantId, $organizerId]);
            if ($stmtDelMeals) $stmtDelMeals->execute([$participantId, $organizerId]);

            $stmtDelParticipant->execute([$participantId, $organizerId]);
            $db->commit();

            $deleted++;
            participantAudit(
                'participant.delete',
                (int)$participantId,
                [
                    'name' => $participant['name'],
                    'email' => $participant['email'],
                    'person_id' => (int)$participant['person_id']
                ],
                null,
                $user,
                [
                    'event_id' => (int)$participant['event_id'],
                    'bulk' => true
                ]
            );
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $failed[] = [
                'id' => $participantId,
                'reason' => $e->getMessage()
            ];
        }
    }

    $status = 'success';
    if ($deleted === 0) {
        $status = 'error';
    } elseif (!empty($notFound) || !empty($forbidden) || !empty($failed)) {
        $status = 'partial';
    }

    jsonSuccess([
        'status' => $status,
        'requested' => count($normalized),
        'deleted' => $deleted,
        'not_found' => $notFound,
        'forbidden' => $forbidden,
        'failed' => $failed
    ], $status === 'success'
        ? 'Exclusão em massa concluída.'
        : 'Exclusão em massa concluída com pendências.');
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

    $stmt = $db->prepare("
        SELECT id, name, type, 'organizer' AS scope_origin
        FROM participant_categories
        WHERE organizer_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$organizerId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($categories)) {
        jsonSuccess($categories);
    }

    jsonError('Nenhuma categoria de participantes cadastrada para este organizador.', 422);
}

function backfillParticipantQrs(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($body['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório.', 400);
    }

    $checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $checkEvent->execute([$eventId, $organizerId]);
    if (!$checkEvent->fetch()) {
        jsonError('Evento não encontrado.', 404);
    }

    try {
        $db->beginTransaction();

        $stmtIds = $db->prepare("
            SELECT ep.id
            FROM event_participants ep
            JOIN people p ON p.id = ep.person_id
            WHERE ep.event_id = :event_id
              AND p.organizer_id = :organizer_id
              AND (ep.qr_token IS NULL OR TRIM(ep.qr_token) = '')
            ORDER BY ep.id ASC
        ");
        $stmtIds->execute([
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
        ]);

        $participantIds = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));
        $updated = 0;
        $stmtUpdate = $db->prepare("
            UPDATE event_participants
            SET qr_token = :qr_token
            WHERE id = :id
              AND (qr_token IS NULL OR TRIM(qr_token) = '')
        ");

        foreach ($participantIds as $participantId) {
            $stmtUpdate->execute([
                ':qr_token' => participantGenerateQrToken(),
                ':id' => $participantId,
            ]);
            $updated += $stmtUpdate->rowCount();
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Falha ao executar backfill de QR: ' . $e->getMessage(), 500);
    }

    jsonSuccess([
        'event_id' => $eventId,
        'updated' => $updated,
    ], 'Backfill explícito de QR concluído.');
}

function participantGenerateQrToken(): string
{
    return 'PT_' . bin2hex(random_bytes(16));
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

function participantTableExists(PDO $db, string $tableName): bool
{
    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
        )
    ");
    $stmt->execute([':table_name' => $tableName]);
    return (bool)$stmt->fetchColumn();
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
            $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, status, qr_token, organizer_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtEp->execute([$eventId, $personId, $defaultCategoryId, $status, $g['qr_code_token'], $organizerId, $g['created_at'], $g['updated_at']]);
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

