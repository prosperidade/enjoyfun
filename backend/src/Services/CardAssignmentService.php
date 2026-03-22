<?php

class CardAssignmentService
{
    public static function tableExists(PDO $db): bool
    {
        return self::schemaTableExists($db, 'event_card_assignments');
    }

    public static function ensureTableExists(PDO $db): void
    {
        if (self::tableExists($db)) {
            return;
        }

        throw new RuntimeException(
            'Estrutura de cartões por evento indisponível. Aplique a migration 026_event_scoped_card_assignments.sql.',
            500
        );
    }

    public static function operationalStructureReady(PDO $db): bool
    {
        if (!self::tableExists($db)) {
            return false;
        }

        foreach (['participant_id', 'person_id', 'source_batch_id', 'source_role_id', 'source_event_role_id', 'issued_at'] as $column) {
            if (!self::schemaColumnExists($db, 'event_card_assignments', $column)) {
                return false;
            }
        }

        return self::schemaTableExists($db, 'card_issue_batches')
            && self::schemaTableExists($db, 'card_issue_batch_items');
    }

    public static function ensureOperationalStructureExists(PDO $db): void
    {
        if (self::operationalStructureReady($db)) {
            return;
        }

        throw new RuntimeException(
            'Estrutura de emissão em massa indisponível. Aplique a migration 028_workforce_bulk_card_issuance_foundation.sql.',
            500
        );
    }

    public static function schemaTableExists(PDO $db, string $table): bool
    {
        static $cache = [];
        $table = trim(strtolower($table));
        if ($table === '') {
            return false;
        }

        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = 'public'
                  AND table_name = :table
            )
        ");
        $stmt->execute([':table' => $table]);

        $cache[$table] = (bool)$stmt->fetchColumn();
        return $cache[$table];
    }

    public static function schemaColumnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $table = trim(strtolower($table));
        $column = trim(strtolower($column));
        if ($table === '' || $column === '') {
            return false;
        }

        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = :table
                  AND column_name = :column
            )
        ");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }

    public static function resolveOrganizerEventId(PDO $db, int $organizerId, mixed $rawEventId, bool $required = false): ?int
    {
        $eventId = (int)($rawEventId ?? 0);
        if ($eventId <= 0) {
            if ($required) {
                throw new RuntimeException('event_id é obrigatório para operações de cartão por evento.', 422);
            }

            return null;
        }

        $stmt = $db->prepare("
            SELECT id
            FROM public.events
            WHERE id = ?
              AND organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$eventId, $organizerId]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Evento fora do escopo do organizador.', 403);
        }

        return $eventId;
    }

    public static function assignCardToEvent(PDO $db, string $cardId, int $organizerId, int $eventId, array $payload = []): void
    {
        self::ensureTableExists($db);

        $holderName = trim((string)($payload['holder_name_snapshot'] ?? $payload['user_name'] ?? ''));
        $holderDocument = self::normalizeDocument((string)($payload['holder_document_snapshot'] ?? $payload['cpf'] ?? ''));
        $issuedByUserId = self::normalizeOptionalInt($payload['issued_by_user_id'] ?? null);
        $participantId = self::normalizeOptionalInt($payload['participant_id'] ?? null);
        $personId = self::normalizeOptionalInt($payload['person_id'] ?? null);
        $sourceBatchId = self::normalizeOptionalInt($payload['source_batch_id'] ?? null);
        $sourceRoleId = self::normalizeOptionalInt($payload['source_role_id'] ?? null);
        $sourceEventRoleId = self::normalizeOptionalInt($payload['source_event_role_id'] ?? null);
        $sector = self::normalizeOptionalText($payload['sector'] ?? null, 50);
        $sourceModule = self::normalizeOptionalText($payload['source_module'] ?? null, 50);
        $notes = self::normalizeOptionalText($payload['notes'] ?? null);
        $issuedAt = self::normalizeOptionalTimestamp($payload['issued_at'] ?? null);

        $updateFields = [
            'organizer_id = ?',
            'event_id = ?',
            "holder_name_snapshot = COALESCE(NULLIF(?, ''), holder_name_snapshot)",
            "holder_document_snapshot = COALESCE(NULLIF(?, ''), holder_document_snapshot)",
            'issued_by_user_id = COALESCE(?, issued_by_user_id)',
        ];
        $updateParams = [
            $organizerId,
            $eventId,
            $holderName,
            $holderDocument,
            $issuedByUserId,
        ];

        if (self::schemaColumnExists($db, 'event_card_assignments', 'participant_id')) {
            $updateFields[] = 'participant_id = COALESCE(?, participant_id)';
            $updateParams[] = $participantId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'person_id')) {
            $updateFields[] = 'person_id = COALESCE(?, person_id)';
            $updateParams[] = $personId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'sector')) {
            $updateFields[] = "sector = COALESCE(NULLIF(?, ''), sector)";
            $updateParams[] = $sector;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_module')) {
            $updateFields[] = "source_module = COALESCE(NULLIF(?, ''), source_module)";
            $updateParams[] = $sourceModule;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_batch_id')) {
            $updateFields[] = 'source_batch_id = COALESCE(?, source_batch_id)';
            $updateParams[] = $sourceBatchId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_role_id')) {
            $updateFields[] = 'source_role_id = COALESCE(?, source_role_id)';
            $updateParams[] = $sourceRoleId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_event_role_id')) {
            $updateFields[] = 'source_event_role_id = COALESCE(?, source_event_role_id)';
            $updateParams[] = $sourceEventRoleId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'issued_at')) {
            $updateFields[] = 'issued_at = COALESCE(?::timestamp, issued_at, NOW())';
            $updateParams[] = $issuedAt;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'notes')) {
            $updateFields[] = "notes = COALESCE(NULLIF(?, ''), notes)";
            $updateParams[] = $notes;
        }

        $updateFields[] = "status = 'active'";
        $updateFields[] = 'updated_at = NOW()';
        $updateParams[] = $cardId;

        $update = $db->prepare(sprintf(
            "UPDATE public.event_card_assignments
             SET %s
             WHERE card_id = ?::uuid
               AND status = 'active'",
            implode(",\n                ", $updateFields)
        ));
        $update->execute($updateParams);

        if ($update->rowCount() > 0) {
            return;
        }

        $columns = [
            'card_id',
            'organizer_id',
            'event_id',
            'holder_name_snapshot',
            'holder_document_snapshot',
            'issued_by_user_id',
            'status',
            'created_at',
            'updated_at',
        ];
        $values = [
            '?::uuid',
            '?',
            '?',
            "NULLIF(?, '')",
            "NULLIF(?, '')",
            '?',
            "'active'",
            'NOW()',
            'NOW()',
        ];
        $insertParams = [
            $cardId,
            $organizerId,
            $eventId,
            $holderName,
            $holderDocument,
            $issuedByUserId,
        ];

        if (self::schemaColumnExists($db, 'event_card_assignments', 'participant_id')) {
            $columns[] = 'participant_id';
            $values[] = '?';
            $insertParams[] = $participantId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'person_id')) {
            $columns[] = 'person_id';
            $values[] = '?';
            $insertParams[] = $personId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'sector')) {
            $columns[] = 'sector';
            $values[] = "NULLIF(?, '')";
            $insertParams[] = $sector;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_module')) {
            $columns[] = 'source_module';
            $values[] = "NULLIF(?, '')";
            $insertParams[] = $sourceModule;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_batch_id')) {
            $columns[] = 'source_batch_id';
            $values[] = '?';
            $insertParams[] = $sourceBatchId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_role_id')) {
            $columns[] = 'source_role_id';
            $values[] = '?';
            $insertParams[] = $sourceRoleId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'source_event_role_id')) {
            $columns[] = 'source_event_role_id';
            $values[] = '?';
            $insertParams[] = $sourceEventRoleId;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'issued_at')) {
            $columns[] = 'issued_at';
            $values[] = 'COALESCE(?::timestamp, NOW())';
            $insertParams[] = $issuedAt;
        }
        if (self::schemaColumnExists($db, 'event_card_assignments', 'notes')) {
            $columns[] = 'notes';
            $values[] = "NULLIF(?, '')";
            $insertParams[] = $notes;
        }

        $insert = $db->prepare(sprintf(
            'INSERT INTO public.event_card_assignments (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $values)
        ));
        $insert->execute($insertParams);
    }

    public static function resolveEventHolderBinding(PDO $db, int $organizerId, int $eventId, array $identity = []): array
    {
        $participantId = self::normalizeOptionalInt($identity['participant_id'] ?? null);
        $personId = self::normalizeOptionalInt($identity['person_id'] ?? null);
        $holderName = trim((string)($identity['name'] ?? $identity['holder_name_snapshot'] ?? $identity['user_name'] ?? ''));
        $holderDocument = self::normalizeDocument((string)($identity['document'] ?? $identity['holder_document_snapshot'] ?? $identity['cpf'] ?? ''));
        $holderEmail = strtolower(trim((string)($identity['email'] ?? '')));

        $binding = [
            'participant_id' => $participantId,
            'person_id' => $personId,
            'holder_name' => $holderName,
            'holder_document' => $holderDocument,
            'holder_email' => $holderEmail,
            'matched' => false,
            'match_type' => null,
        ];

        if ($organizerId <= 0 || $eventId <= 0) {
            return $binding;
        }

        if ($participantId !== null) {
            $stmt = $db->prepare("
                SELECT
                    ep.id AS participant_id,
                    p.id AS person_id,
                    COALESCE(NULLIF(TRIM(p.name), ''), '') AS holder_name,
                    COALESCE(NULLIF(TRIM(p.document), ''), '') AS holder_document,
                    COALESCE(NULLIF(TRIM(p.email), ''), '') AS holder_email
                FROM public.event_participants ep
                JOIN public.people p
                  ON p.id = ep.person_id
                WHERE ep.id = ?
                  AND ep.event_id = ?
                  AND p.organizer_id = ?
                LIMIT 1
            ");
            $stmt->execute([$participantId, $eventId, $organizerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return self::mergeResolvedEventHolderBinding($binding, $row, 'participant');
            }
        }

        if ($personId !== null) {
            $stmt = $db->prepare("
                SELECT
                    ep.id AS participant_id,
                    p.id AS person_id,
                    COALESCE(NULLIF(TRIM(p.name), ''), '') AS holder_name,
                    COALESCE(NULLIF(TRIM(p.document), ''), '') AS holder_document,
                    COALESCE(NULLIF(TRIM(p.email), ''), '') AS holder_email
                FROM public.event_participants ep
                JOIN public.people p
                  ON p.id = ep.person_id
                WHERE ep.event_id = ?
                  AND p.id = ?
                  AND p.organizer_id = ?
                ORDER BY ep.id ASC
                LIMIT 2
            ");
            $stmt->execute([$eventId, $personId, $organizerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) === 1) {
                return self::mergeResolvedEventHolderBinding($binding, $rows[0], 'person');
            }
        }

        $conditions = [];
        $params = [
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
        ];

        if ($holderDocument !== '') {
            $conditions[] = "REGEXP_REPLACE(COALESCE(p.document, ''), '\\D+', '', 'g') = :document";
            $params[':document'] = $holderDocument;
        }
        if ($holderEmail !== '') {
            $conditions[] = "LOWER(TRIM(COALESCE(p.email, ''))) = :email";
            $params[':email'] = $holderEmail;
        }
        if ($holderDocument === '' && $holderEmail === '' && $holderName !== '') {
            $conditions[] = "LOWER(TRIM(COALESCE(p.name, ''))) = :name";
            $params[':name'] = strtolower($holderName);
        }

        if (empty($conditions)) {
            return $binding;
        }

        $stmt = $db->prepare("
            SELECT
                ep.id AS participant_id,
                p.id AS person_id,
                COALESCE(NULLIF(TRIM(p.name), ''), '') AS holder_name,
                COALESCE(NULLIF(TRIM(p.document), ''), '') AS holder_document,
                COALESCE(NULLIF(TRIM(p.email), ''), '') AS holder_email
            FROM public.event_participants ep
            JOIN public.people p
              ON p.id = ep.person_id
            WHERE ep.event_id = :event_id
              AND p.organizer_id = :organizer_id
              AND (" . implode(' OR ', $conditions) . ")
            ORDER BY ep.id ASC
            LIMIT 2
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== 1) {
            return $binding;
        }

        return self::mergeResolvedEventHolderBinding($binding, $rows[0], 'identity');
    }

    public static function resolveOrganizerUserIdByIdentity(PDO $db, int $organizerId, array $identity = []): ?int
    {
        $personId = self::normalizeOptionalInt($identity['person_id'] ?? null);
        $email = strtolower(trim((string)($identity['email'] ?? '')));
        $document = self::normalizeDocument((string)($identity['document'] ?? $identity['cpf'] ?? ''));

        if ($personId !== null && ($email === '' || $document === '')) {
            $stmt = $db->prepare("
                SELECT
                    COALESCE(NULLIF(TRIM(email), ''), '') AS email,
                    COALESCE(NULLIF(TRIM(document), ''), '') AS document
                FROM public.people
                WHERE id = ?
                  AND organizer_id = ?
                LIMIT 1
            ");
            $stmt->execute([$personId, $organizerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($email === '') {
                $email = strtolower(trim((string)($row['email'] ?? '')));
            }
            if ($document === '') {
                $document = self::normalizeDocument((string)($row['document'] ?? ''));
            }
        }

        if ($email === '' && $document === '') {
            return null;
        }

        $conditions = [];
        $params = [
            ':organizer_id' => $organizerId,
        ];

        if ($email !== '') {
            $conditions[] = "LOWER(TRIM(COALESCE(u.email, ''))) = :email";
            $params[':email'] = $email;
        }
        if ($document !== '') {
            $conditions[] = "REGEXP_REPLACE(COALESCE(u.cpf, ''), '\\D+', '', 'g') = :document";
            $params[':document'] = $document;
        }

        $stmt = $db->prepare("
            SELECT u.id
            FROM public.users u
            WHERE (u.organizer_id = :organizer_id OR u.id = :organizer_id)
              AND (" . implode(' OR ', $conditions) . ")
            ORDER BY u.id ASC
            LIMIT 2
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($rows) !== 1) {
            return null;
        }

        $userId = (int)($rows[0]['id'] ?? 0);
        return $userId > 0 ? $userId : null;
    }

    public static function normalizeDocument(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value)) ?? '';
    }

    private static function mergeResolvedEventHolderBinding(array $binding, array $row, string $matchType): array
    {
        $participantId = self::normalizeOptionalInt($row['participant_id'] ?? null);
        $personId = self::normalizeOptionalInt($row['person_id'] ?? null);
        $holderName = trim((string)($row['holder_name'] ?? ''));
        $holderDocument = self::normalizeDocument((string)($row['holder_document'] ?? ''));
        $holderEmail = strtolower(trim((string)($row['holder_email'] ?? '')));

        if ($participantId !== null) {
            $binding['participant_id'] = $participantId;
        }
        if ($personId !== null) {
            $binding['person_id'] = $personId;
        }
        if ($holderName !== '') {
            $binding['holder_name'] = $holderName;
        }
        if ($holderDocument !== '') {
            $binding['holder_document'] = $holderDocument;
        }
        if ($holderEmail !== '') {
            $binding['holder_email'] = $holderEmail;
        }

        $binding['matched'] = true;
        $binding['match_type'] = $matchType;

        return $binding;
    }

    private static function normalizeOptionalInt(mixed $value): ?int
    {
        $normalized = (int)($value ?? 0);
        return $normalized > 0 ? $normalized : null;
    }

    private static function normalizeOptionalText(mixed $value, ?int $maxLength = null): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }

        if ($maxLength !== null && $maxLength > 0) {
            $text = function_exists('mb_substr')
                ? mb_substr($text, 0, $maxLength)
                : substr($text, 0, $maxLength);
        }

        return $text;
    }

    private static function normalizeOptionalTimestamp(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text !== '' ? $text : null;
    }
}
