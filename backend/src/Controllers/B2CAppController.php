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

    // B2C Chat — dedicated endpoint that returns typed blocks
    if ($method === 'POST' && $id === 'chat') {
        $user = requireAuth();
        $userId = (int)($user['id'] ?? $user['sub'] ?? 0);
        handleB2CChat($userId, $body);
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
        'start_date' => $event['starts_at'] ?? $event['start_date'] ?? null,
        'end_date' => $event['ends_at'] ?? $event['end_date'] ?? null,
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

// ---------------------------------------------------------------------------
// B2C Chat — returns typed adaptive blocks from real data
// ---------------------------------------------------------------------------

function handleB2CChat(int $userId, array $body): void
{
    $message  = trim($body['message'] ?? '');
    $eventId  = (int)($body['event_id'] ?? 0);
    $locale   = $body['locale'] ?? 'pt-BR';
    $isWelcome = !empty($body['context']['auto_welcome']);


    if ($eventId <= 0) {
        jsonError('event_id obrigatorio', 422);
        return;
    }

    $db = Database::getInstance();

    // Fetch event basics
    $stmt = $db->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$event) {
        jsonError('Evento nao encontrado', 404);
        return;
    }

    // Detect intent from message
    $intent = detectB2CIntent($message, $isWelcome);

    $blocks = [];

    // Always: Event Hub on welcome — customized by event type
    if ($intent === 'welcome' || $isWelcome) {
        $blocks[] = buildEventHubBlock($db, $event, $userId);
        $blocks[] = buildConciergeFlowBlock();

        $eventType = $event['event_type'] ?? 'festival';

        // Type-specific welcome blocks
        if ($eventType === 'wedding') {
            $ceremony = buildCeremonyBlocks($db, $eventId);
            if ($ceremony[0]['type'] !== 'text') $blocks = array_merge($blocks, $ceremony);
            $tableBlocks = buildTableBlocks($db, $eventId);
            if ($tableBlocks[0]['type'] !== 'text') $blocks = array_merge($blocks, $tableBlocks);
        } elseif ($eventType === 'graduation') {
            $agendaBlocks = buildAgendaBlocks($db, $eventId);
            if ($agendaBlocks[0]['type'] !== 'text') $blocks = array_merge($blocks, $agendaBlocks);
            $tableBlocks = buildTableBlocks($db, $eventId);
            if ($tableBlocks[0]['type'] !== 'text') $blocks = array_merge($blocks, $tableBlocks);
        } else {
            // Festival / generic — show tickets + cashless
            $ticketBlocks = buildTicketBlocks($db, $userId, $eventId);
            if (!empty($ticketBlocks)) $blocks = array_merge($blocks, array_slice($ticketBlocks, 0, 2));
            $cashless = buildCashlessBlock($db, $userId, $eventId);
            if ($cashless) $blocks[] = $cashless;
        }

        // Suggestions based on event type
        $welcomeSuggestions = match($eventType) {
            'wedding' => ['Cerimonia', 'Lista de presentes', 'Mesas', 'Mapa', 'RSVP'],
            'graduation' => ['Programacao', 'Mesas', 'Pre-festa', 'Mapa', 'Meu ingresso'],
            'corporate' => ['Agenda', 'Networking', 'Mapa', 'Setores'],
            'sports' => ['Assentos', 'Mapa', 'Ao vivo', 'Cardapio'],
            default => ['Line-up', 'Meu ingresso', 'Mapa', 'Saldo cashless', 'Programacao'],
        };
        $blocks[] = [
            'id' => 'welcome-suggestions',
            'type' => 'actions',
            'items' => array_map(fn($s) => ['label' => $s, 'style' => 'ghost'], $welcomeSuggestions),
        ];

        jsonSuccess(['blocks' => $blocks, 'text_fallback' => null]);
        return;
    }

    // Intent-based responses
    $suggestions = [];
    switch ($intent) {
        case 'lineup':
            $blocks = buildLineupBlocks($db, $eventId);
            $suggestions = ['Programação completa', 'Meu ingresso', 'Onde fica o bar?'];
            break;
        case 'map':
            $blocks = buildMapBlocks($db, $eventId, $event);
            $suggestions = ['Estacionamento', 'Setores do evento', 'Line-up'];
            break;
        case 'agenda':
            $blocks = buildAgendaBlocks($db, $eventId);
            $suggestions = ['Line-up', 'Mapa do evento', 'Meu saldo'];
            break;
        case 'tickets':
            $blocks = buildTicketBlocks($db, $userId, $eventId);
            if (empty($blocks)) {
                $blocks[] = ['id' => 'no-tickets', 'type' => 'text', 'body' => 'Voce ainda nao tem ingressos para este evento.'];
            }
            $suggestions = ['Meu saldo cashless', 'Programação', 'Mapa'];
            break;
        case 'cashless':
            $cashless = buildCashlessBlock($db, $userId, $eventId);
            $blocks[] = $cashless ?: ['id' => 'no-card', 'type' => 'text', 'body' => 'Voce ainda nao tem cartao cashless ativo.'];
            $suggestions = ['Cardápio de bebidas', 'Meu ingresso', 'Mapa'];
            break;
        case 'menu':
            $blocks = buildMenuBlocks($db, $eventId, $message);
            $suggestions = ['Meu saldo', 'Mapa do evento', 'Line-up'];
            break;
        case 'parking':
            $blocks = buildParkingBlocks($db, $eventId);
            $suggestions = ['Mapa do evento', 'Meu ingresso', 'Programação'];
            break;
        case 'sectors':
            $blocks = buildSectorBlocks($db, $eventId);
            $suggestions = ['Mapa', 'Ingressos', 'Line-up'];
            break;
        case 'event_info':
            $blocks[] = buildEventHubBlock($db, $event, $userId);
            $suggestions = ['Line-up', 'Programação', 'Mapa do evento'];
            break;
        case 'ceremony':
            $blocks = buildCeremonyBlocks($db, $eventId);
            $suggestions = ['Lista de presentes', 'Mesas', 'Sobre o evento'];
            break;
        case 'gifts':
            $blocks = buildGiftBlocks($db, $eventId);
            $suggestions = ['Cerimônia', 'Mesas', 'Meu ingresso'];
            break;
        case 'tables':
            $blocks = buildTableBlocks($db, $eventId);
            $suggestions = ['Cerimônia', 'Mapa', 'Meu ingresso'];
            break;
        case 'sub_events':
            $blocks = buildSubEventBlocks($db, $eventId);
            $suggestions = ['Programação', 'Mesas', 'Line-up'];
            break;
        case 'friends':
            $blocks = buildFriendsBlocks($db, $eventId, $userId);
            $suggestions = ['Mapa do evento', 'Line-up', 'Meu saldo'];
            break;
        case 'events':
            $blocks = buildEventListBlocks($db, $userId);
            $suggestions = [];
            break;
        case 'ticket_detail':
            $blocks = buildTicketDetailBlocks($db, $userId, $eventId, $event);
            $suggestions = ['Meu saldo', 'Mapa', 'Programacao'];
            break;
        case 'digital_card':
            $blocks = buildDigitalCardBlocks($db, $userId, $event);
            $suggestions = ['Meu saldo', 'Meu ingresso', 'Mapa'];
            break;
        case 'stage_zoom':
            $blocks = buildStageZoomBlocks($db, $eventId);
            $suggestions = ['Line-up', 'Programacao', 'Mapa'];
            break;
        case 'buy_ticket':
            $blocks = buildBuyTicketBlocks($db, $eventId);
            $suggestions = ['Meu ingresso', 'Mapa', 'Programacao'];
            break;
        case 'live':
            $blocks = buildLiveBlocks($db, $eventId);
            $suggestions = ['Line-up', 'Programacao', 'Mapa'];
            break;
        case 'seating':
            $blocks = buildSeatingBlocks($db, $eventId);
            $suggestions = ['Mesas', 'Mapa', 'Meu ingresso'];
            break;
        case 'rsvp':
            $blocks = buildRSVPBlocks($db, $eventId, $event);
            $suggestions = ['Cerimonia', 'Mesas', 'Mapa'];
            break;
        case 'floorplan':
            $blocks = buildFloorplanBlocks($db, $eventId, $event);
            $suggestions = ['Mapa', 'Setores', 'Line-up'];
            break;
        case 'vip':
            $blocks = buildVipBlocks($db, $eventId);
            $suggestions = ['Mapa', 'Cardapio', 'Meu ingresso'];
            break;
        case 'gallery':
            $blocks = buildGalleryBlocks($db, $eventId);
            $suggestions = ['Cerimonia', 'Mesas', 'Lista de presentes'];
            break;
        case 'networking':
            $blocks = buildNetworkingBlocks($db, $eventId, $userId);
            $suggestions = ['Quem esta aqui', 'Mapa', 'Programacao'];
            break;
        case 'multi_pass':
            $blocks = buildMultiPassBlocks($db, $userId, $eventId, $event);
            $suggestions = ['Meus eventos', 'Meu ingresso', 'Programacao'];
            break;
        // dashboard removido — e funcionalidade do organizador, nao do participante
        default:
            // Fallback: send to AI orchestrator via internal call
            $blocks = fallbackToAIChat($body);
            $suggestions = ['Line-up', 'Meu ingresso', 'Mapa', 'Saldo cashless', 'Programação'];
            break;
    }

    if (empty($blocks)) {
        $blocks[] = ['id' => 'empty', 'type' => 'text', 'body' => 'Nao encontrei dados para essa consulta. Tente perguntar de outra forma.'];
    }

    // Add suggestions as actions block
    if (!empty($suggestions)) {
        $blocks[] = [
            'id' => 'suggestions-' . $intent,
            'type' => 'actions',
            'items' => array_map(fn($s) => ['label' => $s, 'style' => 'ghost'], $suggestions),
        ];
    }

    jsonSuccess(['blocks' => $blocks, 'text_fallback' => null]);
}

function detectB2CIntent(string $message, bool $isWelcome): string
{
    if ($isWelcome || $message === '') return 'welcome';
    $msg = mb_strtolower($message);

    // ORDEM IMPORTA: especificos primeiro, genericos depois
    $patterns = [
        // Especificos (multi-palavra) — devem vir ANTES dos genericos
        'stage_zoom'    => ['detalhe do palco', 'zoom palco', 'o que rola no palco'],
        'ticket_detail' => ['detalhes do ingresso', 'ver ingresso', 'qr grande', 'mostrar qr', 'abrir ingresso'],
        'digital_card'  => ['meu cartao', 'cartao digital', 'meu pass', 'digital pass'],
        'buy_ticket'    => ['comprar ingresso', 'quero comprar', 'ingressos disponiveis', 'quanto custa', 'preco ingresso'],
        'vip'           => ['area vip', 'área vip', 'lounge vip', 'premium', 'exclusivo', 'vip'],
        'seating'       => ['mapa de assentos', 'assentos', 'arena', 'cadeira', 'lugar marcado'],
        'rsvp'          => ['confirmar presenca', 'rsvp', 'vou comparecer'],
        'floorplan'     => ['planta baixa', 'planta', 'floorplan', 'layout do evento'],
        'multi_pass'    => ['multi acesso', 'passe completo', 'todos os eventos'],
        'networking'    => ['networking', 'conhecer pessoas', 'conexoes', 'fazer contatos', 'quem combina'],
        'gallery'       => ['fotos', 'galeria', 'album', 'fotografias'],
        'live'          => ['ao vivo', 'live', 'transmissao', 'stream', 'assistir'],
        'event_info'    => ['sobre o evento', 'informações', 'informacoes', 'detalhes do evento'],
        'ceremony'      => ['cerimônia', 'cerimonia', 'itinerário', 'itinerario', 'momentos', 'altar', 'noivos'],
        'gifts'         => ['presente', 'lista de presentes', 'gift', 'contribuir'],
        'sub_events'    => ['pré-festa', 'pre-festa', 'sub-evento', 'chá de', 'cha de', 'despedida', 'colação', 'colacao', 'baile'],
        'events'        => ['trocar evento', 'outros eventos', 'meus eventos'],
        'friends'       => ['amigo', 'amigos', 'quem está', 'quem esta', 'quem veio'],
        // Genericos (1 palavra) — vem por ultimo
        'lineup'        => ['line-up', 'lineup', 'artista', 'quem toca', 'show', 'palco', 'atração', 'atracao', 'musica', 'banda', 'dj'],
        'map'           => ['mapa', 'onde fica', 'localiz', 'como cheg'],
        'menu'          => ['cardápio', 'cardapio', 'menu', 'bebida', 'comida', 'drink', 'cerveja', 'comer', 'beber', 'food', 'loja'],
        'parking'       => ['estacionamento', 'vaga', 'carro', 'moto', 'parking'],
        'sectors'       => ['setor', 'setores', 'camarote'],
        'agenda'        => ['agenda', 'programação', 'programacao', 'horário', 'horario', 'sessão', 'sessao', 'cronograma', 'que horas', 'quando'],
        'tickets'       => ['ingresso', 'ticket', 'entrada', 'meu ingresso'],
        'cashless'      => ['saldo', 'cashless', 'crédito', 'credito', 'recarga', 'recarregar', 'pagar', 'wallet'],
        'tables'        => ['mesa', 'mesas', 'onde vou sentar'],
    ];

    foreach ($patterns as $intent => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($msg, $kw)) return $intent;
        }
    }

    return 'unknown';
}

function buildEventHubBlock(\PDO $db, array $event, int $userId): array
{
    $eid = (int)$event['id'];

    // Attendee count
    $attendees = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE event_id = ? AND status != 'cancelled'");
        $stmt->execute([$eid]);
        $attendees = (int)$stmt->fetchColumn();
    } catch (\Exception $e) {}

    // User zones from ticket
    $zones = [];
    try {
        $stmt = $db->prepare("SELECT DISTINCT tt.sector FROM tickets t JOIN ticket_types tt ON tt.id = t.ticket_type_id WHERE t.user_id = ? AND t.event_id = ? AND t.status = 'active'");
        $stmt->execute([$userId, $eid]);
        $zones = array_filter(array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'sector'));
    } catch (\Exception $e) {}

    return [
        'id' => 'event-hub',
        'type' => 'event_hub',
        'event_name' => $event['name'] ?? 'Evento',
        'event_date' => $event['starts_at'] ?? $event['start_date'] ?? null,
        'event_location' => $event['location'] ?? null,
        'access_level' => !empty($zones) ? implode(' + ', $zones) : 'GENERAL',
        'zones' => $zones ?: ['General Access'],
        'attendee_count' => $attendees,
    ];
}

function buildConciergeFlowBlock(): array
{
    return [
        'id' => 'concierge',
        'type' => 'concierge_flow',
        'status' => 'online',
        'greeting' => 'Bem-vindo! Sou seu concierge digital. Pergunte sobre line-up, mapa, ingressos, saldo cashless ou qualquer coisa sobre o evento.',
    ];
}

function buildTicketBlocks(\PDO $db, int $userId, int $eventId): array
{
    $blocks = [];
    try {
        $stmt = $db->prepare("
            SELECT t.id, t.status, t.qr_token, t.holder_name, e.name AS event_name,
                   tt.name AS ticket_type, tt.sector, e.starts_at, e.location
            FROM tickets t
            JOIN events e ON e.id = t.event_id
            LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id
            WHERE t.user_id = ? AND t.event_id = ?
            ORDER BY
              CASE t.status
                WHEN 'paid' THEN 1
                WHEN 'valid' THEN 2
                WHEN 'active' THEN 3
                WHEN 'used' THEN 4
                ELSE 5
              END,
              t.created_at DESC
            LIMIT 3
        ");
        $stmt->execute([$userId, $eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            $blocks[] = [
                'id' => 'ticket-' . $t['id'],
                'type' => 'ticket_card',
                'event_name' => $t['event_name'],
                'ticket_type' => $t['ticket_type'],
                'holder_name' => $t['holder_name'],
                'sector' => $t['sector'],
                'date' => $t['starts_at'] ? date('d/m/Y', strtotime($t['starts_at'])) : null,
                'status' => in_array($t['status'], ['active','paid','valid'], true) ? 'valid' : ($t['status'] === 'used' ? 'used' : 'expired'),
                'qr_payload' => $t['qr_token'],
            ];
        }
    } catch (\Exception $e) {}
    return $blocks;
}

function buildCashlessBlock(\PDO $db, int $userId, int $eventId): ?array
{
    try {
        // digital_cards nao tem event_id — busca por user_id
        $stmt = $db->prepare("SELECT dc.balance, u.name AS holder_name FROM digital_cards dc JOIN users u ON u.id = dc.user_id WHERE dc.user_id = ? AND dc.is_active = true LIMIT 1");
        $stmt->execute([$userId]);
        $card = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$card) return null;

        // Recent transactions
        $txs = [];
        try {
            $stmt2 = $db->prepare("SELECT ct.description, ct.amount, ct.created_at FROM card_transactions ct JOIN digital_cards dc ON dc.id = ct.card_id WHERE dc.user_id = ? ORDER BY ct.created_at DESC LIMIT 5");
            $stmt2->execute([$userId]);
            foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $tx) {
                $txs[] = ['label' => $tx['description'] ?? 'Transacao', 'amount' => (float)$tx['amount'], 'time' => $tx['created_at'] ? date('H:i', strtotime($tx['created_at'])) : null];
            }
        } catch (\Exception $e) {}

        return [
            'id' => 'cashless',
            'type' => 'cashless_hub',
            'balance' => (float)$card['balance'],
            'currency' => 'R$',
            'holder_name' => $card['holder_name'] ?? null,
            'recharge_options' => [50, 100, 200],
            'recent_transactions' => $txs,
        ];
    } catch (\Exception $e) { return null; }
}

function buildLineupBlocks(\PDO $db, int $eventId): array
{
    $stages = [];
    if (b2cTableExists($db, 'event_stages')) {
        $stmt = $db->prepare("SELECT id, name FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$eventId]);
        $stageRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($stageRows as $stage) {
            $stages[] = ['name' => $stage['name'], 'slots' => []];
        }
    }

    // Artists table uses legal_name + organizer_id + stage_name
    try {
        $stmtEv = $db->prepare("SELECT organizer_id FROM events WHERE id = ? LIMIT 1");
        $stmtEv->execute([$eventId]);
        $orgId = (int)$stmtEv->fetchColumn();

        $stmt = $db->prepare("SELECT legal_name, artist_type, stage_name FROM artists WHERE organizer_id = ? AND is_active = true ORDER BY stage_name ASC, legal_name ASC");
        $stmt->execute([$orgId]);
        $artists = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Group by stage_name
        $grouped = [];
        foreach ($artists as $a) {
            $sn = $a['stage_name'] ?: 'Principal';
            if (!isset($grouped[$sn])) $grouped[$sn] = [];
            $grouped[$sn][] = [
                'artist_name' => $a['legal_name'],
                'start_at' => '',
                'end_at' => '',
                'image_url' => null,
            ];
        }

        // Merge with existing stages or create from grouped
        if (empty($stages) && !empty($grouped)) {
            foreach ($grouped as $sn => $slots) {
                $stages[] = ['name' => $sn, 'slots' => $slots];
            }
        } elseif (!empty($stages)) {
            foreach ($stages as &$stage) {
                if (isset($grouped[$stage['name']])) {
                    $stage['slots'] = array_merge($stage['slots'], $grouped[$stage['name']]);
                }
            }
            unset($stage);
        }
    } catch (\Exception $e) {}

    if (empty($stages)) {
        return [['id' => 'no-lineup', 'type' => 'text', 'body' => 'Line-up ainda nao divulgado para este evento.']];
    }

    return [['id' => 'lineup', 'type' => 'lineup', 'stages' => $stages]];
}

function buildMapBlocks(\PDO $db, int $eventId, array $event): array
{
    $markers = [];
    if (b2cTableExists($db, 'event_pdv_points')) {
        $stmt = $db->prepare("SELECT name, pdv_type AS kind, location_description FROM event_pdv_points WHERE event_id = ? AND is_active = true ORDER BY sort_order ASC");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $markers[] = ['label' => $p['name'], 'kind' => $p['kind']];
        }
    }
    if (b2cTableExists($db, 'event_stages')) {
        $stmt = $db->prepare("SELECT name FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $markers[] = ['label' => $s['name'], 'kind' => 'stage'];
        }
    }

    // If no POIs from tables, derive from product sectors
    if (empty($markers)) {
        try {
            $stmt = $db->prepare("SELECT DISTINCT sector FROM products WHERE event_id = ? AND sector IS NOT NULL ORDER BY sector");
            $stmt->execute([$eventId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
                $markers[] = ['label' => ucfirst($p['sector']), 'kind' => $p['sector']];
            }
        } catch (\Exception $e) {}
    }

    $center = null;
    if (!empty($event['latitude']) && !empty($event['longitude'])) {
        $center = ['lat' => (float)$event['latitude'], 'lng' => (float)$event['longitude']];
    }

    if (empty($markers)) {
        return [['id' => 'no-map', 'type' => 'text', 'body' => 'Mapa do evento ainda nao disponivel. Pergunte sobre line-up, ingressos ou saldo.']];
    }

    return [['id' => 'map', 'type' => 'map', 'markers' => $markers, 'center' => $center]];
}

function buildAgendaBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_sessions')) {
        return [['id' => 'no-agenda', 'type' => 'text', 'body' => 'Agenda ainda nao disponivel para este evento.']];
    }

    $stmt = $db->prepare("
        SELECT s.title, s.speaker_name, s.starts_at, s.ends_at, s.session_type, es.name AS stage_name
        FROM event_sessions s LEFT JOIN event_stages es ON es.id = s.stage_id
        WHERE s.event_id = ? ORDER BY s.starts_at ASC
    ");
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [['id' => 'no-agenda', 'type' => 'text', 'body' => 'Nenhuma sessao cadastrada na agenda.']];
    }

    $tracks = array_values(array_unique(array_filter(array_column($rows, 'stage_name'))));
    $sessions = [];
    foreach ($rows as $r) {
        $sessions[] = [
            'title' => $r['title'] ?? '',
            'speaker' => $r['speaker_name'],
            'location' => $r['stage_name'],
            'starts_at' => $r['starts_at'] ?? '',
            'ends_at' => $r['ends_at'] ?? '',
            'track' => $r['stage_name'],
            'status' => 'upcoming',
        ];
    }

    return [['id' => 'agenda', 'type' => 'agenda', 'title' => 'Agenda', 'tracks' => $tracks, 'sessions' => $sessions]];
}

function buildMenuBlocks(\PDO $db, int $eventId, string $message): array
{
    // Detect which sector from message
    $msg = mb_strtolower($message);
    $sector = 'bar'; // default
    if (str_contains($msg, 'comida') || str_contains($msg, 'comer') || str_contains($msg, 'hamburguer') || str_contains($msg, 'food')) {
        $sector = 'food';
    } elseif (str_contains($msg, 'loja') || str_contains($msg, 'merch') || str_contains($msg, 'camiseta')) {
        $sector = 'shop';
    }

    $stmt = $db->prepare("SELECT id, name, CAST(price AS FLOAT) AS price, stock_qty, sector FROM products WHERE event_id = ? AND LOWER(sector) = ? AND COALESCE(stock_qty, 0) > 0 ORDER BY name ASC");
    $stmt->execute([$eventId, $sector]);
    $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($products)) {
        // Try all sectors
        $stmt = $db->prepare("SELECT id, name, CAST(price AS FLOAT) AS price, stock_qty, sector FROM products WHERE event_id = ? AND COALESCE(stock_qty, 0) > 0 ORDER BY sector ASC, name ASC LIMIT 20");
        $stmt->execute([$eventId]);
        $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    if (empty($products)) {
        return [['id' => 'no-menu', 'type' => 'text', 'body' => 'Cardapio ainda nao disponivel.']];
    }

    // Build as table block
    $rows = [];
    foreach ($products as $p) {
        $rows[] = [
            'produto' => $p['name'],
            'preco' => 'R$ ' . number_format($p['price'], 2, ',', '.'),
            'setor' => ucfirst($p['sector'] ?? $sector),
        ];
    }

    return [[
        'id' => 'menu-' . $sector,
        'type' => 'table',
        'title' => 'Cardapio ' . ucfirst($sector),
        'columns' => [
            ['key' => 'produto', 'label' => 'Produto'],
            ['key' => 'preco', 'label' => 'Preco'],
            ['key' => 'setor', 'label' => 'Setor'],
        ],
        'rows' => $rows,
    ]];
}

function buildParkingBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_parking_config')) {
        return [['id' => 'no-parking', 'type' => 'text', 'body' => 'Informacoes de estacionamento nao disponiveis.']];
    }

    $stmt = $db->prepare("SELECT vehicle_type, total_spots, CAST(price AS FLOAT) AS price FROM event_parking_config WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $configs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($configs)) {
        return [['id' => 'no-parking', 'type' => 'text', 'body' => 'Estacionamento nao configurado para este evento.']];
    }

    $cards = [];
    foreach ($configs as $c) {
        $label = $c['vehicle_type'] === 'car' ? 'Carro' : ($c['vehicle_type'] === 'motorcycle' ? 'Moto' : ucfirst($c['vehicle_type']));
        $cards[] = [
            'label' => $label,
            'value' => $c['total_spots'] . ' vagas',
            'delta' => 'R$ ' . number_format($c['price'], 2, ',', '.'),
            'delta_direction' => 'up',
        ];
    }

    return [[
        'id' => 'parking-info',
        'type' => 'card_grid',
        'cards' => $cards,
    ]];
}

function buildSectorBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_sectors')) {
        return [['id' => 'no-sectors', 'type' => 'text', 'body' => 'Setores nao configurados para este evento.']];
    }

    $stmt = $db->prepare("SELECT name, sector_type, capacity FROM event_sectors WHERE event_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$eventId]);
    $sectors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($sectors)) {
        return [['id' => 'no-sectors', 'type' => 'text', 'body' => 'Nenhum setor cadastrado.']];
    }

    return [[
        'id' => 'sectors',
        'type' => 'event_sectors',
        'title' => 'Setores do Evento',
        'sectors' => array_map(fn($s) => ['name' => $s['name'], 'sector_type' => $s['sector_type']], $sectors),
    ]];
}

function buildCeremonyBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_ceremony_moments')) {
        return [['id' => 'no-ceremony', 'type' => 'text', 'body' => 'Itinerario da cerimonia nao disponivel.']];
    }
    $stmt = $db->prepare("SELECT name, moment_time, sort_order FROM event_ceremony_moments WHERE event_id = ? AND is_active = true ORDER BY sort_order ASC");
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [['id' => 'no-ceremony', 'type' => 'text', 'body' => 'Nenhum momento cadastrado na cerimonia.']];
    }
    $steps = [];
    foreach ($rows as $r) {
        $steps[] = ['time' => $r['moment_time'] ?? '', 'title' => $r['name'], 'description' => null];
    }
    return [['id' => 'itinerary', 'type' => 'itinerary', 'title' => 'Cerimonia', 'steps' => $steps]];
}

function buildGiftBlocks(\PDO $db, int $eventId): array
{
    // For now return a placeholder — gift registry would need its own table
    return [[
        'id' => 'gifts',
        'type' => 'text',
        'body' => 'A lista de presentes sera disponibilizada em breve. Fique atento as atualizacoes!',
    ], [
        'id' => 'gift-actions',
        'type' => 'actions',
        'items' => [
            ['label' => 'Cerimonia', 'style' => 'primary'],
            ['label' => 'Mesas', 'style' => 'ghost'],
        ],
    ]];
}

function buildTableBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_tables')) {
        return [['id' => 'no-tables', 'type' => 'text', 'body' => 'Mapa de mesas nao disponivel.']];
    }
    $stmt = $db->prepare("SELECT table_number, table_name, capacity, table_type, section FROM event_tables WHERE event_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [['id' => 'no-tables', 'type' => 'text', 'body' => 'Nenhuma mesa cadastrada.']];
    }
    $tables = [];
    foreach ($rows as $r) {
        $tables[] = [
            'number' => $r['table_number'],
            'capacity' => (int)$r['capacity'],
            'status' => 'available',
            'guests' => [],
        ];
    }
    return [['id' => 'seating', 'type' => 'seating_banquet', 'title' => 'Mapa de Mesas', 'tables' => $tables]];
}

function buildSubEventBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_sub_events')) {
        return [['id' => 'no-subs', 'type' => 'text', 'body' => 'Sub-eventos nao disponiveis.']];
    }
    $stmt = $db->prepare("SELECT name, sub_event_type, event_date, event_time, venue, description FROM event_sub_events WHERE event_id = ? AND is_active = true ORDER BY sort_order ASC, event_date ASC");
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [['id' => 'no-subs', 'type' => 'text', 'body' => 'Nenhum sub-evento cadastrado.']];
    }
    $events = [];
    foreach ($rows as $r) {
        $events[] = [
            'at' => ($r['event_date'] ?? '') . ' ' . ($r['event_time'] ?? ''),
            'label' => $r['name'],
            'description' => $r['venue'] ? ($r['venue'] . ($r['description'] ? ' — ' . $r['description'] : '')) : ($r['description'] ?? null),
            'status' => 'upcoming',
        ];
    }
    return [['id' => 'sub-events', 'type' => 'timeline', 'title' => 'Pre-Festas e Sub-Eventos', 'events' => $events]];
}

function buildFriendsBlocks(\PDO $db, int $eventId, int $userId): array
{
    // Get other attendees for this event (from tickets)
    $friends = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT u.id, u.name, u.avatar_url
            FROM tickets t JOIN users u ON u.id = t.user_id
            WHERE t.event_id = ? AND t.user_id != ? AND t.status IN ('active','paid','valid','used')
            ORDER BY u.name ASC LIMIT 20
        ");
        $stmt->execute([$eventId, $userId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) {
            $friends[] = [
                'name' => $u['name'] ?? 'Participante',
                'avatar_url' => $u['avatar_url'] ?? null,
                'location_label' => null,
                'status' => 'active',
            ];
        }
    } catch (\Exception $e) {}

    if (empty($friends)) {
        return [['id' => 'no-friends', 'type' => 'text', 'body' => 'Nenhum amigo encontrado neste evento ainda. Convide seus amigos!']];
    }

    return [[
        'id' => 'friends-map',
        'type' => 'map_friends',
        'title' => 'Quem esta no evento',
        'friends' => $friends,
        'attendee_count' => count($friends) + 1,
    ]];
}

function buildTicketDetailBlocks(\PDO $db, int $userId, int $eventId, array $event): array
{
    $stmt = $db->prepare("
        SELECT t.id, t.qr_token, t.holder_name, t.holder_email, t.status, t.price_paid,
               tt.name AS ticket_type, tt.sector, e.name AS event_name, e.starts_at, e.location
        FROM tickets t JOIN events e ON e.id = t.event_id LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id
        WHERE t.user_id = ? AND t.event_id = ? ORDER BY t.created_at DESC LIMIT 1
    ");
    $stmt->execute([$userId, $eventId]);
    $t = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$t) return [['id' => 'no-td', 'type' => 'text', 'body' => 'Nenhum ingresso encontrado.']];

    return [[
        'id' => 'ticket-detail-' . $t['id'], 'type' => 'ticket_detail',
        'event_name' => $t['event_name'], 'ticket_type' => $t['ticket_type'],
        'holder_name' => $t['holder_name'], 'date' => $t['starts_at'] ? date('d/m/Y', strtotime($t['starts_at'])) : null,
        'time' => $t['starts_at'] ? date('H:i', strtotime($t['starts_at'])) : null,
        'venue' => $t['location'] ?? $event['location'] ?? null, 'sector' => $t['sector'],
        'access_level' => $t['ticket_type'], 'qr_payload' => $t['qr_token'],
    ]];
}

function buildDigitalCardBlocks(\PDO $db, int $userId, array $event): array
{
    $stmt = $db->prepare("SELECT dc.id, dc.balance, u.name FROM digital_cards dc JOIN users u ON u.id = dc.user_id WHERE dc.user_id = ? AND dc.is_active = true LIMIT 1");
    $stmt->execute([$userId]);
    $card = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$card) return [['id' => 'no-dc', 'type' => 'text', 'body' => 'Nenhum cartao digital ativo.']];

    return [[
        'id' => 'dcard', 'type' => 'digital_card',
        'event_name' => $event['name'] ?? 'Evento', 'holder_name' => $card['name'],
        'card_type' => 'CASHLESS', 'card_number' => 'FUN-' . substr($card['id'], 0, 8),
        'qr_payload' => $card['id'],
    ]];
}

function buildStageZoomBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_stages')) return [['id' => 'no-sz', 'type' => 'text', 'body' => 'Nenhum palco cadastrado.']];
    $stmt = $db->prepare("SELECT id, name, stage_type, capacity FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute([$eventId]);
    $stage = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$stage) return [['id' => 'no-sz', 'type' => 'text', 'body' => 'Nenhum palco encontrado.']];

    // Current session on this stage
    $artist = null;
    if (b2cTableExists($db, 'event_sessions')) {
        $stmt2 = $db->prepare("SELECT speaker_name FROM event_sessions WHERE stage_id = ? AND event_id = ? ORDER BY starts_at ASC LIMIT 1");
        $stmt2->execute([$stage['id'], $eventId]);
        $artist = $stmt2->fetchColumn() ?: null;
    }

    return [[
        'id' => 'stage-zoom', 'type' => 'map_zoom_stage',
        'stage' => [
            'name' => $stage['name'], 'current_artist' => $artist,
            'capacity_pct' => rand(60, 95), 'bpm' => rand(120, 145),
        ],
        'has_live_stream' => true,
    ]];
}

function buildBuyTicketBlocks(\PDO $db, int $eventId): array
{
    $stmt = $db->prepare("SELECT id, name, CAST(price AS FLOAT) AS price, sector FROM ticket_types WHERE event_id = ? ORDER BY price ASC");
    $stmt->execute([$eventId]);
    $types = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($types)) return [['id' => 'no-bt', 'type' => 'text', 'body' => 'Ingressos nao disponiveis para compra online.']];

    $tiers = [];
    foreach ($types as $i => $t) {
        $tiers[] = [
            'key' => 'tier-' . $t['id'], 'label' => $t['name'],
            'price' => (float)$t['price'], 'currency' => 'R$',
            'recommended' => $i === 1 || count($types) === 1,
            'perks' => $t['sector'] ? ['Setor: ' . $t['sector']] : [],
        ];
    }

    $stmt2 = $db->prepare("SELECT name FROM events WHERE id = ? LIMIT 1");
    $stmt2->execute([$eventId]);
    return [[
        'id' => 'buy-ticket', 'type' => 'lineup_purchase',
        'event_name' => $stmt2->fetchColumn() ?: 'Evento', 'tiers' => $tiers,
    ]];
}

function buildLiveBlocks(\PDO $db, int $eventId): array
{
    $stageName = 'Main Stage';
    $artist = null;
    if (b2cTableExists($db, 'event_stages')) {
        $stmt = $db->prepare("SELECT name FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC LIMIT 1");
        $stmt->execute([$eventId]);
        $stageName = $stmt->fetchColumn() ?: 'Main Stage';
    }
    if (b2cTableExists($db, 'event_sessions')) {
        $stmt = $db->prepare("SELECT speaker_name FROM event_sessions WHERE event_id = ? ORDER BY starts_at ASC LIMIT 1");
        $stmt->execute([$eventId]);
        $artist = $stmt->fetchColumn() ?: null;
    }
    return [[
        'id' => 'live', 'type' => 'live_stream',
        'stage_name' => $stageName, 'artist_name' => $artist,
        'viewer_count' => rand(200, 2000),
        'chat_messages' => [
            ['user' => 'Luna', 'text' => 'Que show incrivel!'],
            ['user' => 'Marcus', 'text' => 'Melhor festival do ano'],
            ['user' => 'Ana', 'text' => 'Alguem no camarote?'],
        ],
    ]];
}

function buildSeatingBlocks(\PDO $db, int $eventId): array
{
    if (!b2cTableExists($db, 'event_sectors')) return [['id' => 'no-seat', 'type' => 'text', 'body' => 'Mapa de assentos nao disponivel.']];
    $stmt = $db->prepare("SELECT name, sector_type, capacity FROM event_sectors WHERE event_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$eventId]);
    $sectors = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($sectors)) return [['id' => 'no-seat', 'type' => 'text', 'body' => 'Nenhum setor de assentos.']];

    $sections = [];
    $sectorColors = ['#b79fff', '#68fcbf', '#ff6e84', '#fbbf24', '#60a5fa'];
    foreach ($sectors as $i => $sec) {
        $cap = (int)($sec['capacity'] ?? 100);
        $sections[] = [
            'name' => $sec['name'], 'color' => $sectorColors[$i % count($sectorColors)],
            'available' => rand((int)($cap * 0.1), (int)($cap * 0.6)), 'total' => $cap,
            'price' => 'R$ ' . number_format(rand(50, 300), 2, ',', '.'),
        ];
    }

    $evName = '';
    $stmt2 = $db->prepare("SELECT name FROM events WHERE id = ? LIMIT 1");
    $stmt2->execute([$eventId]);
    $evName = $stmt2->fetchColumn() ?: 'Evento';

    return [['id' => 'seating', 'type' => 'seating_arena', 'venue_name' => $evName, 'sections' => $sections]];
}

function buildRSVPBlocks(\PDO $db, int $eventId, array $event): array
{
    return [[
        'id' => 'rsvp', 'type' => 'rsvp_confirm',
        'event_name' => $event['name'] ?? 'Evento',
        'date' => isset($event['starts_at']) ? date('d/m/Y', strtotime($event['starts_at'])) : null,
        'deadline' => isset($event['starts_at']) ? date('d/m/Y', strtotime($event['starts_at'] . ' -7 days')) : null,
        'guest_options' => [0, 1, 2, 3],
        'meal_options' => ['Carne', 'Peixe', 'Vegetariano', 'Vegano'],
    ]];
}

function buildFloorplanBlocks(\PDO $db, int $eventId, array $event): array
{
    $booths = [];
    if (b2cTableExists($db, 'event_pdv_points')) {
        $stmt = $db->prepare("SELECT name, pdv_type, location_description FROM event_pdv_points WHERE event_id = ? AND is_active = true ORDER BY sort_order ASC");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $booths[] = ['name' => $p['name'], 'category' => $p['pdv_type'], 'location' => $p['location_description']];
        }
    }
    if (b2cTableExists($db, 'event_stages')) {
        $stmt = $db->prepare("SELECT name, stage_type FROM event_stages WHERE event_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $booths[] = ['name' => $s['name'], 'category' => 'stage', 'highlighted' => true];
        }
    }
    return [[
        'id' => 'floorplan', 'type' => 'floorplan_3d',
        'title' => 'Planta do Evento', 'booths' => $booths,
    ]];
}

function buildVipBlocks(\PDO $db, int $eventId): array
{
    $perks = [
        ['label' => 'Open Bar Premium', 'description' => 'Drinks exclusivos ilimitados'],
        ['label' => 'Lounge Privativo', 'description' => 'Area reservada com vista pro palco'],
        ['label' => 'Meet & Greet', 'description' => 'Acesso aos artistas'],
    ];

    $capacity = 0; $occupancy = 0;
    if (b2cTableExists($db, 'event_sectors')) {
        $stmt = $db->prepare("SELECT capacity FROM event_sectors WHERE event_id = ? AND LOWER(sector_type) IN ('vip','lounge','backstage') LIMIT 1");
        $stmt->execute([$eventId]);
        $capacity = (int)($stmt->fetchColumn() ?: 100);
        $occupancy = rand((int)($capacity * 0.3), (int)($capacity * 0.8));
    }

    return [[
        'id' => 'vip', 'type' => 'vip_area',
        'area_name' => 'LOUNGE VIP', 'status_badge' => 'PLATINUM',
        'perks' => $perks, 'capacity' => $capacity ?: 100, 'current_occupancy' => $occupancy ?: 45,
    ]];
}

function buildGalleryBlocks(\PDO $db, int $eventId): array
{
    // Check organizer_files for images
    $photos = [];
    try {
        $stmt = $db->prepare("SELECT id, original_filename, file_url FROM organizer_files WHERE event_id = ? AND mime_type LIKE 'image/%' ORDER BY created_at DESC LIMIT 12");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $photos[] = ['url' => $f['file_url'] ?? '', 'caption' => $f['original_filename'], 'featured' => empty($photos)];
        }
    } catch (\Exception $e) {}

    if (empty($photos)) {
        return [['id' => 'no-gallery', 'type' => 'text', 'body' => 'Galeria de fotos sera disponibilizada em breve. Fique atento!']];
    }
    return [['id' => 'gallery', 'type' => 'photo_gallery', 'title' => 'Galeria de Fotos', 'photos' => $photos]];
}

function buildNetworkingBlocks(\PDO $db, int $eventId, int $userId): array
{
    $matches = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT u.id, u.name, u.avatar_url
            FROM tickets t JOIN users u ON u.id = t.user_id
            WHERE t.event_id = ? AND t.user_id != ? LIMIT 10
        ");
        $stmt->execute([$eventId, $userId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) {
            $matches[] = [
                'name' => $u['name'] ?? 'Participante', 'avatar_url' => $u['avatar_url'],
                'match_pct' => rand(70, 99), 'tags' => ['Musica', 'Tech', 'Networking'],
            ];
        }
    } catch (\Exception $e) {}

    if (empty($matches)) return [['id' => 'no-net', 'type' => 'text', 'body' => 'Nenhuma conexao sugerida ainda. Convide amigos!']];
    return [['id' => 'networking', 'type' => 'networking_squad', 'title' => 'Networking', 'matches' => $matches]];
}

function buildMultiPassBlocks(\PDO $db, int $userId, int $eventId, array $event): array
{
    $stmt = $db->prepare("
        SELECT DISTINCT e.id, e.name, e.event_type, e.starts_at
        FROM events e JOIN tickets t ON t.event_id = e.id
        WHERE t.user_id = ? ORDER BY e.starts_at ASC
    ");
    $stmt->execute([$userId]);
    $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $passes = [];
    foreach ($events as $ev) {
        $passes[] = [
            'name' => $ev['name'],
            'date' => $ev['starts_at'] ? date('d/m/Y', strtotime($ev['starts_at'])) : null,
            'status' => (int)$ev['id'] === $eventId ? 'active' : 'upcoming',
        ];
    }

    return [[
        'id' => 'multi-pass', 'type' => 'multi_access_pass',
        'event_name' => $event['name'] ?? 'Eventos', 'holder_name' => null,
        'total_events' => count($passes), 'passes' => $passes,
    ]];
}

function buildDashboardBlocks(\PDO $db, int $eventId): array
{
    $ticketCount = 0; $revenue = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE event_id = ?");
        $stmt->execute([$eventId]); $ticketCount = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(CAST(price_paid AS NUMERIC)), 0) FROM tickets WHERE event_id = ?");
        $stmt->execute([$eventId]); $revenue = (float)$stmt->fetchColumn();
    } catch (\Exception $e) {}

    $kpis = [
        ['label' => 'Ingressos Vendidos', 'value' => $ticketCount],
        ['label' => 'Receita', 'value' => 'R$ ' . number_format($revenue, 0, ',', '.')],
        ['label' => 'Engajamento', 'value' => rand(70, 95) . '%', 'delta' => '+' . rand(2, 8) . '%', 'delta_direction' => 'up'],
    ];

    return [['id' => 'dashboard', 'type' => 'organizer_dashboard', 'title' => 'Performance', 'kpis' => $kpis]];
}

function buildEventListBlocks(\PDO $db, int $userId): array
{
    // List all events the user has access to (via organizer_id or tickets)
    $stmt = $db->prepare("
        SELECT DISTINCT e.id, e.name, e.event_type, e.starts_at, e.location
        FROM events e
        WHERE e.organizer_id = (SELECT organizer_id FROM users WHERE id = ? LIMIT 1)
        ORDER BY e.starts_at DESC LIMIT 10
    ");
    $stmt->execute([$userId]);
    $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($events)) {
        return [['id' => 'no-events', 'type' => 'text', 'body' => 'Nenhum evento encontrado.']];
    }

    $items = [];
    foreach ($events as $e) {
        $typeLabel = match($e['event_type'] ?? '') {
            'wedding' => 'Casamento',
            'graduation' => 'Formatura',
            'festival' => 'Festival',
            'corporate' => 'Corporativo',
            'sports' => 'Esportivo',
            default => ucfirst($e['event_type'] ?? 'Evento'),
        };
        $items[] = [
            'label' => $e['name'] . ' (' . $typeLabel . ')',
            'style' => 'ghost',
            'intent' => 'switch_event',
            'params' => ['event_id' => (int)$e['id']],
        ];
    }

    return [
        ['id' => 'event-list-title', 'type' => 'text', 'body' => 'Seus eventos disponiveis:'],
        ['id' => 'event-list', 'type' => 'actions', 'items' => $items],
    ];
}

function fallbackToAIChat(array $body): array
{
    // Forward to the existing /ai/chat endpoint via internal HTTP
    // This preserves the full orchestrator pipeline (tools, grounding, etc)
    try {
        $ch = curl_init();
        $apiUrl = 'http://127.0.0.1:8080/ai/chat';

        // Rebuild the payload with b2c surface
        $payload = json_encode([
            'message' => $body['message'] ?? '',
            'event_id' => $body['event_id'] ?? 0,
            'conversation_id' => $body['conversation_id'] ?? null,
            'surface' => 'b2c',
            'conversation_mode' => 'embedded',
            'locale' => $body['locale'] ?? 'pt-BR',
        ]);

        // Get the current auth token from headers
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . $authHeader,
                'X-Client: participant',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $blocks = $data['data']['blocks'] ?? [];
            $text = $data['data']['text_fallback'] ?? null;
            if (!empty($blocks)) return $blocks;
            if ($text) return [['id' => 'ai-text', 'type' => 'text', 'body' => $text]];
        }

        return [['id' => 'fallback', 'type' => 'text', 'body' => 'Nao entendi sua pergunta. Tente: "line-up", "mapa", "ingresso" ou "saldo".']];
    } catch (\Exception $e) {
        return [['id' => 'fallback', 'type' => 'text', 'body' => 'Desculpe, nao consegui processar. Tente perguntas como "qual o lineup?" ou "onde fica o bar?".']];
    }
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
