<?php
/**
 * Workforce Controller
 * Gerencia os cargos e atribuições de trabalho de Staff e Terceiros no evento.
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Sub-rotas: /api/workforce/roles ou /api/workforce/assignments
    if ($id === 'import') {
        match (true) {
            $method === 'POST'   => importWorkforce($body),
            default => jsonError('Endpoint de Importação de Workforce não encontrado.', 404),
        };
        return;
    }

    if ($id === 'roles') {
        match (true) {
            $method === 'GET'    => listRoles(),
            $method === 'POST'   && $sub !== null && $subId === 'import' => importWorkforce($body, (int)$sub),
            $method === 'POST'   => createRole($body),
            $method === 'DELETE' && $sub !== null => deleteRole((int)$sub),
            default => jsonError('Endpoint de Roles não encontrado.', 404),
        };
        return;
    }

    if ($id === 'member-settings') {
        match (true) {
            $method === 'GET' && $sub !== null => getMemberSettings((int)$sub),
            $method === 'PUT' && $sub !== null => upsertMemberSettings((int)$sub, $body),
            default => jsonError('Endpoint de Configuração de Membro não encontrado.', 404),
        };
        return;
    }

    if ($id === 'assignments') {
        match (true) {
            $method === 'GET'    => listAssignments($query),
            $method === 'POST'   => createAssignment($body),
            $method === 'DELETE' && $sub !== null => deleteAssignment((int)$sub),
            default => jsonError('Endpoint de Assignments não encontrado.', 404),
        };
        return;
    }

    jsonError('Endpoint de Workforce não encontrado (utilize /workforce/roles ou /workforce/assignments).', 404);
}

// ----------------------------------------------------
// ROLES (Cargos Operacionais do Organizador)
// ----------------------------------------------------

function listRoles(): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $hasRoleSector = columnExists($db, 'workforce_roles', 'sector');
    $sectorSelect = $hasRoleSector ? "sector" : "NULL::varchar AS sector";
    $sql = "SELECT id, name, {$sectorSelect}, created_at FROM workforce_roles WHERE organizer_id = ?";
    $params = [$organizerId];
    if ($hasRoleSector && !$canBypassSector && $userSector !== 'all') {
        $sql .= " AND LOWER(COALESCE(sector, '')) = ?";
        $params[] = $userSector;
    }
    $sql .= " ORDER BY name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createRole(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $name = trim((string)($body['name'] ?? ''));
    $sector = normalizeSector((string)($body['sector'] ?? ''));
    if (!$name) jsonError('Nome do cargo é obrigatório.', 400);

    if (!$canBypassSector && $userSector !== 'all') {
        $sector = $userSector;
    }
    if (!$sector) {
        $sector = inferSectorFromRoleName($name);
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, sector, created_at) VALUES (?, ?, ?, NOW()) RETURNING id");
        $stmt->execute([$organizerId, $name, $sector ?: null]);
    } else {
        $stmt = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, created_at) VALUES (?, ?, NOW()) RETURNING id");
        $stmt->execute([$organizerId, $name]);
    }

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Cargo criado com sucesso.', 201);
}

function deleteRole(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $stmtCheck = $db->prepare("SELECT id FROM workforce_roles WHERE id = ? AND organizer_id = ?");
    $stmtCheck->execute([$id, $organizerId]);
    if (!$stmtCheck->fetchColumn()) jsonError('Cargo não encontrado.', 404);

    $checkDeps = $db->prepare("SELECT COUNT(*) FROM workforce_assignments WHERE role_id = ?");
    $checkDeps->execute([$id]);
    if ($checkDeps->fetchColumn() > 0) jsonError('Não é possível excluir o cargo pois existem alocações vinculadas a ele.', 409);

    $db->prepare("DELETE FROM workforce_roles WHERE id = ?")->execute([$id]);
    jsonSuccess([], 'Cargo excluído.');
}

// ----------------------------------------------------
// ASSIGNMENTS (Alocações da Equipe)
// ----------------------------------------------------

function listAssignments(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $eventId = $query['event_id'] ?? null;
    if (!$eventId) jsonError('event_id é obrigatório para consultar escalas.', 400);

    // Garante QR token para equipe já existente sem token.
    backfillMissingQrTokensForEvent($db, (int)$eventId, $organizerId);

    $requestedSector = normalizeSector((string)($query['sector'] ?? ''));
    $requestedRoleId = (int)($query['role_id'] ?? 0);
    $effectiveSector = null;

    if ($canBypassSector) {
        $effectiveSector = $requestedSector ?: null;
    } else {
        $effectiveSector = $userSector !== 'all' ? $userSector : ($requestedSector ?: null);
    }

    $whereSector = '';
    $params = [':evt_id' => $eventId, ':org_id' => $organizerId];
    if ($effectiveSector) {
        $whereSector = ' AND LOWER(COALESCE(wa.sector, \'\')) = :sector';
        $params[':sector'] = $effectiveSector;
    }
    if ($requestedRoleId > 0) {
        $params[':role_id'] = $requestedRoleId;
    }

    $sql = "
        SELECT wa.id, wa.sector, wa.created_at,
               ep.id as participant_id, ep.qr_token, p.name as person_name, p.phone,
               r.id as role_id, r.name as role_name,
               es.id as shift_id, es.name as shift_name, ed.date as shift_date,
               wms.max_shifts_event, wms.shift_hours, wms.meals_per_day, wms.payment_amount
        FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN people p ON p.id = ep.person_id
        JOIN workforce_roles r ON r.id = wa.role_id
        LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
        LEFT JOIN event_days ed ON ed.id = es.event_day_id
        LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
        WHERE ep.event_id = :evt_id AND p.organizer_id = :org_id
        {$whereSector}
        " . ($requestedRoleId > 0 ? " AND wa.role_id = :role_id" : "") . "
        ORDER BY p.name ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function createAssignment(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $participantId = $body['participant_id'] ?? null;
    $roleId = $body['role_id'] ?? null;
    $sector = normalizeSector((string)($body['sector'] ?? ''));
    $eventShiftId = $body['event_shift_id'] ?? null;

    if (!$participantId || !$roleId) {
        jsonError('Dados necessários: participant_id, role_id.', 400);
    }

    if (!$canBypassSector && $userSector !== 'all') {
        if (!$sector) {
            $sector = $userSector;
        }
        if ($sector !== $userSector) {
            jsonError('Você só pode alocar no seu próprio setor.', 403);
        }
    }

    // Validate Participant
    $stmtPart = $db->prepare("
        SELECT e.id FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        WHERE ep.id = ? AND e.organizer_id = ?
    ");
    $stmtPart->execute([$participantId, $organizerId]);
    if (!$stmtPart->fetchColumn()) jsonError('Participante inválido ou não peretence ao tenant.', 403);
    ensureParticipantQrToken($db, (int)$participantId);

    // Validate Role
    $role = getRoleById($db, $organizerId, (int)$roleId);
    if (!$role) jsonError('Cargo inválido.', 403);
    $roleSector = normalizeSector((string)($role['sector'] ?? ''));
    if (!$canBypassSector && $userSector !== 'all' && $roleSector && $roleSector !== $userSector) {
        jsonError('Você não pode alocar neste cargo de outro setor.', 403);
    }
    if (!$sector && $roleSector) {
        $sector = $roleSector;
    }

    // Validate Shift (se passado)
    if ($eventShiftId) {
        $stmtShift = $db->prepare("
            SELECT es.id FROM event_shifts es
            JOIN event_days ed ON ed.id = es.event_day_id
            JOIN events e ON e.id = ed.event_id
            WHERE es.id = ? AND e.organizer_id = ?
        ");
        $stmtShift->execute([$eventShiftId, $organizerId]);
        if (!$stmtShift->fetchColumn()) jsonError('Turno inválido.', 403);
    }

    if (columnExists($db, 'workforce_assignments', 'manager_user_id') && columnExists($db, 'workforce_assignments', 'source_file_name')) {
        $managerUserId = $canBypassSector ? null : (int)($user['id'] ?? 0);
        $stmt = $db->prepare("
            INSERT INTO workforce_assignments (participant_id, role_id, sector, event_shift_id, manager_user_id, source_file_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmt->execute([$participantId, $roleId, $sector ?: null, $eventShiftId ?: null, $managerUserId ?: null, 'manual']);
    } else {
        $stmt = $db->prepare("INSERT INTO workforce_assignments (participant_id, role_id, sector, event_shift_id, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
        $stmt->execute([$participantId, $roleId, $sector ?: null, $eventShiftId ?: null]);
    }

    jsonSuccess(['id' => $stmt->fetchColumn()], 'Escala registrada com sucesso.', 201);
}

function deleteAssignment(int $id): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $sql = "
        SELECT wa.id FROM workforce_assignments wa
        JOIN event_participants ep ON ep.id = wa.participant_id
        JOIN events e ON e.id = ep.event_id
        WHERE wa.id = ? AND e.organizer_id = ?
    ";
    $params = [$id, $organizerId];
    if (!$canBypassSector && $userSector !== 'all') {
        $sql .= " AND LOWER(COALESCE(wa.sector, '')) = ?";
        $params[] = $userSector;
    }

    $stmtCheck = $db->prepare($sql);
    $stmtCheck->execute($params);
    if (!$stmtCheck->fetchColumn()) jsonError('Escala não encontrada.', 404);

    $db->prepare("DELETE FROM workforce_assignments WHERE id = ?")->execute([$id]);
    jsonSuccess([], 'Escala removida com sucesso.');
}

function getMemberSettings(int $participantId): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $stmtPart = $db->prepare("
        SELECT ep.id
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        LEFT JOIN workforce_assignments wa ON wa.participant_id = ep.id
        WHERE ep.id = :participant_id
          AND e.organizer_id = :organizer_id
          " . (!$canBypassSector && $userSector !== 'all' ? " AND LOWER(COALESCE(wa.sector, '')) = :sector " : "") . "
        LIMIT 1
    ");
    $params = [':participant_id' => $participantId, ':organizer_id' => $organizerId];
    if (!$canBypassSector && $userSector !== 'all') {
        $params[':sector'] = $userSector;
    }
    $stmtPart->execute($params);
    if (!$stmtPart->fetchColumn()) {
        jsonError('Participante não encontrado ou sem permissão.', 404);
    }

    $stmt = $db->prepare("
        SELECT participant_id, max_shifts_event, shift_hours, meals_per_day, payment_amount
        FROM workforce_member_settings
        WHERE participant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$participantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $row = [
            'participant_id' => $participantId,
            'max_shifts_event' => 1,
            'shift_hours' => 8,
            'meals_per_day' => 4,
            'payment_amount' => 0
        ];
    }

    jsonSuccess($row);
}

function upsertMemberSettings(int $participantId, array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $userSector = resolveUserSector($db, $user);
    $canBypassSector = canBypassSectorAcl($user);

    $stmtPart = $db->prepare("
        SELECT ep.id
        FROM event_participants ep
        JOIN events e ON e.id = ep.event_id
        LEFT JOIN workforce_assignments wa ON wa.participant_id = ep.id
        WHERE ep.id = :participant_id
          AND e.organizer_id = :organizer_id
          " . (!$canBypassSector && $userSector !== 'all' ? " AND LOWER(COALESCE(wa.sector, '')) = :sector " : "") . "
        LIMIT 1
    ");
    $params = [':participant_id' => $participantId, ':organizer_id' => $organizerId];
    if (!$canBypassSector && $userSector !== 'all') {
        $params[':sector'] = $userSector;
    }
    $stmtPart->execute($params);
    if (!$stmtPart->fetchColumn()) {
        jsonError('Participante não encontrado ou sem permissão.', 404);
    }

    $maxShiftsEvent = max(0, (int)($body['max_shifts_event'] ?? 1));
    $shiftHours = max(0, (float)($body['shift_hours'] ?? 8));
    $mealsPerDay = max(0, (int)($body['meals_per_day'] ?? 4));
    $paymentAmount = max(0, (float)($body['payment_amount'] ?? 0));

    $stmt = $db->prepare("
        INSERT INTO workforce_member_settings (participant_id, max_shifts_event, shift_hours, meals_per_day, payment_amount, created_at, updated_at)
        VALUES (:participant_id, :max_shifts_event, :shift_hours, :meals_per_day, :payment_amount, NOW(), NOW())
        ON CONFLICT (participant_id) DO UPDATE SET
            max_shifts_event = EXCLUDED.max_shifts_event,
            shift_hours = EXCLUDED.shift_hours,
            meals_per_day = EXCLUDED.meals_per_day,
            payment_amount = EXCLUDED.payment_amount,
            updated_at = NOW()
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':max_shifts_event' => $maxShiftsEvent,
        ':shift_hours' => $shiftHours,
        ':meals_per_day' => $mealsPerDay,
        ':payment_amount' => $paymentAmount
    ]);

    jsonSuccess([
        'participant_id' => $participantId,
        'max_shifts_event' => $maxShiftsEvent,
        'shift_hours' => $shiftHours,
        'meals_per_day' => $mealsPerDay,
        'payment_amount' => $paymentAmount
    ], 'Configuração do membro atualizada com sucesso.');
}

function importWorkforce(array $body, ?int $forcedRoleId = null): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $eventId = (int)($body['event_id'] ?? 0);
    $participants = $body['participants'] ?? [];
    $fileName = trim((string)($body['file_name'] ?? ''));
    $sectorFromBody = normalizeSector((string)($body['sector'] ?? ''));
    $sectorFromFile = inferSectorFromFileName($fileName);
    $targetSector = $sectorFromBody ?: $sectorFromFile;
    $roleIdInput = (int)($body['role_id'] ?? 0);
    $targetRoleId = $forcedRoleId ?: ($roleIdInput > 0 ? $roleIdInput : 0);
    $targetRole = null;

    if ($eventId <= 0 || !is_array($participants) || count($participants) === 0) {
        jsonError('Dados inválidos. event_id e participants são obrigatórios.', 400);
    }

    if ($targetRoleId > 0) {
        $targetRole = getRoleById($db, $organizerId, $targetRoleId);
        if (!$targetRole) {
            jsonError('Cargo inválido para importação.', 403);
        }
        $roleSector = normalizeSector((string)($targetRole['sector'] ?? ''));
        if (!$canBypassSector && $userSector !== 'all' && $roleSector && $roleSector !== $userSector) {
            jsonError('Você só pode importar para cargos do seu setor.', 403);
        }
        if ($roleSector) {
            $targetSector = $roleSector;
        }
    }

    if (!$targetSector) {
        if (!$canBypassSector && $userSector !== 'all') {
            $targetSector = $userSector;
        } else {
            jsonError('Não foi possível identificar o setor pelo nome do arquivo. Informe sector explicitamente.', 422);
        }
    }

    if (!$canBypassSector && $userSector !== 'all' && $targetSector !== $userSector) {
        jsonError('Você só pode importar para o seu próprio setor.', 403);
    }

    $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ?");
    $stmtEvent->execute([$eventId, $organizerId]);
    if (!$stmtEvent->fetchColumn()) {
        jsonError('Evento não encontrado para este organizador.', 404);
    }

    $defaultRoleId = $targetRoleId > 0 ? $targetRoleId : ensureSectorDefaultRole($db, $organizerId, $targetSector);
    $defaultCategoryId = resolveDefaultCategoryId($db, $organizerId);
    $managerUserId = $canBypassSector ? null : (int)($user['id'] ?? 0);
    $source = $fileName ?: 'workforce_import.csv';

    $imported = 0;
    $assigned = 0;
    $skipped = 0;
    $errors = [];

    try {
        $db->beginTransaction();

        foreach ($participants as $idx => $row) {
            $name = trim((string)($row['name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $document = trim((string)($row['document'] ?? ''));
            $phone = trim((string)($row['phone'] ?? ''));
            $categoryId = (int)($row['category_id'] ?? 0);

            if ($name === '') {
                $errors[] = 'Linha ' . ($idx + 1) . ': nome é obrigatório.';
                continue;
            }

            if ($categoryId <= 0 || !categoryBelongsToOrganizer($db, $categoryId, $organizerId)) {
                $categoryId = $defaultCategoryId;
            }

            $personId = findPersonId($db, $organizerId, $document, $email);
            if (!$personId) {
                $stmtInsP = $db->prepare("INSERT INTO people (name, email, document, phone, organizer_id, created_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id");
                $stmtInsP->execute([$name, $email ?: null, $document ?: null, $phone ?: null, $organizerId]);
                $personId = (int)$stmtInsP->fetchColumn();
            } else {
                $stmtUpdP = $db->prepare("UPDATE people SET name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                $stmtUpdP->execute([$name, $email ?: null, $phone ?: null, $personId]);
            }

            $participantId = findEventParticipantId($db, $eventId, $personId);
            if (!$participantId) {
                $qrToken = 'PT_' . bin2hex(random_bytes(16));
                $stmtEp = $db->prepare("INSERT INTO event_participants (event_id, person_id, category_id, qr_token, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
                $stmtEp->execute([$eventId, $personId, $categoryId, $qrToken]);
                $participantId = (int)$stmtEp->fetchColumn();
                $imported++;
            } else {
                $skipped++;
                ensureParticipantQrToken($db, (int)$participantId);
            }

            $stmtExistingAssignment = $db->prepare("
                SELECT id FROM workforce_assignments
                WHERE participant_id = ? AND role_id = ?
                LIMIT 1
            ");
            $stmtExistingAssignment->execute([$participantId, $defaultRoleId]);
            $existingAssignmentId = $stmtExistingAssignment->fetchColumn();

            if (!$existingAssignmentId) {
                if (columnExists($db, 'workforce_assignments', 'manager_user_id') && columnExists($db, 'workforce_assignments', 'source_file_name')) {
                    $stmtAssign = $db->prepare("
                        INSERT INTO workforce_assignments (participant_id, role_id, sector, event_shift_id, manager_user_id, source_file_name, created_at)
                        VALUES (?, ?, ?, NULL, ?, ?, NOW())
                    ");
                    $stmtAssign->execute([$participantId, $defaultRoleId, $targetSector, $managerUserId ?: null, $source]);
                } else {
                    $stmtAssign = $db->prepare("
                        INSERT INTO workforce_assignments (participant_id, role_id, sector, event_shift_id, created_at)
                        VALUES (?, ?, ?, NULL, NOW())
                    ");
                    $stmtAssign->execute([$participantId, $defaultRoleId, $targetSector]);
                }
                $assigned++;
            }
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao importar equipe: ' . $e->getMessage(), 500);
    }

    jsonSuccess([
        'sector' => $targetSector,
        'imported' => $imported,
        'assigned' => $assigned,
        'skipped' => $skipped,
        'errors' => $errors
    ], "Importação concluída para o setor '{$targetSector}'.");
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function canBypassSectorAcl(array $user): bool
{
    $role = strtolower((string)($user['role'] ?? ''));
    return $role === 'admin' || $role === 'organizer';
}

function resolveUserSector(PDO $db, array $user): string
{
    $sectorFromToken = normalizeSector((string)($user['sector'] ?? ''));
    if ($sectorFromToken) {
        return $sectorFromToken;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return 'all';
    }

    $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(sector), ''), 'all') FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $sector = $stmt->fetchColumn();

    return normalizeSector((string)$sector) ?: 'all';
}

function normalizeSector(string $value): string
{
    $v = strtolower(trim($value));
    return preg_replace('/\s+/', '_', $v);
}

function inferSectorFromFileName(string $fileName): ?string
{
    $name = normalizeSector(pathinfo($fileName, PATHINFO_FILENAME));
    if ($name === '') {
        return null;
    }

    $map = [
        'bar' => ['bar', 'bebidas', 'drink'],
        'food' => ['food', 'cozinha', 'kitchen', 'alimento'],
        'shop' => ['shop', 'loja', 'merch', 'store'],
        'parking' => ['parking', 'estacionamento'],
        'acessos' => ['acesso', 'acessos', 'entrada', 'portaria'],
    ];

    foreach ($map as $sector => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                return $sector;
            }
        }
    }

    return null;
}

function ensureSectorDefaultRole(PDO $db, int $organizerId, string $sector): int
{
    $defaultRoleName = 'Equipe ' . strtoupper($sector);
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("SELECT id FROM workforce_roles WHERE organizer_id = ? AND LOWER(name) = LOWER(?) AND LOWER(COALESCE(sector, '')) = ? LIMIT 1");
        $stmt->execute([$organizerId, $defaultRoleName, normalizeSector($sector)]);
    } else {
        $stmt = $db->prepare("SELECT id FROM workforce_roles WHERE organizer_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$organizerId, $defaultRoleName]);
    }
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmtIns = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, sector, created_at) VALUES (?, ?, ?, NOW()) RETURNING id");
        $stmtIns->execute([$organizerId, $defaultRoleName, normalizeSector($sector)]);
    } else {
        $stmtIns = $db->prepare("INSERT INTO workforce_roles (organizer_id, name, created_at) VALUES (?, ?, NOW()) RETURNING id");
        $stmtIns->execute([$organizerId, $defaultRoleName]);
    }
    return (int)$stmtIns->fetchColumn();
}

function getRoleById(PDO $db, int $organizerId, int $roleId): ?array
{
    if (columnExists($db, 'workforce_roles', 'sector')) {
        $stmt = $db->prepare("SELECT id, name, sector FROM workforce_roles WHERE id = ? AND organizer_id = ? LIMIT 1");
    } else {
        $stmt = $db->prepare("SELECT id, name, NULL::varchar AS sector FROM workforce_roles WHERE id = ? AND organizer_id = ? LIMIT 1");
    }
    $stmt->execute([$roleId, $organizerId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    return $role ?: null;
}

function inferSectorFromRoleName(string $roleName): string
{
    $name = normalizeSector($roleName);
    if ($name === '') {
        return '';
    }

    // Remove prefixos comuns para capturar o setor principal.
    $prefixes = [
        'gerente_de_',
        'diretor_de_',
        'coordenador_de_',
        'supervisor_de_',
        'lider_de_',
        'chefe_de_',
        'equipe_de_',
        'time_de_'
    ];
    foreach ($prefixes as $p) {
        if (str_starts_with($name, $p)) {
            $name = substr($name, strlen($p));
            break;
        }
    }

    // Remove sufixos comuns sem valor setorial.
    $suffixes = ['_senior', '_junior', '_pleno', '_noturno', '_diurno'];
    foreach ($suffixes as $s) {
        if (str_ends_with($name, $s)) {
            $name = substr($name, 0, -strlen($s));
        }
    }

    return trim($name, '_');
}

function resolveDefaultCategoryId(PDO $db, int $organizerId): int
{
    $stmtStaff = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? AND type = 'staff' LIMIT 1");
    $stmtStaff->execute([$organizerId]);
    $staffId = $stmtStaff->fetchColumn();
    if ($staffId) {
        return (int)$staffId;
    }

    $stmtAny = $db->prepare("SELECT id FROM participant_categories WHERE organizer_id = ? ORDER BY id ASC LIMIT 1");
    $stmtAny->execute([$organizerId]);
    $anyId = $stmtAny->fetchColumn();
    if (!$anyId) {
        jsonError('Nenhuma categoria de participantes cadastrada para este organizador.', 422);
    }
    return (int)$anyId;
}

function categoryBelongsToOrganizer(PDO $db, int $categoryId, int $organizerId): bool
{
    $stmt = $db->prepare("SELECT id FROM participant_categories WHERE id = ? AND organizer_id = ? LIMIT 1");
    $stmt->execute([$categoryId, $organizerId]);
    return (bool)$stmt->fetchColumn();
}

function findPersonId(PDO $db, int $organizerId, string $document, string $email): ?int
{
    if ($document !== '') {
        $stmt = $db->prepare("SELECT id FROM people WHERE document = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$document, $organizerId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    if ($email !== '') {
        $stmt = $db->prepare("SELECT id FROM people WHERE email = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$email, $organizerId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
    }

    return null;
}

function findEventParticipantId(PDO $db, int $eventId, int $personId): ?int
{
    $stmt = $db->prepare("SELECT id FROM event_participants WHERE event_id = ? AND person_id = ? LIMIT 1");
    $stmt->execute([$eventId, $personId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function columnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':table' => $table, ':column' => $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function ensureParticipantQrToken(PDO $db, int $participantId): void
{
    $stmt = $db->prepare("
        UPDATE event_participants
        SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || id::text)
        WHERE id = ?
          AND (qr_token IS NULL OR TRIM(qr_token) = '')
    ");
    $stmt->execute([$participantId]);
}

function backfillMissingQrTokensForEvent(PDO $db, int $eventId, int $organizerId): void
{
    $stmt = $db->prepare("
        UPDATE event_participants ep
        SET qr_token = 'PT_' || md5(random()::text || clock_timestamp()::text || ep.id::text)
        FROM people p
        WHERE ep.person_id = p.id
          AND ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
          AND (ep.qr_token IS NULL OR TRIM(ep.qr_token) = '')
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId
    ]);
}
