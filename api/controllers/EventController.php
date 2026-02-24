<?php
/**
 * EnjoyFun 2.0 — Event Controller
 * Routes:
 *   GET    /api/events              — list events
 *   POST   /api/events              — create event (organizer+)
 *   GET    /api/events/{id}         — get event detail
 *   PUT    /api/events/{id}         — update event
 *   DELETE /api/events/{id}         — delete event
 *   GET    /api/events/{id}/lineup  — lineup
 *   POST   /api/events/{id}/lineup  — add lineup entry
 *   GET    /api/events/{id}/stages  — list stages
 *   POST   /api/events/{id}/stages  — add stage
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();

    if (!$id) {
        // /api/events
        match ($method) {
            'GET'  => listEvents($db, $query),
            'POST' => createEvent($db, $body),
            default => Response::error('Method not allowed.', 405),
        };
        return;
    }

    if (!$sub) {
        // /api/events/{id}
        match ($method) {
            'GET'    => getEvent($db, (int)$id),
            'PUT'    => updateEvent($db, (int)$id, $body),
            'PATCH'  => updateEvent($db, (int)$id, $body),
            'DELETE' => deleteEvent($db, (int)$id),
            default  => Response::error('Method not allowed.', 405),
        };
        return;
    }

    // /api/events/{id}/{sub}
    match ($sub) {
        'lineup' => match ($method) {
            'GET'  => getLineup($db, (int)$id),
            'POST' => addLineup($db, (int)$id, $body),
            default => Response::error('Method not allowed.', 405),
        },
        'stages' => match ($method) {
            'GET'  => getStages($db, (int)$id),
            'POST' => addStage($db, (int)$id, $body),
            default => Response::error('Method not allowed.', 405),
        },
        default => Response::error("Sub-resource '$sub' not found.", 404),
    };
}

// ── List Events ───────────────────────────────────────────────────────────────
function listEvents(PDO $db, array $q): void
{
    $user = optionalAuth();
    $page    = max(1, (int)($q['page']    ?? 1));
    $perPage = min(50, max(1, (int)($q['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;
    $status  = $q['status'] ?? null;

    $where  = '';
    $params = [];

    // Non-admins see only published/ongoing events
    $isAdmin = $user && in_array('admin', $user['roles'] ?? []);
    if (!$isAdmin) {
        $where .= " AND e.status IN ('published','ongoing')";
    } elseif ($status) {
        $where .= ' AND e.status = ?';
        $params[] = $status;
    }

    if (!empty($q['search'])) {
        $where .= ' AND (e.name LIKE ? OR e.venue_name LIKE ?)';
        $s = '%' . $q['search'] . '%';
        $params[] = $s; $params[] = $s;
    }

    $countSql = "SELECT COUNT(*) FROM events e WHERE 1=1 $where";
    $total    = (int) $db->prepare($countSql)->execute($params) ? $db->prepare($countSql)->execute($params) : 0;
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $sql  = "SELECT e.id, e.name, e.slug, e.banner_url, e.venue_name, e.address,
                    e.starts_at, e.ends_at, e.status, e.capacity,
                    u.name AS organizer_name
             FROM events e
             JOIN users u ON u.id = e.organizer_id
             WHERE 1=1 $where
             ORDER BY e.starts_at DESC
             LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    Response::paginated($events, $total, $page, $perPage);
}

// ── Get Event ─────────────────────────────────────────────────────────────────
function getEvent(PDO $db, int $id): void
{
    $stmt = $db->prepare('SELECT e.*, u.name AS organizer_name FROM events e JOIN users u ON u.id = e.organizer_id WHERE e.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $event = $stmt->fetch();
    if (!$event) Response::error('Event not found.', 404);

    // Append stages
    $stmt = $db->prepare('SELECT * FROM event_stages WHERE event_id = ? ORDER BY id');
    $stmt->execute([$id]);
    $event['stages'] = $stmt->fetchAll();

    // Append lineup (next 10 upcoming)
    $stmt = $db->prepare('SELECT l.*, s.name AS stage_name FROM event_lineup l LEFT JOIN event_stages s ON s.id = l.stage_id WHERE l.event_id = ? ORDER BY l.starts_at LIMIT 10');
    $stmt->execute([$id]);
    $event['lineup'] = $stmt->fetchAll();

    Response::success($event);
}

// ── Create Event ──────────────────────────────────────────────────────────────
function createEvent(PDO $db, array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    validateEventBody($body);

    $slug = createSlug($body['name']) . '-' . substr(uniqid(), -5);

    $db->prepare('
        INSERT INTO events
            (organizer_id,name,slug,description,banner_url,venue_name,address,
             latitude,longitude,starts_at,ends_at,status,capacity,credit_ratio)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $user['sub'],
        $body['name'],
        $slug,
        $body['description'] ?? null,
        $body['banner_url']  ?? null,
        $body['venue_name']  ?? null,
        $body['address']     ?? null,
        $body['latitude']    ?? null,
        $body['longitude']   ?? null,
        $body['starts_at'],
        $body['ends_at'],
        $body['status']       ?? 'draft',
        $body['capacity']     ?? null,
        $body['credit_ratio'] ?? 1.00,
    ]);

    getEvent($db, (int)$db->lastInsertId());
}

// ── Update Event ──────────────────────────────────────────────────────────────
function updateEvent(PDO $db, int $id, array $body): void
{
    requireAuth(['admin', 'organizer']);
    $stmt = $db->prepare('SELECT id FROM events WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) Response::error('Event not found.', 404);

    validateEventBody($body, false);

    $fields = [];
    $params = [];
    $allowed = ['name','description','banner_url','venue_name','address','latitude','longitude',
                'starts_at','ends_at','status','capacity','credit_ratio','offline_enabled','sync_interval'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "$f = ?";
            $params[] = $body[$f];
        }
    }
    if (empty($fields)) Response::error('No fields to update.', 422);
    $params[] = $id;
    $db->prepare('UPDATE events SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    getEvent($db, $id);
}

// ── Delete Event ──────────────────────────────────────────────────────────────
function deleteEvent(PDO $db, int $id): void
{
    requireAuth(['admin']);
    $db->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
    Response::success(null, 'Event deleted.');
}

// ── Lineup ────────────────────────────────────────────────────────────────────
function getLineup(PDO $db, int $eventId): void
{
    $stmt = $db->prepare('SELECT l.*, s.name AS stage_name FROM event_lineup l LEFT JOIN event_stages s ON s.id = l.stage_id WHERE l.event_id = ? ORDER BY l.starts_at, l.sort_order');
    $stmt->execute([$eventId]);
    Response::success($stmt->fetchAll());
}

function addLineup(PDO $db, int $eventId, array $body): void
{
    requireAuth(['admin', 'organizer', 'staff']);
    if (empty($body['artist_name']))   Response::error('artist_name required.', 422);
    if (empty($body['starts_at']))     Response::error('starts_at required.', 422);
    if (empty($body['ends_at']))       Response::error('ends_at required.', 422);

    $db->prepare('INSERT INTO event_lineup (event_id,stage_id,artist_name,genre,starts_at,ends_at,image_url,description,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$eventId, $body['stage_id'] ?? null, $body['artist_name'], $body['genre'] ?? null,
                  $body['starts_at'], $body['ends_at'], $body['image_url'] ?? null,
                  $body['description'] ?? null, $body['sort_order'] ?? 0]);

    Response::success(['id' => (int)$db->lastInsertId()], 'Lineup entry added.', 201);
}

// ── Stages ────────────────────────────────────────────────────────────────────
function getStages(PDO $db, int $eventId): void
{
    $stmt = $db->prepare('SELECT * FROM event_stages WHERE event_id = ?');
    $stmt->execute([$eventId]);
    Response::success($stmt->fetchAll());
}

function addStage(PDO $db, int $eventId, array $body): void
{
    requireAuth(['admin', 'organizer']);
    if (empty($body['name'])) Response::error('Stage name required.', 422);
    $db->prepare('INSERT INTO event_stages (event_id,name,capacity,map_x,map_y) VALUES (?,?,?,?,?)')
       ->execute([$eventId, $body['name'], $body['capacity'] ?? null, $body['map_x'] ?? null, $body['map_y'] ?? null]);
    Response::success(['id' => (int)$db->lastInsertId()], 'Stage added.', 201);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function validateEventBody(array $body, bool $requireAll = true): void
{
    $errors = [];
    if ($requireAll || isset($body['name']))      if (empty($body['name']))      $errors['name']      = 'required';
    if ($requireAll || isset($body['starts_at'])) if (empty($body['starts_at'])) $errors['starts_at'] = 'required';
    if ($requireAll || isset($body['ends_at']))   if (empty($body['ends_at']))   $errors['ends_at']   = 'required';
    if ($errors) Response::error('Validation failed.', 422, $errors);
}

function createSlug(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[áàãâä]/u', 'a', $text);
    $text = preg_replace('/[éèêë]/u',  'e', $text);
    $text = preg_replace('/[íìîï]/u',  'i', $text);
    $text = preg_replace('/[óòõôö]/u', 'o', $text);
    $text = preg_replace('/[úùûü]/u',  'u', $text);
    $text = preg_replace('/[ç]/u',     'c', $text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', trim($text));
    return $text;
}
