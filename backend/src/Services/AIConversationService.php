<?php
/**
 * AIConversationService.php
 * Manages conversation sessions for multi-turn AI interactions.
 * Gated by FEATURE_AI_CHAT.
 */

namespace EnjoyFun\Services;

use PDO;

final class AIConversationService
{
    private const MAX_MESSAGES_PER_SESSION = 100;
    private const SESSION_EXPIRY_HOURS = 24;
    /** Idle timeout in minutes: sessions inactive longer than this get auto-archived
     *  on the next findOrCreateSession call, forcing a fresh conversation slate.
     *  Fix for Bug H: prevents cross-topic contamination from stale history. */
    private const SESSION_IDLE_TIMEOUT_MINUTES = 10;

    // ──────────────────────────────────────────────────────────────
    //  Session lifecycle
    // ──────────────────────────────────────────────────────────────

    /**
     * EMAS composite key: "{organizer_id}:{event_id|null}:{surface}:{agent_scope}".
     * Resolved server-side; clients never construct this.
     * ADR: docs/adr_emas_architecture_v1.md (decisão 1).
     */
    public static function buildSessionKey(
        int $organizerId,
        ?int $eventId,
        string $surface,
        string $agentScope
    ): string {
        $surface = $surface !== '' ? $surface : 'unknown';
        $agentScope = $agentScope !== '' ? $agentScope : 'auto';
        $event = $eventId !== null && $eventId > 0 ? (string)$eventId : 'null';
        return $organizerId . ':' . $event . ':' . $surface . ':' . $agentScope;
    }

    /**
     * EMAS BE-S1-A1: find an active session matching the composite key, or create a new one.
     * Auto-archives any other active session for the same (organizer, user, surface)
     * with a different key — guaranteeing at most one active session per surface per user.
     * Idempotent: same key on repeated calls returns the same session id.
     */
    public static function findOrCreateSession(
        PDO $db,
        int $organizerId,
        int $userId,
        ?int $eventId,
        string $surface,
        string $conversationMode,
        string $agentScope,
        array $context = []
    ): array {
        $sessionKey = self::buildSessionKey($organizerId, $eventId, $surface, $agentScope);

        // 1. Auto-archive stale active sessions for the same (organizer, user, surface)
        //    that have a different key. Switching event/agent within the same surface
        //    is treated as a new session — the previous one is closed cleanly.
        $stmt = $db->prepare(
            'UPDATE ai_conversation_sessions
                SET status = \'archived\', updated_at = NOW()
              WHERE organizer_id = :org_id
                AND user_id = :user_id
                AND surface = :surface
                AND status = \'active\'
                AND (session_key IS DISTINCT FROM :session_key)'
        );
        $stmt->execute([
            'org_id'      => $organizerId,
            'user_id'     => $userId,
            'surface'     => $surface,
            'session_key' => $sessionKey,
        ]);

        // 2. Try to reuse an existing active session with the exact key.
        $stmt = $db->prepare(
            'SELECT id, organizer_id, event_id, user_id, surface, conversation_mode,
                    session_key, routed_agent_key, routing_trace_id, status,
                    context_json, metadata_json, created_at, updated_at, expires_at
               FROM ai_conversation_sessions
              WHERE session_key = :session_key
                AND organizer_id = :org_id
                AND status = \'active\'
                AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([
            'session_key' => $sessionKey,
            'org_id'      => $organizerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Bug H fix: if the session has been idle longer than the timeout,
            // archive it and fall through to create a fresh one. This prevents
            // cross-topic contamination from stale conversation history.
            $updatedAt = strtotime($row['updated_at'] ?? $row['created_at'] ?? 'now');
            $idleMinutes = (time() - $updatedAt) / 60;

            if ($idleMinutes > self::SESSION_IDLE_TIMEOUT_MINUTES) {
                $archiveStmt = $db->prepare(
                    'UPDATE ai_conversation_sessions
                        SET status = \'archived\', updated_at = NOW()
                      WHERE id = :id AND organizer_id = :org_id AND status = \'active\''
                );
                $archiveStmt->execute(['id' => $row['id'], 'org_id' => $organizerId]);
                // Fall through to step 3 — create a new session.
            } else {
                $row['context_json'] = json_decode($row['context_json'] ?: '{}', true);
                $row['metadata_json'] = json_decode($row['metadata_json'] ?: '{}', true);
                $row['_created'] = false;
                return $row;
            }
        }

        // 3. Create a new session bound to the composite key.
        $stmt = $db->prepare(
            'INSERT INTO ai_conversation_sessions
                (organizer_id, user_id, event_id, surface, conversation_mode,
                 session_key, context_json, expires_at)
             VALUES
                (:org_id, :user_id, :event_id, :surface, :mode,
                 :session_key, :context_json,
                 NOW() + INTERVAL \'' . self::SESSION_EXPIRY_HOURS . ' hours\')
             RETURNING id, organizer_id, event_id, user_id, surface, conversation_mode,
                       session_key, routed_agent_key, routing_trace_id, status,
                       context_json, metadata_json, created_at, updated_at, expires_at'
        );
        $stmt->execute([
            'org_id'       => $organizerId,
            'user_id'      => $userId,
            'event_id'     => $eventId !== null && $eventId > 0 ? $eventId : null,
            'surface'      => $surface,
            'mode'         => $conversationMode,
            'session_key'  => $sessionKey,
            'context_json' => json_encode($context),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row['context_json'] = json_decode($row['context_json'] ?: '{}', true);
        $row['metadata_json'] = json_decode($row['metadata_json'] ?: '{}', true);
        $row['_created'] = true;
        return $row;
    }

    /**
     * Start a new conversation session (legacy V2 path — kept for backward compat).
     * EMAS V3 callers should use findOrCreateSession() instead.
     * @return string Session UUID
     */
    public static function startSession(PDO $db, int $organizerId, int $userId, array $context = []): string
    {
        $stmt = $db->prepare(
            'INSERT INTO ai_conversation_sessions
                (organizer_id, user_id, event_id, surface, context_json, expires_at)
             VALUES
                (:org_id, :user_id, :event_id, :surface, :context_json,
                 NOW() + INTERVAL \'' . self::SESSION_EXPIRY_HOURS . ' hours\')
             RETURNING id'
        );
        $stmt->execute([
            'org_id'       => $organizerId,
            'user_id'      => $userId,
            'event_id'     => (int)($context['event_id'] ?? 0) ?: null,
            'surface'      => $context['surface'] ?? null,
            'context_json' => json_encode($context),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['id'];
    }

    /**
     * Persist the routing_trace_id for a session (EMAS BE-S1-A3 hand-off).
     */
    public static function setRoutingTrace(PDO $db, string $sessionId, ?string $routingTraceId, ?int $organizerId = null): void
    {
        if ($routingTraceId === null || $routingTraceId === '') {
            return;
        }
        $sql = 'UPDATE ai_conversation_sessions
                   SET routing_trace_id = :trace, updated_at = NOW()
                 WHERE id = :id';
        $params = ['id' => $sessionId, 'trace' => $routingTraceId];
        if ($organizerId !== null) {
            $sql .= ' AND organizer_id = :org_id';
            $params['org_id'] = $organizerId;
        }
        $db->prepare($sql)->execute($params);
    }

    /**
     * Get a session by ID, scoped to organizer.
     */
    public static function getSession(PDO $db, string $sessionId, int $organizerId): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, organizer_id, event_id, user_id, surface, routed_agent_key,
                    status, context_json, metadata_json, created_at, updated_at, expires_at
             FROM ai_conversation_sessions
             WHERE id = :id AND organizer_id = :org_id'
        );
        $stmt->execute(['id' => $sessionId, 'org_id' => $organizerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['context_json'] = json_decode($row['context_json'] ?: '{}', true);
        $row['metadata_json'] = json_decode($row['metadata_json'] ?: '{}', true);
        return $row;
    }

    /**
     * List active sessions for a user.
     */
    public static function listSessions(PDO $db, int $organizerId, int $userId, int $limit = 20): array
    {
        $stmt = $db->prepare(
            'SELECT id, event_id, surface, routed_agent_key, status,
                    created_at, updated_at, expires_at
             FROM ai_conversation_sessions
             WHERE organizer_id = :org_id AND user_id = :user_id
               AND status = \'active\' AND expires_at > NOW()
             ORDER BY updated_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue('org_id', $organizerId, PDO::PARAM_INT);
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Archive a session.
     */
    public static function archiveSession(PDO $db, string $sessionId, int $organizerId): bool
    {
        $stmt = $db->prepare(
            'UPDATE ai_conversation_sessions
             SET status = \'archived\', updated_at = NOW()
             WHERE id = :id AND organizer_id = :org_id AND status = \'active\''
        );
        $stmt->execute(['id' => $sessionId, 'org_id' => $organizerId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update the routed agent for a session.
     */
    public static function updateRoutedAgent(PDO $db, string $sessionId, string $agentKey, ?int $organizerId = null): void
    {
        $sql = 'UPDATE ai_conversation_sessions
                SET routed_agent_key = :agent_key, updated_at = NOW()
                WHERE id = :id';
        $params = ['id' => $sessionId, 'agent_key' => $agentKey];

        if ($organizerId !== null) {
            $sql .= ' AND organizer_id = :org_id';
            $params['org_id'] = $organizerId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    // ──────────────────────────────────────────────────────────────
    //  Messages
    // ──────────────────────────────────────────────────────────────

    /**
     * Add a message to the conversation.
     */
    public static function addMessage(
        PDO $db,
        string $sessionId,
        int $organizerId,
        string $role,
        string $content,
        string $contentType = 'text',
        ?string $agentKey = null,
        ?int $executionId = null,
        array $metadata = []
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO ai_conversation_messages
                (session_id, organizer_id, role, content, content_type,
                 agent_key, execution_id, metadata_json)
             VALUES
                (:session_id, :org_id, :role, :content, :content_type,
                 :agent_key, :execution_id, :metadata_json)
             RETURNING id'
        );
        $stmt->execute([
            'session_id'    => $sessionId,
            'org_id'        => $organizerId,
            'role'          => $role,
            'content'       => $content,
            'content_type'  => $contentType,
            'agent_key'     => $agentKey,
            'execution_id'  => $executionId,
            'metadata_json' => json_encode($metadata),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Update session timestamp (scoped to organizer)
        $db->prepare('UPDATE ai_conversation_sessions SET updated_at = NOW() WHERE id = :id AND organizer_id = :org_id')
            ->execute(['id' => $sessionId, 'org_id' => $organizerId]);

        return (int)$row['id'];
    }

    /**
     * Get message history for a session.
     */
    public static function getHistory(PDO $db, string $sessionId, int $organizerId, int $limit = 50): array
    {
        // Subquery: pick the N most RECENT messages (DESC), then wrap in
        // outer query to restore chronological ASC order. This ensures the
        // LLM sees the latest exchanges, not the oldest ones from the start
        // of the session. Critical for Bug H fix (hotfix 5/7).
        $stmt = $db->prepare(
            'SELECT id, role, content, content_type, agent_key, execution_id,
                    metadata_json, created_at
             FROM (
                SELECT id, role, content, content_type, agent_key, execution_id,
                       metadata_json, created_at
                FROM ai_conversation_messages
                WHERE session_id = :session_id AND organizer_id = :org_id
                ORDER BY created_at DESC
                LIMIT :lim
             ) recent
             ORDER BY created_at ASC'
        );
        $stmt->bindValue('session_id', $sessionId);
        $stmt->bindValue('org_id', $organizerId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($messages as &$msg) {
            $msg['metadata_json'] = json_decode($msg['metadata_json'] ?: '{}', true);
        }

        return $messages;
    }

    /**
     * Build conversational context for the orchestrator.
     * Returns an array of messages in the format expected by the AI providers:
     * [['role' => 'user', 'content' => '...'], ['role' => 'assistant', 'content' => '...'], ...]
     */
    public static function buildConversationalContext(PDO $db, string $sessionId, int $organizerId, int $maxMessages = 20): array
    {
        $messages = self::getHistory($db, $sessionId, $organizerId, $maxMessages);

        $context = [];
        foreach ($messages as $msg) {
            // Only include user and assistant messages for the provider context
            if (!in_array($msg['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $context[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        return $context;
    }

    /**
     * Count messages in a session.
     */
    public static function countMessages(PDO $db, string $sessionId, ?int $organizerId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ai_conversation_messages WHERE session_id = :session_id';
        $params = ['session_id' => $sessionId];

        if ($organizerId !== null) {
            $sql .= ' AND organizer_id = :org_id';
            $params['org_id'] = $organizerId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if session can accept more messages.
     */
    public static function canAddMessage(PDO $db, string $sessionId, ?int $organizerId = null): bool
    {
        return self::countMessages($db, $sessionId, $organizerId) < self::MAX_MESSAGES_PER_SESSION;
    }

    // ──────────────────────────────────────────────────────────────
    //  Cleanup
    // ──────────────────────────────────────────────────────────────

    /**
     * Expire old sessions. Call periodically (e.g., from a cron or health check).
     */
    public static function expireOldSessions(PDO $db): int
    {
        $stmt = $db->query(
            'UPDATE ai_conversation_sessions
             SET status = \'expired\', updated_at = NOW()
             WHERE status = \'active\' AND expires_at < NOW()'
        );
        return $stmt->rowCount();
    }
}
