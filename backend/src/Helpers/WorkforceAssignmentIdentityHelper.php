<?php

require_once __DIR__ . '/WorkforceControllerSupport.php';
require_once __DIR__ . '/WorkforceEventRoleHelper.php';

function workforceNormalizeAssignmentIdentitySector(?string $sector): string
{
    return normalizeSector((string)($sector ?? ''));
}

function workforceFindExistingAssignment(
    PDO $db,
    int $participantId,
    int $roleId,
    string $sector,
    ?int $eventShiftId,
    array $selectColumns = ['id'],
    string $assignmentPublicId = ''
): ?array {
    $columns = array_values(array_unique(array_merge(['id'], $selectColumns)));
    $select = implode(', ', $columns);

    if ($assignmentPublicId !== '' && workforceAssignmentsHavePublicId($db)) {
        $stmtByPublicId = $db->prepare("
            SELECT {$select}
            FROM workforce_assignments
            WHERE participant_id = :participant_id
              AND public_id = :public_id
            LIMIT 1
        ");
        $stmtByPublicId->execute([
            ':participant_id' => $participantId,
            ':public_id' => $assignmentPublicId,
        ]);
        $existingByPublicId = $stmtByPublicId->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingByPublicId) {
            return $existingByPublicId;
        }
    }

    $normalizedSector = workforceNormalizeAssignmentIdentitySector($sector);
    $stmt = $db->prepare("
        SELECT {$select}
        FROM workforce_assignments
        WHERE participant_id = :participant_id
          AND role_id = :role_id
          AND REGEXP_REPLACE(LOWER(COALESCE(NULLIF(BTRIM(sector), ''), '')), '\s+', '_', 'g') = :sector
          AND (
              (:event_shift_id_is_null = 1 AND event_shift_id IS NULL)
              OR event_shift_id = :event_shift_id
          )
        LIMIT 1
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':role_id' => $roleId,
        ':sector' => $normalizedSector,
        ':event_shift_id_is_null' => $eventShiftId === null ? 1 : 0,
        ':event_shift_id' => $eventShiftId,
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function workforceResolveAssignmentSupportFlags(PDO $db): array
{
    return [
        'supports_manager_binding' => columnExists($db, 'workforce_assignments', 'manager_user_id')
            && columnExists($db, 'workforce_assignments', 'source_file_name'),
        'supports_event_bindings' => workforceAssignmentsHaveEventRoleColumns($db),
        'supports_public_id' => workforceAssignmentsHavePublicId($db),
    ];
}

function workforceBuildAssignmentMutationSelectColumns(array $supportFlags): array
{
    $columns = ['id', 'role_id', 'sector', 'event_shift_id'];

    if (!empty($supportFlags['supports_manager_binding'])) {
        $columns[] = 'manager_user_id';
        $columns[] = 'source_file_name';
    }
    if (!empty($supportFlags['supports_event_bindings'])) {
        $columns[] = 'event_role_id';
        $columns[] = 'root_manager_event_role_id';
    }
    if (!empty($supportFlags['supports_public_id'])) {
        $columns[] = 'public_id';
    }

    return $columns;
}

function workforceNormalizeOptionalInt(mixed $value): ?int
{
    $normalized = (int)($value ?? 0);
    return $normalized > 0 ? $normalized : null;
}

function workforceUpsertAssignment(PDO $db, array $payload): array
{
    $participantId = (int)($payload['participant_id'] ?? 0);
    $roleId = (int)($payload['role_id'] ?? 0);
    $sector = workforceNormalizeAssignmentIdentitySector((string)($payload['sector'] ?? ''));
    $eventShiftId = workforceNormalizeOptionalInt($payload['event_shift_id'] ?? null);
    $managerUserId = workforceNormalizeOptionalInt($payload['manager_user_id'] ?? null);
    $sourceFileName = trim((string)($payload['source_file_name'] ?? ''));
    $eventRoleId = workforceNormalizeOptionalInt($payload['event_role_id'] ?? null);
    $rootManagerEventRoleId = workforceNormalizeOptionalInt($payload['root_manager_event_role_id'] ?? null);
    $assignmentPublicId = trim((string)($payload['public_id'] ?? ''));
    $supportFlags = $payload['support_flags'] ?? workforceResolveAssignmentSupportFlags($db);

    if ($participantId <= 0 || $roleId <= 0) {
        throw new InvalidArgumentException('participant_id e role_id são obrigatórios para persistir assignment.');
    }

    $existingAssignment = $payload['existing_assignment'] ?? null;
    if (!$existingAssignment) {
        $existingAssignment = workforceFindExistingAssignment(
            $db,
            $participantId,
            $roleId,
            $sector,
            $eventShiftId,
            workforceBuildAssignmentMutationSelectColumns($supportFlags),
            $assignmentPublicId
        );
    }

    if (!empty($supportFlags['supports_public_id']) && $assignmentPublicId === '' && $existingAssignment) {
        $assignmentPublicId = trim((string)($existingAssignment['public_id'] ?? ''));
    }

    if ($existingAssignment) {
        $setClauses = [];
        $params = [':id' => (int)($existingAssignment['id'] ?? 0)];

        if ((int)($existingAssignment['role_id'] ?? 0) !== $roleId) {
            $setClauses[] = 'role_id = :role_id';
            $params[':role_id'] = $roleId;
        }

        if (workforceNormalizeAssignmentIdentitySector((string)($existingAssignment['sector'] ?? '')) !== $sector) {
            $setClauses[] = 'sector = :sector';
            $params[':sector'] = $sector !== '' ? $sector : null;
        }

        $existingEventShiftId = workforceNormalizeOptionalInt($existingAssignment['event_shift_id'] ?? null);
        if ($existingEventShiftId !== $eventShiftId) {
            $setClauses[] = 'event_shift_id = :event_shift_id';
            $params[':event_shift_id'] = $eventShiftId;
        }

        if (!empty($supportFlags['supports_manager_binding'])) {
            $existingManagerUserId = workforceNormalizeOptionalInt($existingAssignment['manager_user_id'] ?? null);
            $existingSourceFileName = (string)($existingAssignment['source_file_name'] ?? '');
            if ($existingManagerUserId !== $managerUserId) {
                $setClauses[] = 'manager_user_id = :manager_user_id';
                $params[':manager_user_id'] = $managerUserId;
            }
            if ($existingSourceFileName !== $sourceFileName) {
                $setClauses[] = 'source_file_name = :source_file_name';
                $params[':source_file_name'] = $sourceFileName;
            }
        }

        if (!empty($supportFlags['supports_event_bindings'])) {
            $existingEventRoleId = workforceNormalizeOptionalInt($existingAssignment['event_role_id'] ?? null);
            $existingRootManagerEventRoleId = workforceNormalizeOptionalInt($existingAssignment['root_manager_event_role_id'] ?? null);
            if ($existingEventRoleId !== $eventRoleId) {
                $setClauses[] = 'event_role_id = :event_role_id';
                $params[':event_role_id'] = $eventRoleId;
            }
            if ($existingRootManagerEventRoleId !== $rootManagerEventRoleId) {
                $setClauses[] = 'root_manager_event_role_id = :root_manager_event_role_id';
                $params[':root_manager_event_role_id'] = $rootManagerEventRoleId;
            }
        }

        if (!empty($supportFlags['supports_public_id']) && $assignmentPublicId !== '') {
            $existingPublicId = trim((string)($existingAssignment['public_id'] ?? ''));
            if ($existingPublicId !== $assignmentPublicId) {
                $setClauses[] = 'public_id = :public_id';
                $params[':public_id'] = $assignmentPublicId;
            }
        }

        if (empty($setClauses)) {
            return [
                'id' => (int)($existingAssignment['id'] ?? 0),
                'public_id' => $assignmentPublicId,
                'mode' => 'unchanged',
            ];
        }

        $stmt = $db->prepare("
            UPDATE workforce_assignments
            SET " . implode(",\n                ", $setClauses) . "
            WHERE id = :id
            RETURNING id" . (!empty($supportFlags['supports_public_id']) ? ', public_id' : '') . "
        ");
        $stmt->execute($params);
        $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'id' => (int)($saved['id'] ?? ($existingAssignment['id'] ?? 0)),
            'public_id' => (string)($saved['public_id'] ?? $assignmentPublicId),
            'mode' => 'updated',
        ];
    }

    $columns = ['participant_id', 'role_id', 'sector', 'event_shift_id', 'created_at'];
    $values = [':participant_id', ':role_id', ':sector', ':event_shift_id', 'NOW()'];
    $params = [
        ':participant_id' => $participantId,
        ':role_id' => $roleId,
        ':sector' => $sector !== '' ? $sector : null,
        ':event_shift_id' => $eventShiftId,
    ];

    if (!empty($supportFlags['supports_manager_binding'])) {
        $columns[] = 'manager_user_id';
        $columns[] = 'source_file_name';
        $values[] = ':manager_user_id';
        $values[] = ':source_file_name';
        $params[':manager_user_id'] = $managerUserId;
        $params[':source_file_name'] = $sourceFileName;
    }

    if (!empty($supportFlags['supports_event_bindings'])) {
        $columns[] = 'event_role_id';
        $columns[] = 'root_manager_event_role_id';
        $values[] = ':event_role_id';
        $values[] = ':root_manager_event_role_id';
        $params[':event_role_id'] = $eventRoleId;
        $params[':root_manager_event_role_id'] = $rootManagerEventRoleId;
    }

    if (!empty($supportFlags['supports_public_id']) && $assignmentPublicId !== '') {
        $columns[] = 'public_id';
        $values[] = ':public_id';
        $params[':public_id'] = $assignmentPublicId;
    }

    $stmt = $db->prepare("
        INSERT INTO workforce_assignments (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $values) . ")
        RETURNING id" . (!empty($supportFlags['supports_public_id']) ? ', public_id' : '') . "
    ");
    $stmt->execute($params);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'id' => (int)($saved['id'] ?? 0),
        'public_id' => (string)($saved['public_id'] ?? $assignmentPublicId),
        'mode' => 'created',
    ];
}

function workforceEnsureImportedParticipant(
    PDO $db,
    int $organizerId,
    int $eventId,
    int $defaultCategoryId,
    array $validCategoryIds,
    array $row
): array {
    $name = trim((string)($row['name'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $document = trim((string)($row['document'] ?? ''));
    $phone = trim((string)($row['phone'] ?? ''));
    $categoryId = (int)($row['category_id'] ?? 0);

    if ($name === '') {
        throw new InvalidArgumentException('nome é obrigatório para importar participante.');
    }

    if ($categoryId <= 0 || !isset($validCategoryIds[$categoryId])) {
        $categoryId = $defaultCategoryId;
    }

    $personId = findPersonId($db, $organizerId, $document, $email);
    if (!$personId) {
        $stmtInsertPerson = $db->prepare("
            INSERT INTO people (name, email, document, phone, organizer_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsertPerson->execute([$name, $email !== '' ? $email : null, $document !== '' ? $document : null, $phone !== '' ? $phone : null, $organizerId]);
        $personId = (int)$stmtInsertPerson->fetchColumn();
    } else {
        $stmtUpdatePerson = $db->prepare("
            UPDATE people
            SET name = ?, email = ?, phone = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpdatePerson->execute([$name, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $personId]);
    }

    $participantId = findEventParticipantId($db, $eventId, $personId);
    $participantCreated = false;
    if (!$participantId) {
        $qrToken = 'PT_' . bin2hex(random_bytes(16));
        $stmtInsertParticipant = $db->prepare("
            INSERT INTO event_participants (event_id, person_id, category_id, qr_token, organizer_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsertParticipant->execute([$eventId, $personId, $categoryId, $qrToken, $organizerId]);
        $participantId = (int)$stmtInsertParticipant->fetchColumn();
        $participantCreated = true;
    } else {
        ensureParticipantQrToken($db, (int)$participantId);
    }

    return [
        'participant_id' => (int)$participantId,
        'person_id' => (int)$personId,
        'imported' => $participantCreated,
    ];
}

function fetchLeaderParticipantBindingContext(PDO $db, int $organizerId, int $eventId, int $participantId): ?array
{
    if ($eventId <= 0 || $participantId <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            ep.id AS participant_id,
            p.id AS person_id,
            p.name,
            p.email,
            p.document,
            p.phone,
            ep.qr_token
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        WHERE ep.id = :participant_id
          AND ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
        LIMIT 1
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['participant_id'] = (int)($row['participant_id'] ?? 0);
    $row['person_id'] = (int)($row['person_id'] ?? 0);
    $row['name'] = (string)($row['name'] ?? '');
    $row['email'] = (string)($row['email'] ?? '');
    $row['document'] = (string)($row['document'] ?? '');
    $row['phone'] = (string)($row['phone'] ?? '');
    $row['qr_token'] = (string)($row['qr_token'] ?? '');
    return $row;
}

function fetchLeaderUserBindingContext(PDO $db, int $organizerId, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            COALESCE(u.cpf, '') AS cpf,
            u.role,
            u.sector,
            u.is_active
        FROM users u
        WHERE u.id = :user_id
          AND (u.organizer_id = :organizer_id OR u.id = :organizer_id)
        LIMIT 1
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':organizer_id' => $organizerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['name'] = (string)($row['name'] ?? '');
    $row['email'] = (string)($row['email'] ?? '');
    $row['phone'] = (string)($row['phone'] ?? '');
    $row['cpf'] = (string)($row['cpf'] ?? '');
    $row['role'] = (string)($row['role'] ?? '');
    $row['sector'] = (string)($row['sector'] ?? '');
    $row['is_active'] = workforceNormalizePgBool($row['is_active'] ?? true);
    return $row;
}

function findLeaderUserBindingByIdentity(PDO $db, int $organizerId, string $email = '', string $document = ''): ?array
{
    $normalizedEmail = strtolower(trim($email));
    $normalizedDocument = preg_replace('/\D+/', '', (string)$document);
    if ($normalizedEmail === '' && $normalizedDocument === '') {
        return null;
    }

    $conditions = [];
    $params = [':organizer_id' => $organizerId];
    if ($normalizedEmail !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(u.email, ''))) = :email";
        $params[':email'] = $normalizedEmail;
    }
    if ($normalizedDocument !== '') {
        $conditions[] = "REGEXP_REPLACE(COALESCE(u.cpf, ''), '\D', '', 'g') = :document";
        $params[':document'] = $normalizedDocument;
    }

    $stmt = $db->prepare("
        SELECT u.id
        FROM users u
        WHERE (u.organizer_id = :organizer_id OR u.id = :organizer_id)
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY u.id ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? fetchLeaderUserBindingContext($db, $organizerId, (int)$row['id']) : null;
}

function findLeaderParticipantBindingByIdentity(PDO $db, int $organizerId, int $eventId, string $email = '', string $document = ''): ?array
{
    $normalizedEmail = strtolower(trim($email));
    $normalizedDocument = preg_replace('/\D+/', '', (string)$document);
    if ($eventId <= 0 || ($normalizedEmail === '' && $normalizedDocument === '')) {
        return null;
    }

    $conditions = [];
    $params = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];
    if ($normalizedEmail !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(p.email, ''))) = :email";
        $params[':email'] = $normalizedEmail;
    }
    if ($normalizedDocument !== '') {
        $conditions[] = "REGEXP_REPLACE(COALESCE(p.document, ''), '\D', '', 'g') = :document";
        $params[':document'] = $normalizedDocument;
    }

    $stmt = $db->prepare("
        SELECT ep.id AS participant_id
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        WHERE ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY ep.id ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, (int)$row['participant_id']) : null;
}

function findPersonIdByIdentity(PDO $db, int $organizerId, string $email = '', string $document = ''): ?int
{
    $normalizedEmail = strtolower(trim($email));
    $normalizedDocument = preg_replace('/\D+/', '', (string)$document);
    if ($normalizedEmail === '' && $normalizedDocument === '') {
        return null;
    }

    $conditions = [];
    $params = [':organizer_id' => $organizerId];
    if ($normalizedEmail !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(p.email, ''))) = :email";
        $params[':email'] = $normalizedEmail;
    }
    if ($normalizedDocument !== '') {
        $conditions[] = "REGEXP_REPLACE(COALESCE(p.document, ''), '\D', '', 'g') = :document";
        $params[':document'] = $normalizedDocument;
    }

    $stmt = $db->prepare("
        SELECT p.id
        FROM people p
        WHERE p.organizer_id = :organizer_id
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY p.id ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function ensureLeadershipParticipantFromIdentity(
    PDO $db,
    int $organizerId,
    int $eventId,
    string $leaderName,
    string $leaderCpf,
    string $leaderPhone = '',
    ?array $leaderUser = null
): ?array {
    $normalizedName = trim($leaderName);
    $normalizedDocument = preg_replace('/\D+/', '', (string)$leaderCpf);
    if ($eventId <= 0 || $normalizedName === '' || $normalizedDocument === '') {
        return null;
    }

    $email = trim((string)($leaderUser['email'] ?? ''));
    $personId = findPersonIdByIdentity($db, $organizerId, $email, $leaderCpf);
    if (!$personId) {
        $stmtInsertPerson = $db->prepare("
            INSERT INTO people (name, email, document, phone, organizer_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsertPerson->execute([
            $normalizedName,
            $email !== '' ? $email : null,
            $leaderCpf,
            $leaderPhone !== '' ? $leaderPhone : null,
            $organizerId,
        ]);
        $personId = (int)$stmtInsertPerson->fetchColumn();
    } else {
        $stmtUpdatePerson = $db->prepare("
            UPDATE people
            SET name = COALESCE(NULLIF(?, ''), name),
                email = COALESCE(NULLIF(?, ''), email),
                phone = COALESCE(NULLIF(?, ''), phone),
                document = COALESCE(NULLIF(?, ''), document),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpdatePerson->execute([
            $normalizedName,
            $email,
            $leaderPhone,
            $leaderCpf,
            $personId,
        ]);
    }

    $participantId = findEventParticipantId($db, $eventId, $personId);
    if (!$participantId) {
        $categoryId = resolveDefaultCategoryId($db, $organizerId);
        $qrToken = 'PT_' . bin2hex(random_bytes(16));
        $stmtInsertParticipant = $db->prepare("
            INSERT INTO event_participants (event_id, person_id, category_id, qr_token, organizer_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsertParticipant->execute([$eventId, $personId, $categoryId, $qrToken, $organizerId]);
        $participantId = (int)$stmtInsertParticipant->fetchColumn();
    } else {
        ensureParticipantQrToken($db, $participantId);
    }

    return fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, $participantId);
}
