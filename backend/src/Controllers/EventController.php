<?php
/**
 * Event Controller
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Bypass request via /api/test-event/1
    if (strpos($_SERVER['REQUEST_URI'], 'test-event') !== false) {
        getEventDetails((int)$id, false);
        return;
    }

    match (true) {
        $method === 'GET' && $id === null => listEvents(),
        $method === 'POST' && $id === null => createEvent($body),
        $method === 'GET' && is_numeric($id) => getEventDetails((int)$id),
        default => jsonError("Endpoint not found: {$method} /events/{$id}", 404),
    };
}

function listEvents(): void
{
    requireAuth();

    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name, slug, description, banner_url, venue_name, starts_at, ends_at, status, capacity FROM events ORDER BY starts_at ASC");
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($events);
    } catch (Exception $e) {
        jsonError("Failed to fetch events: " . $e->getMessage(), 500);
    }
}

function createEvent(array $body): void
{
    requireAuth();
    
    $name = trim($body['name'] ?? '');
    $startsAt = $body['starts_at'] ?? ''; // Nova nomenclatura obrigatória
    $venueName = $body['venue_name'] ?? 'Local a Definir';
    
    if (!$name) jsonError("O nome do evento é obrigatório.");
    if (!$startsAt) jsonError("A data de início (starts_at) é obrigatória.");

    // Gerar SLUG básico a partir do nome
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-')) . '-' . time();

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO events (name, slug, description, venue_name, starts_at, capacity)
            VALUES (?, ?, ?, ?, ?, ?) RETURNING id
        ");
        
        $desc = $body['description'] ?? '';
        $capacity = (int)($body['capacity'] ?? 0);
        
        $stmt->execute([$name, $slug, $desc, $venueName, $startsAt, $capacity]);
        $id = $stmt->fetchColumn();

        jsonSuccess(['id' => $id], "Evento criado com sucesso!", 201);
    } catch (Exception $e) {
        jsonError("Erro ao criar evento: " . $e->getMessage(), 500);
    }
}

function getEventDetails(int $id, bool $checkAuth = true): void
{
    // Opcional para acesso público vs privado. Bypass temporário para testes do front
    if ($checkAuth) {
        optionalAuth(); // Não força falha 401, apenas preenche se o token vier, permitindo leitura livre
    }

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name, slug, description, banner_url, venue_name, starts_at, ends_at, status, capacity FROM events WHERE id = ?");
        $stmt->execute([$id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            jsonError("Evento não encontrado.", 404);
        }

        jsonSuccess($event);
    } catch (Exception $e) {
        jsonError("Failed to fetch event details: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────
// JSON helpers
// ─────────────────────────────────────────────────────────────
function jsonSuccess(mixed $data, string $message = 'OK', int $code = 200): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON: ' . json_last_error_msg() . '", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}

function jsonError(string $message, int $code = 400, mixed $errors = null): void
{
    ini_set('display_errors', '0');
    if (ob_get_length()) ob_clean();

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['success' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Erro de serialização JSON: ' . json_last_error_msg() . '", "errors":null}';
    } else {
        echo $json;
    }
    exit;
}
