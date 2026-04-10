<?php

function previewArtistImport(array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $eventId = artistResolveEventId($db, $organizerId, $body['event_id'] ?? null, true);
    artistSetCurrentEventId($eventId);

    $importType = artistNormalizeImportType($body['import_type'] ?? null);
    $sourceFilename = artistNormalizeOptionalText($body['source_filename'] ?? null, 255);
    $rows = $body['rows'] ?? null;

    if ($sourceFilename === null) {
        jsonError('source_filename e obrigatorio.', 422);
    }
    if (!is_array($rows) || $rows === []) {
        jsonError('rows deve ser um array nao vazio.', 422);
    }

    $context = artistBuildImportContext($db, $organizerId, $eventId);
    $parsedRows = [];
    $validCount = 0;
    $invalidCount = 0;
    $errorSummary = [];

    foreach (array_values($rows) as $index => $rawRow) {
        $rowNumber = $index + 1;

        if (!is_array($rawRow)) {
            $errors = ['Cada linha do lote deve ser um objeto JSON.'];
            $parsedRows[] = [
                'row_number' => $rowNumber,
                'row_status' => 'invalid',
                'raw_payload' => $rawRow,
                'normalized_payload' => null,
                'error_messages' => $errors,
            ];
            $invalidCount++;
            $errorSummary[] = ['row' => $rowNumber, 'errors' => $errors];
            continue;
        }

        $result = artistPreviewImportRow($importType, $rawRow, $context, $eventId);
        $status = $result['errors'] === [] ? 'valid' : 'invalid';

        if ($status === 'valid') {
            $validCount++;
        } else {
            $invalidCount++;
            $errorSummary[] = ['row' => $rowNumber, 'errors' => $result['errors']];
        }

        $parsedRows[] = [
            'row_number' => $rowNumber,
            'row_status' => $status,
            'raw_payload' => $rawRow,
            'normalized_payload' => $status === 'valid' ? $result['normalized'] : null,
            'error_messages' => $result['errors'] === [] ? null : $result['errors'],
        ];
    }

    $batchStmt = $db->prepare("
        INSERT INTO public.artist_import_batches (
            organizer_id,
            event_id,
            import_type,
            source_filename,
            status,
            preview_payload,
            error_summary,
            created_at,
            updated_at
        ) VALUES (
            :organizer_id,
            :event_id,
            :import_type,
            :source_filename,
            'pending',
            :preview_payload,
            :error_summary,
            NOW(),
            NOW()
        )
        RETURNING id
    ");
    $batchStmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':import_type' => $importType,
        ':source_filename' => $sourceFilename,
        ':preview_payload' => json_encode($parsedRows, JSON_UNESCAPED_UNICODE),
        ':error_summary' => json_encode($errorSummary, JSON_UNESCAPED_UNICODE),
    ]);
    $batchId = (int)$batchStmt->fetchColumn();

    $rowStmt = $db->prepare("
        INSERT INTO public.artist_import_rows (
            batch_id,
            row_number,
            row_status,
            raw_payload,
            normalized_payload,
            error_messages,
            created_at,
            updated_at
        ) VALUES (
            :batch_id,
            :row_number,
            :row_status,
            :raw_payload,
            :normalized_payload,
            :error_messages,
            NOW(),
            NOW()
        )
    ");

    foreach ($parsedRows as $row) {
        $rowStmt->execute([
            ':batch_id' => $batchId,
            ':row_number' => $row['row_number'],
            ':row_status' => $row['row_status'],
            ':raw_payload' => json_encode($row['raw_payload'], JSON_UNESCAPED_UNICODE),
            ':normalized_payload' => $row['normalized_payload'] !== null
                ? json_encode($row['normalized_payload'], JSON_UNESCAPED_UNICODE)
                : null,
            ':error_messages' => $row['error_messages'] !== null
                ? json_encode($row['error_messages'], JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }

    jsonSuccess([
        'batch_id' => $batchId,
        'event_id' => $eventId,
        'import_type' => $importType,
        'total_rows' => count($parsedRows),
        'valid' => $validCount,
        'invalid' => $invalidCount,
        'errors' => $errorSummary,
        'preview' => $parsedRows,
        'can_confirm' => $validCount > 0,
    ], 'Preview de importacao concluido. Confirme para aplicar as linhas validas.');
}

function confirmArtistImport(array $body): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $batchId = artistNormalizeOptionalInt($body['batch_id'] ?? null);
    if ($batchId === null || $batchId <= 0) {
        jsonError('batch_id e obrigatorio.', 422);
    }

    $batchStmt = $db->prepare("
        SELECT *
        FROM public.artist_import_batches
        WHERE id = :batch_id
          AND organizer_id = :organizer_id
        LIMIT 1
    ");
    $batchStmt->execute([
        ':batch_id' => $batchId,
        ':organizer_id' => $organizerId,
    ]);
    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        jsonError('Batch de importacao nao encontrado.', 404);
    }

    $eventId = isset($batch['event_id']) ? (int)$batch['event_id'] : null;
    if ($eventId !== null) {
        artistSetCurrentEventId($eventId);
    }

    if (($batch['status'] ?? '') === 'done') {
        jsonError('Este batch ja foi aplicado.', 409);
    }
    if (($batch['status'] ?? '') === 'failed') {
        jsonError('Este batch falhou. Gere um novo preview.', 409);
    }

    $rowsStmt = $db->prepare("
        SELECT id, row_number, normalized_payload
        FROM public.artist_import_rows
        WHERE batch_id = :batch_id
          AND row_status = 'valid'
        ORDER BY row_number ASC
    ");
    $rowsStmt->execute([':batch_id' => $batchId]);
    $validRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($validRows === []) {
        jsonError('Nenhuma linha valida para aplicar neste batch.', 422);
    }

    $applied = 0;
    $skipped = 0;

    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE public.artist_import_batches
            SET status = 'processing', updated_at = NOW()
            WHERE id = :batch_id
        ")->execute([':batch_id' => $batchId]);

        foreach ($validRows as $row) {
            $payload = artistImportDecodeJson($row['normalized_payload']);
            if (!is_array($payload)) {
                $payload = [];
            }

            $db->exec('SAVEPOINT artist_import_row');
            try {
                $result = artistApplyImportRow((string)$batch['import_type'], $payload, $organizerId, $eventId, $db);
                $action = $result['action'] ?? 'skipped';
                $recordId = isset($result['record_id']) ? (int)$result['record_id'] : null;

                if ($action === 'applied') {
                    $db->prepare("
                        UPDATE public.artist_import_rows
                        SET row_status = 'applied', created_record_id = :created_record_id, updated_at = NOW()
                        WHERE id = :row_id
                    ")->execute([
                        ':created_record_id' => $recordId,
                        ':row_id' => (int)$row['id'],
                    ]);
                    $applied++;
                } else {
                    $db->prepare("
                        UPDATE public.artist_import_rows
                        SET row_status = 'skipped', created_record_id = :created_record_id, updated_at = NOW()
                        WHERE id = :row_id
                    ")->execute([
                        ':created_record_id' => $recordId,
                        ':row_id' => (int)$row['id'],
                    ]);
                    $skipped++;
                }

                $db->exec('RELEASE SAVEPOINT artist_import_row');
            } catch (Throwable $error) {
                $db->exec('ROLLBACK TO SAVEPOINT artist_import_row');
                $db->prepare("
                    UPDATE public.artist_import_rows
                    SET row_status = 'invalid', error_messages = :error_messages, updated_at = NOW()
                    WHERE id = :row_id
                ")->execute([
                    ':error_messages' => json_encode(
                        ['Erro ao aplicar linha importada: ' . $error->getMessage()],
                        JSON_UNESCAPED_UNICODE
                    ),
                    ':row_id' => (int)$row['id'],
                ]);
                $skipped++;
            }
        }

        $db->prepare("
            UPDATE public.artist_import_batches
            SET status = 'done', confirmed_at = NOW(), updated_at = NOW()
            WHERE id = :batch_id
        ")->execute([':batch_id' => $batchId]);

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $db->prepare("
            UPDATE public.artist_import_batches
            SET status = 'failed', updated_at = NOW()
            WHERE id = :batch_id
        ")->execute([':batch_id' => $batchId]);

        throw $error;
    }

    jsonSuccess([
        'batch_id' => $batchId,
        'event_id' => $eventId,
        'import_type' => (string)$batch['import_type'],
        'applied' => $applied,
        'skipped' => $skipped,
    ], 'Importacao aplicada com sucesso.');
}

function getArtistImportBatch(int $batchId): void
{
    [$db, $organizerId] = getArtistContext(['admin', 'organizer', 'manager']);
    artistEnsureModuleSchemaReady($db);

    $stmt = $db->prepare("
        SELECT *
        FROM public.artist_import_batches
        WHERE id = :batch_id
          AND organizer_id = :organizer_id
        LIMIT 1
    ");
    $stmt->execute([
        ':batch_id' => $batchId,
        ':organizer_id' => $organizerId,
    ]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        jsonError('Batch de importacao nao encontrado.', 404);
    }

    if (isset($batch['event_id']) && $batch['event_id'] !== null) {
        artistSetCurrentEventId((int)$batch['event_id']);
    }

    $rowsStmt = $db->prepare("
        SELECT
            id,
            row_number,
            row_status,
            raw_payload,
            normalized_payload,
            error_messages,
            created_record_id,
            created_at,
            updated_at
        FROM public.artist_import_rows
        WHERE batch_id = :batch_id
        ORDER BY row_number ASC
    ");
    $rowsStmt->execute([':batch_id' => $batchId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $decodedRows = [];
    $summary = [
        'total_rows' => 0,
        'valid' => 0,
        'invalid' => 0,
        'applied' => 0,
        'skipped' => 0,
    ];

    foreach ($rows as $row) {
        $status = (string)$row['row_status'];
        if (array_key_exists($status, $summary)) {
            $summary[$status]++;
        }
        $summary['total_rows']++;

        $decodedRows[] = [
            'id' => (int)$row['id'],
            'row_number' => (int)$row['row_number'],
            'row_status' => $status,
            'raw_payload' => artistImportDecodeJson($row['raw_payload']),
            'normalized_payload' => artistImportDecodeJson($row['normalized_payload']),
            'error_messages' => artistImportDecodeJson($row['error_messages']),
            'created_record_id' => $row['created_record_id'] !== null ? (int)$row['created_record_id'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    jsonSuccess([
        'id' => (int)$batch['id'],
        'organizer_id' => (int)$batch['organizer_id'],
        'event_id' => $batch['event_id'] !== null ? (int)$batch['event_id'] : null,
        'import_type' => (string)$batch['import_type'],
        'source_filename' => $batch['source_filename'],
        'status' => $batch['status'],
        'preview_payload' => artistImportDecodeJson($batch['preview_payload']),
        'error_summary' => artistImportDecodeJson($batch['error_summary']),
        'confirmed_at' => $batch['confirmed_at'],
        'created_at' => $batch['created_at'],
        'updated_at' => $batch['updated_at'],
        'summary' => $summary,
        'rows' => $decodedRows,
    ], 'Batch de importacao carregado com sucesso.');
}

function artistImportSupportedTypes(): array
{
    return ['bookings', 'logistics', 'team'];
}

function artistNormalizeImportType(mixed $value): string
{
    $normalized = strtolower(trim((string)($value ?? '')));
    $map = [
        'booking' => 'bookings',
        'bookings' => 'bookings',
        'logistica' => 'logistics',
        'logistics' => 'logistics',
        'team' => 'team',
        'equipe' => 'team',
        'team_members' => 'team',
        'team-members' => 'team',
    ];

    if (!isset($map[$normalized])) {
        jsonError('import_type invalido. Use: ' . implode(', ', artistImportSupportedTypes()) . '.', 422);
    }

    return $map[$normalized];
}

function artistBuildImportContext(PDO $db, int $organizerId, int $eventId): array
{
    $artistsStmt = $db->prepare("
        SELECT
            id,
            stage_name,
            legal_name,
            document_number,
            artist_type,
            default_contact_name,
            default_contact_phone,
            notes
        FROM public.artists
        WHERE organizer_id = :organizer_id
        ORDER BY id ASC
    ");
    $artistsStmt->execute([':organizer_id' => $organizerId]);
    $artists = $artistsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $artistsById = [];
    $artistsByStageName = [];
    foreach ($artists as $artist) {
        $artist['id'] = (int)$artist['id'];
        $artistsById[$artist['id']] = $artist;

        $stageKey = artistImportStageKey($artist['stage_name'] ?? null);
        if ($stageKey !== '') {
            $artistsByStageName[$stageKey][] = $artist;
        }
    }

    $bookingsStmt = $db->prepare("
        SELECT
            ea.id,
            ea.artist_id,
            a.stage_name AS artist_stage_name
        FROM public.event_artists ea
        JOIN public.artists a
          ON a.id = ea.artist_id
         AND a.organizer_id = ea.organizer_id
        WHERE ea.organizer_id = :organizer_id
          AND ea.event_id = :event_id
        ORDER BY ea.id ASC
    ");
    $bookingsStmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ]);
    $bookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $bookingsById = [];
    $bookingsByArtistId = [];
    $bookingsByStageName = [];
    foreach ($bookings as $booking) {
        $booking['id'] = (int)$booking['id'];
        $booking['artist_id'] = (int)$booking['artist_id'];
        $bookingsById[$booking['id']] = $booking;
        $bookingsByArtistId[$booking['artist_id']] = $booking;

        $stageKey = artistImportStageKey($booking['artist_stage_name'] ?? null);
        if ($stageKey !== '') {
            $bookingsByStageName[$stageKey][] = $booking;
        }
    }

    $logisticsStmt = $db->prepare("
        SELECT id, event_artist_id
        FROM public.artist_logistics
        WHERE organizer_id = :organizer_id
          AND event_id = :event_id
    ");
    $logisticsStmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ]);
    $logisticsRows = $logisticsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $logisticsByBookingId = [];
    foreach ($logisticsRows as $logistics) {
        $logisticsByBookingId[(int)$logistics['event_artist_id']] = [
            'id' => (int)$logistics['id'],
            'event_artist_id' => (int)$logistics['event_artist_id'],
        ];
    }

    $teamStmt = $db->prepare("
        SELECT id, event_artist_id, full_name, role_name
        FROM public.artist_team_members
        WHERE organizer_id = :organizer_id
          AND event_id = :event_id
    ");
    $teamStmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ]);
    $teamRows = $teamStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $teamByCompositeKey = [];
    foreach ($teamRows as $member) {
        $teamByCompositeKey[artistImportTeamDuplicateKey(
            (int)$member['event_artist_id'],
            (string)$member['full_name'],
            $member['role_name'] ?? null
        )] = (int)$member['id'];
    }

    return [
        'artists_by_id' => $artistsById,
        'artists_by_stage_name' => $artistsByStageName,
        'bookings_by_id' => $bookingsById,
        'bookings_by_artist_id' => $bookingsByArtistId,
        'bookings_by_stage_name' => $bookingsByStageName,
        'logistics_by_booking_id' => $logisticsByBookingId,
        'team_by_composite_key' => $teamByCompositeKey,
    ];
}

function artistPreviewImportRow(string $importType, array $row, array $context, int $eventId): array
{
    return match ($importType) {
        'bookings' => artistPreviewBookingImportRow($row, $context, $eventId),
        'logistics' => artistPreviewLogisticsImportRow($row, $context, $eventId),
        'team' => artistPreviewTeamImportRow($row, $context, $eventId),
        default => [
            'normalized' => null,
            'errors' => ['import_type nao suportado para preview.'],
        ],
    };
}

function artistPreviewBookingImportRow(array $row, array $context, int $eventId): array
{
    $errors = [];
    $artistRef = artistPreviewImportArtistReference($row, $context, $errors);

    $performanceStartAt = artistImportParseTimestamp($row['performance_start_at'] ?? null, 'performance_start_at', $errors);
    $performanceDate = artistImportParseDate($row['performance_date'] ?? null, 'performance_date', $errors);
    if ($performanceDate === null && $performanceStartAt !== null) {
        $performanceDate = substr($performanceStartAt, 0, 10);
    }

    $normalized = [
        'event_id' => $eventId,
        'artist_id' => $artistRef['artist_id'],
        'artist_payload' => $artistRef['artist_payload'],
        'booking_status' => artistImportText($row['booking_status'] ?? null, 30) ?? 'pending',
        'performance_date' => $performanceDate,
        'performance_start_at' => $performanceStartAt,
        'performance_duration_minutes' => artistImportParseNonNegativeInt(
            $row['performance_duration_minutes'] ?? null,
            'performance_duration_minutes',
            $errors
        ),
        'soundcheck_at' => artistImportParseTimestamp($row['soundcheck_at'] ?? null, 'soundcheck_at', $errors),
        'stage_name' => artistImportText($row['booking_stage_name'] ?? $row['show_stage_name'] ?? null, 150),
        'cache_amount' => artistImportParseMoney($row['cache_amount'] ?? null, 'cache_amount', $errors),
        'notes' => artistImportText($row['notes'] ?? null),
        'existing_booking_id' => null,
        'apply_mode' => 'create',
    ];

    if (($artistRef['artist_id'] ?? null) !== null && isset($context['bookings_by_artist_id'][$artistRef['artist_id']])) {
        $normalized['existing_booking_id'] = (int)$context['bookings_by_artist_id'][$artistRef['artist_id']]['id'];
        $normalized['apply_mode'] = 'skip_existing';
    }

    return [
        'normalized' => $normalized,
        'errors' => $errors,
    ];
}

function artistPreviewLogisticsImportRow(array $row, array $context, int $eventId): array
{
    $errors = [];
    $booking = artistPreviewResolveImportBooking($row, $context, $errors);

    $normalized = [
        'event_id' => $eventId,
        'event_artist_id' => $booking['id'] ?? null,
        'arrival_origin' => artistImportText($row['arrival_origin'] ?? null, 200),
        'arrival_mode' => artistImportText($row['arrival_mode'] ?? null, 50),
        'arrival_reference' => artistImportText($row['arrival_reference'] ?? null, 120),
        'arrival_at' => artistImportParseTimestamp($row['arrival_at'] ?? null, 'arrival_at', $errors),
        'hotel_name' => artistImportText($row['hotel_name'] ?? null, 200),
        'hotel_address' => artistImportText($row['hotel_address'] ?? null, 300),
        'hotel_check_in_at' => artistImportParseTimestamp($row['hotel_check_in_at'] ?? null, 'hotel_check_in_at', $errors),
        'hotel_check_out_at' => artistImportParseTimestamp($row['hotel_check_out_at'] ?? null, 'hotel_check_out_at', $errors),
        'venue_arrival_at' => artistImportParseTimestamp($row['venue_arrival_at'] ?? null, 'venue_arrival_at', $errors),
        'departure_destination' => artistImportText($row['departure_destination'] ?? null, 200),
        'departure_mode' => artistImportText($row['departure_mode'] ?? null, 50),
        'departure_reference' => artistImportText($row['departure_reference'] ?? null, 120),
        'departure_at' => artistImportParseTimestamp($row['departure_at'] ?? null, 'departure_at', $errors),
        'hospitality_notes' => artistImportText($row['hospitality_notes'] ?? null),
        'transport_notes' => artistImportText($row['transport_notes'] ?? null),
        'existing_logistics_id' => null,
        'apply_mode' => 'create',
    ];

    if (($normalized['event_artist_id'] ?? null) !== null && isset($context['logistics_by_booking_id'][$normalized['event_artist_id']])) {
        $normalized['existing_logistics_id'] = (int)$context['logistics_by_booking_id'][$normalized['event_artist_id']]['id'];
        $normalized['apply_mode'] = 'update_existing';
    }

    $meaningfulFields = [
        'arrival_origin',
        'arrival_mode',
        'arrival_reference',
        'arrival_at',
        'hotel_name',
        'hotel_address',
        'hotel_check_in_at',
        'hotel_check_out_at',
        'venue_arrival_at',
        'departure_destination',
        'departure_mode',
        'departure_reference',
        'departure_at',
        'hospitality_notes',
        'transport_notes',
    ];
    $hasPayload = false;
    foreach ($meaningfulFields as $field) {
        if (($normalized[$field] ?? null) !== null) {
            $hasPayload = true;
            break;
        }
    }
    if (!$hasPayload) {
        $errors[] = 'Ao menos um campo logistico deve ser informado na linha.';
    }

    return [
        'normalized' => $normalized,
        'errors' => $errors,
    ];
}

function artistPreviewTeamImportRow(array $row, array $context, int $eventId): array
{
    $errors = [];
    $booking = artistPreviewResolveImportBooking($row, $context, $errors);
    $fullName = artistImportText($row['full_name'] ?? null, 180);
    if ($fullName === null) {
        $errors[] = 'full_name e obrigatorio.';
    }

    $roleName = artistImportText($row['role_name'] ?? null, 120);
    $eventArtistId = $booking['id'] ?? null;
    $duplicateId = null;
    if ($eventArtistId !== null && $fullName !== null) {
        $duplicateKey = artistImportTeamDuplicateKey((int)$eventArtistId, $fullName, $roleName);
        if (isset($context['team_by_composite_key'][$duplicateKey])) {
            $duplicateId = (int)$context['team_by_composite_key'][$duplicateKey];
        }
    }

    return [
        'normalized' => [
            'event_id' => $eventId,
            'event_artist_id' => $eventArtistId,
            'full_name' => $fullName,
            'role_name' => $roleName,
            'document_number' => artistImportText($row['document_number'] ?? null, 40),
            'phone' => artistImportText($row['phone'] ?? null, 40),
            'needs_hotel' => artistNormalizeBoolean($row['needs_hotel'] ?? null, false),
            'needs_transfer' => artistNormalizeBoolean($row['needs_transfer'] ?? null, false),
            'notes' => artistImportText($row['notes'] ?? null),
            'is_active' => artistNormalizeBoolean($row['is_active'] ?? null, true),
            'existing_team_member_id' => $duplicateId,
            'apply_mode' => $duplicateId !== null ? 'skip_existing' : 'create',
        ],
        'errors' => $errors,
    ];
}

function artistPreviewImportArtistReference(array $row, array $context, array &$errors): array
{
    $artistId = artistImportParseNonNegativeInt($row['artist_id'] ?? null, 'artist_id', $errors);
    if ($artistId !== null) {
        if (!isset($context['artists_by_id'][$artistId])) {
            $errors[] = 'artist_id nao existe no escopo do organizador.';
        }

        return [
            'artist_id' => isset($context['artists_by_id'][$artistId]) ? $artistId : null,
            'artist_payload' => null,
        ];
    }

    $stageName = artistImportExtractArtistStageName($row);
    if ($stageName === null) {
        $errors[] = 'artist_id ou artist_stage_name e obrigatorio.';
        return [
            'artist_id' => null,
            'artist_payload' => null,
        ];
    }

    $matches = $context['artists_by_stage_name'][artistImportStageKey($stageName)] ?? [];
    if (count($matches) > 1) {
        $errors[] = 'artist_stage_name ambigua no cadastro do organizador. Informe artist_id.';
        return [
            'artist_id' => null,
            'artist_payload' => null,
        ];
    }

    if ($matches !== []) {
        return [
            'artist_id' => (int)$matches[0]['id'],
            'artist_payload' => null,
        ];
    }

    return [
        'artist_id' => null,
        'artist_payload' => [
            'stage_name' => $stageName,
            'legal_name' => artistImportText($row['artist_legal_name'] ?? $row['legal_name'] ?? null, 200),
            'document_number' => artistImportText($row['artist_document_number'] ?? $row['document_number'] ?? null, 30),
            'artist_type' => artistImportText($row['artist_type'] ?? null, 50),
            'default_contact_name' => artistImportText($row['artist_contact_name'] ?? $row['default_contact_name'] ?? null, 150),
            'default_contact_phone' => artistImportText($row['artist_contact_phone'] ?? $row['default_contact_phone'] ?? null, 40),
            'notes' => artistImportText($row['artist_notes'] ?? null),
        ],
    ];
}

function artistPreviewResolveImportBooking(array $row, array $context, array &$errors): ?array
{
    $bookingId = artistImportParseNonNegativeInt($row['event_artist_id'] ?? $row['booking_id'] ?? null, 'event_artist_id', $errors);
    if ($bookingId !== null) {
        if (!isset($context['bookings_by_id'][$bookingId])) {
            $errors[] = 'event_artist_id nao existe no evento informado.';
            return null;
        }

        return $context['bookings_by_id'][$bookingId];
    }

    $artistId = artistImportParseNonNegativeInt($row['artist_id'] ?? null, 'artist_id', $errors);
    if ($artistId !== null) {
        if (!isset($context['bookings_by_artist_id'][$artistId])) {
            $errors[] = 'Nao existe booking deste artista no event_id informado.';
            return null;
        }

        return $context['bookings_by_artist_id'][$artistId];
    }

    $stageName = artistImportExtractArtistStageName($row);
    if ($stageName === null) {
        $errors[] = 'event_artist_id ou artist_id ou artist_stage_name e obrigatorio.';
        return null;
    }

    $matches = $context['bookings_by_stage_name'][artistImportStageKey($stageName)] ?? [];
    if ($matches === []) {
        $errors[] = 'Booking do artista nao encontrado para o event_id informado.';
        return null;
    }
    if (count($matches) > 1) {
        $errors[] = 'artist_stage_name ambigua dentro do evento. Informe event_artist_id.';
        return null;
    }

    return $matches[0];
}

function artistApplyImportRow(string $importType, array $payload, int $organizerId, ?int $eventId, PDO $db): array
{
    if ($eventId === null || $eventId <= 0) {
        throw new RuntimeException('Batch sem event_id valido para importacao de artistas.');
    }

    return match ($importType) {
        'bookings' => artistApplyBookingImportRow($payload, $organizerId, $eventId, $db),
        'logistics' => artistApplyLogisticsImportRow($payload, $organizerId, $eventId, $db),
        'team' => artistApplyTeamImportRow($payload, $organizerId, $eventId, $db),
        default => throw new RuntimeException('import_type nao suportado para confirmacao.'),
    };
}

function artistApplyBookingImportRow(array $payload, int $organizerId, int $eventId, PDO $db): array
{
    $artistId = isset($payload['artist_id']) && $payload['artist_id'] !== null
        ? (int)$payload['artist_id']
        : null;

    if ($artistId === null) {
        $artistPayload = is_array($payload['artist_payload'] ?? null) ? $payload['artist_payload'] : [];
        $artistId = artistEnsureImportedArtist($db, $organizerId, $artistPayload);
    }

    $existingBookingId = artistFindExistingBookingIdForImport($db, $organizerId, $eventId, $artistId);
    if ($existingBookingId !== null) {
        return [
            'action' => 'skipped',
            'record_id' => $existingBookingId,
        ];
    }

    $cancelledAt = (($payload['booking_status'] ?? 'pending') === 'cancelled')
        ? date('Y-m-d H:i:s')
        : null;

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
            :cancelled_at,
            NOW(),
            NOW()
        )
        ON CONFLICT (event_id, artist_id) DO NOTHING
        RETURNING id
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':artist_id' => $artistId,
        ':booking_status' => (string)($payload['booking_status'] ?? 'pending'),
        ':performance_date' => $payload['performance_date'] ?? null,
        ':performance_start_at' => $payload['performance_start_at'] ?? null,
        ':performance_duration_minutes' => $payload['performance_duration_minutes'] ?? null,
        ':soundcheck_at' => $payload['soundcheck_at'] ?? null,
        ':stage_name' => $payload['stage_name'] ?? null,
        ':cache_amount' => $payload['cache_amount'] ?? null,
        ':notes' => $payload['notes'] ?? null,
        ':cancelled_at' => $cancelledAt,
    ]);

    $bookingId = $stmt->fetchColumn();
    if ($bookingId === false) {
        return [
            'action' => 'skipped',
            'record_id' => artistFindExistingBookingIdForImport($db, $organizerId, $eventId, $artistId),
        ];
    }

    return [
        'action' => 'applied',
        'record_id' => (int)$bookingId,
    ];
}

function artistApplyLogisticsImportRow(array $payload, int $organizerId, int $eventId, PDO $db): array
{
    $eventArtistId = (int)($payload['event_artist_id'] ?? 0);
    if (!artistImportBookingExists($db, $organizerId, $eventId, $eventArtistId)) {
        throw new RuntimeException('Booking alvo da logistica nao existe mais no evento.');
    }

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
        ON CONFLICT (event_artist_id) DO UPDATE
        SET
            arrival_origin = EXCLUDED.arrival_origin,
            arrival_mode = EXCLUDED.arrival_mode,
            arrival_reference = EXCLUDED.arrival_reference,
            arrival_at = EXCLUDED.arrival_at,
            hotel_name = EXCLUDED.hotel_name,
            hotel_address = EXCLUDED.hotel_address,
            hotel_check_in_at = EXCLUDED.hotel_check_in_at,
            hotel_check_out_at = EXCLUDED.hotel_check_out_at,
            venue_arrival_at = EXCLUDED.venue_arrival_at,
            departure_destination = EXCLUDED.departure_destination,
            departure_mode = EXCLUDED.departure_mode,
            departure_reference = EXCLUDED.departure_reference,
            departure_at = EXCLUDED.departure_at,
            hospitality_notes = EXCLUDED.hospitality_notes,
            transport_notes = EXCLUDED.transport_notes,
            updated_at = NOW()
        RETURNING id
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':event_artist_id' => $eventArtistId,
        ':arrival_origin' => $payload['arrival_origin'] ?? null,
        ':arrival_mode' => $payload['arrival_mode'] ?? null,
        ':arrival_reference' => $payload['arrival_reference'] ?? null,
        ':arrival_at' => $payload['arrival_at'] ?? null,
        ':hotel_name' => $payload['hotel_name'] ?? null,
        ':hotel_address' => $payload['hotel_address'] ?? null,
        ':hotel_check_in_at' => $payload['hotel_check_in_at'] ?? null,
        ':hotel_check_out_at' => $payload['hotel_check_out_at'] ?? null,
        ':venue_arrival_at' => $payload['venue_arrival_at'] ?? null,
        ':departure_destination' => $payload['departure_destination'] ?? null,
        ':departure_mode' => $payload['departure_mode'] ?? null,
        ':departure_reference' => $payload['departure_reference'] ?? null,
        ':departure_at' => $payload['departure_at'] ?? null,
        ':hospitality_notes' => $payload['hospitality_notes'] ?? null,
        ':transport_notes' => $payload['transport_notes'] ?? null,
    ]);

    return [
        'action' => 'applied',
        'record_id' => (int)$stmt->fetchColumn(),
    ];
}

function artistApplyTeamImportRow(array $payload, int $organizerId, int $eventId, PDO $db): array
{
    $eventArtistId = (int)($payload['event_artist_id'] ?? 0);
    if (!artistImportBookingExists($db, $organizerId, $eventId, $eventArtistId)) {
        throw new RuntimeException('Booking alvo da equipe nao existe mais no evento.');
    }

    $fullName = trim((string)($payload['full_name'] ?? ''));
    if ($fullName === '') {
        throw new RuntimeException('Linha de equipe sem full_name normalizado.');
    }

    $duplicateId = artistFindExistingTeamMemberIdForImport(
        $db,
        $organizerId,
        $eventId,
        $eventArtistId,
        $fullName,
        $payload['role_name'] ?? null
    );
    if ($duplicateId !== null) {
        return [
            'action' => 'skipped',
            'record_id' => $duplicateId,
        ];
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
        ':role_name' => $payload['role_name'] ?? null,
        ':document_number' => $payload['document_number'] ?? null,
        ':phone' => $payload['phone'] ?? null,
        ':needs_hotel' => artistNormalizeBoolean($payload['needs_hotel'] ?? null, false) ? 'true' : 'false',
        ':needs_transfer' => artistNormalizeBoolean($payload['needs_transfer'] ?? null, false) ? 'true' : 'false',
        ':notes' => $payload['notes'] ?? null,
        ':is_active' => artistNormalizeBoolean($payload['is_active'] ?? null, true) ? 'true' : 'false',
    ]);

    return [
        'action' => 'applied',
        'record_id' => (int)$stmt->fetchColumn(),
    ];
}

function artistEnsureImportedArtist(PDO $db, int $organizerId, array $artistPayload): int
{
    $stageName = trim((string)($artistPayload['stage_name'] ?? ''));
    if ($stageName === '') {
        throw new RuntimeException('Linha sem artist_stage_name suficiente para criar artista.');
    }

    $existingId = artistFindArtistIdByStageNameForImport($db, $organizerId, $stageName);
    if ($existingId !== null) {
        return $existingId;
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
            TRUE,
            NOW(),
            NOW()
        )
        RETURNING id
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':stage_name' => $stageName,
        ':legal_name' => $artistPayload['legal_name'] ?? null,
        ':document_number' => $artistPayload['document_number'] ?? null,
        ':artist_type' => $artistPayload['artist_type'] ?? null,
        ':default_contact_name' => $artistPayload['default_contact_name'] ?? null,
        ':default_contact_phone' => $artistPayload['default_contact_phone'] ?? null,
        ':notes' => $artistPayload['notes'] ?? null,
    ]);

    return (int)$stmt->fetchColumn();
}

function artistFindArtistIdByStageNameForImport(PDO $db, int $organizerId, string $stageName): ?int
{
    $stmt = $db->prepare("
        SELECT id
        FROM public.artists
        WHERE organizer_id = :organizer_id
          AND LOWER(TRIM(stage_name)) = LOWER(TRIM(:stage_name))
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':stage_name' => $stageName,
    ]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function artistFindExistingBookingIdForImport(PDO $db, int $organizerId, int $eventId, int $artistId): ?int
{
    $stmt = $db->prepare("
        SELECT id
        FROM public.event_artists
        WHERE organizer_id = :organizer_id
          AND event_id = :event_id
          AND artist_id = :artist_id
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':artist_id' => $artistId,
    ]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function artistImportBookingExists(PDO $db, int $organizerId, int $eventId, int $eventArtistId): bool
{
    if ($eventArtistId <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT id
        FROM public.event_artists
        WHERE id = :event_artist_id
          AND organizer_id = :organizer_id
          AND event_id = :event_id
        LIMIT 1
    ");
    $stmt->execute([
        ':event_artist_id' => $eventArtistId,
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
    ]);

    return (bool)$stmt->fetchColumn();
}

function artistFindExistingTeamMemberIdForImport(
    PDO $db,
    int $organizerId,
    int $eventId,
    int $eventArtistId,
    string $fullName,
    ?string $roleName
): ?int {
    $stmt = $db->prepare("
        SELECT id
        FROM public.artist_team_members
        WHERE organizer_id = :organizer_id
          AND event_id = :event_id
          AND event_artist_id = :event_artist_id
          AND LOWER(TRIM(full_name)) = LOWER(TRIM(:full_name))
          AND LOWER(TRIM(COALESCE(role_name, ''))) = LOWER(TRIM(COALESCE(:role_name, '')))
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([
        ':organizer_id' => $organizerId,
        ':event_id' => $eventId,
        ':event_artist_id' => $eventArtistId,
        ':full_name' => $fullName,
        ':role_name' => $roleName,
    ]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function artistImportDecodeJson(mixed $value): mixed
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string)$value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function artistImportExtractArtistStageName(array $row): ?string
{
    return artistImportText(
        $row['artist_stage_name'] ?? $row['artist_name'] ?? $row['stage_name'] ?? null,
        200
    );
}

function artistImportStageKey(?string $value): string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return '';
    }

    return function_exists('mb_strtolower')
        ? mb_strtolower($text)
        : strtolower($text);
}

function artistImportTeamDuplicateKey(int $eventArtistId, string $fullName, ?string $roleName): string
{
    return $eventArtistId . '|' . artistImportStageKey($fullName) . '|' . artistImportStageKey($roleName);
}

function artistImportText(mixed $value, ?int $maxLength = null): ?string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return null;
    }

    if ($maxLength !== null && $maxLength > 0) {
        $text = function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength)
            : substr($text, 0, $maxLength);
    }

    return $text;
}

function artistImportParseDate(mixed $value, string $fieldName, array &$errors): ?string
{
    $text = artistImportText($value);
    if ($text === null) {
        return null;
    }

    try {
        return (new DateTimeImmutable($text))->format('Y-m-d');
    } catch (Throwable) {
        $errors[] = $fieldName . ' invalido.';
        return null;
    }
}

function artistImportParseTimestamp(mixed $value, string $fieldName, array &$errors): ?string
{
    $text = artistImportText($value);
    if ($text === null) {
        return null;
    }

    try {
        return (new DateTimeImmutable(str_replace('T', ' ', $text)))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        $errors[] = $fieldName . ' invalido.';
        return null;
    }
}

function artistImportParseNonNegativeInt(mixed $value, string $fieldName, array &$errors): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        $errors[] = $fieldName . ' invalido.';
        return null;
    }

    $normalized = (int)$value;
    if ($normalized < 0) {
        $errors[] = $fieldName . ' invalido.';
        return null;
    }

    return $normalized;
}

function artistImportParseMoney(mixed $value, string $fieldName, array &$errors): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        $amount = (float)$value;
    } else {
        $raw = trim((string)$value);
        $sanitized = preg_replace('/[^\d,\.\-]/', '', $raw) ?? '';
        if ($sanitized === '' || $sanitized === '-') {
            $errors[] = $fieldName . ' invalido.';
            return null;
        }

        $lastComma = strrpos($sanitized, ',');
        $lastDot = strrpos($sanitized, '.');
        $decimalPos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        if ($decimalPos >= 0) {
            $integerPart = preg_replace('/[^\d\-]/', '', substr($sanitized, 0, $decimalPos)) ?? '';
            $fractionPart = preg_replace('/\D/', '', substr($sanitized, $decimalPos + 1)) ?? '';
            $normalized = ($integerPart === '' || $integerPart === '-') ? '0' : $integerPart;
            $amount = (float)($normalized . '.' . $fractionPart);
        } else {
            $normalized = preg_replace('/[^\d\-]/', '', $sanitized) ?? '';
            if ($normalized === '' || $normalized === '-') {
                $errors[] = $fieldName . ' invalido.';
                return null;
            }
            $amount = (float)$normalized;
        }
    }

    if (!is_finite($amount) || $amount < 0) {
        $errors[] = $fieldName . ' invalido.';
        return null;
    }

    return number_format(round($amount, 2), 2, '.', '');
}
