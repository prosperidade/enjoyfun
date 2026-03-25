<?php

function listArtistLogistics(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $eventArtistId = artistNormalizeOptionalInt($query['event_artist_id'] ?? null);

    $countSql = "
        SELECT COUNT(*)
        FROM public.artist_logistics l
        JOIN public.event_artists ea
          ON ea.id = l.event_artist_id
         AND ea.organizer_id = l.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE l.organizer_id = :organizer_id
          AND l.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
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
          AND l.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(l.hotel_name, '')) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(a.stage_name) LIKE LOWER(:search) OR LOWER(COALESCE(l.hotel_name, '')) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($eventArtistId !== null) {
        $countSql .= " AND l.event_artist_id = :event_artist_id";
        $dataSql .= " AND l.event_artist_id = :event_artist_id";
        $countParams[':event_artist_id'] = $eventArtistId;
        $dataParams[':event_artist_id'] = $eventArtistId;
    }

    $dataSql .= "
        ORDER BY COALESCE(l.arrival_at, l.created_at) ASC, a.stage_name ASC
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
        $items[] = artistHydrateLogisticsRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Logisticas carregadas com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtistLogistics(array $body): void
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
        INSERT INTO public.artist_logistics (
            organizer_id,
            event_id,
            event_artist_id,
            arrival_origin,
            arrival_mode,
            arrival_reference,
            arrival_at,
            hotel_name,
            hotel_address,
            hotel_check_in_at,
            hotel_check_out_at,
            venue_arrival_at,
            departure_destination,
            departure_mode,
            departure_reference,
            departure_at,
            hospitality_notes,
            transport_notes,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :event_artist_id,
            :arrival_origin,
            :arrival_mode,
            :arrival_reference,
            :arrival_at,
            :hotel_name,
            :hotel_address,
            :hotel_check_in_at,
            :hotel_check_out_at,
            :venue_arrival_at,
            :departure_destination,
            :departure_mode,
            :departure_reference,
            :departure_at,
            :hospitality_notes,
            :transport_notes,
            NOW(),
            NOW()
        )
        RETURNING id
    ");

    try {
        $stmt->execute(artistBuildLogisticsMutationPayload($organizerId, $eventId, $eventArtistId, $body));
    } catch (Throwable $error) {
        if (artistIsUniqueViolation($error)) {
            jsonError('Ja existe logistica cadastrada para este booking.', 409);
        }
        throw $error;
    }

    $logisticsId = (int)$stmt->fetchColumn();
    jsonSuccess(artistRequireLogisticsById($db, $organizerId, $logisticsId), 'Logistica criada com sucesso.', 201);
}

function getArtistLogistics(int $logisticsId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $logistics = artistRequireLogisticsById($db, $organizerId, $logisticsId);
    artistSetCurrentEventId((int)$logistics['event_id']);
    jsonSuccess($logistics, 'Logistica carregada com sucesso.');
}

function updateArtistLogistics(int $logisticsId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireLogisticsById($db, $organizerId, $logisticsId);
    artistSetCurrentEventId((int)$current['event_id']);

    if (array_key_exists('event_id', $body) && (int)$body['event_id'] !== (int)$current['event_id']) {
        jsonError('event_id nao pode ser alterado em logistica existente.', 422);
    }
    if (array_key_exists('event_artist_id', $body) && (int)$body['event_artist_id'] !== (int)$current['event_artist_id']) {
        jsonError('event_artist_id nao pode ser alterado em logistica existente.', 422);
    }

    $stmt = $db->prepare("
        UPDATE public.artist_logistics
        SET
            arrival_origin = :arrival_origin,
            arrival_mode = :arrival_mode,
            arrival_reference = :arrival_reference,
            arrival_at = :arrival_at,
            hotel_name = :hotel_name,
            hotel_address = :hotel_address,
            hotel_check_in_at = :hotel_check_in_at,
            hotel_check_out_at = :hotel_check_out_at,
            venue_arrival_at = :venue_arrival_at,
            departure_destination = :departure_destination,
            departure_mode = :departure_mode,
            departure_reference = :departure_reference,
            departure_at = :departure_at,
            hospitality_notes = :hospitality_notes,
            transport_notes = :transport_notes,
            updated_at = NOW()
        WHERE id = :logistics_id
          AND organizer_id = :organizer_id
    ");
    $payload = artistBuildLogisticsMutationPayload($organizerId, (int)$current['event_id'], (int)$current['event_artist_id'], $body, $current);
    $payload[':logistics_id'] = $logisticsId;
    $stmt->execute($payload);

    jsonSuccess(artistRequireLogisticsById($db, $organizerId, $logisticsId), 'Logistica atualizada com sucesso.');
}

function listArtistLogisticsItems(array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $query['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);
    $pagination = artistNormalizePagination($query);
    $search = artistNormalizeOptionalText($query['search'] ?? null, 200);
    $status = artistNormalizeOptionalText($query['status'] ?? null, 30);
    $eventArtistId = artistNormalizeOptionalInt($query['event_artist_id'] ?? null);
    $itemType = artistNormalizeOptionalText($query['item_type'] ?? null, 50);

    $countSql = "
        SELECT COUNT(*)
        FROM public.artist_logistics_items i
        JOIN public.event_artists ea
          ON ea.id = i.event_artist_id
         AND ea.organizer_id = i.organizer_id
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE i.organizer_id = :organizer_id
          AND i.event_id = :event_id
    ";
    $countParams = [
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ];

    $dataSql = "
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
          AND i.event_id = :event_id
    ";
    $dataParams = $countParams;

    if ($search !== null) {
        $countSql .= " AND (LOWER(i.description) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $dataSql .= " AND (LOWER(i.description) LIKE LOWER(:search) OR LOWER(a.stage_name) LIKE LOWER(:search))";
        $countParams[':search'] = '%' . $search . '%';
        $dataParams[':search'] = '%' . $search . '%';
    }

    if ($status !== null) {
        $countSql .= " AND i.status = :status";
        $dataSql .= " AND i.status = :status";
        $countParams[':status'] = $status;
        $dataParams[':status'] = $status;
    }

    if ($eventArtistId !== null) {
        $countSql .= " AND i.event_artist_id = :event_artist_id";
        $dataSql .= " AND i.event_artist_id = :event_artist_id";
        $countParams[':event_artist_id'] = $eventArtistId;
        $dataParams[':event_artist_id'] = $eventArtistId;
    }

    if ($itemType !== null) {
        $countSql .= " AND i.item_type = :item_type";
        $dataSql .= " AND i.item_type = :item_type";
        $countParams[':item_type'] = $itemType;
        $dataParams[':item_type'] = $itemType;
    }

    $dataSql .= "
        ORDER BY i.created_at DESC, i.id DESC
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
        $items[] = artistHydrateLogisticsItemRow($row);
    }

    artistJsonSuccessWithMeta(
        $items,
        'Itens de logistica carregados com sucesso.',
        artistBuildPaginationMeta($pagination['page'], $pagination['per_page'], $total)
    );
}

function createArtistLogisticsItem(array $body): void
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
    $logisticsId = artistResolveOptionalLogisticsHeaderId($db, $organizerId, $body['artist_logistics_id'] ?? null, $eventArtistId);

    $itemType = artistNormalizeOptionalText($body['item_type'] ?? null, 50);
    $description = artistNormalizeOptionalText($body['description'] ?? null, 255);
    if ($itemType === null || $description === null) {
        jsonError('item_type e description sao obrigatorios.', 422);
    }

    $amounts = artistResolveLogisticsItemAmounts($body);

    $stmt = $db->prepare("
        INSERT INTO public.artist_logistics_items (
            organizer_id,
            event_id,
            event_artist_id,
            artist_logistics_id,
            item_type,
            description,
            quantity,
            unit_amount,
            total_amount,
            currency_code,
            supplier_name,
            notes,
            status,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :event_artist_id,
            :artist_logistics_id,
            :item_type,
            :description,
            :quantity,
            :unit_amount,
            :total_amount,
            :currency_code,
            :supplier_name,
            :notes,
            :status,
            NOW(),
            NOW()
        )
        RETURNING id
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':event_artist_id' => $eventArtistId,
        ':artist_logistics_id' => $logisticsId,
        ':item_type' => $itemType,
        ':description' => $description,
        ':quantity' => $amounts['quantity'],
        ':unit_amount' => $amounts['unit_amount'],
        ':total_amount' => $amounts['total_amount'],
        ':currency_code' => artistNormalizeOptionalText($body['currency_code'] ?? null, 10),
        ':supplier_name' => artistNormalizeOptionalText($body['supplier_name'] ?? null, 200),
        ':notes' => artistNormalizeOptionalText($body['notes'] ?? null),
        ':status' => artistNormalizeOptionalText($body['status'] ?? null, 30) ?? 'pending',
    ]);

    $itemId = (int)$stmt->fetchColumn();
    jsonSuccess(artistRequireLogisticsItemById($db, $organizerId, $itemId), 'Item de logistica criado com sucesso.', 201);
}

function getArtistLogisticsItem(int $itemId): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $item = artistRequireLogisticsItemById($db, $organizerId, $itemId);
    artistSetCurrentEventId((int)$item['event_id']);
    jsonSuccess($item, 'Item de logistica carregado com sucesso.');
}

function updateArtistLogisticsItem(int $itemId, array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireLogisticsItemById($db, $organizerId, $itemId);
    artistSetCurrentEventId((int)$current['event_id']);

    if (array_key_exists('event_id', $body) && (int)$body['event_id'] !== (int)$current['event_id']) {
        jsonError('event_id nao pode ser alterado em item de logistica existente.', 422);
    }
    if (array_key_exists('event_artist_id', $body) && (int)$body['event_artist_id'] !== (int)$current['event_artist_id']) {
        jsonError('event_artist_id nao pode ser alterado em item de logistica existente.', 422);
    }

    $logisticsId = array_key_exists('artist_logistics_id', $body)
        ? artistResolveOptionalLogisticsHeaderId($db, $organizerId, $body['artist_logistics_id'], (int)$current['event_artist_id'])
        : artistNormalizeOptionalInt($current['artist_logistics_id']);

    $itemType = array_key_exists('item_type', $body)
        ? artistNormalizeOptionalText($body['item_type'], 50)
        : $current['item_type'];
    $description = array_key_exists('description', $body)
        ? artistNormalizeOptionalText($body['description'], 255)
        : $current['description'];
    if ($itemType === null || $description === null) {
        jsonError('item_type e description sao obrigatorios.', 422);
    }

    $amounts = artistResolveLogisticsItemAmounts($body, $current);

    $stmt = $db->prepare("
        UPDATE public.artist_logistics_items
        SET
            artist_logistics_id = :artist_logistics_id,
            item_type = :item_type,
            description = :description,
            quantity = :quantity,
            unit_amount = :unit_amount,
            total_amount = :total_amount,
            currency_code = :currency_code,
            supplier_name = :supplier_name,
            notes = :notes,
            status = :status,
            updated_at = NOW()
        WHERE id = :item_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':artist_logistics_id' => $logisticsId,
        ':item_type' => $itemType,
        ':description' => $description,
        ':quantity' => $amounts['quantity'],
        ':unit_amount' => $amounts['unit_amount'],
        ':total_amount' => $amounts['total_amount'],
        ':currency_code' => array_key_exists('currency_code', $body)
            ? artistNormalizeOptionalText($body['currency_code'], 10)
            : $current['currency_code'],
        ':supplier_name' => array_key_exists('supplier_name', $body)
            ? artistNormalizeOptionalText($body['supplier_name'], 200)
            : $current['supplier_name'],
        ':notes' => array_key_exists('notes', $body)
            ? artistNormalizeOptionalText($body['notes'])
            : $current['notes'],
        ':status' => array_key_exists('status', $body)
            ? artistNormalizeOptionalText($body['status'], 30)
            : $current['status'],
        ':item_id' => $itemId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(artistRequireLogisticsItemById($db, $organizerId, $itemId), 'Item de logistica atualizado com sucesso.');
}

function deleteArtistLogisticsItem(int $itemId): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $current = artistRequireLogisticsItemById($db, $organizerId, $itemId);
    artistSetCurrentEventId((int)$current['event_id']);

    $stmt = $db->prepare("
        DELETE FROM public.artist_logistics_items
        WHERE id = :item_id
          AND organizer_id = :organizer_id
    ");
    $stmt->execute([
        ':item_id' => $itemId,
        ':organizer_id' => $organizerId,
    ]);

    jsonSuccess(['id' => $itemId], 'Item de logistica removido com sucesso.');
}

