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
            INSERT INTO event_participants (event_id, person_id, category_id, qr_token, created_at)
            VALUES (?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtInsertParticipant->execute([$eventId, $personId, $categoryId, $qrToken]);
        $participantId = (int)$stmtInsertParticipant->fetchColumn();
    } else {
        ensureParticipantQrToken($db, $participantId);
    }

    return fetchLeaderParticipantBindingContext($db, $organizerId, $eventId, $participantId);
}
