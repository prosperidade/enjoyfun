<?php
/**
 * Organizer Messaging Settings Controller
 * Gerencia as configurações de canais de comunicação (E-mail e WhatsApp)
 */

require_once BASE_PATH . '/src/Services/OrganizerMessagingConfigService.php';

function organizerMessagingSettingsDispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null => organizerMessagingSettingsGet(),
        $method === 'POST' && $id === null => organizerMessagingSettingsSave($body),
        default => jsonError('Messaging settings endpoint não encontrado.', 404),
    };
}

if (!function_exists('dispatch')) {
    function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
    {
        organizerMessagingSettingsDispatch($method, $id, $sub, $subId, $body, $query);
    }
}

function organizerMessagingSettingsGet(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();
    messagingSettingsEnsureOrganizerSettingsTable($db);
    $organizerId = messagingSettingsResolveOrganizerId($user);
    $settings = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $organizerId);
    jsonSuccess(\EnjoyFun\Services\OrganizerMessagingConfigService::toSettingsPayload($settings));
}

function organizerMessagingSettingsSave(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db   = Database::getInstance();
    messagingSettingsEnsureOrganizerSettingsTable($db);
    $organizerId = messagingSettingsResolveOrganizerId($user);
    try {
        $settings = \EnjoyFun\Services\OrganizerMessagingConfigService::save($db, $organizerId, $body);
    } catch (\RuntimeException $e) {
        jsonError($e->getMessage(), 409);
    }
    jsonSuccess(
        \EnjoyFun\Services\OrganizerMessagingConfigService::toSettingsPayload($settings),
        'Configurações de mensageria salvas com sucesso.'
    );
}

function messagingSettingsResolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }

    return (int)($user['organizer_id'] ?? 0);
}

function messagingSettingsEnsureOrganizerSettingsTable(PDO $db): void
{
    if (!messagingSettingsTableExists($db)) {
        jsonError(
            'Readiness de ambiente inválida: tabela `organizer_settings` ausente. Aplique a migration obrigatória antes de usar configurações do organizador.',
            409
        );
    }

    $requiredColumns = [
        'organizer_id',
        'resend_api_key',
        'email_sender',
        'wa_api_url',
        'wa_token',
        'wa_instance',
    ];

    $missingColumns = [];
    foreach ($requiredColumns as $column) {
        if (!messagingSettingsColumnExists($db, $column)) {
            $missingColumns[] = $column;
        }
    }

    if ($missingColumns !== []) {
        jsonError(
            'Readiness de ambiente inválida: `organizer_settings` incompleta (faltando: ' .
            implode(', ', $missingColumns) .
            '). Aplique a migration obrigatória antes de usar configurações do organizador.',
            409
        );
    }
}

function messagingSettingsTableExists(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = 'organizer_settings'
        LIMIT 1
    ");
    $stmt->execute();
    $cache = (bool)$stmt->fetchColumn();

    return $cache;
}

function messagingSettingsColumnExists(PDO $db, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'organizer_settings'
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':column' => $column]);
    $cache[$column] = (bool)$stmt->fetchColumn();

    return $cache[$column];
}
