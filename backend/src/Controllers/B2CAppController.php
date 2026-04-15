<?php
/**
 * B2C App Controller — Public + authenticated endpoints for participant mobile app
 * Reads data configured by the organizer (stages, sectors, lineup, maps, etc.)
 * Separate from ParticipantController (which handles event participant CRUD)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Public endpoints (no auth required)
    $publicEndpoints = ['event', 'lineup', 'map', 'sectors', 'sessions', 'tables', 'stages', 'menu'];
    if ($method === 'GET' && in_array($id, $publicEndpoints, true)) {
        match ($id) {
            'event'    => getEventPublicB2C($sub),
            'lineup'   => getLineupB2C((int)($query['event_id'] ?? $sub ?? 0)),
            'map'      => getMapPointsB2C((int)($query['event_id'] ?? $sub ?? 0)),
            'sectors'  => getSectorsB2C((int)($query['event_id'] ?? $sub ?? 0)),
            'sessions' => getSessionsB2C((int)($query['event_id'] ?? $sub ?? 0)),
            'tables'   => getTablesB2C((int)($query['event_id'] ?? $sub ?? 0)),
            'stages'   => getStagesB2C((int)($query['event_id'] ?? $sub ?? 0)),
            'menu'     => getMenuB2C((int)($query['event_id'] ?? $sub ?? 0), $query),
        };
        return;
    }

    // Authenticated endpoints
    $user = requireAuth();
    $userId = (int)($user['id'] ?? $user['sub'] ?? 0);

    match (true) {
        $method === 'GET'  && $id === 'tickets'      => getMyTicketsB2C($userId, $query),
        $method === 'GET'  && $id === 'wallet'        => getWalletB2C($userId, $query),
        $method === 'GET'  && $id === 'transactions'  => getTransactionsB2C($userId, $query),
        $method === 'POST' && $id === 'wallet' && $sub === 'recharge' => requestRechargeB2C($userId, $body),
        default => jsonError('B2C App: Endpoint nao encontrado', 404),
    };
}

// ---------------------------------------------------------------------------
// Public endpoints
// ---------------------------------------------------------------------------

function getEventPublicB2C(?string $slugOrId): void
{
    if (!$slugOrId) jsonError('Slug ou ID do evento obrigatorio', 422);
    $db = Database::getInstance();

    $isNumeric = is_numeric($slugOrId);
    $sql = $isNumeric
        ? "SELECT * FROM events WHERE id = ? LIMIT 1"
        : "SELECT * FROM events WHERE slug = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$isNumeric ? (int)$slugOrId : $slugOrId]);
    $event = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento nao encontrado', 404);

    $eid = (int)$event['id'];

    // Branding
    $branding = [];
    try {
        $stmtOrg = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmtOrg->execute([(int)$event['organizer_id']]);
        $branding['organizer_name'] = $stmtOrg->fetchColumn() ?: '';
    } catch (\Exception $e) {}

    // Ticket count
    $ticketsSold = 0;
    try {
        $stmtTix = $db->prepare("SELECT COUNT(*) FROM tickets WHERE event_id = ? AND status != 'cancelled'");
        $stmtTix->execute([$eid]);
        $ticketsSold = (int)$stmtTix->fetchColumn();
    } catch (\Exception $e) {}

    // Stages count
    $stagesCount = 0;
    if (b2cTableExists($db, 'event_stages')) {
        $stmtSt = $db->prepare("SELECT COUNT(*) FROM event_stages WHERE event_id = ?");
        $stmtSt->execute([$eid]);
        $stagesCount = (int)$stmtSt->fetchColumn();
    }

    jsonSuccess([
        'id' => $eid,
        'name' => $event['name'] ?? '',
        'start_date' => $event['start_date'] ?? null,
        'end_date' => $event['end_date'] ?? null,
        'location' => $event['location'] ?? null,
        'capacity' => (int)($event['capacity'] ?? 0),
        'event_type' => $event['event_type'] ?? 'festival',
        'venue_type' => $event['venue_type'] ?? null,
        'google_maps_url' => $event['google_maps_url'] ?? null,
        'banner_url' => $event['banner_url'] ?? null,
        'latitude' => isset($event['latitude']) ? (float)$event['latitude'] : null,
        'longitude' => isset($event['longitude']) ? (float)$event['longitude'] : null,
        'modules_enabled' => json_decode($event['modules_enabled'] ?? '[]', true) ?: [],
        'tickets_sold' => $ticketsSold,
        'stages_count' => $stagesCount,
        'branding' => $branding,
    ]);
}

function getLineupB2C(int $eventId): void
{
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    $db = Database::getInstance();

    $stages = [];
    if (b2cTableExists($db, 'event_stages')) {
        $stmt = $db->prepare("SELECT id, name, stage_type, capacity FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC, name ASC");
        $stmt->execute([$eventId]);
        $stages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    $artists = [];
    try {
        $stmt = $db->prepare("SELECT id, name, genre, photo_url, bio FROM artists WHERE event_id = ? ORDER BY name ASC");
        $stmt->execute([$eventId]);
        $artists = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    jsonSuccess(['stages' => $stages, 'artists' => $artists]);
}

function getMapPointsB2C(int $eventId): void
{
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    $db = Database::getInstance();
    $points = [];

    if (b2cTableExists($db, 'event_pdv_points')) {
        $stmt = $db->prepare("SELECT id, name, pdv_type AS kind, location_description FROM event_pdv_points WHERE event_id = ? AND is_active = true ORDER BY sort_order ASC");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $points[] = ['id' => 'pdv-' . $p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'description' => $p['location_description']];
        }
    }
    if (b2cTableExists($db, 'event_stages')) {
        $stmt = $db->prepare("SELECT id, name, stage_type FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $points[] = ['id' => 'stage-' . $s['id'], 'name' => $s['name'], 'kind' => 'stage', 'description' => $s['stage_type']];
        }
    }
    if (b2cTableExists($db, 'event_parking_config')) {
        $stmt = $db->prepare("SELECT id, vehicle_type, total_spots FROM event_parking_config WHERE event_id = ?");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $pk) {
            $points[] = ['id' => 'parking-' . $pk['id'], 'name' => 'Estacionamento ' . $pk['vehicle_type'], 'kind' => 'parking', 'description' => $pk['total_spots'] . ' vagas'];
        }
    }

    $gps = null;
    $mapsUrl = null;
    $mapFiles = [];
    try {
        $cols = 'latitude, longitude, google_maps_url';
        foreach (['map_3d_url', 'map_image_url', 'map_seating_url', 'map_parking_url'] as $c) {
            if (b2cColumnExists($db, 'events', $c)) $cols .= ", {$c}";
        }
        $stmt = $db->prepare("SELECT {$cols} FROM events WHERE id = ?");
        $stmt->execute([$eventId]);
        $ev = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($ev) {
            $gps = ['lat' => $ev['latitude'] ? (float)$ev['latitude'] : null, 'lng' => $ev['longitude'] ? (float)$ev['longitude'] : null];
            $mapsUrl = $ev['google_maps_url'] ?? null;
            foreach (['map_3d_url', 'map_image_url', 'map_seating_url', 'map_parking_url'] as $f) {
                if (!empty($ev[$f])) $mapFiles[] = ['field' => $f, 'url' => $ev[$f]];
            }
        }
    } catch (\Exception $e) {}

    jsonSuccess(['points' => $points, 'gps' => $gps, 'google_maps_url' => $mapsUrl, 'map_files' => $mapFiles]);
}

function getSectorsB2C(int $eventId): void
{
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    $db = Database::getInstance();
    if (!b2cTableExists($db, 'event_sectors')) { jsonSuccess([]); return; }
    $stmt = $db->prepare("SELECT id, name, sector_type, capacity, price_modifier, description FROM event_sectors WHERE event_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function getSessionsB2C(int $eventId): void
{
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    $db = Database::getInstance();
    if (!b2cTableExists($db, 'event_sessions')) { jsonSuccess([]); return; }
    $stmt = $db->prepare("
        SELECT s.id, s.title, s.session_type, s.speaker_name, s.starts_at, s.ends_at, s.max_capacity, s.stage_id, es.name AS stage_name
        FROM event_sessions s LEFT JOIN event_stages es ON es.id = s.stage_id
        WHERE s.event_id = ? ORDER BY s.starts_at ASC
    ");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function getTablesB2C(int $eventId): void
{
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    $db = Database::getInstance();
    if (!b2cTableExists($db, 'event_tables')) { jsonSuccess([]); return; }
    $stmt = $db->prepare("SELECT id, table_number, table_name, table_type, capacity, section FROM event_tables WHERE event_id = ? ORDER BY sort_order ASC, table_number ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function getStagesB2C(int $eventId): void
{
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    $db = Database::getInstance();
    if (!b2cTableExists($db, 'event_stages')) { jsonSuccess([]); return; }
    $stmt = $db->prepare("SELECT id, name, stage_type, capacity FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$eventId]);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function getMenuB2C(int $eventId, array $query): void
{
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    $db = Database::getInstance();
    $sector = strtolower(trim($query['sector'] ?? 'bar'));
    if (!in_array($sector, ['bar', 'food', 'shop'], true)) $sector = 'bar';

    $sectorWhere = $sector === 'bar' ? "(LOWER(p.sector) = 'bar' OR p.sector IS NULL)" : "LOWER(p.sector) = ?";
    $params = [$eventId];
    if ($sector !== 'bar') $params[] = $sector;

    $hasPdv = b2cColumnExists($db, 'products', 'pdv_point_id');
    $pdvJoin = $hasPdv ? "LEFT JOIN event_pdv_points pp ON pp.id = p.pdv_point_id" : "";
    $pdvCol = $hasPdv ? ", pp.name AS pdv_point_name" : ", NULL AS pdv_point_name";

    $stmt = $db->prepare("
        SELECT p.id, p.name, CAST(p.price AS FLOAT) AS price, p.stock_qty, p.sector {$pdvCol}
        FROM products p {$pdvJoin}
        WHERE p.event_id = ? AND {$sectorWhere} AND COALESCE(p.stock_qty, 0) > 0
        ORDER BY p.name ASC
    ");
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

// ---------------------------------------------------------------------------
// Authenticated endpoints
// ---------------------------------------------------------------------------

function getMyTicketsB2C(int $userId, array $query): void
{
    $db = Database::getInstance();
    $eventId = (int)($query['event_id'] ?? 0);
    $whereEvent = $eventId > 0 ? "AND t.event_id = ?" : "";
    $params = [$userId];
    if ($eventId > 0) $params[] = $eventId;

    $stmt = $db->prepare("
        SELECT t.id, t.event_id, t.status, t.qr_token, t.order_reference, t.holder_name, t.created_at,
               e.name AS event_name, e.start_date AS event_date, e.location AS event_location,
               tt.name AS ticket_type, tt.sector
        FROM tickets t
        JOIN events e ON e.id = t.event_id
        LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id
        WHERE t.customer_id = ? {$whereEvent}
        ORDER BY e.start_date DESC
    ");
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function getWalletB2C(int $userId, array $query): void
{
    $db = Database::getInstance();
    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId <= 0) { jsonSuccess(['balance' => 0]); return; }

    $stmt = $db->prepare("
        SELECT dc.id AS card_id, dc.balance, e.name AS event_name
        FROM digital_cards dc JOIN events e ON e.id = dc.event_id
        WHERE dc.user_id = ? AND dc.event_id = ? AND dc.is_active = true LIMIT 1
    ");
    $stmt->execute([$userId, $eventId]);
    $card = $stmt->fetch(\PDO::FETCH_ASSOC);
    jsonSuccess([
        'card_id' => $card ? (int)$card['card_id'] : null,
        'balance' => $card ? (float)$card['balance'] : 0,
        'event_name' => $card['event_name'] ?? null,
    ]);
}

function getTransactionsB2C(int $userId, array $query): void
{
    $db = Database::getInstance();
    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId <= 0) { jsonSuccess([]); return; }

    $stmt = $db->prepare("
        SELECT ct.id, ct.type, ct.amount, ct.description, ct.created_at, ct.status
        FROM card_transactions ct
        JOIN digital_cards dc ON dc.id = ct.card_id
        WHERE dc.user_id = ? AND dc.event_id = ?
        ORDER BY ct.created_at DESC LIMIT 50
    ");
    $stmt->execute([$userId, $eventId]);
    jsonSuccess($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function requestRechargeB2C(int $userId, array $body): void
{
    $eventId = (int)($body['event_id'] ?? 0);
    $amount = (float)($body['amount'] ?? 0);
    if ($eventId <= 0) jsonError('event_id obrigatorio', 422);
    if ($amount <= 0 || $amount > 5000) jsonError('Valor entre R$1 e R$5.000', 422);

    $pixCode = 'PIX-B2C-' . $userId . '-' . $eventId . '-' . time();
    jsonSuccess(['status' => 'pending', 'amount' => $amount, 'pix_code' => $pixCode], 'PIX gerado. Pague para creditar.');
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function b2cTableExists(\PDO $db, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try { $db->query("SELECT 1 FROM {$table} LIMIT 1"); return $cache[$table] = true; }
    catch (\Exception $e) { return $cache[$table] = false; }
}

function b2cColumnExists(\PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = "{$table}.{$column}";
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name=?)");
    $stmt->execute([$table, $column]);
    return $cache[$key] = (bool)$stmt->fetchColumn();
}
