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

    // ──────────────────────────────────────────────────────────────
    //  Session lifecycle
    // ──────────────────────────────────────────────────────────────

    /**
     * Start a new conversation session.
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
        $stmt = $db->prepare(
            'SELECT id, role, content, content_type, agent_key, execution_id,
                    metadata_json, created_at
             FROM ai_conversation_messages
             WHERE session_id = :session_id AND organizer_id = :org_id
             ORDER BY created_at ASC
             LIMIT :lim'
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
