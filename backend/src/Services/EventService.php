<?php
namespace EnjoyFun\Services;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * EventService — Domain logic for Event CRUD, calendar sync and commercial config.
 * Extracted from EventController to keep controllers thin.
 */
class EventService
{
    // ── Schema introspection (cached) ─────────────────────────────────────────

    public static function tableExists(PDO $db, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
        return $cache[$table];
    }

    public static function columnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }

    // ── Schema-aware SELECT builder ───────────────────────────────────────────

    private static function eventSelectColumns(PDO $db, bool $includeOrganizerId = false): string
    {
        $cols = [
            'id', 'name', 'slug', 'description',
            self::columnExists($db, 'events', 'venue_name') ? 'venue_name' : "NULL::varchar AS venue_name",
            self::columnExists($db, 'events', 'address') ? 'address' : "NULL::varchar AS address",
            'starts_at',
            self::columnExists($db, 'events', 'ends_at') ? 'ends_at' : "NULL::timestamp AS ends_at",
            'status',
            self::columnExists($db, 'events', 'capacity') ? 'capacity' : "0::integer AS capacity",
            self::columnExists($db, 'events', 'event_timezone') ? 'event_timezone' : "NULL::varchar AS event_timezone",
        ];

        // ── Multi-event fields (migration 089) ──
        $multiEventCols = [
            'event_type'      => 'varchar',
            'modules_enabled' => 'jsonb',
            'latitude'        => 'numeric',
            'longitude'       => 'numeric',
            'city'            => 'varchar',
            'state'           => 'varchar',
            'country'         => 'varchar',
            'zip_code'        => 'varchar',
            'venue_type'      => 'varchar',
            'age_rating'      => 'varchar',
            'map_3d_url'      => 'varchar',
            'map_image_url'   => 'varchar',
            'map_seating_url' => 'varchar',
            'map_parking_url' => 'varchar',
            'banner_url'      => 'varchar',
            'tour_video_url'     => 'varchar',
            'tour_video_360_url' => 'varchar',
        ];
        foreach ($multiEventCols as $col => $pgType) {
            $cols[] = self::columnExists($db, 'events', $col)
                ? $col
                : "NULL::{$pgType} AS {$col}";
        }

        if ($includeOrganizerId) {
            $cols[] = 'organizer_id';
        }

        return implode(', ', $cols);
    }

    // ── List Events ───────────────────────────────────────────────────────────

    public static function listEvents(PDO $db, int $organizerId): array
    {
        $select = self::eventSelectColumns($db);

        $stmt = $db->prepare("
            SELECT {$select}
            FROM events
            WHERE organizer_id = ?
            ORDER BY starts_at ASC
        ");
        $stmt->execute([$organizerId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $event) use ($db, $organizerId) {
            $event['can_delete'] = self::canDeleteEventSafely($db, (int)$event['id'], $organizerId);
            return $event;
        }, $events);
    }

    // ── Get Event Details ─────────────────────────────────────────────────────

    public static function getEventDetails(PDO $db, int $eventId, int $organizerId): ?array
    {
        $select = self::eventSelectColumns($db, true);

        $stmt = $db->prepare("
            SELECT {$select}
            FROM events
            WHERE id = ? AND organizer_id = ?
        ");
        $stmt->execute([$eventId, $organizerId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return null;
        }

        $event['can_delete'] = self::canDeleteEventSafely($db, $eventId, $organizerId);
        unset($event['organizer_id']);
        return $event;
    }

    // ── Create Event ──────────────────────────────────────────────────────────

    /**
     * @return int The newly created event ID.
     */
    public static function createEvent(PDO $db, int $organizerId, array $payload, array $commercialConfig, ?array $user = null): int
    {
        self::validateEventPayload($payload);

        $hasVenueName = self::columnExists($db, 'events', 'venue_name');
        $hasAddress = self::columnExists($db, 'events', 'address');
        $hasEndsAt = self::columnExists($db, 'events', 'ends_at');
        $hasCapacity = self::columnExists($db, 'events', 'capacity');
        $hasEventTimezone = self::columnExists($db, 'events', 'event_timezone');

        if ($hasEventTimezone && $payload['event_timezone'] === null) {
            throw new InvalidArgumentException('event_timezone e obrigatoria. Use um identificador IANA, por exemplo America/Sao_Paulo.');
        }

        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $payload['name']), '-')) . '-' . time();

        $columns = ['name', 'slug', 'description', 'starts_at', 'status', 'organizer_id'];
        $placeholders = ['?', '?', '?', '?', '?', '?'];
        $values = [
            $payload['name'],
            $slug,
            $payload['description'] !== '' ? $payload['description'] : null,
            $payload['starts_at'],
            $payload['status'],
            $organizerId,
        ];

        if ($hasVenueName) {
            $columns[] = 'venue_name';
            $placeholders[] = '?';
            $values[] = $payload['venue_name'] !== '' ? $payload['venue_name'] : null;
        }
        if ($hasAddress) {
            $columns[] = 'address';
            $placeholders[] = '?';
            $values[] = $payload['address'] !== '' ? $payload['address'] : null;
        }
        if ($hasEndsAt) {
            $columns[] = 'ends_at';
            $placeholders[] = '?';
            $values[] = $payload['ends_at'] ?: null;
        }
        if ($hasCapacity) {
            $columns[] = 'capacity';
            $placeholders[] = '?';
            $values[] = $payload['capacity'];
        }
        if ($hasEventTimezone) {
            $columns[] = 'event_timezone';
            $placeholders[] = '?';
            $values[] = $payload['event_timezone'];
        }

        // ── Multi-event fields (migration 089) — graceful column checks ──
        $multiEventStringFields = [
            'event_type', 'city', 'state', 'country', 'zip_code',
            'venue_type', 'age_rating',
            'map_3d_url', 'map_image_url', 'map_seating_url', 'map_parking_url', 'banner_url',
            'tour_video_url', 'tour_video_360_url',
        ];
        foreach ($multiEventStringFields as $field) {
            if (self::eventsHasColumn($db, $field)) {
                $columns[] = $field;
                $placeholders[] = '?';
                $val = $payload[$field] ?? '';
                $values[] = $val !== '' ? $val : null;
            }
        }
        if (self::eventsHasColumn($db, 'modules_enabled')) {
            $columns[] = 'modules_enabled';
            $placeholders[] = '?::jsonb';
            $values[] = json_encode($payload['modules_enabled'] ?? []);
        }
        if (self::eventsHasColumn($db, 'latitude')) {
            $columns[] = 'latitude';
            $placeholders[] = '?';
            $values[] = $payload['latitude'] ?? null;
        }
        if (self::eventsHasColumn($db, 'longitude')) {
            $columns[] = 'longitude';
            $placeholders[] = '?';
            $values[] = $payload['longitude'] ?? null;
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO events (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")
                RETURNING id
            ");
            $stmt->execute($values);
            $id = (int)$stmt->fetchColumn();

            self::syncOperationalCalendar($db, $id, $organizerId, $payload);
            self::persistCommercialConfig($db, $id, $organizerId, $commercialConfig);

            if ($payload['status'] === 'finished') {
                \EnjoyFun\Services\AIMemoryStoreService::queueEndOfEventReport($db, $organizerId, $id, [
                    'automation_source' => 'event_finished',
                    'generated_by_user_id' => isset($user['id']) ? (int)$user['id'] : null,
                    'requested_by' => $user['email'] ?? $user['name'] ?? null,
                    'summary_markdown' => 'Evento criado ja finalizado. Relatorio automatico de fim de evento enfileirado.',
                    'event_snapshot' => [
                        'name' => $payload['name'],
                        'status' => $payload['status'],
                        'starts_at' => $payload['starts_at'],
                        'ends_at' => $payload['ends_at'],
                    ],
                    'audit_user' => $user,
                ]);
            }

            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // ── Update Event ──────────────────────────────────────────────────────────

    public static function updateEvent(PDO $db, int $eventId, int $organizerId, array $payload, array $commercialConfig, ?array $user = null): void
    {
        self::validateEventPayload($payload);

        $stmtEvent = $db->prepare('SELECT id, status FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $stmtEvent->execute([$eventId, $organizerId]);
        $existingEvent = $stmtEvent->fetch(PDO::FETCH_ASSOC);
        if (!$existingEvent) {
            throw new RuntimeException('Evento nao encontrado para este organizador.', 404);
        }

        $hasVenueName = self::columnExists($db, 'events', 'venue_name');
        $hasAddress = self::columnExists($db, 'events', 'address');
        $hasEndsAt = self::columnExists($db, 'events', 'ends_at');
        $hasCapacity = self::columnExists($db, 'events', 'capacity');
        $hasEventTimezone = self::columnExists($db, 'events', 'event_timezone');

        if ($hasEventTimezone && $payload['event_timezone'] === null) {
            throw new InvalidArgumentException('event_timezone e obrigatoria. Use um identificador IANA, por exemplo America/Sao_Paulo.');
        }

        $setParts = [
            'name = ?',
            'description = ?',
            'starts_at = ?',
            'status = ?',
            'updated_at = NOW()',
        ];
        $values = [
            $payload['name'],
            $payload['description'] !== '' ? $payload['description'] : null,
            $payload['starts_at'],
            $payload['status'],
        ];

        if ($hasVenueName) {
            $setParts[] = 'venue_name = ?';
            $values[] = $payload['venue_name'] !== '' ? $payload['venue_name'] : null;
        }
        if ($hasAddress) {
            $setParts[] = 'address = ?';
            $values[] = $payload['address'] !== '' ? $payload['address'] : null;
        }
        if ($hasEndsAt) {
            $setParts[] = 'ends_at = ?';
            $values[] = $payload['ends_at'] ?: null;
        }
        if ($hasCapacity) {
            $setParts[] = 'capacity = ?';
            $values[] = $payload['capacity'];
        }
        if ($hasEventTimezone) {
            $setParts[] = 'event_timezone = ?';
            $values[] = $payload['event_timezone'];
        }

        // ── Multi-event fields (migration 089) — graceful column checks ──
        $multiEventStringFields = [
            'event_type', 'city', 'state', 'country', 'zip_code',
            'venue_type', 'age_rating',
            'map_3d_url', 'map_image_url', 'map_seating_url', 'map_parking_url', 'banner_url',
            'tour_video_url', 'tour_video_360_url',
        ];
        foreach ($multiEventStringFields as $field) {
            if (self::eventsHasColumn($db, $field)) {
                $setParts[] = "{$field} = ?";
                $val = $payload[$field] ?? '';
                $values[] = $val !== '' ? $val : null;
            }
        }
        if (self::eventsHasColumn($db, 'modules_enabled')) {
            $setParts[] = 'modules_enabled = ?::jsonb';
            $values[] = json_encode($payload['modules_enabled'] ?? []);
        }
        if (self::eventsHasColumn($db, 'latitude')) {
            $setParts[] = 'latitude = ?';
            $values[] = $payload['latitude'] ?? null;
        }
        if (self::eventsHasColumn($db, 'longitude')) {
            $setParts[] = 'longitude = ?';
            $values[] = $payload['longitude'] ?? null;
        }

        $values[] = $eventId;
        $values[] = $organizerId;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE events
                SET " . implode(', ', $setParts) . "
                WHERE id = ? AND organizer_id = ?
            ");
            $stmt->execute($values);

            self::syncOperationalCalendar($db, $eventId, $organizerId, $payload);
            self::persistCommercialConfig($db, $eventId, $organizerId, $commercialConfig);

            if (($existingEvent['status'] ?? '') !== 'finished' && $payload['status'] === 'finished') {
                \EnjoyFun\Services\AIMemoryStoreService::queueEndOfEventReport($db, $organizerId, $eventId, [
                    'automation_source' => 'event_finished',
                    'generated_by_user_id' => isset($user['id']) ? (int)$user['id'] : null,
                    'requested_by' => $user['email'] ?? $user['name'] ?? null,
                    'summary_markdown' => 'Evento mudou para finished. Relatorio automatico de fim de evento enfileirado.',
                    'event_snapshot' => [
                        'name' => $payload['name'],
                        'status' => $payload['status'],
                        'starts_at' => $payload['starts_at'],
                        'ends_at' => $payload['ends_at'],
                    ],
                    'audit_user' => $user,
                ]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // ── Delete Event ──────────────────────────────────────────────────────────

    public static function deleteEvent(PDO $db, int $eventId, int $organizerId): void
    {
        $stmt = $db->prepare("SELECT id, name FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmt->execute([$eventId, $organizerId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            throw new RuntimeException('Evento nao encontrado para este organizador.', 404);
        }

        if (!self::canDeleteEventSafely($db, $eventId, $organizerId)) {
            throw new RuntimeException('Este evento ja possui dados operacionais/comerciais vinculados e nao pode ser excluido.', 409);
        }

        $db->beginTransaction();
        try {
            self::deleteCommercialConfig($db, $eventId, $organizerId);

            $delete = $db->prepare("DELETE FROM events WHERE id = ? AND organizer_id = ?");
            $delete->execute([$eventId, $organizerId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // ── Safety checks ─────────────────────────────────────────────────────────

    public static function canDeleteEventSafely(PDO $db, int $eventId, int $organizerId): bool
    {
        $checks = [
            ['table' => 'tickets', 'column' => 'event_id'],
            ['table' => 'event_participants', 'column' => 'event_id'],
            ['table' => 'guests', 'column' => 'event_id'],
            ['table' => 'parking_records', 'column' => 'event_id'],
            ['table' => 'event_days', 'column' => 'event_id'],
            ['table' => 'ticket_types', 'column' => 'event_id'],
            ['table' => 'ticket_batches', 'column' => 'event_id'],
            ['table' => 'commissaries', 'column' => 'event_id'],
        ];

        foreach ($checks as $check) {
            if (!self::tableExists($db, $check['table']) || !self::columnExists($db, $check['table'], $check['column'])) {
                continue;
            }

            $sql = "SELECT COUNT(*) FROM {$check['table']} WHERE {$check['column']} = :event_id";
            if (self::columnExists($db, $check['table'], 'organizer_id')) {
                $sql .= " AND organizer_id = :organizer_id";
            }

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            if (self::columnExists($db, $check['table'], 'organizer_id')) {
                $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
            }
            $stmt->execute();

            if ((int)$stmt->fetchColumn() > 0) {
                return false;
            }
        }

        return true;
    }

    // ── Payload normalisation ─────────────────────────────────────────────────

    public static function normalizeEventPayload(array $body): array
    {
        $status = strtolower(trim((string)($body['status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'published', 'ongoing', 'finished', 'cancelled'], true)) {
            $status = 'draft';
        }

        $eventTimezone = self::normalizeTimezoneValue($body['event_timezone'] ?? null);

        // ── venue_type validation ──
        $venueType = strtolower(trim((string)($body['venue_type'] ?? '')));
        if ($venueType !== '' && !in_array($venueType, ['indoor', 'outdoor', 'hybrid'], true)) {
            $venueType = 'outdoor';
        }

        // ── modules_enabled — accept array or JSON string, store as array ──
        $modulesEnabled = $body['modules_enabled'] ?? [];
        if (is_string($modulesEnabled)) {
            $decoded = json_decode($modulesEnabled, true);
            $modulesEnabled = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($modulesEnabled)) {
            $modulesEnabled = [];
        }

        return [
            'name' => trim((string)($body['name'] ?? '')),
            'description' => trim((string)($body['description'] ?? '')),
            'venue_name' => trim((string)($body['venue_name'] ?? '')),
            'address' => trim((string)($body['address'] ?? '')),
            'starts_at' => self::normalizeDateTimeValue($body['starts_at'] ?? '', $eventTimezone, 'starts_at') ?? '',
            'ends_at' => self::normalizeDateTimeValue($body['ends_at'] ?? null, $eventTimezone, 'ends_at'),
            'status' => $status,
            'capacity' => array_key_exists('capacity', $body) && $body['capacity'] !== '' ? (int)$body['capacity'] : 0,
            'event_timezone' => $eventTimezone,
            // ── Multi-event fields (migration 089) ──
            'event_type' => trim((string)($body['event_type'] ?? '')),
            'modules_enabled' => $modulesEnabled,
            'latitude' => array_key_exists('latitude', $body) && $body['latitude'] !== null && $body['latitude'] !== '' ? (float)$body['latitude'] : null,
            'longitude' => array_key_exists('longitude', $body) && $body['longitude'] !== null && $body['longitude'] !== '' ? (float)$body['longitude'] : null,
            'city' => trim((string)($body['city'] ?? '')),
            'state' => trim((string)($body['state'] ?? '')),
            'country' => trim((string)($body['country'] ?? '')),
            'zip_code' => trim((string)($body['zip_code'] ?? '')),
            'venue_type' => $venueType !== '' ? $venueType : null,
            'age_rating' => trim((string)($body['age_rating'] ?? '')),
            'map_3d_url' => trim((string)($body['map_3d_url'] ?? '')),
            'map_image_url' => trim((string)($body['map_image_url'] ?? '')),
            'map_seating_url' => trim((string)($body['map_seating_url'] ?? '')),
            'map_parking_url' => trim((string)($body['map_parking_url'] ?? '')),
            'banner_url' => trim((string)($body['banner_url'] ?? '')),
            'tour_video_url' => trim((string)($body['tour_video_url'] ?? '')),
            'tour_video_360_url' => trim((string)($body['tour_video_360_url'] ?? '')),
        ];
    }

    public static function normalizeCommercialConfigPayload(mixed $input): array
    {
        $config = is_array($input) ? $input : [];
        $ticketTypes = isset($config['ticket_types']) && is_array($config['ticket_types']) ? $config['ticket_types'] : [];
        $batches = isset($config['batches']) && is_array($config['batches']) ? $config['batches'] : [];
        $commissaries = isset($config['commissaries']) && is_array($config['commissaries']) ? $config['commissaries'] : [];

        return [
            'ticket_types' => array_map([self::class, 'normalizeTicketTypePayload'], $ticketTypes),
            'batches' => array_map([self::class, 'normalizeBatchPayload'], $batches),
            'commissaries' => array_map([self::class, 'normalizeCommissaryPayload'], $commissaries),
        ];
    }

    // ── Private payload helpers ───────────────────────────────────────────────

    private static function validateEventPayload(array $payload): void
    {
        if ($payload['name'] === '') {
            throw new InvalidArgumentException('O nome do evento e obrigatorio.');
        }
        if (!$payload['starts_at']) {
            throw new InvalidArgumentException('A data de inicio (starts_at) e obrigatoria.');
        }
        if ($payload['capacity'] < 0) {
            throw new InvalidArgumentException('Capacidade invalida.');
        }
    }

    private static function normalizeTimezoneValue(mixed $value): ?string
    {
        $timezoneName = trim((string)($value ?? ''));
        if ($timezoneName === '') {
            return null;
        }

        try {
            new DateTimeZone($timezoneName);
        } catch (Throwable) {
            throw new InvalidArgumentException('event_timezone invalida. Use um identificador IANA, por exemplo America/Sao_Paulo.');
        }

        return $timezoneName;
    }

    private static function normalizeDateTimeValue(mixed $value, ?string $eventTimezone, string $field): ?string
    {
        $trimmed = trim((string)($value ?? ''));
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?(?:\.\d+)?$/', $trimmed, $matches)) {
            return sprintf('%s %s:%s', $matches[1], $matches[2], $matches[3] ?? '00');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/i', $trimmed)) {
            if ($eventTimezone === null) {
                throw new InvalidArgumentException(sprintf(
                    '%s com timezone/offset exige event_timezone explicita para preservar o calendario operacional.',
                    $field
                ));
            }

            try {
                return (new DateTimeImmutable($trimmed))
                    ->setTimezone(new DateTimeZone($eventTimezone))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                throw new InvalidArgumentException(sprintf(
                    '%s invalido. Use ISO 8601 ou YYYY-MM-DD HH:MM[:SS].',
                    $field
                ));
            }
        }

        throw new InvalidArgumentException(sprintf(
            '%s invalido. Use ISO 8601 ou YYYY-MM-DD HH:MM[:SS].',
            $field
        ));
    }

    private static function normalizeTicketTypePayload(array $item): array
    {
        $clientKey = trim((string)($item['client_key'] ?? ''));
        if ($clientKey === '' && isset($item['id']) && !is_numeric($item['id'])) {
            $clientKey = trim((string)$item['id']);
        }

        $sector = isset($item['sector']) && trim((string)$item['sector']) !== '' ? trim((string)$item['sector']) : null;

        return [
            'id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : null,
            'client_key' => $clientKey !== '' ? $clientKey : null,
            'name' => trim((string)($item['name'] ?? '')),
            'price' => array_key_exists('price', $item) && $item['price'] !== '' && $item['price'] !== null ? (float)$item['price'] : 0.0,
            'sector' => $sector,
        ];
    }

    private static function eventsHasColumn(PDO $db, string $column): bool
    {
        return self::columnExists($db, 'events', $column);
    }

    private static function ticketTypesHasSector(PDO $db): bool
    {
        static $result = null;
        if ($result !== null) return $result;
        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'ticket_types' AND column_name = 'sector'
            )
        ");
        $stmt->execute();
        $result = (bool)$stmt->fetchColumn();
        return $result;
    }

    private static function normalizeBatchPayload(array $item): array
    {
        $ticketTypeId = isset($item['ticket_type_id']) && $item['ticket_type_id'] !== '' && $item['ticket_type_id'] !== null
            ? (int)$item['ticket_type_id']
            : null;
        if ($ticketTypeId !== null && $ticketTypeId <= 0) {
            $ticketTypeId = null;
        }

        $quantityTotal = array_key_exists('quantity_total', $item) && $item['quantity_total'] !== '' && $item['quantity_total'] !== null
            ? (int)$item['quantity_total']
            : null;

        return [
            'id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : null,
            'name' => trim((string)($item['name'] ?? '')),
            'code' => trim((string)($item['code'] ?? '')),
            'price' => array_key_exists('price', $item) && $item['price'] !== '' && $item['price'] !== null ? (float)$item['price'] : 0.0,
            'quantity_total' => $quantityTotal,
            'starts_at' => !empty($item['starts_at']) ? (string)$item['starts_at'] : null,
            'ends_at' => !empty($item['ends_at']) ? (string)$item['ends_at'] : null,
            'ticket_type_id' => $ticketTypeId,
            'ticket_type_client_key' => !empty($item['ticket_type_client_key']) ? trim((string)$item['ticket_type_client_key']) : null,
            'is_active' => !array_key_exists('is_active', $item) || filter_var($item['is_active'], FILTER_VALIDATE_BOOL),
        ];
    }

    private static function normalizeCommissaryPayload(array $item): array
    {
        $commissionMode = strtolower(trim((string)($item['commission_mode'] ?? 'percent')));
        if (!in_array($commissionMode, ['percent', 'fixed'], true)) {
            $commissionMode = 'percent';
        }

        $status = strtolower(trim((string)($item['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        return [
            'id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : null,
            'name' => trim((string)($item['name'] ?? '')),
            'email' => trim((string)($item['email'] ?? '')),
            'phone' => trim((string)($item['phone'] ?? '')),
            'commission_mode' => $commissionMode,
            'commission_value' => array_key_exists('commission_value', $item) && $item['commission_value'] !== '' && $item['commission_value'] !== null
                ? (float)$item['commission_value']
                : 0.0,
            'status' => $status,
        ];
    }

    // ── Operational calendar sync ─────────────────────────────────────────────

    private static function syncOperationalCalendar(PDO $db, int $eventId, int $organizerId, array $payload): void
    {
        if (!self::tableExists($db, 'event_days')) {
            return;
        }

        $calendar = self::buildDerivedCalendar($payload);
        if ($calendar === []) {
            return;
        }

        if (self::calendarHasLiveDependencies($db, $eventId)) {
            return;
        }

        if (self::tableExists($db, 'event_shifts')) {
            $stmtDeleteShifts = $db->prepare("
                DELETE FROM event_shifts
                WHERE event_day_id IN (
                    SELECT id
                    FROM event_days
                    WHERE event_id = ?
                )
            ");
            $stmtDeleteShifts->execute([$eventId]);
        }

        $stmtDeleteDays = $db->prepare("DELETE FROM event_days WHERE event_id = ?");
        $stmtDeleteDays->execute([$eventId]);

        $stmtCreateDay = $db->prepare("
            INSERT INTO event_days (event_id, organizer_id, date, starts_at, ends_at, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmtCreateShift = self::tableExists($db, 'event_shifts')
            ? $db->prepare("
                INSERT INTO event_shifts (event_day_id, organizer_id, name, starts_at, ends_at, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")
            : null;

        foreach ($calendar as $item) {
            $stmtCreateDay->execute([
                $eventId,
                $organizerId,
                $item['date'],
                $item['starts_at'],
                $item['ends_at'],
            ]);
            $eventDayId = (int)$stmtCreateDay->fetchColumn();
            if ($eventDayId <= 0 || !$stmtCreateShift) {
                continue;
            }

            $stmtCreateShift->execute([
                $eventDayId,
                $organizerId,
                'Turno Unico',
                $item['starts_at'],
                $item['ends_at'],
            ]);
        }
    }

    private static function buildDerivedCalendar(array $payload): array
    {
        $startsAt = trim((string)($payload['starts_at'] ?? ''));
        if ($startsAt === '') {
            return [];
        }

        try {
            $eventStart = new DateTimeImmutable($startsAt);
        } catch (Throwable) {
            return [];
        }

        $endsAt = trim((string)($payload['ends_at'] ?? ''));
        try {
            $eventEnd = $endsAt !== '' ? new DateTimeImmutable($endsAt) : $eventStart;
        } catch (Throwable) {
            $eventEnd = $eventStart;
        }
        if ($eventEnd < $eventStart) {
            $eventEnd = $eventStart;
        }

        $currentDay = $eventStart->setTime(0, 0, 0);
        $lastDay = $eventEnd->setTime(0, 0, 0);
        $startDateKey = $eventStart->format('Y-m-d');
        $endDateKey = $eventEnd->format('Y-m-d');
        $days = [];

        while ($currentDay <= $lastDay) {
            $dateKey = $currentDay->format('Y-m-d');
            $dayStart = $dateKey === $startDateKey
                ? $eventStart
                : $currentDay->setTime(0, 0, 0);
            $dayEnd = $dateKey === $endDateKey
                ? $eventEnd
                : $currentDay->setTime(23, 59, 59);
            if ($dayEnd < $dayStart) {
                $dayEnd = $dayStart;
            }

            $days[] = [
                'date' => $dateKey,
                'starts_at' => $dayStart->format('Y-m-d H:i:s'),
                'ends_at' => $dayEnd->format('Y-m-d H:i:s'),
            ];

            $currentDay = $currentDay->modify('+1 day');
        }

        return $days;
    }

    private static function calendarHasLiveDependencies(PDO $db, int $eventId): bool
    {
        if (self::tableExists($db, 'participant_meals')) {
            $stmtMeals = $db->prepare("
                SELECT COUNT(*)
                FROM participant_meals pm
                JOIN event_days ed ON ed.id = pm.event_day_id
                WHERE ed.event_id = ?
            ");
            $stmtMeals->execute([$eventId]);
            if ((int)$stmtMeals->fetchColumn() > 0) {
                return true;
            }
        }

        if (self::tableExists($db, 'workforce_assignments') && self::tableExists($db, 'event_shifts')) {
            $stmtAssignments = $db->prepare("
                SELECT COUNT(*)
                FROM workforce_assignments wa
                JOIN event_shifts es ON es.id = wa.event_shift_id
                JOIN event_days ed ON ed.id = es.event_day_id
                WHERE ed.event_id = ?
            ");
            $stmtAssignments->execute([$eventId]);
            if ((int)$stmtAssignments->fetchColumn() > 0) {
                return true;
            }
        }

        return false;
    }

    // ── Commercial config (ticket types, batches, commissaries) ───────────────

    private static function deleteCommercialConfig(PDO $db, int $eventId, int $organizerId): void
    {
        $tables = ['ticket_commissions', 'ticket_batches', 'commissaries'];
        foreach ($tables as $table) {
            if (!self::tableExists($db, $table) || !self::columnExists($db, $table, 'event_id')) {
                continue;
            }

            $sql = "DELETE FROM {$table} WHERE event_id = :event_id";
            if (self::columnExists($db, $table, 'organizer_id')) {
                $sql .= " AND organizer_id = :organizer_id";
            }

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            if (self::columnExists($db, $table, 'organizer_id')) {
                $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
            }
            $stmt->execute();
        }
    }

    private static function persistCommercialConfig(PDO $db, int $eventId, int $organizerId, array $commercialConfig): void
    {
        self::ensureCommercialSchemaReady($db);
        $batches = $commercialConfig['batches'] ?? [];
        $ticketTypes = self::buildTicketTypesForLegacyFlow($commercialConfig['ticket_types'] ?? [], $batches);
        $ticketTypeMap = self::syncTicketTypes($db, $eventId, $organizerId, $ticketTypes);
        self::syncBatches($db, $eventId, $organizerId, $batches, $ticketTypeMap);
        self::syncCommissaries($db, $eventId, $organizerId, $commercialConfig['commissaries'] ?? []);
    }

    private static function ensureCommercialSchemaReady(PDO $db): void
    {
        $requiredTables = ['ticket_batches', 'commissaries', 'ticket_commissions'];
        foreach ($requiredTables as $table) {
            if (!self::tableExists($db, $table)) {
                throw new RuntimeException("Migration 008 nao aplicada: tabela {$table} ausente.", 409);
            }
        }

        foreach (['ticket_batch_id', 'commissary_id'] as $column) {
            if (!self::columnExists($db, 'tickets', $column)) {
                throw new RuntimeException("Migration 008 nao aplicada: coluna tickets.{$column} ausente.", 409);
            }
        }
    }

    private static function buildTicketTypesForLegacyFlow(array $ticketTypes, array $batches): array
    {
        if (count($ticketTypes) > 0 || count($batches) === 0) {
            return $ticketTypes;
        }

        $basePrice = 0.0;
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }
            $price = (float)($batch['price'] ?? 0);
            if ($price > 0 && ($basePrice <= 0 || $price < $basePrice)) {
                $basePrice = $price;
            }
        }

        return [[
            'id' => null,
            'client_key' => 'legacy-default-ticket-type',
            'name' => 'Ingresso Comercial',
            'price' => $basePrice,
        ]];
    }

    private static function syncTicketTypes(PDO $db, int $eventId, int $organizerId, array $items): array
    {
        $stmtExisting = $db->prepare('
            SELECT id, name, organizer_id
            FROM ticket_types
            WHERE event_id = ? AND organizer_id = ?
            ORDER BY id ASC
        ');
        $stmtExisting->execute([$eventId, $organizerId]);
        $existingRows = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);
        $existingById = [];
        foreach ($existingRows as $row) {
            $existingById[(int)$row['id']] = $row;
        }

        $keptIds = [];
        $map = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Tipo de ingresso invalido na posicao " . ($index + 1) . '.', 422);
            }

            $name = $item['name'] ?? '';
            if ($name === '') {
                throw new RuntimeException("Nome do tipo de ingresso e obrigatorio na posicao " . ($index + 1) . '.', 422);
            }
            if ($item['price'] < 0) {
                throw new RuntimeException("Preco invalido no tipo de ingresso \"{$name}\".", 422);
            }

            if ($item['id'] !== null) {
                if (!isset($existingById[$item['id']])) {
                    throw new RuntimeException('Tipo de ingresso invalido para este evento.', 404);
                }
                if ($existingById[$item['id']]['organizer_id'] === null) {
                    throw new RuntimeException(
                        "Tipo de ingresso \"{$name}\" esta em escopo legado sem organizer_id. Regularize o vinculo antes de editar este tipo.",
                        409
                    );
                }

                $hasSector = self::ticketTypesHasSector($db);
                if ($hasSector) {
                    $stmtUpdate = $db->prepare("
                        UPDATE ticket_types
                        SET name = ?, price = ?, sector = ?, updated_at = NOW()
                        WHERE id = ? AND event_id = ? AND organizer_id = ?
                    ");
                    $stmtUpdate->execute([$name, $item['price'], $item['sector'] ?? null, $item['id'], $eventId, $organizerId]);
                } else {
                    $stmtUpdate = $db->prepare("
                        UPDATE ticket_types
                        SET name = ?, price = ?, updated_at = NOW()
                        WHERE id = ? AND event_id = ? AND organizer_id = ?
                    ");
                    $stmtUpdate->execute([$name, $item['price'], $item['id'], $eventId, $organizerId]);
                }

                $keptIds[] = $item['id'];
                if ($item['client_key']) {
                    $map[$item['client_key']] = $item['id'];
                }
                continue;
            }

            $hasSector = self::ticketTypesHasSector($db);
            if ($hasSector) {
                $stmtInsert = $db->prepare("
                    INSERT INTO ticket_types (event_id, name, price, sector, created_at, updated_at, organizer_id)
                    VALUES (?, ?, ?, ?, NOW(), NOW(), ?)
                    RETURNING id
                ");
                $stmtInsert->execute([$eventId, $name, $item['price'], $item['sector'] ?? null, $organizerId]);
            } else {
                $stmtInsert = $db->prepare("
                    INSERT INTO ticket_types (event_id, name, price, created_at, updated_at, organizer_id)
                    VALUES (?, ?, ?, NOW(), NOW(), ?)
                    RETURNING id
                ");
                $stmtInsert->execute([$eventId, $name, $item['price'], $organizerId]);
            }
            $newId = (int)$stmtInsert->fetchColumn();
            $keptIds[] = $newId;
            if ($item['client_key']) {
                $map[$item['client_key']] = $newId;
            }
        }

        foreach ($existingById as $existingId => $existing) {
            if (in_array($existingId, $keptIds, true)) {
                continue;
            }
            if ($existing['organizer_id'] === null) {
                continue;
            }

            self::assertTicketTypeRemovable($db, $existingId, $eventId, $organizerId, $existing['name']);
            $stmtDelete = $db->prepare('DELETE FROM ticket_types WHERE id = ? AND event_id = ? AND organizer_id = ?');
            $stmtDelete->execute([$existingId, $eventId, $organizerId]);
        }

        return $map;
    }

    private static function assertTicketTypeRemovable(PDO $db, int $ticketTypeId, int $eventId, int $organizerId, string $name): void
    {
        $stmtTickets = $db->prepare('SELECT COUNT(*) FROM tickets WHERE ticket_type_id = ? AND event_id = ? AND organizer_id = ?');
        $stmtTickets->execute([$ticketTypeId, $eventId, $organizerId]);
        if ((int)$stmtTickets->fetchColumn() > 0) {
            throw new RuntimeException("O tipo de ingresso \"{$name}\" ja possui ingressos emitidos e nao pode ser removido.", 409);
        }

        $stmtBatches = $db->prepare('SELECT COUNT(*) FROM ticket_batches WHERE ticket_type_id = ? AND event_id = ? AND organizer_id = ?');
        $stmtBatches->execute([$ticketTypeId, $eventId, $organizerId]);
        if ((int)$stmtBatches->fetchColumn() > 0) {
            throw new RuntimeException("O tipo de ingresso \"{$name}\" ja esta vinculado a lotes comerciais e nao pode ser removido.", 409);
        }
    }

    private static function syncBatches(PDO $db, int $eventId, int $organizerId, array $items, array $ticketTypeMap = []): void
    {
        $stmtExisting = $db->prepare('SELECT id, name FROM ticket_batches WHERE event_id = ? AND organizer_id = ? ORDER BY id ASC');
        $stmtExisting->execute([$eventId, $organizerId]);
        $existingRows = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);
        $existingById = [];
        foreach ($existingRows as $row) {
            $existingById[(int)$row['id']] = $row;
        }

        $keptIds = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Lote invalido na posicao " . ($index + 1) . '.', 422);
            }

            $name = $item['name'] ?? '';
            if ($name === '') {
                throw new RuntimeException("Nome do lote e obrigatorio na posicao " . ($index + 1) . '.', 422);
            }

            $quantityTotal = $item['quantity_total'];
            if ($quantityTotal !== null && $quantityTotal < 0) {
                throw new RuntimeException("Quantidade total invalida no lote \"{$name}\".", 422);
            }
            if ($item['price'] < 0) {
                throw new RuntimeException("Preco invalido no lote \"{$name}\".", 422);
            }

            $resolvedTicketTypeId = $item['ticket_type_id'];
            if ($resolvedTicketTypeId === null && !empty($item['ticket_type_client_key'])) {
                $resolvedTicketTypeId = $ticketTypeMap[$item['ticket_type_client_key']] ?? null;
                if ($resolvedTicketTypeId === null) {
                    throw new RuntimeException("Tipo de ingresso vinculado ao lote \"{$name}\" nao foi resolvido.", 422);
                }
            }

            if ($resolvedTicketTypeId !== null) {
                $stmtType = $db->prepare('SELECT id FROM ticket_types WHERE id = ? AND event_id = ? AND organizer_id = ? LIMIT 1');
                $stmtType->execute([$resolvedTicketTypeId, $eventId, $organizerId]);
                if (!$stmtType->fetchColumn()) {
                    if (self::hasLegacyNullScopedTicketType($db, $eventId, $organizerId, $resolvedTicketTypeId)) {
                        throw new RuntimeException(
                            "Tipo de ingresso legado sem organizer_id detectado no lote \"{$name}\". Regularize o escopo do tipo antes de vincular ao lote.",
                            409
                        );
                    }
                    throw new RuntimeException("Tipo de ingresso invalido no lote \"{$name}\".", 422);
                }
            }

            if ($item['id'] !== null) {
                if (!isset($existingById[$item['id']])) {
                    throw new RuntimeException("Lote comercial invalido para este evento.", 404);
                }

                $stmtSold = $db->prepare('SELECT quantity_sold FROM ticket_batches WHERE id = ? AND organizer_id = ? AND event_id = ? LIMIT 1');
                $stmtSold->execute([$item['id'], $organizerId, $eventId]);
                $quantitySold = (int)$stmtSold->fetchColumn();
                if ($quantityTotal !== null && $quantityTotal < $quantitySold) {
                    throw new RuntimeException("O lote \"{$name}\" nao pode ter quantidade menor que os ingressos ja vendidos.", 409);
                }

                $stmtUpdate = $db->prepare("
                    UPDATE ticket_batches
                    SET ticket_type_id = ?, name = ?, code = ?, price = ?,
                        starts_at = ?, ends_at = ?, quantity_total = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ? AND organizer_id = ? AND event_id = ?
                ");
                $stmtUpdate->execute([
                    $resolvedTicketTypeId,
                    $name,
                    $item['code'] !== '' ? $item['code'] : null,
                    $item['price'],
                    $item['starts_at'],
                    $item['ends_at'],
                    $quantityTotal,
                    $item['is_active'],
                    $item['id'],
                    $organizerId,
                    $eventId,
                ]);

                $keptIds[] = $item['id'];
                continue;
            }

            $stmtInsert = $db->prepare("
                INSERT INTO ticket_batches
                    (organizer_id, event_id, ticket_type_id, name, code, price, starts_at, ends_at, quantity_total, quantity_sold, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmtInsert->execute([
                $organizerId,
                $eventId,
                $resolvedTicketTypeId,
                $name,
                $item['code'] !== '' ? $item['code'] : null,
                $item['price'],
                $item['starts_at'],
                $item['ends_at'],
                $quantityTotal,
                $item['is_active'],
            ]);
            $keptIds[] = (int)$stmtInsert->fetchColumn();
        }

        foreach ($existingById as $existingId => $existing) {
            if (in_array($existingId, $keptIds, true)) {
                continue;
            }

            self::assertBatchRemovable($db, $existingId, $eventId, $organizerId, $existing['name']);
            $stmtDelete = $db->prepare('DELETE FROM ticket_batches WHERE id = ? AND event_id = ? AND organizer_id = ?');
            $stmtDelete->execute([$existingId, $eventId, $organizerId]);
        }
    }

    private static function hasLegacyNullScopedTicketType(PDO $db, int $eventId, int $organizerId, ?int $ticketTypeId = null): bool
    {
        $sql = '
            SELECT 1
            FROM ticket_types tt
            INNER JOIN events e ON e.id = tt.event_id
            WHERE tt.event_id = :event_id
              AND tt.organizer_id IS NULL
              AND e.organizer_id = :organizer_id
        ';
        if ($ticketTypeId !== null) {
            $sql .= ' AND tt.id = :ticket_type_id';
        }
        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->bindValue(':organizer_id', $organizerId, PDO::PARAM_INT);
        if ($ticketTypeId !== null) {
            $stmt->bindValue(':ticket_type_id', $ticketTypeId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    private static function assertBatchRemovable(PDO $db, int $batchId, int $eventId, int $organizerId, string $name): void
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tickets WHERE ticket_batch_id = ? AND event_id = ? AND organizer_id = ?');
        $stmt->execute([$batchId, $eventId, $organizerId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException("O lote \"{$name}\" ja possui ingressos vinculados e nao pode ser removido.", 409);
        }
    }

    private static function syncCommissaries(PDO $db, int $eventId, int $organizerId, array $items): void
    {
        $stmtExisting = $db->prepare('SELECT id, name FROM commissaries WHERE event_id = ? AND organizer_id = ? ORDER BY id ASC');
        $stmtExisting->execute([$eventId, $organizerId]);
        $existingRows = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);
        $existingById = [];
        foreach ($existingRows as $row) {
            $existingById[(int)$row['id']] = $row;
        }

        $keptIds = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Comissario invalido na posicao " . ($index + 1) . '.', 422);
            }

            $name = $item['name'] ?? '';
            if ($name === '') {
                throw new RuntimeException("Nome do comissario e obrigatorio na posicao " . ($index + 1) . '.', 422);
            }
            if ($item['commission_value'] < 0) {
                throw new RuntimeException("Comissao invalida no comissario \"{$name}\".", 422);
            }
            if ($item['commission_mode'] === 'percent' && $item['commission_value'] > 100) {
                throw new RuntimeException("Comissao percentual acima de 100% no comissario \"{$name}\".", 422);
            }

            if ($item['id'] !== null) {
                if (!isset($existingById[$item['id']])) {
                    throw new RuntimeException('Comissario invalido para este evento.', 404);
                }

                $stmtUpdate = $db->prepare("
                    UPDATE commissaries
                    SET name = ?, email = ?, phone = ?, status = ?,
                        commission_mode = ?, commission_value = ?, updated_at = NOW()
                    WHERE id = ? AND organizer_id = ? AND event_id = ?
                ");
                $stmtUpdate->execute([
                    $name,
                    $item['email'] !== '' ? $item['email'] : null,
                    $item['phone'] !== '' ? $item['phone'] : null,
                    $item['status'],
                    $item['commission_mode'],
                    $item['commission_value'],
                    $item['id'],
                    $organizerId,
                    $eventId,
                ]);

                $keptIds[] = $item['id'];
                continue;
            }

            $stmtInsert = $db->prepare("
                INSERT INTO commissaries
                    (organizer_id, event_id, name, email, phone, status, commission_mode, commission_value, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmtInsert->execute([
                $organizerId,
                $eventId,
                $name,
                $item['email'] !== '' ? $item['email'] : null,
                $item['phone'] !== '' ? $item['phone'] : null,
                $item['status'],
                $item['commission_mode'],
                $item['commission_value'],
            ]);
            $keptIds[] = (int)$stmtInsert->fetchColumn();
        }

        foreach ($existingById as $existingId => $existing) {
            if (in_array($existingId, $keptIds, true)) {
                continue;
            }

            self::assertCommissaryRemovable($db, $existingId, $eventId, $organizerId, $existing['name']);
            $stmtDelete = $db->prepare('DELETE FROM commissaries WHERE id = ? AND event_id = ? AND organizer_id = ?');
            $stmtDelete->execute([$existingId, $eventId, $organizerId]);
        }
    }

    private static function assertCommissaryRemovable(PDO $db, int $commissaryId, int $eventId, int $organizerId, string $name): void
    {
        $stmtTickets = $db->prepare('SELECT COUNT(*) FROM tickets WHERE commissary_id = ? AND event_id = ? AND organizer_id = ?');
        $stmtTickets->execute([$commissaryId, $eventId, $organizerId]);
        if ((int)$stmtTickets->fetchColumn() > 0) {
            throw new RuntimeException("O comissario \"{$name}\" ja possui ingressos vinculados e nao pode ser removido.", 409);
        }

        $stmtCommissions = $db->prepare('SELECT COUNT(*) FROM ticket_commissions WHERE commissary_id = ? AND event_id = ? AND organizer_id = ?');
        $stmtCommissions->execute([$commissaryId, $eventId, $organizerId]);
        if ((int)$stmtCommissions->fetchColumn() > 0) {
            throw new RuntimeException("O comissario \"{$name}\" ja possui comissoes registradas e nao pode ser removido.", 409);
        }
    }
}
