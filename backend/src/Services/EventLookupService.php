<?php

class EventLookupService
{
    public static function resolvePublicEvent(PDO $db, mixed $rawEventId = null, mixed $rawSlug = null): ?array
    {
        return self::resolveEvent($db, null, $rawEventId, $rawSlug);
    }

    public static function resolveOrganizerEvent(PDO $db, int $organizerId, mixed $rawEventId = null, mixed $rawSlug = null): ?array
    {
        if ($organizerId <= 0) {
            return null;
        }

        return self::resolveEvent($db, $organizerId, $rawEventId, $rawSlug);
    }

    private static function resolveEvent(PDO $db, ?int $organizerId, mixed $rawEventId, mixed $rawSlug): ?array
    {
        $eventId = (int)($rawEventId ?? 0);
        $slug = trim((string)($rawSlug ?? ''));

        if ($eventId <= 0 && $slug === '') {
            return null;
        }

        $sql = "
            SELECT id, name, slug, organizer_id, starts_at, status
            FROM public.events
            WHERE
        ";
        $params = [];

        if ($eventId > 0) {
            $sql .= ' id = ?';
            $params[] = $eventId;
        } else {
            $sql .= ' slug = ?';
            $params[] = $slug;
        }

        if ($organizerId !== null) {
            $sql .= ' AND organizer_id = ?';
            $params[] = $organizerId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        return $event ?: null;
    }
}
