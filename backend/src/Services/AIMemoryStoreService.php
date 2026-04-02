<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/AuditService.php';

final class AIMemoryStoreService
{
    public static function recordMemory(PDO $db, array $payload): ?int
    {
        try {
            if (!self::tableExists($db, 'ai_agent_memories')) {
                self::handlePersistenceFailure(
                    new RuntimeException('Tabela public.ai_agent_memories ausente.'),
                    $payload,
                    'ai.memory.store_unavailable'
                );
                return null;
            }

            $organizerId = (int)($payload['organizer_id'] ?? 0);
            if ($organizerId <= 0) {
                return null;
            }

            $summary = self::nullableText($payload['summary'] ?? null, 2000);
            if ($summary === null) {
                return null;
            }

            $stmt = $db->prepare('
                INSERT INTO public.ai_agent_memories (
                    organizer_id,
                    event_id,
                    agent_key,
                    surface,
                    memory_type,
                    title,
                    summary,
                    content,
                    importance,
                    source_entrypoint,
                    source_execution_id,
                    tags_json,
                    metadata_json,
                    created_at,
                    updated_at
                ) VALUES (
                    :organizer_id,
                    :event_id,
                    :agent_key,
                    :surface,
                    :memory_type,
                    :title,
                    :summary,
                    :content,
                    :importance,
                    :source_entrypoint,
                    :source_execution_id,
                    :tags_json,
                    :metadata_json,
                    NOW(),
                    NOW()
                )
                RETURNING id
            ');
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':event_id' => self::nullablePositiveInt($payload['event_id'] ?? null),
                ':agent_key' => self::nullableText($payload['agent_key'] ?? null, 100),
                ':surface' => self::nullableText($payload['surface'] ?? null, 100),
                ':memory_type' => self::nullableText($payload['memory_type'] ?? null, 50) ?? 'execution_summary',
                ':title' => self::nullableText($payload['title'] ?? null, 180),
                ':summary' => $summary,
                ':content' => self::nullableText($payload['content'] ?? null, 8000),
                ':importance' => self::normalizeImportance($payload['importance'] ?? null),
                ':source_entrypoint' => self::nullableText($payload['source_entrypoint'] ?? null, 100),
                ':source_execution_id' => self::nullablePositiveInt($payload['source_execution_id'] ?? null),
                ':tags_json' => self::encodeJsonArray($payload['tags'] ?? []),
                ':metadata_json' => self::encodeJsonObject($payload['metadata'] ?? []),
            ]);
            $memoryId = (int)$stmt->fetchColumn();
            return $memoryId > 0 ? $memoryId : null;
        } catch (\Throwable $e) {
            self::handlePersistenceFailure($e, $payload, 'ai.memory.persist_failed');
            return null;
        }
    }

    public static function listMemories(PDO $db, int $organizerId, array $filters = []): array
    {
        if ($organizerId <= 0 || !self::tableExists($db, 'ai_agent_memories')) {
            return [];
        }

        $where = ['organizer_id = :organizer_id'];
        $params = [':organizer_id' => $organizerId];

        $eventId = self::nullablePositiveInt($filters['event_id'] ?? null);
        if ($eventId !== null) {
            $where[] = 'event_id = :event_id';
            $params[':event_id'] = $eventId;
        }

        $agentKey = self::nullableText($filters['agent_key'] ?? null, 100);
        if ($agentKey !== null) {
            $where[] = 'agent_key = :agent_key';
            $params[':agent_key'] = strtolower($agentKey);
        }

        $limit = self::normalizeLimit($filters['limit'] ?? null, 12, 50);

        $stmt = $db->prepare('
            SELECT
                id,
                organizer_id,
                event_id,
                agent_key,
                surface,
                memory_type,
                title,
                summary,
                content,
                importance,
                source_entrypoint,
                source_execution_id,
                tags_json,
                metadata_json,
                created_at,
                updated_at
            FROM public.ai_agent_memories
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY created_at DESC
            LIMIT :limit
        ');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            $tags = json_decode((string)($row['tags_json'] ?? '[]'), true);
            $metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true);

            return [
                'id' => (int)($row['id'] ?? 0),
                'organizer_id' => (int)($row['organizer_id'] ?? 0),
                'event_id' => isset($row['event_id']) ? (int)$row['event_id'] : null,
                'agent_key' => $row['agent_key'] ?? null,
                'surface' => $row['surface'] ?? null,
                'memory_type' => $row['memory_type'] ?? 'execution_summary',
                'title' => $row['title'] ?? null,
                'summary' => $row['summary'] ?? '',
                'content' => $row['content'] ?? null,
                'importance' => (int)($row['importance'] ?? 3),
                'source_entrypoint' => $row['source_entrypoint'] ?? null,
                'source_execution_id' => isset($row['source_execution_id']) ? (int)$row['source_execution_id'] : null,
                'tags' => is_array($tags) ? $tags : [],
                'metadata' => is_array($metadata) ? $metadata : [],
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $rows);
    }

    public static function queueEndOfEventReport(PDO $db, int $organizerId, int $eventId, array $payload = []): ?array
    {
        if ($organizerId <= 0 || $eventId <= 0 || !self::tableExists($db, 'ai_event_reports')) {
            return null;
        }

        $existing = self::findOpenEndOfEventReport($db, $organizerId, $eventId);
        if ($existing !== null) {
            return $existing;
        }

        $blueprint = AIPromptCatalogService::getEndOfEventReportBlueprint();
        $title = self::nullableText($payload['title'] ?? null, 180)
            ?? 'Raio X final do evento';
        $automationSource = self::nullableText($payload['automation_source'] ?? null, 60)
            ?? 'manual';
        $summary = self::nullableText($payload['summary_markdown'] ?? null, 4000)
            ?? 'Relatorio enfileirado para consolidar aprendizados finais do evento por todos os agentes habilitados.';

        $stmt = $db->prepare('
            INSERT INTO public.ai_event_reports (
                organizer_id,
                event_id,
                report_type,
                report_status,
                automation_source,
                title,
                summary_markdown,
                report_payload_json,
                generated_by_user_id,
                generated_at
            ) VALUES (
                :organizer_id,
                :event_id,
                :report_type,
                :report_status,
                :automation_source,
                :title,
                :summary_markdown,
                :report_payload_json,
                :generated_by_user_id,
                NOW()
            )
            RETURNING id
        ');
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':event_id' => $eventId,
            ':report_type' => 'end_of_event',
            ':report_status' => 'queued',
            ':automation_source' => $automationSource,
            ':title' => $title,
            ':summary_markdown' => $summary,
            ':report_payload_json' => self::encodeJsonObject([
                'blueprint' => $blueprint,
                'event_snapshot' => $payload['event_snapshot'] ?? [],
                'requested_by' => $payload['requested_by'] ?? null,
            ]),
            ':generated_by_user_id' => self::nullablePositiveInt($payload['generated_by_user_id'] ?? null),
        ]);
        $reportId = (int)$stmt->fetchColumn();
        if ($reportId <= 0) {
            return null;
        }

        foreach ((array)($blueprint['sections'] ?? []) as $section) {
            $sectionStmt = $db->prepare('
                INSERT INTO public.ai_event_report_sections (
                    report_id,
                    organizer_id,
                    event_id,
                    agent_key,
                    section_key,
                    section_title,
                    section_status,
                    section_payload_json,
                    created_at,
                    updated_at
                ) VALUES (
                    :report_id,
                    :organizer_id,
                    :event_id,
                    :agent_key,
                    :section_key,
                    :section_title,
                    :section_status,
                    :section_payload_json,
                    NOW(),
                    NOW()
                )
            ');
            $sectionStmt->execute([
                ':report_id' => $reportId,
                ':organizer_id' => $organizerId,
                ':event_id' => $eventId,
                ':agent_key' => self::nullableText($section['agent_key'] ?? null, 100),
                ':section_key' => self::nullableText($section['section_key'] ?? null, 100) ?? 'section',
                ':section_title' => self::nullableText($section['section_title'] ?? null, 180) ?? 'Secao',
                ':section_status' => 'pending',
                ':section_payload_json' => self::encodeJsonObject($section),
            ]);
        }

        $report = self::getReportById($db, $organizerId, $reportId);
        self::writeQueuedReportAudit($payload, $organizerId, $eventId, $reportId, $blueprint);

        return $report;
    }

    public static function listReports(PDO $db, int $organizerId, array $filters = []): array
    {
        if ($organizerId <= 0 || !self::tableExists($db, 'ai_event_reports')) {
            return [];
        }

        $where = ['organizer_id = :organizer_id'];
        $params = [':organizer_id' => $organizerId];
        $eventId = self::nullablePositiveInt($filters['event_id'] ?? null);
        if ($eventId !== null) {
            $where[] = 'event_id = :event_id';
            $params[':event_id'] = $eventId;
        }

        $limit = self::normalizeLimit($filters['limit'] ?? null, 10, 50);

        $stmt = $db->prepare('
            SELECT
                id,
                organizer_id,
                event_id,
                report_type,
                report_status,
                automation_source,
                title,
                summary_markdown,
                report_payload_json,
                generated_by_user_id,
                generated_at,
                completed_at
            FROM public.ai_event_reports
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY generated_at DESC
            LIMIT :limit
        ');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn(array $row): array => self::hydrateReportRow($db, $row), $rows);
    }

    public static function getBlueprintPayload(): array
    {
        return [
            'layers' => [
                [
                    'key' => 'context-builders',
                    'label' => 'Context builders por superficie',
                    'status' => 'active',
                    'description' => 'Transforma o estado da tela/evento em contexto consistente para o orquestrador.',
                ],
                [
                    'key' => 'prompt-catalog',
                    'label' => 'Prompt catalog versionado no codigo',
                    'status' => 'active',
                    'description' => 'Prompts base do produto e dos agentes versionados, com override do organizer por agente.',
                ],
                [
                    'key' => 'memory-store',
                    'label' => 'Memory store em tabelas proprias',
                    'status' => 'active',
                    'description' => 'Memorias persistidas, relatorios de fim de evento e material vivo de aprendizado do organizer.',
                ],
            ],
            'surface_blueprints' => AIContextBuilderService::listSurfaceBlueprints(),
            'prompt_catalog' => AIPromptCatalogService::listCatalog(),
            'end_of_event_report' => AIPromptCatalogService::getEndOfEventReportBlueprint(),
        ];
    }

    private static function getReportById(PDO $db, int $organizerId, int $reportId): ?array
    {
        $stmt = $db->prepare('
            SELECT
                id,
                organizer_id,
                event_id,
                report_type,
                report_status,
                automation_source,
                title,
                summary_markdown,
                report_payload_json,
                generated_by_user_id,
                generated_at,
                completed_at
            FROM public.ai_event_reports
            WHERE organizer_id = :organizer_id
              AND id = :id
            LIMIT 1
        ');
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':id' => $reportId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateReportRow($db, $row) : null;
    }

    private static function hydrateReportRow(PDO $db, array $row): array
    {
        $sectionsStmt = $db->prepare('
            SELECT id, agent_key, section_key, section_title, section_status, content_markdown, section_payload_json, created_at, updated_at
            FROM public.ai_event_report_sections
            WHERE report_id = :report_id
            ORDER BY id ASC
        ');
        $sectionsStmt->execute([':report_id' => (int)($row['id'] ?? 0)]);
        $sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $payload = json_decode((string)($row['report_payload_json'] ?? '{}'), true);

        return [
            'id' => (int)($row['id'] ?? 0),
            'organizer_id' => (int)($row['organizer_id'] ?? 0),
            'event_id' => (int)($row['event_id'] ?? 0),
            'report_type' => $row['report_type'] ?? 'end_of_event',
            'report_status' => $row['report_status'] ?? 'queued',
            'automation_source' => $row['automation_source'] ?? 'manual',
            'title' => $row['title'] ?? null,
            'summary_markdown' => $row['summary_markdown'] ?? null,
            'generated_by_user_id' => isset($row['generated_by_user_id']) ? (int)$row['generated_by_user_id'] : null,
            'generated_at' => $row['generated_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'payload' => is_array($payload) ? $payload : [],
            'sections' => array_map(static function (array $section): array {
                $sectionPayload = json_decode((string)($section['section_payload_json'] ?? '{}'), true);
                return [
                    'id' => (int)($section['id'] ?? 0),
                    'agent_key' => $section['agent_key'] ?? null,
                    'section_key' => $section['section_key'] ?? null,
                    'section_title' => $section['section_title'] ?? null,
                    'section_status' => $section['section_status'] ?? 'pending',
                    'content_markdown' => $section['content_markdown'] ?? null,
                    'payload' => is_array($sectionPayload) ? $sectionPayload : [],
                    'created_at' => $section['created_at'] ?? null,
                    'updated_at' => $section['updated_at'] ?? null,
                ];
            }, $sections),
        ];
    }

    private static function findOpenEndOfEventReport(PDO $db, int $organizerId, int $eventId): ?array
    {
        $stmt = $db->prepare("
            SELECT
                id,
                organizer_id,
                event_id,
                report_type,
                report_status,
                automation_source,
                title,
                summary_markdown,
                report_payload_json,
                generated_by_user_id,
                generated_at,
                completed_at
            FROM public.ai_event_reports
            WHERE organizer_id = :organizer_id
              AND event_id = :event_id
              AND report_type = 'end_of_event'
              AND report_status IN ('queued', 'running', 'ready')
            ORDER BY generated_at DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':event_id' => $eventId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::hydrateReportRow($db, $row) : null;
    }

    private static function tableExists(PDO $db, string $tableName): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute([':table_name' => $tableName]);
        return (bool)$stmt->fetchColumn();
    }

    private static function normalizeLimit(mixed $value, int $default, int $max): int
    {
        $limit = (int)$value;
        if ($limit <= 0) {
            $limit = $default;
        }

        return min($limit, $max);
    }

    private static function normalizeImportance(mixed $value): int
    {
        $importance = (int)$value;
        if ($importance < 1) {
            return 3;
        }

        return min($importance, 5);
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)$value;
        return $normalized > 0 ? $normalized : null;
    }

    private static function nullableText(mixed $value, int $maxLength): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
        if ($normalized === '') {
            return null;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($normalized) > $maxLength) {
            return rtrim(mb_substr($normalized, 0, max(0, $maxLength - 1))) . '…';
        }

        if (strlen($normalized) > $maxLength) {
            return rtrim(substr($normalized, 0, max(0, $maxLength - 3))) . '...';
        }

        return $normalized;
    }

    private static function encodeJsonArray(mixed $value): string
    {
        $payload = is_array($value) ? array_values($value) : [];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '[]';
    }

    private static function encodeJsonObject(mixed $value): string
    {
        $payload = is_array($value) ? $value : [];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    private static function handlePersistenceFailure(\Throwable $e, array $payload, string $eventName): void
    {
        self::emitPersistenceLog($eventName, [
            'organizer_id' => (int)($payload['organizer_id'] ?? 0),
            'event_id' => self::nullablePositiveInt($payload['event_id'] ?? null),
            'surface' => self::nullableText($payload['surface'] ?? null, 100),
            'agent_key' => self::nullableText($payload['agent_key'] ?? null, 100),
            'memory_type' => self::nullableText($payload['memory_type'] ?? null, 50),
            'source_execution_id' => self::nullablePositiveInt($payload['source_execution_id'] ?? null),
            'message' => self::nullableText($e->getMessage(), 400),
        ]);

        if (self::isAuditStrictModeEnabled()) {
            throw new RuntimeException('Falha ao persistir memoria da IA.', 0, $e);
        }
    }

    private static function emitPersistenceLog(string $eventName, array $payload): void
    {
        $context = ['event' => $eventName];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $context[$key] = $value;
        }

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log(is_string($encoded) ? $encoded : $eventName);
    }

    private static function isAuditStrictModeEnabled(): bool
    {
        $raw = strtolower(trim((string)(getenv('AI_AUDIT_STRICT') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function writeQueuedReportAudit(array $payload, int $organizerId, int $eventId, int $reportId, array $blueprint): void
    {
        if (!class_exists('\AuditService')) {
            return;
        }

        $automationSource = self::nullableText($payload['automation_source'] ?? null, 60) ?? 'manual';
        $auditUser = is_array($payload['audit_user'] ?? null)
            ? $payload['audit_user']
            : [
                'id' => self::nullablePositiveInt($payload['generated_by_user_id'] ?? null),
                'email' => self::nullableText($payload['requested_by'] ?? null, 255),
                'organizer_id' => $organizerId,
            ];

        \AuditService::log(
            \AuditService::AI_REPORT_QUEUED,
            'ai_report',
            $reportId,
            null,
            [
                'report_type' => 'end_of_event',
                'report_status' => 'queued',
                'automation_source' => $automationSource,
            ],
            $auditUser,
            'success',
            [
                'event_id' => $eventId,
                'organizer_id' => $organizerId,
                'entrypoint' => 'ai/reports/end-of-event',
                'metadata' => array_filter([
                    'automation_source' => $automationSource,
                    'requested_by' => self::nullableText($payload['requested_by'] ?? null, 255),
                    'generated_by_user_id' => self::nullablePositiveInt($payload['generated_by_user_id'] ?? null),
                    'section_count' => count((array)($blueprint['sections'] ?? [])),
                ], static fn(mixed $value): bool => $value !== null && $value !== ''),
                'actor' => self::resolveReportAuditActor($payload, $automationSource),
            ]
        );
    }

    private static function resolveReportAuditActor(array $payload, string $automationSource): array
    {
        if (is_array($payload['audit_actor'] ?? null)) {
            return $payload['audit_actor'];
        }

        if ($automationSource !== 'manual') {
            return [
                'type' => 'system',
                'id' => 'ai.end_of_event_report',
                'origin' => 'events.lifecycle',
            ];
        }

        return [
            'type' => 'human',
            'id' => isset($payload['generated_by_user_id']) ? (string)(int)$payload['generated_by_user_id'] : null,
            'origin' => 'http.ai_reports',
        ];
    }
}
