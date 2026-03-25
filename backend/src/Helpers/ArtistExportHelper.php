<?php

function exportArtistOperation(array $body, array $query): void
{
    [$db, $organizerId] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    $format = strtolower(trim((string)($body['format'] ?? $query['format'] ?? 'csv')));
    if (!in_array($format, ['csv', 'docx'], true)) {
        jsonError('Formato de exportacao invalido. Use csv ou docx.', 422);
    }

    $artistId = artistNormalizeOptionalInt($body['artist_id'] ?? $query['artist_id'] ?? null);
    if ($artistId === null) {
        jsonError('artist_id e obrigatorio.', 422);
    }

    $eventId = artistResolveEventId($db, $organizerId, $body['event_id'] ?? $query['event_id'] ?? null, true);
    $eventArtistId = artistNormalizeOptionalInt($body['event_artist_id'] ?? $query['event_artist_id'] ?? null);
    artistSetCurrentEventId($eventId);

    $snapshot = artistBuildOperationExportSnapshot($db, $organizerId, $artistId, $eventId, $eventArtistId);
    $file = $format === 'docx'
        ? artistBuildOperationDocxExportFile($snapshot)
        : artistBuildOperationCsvExportFile($snapshot);

    jsonSuccess([
        'format' => $format,
        'filename' => $file['filename'],
        'mime_type' => $file['mime_type'],
        'file_size_bytes' => strlen($file['content']),
        'file_base64' => base64_encode($file['content']),
        'meta' => [
            'event_id' => $snapshot['booking']['event_id'],
            'event_artist_id' => $snapshot['booking']['id'],
            'artist_id' => $snapshot['artist']['id'],
            'alerts_count' => count($snapshot['alerts']),
            'team_count' => count($snapshot['team_members']),
            'files_count' => count($snapshot['files']),
            'logistics_items_count' => count($snapshot['logistics_items']),
        ],
    ], 'Exportacao da operacao do artista gerada com sucesso.');
}

function artistBuildOperationExportSnapshot(
    PDO $db,
    int $organizerId,
    int $artistId,
    int $eventId,
    ?int $eventArtistId = null
): array {
    $artist = artistRequireArtistById($db, $organizerId, $artistId);
    $booking = artistResolveExportBooking($db, $organizerId, $artistId, $eventId, $eventArtistId);

    $logistics = artistFindLogisticsByEventArtistId($db, $organizerId, (int)$booking['id']);
    $logisticsItems = artistFetchLogisticsItemsForBooking($db, $organizerId, (int)$booking['id']);
    $transfers = artistFetchTransfersForBooking($db, $organizerId, (int)$booking['id']);
    $alerts = artistFetchAlertsForBooking($db, $organizerId, (int)$booking['id']);
    $timelineBase = artistFindTimelineByEventArtistId($db, $organizerId, (int)$booking['id']);
    $teamMembers = artistExportFetchTeamMembersForBooking($db, $organizerId, (int)$booking['id']);
    $files = artistExportFetchFilesForBooking($db, $organizerId, (int)$booking['id']);

    if ($timelineBase !== null) {
        $timeline = artistRequireTimelineById($db, $organizerId, (int)$timelineBase['id']);
    } else {
        $computed = artistBuildOperationalComputationForBooking($booking, $logistics, null, $transfers);
        $timeline = [
            'id' => null,
            'organizer_id' => (int)$booking['organizer_id'],
            'event_id' => (int)$booking['event_id'],
            'event_name' => $booking['event_name'] ?? null,
            'event_artist_id' => (int)$booking['id'],
            'artist_id' => (int)$booking['artist_id'],
            'artist_stage_name' => $booking['artist_stage_name'] ?? null,
            'booking_status' => $booking['booking_status'] ?? null,
            'landing_at' => $computed['timeline']['landing_at'] ?? null,
            'airport_out_at' => $computed['timeline']['airport_out_at'] ?? null,
            'hotel_arrival_at' => $computed['timeline']['hotel_arrival_at'] ?? null,
            'venue_arrival_at' => $computed['timeline']['venue_arrival_at'] ?? null,
            'soundcheck_at' => $computed['timeline']['soundcheck_at'] ?? null,
            'show_start_at' => $computed['timeline']['show_start_at'] ?? null,
            'show_end_at' => $computed['timeline']['show_end_at'] ?? null,
            'venue_exit_at' => $computed['timeline']['venue_exit_at'] ?? null,
            'next_departure_deadline_at' => $computed['timeline']['next_departure_deadline_at'] ?? null,
            'timeline_status' => $computed['timeline']['timeline_status'] ?? null,
            'current_severity' => $computed['current_severity'],
            'transfers_count' => count($transfers),
            'open_alerts_count' => count(array_filter(
                $alerts,
                static fn (array $alert): bool => in_array($alert['status'], ['open', 'acknowledged'], true)
            )),
            'created_at' => null,
            'updated_at' => null,
            'derived_timeline' => $computed['timeline'],
            'computed_windows' => $computed['windows'],
            'transfers' => $transfers,
            'alerts' => $alerts,
        ];
    }

    $costSummary = artistExportSummarizeLogisticsItems($logisticsItems);

    return [
        'exported_at' => date('Y-m-d H:i:s'),
        'artist' => $artist,
        'booking' => $booking,
        'logistics' => $logistics,
        'logistics_items' => $logisticsItems,
        'logistics_cost_summary' => $costSummary,
        'timeline' => $timeline,
        'transfers' => is_array($timeline['transfers'] ?? null) ? $timeline['transfers'] : $transfers,
        'alerts' => is_array($timeline['alerts'] ?? null) ? $timeline['alerts'] : $alerts,
        'team_members' => $teamMembers,
        'files' => $files,
    ];
}

function artistResolveExportBooking(
    PDO $db,
    int $organizerId,
    int $artistId,
    int $eventId,
    ?int $eventArtistId = null
): array {
    if ($eventArtistId !== null) {
        $booking = artistRequireBookingById($db, $organizerId, $eventArtistId);
        if ((int)$booking['artist_id'] !== $artistId) {
            jsonError('event_artist_id nao pertence ao artista informado.', 422);
        }
        if ((int)$booking['event_id'] !== $eventId) {
            jsonError('event_artist_id nao pertence ao evento informado.', 422);
        }

        return $booking;
    }

    $bookings = artistFetchBookingsForArtist($db, $organizerId, $artistId, $eventId);
    if ($bookings === []) {
        jsonError('Nenhuma contratacao encontrada para este artista no evento informado.', 404);
    }

    return $bookings[0];
}

function artistExportFetchTeamMembersForBooking(PDO $db, int $organizerId, int $eventArtistId): array
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
          AND tm.event_artist_id = :event_artist_id
        ORDER BY tm.is_active DESC, tm.full_name ASC, tm.id ASC
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateTeamMemberRow($row);
    }

    return $items;
}

function artistExportFetchFilesForBooking(PDO $db, int $organizerId, int $eventArtistId): array
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
          AND f.event_artist_id = :event_artist_id
        ORDER BY f.created_at DESC, f.id DESC
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_artist_id' => $eventArtistId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = artistHydrateFileRow($row);
    }

    return $items;
}

function artistExportSummarizeLogisticsItems(array $items): array
{
    $overallTotal = 0.0;
    $groups = [];

    foreach ($items as $item) {
        $type = trim((string)($item['item_type'] ?? '')) ?: 'other';
        $totalAmount = artistExportResolveItemTotalAmount($item);
        $overallTotal += $totalAmount;

        if (!isset($groups[$type])) {
            $groups[$type] = [
                'item_type' => $type,
                'items_count' => 0,
                'total_amount' => 0.0,
            ];
        }

        $groups[$type]['items_count']++;
        $groups[$type]['total_amount'] += $totalAmount;
    }

    foreach ($groups as &$group) {
        $group['total_amount'] = round((float)$group['total_amount'], 2);
    }
    unset($group);

    usort($groups, static function (array $left, array $right): int {
        return ($right['total_amount'] <=> $left['total_amount'])
            ?: strcmp((string)$left['item_type'], (string)$right['item_type']);
    });

    return [
        'items_count' => count($items),
        'overall_total' => round($overallTotal, 2),
        'by_type' => $groups,
    ];
}

function artistExportResolveItemTotalAmount(array $item): float
{
    if (isset($item['total_amount']) && $item['total_amount'] !== null) {
        return round((float)$item['total_amount'], 2);
    }

    $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
    $unitAmount = isset($item['unit_amount']) && $item['unit_amount'] !== null
        ? (float)$item['unit_amount']
        : 0.0;

    return round($quantity * $unitAmount, 2);
}

function artistBuildOperationCsvExportFile(array $snapshot): array
{
    $rows = artistBuildOperationCsvRows($snapshot);
    $content = "\xEF\xBB\xBF" . artistExportBuildCsvString($rows);

    return [
        'filename' => artistExportBuildFilename($snapshot, 'csv'),
        'mime_type' => 'text/csv; charset=utf-8',
        'content' => $content,
    ];
}

function artistBuildOperationCsvRows(array $snapshot): array
{
    $rows = [];
    $artist = $snapshot['artist'];
    $booking = $snapshot['booking'];
    $logistics = $snapshot['logistics'] ?? [];
    $timeline = $snapshot['timeline'] ?? [];
    $derivedTimeline = $timeline['derived_timeline'] ?? $timeline;
    $windows = $timeline['computed_windows'] ?? [];
    $costSummary = $snapshot['logistics_cost_summary'] ?? ['overall_total' => 0.0, 'by_type' => []];

    $appendField = static function (string $section, string $field, mixed $value, string $item = '') use (&$rows): void {
        $rows[] = [
            'secao' => $section,
            'item' => $item,
            'campo' => $field,
            'valor' => artistExportStringifyValue($value),
        ];
    };

    $appendMap = static function (string $section, array $fields, string $item = '') use ($appendField): void {
        foreach ($fields as $field => $value) {
            $appendField($section, $field, $value, $item);
        }
    };

    $appendMap('geral', [
        'exportado_em' => $snapshot['exported_at'] ?? null,
        'artista' => $artist['stage_name'] ?? null,
        'nome_juridico' => $artist['legal_name'] ?? null,
        'evento' => $booking['event_name'] ?? null,
        'artist_id' => $artist['id'] ?? null,
        'event_id' => $booking['event_id'] ?? null,
        'event_artist_id' => $booking['id'] ?? null,
    ]);

    $appendMap('contratacao', [
        'status' => $booking['booking_status'] ?? null,
        'palco' => $booking['stage_name'] ?? null,
        'data_show' => $booking['performance_date'] ?? null,
        'inicio_show' => $booking['performance_start_at'] ?? null,
        'soundcheck' => $booking['soundcheck_at'] ?? null,
        'duracao_minutos' => $booking['performance_duration_minutes'] ?? null,
        'valor_contratacao' => $booking['cache_amount'] ?? null,
        'custo_logistico_total' => $costSummary['overall_total'] ?? null,
        'custo_total_artista' => $booking['total_artist_cost'] ?? null,
        'observacoes' => $booking['notes'] ?? null,
    ]);

    $appendMap('logistica', [
        'origem' => $logistics['arrival_origin'] ?? null,
        'modo_chegada' => $logistics['arrival_mode'] ?? null,
        'referencia_chegada' => $logistics['arrival_reference'] ?? null,
        'horario_chegada' => $logistics['arrival_at'] ?? null,
        'hotel' => $logistics['hotel_name'] ?? null,
        'endereco_hotel' => $logistics['hotel_address'] ?? null,
        'checkin_hotel' => $logistics['hotel_check_in_at'] ?? null,
        'checkout_hotel' => $logistics['hotel_check_out_at'] ?? null,
        'chegada_venue' => $logistics['venue_arrival_at'] ?? null,
        'destino_saida' => $logistics['departure_destination'] ?? null,
        'modo_saida' => $logistics['departure_mode'] ?? null,
        'referencia_saida' => $logistics['departure_reference'] ?? null,
        'horario_saida' => $logistics['departure_at'] ?? null,
        'hospitalidade' => $logistics['hospitality_notes'] ?? null,
        'transporte' => $logistics['transport_notes'] ?? null,
    ]);

    $appendMap('timeline', [
        'status_timeline' => $timeline['timeline_status'] ?? null,
        'severidade_atual' => $timeline['current_severity'] ?? null,
        'landing' => $derivedTimeline['landing_at'] ?? null,
        'airport_out' => $derivedTimeline['airport_out_at'] ?? null,
        'hotel_arrival' => $derivedTimeline['hotel_arrival_at'] ?? null,
        'venue_arrival' => $derivedTimeline['venue_arrival_at'] ?? null,
        'soundcheck' => $derivedTimeline['soundcheck_at'] ?? null,
        'show_start' => $derivedTimeline['show_start_at'] ?? null,
        'show_end' => $derivedTimeline['show_end_at'] ?? null,
        'venue_exit' => $derivedTimeline['venue_exit_at'] ?? null,
        'deadline_saida' => $derivedTimeline['next_departure_deadline_at'] ?? null,
    ]);

    foreach (['arrival_soundcheck', 'arrival_show', 'departure_deadline'] as $windowKey) {
        $window = $windows[$windowKey] ?? [];
        $appendMap('janela', [
            'predicted_at' => $window['predicted_at'] ?? null,
            'target_at' => $window['target_at'] ?? null,
            'margin_minutes' => $window['margin_minutes'] ?? null,
            'planned_eta_minutes' => $window['planned_eta_minutes'] ?? null,
            'source' => $window['source'] ?? null,
        ], $windowKey);
    }

    $appendMap('custos_resumo', [
        'itens' => $costSummary['items_count'] ?? 0,
        'total_geral' => $costSummary['overall_total'] ?? 0,
    ]);

    foreach (($costSummary['by_type'] ?? []) as $summaryIndex => $summary) {
        $appendMap('custos_resumo_tipo', [
            'tipo' => $summary['item_type'] ?? null,
            'itens' => $summary['items_count'] ?? 0,
            'total' => $summary['total_amount'] ?? 0,
        ], (string)($summaryIndex + 1));
    }

    foreach (($snapshot['logistics_items'] ?? []) as $index => $item) {
        $appendMap('custo_item', [
            'tipo' => $item['item_type'] ?? null,
            'descricao' => $item['description'] ?? null,
            'quantidade' => $item['quantity'] ?? null,
            'valor_unitario' => $item['unit_amount'] ?? null,
            'valor_total' => artistExportResolveItemTotalAmount($item),
            'fornecedor' => $item['supplier_name'] ?? null,
            'moeda' => $item['currency_code'] ?? null,
            'status' => $item['status'] ?? null,
            'observacoes' => $item['notes'] ?? null,
        ], (string)($index + 1));
    }

    foreach (($snapshot['team_members'] ?? []) as $index => $member) {
        $appendMap('equipe', [
            'nome' => $member['full_name'] ?? null,
            'funcao' => $member['role_name'] ?? null,
            'documento' => $member['document_number'] ?? null,
            'telefone' => $member['phone'] ?? null,
            'precisa_hotel' => artistExportBooleanLabel($member['needs_hotel'] ?? false),
            'precisa_transfer' => artistExportBooleanLabel($member['needs_transfer'] ?? false),
            'ativo' => artistExportBooleanLabel($member['is_active'] ?? true),
            'observacoes' => $member['notes'] ?? null,
        ], (string)($index + 1));
    }

    foreach (($snapshot['transfers'] ?? []) as $index => $transfer) {
        $appendMap('transfer', [
            'rota' => $transfer['route_code'] ?? null,
            'fase' => $transfer['route_phase'] ?? null,
            'origem' => $transfer['origin_label'] ?? null,
            'destino' => $transfer['destination_label'] ?? null,
            'eta_planejado_min' => $transfer['planned_eta_minutes'] ?? null,
            'eta_base_min' => $transfer['eta_base_minutes'] ?? null,
            'eta_pico_min' => $transfer['eta_peak_minutes'] ?? null,
            'buffer_min' => $transfer['buffer_minutes'] ?? null,
            'observacoes' => $transfer['notes'] ?? null,
        ], (string)($index + 1));
    }

    foreach (($snapshot['alerts'] ?? []) as $index => $alert) {
        $appendMap('alerta', [
            'severidade' => $alert['severity'] ?? null,
            'status' => $alert['status'] ?? null,
            'titulo' => $alert['title'] ?? null,
            'mensagem' => $alert['message'] ?? null,
            'acao_recomendada' => $alert['recommended_action'] ?? null,
            'disparado_em' => $alert['triggered_at'] ?? null,
            'resolvido_em' => $alert['resolved_at'] ?? null,
            'notas_resolucao' => $alert['resolution_notes'] ?? null,
        ], (string)($index + 1));
    }

    foreach (($snapshot['files'] ?? []) as $index => $file) {
        $appendMap('arquivo', [
            'tipo' => $file['file_type'] ?? null,
            'nome' => $file['original_name'] ?? null,
            'storage_path' => $file['storage_path'] ?? null,
            'mime_type' => $file['mime_type'] ?? null,
            'tamanho_bytes' => $file['file_size_bytes'] ?? null,
            'observacoes' => $file['notes'] ?? null,
        ], (string)($index + 1));
    }

    return $rows;
}

function artistExportBuildCsvString(array $rows): string
{
    if ($rows === []) {
        return "secao;item;campo;valor\n";
    }

    $headers = array_keys($rows[0]);
    $lines = [implode(';', $headers)];

    foreach ($rows as $row) {
        $cells = [];
        foreach ($headers as $header) {
            $cells[] = artistExportEscapeCsvCell($row[$header] ?? '');
        }
        $lines[] = implode(';', $cells);
    }

    return implode("\n", $lines);
}

function artistExportEscapeCsvCell(mixed $value): string
{
    $text = artistExportStringifyValue($value);
    $needsQuotes = str_contains($text, ';') || str_contains($text, "\n") || str_contains($text, '"');
    if ($needsQuotes) {
        return '"' . str_replace('"', '""', $text) . '"';
    }

    return $text;
}

function artistExportBuildFilename(array $snapshot, string $extension): string
{
    $artistSlug = artistExportSlug((string)($snapshot['artist']['stage_name'] ?? 'artista'), 'artista');
    $eventSlug = artistExportSlug((string)($snapshot['booking']['event_name'] ?? 'evento'), 'evento');
    $timestamp = date('Ymd_His');

    return 'operacao_artista_' . $artistSlug . '_' . $eventSlug . '_' . $timestamp . '.' . $extension;
}

function artistExportSlug(string $value, string $fallback): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/i', '_', $slug) ?? '';
    $slug = trim($slug, '_');

    return $slug !== '' ? $slug : $fallback;
}

function artistExportBooleanLabel(mixed $value): string
{
    return artistNormalizeBoolean($value, false) ? 'sim' : 'nao';
}

function artistExportStringifyValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_float($value)) {
        return number_format($value, 2, '.', '');
    }

    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
    }

    return trim((string)$value);
}

function artistBuildOperationDocxExportFile(array $snapshot): array
{
    $documentXml = artistBuildOperationDocxDocumentXml($snapshot);
    $contentTypesXml = artistBuildOperationDocxContentTypesXml();
    $relsXml = artistBuildOperationDocxRelationshipsXml();
    $coreXml = artistBuildOperationDocxCorePropsXml($snapshot);
    $appXml = artistBuildOperationDocxAppPropsXml();

    $content = artistBuildStoredZipBinary([
        '[Content_Types].xml' => $contentTypesXml,
        '_rels/.rels' => $relsXml,
        'docProps/core.xml' => $coreXml,
        'docProps/app.xml' => $appXml,
        'word/document.xml' => $documentXml,
    ]);

    return [
        'filename' => artistExportBuildFilename($snapshot, 'docx'),
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'content' => $content,
    ];
}

function artistBuildOperationDocxDocumentXml(array $snapshot): string
{
    $artist = $snapshot['artist'];
    $booking = $snapshot['booking'];
    $logistics = $snapshot['logistics'] ?? [];
    $timeline = $snapshot['timeline'] ?? [];
    $derivedTimeline = $timeline['derived_timeline'] ?? $timeline;
    $windows = $timeline['computed_windows'] ?? [];
    $costSummary = $snapshot['logistics_cost_summary'] ?? ['overall_total' => 0.0, 'by_type' => []];

    $body = [];
    $body[] = artistDocxParagraph(
        'Operacao do artista - ' . (($artist['stage_name'] ?? '') !== '' ? $artist['stage_name'] : 'Artista'),
        ['bold' => true, 'size' => 28]
    );
    $body[] = artistDocxParagraph(
        'Evento: ' . (($booking['event_name'] ?? '') !== '' ? $booking['event_name'] : ('Evento ' . ($booking['event_id'] ?? '')))
        . ' | Exportado em: ' . ($snapshot['exported_at'] ?? ''),
        ['size' => 20]
    );
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Resumo operacional', ['bold' => true, 'size' => 24]);
    $body[] = artistDocxTable(
        ['Campo', 'Valor'],
        [
            ['Artista', $artist['stage_name'] ?? '-'],
            ['Nome juridico', $artist['legal_name'] ?? '-'],
            ['Evento', $booking['event_name'] ?? '-'],
            ['Status da contratacao', $booking['booking_status'] ?? '-'],
            ['Palco', $booking['stage_name'] ?? '-'],
            ['Show', $booking['performance_start_at'] ?? '-'],
            ['Soundcheck', $booking['soundcheck_at'] ?? '-'],
            ['Chegada', $logistics['arrival_at'] ?? ($derivedTimeline['landing_at'] ?? '-')],
            ['Valor da contratacao', artistExportStringifyValue($booking['cache_amount'] ?? null)],
            ['Custo logistico total', artistExportStringifyValue($costSummary['overall_total'] ?? null)],
            ['Custo total artistico', artistExportStringifyValue($booking['total_artist_cost'] ?? null)],
            ['Severidade atual', $timeline['current_severity'] ?? '-'],
        ]
    );
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Logistica', ['bold' => true, 'size' => 24]);
    $body[] = artistDocxTable(
        ['Campo', 'Valor'],
        [
            ['Origem', $logistics['arrival_origin'] ?? '-'],
            ['Modo de chegada', $logistics['arrival_mode'] ?? '-'],
            ['CIA / voo / localizador', $logistics['arrival_reference'] ?? '-'],
            ['Horario de chegada', $logistics['arrival_at'] ?? '-'],
            ['Hotel', $logistics['hotel_name'] ?? '-'],
            ['Endereco do hotel', $logistics['hotel_address'] ?? '-'],
            ['Check-in hotel', $logistics['hotel_check_in_at'] ?? '-'],
            ['Check-out hotel', $logistics['hotel_check_out_at'] ?? '-'],
            ['Chegada no venue', $logistics['venue_arrival_at'] ?? '-'],
            ['Destino de saida', $logistics['departure_destination'] ?? '-'],
            ['Modo de saida', $logistics['departure_mode'] ?? '-'],
            ['Referencia de saida', $logistics['departure_reference'] ?? '-'],
            ['Horario de saida', $logistics['departure_at'] ?? '-'],
            ['Hospitalidade', $logistics['hospitality_notes'] ?? '-'],
            ['Transporte', $logistics['transport_notes'] ?? '-'],
        ]
    );
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Custos logisticos', ['bold' => true, 'size' => 24]);
    if (($costSummary['by_type'] ?? []) === []) {
        $body[] = artistDocxParagraph('Nenhum custo logistico cadastrado.', ['size' => 20]);
    } else {
        $summaryRows = [];
        foreach ($costSummary['by_type'] as $summary) {
            $summaryRows[] = [
                $summary['item_type'] ?? '-',
                (string)($summary['items_count'] ?? 0),
                artistExportStringifyValue($summary['total_amount'] ?? 0),
            ];
        }
        $body[] = artistDocxTable(['Tipo', 'Itens', 'Total'], $summaryRows);
        $body[] = artistDocxEmptyParagraph();
    }

    if (($snapshot['logistics_items'] ?? []) === []) {
        $body[] = artistDocxParagraph('Nenhum item detalhado de custo cadastrado.', ['size' => 20]);
    } else {
        $rows = [];
        foreach ($snapshot['logistics_items'] as $item) {
            $rows[] = [
                $item['item_type'] ?? '-',
                $item['description'] ?? '-',
                artistExportStringifyValue($item['quantity'] ?? null),
                artistExportStringifyValue($item['unit_amount'] ?? null),
                artistExportStringifyValue(artistExportResolveItemTotalAmount($item)),
                $item['supplier_name'] ?? '-',
            ];
        }
        $body[] = artistDocxTable(['Tipo', 'Descricao', 'Qtd', 'Unit.', 'Total', 'Fornecedor'], $rows);
    }
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Equipe do artista', ['bold' => true, 'size' => 24]);
    if (($snapshot['team_members'] ?? []) === []) {
        $body[] = artistDocxParagraph('Nenhum membro de equipe cadastrado.', ['size' => 20]);
    } else {
        $rows = [];
        foreach ($snapshot['team_members'] as $member) {
            $rows[] = [
                $member['full_name'] ?? '-',
                $member['role_name'] ?? '-',
                $member['phone'] ?? '-',
                artistExportBooleanLabel($member['needs_hotel'] ?? false),
                artistExportBooleanLabel($member['needs_transfer'] ?? false),
                artistExportBooleanLabel($member['is_active'] ?? true),
            ];
        }
        $body[] = artistDocxTable(['Nome', 'Funcao', 'Telefone', 'Hotel', 'Transfer', 'Ativo'], $rows);
    }
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Timeline operacional', ['bold' => true, 'size' => 24]);
    $body[] = artistDocxTable(
        ['Marco', 'Horario'],
        [
            ['Landing', $derivedTimeline['landing_at'] ?? '-'],
            ['Airport out', $derivedTimeline['airport_out_at'] ?? '-'],
            ['Hotel arrival', $derivedTimeline['hotel_arrival_at'] ?? '-'],
            ['Venue arrival', $derivedTimeline['venue_arrival_at'] ?? '-'],
            ['Soundcheck', $derivedTimeline['soundcheck_at'] ?? '-'],
            ['Show start', $derivedTimeline['show_start_at'] ?? '-'],
            ['Show end', $derivedTimeline['show_end_at'] ?? '-'],
            ['Venue exit', $derivedTimeline['venue_exit_at'] ?? '-'],
            ['Deadline saida', $derivedTimeline['next_departure_deadline_at'] ?? '-'],
        ]
    );
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Janelas calculadas', ['bold' => true, 'size' => 24]);
    $body[] = artistDocxTable(
        ['Janela', 'Previsto', 'Alvo', 'Margem (min)', 'ETA (min)', 'Origem'],
        [
            [
                'Chegada -> Soundcheck',
                $windows['arrival_soundcheck']['predicted_at'] ?? '-',
                $windows['arrival_soundcheck']['target_at'] ?? '-',
                artistExportStringifyValue($windows['arrival_soundcheck']['margin_minutes'] ?? null),
                artistExportStringifyValue($windows['arrival_soundcheck']['planned_eta_minutes'] ?? null),
                $windows['arrival_soundcheck']['source'] ?? '-',
            ],
            [
                'Chegada -> Show',
                $windows['arrival_show']['predicted_at'] ?? '-',
                $windows['arrival_show']['target_at'] ?? '-',
                artistExportStringifyValue($windows['arrival_show']['margin_minutes'] ?? null),
                artistExportStringifyValue($windows['arrival_show']['planned_eta_minutes'] ?? null),
                $windows['arrival_show']['source'] ?? '-',
            ],
            [
                'Saida -> Deadline',
                $windows['departure_deadline']['predicted_at'] ?? '-',
                $windows['departure_deadline']['target_at'] ?? '-',
                artistExportStringifyValue($windows['departure_deadline']['margin_minutes'] ?? null),
                artistExportStringifyValue($windows['departure_deadline']['planned_eta_minutes'] ?? null),
                $windows['departure_deadline']['source'] ?? '-',
            ],
        ]
    );
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Transfers', ['bold' => true, 'size' => 24]);
    if (($snapshot['transfers'] ?? []) === []) {
        $body[] = artistDocxParagraph('Nenhum transfer cadastrado.', ['size' => 20]);
    } else {
        $rows = [];
        foreach ($snapshot['transfers'] as $transfer) {
            $rows[] = [
                $transfer['route_code'] ?? '-',
                $transfer['route_phase'] ?? '-',
                ($transfer['origin_label'] ?? '-') . ' -> ' . ($transfer['destination_label'] ?? '-'),
                artistExportStringifyValue($transfer['planned_eta_minutes'] ?? null),
                artistExportStringifyValue($transfer['buffer_minutes'] ?? null),
            ];
        }
        $body[] = artistDocxTable(['Rota', 'Fase', 'Trecho', 'ETA', 'Buffer'], $rows);
    }
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Alertas operacionais', ['bold' => true, 'size' => 24]);
    if (($snapshot['alerts'] ?? []) === []) {
        $body[] = artistDocxParagraph('Nenhum alerta operacional registrado.', ['size' => 20]);
    } else {
        $rows = [];
        foreach ($snapshot['alerts'] as $alert) {
            $rows[] = [
                $alert['severity'] ?? '-',
                $alert['status'] ?? '-',
                $alert['title'] ?? '-',
                $alert['recommended_action'] ?? '-',
                $alert['triggered_at'] ?? '-',
            ];
        }
        $body[] = artistDocxTable(['Severidade', 'Status', 'Titulo', 'Acao sugerida', 'Disparado em'], $rows);
    }
    $body[] = artistDocxEmptyParagraph();

    $body[] = artistDocxParagraph('Arquivos operacionais', ['bold' => true, 'size' => 24]);
    if (($snapshot['files'] ?? []) === []) {
        $body[] = artistDocxParagraph('Nenhum arquivo operacional registrado.', ['size' => 20]);
    } else {
        $rows = [];
        foreach ($snapshot['files'] as $file) {
            $rows[] = [
                $file['file_type'] ?? '-',
                $file['original_name'] ?? '-',
                $file['storage_path'] ?? '-',
                artistExportStringifyValue($file['file_size_bytes'] ?? null),
            ];
        }
        $body[] = artistDocxTable(['Tipo', 'Nome', 'Storage path', 'Tamanho'], $rows);
    }

    $body[] = '
      <w:sectPr>
        <w:pgSz w:w="12240" w:h="15840"/>
        <w:pgMar w:top="1440" w:right="1080" w:bottom="1440" w:left="1080" w:header="708" w:footer="708" w:gutter="0"/>
      </w:sectPr>';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . implode('', $body) . '</w:body>'
        . '</w:document>';
}

function artistBuildOperationDocxContentTypesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
}

function artistBuildOperationDocxRelationshipsXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function artistBuildOperationDocxCorePropsXml(array $snapshot): string
{
    $title = 'Operacao do artista - ' . (($snapshot['artist']['stage_name'] ?? '') !== '' ? $snapshot['artist']['stage_name'] : 'Artista');
    $createdAt = gmdate('Y-m-d\TH:i:s\Z');

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . artistDocxEscapeText($title) . '</dc:title>'
        . '<dc:creator>EnjoyFun</dc:creator>'
        . '<cp:lastModifiedBy>EnjoyFun</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function artistBuildOperationDocxAppPropsXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>EnjoyFun</Application>'
        . '<Company>EnjoyFun</Company>'
        . '<DocSecurity>0</DocSecurity>'
        . '<ScaleCrop>false</ScaleCrop>'
        . '<LinksUpToDate>false</LinksUpToDate>'
        . '<SharedDoc>false</SharedDoc>'
        . '<HyperlinksChanged>false</HyperlinksChanged>'
        . '<AppVersion>1.0</AppVersion>'
        . '</Properties>';
}

function artistDocxParagraph(string $text, array $options = []): string
{
    $runProperties = '';
    if (!empty($options['bold']) || !empty($options['size'])) {
        $runProperties .= '<w:rPr>';
        if (!empty($options['bold'])) {
            $runProperties .= '<w:b/>';
        }
        if (!empty($options['size'])) {
            $runProperties .= '<w:sz w:val="' . (int)$options['size'] . '"/>';
        }
        $runProperties .= '</w:rPr>';
    }

    return '<w:p><w:r>' . $runProperties . '<w:t xml:space="preserve">'
        . artistDocxEscapeText($text)
        . '</w:t></w:r></w:p>';
}

function artistDocxEmptyParagraph(): string
{
    return '<w:p/>';
}

function artistDocxTable(array $headers, array $rows): string
{
    $xml = '<w:tbl>'
        . '<w:tblPr>'
        . '<w:tblW w:w="0" w:type="auto"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        . '</w:tblBorders>'
        . '</w:tblPr>';

    $xml .= artistDocxTableRow($headers, true);
    foreach ($rows as $row) {
        $xml .= artistDocxTableRow($row, false);
    }

    return $xml . '</w:tbl>';
}

function artistDocxTableRow(array $cells, bool $header): string
{
    $xml = '<w:tr>';
    foreach ($cells as $cell) {
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>'
            . artistDocxParagraph((string)$cell, $header ? ['bold' => true, 'size' => 20] : ['size' => 20])
            . '</w:tc>';
    }

    return $xml . '</w:tr>';
}

function artistDocxEscapeText(string $text): string
{
    $normalized = str_replace(["\r\n", "\r", "\n"], ' | ', $text);
    $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $normalized) ?? $normalized;
    return htmlspecialchars($normalized, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function artistBuildStoredZipBinary(array $files): string
{
    $data = '';
    $centralDirectory = '';
    $offset = 0;
    $entriesCount = 0;
    $dosTime = artistZipDosTime();
    $dosDate = artistZipDosDate();

    foreach ($files as $filename => $content) {
        $entriesCount++;
        $crc = (int)sprintf('%u', crc32($content));
        $size = strlen($content);
        $filenameLength = strlen($filename);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $filenameLength,
            0
        );

        $data .= $localHeader . $filename . $content;

        $centralDirectory .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $filenameLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $filename;

        $offset += strlen($localHeader) + $filenameLength + $size;
    }

    $centralDirectoryOffset = strlen($data);
    $centralDirectorySize = strlen($centralDirectory);
    $endRecord = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $entriesCount,
        $entriesCount,
        $centralDirectorySize,
        $centralDirectoryOffset,
        0
    );

    return $data . $centralDirectory . $endRecord;
}

function artistZipDosTime(): int
{
    $now = getdate();
    return (($now['hours'] & 0x1F) << 11)
        | (($now['minutes'] & 0x3F) << 5)
        | (int)floor(($now['seconds'] ?? 0) / 2);
}

function artistZipDosDate(): int
{
    $now = getdate();
    $year = max(1980, (int)$now['year']);
    return ((($year - 1980) & 0x7F) << 9)
        | (((int)$now['mon'] & 0x0F) << 5)
        | ((int)$now['mday'] & 0x1F);
}
