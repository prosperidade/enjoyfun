<?php

require_once __DIR__ . '/WorkforceControllerSupport.php';
require_once BASE_PATH . '/src/Services/CardIssuanceService.php';

function previewCardIssuance(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    ensureWorkforceBulkCardIssuanceEnabled();

    $organizerId = resolveOrganizerId($user);
    $eventId = (int)($body['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para gerar o preview da emissão de cartões.', 422);
    }

    $participantIds = resolveCardIssuanceParticipantIds($body);
    if (empty($participantIds)) {
        jsonError('participant_ids deve conter ao menos um participante válido.', 422);
    }

    $initialBalance = 0.0;

    try {
        $db = Database::getInstance();
        if (function_exists('setCurrentRequestEventId')) {
            setCurrentRequestEventId($eventId);
        }

        $initialBalance = normalizeCardIssuanceMoney($body['initial_balance'] ?? 0);

        $preview = CardIssuanceService::previewWorkforceParticipants(
            $db,
            $organizerId,
            $eventId,
            $participantIds,
            [
                'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
                'manager_event_role_id' => normalizeCardIssuanceOptionalInt($body['manager_event_role_id'] ?? null),
                'source_context' => is_array($body['source_context'] ?? null) ? $body['source_context'] : null,
                'initial_balance' => $initialBalance,
                'notes' => trim((string)($body['notes'] ?? '')),
            ]
        );

        auditCardIssuancePreview($user, $eventId, $participantIds, $preview, [
            'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
            'manager_event_role_id' => normalizeCardIssuanceOptionalInt($body['manager_event_role_id'] ?? null),
            'initial_balance' => $initialBalance,
        ]);
        jsonSuccess($preview, 'Preview de emissão de cartões gerado com sucesso.');
    } catch (RuntimeException $e) {
        auditCardIssuanceFailure($user, 'preview', $eventId, $participantIds, $e, [
            'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
            'initial_balance' => $initialBalance,
        ]);
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    } catch (Throwable $e) {
        auditCardIssuanceFailure($user, 'preview', $eventId, $participantIds, $e, [
            'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
            'initial_balance' => $initialBalance,
        ]);
        jsonError('Erro ao gerar preview da emissão de cartões: ' . $e->getMessage(), 500);
    }
}

function issueCardsInBulk(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    ensureWorkforceBulkCardIssuanceEnabled();

    $organizerId = resolveOrganizerId($user);
    $issuedByUserId = (int)($user['id'] ?? 0);
    $eventId = (int)($body['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para emitir cartões em massa.', 422);
    }

    $participantIds = resolveCardIssuanceParticipantIds($body);
    if (empty($participantIds)) {
        jsonError('participant_ids deve conter ao menos um participante válido.', 422);
    }

    $idempotencyKey = trim((string)($body['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
        jsonError('idempotency_key é obrigatório para emitir cartões em massa.', 422);
    }

    $initialBalance = 0.0;

    try {
        $db = Database::getInstance();
        if (function_exists('setCurrentRequestEventId')) {
            setCurrentRequestEventId($eventId);
        }

        $initialBalance = normalizeCardIssuanceMoney($body['initial_balance'] ?? 0);

        $result = CardIssuanceService::issueWorkforceParticipants(
            $db,
            $organizerId,
            $eventId,
            $participantIds,
            $issuedByUserId,
            [
                'idempotency_key' => $idempotencyKey,
                'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
                'manager_event_role_id' => normalizeCardIssuanceOptionalInt($body['manager_event_role_id'] ?? null),
                'source_context' => is_array($body['source_context'] ?? null) ? $body['source_context'] : null,
                'initial_balance' => $initialBalance,
                'notes' => trim((string)($body['notes'] ?? '')),
            ]
        );

        auditCardIssuanceIssue($user, $eventId, $participantIds, $result, [
            'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
            'manager_event_role_id' => normalizeCardIssuanceOptionalInt($body['manager_event_role_id'] ?? null),
            'initial_balance' => $initialBalance,
        ]);
        jsonSuccess($result, !empty($result['replayed']) ? 'Lote de emissão reaproveitado por idempotência.' : 'Lote de emissão de cartões processado com sucesso.');
    } catch (RuntimeException $e) {
        auditCardIssuanceFailure($user, 'issue', $eventId, $participantIds, $e, [
            'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
            'idempotency_key' => $idempotencyKey,
            'initial_balance' => $initialBalance,
        ]);
        jsonError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    } catch (Throwable $e) {
        auditCardIssuanceFailure($user, 'issue', $eventId, $participantIds, $e, [
            'source_module' => normalizeCardIssuanceSourceModule($body['source_module'] ?? null),
            'idempotency_key' => $idempotencyKey,
            'initial_balance' => $initialBalance,
        ]);
        jsonError('Erro ao emitir cartões em massa: ' . $e->getMessage(), 500);
    }
}

function auditCardIssuancePreview(array $user, int $eventId, array $participantIds, array $preview, array $context = []): void
{
    if (!class_exists('AuditService')) {
        return;
    }

    AuditService::log(
        defined('AuditService::WORKFORCE_CARD_ISSUANCE_PREVIEW')
            ? AuditService::WORKFORCE_CARD_ISSUANCE_PREVIEW
            : 'workforce.card_issuance.preview',
        'card_issue_preview',
        $eventId > 0 ? $eventId : 'workforce-preview',
        null,
        [
            'summary' => $preview['summary'] ?? [],
            'can_issue' => !empty($preview['can_issue']),
            'source_module' => (string)($preview['source_module'] ?? CardIssuanceService::SOURCE_WORKFORCE_BULK),
            'initial_balance' => (float)($preview['initial_balance'] ?? 0),
        ],
        $user,
        'success',
        [
            'event_id' => $eventId > 0 ? $eventId : null,
            'metadata' => [
                'participant_count' => count($participantIds),
                'participant_ids_sample' => cardIssuanceParticipantIdSample($participantIds),
                'manager_event_role_id' => normalizeCardIssuanceOptionalInt($context['manager_event_role_id'] ?? null),
                'source_module' => (string)($context['source_module'] ?? CardIssuanceService::SOURCE_WORKFORCE_BULK),
                'initial_balance' => (float)($preview['initial_balance'] ?? $context['initial_balance'] ?? 0),
            ],
        ]
    );
}

function auditCardIssuanceIssue(array $user, int $eventId, array $participantIds, array $result, array $context = []): void
{
    if (!class_exists('AuditService')) {
        return;
    }

    $batchId = (int)($result['batch_id'] ?? 0);
    $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
    $replayed = !empty($result['replayed']);
    $sourceModule = (string)($result['source_module'] ?? $context['source_module'] ?? CardIssuanceService::SOURCE_WORKFORCE_BULK);

    AuditService::log(
        defined('AuditService::WORKFORCE_CARD_ISSUANCE_BATCH')
            ? AuditService::WORKFORCE_CARD_ISSUANCE_BATCH
            : 'workforce.card_issuance.batch',
        'card_issue_batch',
        $batchId > 0 ? $batchId : ($eventId > 0 ? $eventId : 'workforce-batch'),
        null,
        [
            'summary' => $summary,
            'replayed' => $replayed,
            'source_module' => $sourceModule,
            'idempotency_key' => (string)($result['idempotency_key'] ?? ''),
            'initial_balance' => (float)($result['initial_balance'] ?? $context['initial_balance'] ?? 0),
        ],
        $user,
        'success',
        [
            'event_id' => $eventId > 0 ? $eventId : null,
            'metadata' => [
                'participant_count' => count($participantIds),
                'participant_ids_sample' => cardIssuanceParticipantIdSample($participantIds),
                'manager_event_role_id' => normalizeCardIssuanceOptionalInt($context['manager_event_role_id'] ?? null),
                'source_module' => $sourceModule,
                'replayed' => $replayed,
                'initial_balance' => (float)($result['initial_balance'] ?? $context['initial_balance'] ?? 0),
            ],
        ]
    );

    if ($replayed) {
        return;
    }

    $items = is_array($result['items'] ?? null) ? $result['items'] : [];
    foreach ($items as $item) {
        $participantId = normalizeCardIssuanceOptionalInt($item['participant_id'] ?? null);
        $status = (string)($item['status'] ?? '');
        $itemResult = $status === CardIssuanceService::ISSUE_FAILED ? 'failure' : 'success';
        $entityId = $batchId > 0 && $participantId !== null
            ? $batchId . ':' . $participantId
            : ($participantId !== null ? (string)$participantId : ($batchId > 0 ? (string)$batchId : 'card-issue-item'));

        AuditService::log(
            defined('AuditService::WORKFORCE_CARD_ISSUANCE_ITEM')
                ? AuditService::WORKFORCE_CARD_ISSUANCE_ITEM
                : 'workforce.card_issuance.item',
            'card_issue_item',
            $entityId,
            [
                'existing_card_id' => $item['existing_card_id'] ?? null,
            ],
            [
                'participant_id' => $participantId,
                'status' => $status,
                'issued_card_id' => $item['issued_card_id'] ?? null,
                'initial_credit_applied' => (float)($item['initial_credit_applied'] ?? 0),
                'reason_code' => $item['reason_code'] ?? null,
                'reason_message' => $item['reason_message'] ?? null,
            ],
            $user,
            $itemResult,
            [
                'event_id' => $eventId > 0 ? $eventId : null,
                'metadata' => [
                    'batch_id' => $batchId > 0 ? $batchId : null,
                    'participant_id' => $participantId,
                    'person_id' => normalizeCardIssuanceOptionalInt($item['person_id'] ?? null),
                    'role_id' => normalizeCardIssuanceOptionalInt($item['role_id'] ?? null),
                    'event_role_id' => normalizeCardIssuanceOptionalInt($item['event_role_id'] ?? null),
                    'sector' => trim((string)($item['sector'] ?? '')),
                    'source_module' => $sourceModule,
                    'initial_credit_applied' => (float)($item['initial_credit_applied'] ?? 0),
                ],
            ]
        );
    }
}

function auditCardIssuanceFailure(array $user, string $phase, int $eventId, array $participantIds, Throwable $error, array $context = []): void
{
    if (!class_exists('AuditService')) {
        return;
    }

    $normalizedPhase = $phase === 'issue' ? 'issue' : 'preview';
    $action = $normalizedPhase === 'issue'
        ? (defined('AuditService::WORKFORCE_CARD_ISSUANCE_BATCH') ? AuditService::WORKFORCE_CARD_ISSUANCE_BATCH : 'workforce.card_issuance.batch')
        : (defined('AuditService::WORKFORCE_CARD_ISSUANCE_PREVIEW') ? AuditService::WORKFORCE_CARD_ISSUANCE_PREVIEW : 'workforce.card_issuance.preview');

    AuditService::logFailure(
        $action,
        $normalizedPhase === 'issue' ? 'card_issue_batch' : 'card_issue_preview',
        $eventId > 0 ? $eventId : ('workforce-' . $normalizedPhase),
        $error->getMessage(),
        $user,
        [
            'event_id' => $eventId > 0 ? $eventId : null,
            'metadata' => [
                'phase' => $normalizedPhase,
                'participant_count' => count($participantIds),
                'participant_ids_sample' => cardIssuanceParticipantIdSample($participantIds),
                'source_module' => (string)($context['source_module'] ?? CardIssuanceService::SOURCE_WORKFORCE_BULK),
                'idempotency_key' => $normalizedPhase === 'issue' ? (string)($context['idempotency_key'] ?? '') : null,
                'initial_balance' => (float)($context['initial_balance'] ?? 0),
            ],
        ]
    );
}

function cardIssuanceParticipantIdSample(array $participantIds, int $limit = 20): array
{
    $normalized = [];
    foreach ($participantIds as $participantId) {
        $id = (int)$participantId;
        if ($id > 0) {
            $normalized[$id] = $id;
        }
    }

    return array_slice(array_values($normalized), 0, max(1, $limit));
}
