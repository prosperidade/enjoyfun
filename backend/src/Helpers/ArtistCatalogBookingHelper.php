<?php

function listArtists(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, false);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $activeFilterRaw = $query['is_active'] ?? null;
    $filterByActive = $activeFilterRaw !== null && $activeFilterRaw !== '';

    if ($eventId !== null) {
        $countSql = "
            SELECT COUNT(*)
            FROM public.event_artists ea
            JOIN public.artists a
              ON a.id = ea.artist_id
             AND a.organizer_id = ea.organizer_id
            WHERE ea.organizer_id = :organizer_id
              AND ea.event_id = :event_id
        ";
        $countParams = [
            ':organizer_id' => $organizerId,
            ':event_id' => $eventId,
        ];

        $dataSql = "
            SELECT
                a.id,
                a.organizer_id,
                a.stage_name,
                a.legal_name,
                a.document_number,
                a.artist_type,
                a.default_contact_name,
                a.default_contact_phone,
                a.notes,
                a.is_active,
                a.created_at,
                a.updated_at,
                ea.id AS event_artist_id,
                ea.event_id,
                ea.booking_status,
                ea.performance_date,
                ea.performance_start_at,
                ea.performance_duration_minutes,
                ea.soundcheck_at,
                ea.stage_name AS booking_stage_name,
                CAST(ea.cache_amount AS FLOAT) AS cache_amount,
                ea.cancelled_at,
                COALESCE(costs.logistics_items_count, 0) AS logistics_items_count,
                CAST(COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_logistics_cost,
                CAST(COALESCE(ea.cache_amount, 0) + COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_artist_cost
            FROM public.event_artists ea
            JOIN public.artists a
              ON a.id = ea.artist_id
             AND a.organizer_id = ea.organizer_id
            LEFT JOIN (
                SELECT
                    event_artist_id,
                    COUNT(*) AS logistics_items_count,
                    COALESCE(SUM(COALESCE(total_amount, CASE WHEN unit_amount IS NOT NULL THEN quantity * unit_amount ELSE 0 END)), 0) AS total_logistics_cost
                FROM public.artist_logistics_items
                GROUP BY event_artist_id
            ) costs
              ON costs.event_artist_id = ea.id
            WHERE ea.organizer_id = :organizer_id
              AND ea.event_id = :event_id
        ";
        $dataParams = $countParams;

        if ($search !== null) {
            $countSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(a.legal_name, '')) LIKE LOWER(:search))";
            $dataSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(a.legal_name, '')) LIKE LOWER(:search))";
            $countParams[':search'] = '%' . $search . '%';
            $dataParams[':search'] = '%' . $search . '%';
        }

        if ($filterByActive) {
            $isActive = artistNormalizeBoolean($activeFilterRaw, true);
            $countSql .= " AND a.is_active = :is_active";
            $dataSql .= " AND a.is_active = :is_active";
            $countParams[':is_active'] = $isActive;
            $dataParams[':is_active'] = $isActive;
        }

        $dataSql .= "
            ORDER BY COALESCE(ea.performance_start_at, ea.created_at) ASC, a.stage_name ASC
            LIMIT :limit OFFSET :offset
        ";
    } else {
        $countSql = "
            SELECT COUNT(*)
            FROM public.artists a
            WHERE a.organizer_id = :organizer_id
        ";
        $countParams = [':organizer_id' => $organizerId];

        $dataSql = "
            SELECT
                a.id,
                a.organizer_id,
                a.stage_name,
                a.legal_name,
                a.document_number,
                a.artist_type,
                a.default_contact_name,
                a.default_contact_phone,
                a.notes,
                a.is_active,
                a.created_at,
                a.updated_at,
                COALESCE(stats.bookings_count, 0) AS bookings_count,
                CAST(COALESCE(stats.total_cache_amount, 0) AS FLOAT) AS total_cache_amount,
                CAST(COALESCE(stats.total_logistics_cost, 0) AS FLOAT) AS total_logistics_cost,
                CAST(COALESCE(stats.total_artist_cost, 0) AS FLOAT) AS total_artist_cost
            FROM public.artists a
            LEFT JOIN (
                SELECT
                    ea.artist_id,
                    COUNT(*) AS bookings_count,
                    COALESCE(SUM(COALESCE(ea.cache_amount, 0)), 0) AS total_cache_amount,
                    COALESCE(SUM(COALESCE(costs.total_logistics_cost, 0)), 0) AS total_logistics_cost,
                    COALESCE(SUM(COALESCE(ea.cache_amount, 0) + COALESCE(costs.total_logistics_cost, 0)), 0) AS total_artist_cost
                FROM public.event_artists ea
                LEFT JOIN (
                    SELECT
                        event_artist_id,
                        COALESCE(SUM(COALESCE(total_amount, CASE WHEN unit_amount IS NOT NULL THEN quantity * unit_amount ELSE 0 END)), 0) AS total_logistics_cost
                    FROM public.artist_logistics_items
                    GROUP BY event_artist_id
                ) costs
                  ON costs.event_artist_id = ea.id
                WHERE ea.organizer_id = :stats_organizer_id
                GROUP BY ea.artist_id
            ) stats
              ON stats.artist_id = a.id
            WHERE a.organizer_id = :organizer_id
        ";
        $dataParams = [
            ':organizer_id' => $organizerId,
            ':stats_organizer_id' => $organizerId,
        ];

        if ($search !== null) {
            $countSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(a.legal_name, '')) LIKE LOWER(:search))";
            $dataSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(a.legal_name, '')) LIKE LOWER(:search))";
            $countParams[':search'] = '%' . $search . '%';
            $dataParams[':search'] = '%' . $search . '%';
        }

        if ($filterByActive) {
            $isActive = artistNormalizeBoolean($activeFilterRaw, true);
            $countSql .= " AND a.is_active = :is_active";
            $dataSql .= " AND a.is_active = :is_active";
            $countParams[':is_active'] = $isActive;
            $dataParams[':is_active'] = $isActive;
        }

        $dataSql .= "
            ORDER BY a.stage_name ASC
            LIMIT :limit OFFSET :offset
        ";
    }

    $total = artistFetchCount($db, $countSql, $countParams);
    $stmt = $db->prepare($dataSql);
    artistBindStatementValues($stmt, $dataParams);
    $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateArtistRow($row, $eventId !== null);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Artistas carregados com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtist(array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $stageName = artistNormalizeOptionalText($body['stage_name'] ?? null, 200);
    if ($stageName === null) {
        jsonError('stage_name e obrigatorio.', 422);
    }

    $stmt = $db->prepare("
        INSERT INTO public.artists (
            organizer_id,
            stage_name,
            legal_name,
            document_number,
            artist_type,
            default_contact_name,
            default_contact_phone,
            notes,
            is_active,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :stage_name,
            :legal_name,
            :document_number,
            :artist_type,
            :default_contact_name,
            :default_contact_phone,
            :notes,
            :is_active,
            NOW(),
            NOW()
        )
        RETURNING id
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':stage_name' => $stageName,
        ':legal_name' => artistNormalizeOptionalText($body['legal_name'] ?? null, 200),
        ':document_number' => artistNormalizeOptionalText($body['document_number'] ?? null, 30),
        ':artist_type' => artistNormalizeOptionalText($body['artist_type'] ?? null, 50),
        ':default_contact_name' => artistNormalizeOptionalText($body['default_contact_name'] ?? null, 150),
        ':default_contact_phone' => artistNormalizeOptionalText($body['default_contact_phone'] ?? null, 40),
        ':notes' => artistNormalizeOptionalText($body['notes'] ?? null),
        ':is_active' => artistNormalizeBoolean($body['is_active'] ?? null, true) ? 'true' : 'false',
    ]);

    $artistId = (int)$stmt->fetchColumn();
    jsonSuccess(artistRequireArtistById($db, $organizerId, $artistId), 'Artista criado com sucesso.', 201);
}

function getArtist(int $artistId, array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, false);
    artistSetCurrentEventId($eventId);
    $artist = artistRequireArtistById($db, $organizerId, $artistId);
    $bookings = artistFetchBookingsForArtist($db, $organizerId, $artistId, $eventId);

    $totalCacheAmount = 0.0;
    $totalLogisticsCost = 0.0;
    foreach ($bookings as $booking) {
        $totalCacheAmount += (float)($booking['cache_amount'] ?? 0);
        $totalLogisticsCost += (float)($booking['total_logistics_cost'] ?? 0);
    }

    $payload = $artist;
    $payload['bookings'] = $bookings;
    $payload['bookings_count'] = count($bookings);
    $payload['current_booking'] = $eventId !== null && !empty($bookings) ? $bookings[0] : null;
    $payload['financial_summary'] = [
        'total_cache_amount' => round($totalCacheAmount, 2),
        'total_logistics_cost' => round($totalLogisticsCost, 2),
        'total_artist_cost' => round($totalCacheAmount + $totalLogisticsCost, 2),
    ];

    jsonSuccess($payload, 'Artista carregado com sucesso.');
}

function updateArtist(int $artistId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireArtistById($db, $organizerId, $artistId);

    $stageName = array_key_exists('stage_name', $body)
        ? artistNormalizeOptionalText($body['stage_name'], 200)
        : (string)$current['stage_name'];
    if ($stageName === null) {
        jsonError('stage_name e obrigatorio.', 422);
    }

    $stmt = $db->prepare("
        UPDATE public.artists
        SET
            stage_name = :stage_name,
            legal_name = :legal_name,
            document_number = :document_number,
            artist_type = :artist_type,
            default_contact_name = :default_contact_name,
            default_contact_phone = :default_contact_phone,
            notes = :notes,
            is_active = :is_active,
            updated_at = NOW()
        WHERE id = :artist_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':stage_name' => $stageName,
        ':legal_name' => array_key_exists('legal_name', $body) ? artistNormalizeOptionalText($body['legal_name'], 200) : $current['legal_name'],
        ':document_number' => array_key_exists('document_number', $body) ? artistNormalizeOptionalText($body['document_number'], 30) : $current['document_number'],
        ':artist_type' => array_key_exists('artist_type', $body) ? artistNormalizeOptionalText($body['artist_type'], 50) : $current['artist_type'],
        ':default_contact_name' => array_key_exists('default_contact_name', $body) ? artistNormalizeOptionalText($body['default_contact_name'], 150) : $current['default_contact_name'],
        ':default_contact_phone' => array_key_exists('default_contact_phone', $body) ? artistNormalizeOptionalText($body['default_contact_phone'], 40) : $current['default_contact_phone'],
        ':notes' => array_key_exists('notes', $body) ? artistNormalizeOptionalText($body['notes']) : $current['notes'],
        ':is_active' => (array_key_exists('is_active', $body) ? artistNormalizeBoolean($body['is_active'], (bool)$current['is_active']) : (bool)$current['is_active']) ? 'true' : 'false',
        ':artist_id' => $artistId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireArtistById($db, $organizerId, $artistId), 'Artista atualizado com sucesso.');
}

function listArtistBookings(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $status = artistNormalizeOptionalText($query['status'] ?? $query['booking_status'] ?? null, 30);
    $artistId = artistNormalizeOptionalInt($query['artist_id'] ?? null);

    $countSql = "
        SELECT COUNT(*)
        FROM public.event_artists ea
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE ea.organizer_id = :organizer_id
          AND ea.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
        SELECT
            ea.id,
            ea.organizer_id,
            ea.event_id,
            e.name AS event_name,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            a.legal_name AS artist_legal_name,
            ea.booking_status,
            ea.performance_date,
            ea.performance_start_at,
            ea.performance_duration_minutes,
            ea.soundcheck_at,
            ea.stage_name,
            CAST(ea.cache_amount AS FLOAT) AS cache_amount,
            ea.notes,
            ea.cancelled_at,
            ea.created_at,
            ea.updated_at,
            COALESCE(costs.logistics_items_count, 0) AS logistics_items_count,
            CAST(COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_logistics_cost,
            CAST(COALESCE(ea.cache_amount, 0) + COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_artist_cost
        FROM public.event_artists ea
        JOIN public.events e
          ON e.id = ea.event_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        LEFT JOIN (
            SELECT
                event_artist_id,
                COUNT(*) AS logistics_items_count,
                COALESCE(SUM(COALESCE(total_amount, CASE WHEN unit_amount IS NOT NULL THEN quantity * unit_amount ELSE 0 END)), 0) AS total_logistics_cost
            FROM public.artist_logistics_items
            GROUP BY event_artist_id
        ) costs
          ON costs.event_artist_id = ea.id
        WHERE ea.organizer_id = :organizer_id
          AND ea.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(ea.stage_name, '')) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(ea.stage_name, '')) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($status !== null) {
        $countSql .= " AND ea.booking_status = :booking_status";
        $dataSql .= " AND ea.booking_status = :booking_status";
        $countParams[':booking_status'] = $status;
        $dataParams[':booking_status'] = $status;
    }

    if ($artistId !== null) {
        $countSql .= " AND ea.artist_id = :artist_id";
        $dataSql .= " AND ea.artist_id = :artist_id";
        $countParams[':artist_id'] = $artistId;
        $dataParams[':artist_id'] = $artistId;
    }

    $dataSql .= "
        ORDER BY COALESCE(ea.performance_start_at, ea.created_at) ASC, a.stage_name ASC
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
        $items[] = artistHydrateBookingRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Bookings carregados com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtistBooking(array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $body['event_id'] ?? null, true);
    $artistId = artistNormalizeOptionalInt($body['artist_id'] ?? null);
    if ($artistId === null) {
        jsonError('artist_id e obrigatorio.', 422);
    }

    artistRequireArtistById($db, $organizerId, $artistId);
    artistSetCurrentEventId($eventId);

    $performanceStartAt = artistNormalizeTimestampString($body['performance_start_at'] ?? null);
    $performanceDate = artistNormalizeDateString($body['performance_date'] ?? null);
    if ($performanceDate === null && $performanceStartAt !== null) {
        $performanceDate = substr($performanceStartAt, 0, 10);
    }

    $stmt = $db->prepare("
        INSERT INTO public.event_artists (
            organizer_id,
            event_id,
            artist_id,
            booking_status,
            performance_date,
            performance_start_at,
            performance_duration_minutes,
            soundcheck_at,
            stage_name,
            cache_amount,
            notes,
            cancelled_at,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :artist_id,
            :booking_status,
            :performance_date,
            :performance_start_at,
            :performance_duration_minutes,
            :soundcheck_at,
            :stage_name,
            :cache_amount,
            :notes,
            NULL,
            NOW(),
            NOW()
        )
        RETURNING id
    ");

    try {
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':event_id' => $eventId,
            ':artist_id' => $artistId,
            ':booking_status' => artistNormalizeOptionalText($body['booking_status'] ?? null, 30) ?? 'pending',
            ':performance_date' => $performanceDate,
            ':performance_start_at' => $performanceStartAt,
            ':performance_duration_minutes' => artistNormalizeNonNegativeInt($body['performance_duration_minutes'] ?? null, true, 'performance_duration_minutes'),
            ':soundcheck_at' => artistNormalizeTimestampString($body['soundcheck_at'] ?? null),
            ':stage_name' => artistNormalizeOptionalText($body['stage_name'] ?? null, 150),
            ':cache_amount' => artistNormalizeMoney($body['cache_amount'] ?? null, true, 'cache_amount'),
            ':notes' => artistNormalizeOptionalText($body['notes'] ?? null),
        ]);
    } catch (Throwable $error) {
        if (artistIsUniqueViolation($error)) {
            jsonError('Ja existe booking deste artista para o evento informado.', 409);
        }
        throw $error;
    }

    $bookingId = (int)$stmt->fetchColumn();
    jsonSuccess(artistRequireBookingById($db, $organizerId, $bookingId), 'Booking criado com sucesso.', 201);
}

function getArtistBooking(int $bookingId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $booking = artistRequireBookingById($db, $organizerId, $bookingId);
    artistSetCurrentEventId((int)$booking['event_id']);
    jsonSuccess($booking, 'Booking carregado com sucesso.');
}

function updateArtistBooking(int $bookingId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireBookingById($db, $organizerId, $bookingId);
    artistSetCurrentEventId((int)$current['event_id']);

    if (array_key_exists('event_id', $body) && (int)$body['event_id'] !== (int)$current['event_id']) {
        jsonError('event_id nao pode ser alterado em booking existente.', 422);
    }
    if (array_key_exists('artist_id', $body) && (int)$body['artist_id'] !== (int)$current['artist_id']) {
        jsonError('artist_id nao pode ser alterado em booking existente.', 422);
    }

    $performanceStartAt = array_key_exists('performance_start_at', $body)
        ? artistNormalizeTimestampString($body['performance_start_at'])
        : $current['performance_start_at'];
    $performanceDate = array_key_exists('performance_date', $body)
        ? artistNormalizeDateString($body['performance_date'])
        : $current['performance_date'];
    if ($performanceDate === null && $performanceStartAt !== null) {
        $performanceDate = substr($performanceStartAt, 0, 10);
    }

    $bookingStatus = array_key_exists('booking_status', $body)
        ? artistNormalizeOptionalText($body['booking_status'], 30)
        : $current['booking_status'];
    $cancelledAt = $current['cancelled_at'];
    if ($bookingStatus === 'cancelled' && $cancelledAt === null) {
        $cancelledAt = date('Y-m-d H:i:s');
    } elseif ($bookingStatus !== 'cancelled') {
        $cancelledAt = null;
    }

    $stmt = $db->prepare("
        UPDATE public.event_artists
        SET
            booking_status = :booking_status,
            performance_date = :performance_date,
            performance_start_at = :performance_start_at,
            performance_duration_minutes = :performance_duration_minutes,
            soundcheck_at = :soundcheck_at,
            stage_name = :stage_name,
            cache_amount = :cache_amount,
            notes = :notes,
            cancelled_at = :cancelled_at,
            updated_at = NOW()
        WHERE id = :booking_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':booking_status' => $bookingStatus ?? $current['booking_status'],
        ':performance_date' => $performanceDate,
        ':performance_start_at' => $performanceStartAt,
        ':performance_duration_minutes' => array_key_exists('performance_duration_minutes', $body)
            ? artistNormalizeNonNegativeInt($body['performance_duration_minutes'], true, 'performance_duration_minutes')
            : artistNormalizeNonNegativeInt($current['performance_duration_minutes'], true, 'performance_duration_minutes'),
        ':soundcheck_at' => array_key_exists('soundcheck_at', $body)
            ? artistNormalizeTimestampString($body['soundcheck_at'])
            : $current['soundcheck_at'],
        ':stage_name' => array_key_exists('stage_name', $body)
            ? artistNormalizeOptionalText($body['stage_name'], 150)
            : $current['stage_name'],
        ':cache_amount' => array_key_exists('cache_amount', $body)
            ? artistNormalizeMoney($body['cache_amount'], true, 'cache_amount')
            : artistNormalizeMoney($current['cache_amount'], true, 'cache_amount'),
        ':notes' => array_key_exists('notes', $body)
            ? artistNormalizeOptionalText($body['notes'])
            : $current['notes'],
        ':cancelled_at' => $cancelledAt,
        ':booking_id' => $bookingId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireBookingById($db, $organizerId, $bookingId), 'Booking atualizado com sucesso.');
}

function cancelArtistBooking(int $bookingId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireBookingById($db, $organizerId, $bookingId);
    artistSetCurrentEventId((int)$current['event_id']);
    $reason = artistNormalizeOptionalText($body['cancellation_reason'] ?? $body['notes'] ?? null);

    $notes = trim((string)($current['notes'] ?? ''));
    if ($reason !== null) {
        $notes = trim($notes . "\n" . 'Cancelamento: ' . $reason);
    }
    $notes = $notes !== '' ? $notes : null;

    $stmt = $db->prepare("
        UPDATE public.event_artists
        SET
            booking_status = 'cancelled',
            cancelled_at = NOW(),
            notes = :notes,
            updated_at = NOW()
        WHERE id = :booking_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':notes' => $notes,
        ':booking_id' => $bookingId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireBookingById($db, $organizerId, $bookingId), 'Booking cancelado com sucesso.');
}
