<?php

namespace EnjoyFun\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

require_once BASE_PATH . '/src/Helpers/WorkforceTreeHelper.php';

final class WorkforceTreeUseCaseService
{
    public static function getStatus(
        PDO $db,
        int $organizerId,
        int $eventId,
        bool $canBypassSector,
        string $userSector
    ): array {
        self::assertEventId(
            $eventId,
            'event_id é obrigatório para diagnosticar a árvore do Workforce.'
        );

        return \buildWorkforceTreeStatus($db, $organizerId, $eventId, $canBypassSector, $userSector);
    }

    public static function backfill(
        PDO $db,
        int $organizerId,
        int $eventId,
        string $sector = ''
    ): array {
        self::assertEventId(
            $eventId,
            'event_id é obrigatório para executar o backfill da árvore do Workforce.'
        );
        self::assertEventRolesReadiness($db);

        if (!\workforceAssignmentsHaveEventRoleColumns($db)) {
            throw new RuntimeException(
                'Readiness de ambiente inválida: `workforce_assignments` ainda não recebeu `event_role_id` e `root_manager_event_role_id`.',
                409
            );
        }

        try {
            $db->beginTransaction();
            $result = \runWorkforceTreeBackfill($db, $organizerId, $eventId, $sector);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw new RuntimeException(
                'Erro ao executar backfill da árvore do Workforce: ' . $e->getMessage(),
                500,
                $e
            );
        }

        $result['status_after'] = \buildWorkforceTreeStatus($db, $organizerId, $eventId, true, 'all');
        return $result;
    }

    public static function sanitize(
        PDO $db,
        int $organizerId,
        int $eventId,
        string $sector = ''
    ): array {
        self::assertEventId(
            $eventId,
            'event_id é obrigatório para executar o saneamento da árvore do Workforce.'
        );
        self::assertEventRolesReadiness($db);

        try {
            $db->beginTransaction();
            $result = \runWorkforceTreeSanitization($db, $organizerId, $eventId, $sector);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw new RuntimeException(
                'Erro ao executar saneamento da árvore do Workforce: ' . $e->getMessage(),
                500,
                $e
            );
        }

        $result['status_after'] = \buildWorkforceTreeStatus($db, $organizerId, $eventId, true, 'all');
        return $result;
    }

    private static function assertEventId(int $eventId, string $message): void
    {
        if ($eventId <= 0) {
            throw new InvalidArgumentException($message, 400);
        }
    }

    private static function assertEventRolesReadiness(PDO $db): void
    {
        if (\workforceEventRolesReady($db)) {
            return;
        }

        throw new RuntimeException(
            'Readiness de ambiente inválida: `workforce_event_roles` ausente ou incompleta. Aplique a migration da Fase 1 antes de usar a árvore por evento.',
            409
        );
    }
}
