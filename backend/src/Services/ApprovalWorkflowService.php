<?php
/**
 * ApprovalWorkflowService.php
 * BE-S5-B2: Clean wrapper for the approval workflow: propose → confirm → execute → audit.
 */

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

final class ApprovalWorkflowService
{
    /** Create a pending approval request. */
    public static function propose(PDO $db, array $params): int
    {
        $stmt = $db->prepare("
            INSERT INTO public.ai_approval_requests
                (organizer_id, execution_id, session_id, agent_key, surface, skill_key,
                 params_json, risk_level, summary, status, requested_by_user_id)
            VALUES (:org, :exec_id, :session, :agent, :surface, :skill,
                    :params, :risk, :summary, 'pending', :user_id)
            RETURNING id
        ");
        $stmt->execute([
            ':org'      => (int)$params['organizer_id'],
            ':exec_id'  => $params['execution_id'] ?? null,
            ':session'  => $params['session_id'] ?? null,
            ':agent'    => $params['agent_key'] ?? null,
            ':surface'  => $params['surface'] ?? null,
            ':skill'    => (string)$params['skill_key'],
            ':params'   => json_encode($params['params'] ?? []),
            ':risk'     => $params['risk_level'] ?? 'write',
            ':summary'  => (string)$params['summary'],
            ':user_id'  => $params['user_id'] ?? null,
        ]);
        return (int)$stmt->fetchColumn();
    }

    /** Confirm a pending approval. */
    public static function confirm(PDO $db, int $approvalId, int $organizerId, int $userId, ?string $reason = null): array
    {
        $stmt = $db->prepare("
            UPDATE public.ai_approval_requests
            SET status = 'confirmed', decided_by_user_id = :user, decided_at = NOW(),
                decision_reason = :reason, updated_at = NOW()
            WHERE id = :id AND organizer_id = :org AND status = 'pending'
            RETURNING *
        ");
        $stmt->execute([':id' => $approvalId, ':org' => $organizerId, ':user' => $userId, ':reason' => $reason]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('Approval nao encontrado ou ja decidido.', 404); }
        return $row;
    }

    /** Cancel a pending approval. */
    public static function cancel(PDO $db, int $approvalId, int $organizerId, int $userId, ?string $reason = null): array
    {
        $stmt = $db->prepare("
            UPDATE public.ai_approval_requests
            SET status = 'cancelled', decided_by_user_id = :user, decided_at = NOW(),
                decision_reason = :reason, updated_at = NOW()
            WHERE id = :id AND organizer_id = :org AND status = 'pending'
            RETURNING *
        ");
        $stmt->execute([':id' => $approvalId, ':org' => $organizerId, ':user' => $userId, ':reason' => $reason]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('Approval nao encontrado ou ja decidido.', 404); }
        return $row;
    }

    /** List pending approvals for an organizer. */
    public static function listPending(PDO $db, int $organizerId, int $limit = 20): array
    {
        $stmt = $db->prepare("
            SELECT id, skill_key, summary, risk_level, agent_key, surface,
                   params_json, created_at
            FROM public.ai_approval_requests
            WHERE organizer_id = :org AND status = 'pending'
            ORDER BY created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':org', $organizerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Mark as executed after tool execution succeeds. */
    public static function markExecuted(PDO $db, int $approvalId, int $organizerId, array $resultJson): void
    {
        $db->prepare("
            UPDATE public.ai_approval_requests
            SET status = 'executed', result_json = :result, updated_at = NOW()
            WHERE id = :id AND organizer_id = :org AND status = 'confirmed'
        ")->execute([':id' => $approvalId, ':org' => $organizerId, ':result' => json_encode($resultJson)]);
    }
}
