<?php
/**
 * Organizer Messaging Settings Controller
 * Gerencia as configurações de canais de comunicação (E-mail e WhatsApp)
 */

require_once BASE_PATH . '/src/Services/OrganizerMessagingConfigService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null => getMessagingSettings(),
        $method === 'POST' && $id === null => saveMessagingSettings($body),
        default => jsonError('Messaging settings endpoint não encontrado.', 404),
    };
}

function getMessagingSettings(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    require_once __DIR__ . '/OrganizerSettingsController.php'; // Reaproveitar helpers se necessário
    ensureOrganizerSettingsTable($db);

    $organizerId = resolveOrganizerId($user);
    $settings = \EnjoyFun\Services\OrganizerMessagingConfigService::load($db, $organizerId);
    jsonSuccess(\EnjoyFun\Services\OrganizerMessagingConfigService::toPublicPayload($settings));
}

function saveMessagingSettings(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db   = Database::getInstance();

    require_once __DIR__ . '/OrganizerSettingsController.php'; // Reaproveitar helpers se necessário
    ensureOrganizerSettingsTable($db);

    $organizerId = resolveOrganizerId($user);
    $settings = \EnjoyFun\Services\OrganizerMessagingConfigService::save($db, $organizerId, $body);
    jsonSuccess(
        \EnjoyFun\Services\OrganizerMessagingConfigService::toPublicPayload($settings),
        'Configurações de mensageria salvas com sucesso.'
    );
}
