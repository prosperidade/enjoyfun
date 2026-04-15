<?php
/**
 * PublicInvitationController — EnjoyFun 2.0
 *
 * PUBLIC endpoints (no authentication required).
 * Guests receive a link like /convite/{event_slug}/{guest_token}
 * which maps to API calls here.
 *
 * Routes:
 *   GET  /invitations/{eventSlug}/{guestToken}       → view invitation
 *   POST /invitations/{eventSlug}/{guestToken}/rsvp   → submit RSVP
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Route structure: /invitations/{eventSlug}/{guestToken}[/rsvp]
    // $id = eventSlug, $sub = guestToken, $subId = "rsvp" or null
    $eventSlug = $id;
    $guestToken = $sub;
    $action = $subId;

    if (!$eventSlug || !$guestToken) {
        jsonError('Convite nao encontrado.', 404);
    }

    match (true) {
        $method === 'GET'  && $eventSlug === 'banner' && $guestToken !== null && $action === null => serveEventBanner($guestToken),
        $method === 'GET'  && $action === null   => getInvitation($eventSlug, $guestToken),
        $method === 'POST' && $action === 'rsvp' => submitRsvp($eventSlug, $guestToken, $body),
        default => jsonError('Convite: rota nao encontrada.', 404),
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// Serve event banner image (public, no auth)
// GET /invitations/banner/{fileId}
// ─────────────────────────────────────────────────────────────────────────────
function serveEventBanner(string $fileId): void
{
    $db = Database::getInstance();
    $id = (int) $fileId;
    if ($id <= 0) { jsonError('Arquivo nao encontrado.', 404); }

    $stmt = $db->prepare("
        SELECT storage_path, mime_type, original_name, file_size_bytes
        FROM public.organizer_files
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) { jsonError('Arquivo nao encontrado.', 404); }

    $fullPath = BASE_PATH . '/public' . ($file['storage_path'] ?? '');
    if (!file_exists($fullPath)) { jsonError('Arquivo nao encontrado.', 404); }

    $mime = $file['mime_type'] ?: 'image/jpeg';
    // Only serve images publicly
    if (!str_starts_with($mime, 'image/')) { jsonError('Tipo de arquivo nao permitido.', 403); }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: public, max-age=86400');
    readfile($fullPath);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Rate limiting helper (IP-based, reuses auth_rate_limits table)
// ─────────────────────────────────────────────────────────────────────────────
function invitationRateLimit(string $action): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey = "invitation_{$action}:" . $ip;
    $windowSecs = 60;
    $maxAttempts = ($action === 'rsvp') ? 10 : 30;

    $db = Database::getInstance();
    $windowStart = date('Y-m-d H:i:s', time() - $windowSecs);

    // Check if auth_rate_limits table exists
    try {
        $countStmt = $db->prepare("SELECT COUNT(*)::int FROM auth_rate_limits WHERE rate_key = ? AND attempted_at > ?");
        $countStmt->execute([$rateKey, $windowStart]);
        $count = (int)$countStmt->fetchColumn();

        if ($count >= $maxAttempts) {
            jsonError('Muitas requisicoes. Tente novamente em instantes.', 429);
        }

        $insertStmt = $db->prepare("INSERT INTO auth_rate_limits (rate_key, attempted_at) VALUES (?, NOW())");
        $insertStmt->execute([$rateKey]);
    } catch (\Throwable $e) {
        // If rate limit table doesn't exist, skip silently
        error_log('[PublicInvitation] Rate limit skip: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: resolve event by slug (or numeric ID fallback)
// ─────────────────────────────────────────────────────────────────────────────
function resolveEventBySlug(string $slug): ?array
{
    $db = Database::getInstance();

    // Build list of columns that exist — base columns are always present
    $baseColumns = [
        'id', 'name', 'slug', 'description', 'banner_url', 'venue_name',
        'starts_at', 'ends_at', 'status', 'location', 'organizer_id',
    ];

    // Optional columns added by migration 089
    $optionalColumns = [
        'event_type', 'city', 'state', 'country', 'venue_type',
        'map_3d_url', 'map_image_url', 'map_seating_url', 'map_parking_url',
        'latitude', 'longitude',
    ];

    $existingOptional = [];
    foreach ($optionalColumns as $col) {
        try {
            $check = $db->prepare(
                "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'events' AND column_name = ?"
            );
            $check->execute([$col]);
            if ($check->fetchColumn()) {
                $existingOptional[] = $col;
            }
        } catch (\Throwable $e) {
            // skip
        }
    }

    $allColumns = array_merge($baseColumns, $existingOptional);
    $columnList = implode(', ', $allColumns);

    $stmt = $db->prepare("SELECT {$columnList} FROM events WHERE slug = ? AND status != 'cancelled' LIMIT 1");
    $stmt->execute([$slug]);
    $event = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$event) {
        return null;
    }

    return $event;
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: resolve guest participant by qr_token within event
// ─────────────────────────────────────────────────────────────────────────────
function resolveGuestByToken(int $eventId, string $guestToken): ?array
{
    $db = Database::getInstance();

    // Base query with JOIN to people
    $baseSql = "SELECT ep.id, ep.event_id, ep.person_id, ep.status, ep.qr_token, ep.organizer_id,
                       p.name, p.email, p.phone";

    // Check for RSVP columns (migration 099)
    $rsvpColumns = [
        'rsvp_status', 'meal_choice', 'dietary_restrictions',
        'table_id', 'seat_number', 'plus_one_name', 'guest_side', 'invited_by',
    ];

    $existingRsvpCols = [];
    foreach ($rsvpColumns as $col) {
        try {
            $check = $db->prepare(
                "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'event_participants' AND column_name = ?"
            );
            $check->execute([$col]);
            if ($check->fetchColumn()) {
                $existingRsvpCols[] = $col;
            }
        } catch (\Throwable $e) {
            // skip
        }
    }

    $extraSelect = '';
    if (!empty($existingRsvpCols)) {
        $extraSelect = ', ' . implode(', ', array_map(fn($c) => "ep.{$c}", $existingRsvpCols));
    }

    $sql = "{$baseSql}{$extraSelect}
            FROM event_participants ep
            JOIN people p ON p.id = ep.person_id
            WHERE ep.qr_token = ? AND ep.event_id = ?
            LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute([$guestToken, $eventId]);
    $guest = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$guest) {
        return null;
    }

    // Resolve table_name if table_id is present
    if (!empty($guest['table_id'])) {
        try {
            $tableStmt = $db->prepare("SELECT table_name, table_number FROM event_tables WHERE id = ? LIMIT 1");
            $tableStmt->execute([$guest['table_id']]);
            $table = $tableStmt->fetch(\PDO::FETCH_ASSOC);
            $guest['table_name'] = $table ? ($table['table_name'] ?: "Mesa {$table['table_number']}") : null;
        } catch (\Throwable $e) {
            $guest['table_name'] = null;
        }
    } else {
        $guest['table_name'] = null;
    }

    return $guest;
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: load ceremony moments (if table exists)
// ─────────────────────────────────────────────────────────────────────────────
function loadCeremonyMoments(int $eventId): array
{
    $db = Database::getInstance();

    try {
        $check = $db->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'event_ceremony_moments'"
        );
        $check->execute();
        if (!$check->fetchColumn()) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT id, name, moment_time, responsible, notes, sort_order
             FROM event_ceremony_moments
             WHERE event_id = ? AND is_active = true
             ORDER BY sort_order, moment_time"
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: load sub-events (if table exists)
// ─────────────────────────────────────────────────────────────────────────────
function loadSubEvents(int $eventId): array
{
    $db = Database::getInstance();

    try {
        $check = $db->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'event_sub_events'"
        );
        $check->execute();
        if (!$check->fetchColumn()) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT id, name, sub_event_type, event_date, event_time, venue, address, description, capacity, sort_order
             FROM event_sub_events
             WHERE event_id = ? AND is_active = true
             ORDER BY event_date, event_time, sort_order"
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /invitations/{eventSlug}/{guestToken}
// Public: returns invitation data for guest view
// ─────────────────────────────────────────────────────────────────────────────
function getInvitation(string $eventSlug, string $guestToken): void
{
    invitationRateLimit('view');

    $event = resolveEventBySlug($eventSlug);
    if (!$event) {
        jsonError('Convite nao encontrado.', 404);
    }

    $guest = resolveGuestByToken((int)$event['id'], $guestToken);
    if (!$guest) {
        jsonError('Convite nao encontrado.', 404);
    }

    $eventId = (int)$event['id'];

    // Build safe event response (no internal IDs leaked)
    $eventData = [
        'name'        => $event['name'],
        'description' => $event['description'] ?? null,
        'starts_at'   => $event['starts_at'],
        'ends_at'     => $event['ends_at'] ?? null,
        'venue_name'  => $event['venue_name'] ?? null,
        'location'    => $event['location'] ?? null,
        'city'        => $event['city'] ?? null,
        'state'       => $event['state'] ?? null,
        'country'     => $event['country'] ?? null,
        'banner_url'  => $event['banner_url'] ?? null,
        'event_type'  => $event['event_type'] ?? null,
        'venue_type'  => $event['venue_type'] ?? null,
        'map_image_url'   => $event['map_image_url'] ?? null,
        'map_seating_url' => $event['map_seating_url'] ?? null,
        'latitude'    => $event['latitude'] ?? null,
        'longitude'   => $event['longitude'] ?? null,
    ];

    // Build safe guest response (only the guest's own data)
    $guestData = [
        'name'                 => $guest['name'],
        'rsvp_status'          => $guest['rsvp_status'] ?? 'pending',
        'meal_choice'          => $guest['meal_choice'] ?? null,
        'dietary_restrictions'  => $guest['dietary_restrictions'] ?? null,
        'plus_one_name'        => $guest['plus_one_name'] ?? null,
        'guest_side'           => $guest['guest_side'] ?? null,
        'table_name'           => $guest['table_name'] ?? null,
        'seat_number'          => $guest['seat_number'] ?? null,
        'invited_by'           => $guest['invited_by'] ?? null,
    ];

    $ceremonyMoments = loadCeremonyMoments($eventId);
    $subEvents = loadSubEvents($eventId);

    jsonSuccess([
        'event'             => $eventData,
        'guest'             => $guestData,
        'ceremony_moments'  => $ceremonyMoments,
        'sub_events'        => $subEvents,
    ], 'Convite carregado com sucesso.');
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /invitations/{eventSlug}/{guestToken}/rsvp
// Public: guest submits their RSVP (partial update)
// ─────────────────────────────────────────────────────────────────────────────
function submitRsvp(string $eventSlug, string $guestToken, array $body): void
{
    invitationRateLimit('rsvp');

    $event = resolveEventBySlug($eventSlug);
    if (!$event) {
        jsonError('Convite nao encontrado.', 404);
    }

    $guest = resolveGuestByToken((int)$event['id'], $guestToken);
    if (!$guest) {
        jsonError('Convite nao encontrado.', 404);
    }

    $db = Database::getInstance();
    $participantId = (int)$guest['id'];

    // Allowed RSVP fields with validation
    $allowedFields = [
        'rsvp_status'          => ['pending', 'confirmed', 'declined'],
        'meal_choice'          => ['meat', 'fish', 'vegetarian', 'vegan', 'kids', 'none'],
        'dietary_restrictions'  => null, // free text
        'plus_one_name'        => null, // free text
    ];

    // Check which columns actually exist in event_participants
    $existingCols = [];
    foreach (array_keys($allowedFields) as $col) {
        try {
            $check = $db->prepare(
                "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'event_participants' AND column_name = ?"
            );
            $check->execute([$col]);
            if ($check->fetchColumn()) {
                $existingCols[] = $col;
            }
        } catch (\Throwable $e) {
            // skip
        }
    }

    // Build SET clause from body (only present and valid fields)
    $setClauses = [];
    $params = [];

    foreach ($existingCols as $col) {
        if (!array_key_exists($col, $body)) {
            continue;
        }

        $value = $body[$col];

        // Validate enum fields
        $validValues = $allowedFields[$col] ?? null;
        if ($validValues !== null && !in_array($value, $validValues, true)) {
            jsonError("Valor invalido para '{$col}'. Valores aceitos: " . implode(', ', $validValues), 422);
        }

        // Sanitize free text fields
        if ($validValues === null && $value !== null) {
            $value = mb_substr(trim((string)$value), 0, 500);
        }

        $setClauses[] = "{$col} = ?";
        $params[] = $value;
    }

    if (empty($setClauses)) {
        jsonError('Nenhum campo valido para atualizar.', 422);
    }

    // Always update updated_at
    $setClauses[] = "updated_at = NOW()";
    $params[] = $participantId;

    $sql = "UPDATE event_participants SET " . implode(', ', $setClauses) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Reload guest data for response
    $updatedGuest = resolveGuestByToken((int)$event['id'], $guestToken);

    $guestData = [
        'name'                 => $updatedGuest['name'],
        'rsvp_status'          => $updatedGuest['rsvp_status'] ?? 'pending',
        'meal_choice'          => $updatedGuest['meal_choice'] ?? null,
        'dietary_restrictions'  => $updatedGuest['dietary_restrictions'] ?? null,
        'plus_one_name'        => $updatedGuest['plus_one_name'] ?? null,
        'guest_side'           => $updatedGuest['guest_side'] ?? null,
        'table_name'           => $updatedGuest['table_name'] ?? null,
        'seat_number'          => $updatedGuest['seat_number'] ?? null,
        'invited_by'           => $updatedGuest['invited_by'] ?? null,
    ];

    jsonSuccess(['guest' => $guestData], 'RSVP atualizado com sucesso.');
}
