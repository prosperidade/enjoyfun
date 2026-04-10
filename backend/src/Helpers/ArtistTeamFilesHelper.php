<?php

function listArtistTeamMembers(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $eventArtistId = artistNormalizeOptionalInt($query['event_artist_id'] ?? null);
    $activeFilterRaw = $query['is_active'] ?? null;
    $filterByActive = $activeFilterRaw !== null && $activeFilterRaw !== '';

    $countSql = "
        SELECT COUNT(*)
        FROM public.artist_team_members tm
        JOIN public.event_artists ea
          ON ea.id = tm.event_artist_id
         AND ea.organizer_id = tm.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE tm.organizer_id = :organizer_id
          AND tm.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
        SELECT
            tm.id,
            tm.organizer_id,
            tm.event_id,
            e.name AS event_name,
            tm.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            tm.full_name,
            tm.role_name,
            tm.document_number,
            tm.phone,
            tm.needs_hotel,
            tm.needs_transfer,
            tm.notes,
            tm.is_active,
            tm.created_at,
            tm.updated_at
        FROM public.artist_team_members tm
        JOIN public.events e
          ON e.id = tm.event_id
        JOIN public.event_artists ea
          ON ea.id = tm.event_artist_id
         AND ea.organizer_id = tm.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE tm.organizer_id = :organizer_id
          AND tm.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(tm.full_name) LIKE LOWER(:search) OR LOWER(COALESCE(tm.role_name, '')) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(tm.full_name) LIKE LOWER(:search) OR LOWER(COALESCE(tm.role_name, '')) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($eventArtistId !== null) {
        $countSql .= " AND tm.event_artist_id = :event_artist_id";
        $dataSql .= " AND tm.event_artist_id = :event_artist_id";
        $countParams[':event_artist_id'] = $eventArtistId;
        $dataParams[':event_artist_id'] = $eventArtistId;
    }

    if ($filterByActive) {
        $isActive = artistNormalizeBoolean($activeFilterRaw, true);
        $countSql .= " AND tm.is_active = :is_active";
        $dataSql .= " AND tm.is_active = :is_active";
        $countParams[':is_active'] = $isActive;
        $dataParams[':is_active'] = $isActive;
    }

    $dataSql .= "
        ORDER BY tm.is_active DESC, tm.full_name ASC, tm.id ASC
        LIMIT :limit OFFSET :offset
    ";

    $total = artistFetchCount($db, $countSql, $countParams);
    $stmt = $db->prepare($dataSql);
    artistBindStatementValues($stmt, $dataParams);
    $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateTeamMemberRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Equipe do artista carregada com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtistTeamMember(array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $body['event_id'] ?? null, true);
    $eventArtistId = artistNormalizeOptionalInt($body['event_artist_id'] ?? null);
    if ($eventArtistId === null) {
        jsonError('event_artist_id e obrigatorio.', 422);
    }

    $booking = artistRequireEventArtistMutationContext($db, $organizerId, $eventArtistId, $eventId);
    artistSetCurrentEventId((int)$booking['event_id']);

    $fullName = artistNormalizeOptionalText($body['full_name'] ?? null, 180);
    if ($fullName === null) {
        jsonError('full_name e obrigatorio.', 422);
    }

    $stmt = $db->prepare("
        INSERT INTO public.artist_team_members (
            organizer_id,
            event_id,
            event_artist_id,
            full_name,
            role_name,
            document_number,
            phone,
            needs_hotel,
            needs_transfer,
            notes,
            is_active,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :event_artist_id,
            :full_name,
            :role_name,
            :document_number,
            :phone,
            :needs_hotel,
            :needs_transfer,
            :notes,
            :is_active,
            NOW(),
            NOW()
        )
        RETURNING id
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':event_artist_id' => $eventArtistId,
        ':full_name' => $fullName,
        ':role_name' => artistNormalizeOptionalText($body['role_name'] ?? null, 120),
        ':document_number' => artistNormalizeOptionalText($body['document_number'] ?? null, 40),
        ':phone' => artistNormalizeOptionalText($body['phone'] ?? null, 40),
        ':needs_hotel' => artistNormalizeBoolean($body['needs_hotel'] ?? null, false) ? 'true' : 'false',
        ':needs_transfer' => artistNormalizeBoolean($body['needs_transfer'] ?? null, false) ? 'true' : 'false',
        ':notes' => artistNormalizeOptionalText($body['notes'] ?? null),
        ':is_active' => artistNormalizeBoolean($body['is_active'] ?? null, true) ? 'true' : 'false',
    ]);

    $memberId = (int)$stmt->fetchColumn();
    jsonSuccess(artistRequireTeamMemberById($db, $organizerId, $memberId), 'Membro da equipe criado com sucesso.', 201);
}

function getArtistTeamMember(int $memberId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $member = artistRequireTeamMemberById($db, $organizerId, $memberId);
    artistSetCurrentEventId((int)$member['event_id']);
    jsonSuccess($member, 'Membro da equipe carregado com sucesso.');
}

function updateArtistTeamMember(int $memberId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireTeamMemberById($db, $organizerId, $memberId);
    artistSetCurrentEventId((int)$current['event_id']);

    if (array_key_exists('event_id', $body) && (int)$body['event_id'] !== (int)$current['event_id']) {
        jsonError('event_id nao pode ser alterado em membro de equipe existente.', 422);
    }
    if (array_key_exists('event_artist_id', $body) && (int)$body['event_artist_id'] !== (int)$current['event_artist_id']) {
        jsonError('event_artist_id nao pode ser alterado em membro de equipe existente.', 422);
    }

    $fullName = array_key_exists('full_name', $body)
        ? artistNormalizeOptionalText($body['full_name'], 180)
        : $current['full_name'];
    if ($fullName === null) {
        jsonError('full_name e obrigatorio.', 422);
    }

    $stmt = $db->prepare("
        UPDATE public.artist_team_members
        SET
            full_name = :full_name,
            role_name = :role_name,
            document_number = :document_number,
            phone = :phone,
            needs_hotel = :needs_hotel,
            needs_transfer = :needs_transfer,
            notes = :notes,
            is_active = :is_active,
            updated_at = NOW()
        WHERE id = :member_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':full_name' => $fullName,
        ':role_name' => array_key_exists('role_name', $body)
            ? artistNormalizeOptionalText($body['role_name'], 120)
            : $current['role_name'],
        ':document_number' => array_key_exists('document_number', $body)
            ? artistNormalizeOptionalText($body['document_number'], 40)
            : $current['document_number'],
        ':phone' => array_key_exists('phone', $body)
            ? artistNormalizeOptionalText($body['phone'], 40)
            : $current['phone'],
        ':needs_hotel' => (array_key_exists('needs_hotel', $body)
            ? artistNormalizeBoolean($body['needs_hotel'], false)
            : artistNormalizeBoolean($current['needs_hotel'], false)) ? 'true' : 'false',
        ':needs_transfer' => (array_key_exists('needs_transfer', $body)
            ? artistNormalizeBoolean($body['needs_transfer'], false)
            : artistNormalizeBoolean($current['needs_transfer'], false)) ? 'true' : 'false',
        ':notes' => array_key_exists('notes', $body)
            ? artistNormalizeOptionalText($body['notes'])
            : $current['notes'],
        ':is_active' => (array_key_exists('is_active', $body)
            ? artistNormalizeBoolean($body['is_active'], true)
            : artistNormalizeBoolean($current['is_active'], true)) ? 'true' : 'false',
        ':member_id' => $memberId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireTeamMemberById($db, $organizerId, $memberId), 'Membro da equipe atualizado com sucesso.');
}

function deleteArtistTeamMember(int $memberId): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireTeamMemberById($db, $organizerId, $memberId);
    artistSetCurrentEventId((int)$current['event_id']);

    $stmt = $db->prepare("
        DELETE FROM public.artist_team_members
        WHERE id = :member_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(['id' => $memberId], 'Membro da equipe removido com sucesso.');
}

function listArtistFiles(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $eventArtistId = artistNormalizeOptionalInt($query['event_artist_id'] ?? null);
    $fileType = artistNormalizeOptionalText($query['file_type'] ?? null, 50);

    $countSql = "
        SELECT COUNT(*)
        FROM public.artist_files f
        JOIN public.event_artists ea
          ON ea.id = f.event_artist_id
         AND ea.organizer_id = f.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE f.organizer_id = :organizer_id
          AND f.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
        SELECT
            f.id,
            f.organizer_id,
            f.event_id,
            e.name AS event_name,
            f.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            f.file_type,
            f.original_name,
            f.storage_path,
            f.mime_type,
            f.file_size_bytes,
            f.notes,
            f.created_at,
            f.updated_at
        FROM public.artist_files f
        JOIN public.events e
          ON e.id = f.event_id
        JOIN public.event_artists ea
          ON ea.id = f.event_artist_id
         AND ea.organizer_id = f.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE f.organizer_id = :organizer_id
          AND f.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(f.original_name) LIKE LOWER(:search) OR LOWER(COALESCE(f.storage_path, '')) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(f.original_name) LIKE LOWER(:search) OR LOWER(COALESCE(f.storage_path, '')) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($eventArtistId !== null) {
        $countSql .= " AND f.event_artist_id = :event_artist_id";
        $dataSql .= " AND f.event_artist_id = :event_artist_id";
        $countParams[':event_artist_id'] = $eventArtistId;
        $dataParams[':event_artist_id'] = $eventArtistId;
    }

    if ($fileType !== null) {
        $countSql .= " AND f.file_type = :file_type";
        $dataSql .= " AND f.file_type = :file_type";
        $countParams[':file_type'] = $fileType;
        $dataParams[':file_type'] = $fileType;
    }

    $dataSql .= "
        ORDER BY f.created_at DESC, f.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $total = artistFetchCount($db, $countSql, $countParams);
    $stmt = $db->prepare($dataSql);
    artistBindStatementValues($stmt, $dataParams);
    $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateFileRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Arquivos do artista carregados com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtistFile(array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $body['event_id'] ?? null, true);
    $eventArtistId = artistNormalizeOptionalInt($body['event_artist_id'] ?? null);
    if ($eventArtistId === null) {
        jsonError('event_artist_id e obrigatorio.', 422);
    }

    $booking = artistRequireEventArtistMutationContext($db, $organizerId, $eventArtistId, $eventId);
    artistSetCurrentEventId((int)$booking['event_id']);

    $fileType = artistNormalizeOptionalText($body['file_type'] ?? null, 50);
    $originalName = artistNormalizeOptionalText($body['original_name'] ?? null, 255);
    $storagePath = artistNormalizeOptionalText($body['storage_path'] ?? null, 500);
    if ($fileType === null || $originalName === null || $storagePath === null) {
        jsonError('file_type, original_name e storage_path sao obrigatorios.', 422);
    }

    $stmt = $db->prepare("
        INSERT INTO public.artist_files (
            organizer_id,
            event_id,
            event_artist_id,
            file_type,
            original_name,
            storage_path,
            mime_type,
            file_size_bytes,
            notes,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :event_artist_id,
            :file_type,
            :original_name,
            :storage_path,
            :mime_type,
            :file_size_bytes,
            :notes,
            NOW(),
            NOW()
        )
        RETURNING id
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':event_artist_id' => $eventArtistId,
        ':file_type' => $fileType,
        ':original_name' => $originalName,
        ':storage_path' => $storagePath,
        ':mime_type' => artistNormalizeOptionalText($body['mime_type'] ?? null, 120),
        ':file_size_bytes' => artistNormalizeNonNegativeInt($body['file_size_bytes'] ?? null, true, 'file_size_bytes'),
        ':notes' => artistNormalizeOptionalText($body['notes'] ?? null),
    ]);

    $fileId = (int)$stmt->fetchColumn();
    jsonSuccess(artistRequireFileById($db, $organizerId, $fileId), 'Arquivo do artista criado com sucesso.', 201);
}

function getArtistFile(int $fileId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $file = artistRequireFileById($db, $organizerId, $fileId);
    artistSetCurrentEventId((int)$file['event_id']);
    jsonSuccess($file, 'Arquivo do artista carregado com sucesso.');
}

function deleteArtistFile(int $fileId): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireFileById($db, $organizerId, $fileId);
    artistSetCurrentEventId((int)$current['event_id']);

    $stmt = $db->prepare("
        DELETE FROM public.artist_files
        WHERE id = :file_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':file_id' => $fileId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(['id' => $fileId], 'Arquivo do artista removido com sucesso.');
}

