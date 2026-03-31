<?php
/**
 * Workforce Controller
 * Gerencia os cargos e atribuições de trabalho de Staff e Terceiros no evento.
 */

require_once __DIR__ . '/../Helpers/WorkforceEventRoleHelper.php';
require_once __DIR__ . '/../Helpers/WorkforceControllerSupport.php';
require_once __DIR__ . '/../Helpers/WorkforceAssignmentIdentityHelper.php';
require_once __DIR__ . '/../Helpers/WorkforceCardIssuanceHelper.php';
require_once __DIR__ . '/../Helpers/WorkforceSettingsHelper.php';
require_once __DIR__ . '/../Helpers/WorkforceAssignmentsManagerHelper.php';
require_once __DIR__ . '/../Helpers/WorkforceImportHelper.php';
require_once __DIR__ . '/../Helpers/WorkforceRolesEventRolesHelper.php';
require_once __DIR__ . '/../Helpers/WorkforceTreeHelper.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Sub-rotas: /api/workforce/roles ou /api/workforce/assignments
    if ($id === 'import') {
        match (true) {
            $method === 'POST'   => importWorkforce($body),
            default => jsonError('Endpoint de Importação de Workforce não encontrado.', 404),
        };
        return;
    }

    if ($id === 'roles') {
        match (true) {
            $method === 'GET'    => listRoles($query),
            $method === 'POST'   && $sub !== null && $subId === 'import' => importWorkforce($body, (int)$sub),
            $method === 'POST'   => createRole($body),
            $method === 'DELETE' && $sub !== null => deleteRole((int)$sub),
            default => jsonError('Endpoint de Roles não encontrado.', 404),
        };
        return;
    }

    if ($id === 'event-roles') {
        match (true) {
            $method === 'GET' && $sub === null => listEventRoles($query),
            $method === 'POST' && $sub === null => createEventRole($body),
            $method === 'GET' && $sub !== null => getEventRole($sub),
            $method === 'PUT' && $sub !== null => updateEventRole($sub, $body),
            $method === 'DELETE' && $sub !== null => deleteEventRole($sub),
            default => jsonError('Endpoint de Event Roles não encontrado.', 404),
        };
        return;
    }

    if ($id === 'tree-status') {
        match (true) {
            $method === 'GET' => getTreeStatus($query),
            default => jsonError('Endpoint de diagnóstico da árvore não encontrado.', 404),
        };
        return;
    }

    if ($id === 'tree-backfill') {
        match (true) {
            $method === 'POST' => backfillTree($body, $query),
            default => jsonError('Endpoint de backfill da árvore não encontrado.', 404),
        };
        return;
    }

    if ($id === 'tree-sanitize') {
        match (true) {
            $method === 'POST' => sanitizeTree($body, $query),
            default => jsonError('Endpoint de saneamento da árvore não encontrado.', 404),
        };
        return;
    }

    if ($id === 'managers') {
        match (true) {
            $method === 'GET'    => listManagers($query),
            default => jsonError('Endpoint de Managers não encontrado.', 404),
        };
        return;
    }

    if ($id === 'member-settings') {
        match (true) {
            $method === 'GET' && $sub !== null => getMemberSettings((int)$sub),
            $method === 'PUT' && $sub !== null => upsertMemberSettings((int)$sub, $body),
            default => jsonError('Endpoint de Configuração de Membro não encontrado.', 404),
        };
        return;
    }

    if ($id === 'role-settings') {
        match (true) {
            $method === 'GET' && $sub !== null => getRoleSettings((int)$sub, $query),
            $method === 'PUT' && $sub !== null => upsertRoleSettings((int)$sub, $body, $query),
            default => jsonError('Endpoint de Configuração de Cargo não encontrado.', 404),
        };
        return;
    }

    if ($id === 'assignments') {
        match (true) {
            $method === 'GET'    => listAssignments($query),
            $method === 'POST'   => createAssignment($body),
            $method === 'DELETE' && $sub !== null => deleteAssignment((int)$sub),
            default => jsonError('Endpoint de Assignments não encontrado.', 404),
        };
        return;
    }

    if ($id === 'card-issuance') {
        match (true) {
            $method === 'POST' && $sub === 'preview' => previewCardIssuance($body),
            $method === 'POST' && $sub === 'issue' => issueCardsInBulk($body),
            default => jsonError('Endpoint de emissão de cartões em massa não encontrado.', 404),
        };
        return;
    }

    jsonError('Endpoint de Workforce não encontrado (utilize /workforce/roles, /workforce/event-roles, /workforce/tree-status, /workforce/tree-backfill, /workforce/tree-sanitize, /workforce/assignments, /workforce/member-settings, /workforce/role-settings ou /workforce/card-issuance).', 404);
}

// ----------------------------------------------------
// ROLES (Cargos Operacionais do Organizador)
// ----------------------------------------------------

function getTreeStatus(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $canBypassSector = canBypassSectorAcl($user);
    $userSector = resolveUserSector($db, $user);

    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para diagnosticar a árvore do Workforce.', 400);
    }

    $status = buildWorkforceTreeStatus($db, $organizerId, $eventId, $canBypassSector, $userSector);
    jsonSuccess($status);
}

function backfillTree(array $body, array $query = []): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($body['event_id'] ?? $query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para executar o backfill da árvore do Workforce.', 400);
    }

    $sector = normalizeSector((string)($body['sector'] ?? $query['sector'] ?? ''));

    ensureWorkforceEventRolesTable($db);
    if (!workforceAssignmentsHaveEventRoleColumns($db)) {
        jsonError(
            'Readiness de ambiente inválida: `workforce_assignments` ainda não recebeu `event_role_id` e `root_manager_event_role_id`.',
            409
        );
    }

    try {
        $db->beginTransaction();
        $result = runWorkforceTreeBackfill($db, $organizerId, $eventId, $sector);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao executar backfill da árvore do Workforce: ' . $e->getMessage(), 500);
    }

    $result['status_after'] = buildWorkforceTreeStatus($db, $organizerId, $eventId, true, 'all');
    jsonSuccess($result, 'Backfill da árvore do Workforce executado com sucesso.');
}

function sanitizeTree(array $body, array $query = []): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);

    $eventId = (int)($body['event_id'] ?? $query['event_id'] ?? 0);
    if ($eventId <= 0) {
        jsonError('event_id é obrigatório para executar o saneamento da árvore do Workforce.', 400);
    }

    $sector = normalizeSector((string)($body['sector'] ?? $query['sector'] ?? ''));

    ensureWorkforceEventRolesTable($db);

    try {
        $db->beginTransaction();
        $result = runWorkforceTreeSanitization($db, $organizerId, $eventId, $sector);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao executar saneamento da árvore do Workforce: ' . $e->getMessage(), 500);
    }

    $result['status_after'] = buildWorkforceTreeStatus($db, $organizerId, $eventId, true, 'all');
    jsonSuccess($result, 'Saneamento da árvore do Workforce executado com sucesso.');
}

// ----------------------------------------------------
// ASSIGNMENTS (Alocações da Equipe)
// ----------------------------------------------------

function importWorkforce(array $body, ?int $forcedRoleId = null): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveOrganizerId($user);
    $context = workforceResolveImportContext($db, $user, $organizerId, $body, $forcedRoleId);

    $targetSector = (string)($context['target_sector'] ?? '');
    $requestedRoleId = $context['requested_role_id'] ?? null;
    $requestedRoleName = $context['requested_role_name'] ?? null;
    $requestedRoleBucket = $context['requested_role_bucket'] ?? null;
    $defaultRoleId = (int)($context['assigned_role_id'] ?? 0);
    $assignedRoleName = (string)($context['assigned_role_name'] ?? '');
    $managerEventRole = is_array($context['manager_event_role'] ?? null) ? $context['manager_event_role'] : null;
    $managerUserId = $context['manager_user_id'] ?? null;
    $rootManagerEventRoleId = $context['root_manager_event_role_id'] ?? null;
    $managerialRedirect = (bool)($context['managerial_redirect'] ?? false);
    $resolvedDefaultEventRole = null;
    $imported = 0;
    $assigned = 0;
    $relinked = 0;
    $skipped = 0;
    $errors = [];

    try {
        $db->beginTransaction();
        $batch = workforceRunImportBatch($db, $organizerId, $context, $body);
        $resolvedDefaultEventRole = is_array($batch['resolved_default_event_role'] ?? null) ? $batch['resolved_default_event_role'] : null;
        $rootManagerEventRoleId = $batch['root_manager_event_role_id'] ?? $rootManagerEventRoleId;
        $imported = (int)($batch['imported'] ?? 0);
        $assigned = (int)($batch['assigned'] ?? 0);
        $relinked = (int)($batch['relinked'] ?? 0);
        $skipped = (int)($batch['skipped'] ?? 0);
        $errors = is_array($batch['errors'] ?? null) ? $batch['errors'] : [];

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Erro ao importar equipe: ' . $e->getMessage(), 500);
    }

    jsonSuccess([
        'sector' => $targetSector,
        'requested_role_id' => $requestedRoleId,
        'requested_role_name' => $requestedRoleName,
        'requested_role_bucket' => $requestedRoleBucket,
        'assigned_role_id' => $defaultRoleId,
        'assigned_role_name' => $assignedRoleName,
        'manager_event_role_id' => $managerEventRole ? (int)($managerEventRole['id'] ?? 0) : null,
        'root_manager_event_role_id' => $rootManagerEventRoleId ?: null,
        'assigned_event_role_id' => $resolvedDefaultEventRole ? (int)($resolvedDefaultEventRole['id'] ?? 0) : null,
        'auto_bound_to_manager' => (bool)($managerEventRole || $managerUserId),
        'managerial_redirect' => $managerialRedirect,
        'imported' => $imported,
        'assigned' => $assigned,
        'relinked' => $relinked,
        'skipped' => $skipped,
        'errors' => $errors
    ], $managerialRedirect
        ? "Importação concluída no cargo operacional '{$assignedRoleName}', vinculada automaticamente à liderança atual. O cargo gerencial '{$requestedRoleName}' foi preservado apenas para a liderança do setor."
        : "Importação concluída para o setor '{$targetSector}', com vínculo automático à liderança atual.");
}



function ensureParticipantQrToken(PDO $db, int $participantId): void
{
    $stmt = $db->prepare("
        UPDATE event_participants
        SET qr_token = :qr_token
        WHERE id = :participant_id
          AND (qr_token IS NULL OR TRIM(qr_token) = '')
    ");
    $stmt->execute([
        ':qr_token' => workforceGenerateParticipantQrToken(),
        ':participant_id' => $participantId,
    ]);
}

function backfillMissingQrTokensForEvent(PDO $db, int $eventId, int $organizerId): void
{
    $stmt = $db->prepare("
        SELECT ep.id
        FROM event_participants ep
        JOIN people p ON p.id = ep.person_id
        WHERE ep.event_id = :event_id
          AND p.organizer_id = :organizer_id
          AND (ep.qr_token IS NULL OR TRIM(ep.qr_token) = '')
        ORDER BY ep.id ASC
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':organizer_id' => $organizerId
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $participantId) {
        ensureParticipantQrToken($db, (int)$participantId);
    }
}

function workforceGenerateParticipantQrToken(): string
{
    return 'PT_' . bin2hex(random_bytes(16));
}
