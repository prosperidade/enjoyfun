<?php
/**
 * Event Controller - Corrigido (Removido redeclaração de JSON helpers)
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
    $user = requireAuth();
    $organizerId = $user['organizer_id'] ?? null;

    if (!$organizerId) {
        jsonError("Usuário não possui organizer_id vinculado.", 403);
    }

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name, slug, description, starts_at, status FROM events WHERE organizer_id = ? ORDER BY starts_at ASC");
        $stmt->execute([$organizerId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonSuccess($events);
    } catch (Exception $e) {
        jsonError("Failed to fetch events: " . $e->getMessage(), 500);
    }
}

function createEvent(array $body): void
{
    $user = requireAuth();
    $organizerId = $user['organizer_id'] ?? null;

    if (!$organizerId) {
        jsonError("Usuário não possui organizer_id vinculado.", 403);
    }
    
    $name = trim($body['name'] ?? '');
    $startsAt = $body['starts_at'] ?? ''; 
    $venueName = $body['venue_name'] ?? 'Local a Definir';
    
    if (!$name) jsonError("O nome do evento é obrigatório.");
    if (!$startsAt) jsonError("A data de início (starts_at) é obrigatória.");

    // Gerar SLUG básico a partir do nome
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-')) . '-' . time();

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO events (name, slug, description, starts_at, status, organizer_id)
            VALUES (?, ?, ?, ?, ?, ?) RETURNING id
        ");
        
        $desc = $body['description'] ?? '';
        $status = 'published';
        
        $stmt->execute([$name, $slug, $desc, $startsAt, $status, $organizerId]);
        $id = $stmt->fetchColumn();

        jsonSuccess(['id' => $id], "Evento criado com sucesso!", 201);
    } catch (Exception $e) {
        jsonError("Erro ao criar evento: " . $e->getMessage(), 500);
    }
}

function getEventDetails(int $id, bool $checkAuth = true): void
{
    $organizerId = null;
    if ($checkAuth) {
        $user = requireAuth();
        $organizerId = $user['organizer_id'] ?? null;
    }

    try {
        $db = Database::getInstance();

        if ($organizerId) {
            $stmt = $db->prepare("SELECT id, name, slug, description, starts_at, status FROM events WHERE id = ? AND organizer_id = ?");
            $stmt->execute([$id, $organizerId]);
        } else {
            $stmt = $db->prepare("SELECT id, name, slug, description, starts_at, status FROM events WHERE id = ?");
            $stmt->execute([$id]);
        }

        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            jsonError("Evento não encontrado.", 404);
        }

        jsonSuccess($event);
    } catch (Exception $e) {
        jsonError("Failed to fetch event details: " . $e->getMessage(), 500);
    }
}