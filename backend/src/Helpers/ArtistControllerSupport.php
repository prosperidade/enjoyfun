<?php

require_once __DIR__ . '/ArtistModuleHelper.php';
require_once __DIR__ . '/ArtistTimelineHelper.php';
require_once __DIR__ . '/ArtistAlertHelper.php';

function artistJsonSuccessWithMeta(mixed $data, string $message, array $meta, int $code = 200): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    if (function_exists('observeApiRequestTelemetry')) {
        observeApiRequestTelemetry(true, $code, $message, ['response_meta_keys' => array_keys($meta)]);
    }

    http_response_code($code);
    echo json_encode([
        'success' => true,
        'data' => $data,
        'message' => $message,
        'meta' => $meta,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function artistSetCurrentEventId(?int $eventId): void
{
    if ($eventId !== null && $eventId > 0 && function_exists('setCurrentRequestEventId')) {
        setCurrentRequestEventId($eventId);
    }
}

function artistBindStatementValues(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        if (is_bool($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
            continue;
        }

        if (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
            continue;
        }

        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            continue;
        }

        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}

function artistFetchCount(PDO $db, string $sql, array $params): int
{
    $stmt = $db->prepare($sql);
    artistBindStatementValues($stmt, $params);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function artistRequireArtistById(PDO $db, int $organizerId, int $artistId): array
{
    $stmt = $db->prepare("
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
            COALESCE(stats.bookings_count, 0) AS bookings_count
        FROM public.artists a
        LEFT JOIN (
            SELECT
                artist_id,
                COUNT(*) AS bookings_count
            FROM public.event_artists
            WHERE organizer_id = :stats_organizer_id
            GROUP BY artist_id
        ) stats
          ON stats.artist_id = a.id
        WHERE a.organizer_id = :organizer_id
          AND a.id = :artist_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':stats_organizer_id' => $organizerId,
        ':artist_id' => $artistId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Artista nao encontrado.', 404);
    }

    return artistHydrateArtistRow($row, false);
}

function artistFetchBookingsForArtist(PDO $db, int $organizerId, int $artistId, ?int $eventId = null): array
{
    $sql = "
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
          AND ea.artist_id = :artist_id
    ";
    $params = [
        ':organizer_id' => $organizerId,
        ':artist_id' => $artistId,
    ];

    if ($eventId !== null) {
        $sql .= " AND ea.event_id = :event_id";
        $params[':event_id'] = $eventId;
    }

    $sql .= " ORDER BY COALESCE(ea.performance_start_at, ea.created_at) ASC, ea.id ASC";
    $stmt = $db->prepare($sql);
    artistBindStatementValues($stmt, $params);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateBookingRow($row);
    }
    return $items;
}

function artistRequireBookingById(PDO $db, int $organizerId, int $bookingId): array
{
    $stmt = $db->prepare("
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
          AND ea.id = :booking_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':booking_id' => $bookingId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Booking nao encontrado.', 404);
    }

    return artistHydrateBookingRow($row);
}

function artistRequireEventArtistMutationContext(PDO $db, int $organizerId, int $eventArtistId, int $expectedEventId): array
{
    $stmt = $db->prepare("
        SELECT id, organizer_id, event_id, artist_id
        FROM public.event_artists
        WHERE id = :event_artist_id
          AND organizer_id = :organizer_id
        LIMIT 1
    ");
    $stmt->execute([
        ':event_artist_id' => $eventArtistId,
        ':organizer_id' => $organizerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Booking informado nao existe no escopo do organizador.', 404);
    }

    if ((int)$row['event_id'] !== $expectedEventId) {
        jsonError('Booking informado nao pertence ao event_id enviado.', 422);
    }

    return $row;
}

function artistBuildLogisticsMutationPayload(int $organizerId, int $eventId, int $eventArtistId, array $body, ?array $current = null): array
{
    return [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':event_artist_id' => $eventArtistId,
        ':arrival_origin' => array_key_exists('arrival_origin', $body) ? artistNormalizeOptionalText($body['arrival_origin'], 200) : ($current['arrival_origin'] ?? null),
        ':arrival_mode' => array_key_exists('arrival_mode', $body) ? artistNormalizeOptionalText($body['arrival_mode'], 50) : ($current['arrival_mode'] ?? null),
        ':arrival_reference' => array_key_exists('arrival_reference', $body) ? artistNormalizeOptionalText($body['arrival_reference'], 120) : ($current['arrival_reference'] ?? null),
        ':arrival_at' => array_key_exists('arrival_at', $body) ? artistNormalizeTimestampString($body['arrival_at']) : ($current['arrival_at'] ?? null),
        ':hotel_name' => array_key_exists('hotel_name', $body) ? artistNormalizeOptionalText($body['hotel_name'], 200) : ($current['hotel_name'] ?? null),
        ':hotel_address' => array_key_exists('hotel_address', $body) ? artistNormalizeOptionalText($body['hotel_address'], 300) : ($current['hotel_address'] ?? null),
        ':hotel_check_in_at' => array_key_exists('hotel_check_in_at', $body) ? artistNormalizeTimestampString($body['hotel_check_in_at']) : ($current['hotel_check_in_at'] ?? null),
        ':hotel_check_out_at' => array_key_exists('hotel_check_out_at', $body) ? artistNormalizeTimestampString($body['hotel_check_out_at']) : ($current['hotel_check_out_at'] ?? null),
        ':venue_arrival_at' => array_key_exists('venue_arrival_at', $body) ? artistNormalizeTimestampString($body['venue_arrival_at']) : ($current['venue_arrival_at'] ?? null),
        ':departure_destination' => array_key_exists('departure_destination', $body) ? artistNormalizeOptionalText($body['departure_destination'], 200) : ($current['departure_destination'] ?? null),
        ':departure_mode' => array_key_exists('departure_mode', $body) ? artistNormalizeOptionalText($body['departure_mode'], 50) : ($current['departure_mode'] ?? null),
        ':departure_reference' => array_key_exists('departure_reference', $body) ? artistNormalizeOptionalText($body['departure_reference'], 120) : ($current['departure_reference'] ?? null),
        ':departure_at' => array_key_exists('departure_at', $body) ? artistNormalizeTimestampString($body['departure_at']) : ($current['departure_at'] ?? null),
        ':hospitality_notes' => array_key_exists('hospitality_notes', $body) ? artistNormalizeOptionalText($body['hospitality_notes']) : ($current['hospitality_notes'] ?? null),
        ':transport_notes' => array_key_exists('transport_notes', $body) ? artistNormalizeOptionalText($body['transport_notes']) : ($current['transport_notes'] ?? null),
    ];
}

function artistRequireLogisticsById(PDO $db, int $organizerId, int $logisticsId): array
{
    $stmt = $db->prepare("
        SELECT
            l.id,
            l.organizer_id,
            l.event_id,
            e.name AS event_name,
            l.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            ea.performance_start_at,
            l.arrival_origin,
            l.arrival_mode,
            l.arrival_reference,
            l.arrival_at,
            l.hotel_name,
            l.hotel_address,
            l.hotel_check_in_at,
            l.hotel_check_out_at,
            l.venue_arrival_at,
            l.departure_destination,
            l.departure_mode,
            l.departure_reference,
            l.departure_at,
            l.hospitality_notes,
            l.transport_notes,
            l.created_at,
            l.updated_at,
            COALESCE(costs.logistics_items_count, 0) AS logistics_items_count,
            CAST(COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_logistics_cost
        FROM public.artist_logistics l
        JOIN public.events e
          ON e.id = l.event_id
        JOIN public.event_artists ea
          ON ea.id = l.event_artist_id
         AND ea.organizer_id = l.organizer_id
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
          ON costs.event_artist_id = l.event_artist_id
        WHERE l.organizer_id = :organizer_id
          AND l.id = :logistics_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':logistics_id' => $logisticsId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Logistica nao encontrada.', 404);
    }

    $payload = artistHydrateLogisticsRow($row);
    $payload['items'] = artistFetchLogisticsItemsForBooking($db, $organizerId, (int)$payload['event_artist_id']);
    return $payload;
}

function artistResolveOptionalLogisticsHeaderId(PDO $db, int $organizerId, mixed $rawLogisticsId, int $eventArtistId): ?int
{
    $logisticsId = artistNormalizeOptionalInt($rawLogisticsId);
    if ($logisticsId === null) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT id
        FROM public.artist_logistics
        WHERE id = :logistics_id
          AND organizer_id = :organizer_id
          AND event_artist_id = :event_artist_id
        LIMIT 1
    ");
    $stmt->execute([
        ':logistics_id' => $logisticsId,
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ]);
    if (!$stmt->fetchColumn()) {
        jsonError('artist_logistics_id nao pertence ao booking informado.', 422);
    }

    return $logisticsId;
}

function artistResolveLogisticsItemAmounts(array $body, ?array $current = null): array
{
    $quantity = array_key_exists('quantity', $body)
        ? artistNormalizePositiveQuantity($body['quantity'], 'quantity')
        : artistNormalizePositiveQuantity($current['quantity'] ?? null, 'quantity');

    $unitAmount = array_key_exists('unit_amount', $body)
        ? artistNormalizeMoney($body['unit_amount'], true, 'unit_amount')
        : artistNormalizeMoney($current['unit_amount'] ?? null, true, 'unit_amount');

    if ($unitAmount !== null) {
        $totalAmount = number_format(round(((float)$quantity) * ((float)$unitAmount), 2), 2, '.', '');
    } else {
        $totalAmount = array_key_exists('total_amount', $body)
            ? artistNormalizeMoney($body['total_amount'], true, 'total_amount')
            : artistNormalizeMoney($current['total_amount'] ?? null, true, 'total_amount');
    }

    return [
        'quantity' => $quantity,
        'unit_amount' => $unitAmount,
        'total_amount' => $totalAmount,
    ];
}

function artistRequireLogisticsItemById(PDO $db, int $organizerId, int $itemId): array
{
    $stmt = $db->prepare("
        SELECT
            i.id,
            i.organizer_id,
            i.event_id,
            e.name AS event_name,
            i.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            i.artist_logistics_id,
            i.item_type,
            i.description,
            CAST(i.quantity AS FLOAT) AS quantity,
            CAST(i.unit_amount AS FLOAT) AS unit_amount,
            CAST(i.total_amount AS FLOAT) AS total_amount,
            i.currency_code,
            i.supplier_name,
            i.notes,
            i.status,
            i.created_at,
            i.updated_at
        FROM public.artist_logistics_items i
        JOIN public.events e
          ON e.id = i.event_id
        JOIN public.event_artists ea
          ON ea.id = i.event_artist_id
         AND ea.organizer_id = i.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE i.organizer_id = :organizer_id
          AND i.id = :item_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':item_id' => $itemId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Item de logistica nao encontrado.', 404);
    }

    return artistHydrateLogisticsItemRow($row);
}

function artistFetchLogisticsItemsForBooking(PDO $db, int $organizerId, int $eventArtistId): array
{
    $stmt = $db->prepare("
        SELECT
            i.id,
            i.organizer_id,
            i.event_id,
            i.event_artist_id,
            i.artist_logistics_id,
            i.item_type,
            i.description,
            CAST(i.quantity AS FLOAT) AS quantity,
            CAST(i.unit_amount AS FLOAT) AS unit_amount,
            CAST(i.total_amount AS FLOAT) AS total_amount,
            i.currency_code,
            i.supplier_name,
            i.notes,
            i.status,
            i.created_at,
            i.updated_at
        FROM public.artist_logistics_items i
        WHERE i.organizer_id = :organizer_id
          AND i.event_artist_id = :event_artist_id
        ORDER BY i.created_at DESC, i.id DESC
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateLogisticsItemRow($row);
    }
    return $items;
}

function artistBuildTimelineMutationPayload(array $body, ?array $current = null): array
{
    $fields = [
        'landing_at',
        'airport_out_at',
        'hotel_arrival_at',
        'venue_arrival_at',
        'soundcheck_at',
        'show_start_at',
        'show_end_at',
        'venue_exit_at',
        'next_departure_deadline_at',
    ];

    $payload = [];
    foreach ($fields as $field) {
        $payload[':' . $field] = array_key_exists($field, $body)
            ? artistNormalizeTimestampString($body[$field])
            : ($current[$field] ?? null);
    }

    return $payload;
}

function artistBuildTransferMutationPayload(array $body, ?array $current = null): array
{
    $routeCode = array_key_exists('route_code', $body)
        ? artistNormalizeOptionalText($body['route_code'], 50)
        : ($current['route_code'] ?? null);
    $originLabel = array_key_exists('origin_label', $body)
        ? artistNormalizeOptionalText($body['origin_label'], 150)
        : ($current['origin_label'] ?? null);
    $destinationLabel = array_key_exists('destination_label', $body)
        ? artistNormalizeOptionalText($body['destination_label'], 150)
        : ($current['destination_label'] ?? null);
    $etaBaseMinutes = array_key_exists('eta_base_minutes', $body)
        ? artistNormalizeNonNegativeInt($body['eta_base_minutes'], true, 'eta_base_minutes')
        : ($current['eta_base_minutes'] ?? null);
    $etaPeakMinutes = array_key_exists('eta_peak_minutes', $body)
        ? artistNormalizeNonNegativeInt($body['eta_peak_minutes'], true, 'eta_peak_minutes')
        : ($current['eta_peak_minutes'] ?? null);
    $bufferMinutes = array_key_exists('buffer_minutes', $body)
        ? artistNormalizeNonNegativeInt($body['buffer_minutes'], true, 'buffer_minutes')
        : ($current['buffer_minutes'] ?? 0);

    if ($routeCode === null || $originLabel === null || $destinationLabel === null || $etaBaseMinutes === null) {
        jsonError('route_code, origin_label, destination_label e eta_base_minutes sao obrigatorios.', 422);
    }

    return [
        'route_code' => $routeCode,
        'origin_label' => $originLabel,
        'destination_label' => $destinationLabel,
        'eta_base_minutes' => $etaBaseMinutes,
        'eta_peak_minutes' => $etaPeakMinutes,
        'buffer_minutes' => $bufferMinutes ?? 0,
        'planned_eta_minutes' => artistCalculatePlannedEtaMinutes(
            $etaBaseMinutes,
            $etaPeakMinutes,
            $bufferMinutes ?? 0
        ),
        'notes' => array_key_exists('notes', $body)
            ? artistNormalizeOptionalText($body['notes'])
            : ($current['notes'] ?? null),
    ];
}

function artistNormalizeAlertStatus(mixed $value, bool $allowDismissed = true): string
{
    $status = strtolower(trim((string)($value ?? '')));
    $allowed = $allowDismissed
        ? ['open', 'acknowledged', 'resolved', 'dismissed']
        : ['open', 'acknowledged', 'resolved'];

    if (!in_array($status, $allowed, true)) {
        jsonError('status de alerta invalido.', 422);
    }

    return $status;
}

function artistResolveAlertResolvedAt(string $status, ?string $currentResolvedAt = null): ?string
{
    if (in_array($status, ['resolved', 'dismissed'], true)) {
        return $currentResolvedAt ?? date('Y-m-d H:i:s');
    }

    return null;
}

function artistAlertSeverityFromRank(int $rank): string
{
    return match (true) {
        $rank >= 4 => 'red',
        $rank === 3 => 'orange',
        $rank === 2 => 'yellow',
        $rank === 1 => 'gray',
        default => 'green',
    };
}

function artistFindLogisticsByEventArtistId(PDO $db, int $organizerId, int $eventArtistId): ?array
{
    $stmt = $db->prepare("
        SELECT
            l.id,
            l.organizer_id,
            l.event_id,
            e.name AS event_name,
            l.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            ea.performance_start_at,
            l.arrival_origin,
            l.arrival_mode,
            l.arrival_reference,
            l.arrival_at,
            l.hotel_name,
            l.hotel_address,
            l.hotel_check_in_at,
            l.hotel_check_out_at,
            l.venue_arrival_at,
            l.departure_destination,
            l.departure_mode,
            l.departure_reference,
            l.departure_at,
            l.hospitality_notes,
            l.transport_notes,
            l.created_at,
            l.updated_at,
            COALESCE(costs.logistics_items_count, 0) AS logistics_items_count,
            CAST(COALESCE(costs.total_logistics_cost, 0) AS FLOAT) AS total_logistics_cost
        FROM public.artist_logistics l
        JOIN public.events e
          ON e.id = l.event_id
        JOIN public.event_artists ea
          ON ea.id = l.event_artist_id
         AND ea.organizer_id = l.organizer_id
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
          ON costs.event_artist_id = l.event_artist_id
        WHERE l.organizer_id = :organizer_id
          AND l.event_artist_id = :event_artist_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? artistHydrateLogisticsRow($row) : null;
}

function artistFindTimelineBaseById(PDO $db, int $organizerId, int $timelineId): ?array
{
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.organizer_id,
            t.event_id,
            e.name AS event_name,
            t.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            t.landing_at,
            t.airport_out_at,
            t.hotel_arrival_at,
            t.venue_arrival_at,
            t.soundcheck_at,
            t.show_start_at,
            t.show_end_at,
            t.venue_exit_at,
            t.next_departure_deadline_at,
            t.timeline_status,
            t.created_at,
            t.updated_at
        FROM public.artist_operational_timelines t
        JOIN public.events e
          ON e.id = t.event_id
        JOIN public.event_artists ea
          ON ea.id = t.event_artist_id
         AND ea.organizer_id = t.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE t.organizer_id = :organizer_id
          AND t.id = :timeline_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':timeline_id' => $timelineId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? artistHydrateTimelineRow($row) : null;
}

function artistFindTimelineByEventArtistId(PDO $db, int $organizerId, int $eventArtistId): ?array
{
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.organizer_id,
            t.event_id,
            e.name AS event_name,
            t.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            t.landing_at,
            t.airport_out_at,
            t.hotel_arrival_at,
            t.venue_arrival_at,
            t.soundcheck_at,
            t.show_start_at,
            t.show_end_at,
            t.venue_exit_at,
            t.next_departure_deadline_at,
            t.timeline_status,
            t.created_at,
            t.updated_at
        FROM public.artist_operational_timelines t
        JOIN public.events e
          ON e.id = t.event_id
        JOIN public.event_artists ea
          ON ea.id = t.event_artist_id
         AND ea.organizer_id = t.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE t.organizer_id = :organizer_id
          AND t.event_artist_id = :event_artist_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? artistHydrateTimelineRow($row) : null;
}

function artistRequireTimelineById(PDO $db, int $organizerId, int $timelineId): array
{
    $timeline = artistFindTimelineBaseById($db, $organizerId, $timelineId);
    if ($timeline === null) {
        jsonError('Timeline operacional nao encontrada.', 404);
    }

    $booking = artistRequireBookingById($db, $organizerId, (int)$timeline['event_artist_id']);
    $logistics = artistFindLogisticsByEventArtistId($db, $organizerId, (int)$timeline['event_artist_id']);
    $transfers = artistFetchTransfersForBooking($db, $organizerId, (int)$timeline['event_artist_id']);
    $alerts = artistFetchAlertsForBooking($db, $organizerId, (int)$timeline['event_artist_id']);
    $computed = artistBuildOperationalComputationForBooking($booking, $logistics, $timeline, $transfers);

    $timeline['derived_timeline'] = $computed['timeline'];
    $timeline['computed_windows'] = $computed['windows'];
    $timeline['current_severity'] = $computed['current_severity'];
    $timeline['transfers_count'] = count($transfers);
    $timeline['open_alerts_count'] = count(array_filter($alerts, static fn (array $alert): bool => in_array($alert['status'], ['open', 'acknowledged'], true)));
    $timeline['transfers'] = $transfers;
    $timeline['alerts'] = $alerts;

    return $timeline;
}

function artistRequireTransferById(PDO $db, int $organizerId, int $transferId): array
{
    $stmt = $db->prepare("
        SELECT
            tr.id,
            tr.organizer_id,
            tr.event_id,
            e.name AS event_name,
            tr.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            tr.route_code,
            tr.origin_label,
            tr.destination_label,
            tr.eta_base_minutes,
            tr.eta_peak_minutes,
            tr.buffer_minutes,
            tr.planned_eta_minutes,
            tr.notes,
            tr.created_at,
            tr.updated_at
        FROM public.artist_transfer_estimations tr
        JOIN public.events e
          ON e.id = tr.event_id
        JOIN public.event_artists ea
          ON ea.id = tr.event_artist_id
         AND ea.organizer_id = tr.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE tr.organizer_id = :organizer_id
          AND tr.id = :transfer_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':transfer_id' => $transferId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Estimativa de transfer nao encontrada.', 404);
    }

    return artistHydrateTransferRow($row);
}

function artistRequireAlertById(PDO $db, int $organizerId, int $alertId): array
{
    $stmt = $db->prepare("
        SELECT
            al.id,
            al.organizer_id,
            al.event_id,
            e.name AS event_name,
            al.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            al.timeline_id,
            al.alert_type,
            al.severity,
            al.status,
            al.title,
            al.message,
            al.recommended_action,
            al.triggered_at,
            al.resolved_at,
            al.resolution_notes,
            al.created_at,
            al.updated_at
        FROM public.artist_operational_alerts al
        JOIN public.events e
          ON e.id = al.event_id
        JOIN public.event_artists ea
          ON ea.id = al.event_artist_id
         AND ea.organizer_id = al.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE al.organizer_id = :organizer_id
          AND al.id = :alert_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':alert_id' => $alertId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Alerta operacional nao encontrado.', 404);
    }

    return artistHydrateAlertRow($row);
}

function artistFetchTransfersForBooking(PDO $db, int $organizerId, int $eventArtistId): array
{
    $stmt = $db->prepare("
        SELECT
            tr.id,
            tr.organizer_id,
            tr.event_id,
            e.name AS event_name,
            tr.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            tr.route_code,
            tr.origin_label,
            tr.destination_label,
            tr.eta_base_minutes,
            tr.eta_peak_minutes,
            tr.buffer_minutes,
            tr.planned_eta_minutes,
            tr.notes,
            tr.created_at,
            tr.updated_at
        FROM public.artist_transfer_estimations tr
        JOIN public.events e
          ON e.id = tr.event_id
        JOIN public.event_artists ea
          ON ea.id = tr.event_artist_id
         AND ea.organizer_id = tr.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE tr.organizer_id = :organizer_id
          AND tr.event_artist_id = :event_artist_id
        ORDER BY tr.created_at ASC, tr.id ASC
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateTransferRow($row);
    }

    return $items;
}

function artistFetchAlertsForBooking(PDO $db, int $organizerId, int $eventArtistId, ?array $statuses = null): array
{
    $sql = "
        SELECT
            al.id,
            al.organizer_id,
            al.event_id,
            e.name AS event_name,
            al.event_artist_id,
            ea.artist_id,
            a.stage_name AS artist_stage_name,
            ea.booking_status,
            al.timeline_id,
            al.alert_type,
            al.severity,
            al.status,
            al.title,
            al.message,
            al.recommended_action,
            al.triggered_at,
            al.resolved_at,
            al.resolution_notes,
            al.created_at,
            al.updated_at
        FROM public.artist_operational_alerts al
        JOIN public.events e
          ON e.id = al.event_id
        JOIN public.event_artists ea
          ON ea.id = al.event_artist_id
         AND ea.organizer_id = al.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE al.organizer_id = :organizer_id
          AND al.event_artist_id = :event_artist_id
    ";
    $params = [
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ];

    if ($statuses !== null && $statuses !== []) {
        $placeholders = [];
        foreach (array_values($statuses) as $index => $status) {
            $key = ':status_' . $index;
            $placeholders[] = $key;
            $params[$key] = $status;
        }
        $sql .= " AND al.status IN (" . implode(', ', $placeholders) . ")";
    }

    $sql .= " ORDER BY al.triggered_at DESC, al.id DESC";
    $stmt = $db->prepare($sql);
    artistBindStatementValues($stmt, $params);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateAlertRow($row);
    }

    return $items;
}

function artistBuildOperationalComputationForBooking(
    array $booking,
    ?array $logistics,
    ?array $timeline,
    array $transfers
): array {
    $derivedTimeline = [
        'id' => $timeline['id'] ?? null,
        'organizer_id' => $booking['organizer_id'],
        'event_id' => $booking['event_id'],
        'event_artist_id' => $booking['id'],
        'landing_at' => $timeline['landing_at'] ?? ($logistics['arrival_at'] ?? null),
        'airport_out_at' => $timeline['airport_out_at'] ?? null,
        'hotel_arrival_at' => $timeline['hotel_arrival_at'] ?? ($logistics['hotel_check_in_at'] ?? null),
        'venue_arrival_at' => $timeline['venue_arrival_at'] ?? ($logistics['venue_arrival_at'] ?? null),
        'soundcheck_at' => $timeline['soundcheck_at'] ?? ($booking['soundcheck_at'] ?? null),
        'show_start_at' => $timeline['show_start_at'] ?? ($booking['performance_start_at'] ?? null),
        'show_end_at' => $timeline['show_end_at'] ?? artistCalculateShowEndAt(
            $timeline['show_start_at'] ?? ($booking['performance_start_at'] ?? null),
            $booking['performance_duration_minutes'] ?? null
        ),
        'venue_exit_at' => $timeline['venue_exit_at'] ?? null,
        'next_departure_deadline_at' => $timeline['next_departure_deadline_at'] ?? ($logistics['departure_at'] ?? null),
        'timeline_status' => $timeline['timeline_status'] ?? null,
    ];

    if ($derivedTimeline['venue_arrival_at'] === null) {
        $transferSummary = artistSummarizeTransferWindows($transfers);
        if ($derivedTimeline['landing_at'] !== null && $transferSummary['arrival_eta_minutes'] !== null) {
            $derivedTimeline['venue_arrival_at'] = artistAddMinutesToTimestamp(
                $derivedTimeline['landing_at'],
                $transferSummary['arrival_eta_minutes']
            );
        }
    }

    $windows = artistBuildOperationalWindows($derivedTimeline, $transfers);
    $snapshots = artistBuildOperationalAlertSnapshots($derivedTimeline, $transfers);
    $derivedTimeline['timeline_status'] = artistResolveTimelineStatusFromSnapshots($snapshots);

    return [
        'timeline' => $derivedTimeline,
        'windows' => $windows,
        'snapshots' => $snapshots,
        'current_severity' => artistResolveHighestAlertSeverity(array_column($snapshots, 'severity'), 'green'),
    ];
}

function artistPersistDerivedTimeline(PDO $db, int $organizerId, int $timelineId, array $timeline): void
{
    $stmt = $db->prepare("
        UPDATE public.artist_operational_timelines
        SET
            landing_at = :landing_at,
            airport_out_at = :airport_out_at,
            hotel_arrival_at = :hotel_arrival_at,
            venue_arrival_at = :venue_arrival_at,
            soundcheck_at = :soundcheck_at,
            show_start_at = :show_start_at,
            show_end_at = :show_end_at,
            venue_exit_at = :venue_exit_at,
            next_departure_deadline_at = :next_departure_deadline_at,
            timeline_status = :timeline_status,
            updated_at = NOW()
        WHERE id = :timeline_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':landing_at' => $timeline['landing_at'] ?? null,
        ':airport_out_at' => $timeline['airport_out_at'] ?? null,
        ':hotel_arrival_at' => $timeline['hotel_arrival_at'] ?? null,
        ':venue_arrival_at' => $timeline['venue_arrival_at'] ?? null,
        ':soundcheck_at' => $timeline['soundcheck_at'] ?? null,
        ':show_start_at' => $timeline['show_start_at'] ?? null,
        ':show_end_at' => $timeline['show_end_at'] ?? null,
        ':venue_exit_at' => $timeline['venue_exit_at'] ?? null,
        ':next_departure_deadline_at' => $timeline['next_departure_deadline_at'] ?? null,
        ':timeline_status' => $timeline['timeline_status'] ?? null,
        ':timeline_id' => $timelineId,
        ':organizer_id' => $organizerId,
    ]);
}

function artistRecalculateTimelineAndAlertsByTimelineId(PDO $db, int $organizerId, int $timelineId): array
{
    $timeline = artistFindTimelineBaseById($db, $organizerId, $timelineId);
    if ($timeline === null) {
        jsonError('Timeline operacional nao encontrada.', 404);
    }

    $booking = artistRequireBookingById($db, $organizerId, (int)$timeline['event_artist_id']);
    $logistics = artistFindLogisticsByEventArtistId($db, $organizerId, (int)$timeline['event_artist_id']);
    $transfers = artistFetchTransfersForBooking($db, $organizerId, (int)$timeline['event_artist_id']);
    $computed = artistBuildOperationalComputationForBooking($booking, $logistics, $timeline, $transfers);

    artistPersistDerivedTimeline($db, $organizerId, $timelineId, $computed['timeline']);
    artistRecalculateAlertsForBooking($db, $organizerId, (int)$timeline['event_artist_id']);

    return artistRequireTimelineById($db, $organizerId, $timelineId);
}

function artistRecalculateAlertsForBooking(PDO $db, int $organizerId, int $eventArtistId): array
{
    $booking = artistRequireBookingById($db, $organizerId, $eventArtistId);
    $logistics = artistFindLogisticsByEventArtistId($db, $organizerId, $eventArtistId);
    $timeline = artistFindTimelineByEventArtistId($db, $organizerId, $eventArtistId);
    $transfers = artistFetchTransfersForBooking($db, $organizerId, $eventArtistId);
    $computed = artistBuildOperationalComputationForBooking($booking, $logistics, $timeline, $transfers);

    if ($timeline !== null) {
        artistPersistDerivedTimeline($db, $organizerId, (int)$timeline['id'], $computed['timeline']);
    }

    $existingAlerts = artistFetchAlertsForBooking($db, $organizerId, $eventArtistId);
    $activeByType = [];
    foreach ($existingAlerts as $alert) {
        if (!in_array($alert['status'], ['open', 'acknowledged'], true)) {
            continue;
        }
        if (!isset($activeByType[$alert['alert_type']])) {
            $activeByType[$alert['alert_type']] = $alert;
        }
    }

    $snapshotByType = [];
    foreach ($computed['snapshots'] as $snapshot) {
        $snapshotByType[$snapshot['alert_type']] = $snapshot;
    }

    $created = 0;
    $updated = 0;
    $resolved = 0;
    $timelineId = $timeline['id'] ?? null;

    foreach ($computed['snapshots'] as $snapshot) {
        $activeAlert = $activeByType[$snapshot['alert_type']] ?? null;

        if (artistShouldPersistOperationalAlert($snapshot)) {
            if ($activeAlert !== null) {
                $stmt = $db->prepare("
                    UPDATE public.artist_operational_alerts
                    SET
                        timeline_id = :timeline_id,
                        severity = :severity,
                        title = :title,
                        message = :message,
                        recommended_action = :recommended_action,
                        updated_at = NOW()
                    WHERE id = :alert_id
                      AND organizer_id = :organizer_id
                ");
                $stmt->execute([
                    ':timeline_id' => $timelineId,
                    ':severity' => $snapshot['severity'],
                    ':title' => $snapshot['title'],
                    ':message' => $snapshot['message'],
                    ':recommended_action' => $snapshot['recommended_action'],
                    ':alert_id' => $activeAlert['id'],
                    ':organizer_id' => $organizerId,
                ]);
                $updated++;
                continue;
            }

            $stmt = $db->prepare("
                INSERT INTO public.artist_operational_alerts (
                    organizer_id,
                    event_id,
                    event_artist_id,
                    timeline_id,
                    alert_type,
                    severity,
                    status,
                    title,
                    message,
                    recommended_action,
                    triggered_at,
                    resolved_at,
                    resolution_notes,
                    created_at,
                    updated_at
                ) VALUES (
                    :organizer_id,
                    :event_id,
                    :event_artist_id,
                    :timeline_id,
                    :alert_type,
                    :severity,
                    'open',
                    :title,
                    :message,
                    :recommended_action,
                    NOW(),
                    NULL,
                    NULL,
                    NOW(),
                    NOW()
                )
            ");
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':event_id' => $booking['event_id'],
                ':event_artist_id' => $eventArtistId,
                ':timeline_id' => $timelineId,
                ':alert_type' => $snapshot['alert_type'],
                ':severity' => $snapshot['severity'],
                ':title' => $snapshot['title'],
                ':message' => $snapshot['message'],
                ':recommended_action' => $snapshot['recommended_action'],
            ]);
            $created++;
            continue;
        }

        if ($activeAlert !== null) {
            $stmt = $db->prepare("
                UPDATE public.artist_operational_alerts
                SET
                    status = 'resolved',
                    resolved_at = COALESCE(resolved_at, NOW()),
                    resolution_notes = COALESCE(resolution_notes, 'Resolvido automaticamente apos recalculo operacional.'),
                    updated_at = NOW()
                WHERE id = :alert_id
                  AND organizer_id = :organizer_id
            ");
            $stmt->execute([
                ':alert_id' => $activeAlert['id'],
                ':organizer_id' => $organizerId,
            ]);
            $resolved++;
        }
    }

    foreach ($activeByType as $alertType => $activeAlert) {
        if (isset($snapshotByType[$alertType])) {
            continue;
        }

        $stmt = $db->prepare("
            UPDATE public.artist_operational_alerts
            SET
                status = 'resolved',
                resolved_at = COALESCE(resolved_at, NOW()),
                resolution_notes = COALESCE(resolution_notes, 'Resolvido automaticamente apos recalculo operacional.'),
                updated_at = NOW()
            WHERE id = :alert_id
              AND organizer_id = :organizer_id
        ");
        $stmt->execute([
            ':alert_id' => $activeAlert['id'],
            ':organizer_id' => $organizerId,
        ]);
        $resolved++;
    }

    return [
        'event_artist_id' => $eventArtistId,
        'created' => $created,
        'updated' => $updated,
        'resolved' => $resolved,
        'current_severity' => $computed['current_severity'],
        'timeline_status' => $computed['timeline']['timeline_status'],
    ];
}

function artistRecalculateAlertsForEvent(PDO $db, int $organizerId, int $eventId, ?int $eventArtistId = null): array
{
    $sql = "
        SELECT id
        FROM public.event_artists
        WHERE organizer_id = :organizer_id
          AND event_id = :event_id
    ";
    $params = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    if ($eventArtistId !== null) {
        $sql .= " AND id = :event_artist_id";
        $params[':event_artist_id'] = $eventArtistId;
    }

    $sql .= " ORDER BY id ASC";
    $stmt = $db->prepare($sql);
    artistBindStatementValues($stmt, $params);
    $stmt->execute();
    $bookingIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $processed = [];
    $totals = [
        'created' => 0,
        'updated' => 0,
        'resolved' => 0,
    ];

    foreach ($bookingIds as $bookingId) {
        $summary = artistRecalculateAlertsForBooking($db, $organizerId, (int)$bookingId);
        $processed[] = $summary;
        $totals['created'] += (int)$summary['created'];
        $totals['updated'] += (int)$summary['updated'];
        $totals['resolved'] += (int)$summary['resolved'];
    }

    return [
        'event_id' => $eventId,
        'event_artist_id' => $eventArtistId,
        'processed_bookings' => count($processed),
        'totals' => $totals,
        'items' => $processed,
    ];
}

function artistRequireTeamMemberById(PDO $db, int $organizerId, int $memberId): array
{
    $stmt = $db->prepare("
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
          AND tm.id = :member_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':member_id' => $memberId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Membro da equipe nao encontrado.', 404);
    }

    return artistHydrateTeamMemberRow($row);
}

function artistRequireFileById(PDO $db, int $organizerId, int $fileId): array
{
    $stmt = $db->prepare("
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
          AND f.id = :file_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':file_id' => $fileId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError('Arquivo do artista nao encontrado.', 404);
    }

    return artistHydrateFileRow($row);
}

function artistHydrateArtistRow(array $row, bool $withEventContext): array
{
    $payload = [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'stage_name' => $row['stage_name'],
        'legal_name' => $row['legal_name'],
        'document_number' => $row['document_number'],
        'artist_type' => $row['artist_type'],
        'default_contact_name' => $row['default_contact_name'],
        'default_contact_phone' => $row['default_contact_phone'],
        'notes' => $row['notes'],
        'is_active' => artistNormalizeBoolean($row['is_active'], true),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];

    if ($withEventContext) {
        $payload['event_artist_id'] = isset($row['event_artist_id']) ? (int)$row['event_artist_id'] : null;
        $payload['event_id'] = isset($row['event_id']) ? (int)$row['event_id'] : null;
        $payload['booking_status'] = $row['booking_status'] ?? null;
        $payload['performance_date'] = $row['performance_date'] ?? null;
        $payload['performance_start_at'] = $row['performance_start_at'] ?? null;
        $payload['performance_duration_minutes'] = isset($row['performance_duration_minutes']) && $row['performance_duration_minutes'] !== null ? (int)$row['performance_duration_minutes'] : null;
        $payload['soundcheck_at'] = $row['soundcheck_at'] ?? null;
        $payload['booking_stage_name'] = $row['booking_stage_name'] ?? null;
        $payload['cache_amount'] = isset($row['cache_amount']) && $row['cache_amount'] !== null ? round((float)$row['cache_amount'], 2) : null;
        $payload['cancelled_at'] = $row['cancelled_at'] ?? null;
        $payload['logistics_items_count'] = isset($row['logistics_items_count']) ? (int)$row['logistics_items_count'] : 0;
        $payload['total_logistics_cost'] = isset($row['total_logistics_cost']) ? round((float)$row['total_logistics_cost'], 2) : 0.0;
        $payload['total_artist_cost'] = isset($row['total_artist_cost']) ? round((float)$row['total_artist_cost'], 2) : 0.0;
    } else {
        $payload['bookings_count'] = isset($row['bookings_count']) ? (int)$row['bookings_count'] : 0;
        $payload['total_cache_amount'] = isset($row['total_cache_amount']) ? round((float)$row['total_cache_amount'], 2) : 0.0;
        $payload['total_logistics_cost'] = isset($row['total_logistics_cost']) ? round((float)$row['total_logistics_cost'], 2) : 0.0;
        $payload['total_artist_cost'] = isset($row['total_artist_cost']) ? round((float)$row['total_artist_cost'], 2) : 0.0;
    }

    return $payload;
}

function artistHydrateBookingRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => (int)$row['event_id'],
        'event_name' => $row['event_name'] ?? null,
        'artist_id' => (int)$row['artist_id'],
        'artist_stage_name' => $row['artist_stage_name'],
        'artist_legal_name' => $row['artist_legal_name'],
        'booking_status' => $row['booking_status'],
        'performance_date' => $row['performance_date'],
        'performance_start_at' => $row['performance_start_at'],
        'performance_duration_minutes' => $row['performance_duration_minutes'] !== null ? (int)$row['performance_duration_minutes'] : null,
        'soundcheck_at' => $row['soundcheck_at'],
        'stage_name' => $row['stage_name'],
        'cache_amount' => $row['cache_amount'] !== null ? round((float)$row['cache_amount'], 2) : null,
        'notes' => $row['notes'],
        'cancelled_at' => $row['cancelled_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'logistics_items_count' => isset($row['logistics_items_count']) ? (int)$row['logistics_items_count'] : 0,
        'total_logistics_cost' => isset($row['total_logistics_cost']) ? round((float)$row['total_logistics_cost'], 2) : 0.0,
        'total_artist_cost' => isset($row['total_artist_cost']) ? round((float)$row['total_artist_cost'], 2) : 0.0,
    ];
}

function artistHydrateLogisticsRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => (int)$row['event_id'],
        'event_name' => $row['event_name'] ?? null,
        'event_artist_id' => (int)$row['event_artist_id'],
        'artist_id' => (int)$row['artist_id'],
        'artist_stage_name' => $row['artist_stage_name'],
        'booking_status' => $row['booking_status'] ?? null,
        'performance_start_at' => $row['performance_start_at'] ?? null,
        'arrival_origin' => $row['arrival_origin'],
        'arrival_mode' => $row['arrival_mode'],
        'arrival_reference' => $row['arrival_reference'],
        'arrival_at' => $row['arrival_at'],
        'hotel_name' => $row['hotel_name'],
        'hotel_address' => $row['hotel_address'],
        'hotel_check_in_at' => $row['hotel_check_in_at'],
        'hotel_check_out_at' => $row['hotel_check_out_at'],
        'venue_arrival_at' => $row['venue_arrival_at'],
        'departure_destination' => $row['departure_destination'],
        'departure_mode' => $row['departure_mode'],
        'departure_reference' => $row['departure_reference'],
        'departure_at' => $row['departure_at'],
        'hospitality_notes' => $row['hospitality_notes'],
        'transport_notes' => $row['transport_notes'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'logistics_items_count' => isset($row['logistics_items_count']) ? (int)$row['logistics_items_count'] : 0,
        'total_logistics_cost' => isset($row['total_logistics_cost']) ? round((float)$row['total_logistics_cost'], 2) : 0.0,
    ];
}

function artistHydrateLogisticsItemRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => isset($row['event_id']) ? (int)$row['event_id'] : null,
        'event_name' => $row['event_name'] ?? null,
        'event_artist_id' => (int)$row['event_artist_id'],
        'artist_id' => isset($row['artist_id']) ? (int)$row['artist_id'] : null,
        'artist_stage_name' => $row['artist_stage_name'] ?? null,
        'artist_logistics_id' => $row['artist_logistics_id'] !== null ? (int)$row['artist_logistics_id'] : null,
        'item_type' => $row['item_type'],
        'description' => $row['description'],
        'quantity' => $row['quantity'] !== null ? round((float)$row['quantity'], 2) : 0.0,
        'unit_amount' => $row['unit_amount'] !== null ? round((float)$row['unit_amount'], 2) : null,
        'total_amount' => $row['total_amount'] !== null ? round((float)$row['total_amount'], 2) : null,
        'currency_code' => $row['currency_code'],
        'supplier_name' => $row['supplier_name'],
        'notes' => $row['notes'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function artistHydrateTimelineRow(array $row): array
{
    $severity = isset($row['current_severity_rank'])
        ? artistAlertSeverityFromRank((int)$row['current_severity_rank'])
        : (($row['current_severity'] ?? null) ?: 'green');

    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => (int)$row['event_id'],
        'event_name' => $row['event_name'] ?? null,
        'event_artist_id' => (int)$row['event_artist_id'],
        'artist_id' => isset($row['artist_id']) ? (int)$row['artist_id'] : null,
        'artist_stage_name' => $row['artist_stage_name'] ?? null,
        'booking_status' => $row['booking_status'] ?? null,
        'landing_at' => $row['landing_at'] ?? null,
        'airport_out_at' => $row['airport_out_at'] ?? null,
        'hotel_arrival_at' => $row['hotel_arrival_at'] ?? null,
        'venue_arrival_at' => $row['venue_arrival_at'] ?? null,
        'soundcheck_at' => $row['soundcheck_at'] ?? null,
        'show_start_at' => $row['show_start_at'] ?? null,
        'show_end_at' => $row['show_end_at'] ?? null,
        'venue_exit_at' => $row['venue_exit_at'] ?? null,
        'next_departure_deadline_at' => $row['next_departure_deadline_at'] ?? null,
        'timeline_status' => $row['timeline_status'] ?? null,
        'current_severity' => $severity,
        'transfers_count' => isset($row['transfers_count']) ? (int)$row['transfers_count'] : 0,
        'open_alerts_count' => isset($row['open_alerts_count']) ? (int)$row['open_alerts_count'] : 0,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function artistHydrateTransferRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => (int)$row['event_id'],
        'event_name' => $row['event_name'] ?? null,
        'event_artist_id' => (int)$row['event_artist_id'],
        'artist_id' => isset($row['artist_id']) ? (int)$row['artist_id'] : null,
        'artist_stage_name' => $row['artist_stage_name'] ?? null,
        'booking_status' => $row['booking_status'] ?? null,
        'route_code' => $row['route_code'],
        'route_phase' => artistResolveTransferPhase(
            $row['route_code'] ?? null,
            $row['origin_label'] ?? null,
            $row['destination_label'] ?? null
        ),
        'origin_label' => $row['origin_label'],
        'destination_label' => $row['destination_label'],
        'eta_base_minutes' => isset($row['eta_base_minutes']) ? (int)$row['eta_base_minutes'] : 0,
        'eta_peak_minutes' => $row['eta_peak_minutes'] !== null ? (int)$row['eta_peak_minutes'] : null,
        'buffer_minutes' => isset($row['buffer_minutes']) ? (int)$row['buffer_minutes'] : 0,
        'planned_eta_minutes' => isset($row['planned_eta_minutes']) ? (int)$row['planned_eta_minutes'] : 0,
        'notes' => $row['notes'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function artistHydrateAlertRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => (int)$row['event_id'],
        'event_name' => $row['event_name'] ?? null,
        'event_artist_id' => (int)$row['event_artist_id'],
        'artist_id' => isset($row['artist_id']) ? (int)$row['artist_id'] : null,
        'artist_stage_name' => $row['artist_stage_name'] ?? null,
        'booking_status' => $row['booking_status'] ?? null,
        'timeline_id' => $row['timeline_id'] !== null ? (int)$row['timeline_id'] : null,
        'alert_type' => $row['alert_type'],
        'severity' => $row['severity'],
        'status' => $row['status'],
        'title' => $row['title'],
        'message' => $row['message'],
        'recommended_action' => $row['recommended_action'],
        'triggered_at' => $row['triggered_at'],
        'resolved_at' => $row['resolved_at'],
        'resolution_notes' => $row['resolution_notes'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function artistHydrateTeamMemberRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => (int)$row['event_id'],
        'event_name' => $row['event_name'] ?? null,
        'event_artist_id' => (int)$row['event_artist_id'],
        'artist_id' => isset($row['artist_id']) ? (int)$row['artist_id'] : null,
        'artist_stage_name' => $row['artist_stage_name'] ?? null,
        'booking_status' => $row['booking_status'] ?? null,
        'full_name' => $row['full_name'],
        'role_name' => $row['role_name'],
        'document_number' => $row['document_number'],
        'phone' => $row['phone'],
        'needs_hotel' => artistNormalizeBoolean($row['needs_hotel'], false),
        'needs_transfer' => artistNormalizeBoolean($row['needs_transfer'], false),
        'notes' => $row['notes'],
        'is_active' => artistNormalizeBoolean($row['is_active'], true),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function artistHydrateFileRow(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'organizer_id' => (int)$row['organizer_id'],
        'event_id' => (int)$row['event_id'],
        'event_name' => $row['event_name'] ?? null,
        'event_artist_id' => (int)$row['event_artist_id'],
        'artist_id' => isset($row['artist_id']) ? (int)$row['artist_id'] : null,
        'artist_stage_name' => $row['artist_stage_name'] ?? null,
        'booking_status' => $row['booking_status'] ?? null,
        'file_type' => $row['file_type'],
        'original_name' => $row['original_name'],
        'storage_path' => $row['storage_path'],
        'mime_type' => $row['mime_type'],
        'file_size_bytes' => $row['file_size_bytes'] !== null ? (int)$row['file_size_bytes'] : null,
        'notes' => $row['notes'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}
