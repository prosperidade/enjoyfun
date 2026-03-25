<?php

function listArtistTimelines(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $eventArtistId = artistNormalizeOptionalInt($query['event_artist_id'] ?? null);
    $timelineStatus = artistNormalizeOptionalText($query['timeline_status'] ?? $query['status'] ?? null, 30);

    $countSql = "
        SELECT COUNT(*)
        FROM public.artist_operational_timelines t
        JOIN public.event_artists ea
          ON ea.id = t.event_artist_id
         AND ea.organizer_id = t.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE t.organizer_id = :organizer_id
          AND t.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
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
            t.updated_at,
            COALESCE(transfers.transfers_count, 0) AS transfers_count,
            COALESCE(alerts.open_alerts_count, 0) AS open_alerts_count,
            COALESCE(alerts.current_severity_rank, 0) AS current_severity_rank
        FROM public.artist_operational_timelines t
        JOIN public.events e
          ON e.id = t.event_id
        JOIN public.event_artists ea
          ON ea.id = t.event_artist_id
         AND ea.organizer_id = t.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        LEFT JOIN (
            SELECT event_artist_id, COUNT(*) AS transfers_count
            FROM public.artist_transfer_estimations
            GROUP BY event_artist_id
        ) transfers
          ON transfers.event_artist_id = t.event_artist_id
        LEFT JOIN (
            SELECT
                event_artist_id,
                COUNT(*) FILTER (WHERE status IN ('open', 'acknowledged')) AS open_alerts_count,
                MAX(
                    CASE severity
                        WHEN 'red' THEN 4
                        WHEN 'orange' THEN 3
                        WHEN 'yellow' THEN 2
                        WHEN 'gray' THEN 1
                        ELSE 0
                    END
                ) FILTER (WHERE status IN ('open', 'acknowledged')) AS current_severity_rank
            FROM public.artist_operational_alerts
            GROUP BY event_artist_id
        ) alerts
          ON alerts.event_artist_id = t.event_artist_id
        WHERE t.organizer_id = :organizer_id
          AND t.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(t.timeline_status, '')) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(t.timeline_status, '')) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($eventArtistId !== null) {
        $countSql .= " AND t.event_artist_id = :event_artist_id";
        $dataSql .= " AND t.event_artist_id = :event_artist_id";
        $countParams[':event_artist_id'] = $eventArtistId;
        $dataParams[':event_artist_id'] = $eventArtistId;
    }

    if ($timelineStatus !== null) {
        $countSql .= " AND t.timeline_status = :timeline_status";
        $dataSql .= " AND t.timeline_status = :timeline_status";
        $countParams[':timeline_status'] = $timelineStatus;
        $dataParams[':timeline_status'] = $timelineStatus;
    }

    $dataSql .= "
        ORDER BY COALESCE(t.show_start_at, t.soundcheck_at, t.created_at) ASC, a.stage_name ASC
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
        $items[] = artistHydrateTimelineRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Timelines operacionais carregadas com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtistTimeline(array $body): void
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

    $stmt = $db->prepare("
        INSERT INTO public.artist_operational_timelines (
            organizer_id,
            event_id,
            event_artist_id,
            landing_at,
            airport_out_at,
            hotel_arrival_at,
            venue_arrival_at,
            soundcheck_at,
            show_start_at,
            show_end_at,
            venue_exit_at,
            next_departure_deadline_at,
            timeline_status,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :event_artist_id,
            :landing_at,
            :airport_out_at,
            :hotel_arrival_at,
            :venue_arrival_at,
            :soundcheck_at,
            :show_start_at,
            :show_end_at,
            :venue_exit_at,
            :next_departure_deadline_at,
            NULL,
            NOW(),
            NOW()
        )
        RETURNING id
    ");

    try {
        $stmt->execute(array_merge([
            ':organizer_id' => $organizerId,
            ':event_id' => $eventId,
            ':event_artist_id' => $eventArtistId,
        ], artistBuildTimelineMutationPayload($body)));
    } catch (Throwable $error) {
        if (artistIsUniqueViolation($error)) {
            jsonError('Ja existe timeline operacional para este booking.', 409);
        }
        throw $error;
    }

    $timelineId = (int)$stmt->fetchColumn();
    $timeline = artistRecalculateTimelineAndAlertsByTimelineId($db, $organizerId, $timelineId);
    jsonSuccess($timeline, 'Timeline operacional criada com sucesso.', 201);
}

function getArtistTimeline(int $timelineId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $timeline = artistRequireTimelineById($db, $organizerId, $timelineId);
    artistSetCurrentEventId((int)$timeline['event_id']);
    jsonSuccess($timeline, 'Timeline operacional carregada com sucesso.');
}

function updateArtistTimeline(int $timelineId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireTimelineById($db, $organizerId, $timelineId);
    artistSetCurrentEventId((int)$current['event_id']);

    if (array_key_exists('event_id', $body) && (int)$body['event_id'] !== (int)$current['event_id']) {
        jsonError('event_id nao pode ser alterado em timeline existente.', 422);
    }
    if (array_key_exists('event_artist_id', $body) && (int)$body['event_artist_id'] !== (int)$current['event_artist_id']) {
        jsonError('event_artist_id nao pode ser alterado em timeline existente.', 422);
    }

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
            updated_at = NOW()
        WHERE id = :timeline_id
          AND organizer_id = :organizer_id
    ");
    $payload = artistBuildTimelineMutationPayload($body, $current);
    $payload[':timeline_id'] = $timelineId;
    $payload[':organizer_id'] = $organizerId;
    $stmt->execute($payload);

    $timeline = artistRecalculateTimelineAndAlertsByTimelineId($db, $organizerId, $timelineId);
    jsonSuccess($timeline, 'Timeline operacional atualizada com sucesso.');
}

function recalculateArtistTimeline(int $timelineId): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $timeline = artistRecalculateTimelineAndAlertsByTimelineId($db, $organizerId, $timelineId);
    artistSetCurrentEventId((int)$timeline['event_id']);
    jsonSuccess($timeline, 'Timeline operacional recalculada com sucesso.');
}

function listArtistTransfers(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $eventArtistId = artistNormalizeOptionalInt($query['event_artist_id'] ?? null);
    $routeCode = artistNormalizeOptionalText($query['route_code'] ?? null, 50);

    $countSql = "
        SELECT COUNT(*)
        FROM public.artist_transfer_estimations tr
        JOIN public.event_artists ea
          ON ea.id = tr.event_artist_id
         AND ea.organizer_id = tr.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE tr.organizer_id = :organizer_id
          AND tr.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
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
          AND tr.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(tr.origin_label) LIKE LOWER(:search) OR LOWER(tr.destination_label) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(tr.origin_label) LIKE LOWER(:search) OR LOWER(tr.destination_label) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($eventArtistId !== null) {
        $countSql .= " AND tr.event_artist_id = :event_artist_id";
        $dataSql .= " AND tr.event_artist_id = :event_artist_id";
        $countParams[':event_artist_id'] = $eventArtistId;
        $dataParams[':event_artist_id'] = $eventArtistId;
    }

    if ($routeCode !== null) {
        $countSql .= " AND tr.route_code = :route_code";
        $dataSql .= " AND tr.route_code = :route_code";
        $countParams[':route_code'] = $routeCode;
        $dataParams[':route_code'] = $routeCode;
    }

    $dataSql .= "
        ORDER BY tr.created_at DESC, tr.id DESC
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
        $items[] = artistHydrateTransferRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Estimativas de transfer carregadas com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtistTransfer(array $body): void
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
    $payload = artistBuildTransferMutationPayload($body);

    $stmt = $db->prepare("
        INSERT INTO public.artist_transfer_estimations (
            organizer_id,
            event_id,
            event_artist_id,
            route_code,
            origin_label,
            destination_label,
            eta_base_minutes,
            eta_peak_minutes,
            buffer_minutes,
            planned_eta_minutes,
            notes,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :event_artist_id,
            :route_code,
            :origin_label,
            :destination_label,
            :eta_base_minutes,
            :eta_peak_minutes,
            :buffer_minutes,
            :planned_eta_minutes,
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
        ':route_code' => $payload['route_code'],
        ':origin_label' => $payload['origin_label'],
        ':destination_label' => $payload['destination_label'],
        ':eta_base_minutes' => $payload['eta_base_minutes'],
        ':eta_peak_minutes' => $payload['eta_peak_minutes'],
        ':buffer_minutes' => $payload['buffer_minutes'],
        ':planned_eta_minutes' => $payload['planned_eta_minutes'],
        ':notes' => $payload['notes'],
    ]);

    $transferId = (int)$stmt->fetchColumn();
    artistRecalculateAlertsForBooking($db, $organizerId, $eventArtistId);
    jsonSuccess(artistRequireTransferById($db, $organizerId, $transferId), 'Estimativa de transfer criada com sucesso.', 201);
}

function getArtistTransfer(int $transferId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $transfer = artistRequireTransferById($db, $organizerId, $transferId);
    artistSetCurrentEventId((int)$transfer['event_id']);
    jsonSuccess($transfer, 'Estimativa de transfer carregada com sucesso.');
}

function updateArtistTransfer(int $transferId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireTransferById($db, $organizerId, $transferId);
    artistSetCurrentEventId((int)$current['event_id']);

    if (array_key_exists('event_id', $body) && (int)$body['event_id'] !== (int)$current['event_id']) {
        jsonError('event_id nao pode ser alterado em transfer existente.', 422);
    }
    if (array_key_exists('event_artist_id', $body) && (int)$body['event_artist_id'] !== (int)$current['event_artist_id']) {
        jsonError('event_artist_id nao pode ser alterado em transfer existente.', 422);
    }

    $payload = artistBuildTransferMutationPayload($body, $current);

    $stmt = $db->prepare("
        UPDATE public.artist_transfer_estimations
        SET
            route_code = :route_code,
            origin_label = :origin_label,
            destination_label = :destination_label,
            eta_base_minutes = :eta_base_minutes,
            eta_peak_minutes = :eta_peak_minutes,
            buffer_minutes = :buffer_minutes,
            planned_eta_minutes = :planned_eta_minutes,
            notes = :notes,
            updated_at = NOW()
        WHERE id = :transfer_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':route_code' => $payload['route_code'],
        ':origin_label' => $payload['origin_label'],
        ':destination_label' => $payload['destination_label'],
        ':eta_base_minutes' => $payload['eta_base_minutes'],
        ':eta_peak_minutes' => $payload['eta_peak_minutes'],
        ':buffer_minutes' => $payload['buffer_minutes'],
        ':planned_eta_minutes' => $payload['planned_eta_minutes'],
        ':notes' => $payload['notes'],
        ':transfer_id' => $transferId,
        ':organizer_id' => $organizerId,
    ]);

    artistRecalculateAlertsForBooking($db, $organizerId, (int)$current['event_artist_id']);
    jsonSuccess(artistRequireTransferById($db, $organizerId, $transferId), 'Estimativa de transfer atualizada com sucesso.');
}

function deleteArtistTransfer(int $transferId): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireTransferById($db, $organizerId, $transferId);
    artistSetCurrentEventId((int)$current['event_id']);

    $stmt = $db->prepare("
        DELETE FROM public.artist_transfer_estimations
        WHERE id = :transfer_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':transfer_id' => $transferId,
        ':organizer_id' => $organizerId,
    ]);

    artistRecalculateAlertsForBooking($db, $organizerId, (int)$current['event_artist_id']);
    jsonSuccess(['id' => $transferId], 'Estimativa de transfer removida com sucesso.');
}

function listArtistAlerts(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $eventArtistId = artistNormalizeOptionalInt($query['event_artist_id'] ?? null);
    $timelineId = artistNormalizeOptionalInt($query['timeline_id'] ?? null);
    $severity = artistNormalizeOptionalText($query['severity'] ?? null, 20);
    $status = artistNormalizeOptionalText($query['status'] ?? null, 20);

    $countSql = "
        SELECT COUNT(*)
        FROM public.artist_operational_alerts al
        JOIN public.event_artists ea
          ON ea.id = al.event_artist_id
         AND ea.organizer_id = al.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE al.organizer_id = :organizer_id
          AND al.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
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
          AND al.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(al.title) LIKE LOWER(:search) OR LOWER(al.message) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(al.title) LIKE LOWER(:search) OR LOWER(al.message) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($eventArtistId !== null) {
        $countSql .= " AND al.event_artist_id = :event_artist_id";
        $dataSql .= " AND al.event_artist_id = :event_artist_id";
        $countParams[':event_artist_id'] = $eventArtistId;
        $dataParams[':event_artist_id'] = $eventArtistId;
    }

    if ($timelineId !== null) {
        $countSql .= " AND al.timeline_id = :timeline_id";
        $dataSql .= " AND al.timeline_id = :timeline_id";
        $countParams[':timeline_id'] = $timelineId;
        $dataParams[':timeline_id'] = $timelineId;
    }

    if ($severity !== null) {
        $severity = strtolower($severity);
        $severity = match ($severity) {
            'critical' => 'red',
            'high' => 'orange',
            default => $severity,
        };
        $countSql .= " AND al.severity = :severity";
        $dataSql .= " AND al.severity = :severity";
        $countParams[':severity'] = $severity;
        $dataParams[':severity'] = $severity;
    }

    if ($status !== null) {
        $status = strtolower($status);
        if ($status === 'active') {
            $countSql .= " AND al.status IN ('open', 'acknowledged')";
            $dataSql .= " AND al.status IN ('open', 'acknowledged')";
        } else {
            $countSql .= " AND al.status = :status";
            $dataSql .= " AND al.status = :status";
            $countParams[':status'] = $status;
            $dataParams[':status'] = $status;
        }
    }

    $dataSql .= "
        ORDER BY
            CASE al.severity
                WHEN 'red' THEN 4
                WHEN 'orange' THEN 3
                WHEN 'yellow' THEN 2
                WHEN 'gray' THEN 1
                ELSE 0
            END DESC,
            al.triggered_at DESC,
            al.id DESC
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
        $items[] = artistHydrateAlertRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Alertas operacionais carregados com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function getArtistAlert(int $alertId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $alert = artistRequireAlertById($db, $organizerId, $alertId);
    artistSetCurrentEventId((int)$alert['event_id']);
    jsonSuccess($alert, 'Alerta operacional carregado com sucesso.');
}

function updateArtistAlert(int $alertId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireAlertById($db, $organizerId, $alertId);
    artistSetCurrentEventId((int)$current['event_id']);

    $status = array_key_exists('status', $body)
        ? artistNormalizeAlertStatus($body['status'], true)
        : $current['status'];
    $resolutionNotes = array_key_exists('resolution_notes', $body)
        ? artistNormalizeOptionalText($body['resolution_notes'])
        : $current['resolution_notes'];
    $resolvedAt = artistResolveAlertResolvedAt($status, $current['resolved_at']);

    $stmt = $db->prepare("
        UPDATE public.artist_operational_alerts
        SET
            status = :status,
            resolution_notes = :resolution_notes,
            resolved_at = :resolved_at,
            updated_at = NOW()
        WHERE id = :alert_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':resolution_notes' => $resolutionNotes,
        ':resolved_at' => $resolvedAt,
        ':alert_id' => $alertId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireAlertById($db, $organizerId, $alertId), 'Alerta operacional atualizado com sucesso.');
}

function acknowledgeArtistAlert(int $alertId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireAlertById($db, $organizerId, $alertId);
    artistSetCurrentEventId((int)$current['event_id']);

    $notes = artistNormalizeOptionalText($body['resolution_notes'] ?? $body['notes'] ?? null) ?? $current['resolution_notes'];
    $stmt = $db->prepare("
        UPDATE public.artist_operational_alerts
        SET
            status = 'acknowledged',
            resolution_notes = :resolution_notes,
            resolved_at = NULL,
            updated_at = NOW()
        WHERE id = :alert_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':resolution_notes' => $notes,
        ':alert_id' => $alertId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireAlertById($db, $organizerId, $alertId), 'Alerta operacional reconhecido com sucesso.');
}

function resolveArtistAlert(int $alertId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireAlertById($db, $organizerId, $alertId);
    artistSetCurrentEventId((int)$current['event_id']);

    $notes = artistNormalizeOptionalText($body['resolution_notes'] ?? $body['notes'] ?? null) ?? $current['resolution_notes'];
    $stmt = $db->prepare("
        UPDATE public.artist_operational_alerts
        SET
            status = 'resolved',
            resolution_notes = :resolution_notes,
            resolved_at = NOW(),
            updated_at = NOW()
        WHERE id = :alert_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':resolution_notes' => $notes,
        ':alert_id' => $alertId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireAlertById($db, $organizerId, $alertId), 'Alerta operacional resolvido com sucesso.');
}

function recalculateArtistAlerts(array $body, array $query): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $rawEventId = $body['event_id'] ?? $query['event_id'] ?? null;
    $eventId = artistResolveEventId($db, $organizerId, $rawEventId, true);
    artistSetCurrentEventId($eventId);

    $eventArtistId = artistNormalizeOptionalInt($body['event_artist_id'] ?? $query['event_artist_id'] ?? null);
    $timelineId = artistNormalizeOptionalInt($body['timeline_id'] ?? $query['timeline_id'] ?? null);

    if ($timelineId !== null) {
        $timeline = artistRequireTimelineById($db, $organizerId, $timelineId);
        if ((int)$timeline['event_id'] !== $eventId) {
            jsonError('timeline_id nao pertence ao event_id informado.', 422);
        }
        $eventArtistId = (int)$timeline['event_artist_id'];
    }

    $summary = artistRecalculateAlertsForEvent($db, $organizerId, $eventId, $eventArtistId);
    jsonSuccess($summary, 'Alertas operacionais recalculados com sucesso.');
}

