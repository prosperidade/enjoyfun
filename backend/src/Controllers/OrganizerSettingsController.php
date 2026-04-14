<?php
/**
 * Organizer Settings Controller
 * White-label settings por organizer (nome do app, cores e logo).
 */

function organizerSettingsDispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null        => organizerSettingsGet(),
        $method === 'PUT'  && $id === null        => organizerSettingsUpdate($body),
        $method === 'POST' && $id === 'logo'      => organizerSettingsUploadLogo(),
        default => jsonError('Organizer settings endpoint não encontrado.', 404),
    };
}

if (!function_exists('dispatch')) {
    function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
    {
        organizerSettingsDispatch($method, $id, $sub, $subId, $body, $query);
    }
}

function organizerSettingsGet(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    organizerSettingsEnsureOrganizerSettingsTable($db);

    $organizerId = organizerSettingsResolveOrganizerId($user);

    $stmt = $db->prepare('
        SELECT organizer_id, app_name, primary_color, secondary_color, logo_url, updated_at
        FROM organizer_settings
        WHERE organizer_id = ?
        LIMIT 1
    ');
    $stmt->execute([$organizerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $row = [
            'organizer_id'   => $organizerId,
            'app_name'       => 'EnjoyFun',
            'primary_color'  => '#7c3aed',
            'secondary_color'=> '#db2777',
            'logo_url'       => null,
            'updated_at'     => null,
        ];
    }

    jsonSuccess($row);
}

function organizerSettingsUpdate(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    organizerSettingsEnsureOrganizerSettingsTable($db);

    $organizerId = organizerSettingsResolveOrganizerId($user);
    $appName = trim((string)($body['app_name'] ?? 'EnjoyFun'));
    $primaryColor = organizerSettingsNormalizeHexColor((string)($body['primary_color'] ?? '#7c3aed'));
    $secondaryColor = organizerSettingsNormalizeHexColor((string)($body['secondary_color'] ?? '#db2777'));

    if (!$appName) {
        jsonError('app_name é obrigatório.', 422);
    }

    $stmt = $db->prepare(
        "INSERT INTO organizer_settings (organizer_id, app_name, primary_color, secondary_color, updated_at)
         VALUES (?, ?, ?, ?, NOW())
         ON CONFLICT (organizer_id)
         DO UPDATE SET app_name = EXCLUDED.app_name,
                       primary_color = EXCLUDED.primary_color,
                       secondary_color = EXCLUDED.secondary_color,
                       updated_at = NOW()"
    );
    $stmt->execute([$organizerId, $appName, $primaryColor, $secondaryColor]);

    AuditService::log(
        'settings.branding.update',
        'organizer_settings',
        $organizerId,
        null,
        ['app_name' => $appName, 'primary_color' => $primaryColor, 'secondary_color' => $secondaryColor],
        $user
    );

    jsonSuccess([
        'organizer_id' => $organizerId,
        'app_name' => $appName,
        'primary_color' => $primaryColor,
        'secondary_color' => $secondaryColor,
    ], 'Configurações visuais salvas com sucesso.');
}

function organizerSettingsUploadLogo(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    organizerSettingsEnsureOrganizerSettingsTable($db);

    if (empty($_FILES['logo'])) {
        jsonError('Arquivo de logo não enviado.', 422);
    }

    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError('Falha no upload da logo.', 400);
    }

    // SVG removido: pode conter scripts XSS embutidos
    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    $mime = mime_content_type($file['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        jsonError('Formato inválido. Use PNG, JPG ou WEBP.', 422);
    }

    $organizerId = organizerSettingsResolveOrganizerId($user);
    $ext = $allowed[$mime];

    $publicDir = BASE_PATH . '/public';
    $targetDir = $publicDir . '/uploads/logos';

    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        jsonError('Não foi possível criar pasta de uploads.', 500);
    }

    $filename = sprintf('org_%d_%s.%s', $organizerId, bin2hex(random_bytes(6)), $ext);
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        jsonError('Não foi possível salvar a logo no servidor.', 500);
    }

    $logoPath = '/uploads/logos/' . $filename;
    $logoUrl = organizerSettingsBuildPublicAssetUrl($logoPath);

    $stmt = $db->prepare(
        "INSERT INTO organizer_settings (organizer_id, logo_url, updated_at)
         VALUES (?, ?, NOW())
         ON CONFLICT (organizer_id)
         DO UPDATE SET logo_url = EXCLUDED.logo_url,
                       updated_at = NOW()"
    );
    $stmt->execute([$organizerId, $logoUrl]);

    jsonSuccess(['logo_url' => $logoUrl], 'Logo atualizada com sucesso.');
}

// Função saveMessagingSettings foi movida para OrganizerMessagingSettingsController.php

function organizerSettingsResolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        // BUG CONHECIDO (S6): fallback para $user['id'] é incorreto — user id != organizer_id.
        // Corrigir requer passar ?organizer_id via query param e propagar $query até aqui.
        // Mantido temporariamente para não bloquear admin; rastrear via TODO.
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }

    return (int)($user['organizer_id'] ?? 0);
}

function organizerSettingsNormalizeHexColor(string $color): string
{
    $color = trim($color);
    if (!preg_match('/^#([A-Fa-f0-9]{6})$/', $color)) {
        jsonError('Cor inválida. Use formato hexadecimal #RRGGBB.', 422);
    }

    return strtoupper($color);
}

function organizerSettingsBuildPublicAssetUrl(string $path): string
{
    // APP_URL é fonte confiável; HTTP_HOST é spoofable e só serve como fallback local
    $appUrl = getenv('APP_URL');
    if ($appUrl) {
        $baseUrl = rtrim($appUrl, '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        $baseUrl = $scheme . '://' . $host;
    }

    // Normaliza barras para ambiente Windows/Linux
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

    return $baseUrl . $path;
}

function organizerSettingsEnsureOrganizerSettingsTable(PDO $db): void
{
    if (!organizerSettingsTableExists($db)) {
        jsonError(
            'Readiness de ambiente inválida: tabela `organizer_settings` ausente. Aplique a migration obrigatória antes de usar configurações do organizador.',
            409
        );
    }

    $requiredColumns = [
        'organizer_id',
        'app_name',
        'primary_color',
        'secondary_color',
        'logo_url',
        'updated_at',
        'resend_api_key',
        'email_sender',
        'wa_api_url',
        'wa_token',
        'wa_instance',
    ];

    $missingColumns = [];
    foreach ($requiredColumns as $column) {
        if (!organizerSettingsColumnExists($db, $column)) {
            $missingColumns[] = $column;
        }
    }

    if (!empty($missingColumns)) {
        jsonError(
            'Readiness de ambiente inválida: `organizer_settings` incompleta (faltando: ' .
            implode(', ', $missingColumns) .
            '). Aplique a migration obrigatória antes de usar configurações do organizador.',
            409
        );
    }
}

function organizerSettingsTableExists(PDO $db): bool
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

function organizerSettingsColumnExists(PDO $db, string $column): bool
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
