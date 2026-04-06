<?php
/**
 * Event Controller - Blindado (Multi-tenant)
 * Thin controller: parses request, calls auth, delegates to EventService, returns JSON.
 */

require_once BASE_PATH . '/src/Services/AIMemoryStoreService.php';
require_once BASE_PATH . '/src/Services/EventService.php';

use EnjoyFun\Services\EventService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => listEvents(),
        $method === 'POST' && $id === null => createEvent($body),
        ($method === 'PUT' || $method === 'PATCH') && is_numeric($id) => updateEvent((int)$id, $body),
        $method === 'GET' && is_numeric($id) => getEventDetails((int)$id),
        $method === 'DELETE' && is_numeric($id) => deleteEvent((int)$id),
        default => jsonError("Endpoint not found: {$method} /events/{$id}", 404),
    };
}

function listEvents(): void
{
    $user = requireAuth();
    $organizerId = (int)($user['organizer_id'] ?? 0);

    try {
        $db = Database::getInstance();
        $items = EventService::listEvents($db, $organizerId);
        jsonSuccess($items);
    } catch (Throwable $e) {
        jsonError("Failed to fetch events: " . $e->getMessage(), 500);
    }
}

function createEvent(array $body): void
{
    $user = requireAuth();
    $organizerId = (int)($user['organizer_id'] ?? 0);

    try {
        $payload = EventService::normalizeEventPayload($body);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }

    $commercialConfig = EventService::normalizeCommercialConfigPayload($body['commercial_config'] ?? null);

    try {
        $db = Database::getInstance();
        $id = EventService::createEvent($db, $organizerId, $payload, $commercialConfig, $user);
        jsonSuccess(['id' => $id], "Evento criado com sucesso!", 201);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) {
            $code = 500;
        }
        jsonError("Erro ao criar evento: " . $e->getMessage(), $code);
    }
}

function updateEvent(int $id, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);

    try {
        $payload = EventService::normalizeEventPayload($body);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    }

    $commercialConfig = EventService::normalizeCommercialConfigPayload($body['commercial_config'] ?? null);

    try {
        $db = Database::getInstance();
        EventService::updateEvent($db, $id, $organizerId, $payload, $commercialConfig, $user);
        jsonSuccess(['id' => $id], 'Evento atualizado com sucesso.');
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 422);
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) {
            $code = 500;
        }
        jsonError('Erro ao atualizar evento: ' . $e->getMessage(), $code);
    }
}

function getEventDetails(int $id): void
{
    $user = requireAuth();
    $organizerId = (int)($user['organizer_id'] ?? 0);

    try {
        $db = Database::getInstance();
        $event = EventService::getEventDetails($db, $id, $organizerId);

        if (!$event) {
            jsonError("Evento não encontrado ou acesso negado.", 404);
        }

        jsonSuccess($event);
    } catch (Throwable $e) {
        jsonError("Failed to fetch event details: " . $e->getMessage(), 500);
    }
}

function deleteEvent(int $id): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);

    try {
        $db = Database::getInstance();
        EventService::deleteEvent($db, $id, $organizerId);
        jsonSuccess(['id' => $id], 'Evento excluído com sucesso.');
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) {
            $code = 500;
        }
        jsonError('Erro ao excluir evento: ' . $e->getMessage(), $code);
    }
}
