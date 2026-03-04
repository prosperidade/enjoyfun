<?php
/**
 * Organizer Settings Controller — EnjoyFun 2.0 (White Label)
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => getSettings(),
        $method === 'PUT' && $id === null => updateSettings($body),
        $method === 'POST' && $id === 'logo' => uploadLogo(),
        default => jsonError("Endpoint de configurações não encontrado.", 404),
    };
}

function getSettings(): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM organizer_settings WHERE organizer_id = ? LIMIT 1");
        $stmt->execute([$organizerId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            // Retorna um padrão se o organizador for novo e ainda não configurou nada
            $settings = [
                'app_name' => 'EnjoyFun',
                'primary_color' => '#7C3AED',
                'secondary_color' => '#4F46E5',
                'logo_url' => null,
                'support_email' => $operator['email'] ?? '',
                'support_whatsapp' => ''
            ];
        }

        jsonSuccess($settings);
    } catch (Exception $e) {
        jsonError("Erro ao buscar configurações: " . $e->getMessage(), 500);
    }
}

function updateSettings(array $body): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    $appName = trim($body['app_name'] ?? 'EnjoyFun');
    $primaryColor = trim($body['primary_color'] ?? '#7C3AED');
    $secondaryColor = trim($body['secondary_color'] ?? '#4F46E5');
    $supportEmail = trim($body['support_email'] ?? '');
    $supportWhatsapp = trim($body['support_whatsapp'] ?? '');
    $subdomain = trim($body['subdomain'] ?? '');

    try {
        $db = Database::getInstance();

        // Verifica se o subdomínio já existe para OUTRO organizador
        if ($subdomain) {
            $stmtCheck = $db->prepare("SELECT organizer_id FROM organizer_settings WHERE subdomain = ? AND organizer_id != ?");
            $stmtCheck->execute([$subdomain, $organizerId]);
            if ($stmtCheck->fetch()) {
                jsonError("Este subdomínio já está em uso por outro evento.", 409);
            }
        }

        // UPSERT: Insere se não existir, Atualiza se já existir (PostgreSQL ON CONFLICT)
        $sql = "
            INSERT INTO organizer_settings (organizer_id, app_name, primary_color, secondary_color, support_email, support_whatsapp, subdomain, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (organizer_id) 
            DO UPDATE SET 
                app_name = EXCLUDED.app_name,
                primary_color = EXCLUDED.primary_color,
                secondary_color = EXCLUDED.secondary_color,
                support_email = EXCLUDED.support_email,
                support_whatsapp = EXCLUDED.support_whatsapp,
                subdomain = EXCLUDED.subdomain,
                updated_at = NOW()
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$organizerId, $appName, $primaryColor, $secondaryColor, $supportEmail, $supportWhatsapp, $subdomain ?: null]);

        jsonSuccess(null, "Configurações visuais atualizadas com sucesso!");
    } catch (Exception $e) {
        jsonError("Erro ao atualizar configurações: " . $e->getMessage(), 500);
    }
}

function uploadLogo(): void
{
    $operator = requireAuth();
    $organizerId = $operator['organizer_id'];

    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        jsonError("Nenhum arquivo de imagem válido enviado.", 400);
    }

    $file = $_FILES['logo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'svg', 'webp'];

    if (!in_array($ext, $allowedExts)) {
        jsonError("Formato de imagem não permitido. Use JPG, PNG, SVG ou WEBP.", 415);
    }

    // Cria a pasta de uploads automaticamente se ela não existir
    $uploadDir = BASE_PATH . '/public/uploads/logos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = "logo_org_{$organizerId}_" . time() . ".{$ext}";
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Monta a URL pública (usando a porta do PHP)
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $domain = $_SERVER['HTTP_HOST'];
        
        // CORREÇÃO: Adicionado o /public/ logo após o domínio para o localhost conseguir achar a foto
        $publicUrl = "{$protocol}://{$domain}/public/uploads/logos/{$filename}";

        try {
            $db = Database::getInstance();
            $sql = "
                INSERT INTO organizer_settings (organizer_id, logo_url, updated_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (organizer_id) 
                DO UPDATE SET logo_url = EXCLUDED.logo_url, updated_at = NOW()
            ";
            $db->prepare($sql)->execute([$organizerId, $publicUrl]);

            jsonSuccess(['logo_url' => $publicUrl], "Logo enviada e salva com sucesso!");
        } catch (Exception $e) {
            jsonError("Erro ao salvar logo no banco: " . $e->getMessage(), 500);
        }
    } else {
        jsonError("Falha ao salvar o arquivo no servidor.", 500);
    }
}