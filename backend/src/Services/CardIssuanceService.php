<?php

require_once BASE_PATH . '/src/Services/CardAssignmentService.php';
require_once BASE_PATH . '/src/Services/WalletSecurityService.php';

class CardIssuanceService
{
    public const SOURCE_WORKFORCE_BULK = 'workforce_bulk';

    public const PREVIEW_ELIGIBLE = 'eligible';
    public const PREVIEW_ALREADY_HAS_ACTIVE_CARD = 'already_has_active_card';
    public const PREVIEW_LEGACY_CONFLICT = 'legacy_conflict_review_required';
    public const PREVIEW_MISSING_IDENTITY = 'missing_identity';
    public const PREVIEW_OUT_OF_SCOPE = 'out_of_scope';
    public const PREVIEW_ERROR = 'error';

    public const ISSUE_ISSUED = 'issued';
    public const ISSUE_SKIPPED = 'skipped';
    public const ISSUE_FAILED = 'failed';

    public static function previewWorkforceParticipants(PDO $db, int $organizerId, int $eventId, array $participantIds, array $context = []): array
    {
        self::ensureFoundationReady($db);
        CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $eventId, true);

        $normalizedIds = self::normalizeParticipantIds($participantIds);
        if (empty($normalizedIds)) {
            throw new RuntimeException('participant_ids deve conter ao menos um participante válido.', 422);
        }

        $participantMap = self::loadWorkforceParticipantContext($db, $organizerId, $eventId, $normalizedIds);
        $items = [];

        foreach ($normalizedIds as $participantId) {
            $row = $participantMap[$participantId] ?? null;
            if ($row === null) {
                $items[] = self::buildOutOfScopeItem($participantId);
                continue;
            }

            $items[] = self::buildPreviewItemFromRow($row);
        }

        $initialBalance = self::normalizeMoneyAmount($context['initial_balance'] ?? 0);
        $summary = self::buildPreviewSummary($items);
        $summary['estimated_initial_credit_total'] = self::calculateTotalCredit(
            (int)($summary['eligible_count'] ?? 0),
            $initialBalance
        );

        return [
            'summary' => $summary,
            'items' => $items,
            'can_issue' => count(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === self::PREVIEW_ELIGIBLE)) > 0,
            'event_id' => $eventId,
            'source_module' => self::normalizeSourceModule($context['source_module'] ?? self::SOURCE_WORKFORCE_BULK),
            'initial_balance' => $initialBalance,
        ];
    }

    public static function issueWorkforceParticipants(
        PDO $db,
        int $organizerId,
        int $eventId,
        array $participantIds,
        int $issuedByUserId,
        array $context = []
    ): array {
        self::ensureFoundationReady($db);
        CardAssignmentService::resolveOrganizerEventId($db, $organizerId, $eventId, true);

        if ($issuedByUserId <= 0) {
            throw new RuntimeException('issued_by_user_id é obrigatório para emitir cartões em massa.', 422);
        }

        $normalizedIds = self::normalizeParticipantIds($participantIds);
        if (empty($normalizedIds)) {
            throw new RuntimeException('participant_ids deve conter ao menos um participante válido.', 422);
        }

        $sourceModule = self::normalizeSourceModule($context['source_module'] ?? self::SOURCE_WORKFORCE_BULK);
        $idempotencyKey = self::normalizeIdempotencyKey($context['idempotency_key'] ?? null);
        $context['initial_balance'] = self::normalizeMoneyAmount($context['initial_balance'] ?? 0);
        if ($idempotencyKey === null) {
            throw new RuntimeException('idempotency_key é obrigatório para emitir cartões em massa.', 422);
        }

        $existingBatchId = self::findExistingBatchId($db, $organizerId, $eventId, $sourceModule, $idempotencyKey);
        if ($existingBatchId !== null) {
            return self::buildBatchResponse($db, $existingBatchId, true);
        }

        $preview = self::previewWorkforceParticipants($db, $organizerId, $eventId, $normalizedIds, $context);
        $batchId = self::createBatch($db, $organizerId, $eventId, $sourceModule, $issuedByUserId, $idempotencyKey, $preview, $context);

        foreach ($preview['items'] as $item) {
            if (($item['status'] ?? '') !== self::PREVIEW_ELIGIBLE) {
                self::insertBatchItem($db, $batchId, $item, self::ISSUE_SKIPPED, $item['existing_card_id'] ?? null, null);
                continue;
            }

            try {
                $issuedItem = self::processEligibleItem($db, $organizerId, $eventId, $batchId, $issuedByUserId, $item, $sourceModule, $context);
                self::insertBatchItem(
                    $db,
                    $batchId,
                    $issuedItem,
                    $issuedItem['status'],
                    $issuedItem['existing_card_id'] ?? null,
                    $issuedItem['issued_card_id'] ?? null
                );
            } catch (Throwable $e) {
                $failedItem = $item;
                $failedItem['status'] = self::ISSUE_FAILED;
                $failedItem['reason_code'] = 'issue_failed';
                $failedItem['reason_message'] = $e->getMessage();
                self::insertBatchItem($db, $batchId, $failedItem, self::ISSUE_FAILED, $item['existing_card_id'] ?? null, null);
            }
        }

        self::refreshBatchCounters($db, $batchId, (int)($preview['summary']['eligible_count'] ?? 0));

        return self::buildBatchResponse($db, $batchId, false);
    }

    private static function ensureFoundationReady(PDO $db): void
    {
        CardAssignmentService::ensureOperationalStructureExists($db);
    }

    private static function normalizeParticipantIds(array $participantIds): array
    {
        $normalized = [];
        foreach ($participantIds as $participantId) {
            $id = (int)$participantId;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }

    private static function normalizeSourceModule(mixed $value): string
    {
        $source = trim((string)$value);
        if ($source === '') {
            return self::SOURCE_WORKFORCE_BULK;
        }

        $source = preg_replace('/[^a-z0-9_\-]/i', '_', strtolower($source)) ?? self::SOURCE_WORKFORCE_BULK;
        $source = trim($source, '_');

        return $source !== '' ? substr($source, 0, 50) : self::SOURCE_WORKFORCE_BULK;
    }

    private static function normalizeIdempotencyKey(mixed $value): ?string
    {
        $key = trim((string)$value);
        return $key !== '' ? substr($key, 0, 120) : null;
    }

    private static function loadWorkforceParticipantContext(PDO $db, int $organizerId, int $eventId, array $participantIds): array
    {
        $placeholders = [];
        $params = [
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
        ];

        foreach ($participantIds as $index => $participantId) {
            $placeholder = ':participant_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $participantId;
        }

        $assignmentEventRoleSelect = CardAssignmentService::schemaColumnExists($db, 'workforce_assignments', 'event_role_id')
            ? 'wa.event_role_id'
            : 'NULL::integer AS event_role_id';

        $sql = sprintf(
            "
            SELECT
                ep.id AS participant_id,
                ep.event_id,
                ep.person_id,
                COALESCE(NULLIF(TRIM(p.name), ''), '') AS holder_name,
                COALESCE(NULLIF(TRIM(p.document), ''), '') AS holder_document,
                COALESCE(NULLIF(TRIM(wa.sector), ''), '') AS sector,
                wa.role_id,
                %s,
                COALESCE(active_assignments.active_count, 0)::int AS active_count,
                active_assignments.active_card_id,
                COALESCE(active_assignments.active_card_balance, 0)::numeric(10,2) AS active_card_balance,
                COALESCE(legacy_assignments.legacy_count, 0)::int AS legacy_count,
                legacy_assignments.legacy_card_id,
                COALESCE(legacy_assignments.legacy_card_balance, 0)::numeric(10,2) AS legacy_card_balance,
                COALESCE(document_assignments.document_count, 0)::int AS document_count,
                document_assignments.document_card_id,
                COALESCE(document_assignments.document_card_balance, 0)::numeric(10,2) AS document_card_balance
            FROM public.event_participants ep
            JOIN public.people p
              ON p.id = ep.person_id
            LEFT JOIN LATERAL (
                SELECT wa.role_id, wa.sector, %s
                FROM public.workforce_assignments wa
                WHERE wa.participant_id = ep.id
                ORDER BY wa.id DESC
                LIMIT 1
            ) wa ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*)::int AS active_count,
                    MIN(a.card_id::text) AS active_card_id,
                    MAX(COALESCE(dc.balance, 0))::numeric(10,2) AS active_card_balance
                FROM public.event_card_assignments a
                JOIN public.digital_cards dc
                  ON dc.id = a.card_id
                 AND dc.is_active = true
                WHERE a.event_id = ep.event_id
                  AND a.participant_id = ep.id
                  AND a.status = 'active'
            ) active_assignments ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*)::int AS legacy_count,
                    MIN(a.card_id::text) AS legacy_card_id,
                    MAX(COALESCE(dc.balance, 0))::numeric(10,2) AS legacy_card_balance
                FROM public.event_card_assignments a
                JOIN public.digital_cards dc
                  ON dc.id = a.card_id
                 AND dc.is_active = true
                WHERE a.event_id = ep.event_id
                  AND a.person_id = ep.person_id
                  AND a.participant_id IS NULL
                  AND a.status = 'active'
            ) legacy_assignments ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*)::int AS document_count,
                    MIN(a.card_id::text) AS document_card_id,
                    MAX(COALESCE(dc.balance, 0))::numeric(10,2) AS document_card_balance
                FROM public.event_card_assignments a
                JOIN public.digital_cards dc
                  ON dc.id = a.card_id
                 AND dc.is_active = true
                WHERE a.event_id = ep.event_id
                  AND a.participant_id IS NULL
                  AND a.person_id IS NULL
                  AND a.status = 'active'
                  AND regexp_replace(COALESCE(NULLIF(TRIM(a.holder_document_snapshot), ''), ''), '\D+', '', 'g') <> ''
                  AND regexp_replace(COALESCE(NULLIF(TRIM(a.holder_document_snapshot), ''), ''), '\D+', '', 'g')
                      = regexp_replace(COALESCE(NULLIF(TRIM(p.document), ''), ''), '\D+', '', 'g')
            ) document_assignments ON TRUE
            WHERE ep.event_id = :event_id
              AND p.organizer_id = :organizer_id
              AND ep.id IN (%s)
            ",
            $assignmentEventRoleSelect,
            CardAssignmentService::schemaColumnExists($db, 'workforce_assignments', 'event_role_id')
                ? 'wa.event_role_id'
                : 'NULL::integer AS event_role_id',
            implode(', ', $placeholders)
        );

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['participant_id']] = $row;
        }

        return $map;
    }

    private static function buildPreviewItemFromRow(array $row): array
    {
        $participantId = (int)($row['participant_id'] ?? 0);
        $personId = (int)($row['person_id'] ?? 0);
        $holderName = trim((string)($row['holder_name'] ?? ''));
        $activeCount = (int)($row['active_count'] ?? 0);
        $legacyCount = (int)($row['legacy_count'] ?? 0);
        $documentCount = (int)($row['document_count'] ?? 0);
        $activeCardId = self::normalizeUuid((string)($row['active_card_id'] ?? ''));
        $legacyCardId = self::normalizeUuid((string)($row['legacy_card_id'] ?? ''));
        $documentCardId = self::normalizeUuid((string)($row['document_card_id'] ?? ''));
        $activeCardBalance = self::normalizeMoneyAmount($row['active_card_balance'] ?? 0);
        $legacyCardBalance = self::normalizeMoneyAmount($row['legacy_card_balance'] ?? 0);
        $documentCardBalance = self::normalizeMoneyAmount($row['document_card_balance'] ?? 0);
        $matchedCardCount = $activeCount + $legacyCount + $documentCount;
        $matchedCardId = $activeCardId ?? $legacyCardId ?? $documentCardId;
        $matchedCardBalance = max($activeCardBalance, $legacyCardBalance, $documentCardBalance);

        $item = [
            'participant_id' => $participantId,
            'person_id' => $personId > 0 ? $personId : null,
            'name' => $holderName,
            'sector' => trim((string)($row['sector'] ?? '')),
            'role_id' => self::normalizePositiveInt($row['role_id'] ?? null),
            'event_role_id' => self::normalizePositiveInt($row['event_role_id'] ?? null),
            'status' => self::PREVIEW_ELIGIBLE,
            'existing_card_id' => null,
            'existing_card_balance' => 0.0,
            'reason_code' => null,
            'reason_message' => null,
            'holder_document_snapshot' => CardAssignmentService::normalizeDocument((string)($row['holder_document'] ?? '')),
        ];

        if ($participantId <= 0) {
            return self::buildErrorItem($item, 'invalid_participant', 'Participante inválido para emissão.');
        }

        if ($matchedCardCount > 1) {
            return self::buildErrorItem(
                array_merge($item, [
                    'existing_card_id' => $matchedCardId,
                    'existing_card_balance' => $matchedCardBalance,
                ]),
                'duplicate_active_assignments',
                'Há mais de um cartão ativo compatível com este participante no evento.'
            );
        }

        if ($activeCount === 1) {
            return self::buildSkippedPreviewItem(
                array_merge($item, [
                    'existing_card_id' => $activeCardId,
                    'existing_card_balance' => $activeCardBalance,
                ]),
                self::PREVIEW_ALREADY_HAS_ACTIVE_CARD,
                'already_has_active_card',
                $activeCardBalance > 0
                    ? sprintf(
                        'O participante já possui um cartão ativo neste evento com saldo de %s.',
                        self::formatMoney($activeCardBalance)
                    )
                    : 'O participante já possui um cartão ativo neste evento.'
            );
        }

        if ($legacyCount > 0) {
            return self::buildSkippedPreviewItem(
                array_merge($item, [
                    'existing_card_id' => $legacyCardId,
                    'existing_card_balance' => $legacyCardBalance,
                ]),
                self::PREVIEW_LEGACY_CONFLICT,
                'legacy_conflict_review_required',
                $legacyCardBalance > 0
                    ? sprintf(
                        'Existe vínculo legado ativo por pessoa neste evento com saldo de %s e a emissão exige revisão manual.',
                        self::formatMoney($legacyCardBalance)
                    )
                    : 'Existe vínculo legado ativo por pessoa neste evento e a emissão exige revisão manual.'
            );
        }

        if ($documentCount > 0) {
            return self::buildSkippedPreviewItem(
                array_merge($item, [
                    'existing_card_id' => $documentCardId,
                    'existing_card_balance' => $documentCardBalance,
                ]),
                self::PREVIEW_ALREADY_HAS_ACTIVE_CARD,
                'already_has_active_card_by_document',
                $documentCardBalance > 0
                    ? sprintf(
                        'O participante já possui um cartão ativo neste evento localizado pelo documento do titular, com saldo de %s.',
                        self::formatMoney($documentCardBalance)
                    )
                    : 'O participante já possui um cartão ativo neste evento localizado pelo documento do titular.'
            );
        }

        if ($personId <= 0 || $holderName === '') {
            return self::buildSkippedPreviewItem(
                $item,
                self::PREVIEW_MISSING_IDENTITY,
                'missing_identity',
                'O participante não possui identidade mínima consolidada para emitir o cartão.'
            );
        }

        return $item;
    }

    private static function buildOutOfScopeItem(int $participantId): array
    {
        return [
            'participant_id' => $participantId,
            'person_id' => null,
            'name' => '',
            'sector' => '',
            'role_id' => null,
            'event_role_id' => null,
            'status' => self::PREVIEW_OUT_OF_SCOPE,
            'existing_card_id' => null,
            'existing_card_balance' => 0.0,
            'reason_code' => 'out_of_scope',
            'reason_message' => 'O participante não pertence ao evento selecionado ou está fora do escopo do organizador.',
            'holder_document_snapshot' => '',
        ];
    }

    private static function buildSkippedPreviewItem(array $item, string $status, string $reasonCode, string $reasonMessage): array
    {
        $item['status'] = $status;
        $item['reason_code'] = $reasonCode;
        $item['reason_message'] = $reasonMessage;
        return $item;
    }

    private static function buildErrorItem(array $item, string $reasonCode, string $reasonMessage): array
    {
        $item['status'] = self::PREVIEW_ERROR;
        $item['reason_code'] = $reasonCode;
        $item['reason_message'] = $reasonMessage;
        return $item;
    }

    private static function buildPreviewSummary(array $items): array
    {
        $summary = [
            'requested_count' => count($items),
            'eligible_count' => 0,
            'already_has_active_card_count' => 0,
            'legacy_conflict_count' => 0,
            'missing_identity_count' => 0,
            'out_of_scope_count' => 0,
            'error_count' => 0,
            'existing_credit_count' => 0,
            'existing_credit_total' => 0.0,
        ];

        foreach ($items as $item) {
            $existingCardBalance = self::normalizeMoneyAmount($item['existing_card_balance'] ?? 0);
            switch ((string)($item['status'] ?? '')) {
                case self::PREVIEW_ELIGIBLE:
                    $summary['eligible_count']++;
                    break;
                case self::PREVIEW_ALREADY_HAS_ACTIVE_CARD:
                    $summary['already_has_active_card_count']++;
                    break;
                case self::PREVIEW_LEGACY_CONFLICT:
                    $summary['legacy_conflict_count']++;
                    break;
                case self::PREVIEW_MISSING_IDENTITY:
                    $summary['missing_identity_count']++;
                    break;
                case self::PREVIEW_OUT_OF_SCOPE:
                    $summary['out_of_scope_count']++;
                    break;
                default:
                    $summary['error_count']++;
                    break;
            }

            if (!empty($item['existing_card_id']) && $existingCardBalance > 0) {
                $summary['existing_credit_count']++;
                $summary['existing_credit_total'] += $existingCardBalance;
            }
        }

        $summary['existing_credit_total'] = round((float)$summary['existing_credit_total'], 2);
        return $summary;
    }

    private static function findExistingBatchId(PDO $db, int $organizerId, int $eventId, string $sourceModule, string $idempotencyKey): ?int
    {
        $stmt = $db->prepare("
            SELECT id
            FROM public.card_issue_batches
            WHERE organizer_id = ?
              AND event_id = ?
              AND source_module = ?
              AND idempotency_key = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$organizerId, $eventId, $sourceModule, $idempotencyKey]);

        $batchId = (int)$stmt->fetchColumn();
        return $batchId > 0 ? $batchId : null;
    }

    private static function createBatch(
        PDO $db,
        int $organizerId,
        int $eventId,
        string $sourceModule,
        int $createdByUserId,
        string $idempotencyKey,
        array $preview,
        array $context
    ): int {
        $stmt = $db->prepare("
            INSERT INTO public.card_issue_batches (
                organizer_id,
                event_id,
                source_module,
                source_context,
                requested_count,
                preview_eligible_count,
                issued_count,
                skipped_count,
                failed_count,
                created_by_user_id,
                idempotency_key,
                created_at,
                updated_at
            ) VALUES (
                ?,
                ?,
                ?,
                ?::jsonb,
                ?,
                ?,
                0,
                0,
                0,
                ?,
                ?,
                NOW(),
                NOW()
            )
            RETURNING id
        ");
        $stmt->execute([
            $organizerId,
            $eventId,
            $sourceModule,
            self::encodeSourceContext($context),
            (int)($preview['summary']['requested_count'] ?? 0),
            (int)($preview['summary']['eligible_count'] ?? 0),
            $createdByUserId,
            $idempotencyKey,
            ]);

        $batchId = (int)$stmt->fetchColumn();
        if ($batchId <= 0) {
            throw new RuntimeException('Falha ao criar o lote de emissão de cartões.', 500);
        }

        return $batchId;
    }

    private static function processEligibleItem(
        PDO $db,
        int $organizerId,
        int $eventId,
        int $batchId,
        int $issuedByUserId,
        array $item,
        string $sourceModule,
        array $context
    ): array {
        $participantId = (int)($item['participant_id'] ?? 0);
        $initialBalance = self::normalizeMoneyAmount($context['initial_balance'] ?? 0);
        if ($participantId <= 0) {
            throw new RuntimeException('Participante inválido para emissão.', 422);
        }

        $db->beginTransaction();

        try {
            self::lockParticipant($db, $eventId, $participantId);

            $currentMap = self::loadWorkforceParticipantContext($db, $organizerId, $eventId, [$participantId]);
            $current = $currentMap[$participantId] ?? null;
            if ($current === null) {
                throw new RuntimeException('O participante saiu do escopo do evento antes da emissão.', 409);
            }

            $revalidated = self::buildPreviewItemFromRow($current);
            if (($revalidated['status'] ?? '') !== self::PREVIEW_ELIGIBLE) {
                $db->commit();

                return [
                    'participant_id' => $participantId,
                    'person_id' => $revalidated['person_id'] ?? null,
                    'name' => $revalidated['name'] ?? '',
                    'sector' => $revalidated['sector'] ?? '',
                    'role_id' => $revalidated['role_id'] ?? null,
                    'event_role_id' => $revalidated['event_role_id'] ?? null,
                    'status' => self::ISSUE_SKIPPED,
                    'existing_card_id' => $revalidated['existing_card_id'] ?? null,
                    'existing_card_balance' => self::normalizeMoneyAmount($revalidated['existing_card_balance'] ?? 0),
                    'issued_card_id' => null,
                    'initial_credit_applied' => 0.0,
                    'reason_code' => $revalidated['reason_code'] ?? 'revalidation_blocked',
                    'reason_message' => $revalidated['reason_message'] ?? 'O participante deixou de ser elegível antes da emissão.',
                ];
            }

            $cardId = self::generateUuidV4();
            $matchedUserId = CardAssignmentService::resolveOrganizerUserIdByIdentity($db, $organizerId, [
                'person_id' => $revalidated['person_id'] ?? null,
                'document' => $revalidated['holder_document_snapshot'] ?? '',
            ]);
            $insertCard = $db->prepare("
                INSERT INTO public.digital_cards (id, user_id, balance, is_active, organizer_id, created_at, updated_at)
                VALUES (?::uuid, ?, 0, true, ?, NOW(), NOW())
            ");
            $insertCard->execute([$cardId, $matchedUserId, $organizerId]);

            CardAssignmentService::assignCardToEvent($db, $cardId, $organizerId, $eventId, [
                'participant_id' => $participantId,
                'person_id' => $revalidated['person_id'] ?? null,
                'sector' => $revalidated['sector'] ?? '',
                'source_module' => $sourceModule,
                'source_batch_id' => $batchId,
                'source_role_id' => $revalidated['role_id'] ?? null,
                'source_event_role_id' => $revalidated['event_role_id'] ?? null,
                'issued_by_user_id' => $issuedByUserId,
                'issued_at' => date('Y-m-d H:i:s'),
                'holder_name_snapshot' => $revalidated['name'] ?? '',
                'holder_document_snapshot' => $revalidated['holder_document_snapshot'] ?? '',
                'notes' => self::normalizeOptionalText($context['notes'] ?? null),
            ]);

            if ($initialBalance > 0) {
                WalletSecurityService::processTransaction(
                    $db,
                    $cardId,
                    $initialBalance,
                    'credit',
                    $organizerId,
                    [
                        'description' => 'Carga inicial na emissao em massa',
                        'event_id' => $eventId,
                        'user_id' => $issuedByUserId,
                        'payment_method' => 'workforce_bulk_issuance',
                    ]
                );
            }

            $db->commit();

            return [
                'participant_id' => $participantId,
                'person_id' => $revalidated['person_id'] ?? null,
                'name' => $revalidated['name'] ?? '',
                'sector' => $revalidated['sector'] ?? '',
                'role_id' => $revalidated['role_id'] ?? null,
                'event_role_id' => $revalidated['event_role_id'] ?? null,
                'status' => self::ISSUE_ISSUED,
                'existing_card_id' => null,
                'existing_card_balance' => 0.0,
                'issued_card_id' => $cardId,
                'initial_credit_applied' => $initialBalance,
                'reason_code' => null,
                'reason_message' => null,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function lockParticipant(PDO $db, int $eventId, int $participantId): void
    {
        $stmt = $db->prepare('SELECT pg_advisory_xact_lock(?, ?)');
        $stmt->execute([$eventId, $participantId]);
    }

    private static function insertBatchItem(
        PDO $db,
        int $batchId,
        array $item,
        string $issueStatus,
        ?string $existingCardId,
        ?string $issuedCardId
    ): void {
        $stmt = $db->prepare("
            INSERT INTO public.card_issue_batch_items (
                batch_id,
                participant_id,
                person_id,
                existing_card_id,
                issued_card_id,
                status,
                reason_code,
                reason_message,
                sector,
                source_role_id,
                source_event_role_id,
                created_at,
                updated_at
            ) VALUES (
                ?,
                ?,
                ?,
                ?::uuid,
                ?::uuid,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW(),
                NOW()
            )
        ");
        $stmt->execute([
            $batchId,
            self::normalizePositiveInt($item['participant_id'] ?? null),
            self::normalizePositiveInt($item['person_id'] ?? null),
            $existingCardId,
            $issuedCardId,
            $issueStatus,
            self::normalizeOptionalText($item['reason_code'] ?? null, 80),
            self::normalizeOptionalText($item['reason_message'] ?? null),
            self::normalizeOptionalText($item['sector'] ?? null, 50),
            self::normalizePositiveInt($item['role_id'] ?? null),
            self::normalizePositiveInt($item['event_role_id'] ?? null),
        ]);
    }

    private static function refreshBatchCounters(PDO $db, int $batchId, int $previewEligibleCount): void
    {
        $counts = [
            self::ISSUE_ISSUED => 0,
            self::ISSUE_SKIPPED => 0,
            self::ISSUE_FAILED => 0,
            'requested_count' => 0,
        ];

        $stmt = $db->prepare("
            SELECT status, COUNT(*)::int AS total
            FROM public.card_issue_batch_items
            WHERE batch_id = ?
            GROUP BY status
        ");
        $stmt->execute([$batchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            $total = (int)($row['total'] ?? 0);
            $counts['requested_count'] += $total;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $total;
            }
        }

        $update = $db->prepare("
            UPDATE public.card_issue_batches
            SET requested_count = ?,
                preview_eligible_count = ?,
                issued_count = ?,
                skipped_count = ?,
                failed_count = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([
            $counts['requested_count'],
            max(0, $previewEligibleCount),
            $counts[self::ISSUE_ISSUED],
            $counts[self::ISSUE_SKIPPED],
            $counts[self::ISSUE_FAILED],
            $batchId,
        ]);
    }

    private static function buildBatchResponse(PDO $db, int $batchId, bool $replayed): array
    {
        $batchStmt = $db->prepare("
            SELECT
                id,
                organizer_id,
                event_id,
                source_module,
                source_context,
                requested_count,
                preview_eligible_count,
                issued_count,
                skipped_count,
                failed_count,
                idempotency_key
            FROM public.card_issue_batches
            WHERE id = ?
            LIMIT 1
        ");
        $batchStmt->execute([$batchId]);
        $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            throw new RuntimeException('Lote de emissão não encontrado.', 404);
        }

        $batchContext = self::decodeSourceContext($batch['source_context'] ?? null);
        $initialBalance = self::normalizeMoneyAmount($batchContext['initial_balance'] ?? 0);

        $itemsStmt = $db->prepare("
            SELECT
                bi.participant_id,
                bi.person_id,
                COALESCE(NULLIF(TRIM(p.name), ''), '') AS name,
                COALESCE(NULLIF(TRIM(bi.sector), ''), '') AS sector,
                bi.source_role_id AS role_id,
                bi.source_event_role_id AS event_role_id,
                bi.status,
                bi.existing_card_id::text AS existing_card_id,
                COALESCE(existing_card.balance, 0)::numeric(10,2) AS existing_card_balance,
                bi.issued_card_id::text AS issued_card_id,
                bi.reason_code,
                bi.reason_message
            FROM public.card_issue_batch_items bi
            LEFT JOIN public.event_participants ep
              ON ep.id = bi.participant_id
            LEFT JOIN public.people p
              ON p.id = COALESCE(bi.person_id, ep.person_id)
            LEFT JOIN public.digital_cards existing_card
              ON existing_card.id = bi.existing_card_id
            WHERE bi.batch_id = ?
            ORDER BY bi.id ASC
        ");
        $itemsStmt->execute([$batchId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $normalizedItems = array_map(static function (array $row) use ($initialBalance): array {
            $status = (string)($row['status'] ?? '');
            return [
                'participant_id' => self::normalizePositiveInt($row['participant_id'] ?? null),
                'person_id' => self::normalizePositiveInt($row['person_id'] ?? null),
                'name' => (string)($row['name'] ?? ''),
                'sector' => (string)($row['sector'] ?? ''),
                'role_id' => self::normalizePositiveInt($row['role_id'] ?? null),
                'event_role_id' => self::normalizePositiveInt($row['event_role_id'] ?? null),
                'status' => $status,
                'existing_card_id' => self::normalizeUuid((string)($row['existing_card_id'] ?? '')),
                'existing_card_balance' => self::normalizeMoneyAmount($row['existing_card_balance'] ?? 0),
                'issued_card_id' => self::normalizeUuid((string)($row['issued_card_id'] ?? '')),
                'initial_credit_applied' => $status === self::ISSUE_ISSUED ? $initialBalance : 0.0,
                'reason_code' => self::normalizeOptionalText($row['reason_code'] ?? null, 80),
                'reason_message' => self::normalizeOptionalText($row['reason_message'] ?? null),
            ];
        }, $items);
        $existingCreditSummary = self::buildExistingCreditSummary($normalizedItems);

        return [
            'batch_id' => (int)$batch['id'],
            'event_id' => (int)$batch['event_id'],
            'source_module' => (string)$batch['source_module'],
            'idempotency_key' => (string)($batch['idempotency_key'] ?? ''),
            'replayed' => $replayed,
            'initial_balance' => $initialBalance,
            'summary' => [
                'requested_count' => (int)($batch['requested_count'] ?? count($items)),
                'preview_eligible_count' => (int)($batch['preview_eligible_count'] ?? 0),
                'issued_count' => (int)($batch['issued_count'] ?? 0),
                'skipped_count' => (int)($batch['skipped_count'] ?? 0),
                'failed_count' => (int)($batch['failed_count'] ?? 0),
                'estimated_initial_credit_total' => self::calculateTotalCredit(
                    (int)($batch['preview_eligible_count'] ?? 0),
                    $initialBalance
                ),
                'applied_initial_credit_total' => self::calculateTotalCredit(
                    (int)($batch['issued_count'] ?? 0),
                    $initialBalance
                ),
                'existing_credit_count' => $existingCreditSummary['existing_credit_count'],
                'existing_credit_total' => $existingCreditSummary['existing_credit_total'],
            ],
            'items' => $normalizedItems,
        ];
    }

    private static function buildExistingCreditSummary(array $items): array
    {
        $summary = [
            'existing_credit_count' => 0,
            'existing_credit_total' => 0.0,
        ];

        foreach ($items as $item) {
            $existingCardBalance = self::normalizeMoneyAmount($item['existing_card_balance'] ?? 0);
            if (empty($item['existing_card_id']) || $existingCardBalance <= 0) {
                continue;
            }

            $summary['existing_credit_count']++;
            $summary['existing_credit_total'] += $existingCardBalance;
        }

        $summary['existing_credit_total'] = round((float)$summary['existing_credit_total'], 2);
        return $summary;
    }

    private static function encodeSourceContext(array $context): ?string
    {
        unset($context['idempotency_key']);
        if (empty($context)) {
            return null;
        }

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : null;
    }

    private static function decodeSourceContext(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function normalizePositiveInt(mixed $value): ?int
    {
        $normalized = (int)($value ?? 0);
        return $normalized > 0 ? $normalized : null;
    }

    private static function normalizeMoneyAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            $amount = (float)$value;
        } else {
            $raw = trim((string)$value);
            if ($raw === '') {
                return 0.0;
            }

            $sanitized = preg_replace('/[^\d,\.\-]/', '', $raw) ?? '';
            if ($sanitized === '') {
                throw new RuntimeException('initial_balance inválido.', 422);
            }

            $lastComma = strrpos($sanitized, ',');
            $lastDot = strrpos($sanitized, '.');
            $decimalPos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

            if ($decimalPos >= 0) {
                $integerPart = preg_replace('/[^\d\-]/', '', substr($sanitized, 0, $decimalPos)) ?? '';
                $fractionPart = preg_replace('/\D/', '', substr($sanitized, $decimalPos + 1)) ?? '';
                $normalized = ($integerPart === '' || $integerPart === '-') ? '0' : $integerPart;
                $amount = (float)($normalized . '.' . $fractionPart);
            } else {
                $normalized = preg_replace('/[^\d\-]/', '', $sanitized) ?? '';
                if ($normalized === '' || $normalized === '-') {
                    throw new RuntimeException('initial_balance inválido.', 422);
                }
                $amount = (float)$normalized;
            }
        }

        if (!is_finite($amount) || $amount < 0) {
            throw new RuntimeException('initial_balance inválido.', 422);
        }

        return round($amount, 2);
    }

    private static function formatMoney(float $amount): string
    {
        return 'R$ ' . number_format(max(0, $amount), 2, ',', '.');
    }

    private static function calculateTotalCredit(int $count, float $initialBalance): float
    {
        return round(max(0, $count) * max(0, $initialBalance), 2);
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

    private static function normalizeUuid(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
