<?php
/**
 * Event Controller - Blindado (Multi-tenant)
 * Thin controller: parses request, calls auth, delegates to EventService, returns JSON.
 */

require_once BASE_PATH . '/src/Services/AIMemoryStoreService.php';
require_once BASE_PATH . '/src/Services/EventService.php';
require_once BASE_PATH . '/src/Services/AuditService.php';

use EnjoyFun\Services\EventService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && is_numeric($id) && $sub === 'ai-dna' => getEventAiDna((int)$id),
        $method === 'PUT' && is_numeric($id) && $sub === 'ai-dna' => updateEventAiDna((int)$id, $body),
        $method === 'GET' && $id === null => listEvents(),
        $method === 'POST' && $id === null => createEvent($body),
        ($method === 'PUT' || $method === 'PATCH') && is_numeric($id) && $sub === null => updateEvent((int)$id, $body),
        $method === 'GET' && is_numeric($id) && $sub === null => getEventDetails((int)$id),
        $method === 'DELETE' && is_numeric($id) && $sub === null => deleteEvent((int)$id),
        default => jsonError("Endpoint not found: {$method} /events/{$id}" . ($sub !== null ? "/{$sub}" : ''), 404),
    };
}

function eventAiDnaFields(): array
{
    return [
        'business_description',
        'tone_of_voice',
        'business_rules',
        'target_audience',
        'forbidden_topics',
    ];
}

function eventAiDnaEmptyPayload(): array
{
    $row = [];
    foreach (eventAiDnaFields() as $field) {
        $row[$field] = null;
    }
    return $row;
}

function eventAiDnaAssertOwnership(PDO $db, int $eventId, int $organizerId): void
{
    $stmt = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :org LIMIT 1');
    $stmt->execute([':id' => $eventId, ':org' => $organizerId]);
    if (!$stmt->fetchColumn()) {
        jsonError('Evento nao encontrado ou acesso negado.', 404);
    }
}

function getEventAiDna(int $eventId): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('organizer_id ausente no contexto de autenticacao.', 403);
    }

    try {
        $db = Database::getInstance();
        eventAiDnaAssertOwnership($db, $eventId, $organizerId);

        $stmt = $db->prepare('SELECT ai_dna_override FROM events WHERE id = :id AND organizer_id = :org LIMIT 1');
        $stmt->execute([':id' => $eventId, ':org' => $organizerId]);
        $raw = $stmt->fetchColumn();

        $payload = eventAiDnaEmptyPayload();
        if ($raw !== false && $raw !== null && $raw !== '') {
            $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if (is_array($decoded)) {
                foreach (eventAiDnaFields() as $field) {
                    $val = $decoded[$field] ?? null;
                    if (is_string($val)) {
                        $val = trim($val);
                        if ($val === '') {
                            $val = null;
                        }
                    }
                    $payload[$field] = $val;
                }
            }
        }

        jsonSuccess(['event_id' => $eventId] + $payload);
    } catch (Throwable $e) {
        jsonError('Falha ao carregar DNA do evento: ' . $e->getMessage(), 500);
    }
}

function updateEventAiDna(int $eventId, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($user['organizer_id'] ?? 0);
    if ($organizerId <= 0) {
        jsonError('organizer_id ausente no contexto de autenticacao.', 403);
    }

    $maxLen = 4000;
    $values = eventAiDnaEmptyPayload();
    $hasAny = false;

    foreach (eventAiDnaFields() as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $raw = $body[$field];
        if ($raw === null || $raw === '') {
            $values[$field] = null;
            continue;
        }
        if (!is_string($raw)) {
            jsonError("Campo `{$field}` deve ser texto.", 422);
        }
        $clean = trim($raw);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean);
        if (strlen($clean) > $maxLen) {
            jsonError("Campo `{$field}` excede {$maxLen} caracteres.", 422);
        }
        $values[$field] = $clean !== '' ? $clean : null;
        if ($values[$field] !== null) {
            $hasAny = true;
        }
    }

    try {
        $db = Database::getInstance();
        eventAiDnaAssertOwnership($db, $eventId, $organizerId);

        $previousStmt = $db->prepare('SELECT ai_dna_override FROM events WHERE id = :id AND organizer_id = :org LIMIT 1');
        $previousStmt->execute([':id' => $eventId, ':org' => $organizerId]);
        $previousRaw = $previousStmt->fetchColumn();
        $previousValue = null;
        if ($previousRaw !== false && $previousRaw !== null && $previousRaw !== '') {
            $previousValue = is_string($previousRaw) ? json_decode($previousRaw, true) : (is_array($previousRaw) ? $previousRaw : null);
        }

        $db->beginTransaction();

        $newJson = $hasAny ? json_encode($values, JSON_UNESCAPED_UNICODE) : null;

        $update = $db->prepare('UPDATE events SET ai_dna_override = :dna WHERE id = :id AND organizer_id = :org');
        $update->execute([
            ':dna' => $newJson,
            ':id' => $eventId,
            ':org' => $organizerId,
        ]);

        $db->commit();

        try {
            AuditService::log(
                'event.ai_dna_override.updated',
                'event',
                $eventId,
                $previousValue,
                $hasAny ? $values : null,
                $user
            );
        } catch (Throwable $auditError) {
            error_log('updateEventAiDna audit failure: ' . $auditError->getMessage());
        }

        jsonSuccess(['event_id' => $eventId] + $values, 'DNA do evento salvo com sucesso.');
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Falha ao salvar DNA do evento: ' . $e->getMessage(), 500);
    }
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
