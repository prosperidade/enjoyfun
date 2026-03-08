<?php
/**
 * Organizer Messaging Settings Controller
 * Gerencia as configurações de canais de comunicação (E-mail e WhatsApp)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    require_once __DIR__ . '/../Services/GeminiService.php'; // Se futuramente usar IA nos canais

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

    $stmt = $db->prepare('
        SELECT resend_api_key, email_sender, wa_api_url, wa_token, wa_instance
        FROM organizer_settings
        WHERE organizer_id = ?
        LIMIT 1
    ');
    $stmt->execute([$organizerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $row = [
            'resend_api_key' => null,
            'email_sender'   => null,
            'wa_api_url'     => null,
            'wa_token'       => null,
            'wa_instance'    => null,
        ];
    }

    // Flags booleanas para o frontend não precisar carregar tokens sensíveis inteiros
    $row['wa_configured']    = !empty($row['wa_api_url']) && !empty($row['wa_token']);
    $row['email_configured'] = !empty($row['resend_api_key']);

    jsonSuccess($row);
}

function saveMessagingSettings(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db   = Database::getInstance();

    require_once __DIR__ . '/OrganizerSettingsController.php'; // Reaproveitar helpers se necessário
    ensureOrganizerSettingsTable($db);

    $organizerId = resolveOrganizerId($user);

    // Aceita qualquer combinação dos 5 campos (parcial update)
    $fields = [
        'resend_api_key' => trim($body['resend_api_key'] ?? ''),
        'email_sender'   => trim($body['email_sender']   ?? ''),
        'wa_api_url'     => rtrim(trim($body['wa_api_url'] ?? ''), '/'),
        'wa_token'       => trim($body['wa_token']       ?? ''),
        'wa_instance'    => trim($body['wa_instance']    ?? ''),
    ];

    $db->prepare("
        INSERT INTO organizer_settings (organizer_id, resend_api_key, email_sender, wa_api_url, wa_token, wa_instance, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON CONFLICT (organizer_id) DO UPDATE SET
            resend_api_key = COALESCE(NULLIF(EXCLUDED.resend_api_key, ''), organizer_settings.resend_api_key),
            email_sender   = COALESCE(NULLIF(EXCLUDED.email_sender, ''),   organizer_settings.email_sender),
            wa_api_url     = COALESCE(NULLIF(EXCLUDED.wa_api_url, ''),     organizer_settings.wa_api_url),
            wa_token       = COALESCE(NULLIF(EXCLUDED.wa_token, ''),       organizer_settings.wa_token),
            wa_instance    = COALESCE(NULLIF(EXCLUDED.wa_instance, ''),    organizer_settings.wa_instance),
            updated_at     = NOW()
    ")->execute([
        $organizerId,
        $fields['resend_api_key'],
        $fields['email_sender'],
        $fields['wa_api_url'],
        $fields['wa_token'],
        $fields['wa_instance'],
    ]);

    jsonSuccess($fields, 'Configurações de mensageria salvas com sucesso.');
}
