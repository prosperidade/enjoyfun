<?php
/**
 * Organizer Settings Controller
 * White-label settings por organizer (nome do app, cores e logo).
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null        => getSettings(),
        $method === 'PUT'  && $id === null        => updateSettings($body),
        $method === 'POST' && $id === 'logo'      => uploadLogo(),
        default => jsonError('Organizer settings endpoint não encontrado.', 404),
    };
}

function getSettings(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    ensureOrganizerSettingsTable($db);

    $organizerId = resolveOrganizerId($user);

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

function updateSettings(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    ensureOrganizerSettingsTable($db);

    $organizerId = resolveOrganizerId($user);
    $appName = trim((string)($body['app_name'] ?? 'EnjoyFun'));
    $primaryColor = normalizeHexColor((string)($body['primary_color'] ?? '#7c3aed'));
    $secondaryColor = normalizeHexColor((string)($body['secondary_color'] ?? '#db2777'));

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

    jsonSuccess([
        'organizer_id' => $organizerId,
        'app_name' => $appName,
        'primary_color' => $primaryColor,
        'secondary_color' => $secondaryColor,
    ], 'Configurações visuais salvas com sucesso.');
}

function uploadLogo(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    ensureOrganizerSettingsTable($db);

    if (empty($_FILES['logo'])) {
        jsonError('Arquivo de logo não enviado.', 422);
    }

    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError('Falha no upload da logo.', 400);
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    $mime = mime_content_type($file['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        jsonError('Formato inválido. Use PNG, JPG, WEBP ou SVG.', 422);
    }

    $organizerId = resolveOrganizerId($user);
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
    $logoUrl = buildPublicAssetUrl($logoPath);

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

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        // fallback para admin operar no próprio id caso não tenha organizer_id
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }

    return (int)($user['organizer_id'] ?? 0);
}

function normalizeHexColor(string $color): string
{
    $color = trim($color);
    if (!preg_match('/^#([A-Fa-f0-9]{6})$/', $color)) {
        jsonError('Cor inválida. Use formato hexadecimal #RRGGBB.', 422);
    }

    return strtoupper($color);
}

function buildPublicAssetUrl(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    // Normaliza barras para ambiente Windows/Linux
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

    return $scheme . '://' . $host . $path;
}

function ensureOrganizerSettingsTable(PDO $db): void
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
