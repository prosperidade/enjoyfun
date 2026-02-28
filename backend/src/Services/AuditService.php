<?php

/**
 * AuditService — EnjoyFun
 * Registra ações críticas no audit_log imutável.
 *
 * Uso:
 *   AuditService::log('card.recharge', 'card', $cardId, $valorAntes, $valorDepois);
 */
class AuditService
{
    // Ações padronizadas
    const CARD_RECHARGE     = 'card.recharge';
    const CARD_DEBIT        = 'card.debit';
    const SALE_CHECKOUT     = 'sale.checkout';
    const SALE_CANCEL       = 'sale.cancel';
    const TICKET_VALIDATE   = 'ticket.validate';
    const TICKET_ISSUE      = 'ticket.issue';
    const USER_LOGIN        = 'user.login';
    const USER_LOGIN_FAILED = 'user.login_failed';
    const USER_LOGOUT       = 'user.logout';
    const SYNC_PROCESSED    = 'sync.processed';
    const SYNC_CONFLICT     = 'sync.conflict';

    public static function log(
        string $action,
        string $entityType,
        mixed  $entityId      = null,
        mixed  $previousValue = null,
        mixed  $newValue      = null,
        ?array $userPayload   = null,
        string $result        = 'success',
        array  $extra         = []
    ): void {
        try {
            $db = Database::getInstance();

            $db->prepare("
                INSERT INTO audit_log (
                    user_id, user_email, session_id,
                    ip_address, user_agent,
                    action, entity_type, entity_id,
                    previous_value, new_value,
                    event_id, pdv_id, metadata, result
                ) VALUES (
                    :user_id, :user_email, :session_id,
                    :ip_address, :user_agent,
                    :action, :entity_type, :entity_id,
                    :previous_value, :new_value,
                    :event_id, :pdv_id, :metadata, :result
                )
            ")->execute([
                ':user_id'        => $userPayload['sub']   ?? null,
                ':user_email'     => $userPayload['email'] ?? null,
                ':session_id'     => $userPayload['jti']   ?? null,
                ':ip_address'     => self::getIp(),
                ':user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':action'         => $action,
                ':entity_type'    => $entityType,
                ':entity_id'      => $entityId !== null ? (string) $entityId : null,
                ':previous_value' => $previousValue !== null ? json_encode($previousValue) : null,
                ':new_value'      => $newValue      !== null ? json_encode($newValue)      : null,
                ':event_id'       => $extra['event_id'] ?? null,
                ':pdv_id'         => $extra['pdv_id']   ?? null,
                ':metadata'       => isset($extra['metadata']) ? json_encode($extra['metadata']) : null,
                ':result'         => $result,
            ]);
        } catch (\Throwable $e) {
            // Nunca derruba o fluxo principal
            error_log('[AuditService] Falha ao registrar: ' . $e->getMessage());
        }
    }

    public static function logFailure(
        string $action,
        string $entityType,
        mixed  $entityId    = null,
        string $reason      = '',
        ?array $userPayload = null,
        array  $extra       = []
    ): void {
        $extra['metadata']['failure_reason'] = $reason;
        self::log($action, $entityType, $entityId, null, null, $userPayload, 'failure', $extra);
    }

    private static function getIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $val = $_SERVER[$key] ?? null;
            if ($val) return trim(explode(',', $val)[0]);
        }
        return null;
    }
}