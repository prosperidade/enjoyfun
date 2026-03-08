<?php
/**
 * Organizer AI Config Controller
 * Gerencia as configurações de IA por organizer (provider, prompt, status).
 */

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET'  && $id === null => getConfig(),
        $method === 'PUT'  && $id === null => updateConfig($body),
        default => jsonError('AI Config endpoint não encontrado.', 404),
    };
}

function getConfig(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    $organizerId = resolveOrganizerId($user);

    $stmt = $db->prepare('
        SELECT provider, system_prompt, is_active, updated_at
        FROM organizer_ai_config
        WHERE organizer_id = ?
        LIMIT 1
    ');
    $stmt->execute([$organizerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $row = [
            'provider'      => 'gemini',
            'system_prompt' => null,
            'is_active'     => true,
            'updated_at'    => null,
        ];
    }

    // Se bool is_active vier como t/f ou 0/1 do pgsql, formatá-lo
    if (isset($row['is_active']) && !is_bool($row['is_active'])) {
        $row['is_active'] = filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN);
    }

    jsonSuccess($row);
}

function updateConfig(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    $organizerId = resolveOrganizerId($user);

    $provider     = trim((string)($body['provider'] ?? 'gemini'));
    $systemPrompt = trim((string)($body['system_prompt'] ?? ''));
    $isActive     = filter_var($body['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';

    $stmt = $db->prepare("
        INSERT INTO organizer_ai_config (organizer_id, provider, system_prompt, is_active, updated_at)
        VALUES (?, ?, ?, {$isActive}, NOW())
        ON CONFLICT (id) DO UPDATE SET 
            provider = EXCLUDED.provider,
            system_prompt = EXCLUDED.system_prompt,
            is_active = EXCLUDED.is_active,
            updated_at = NOW()
    ");

    // NOTA: A tabela atual pode não ter organizer_id como UNIQUE CONFLICT. 
    // Vamos verificar se organizer_id é unique e tratar via transação/update direto caso ON CONFLICT falhe 
    // ou checamos se existe primeiro.
    
    // Tratamento mais seguro caso UNIQUE constraint falte no organizer_id:
    $checkStmt = $db->prepare("SELECT id FROM organizer_ai_config WHERE organizer_id = ?");
    $checkStmt->execute([$organizerId]);
    $existingId = $checkStmt->fetchColumn();

    if ($existingId) {
        $updateStmt = $db->prepare("
            UPDATE organizer_ai_config 
            SET provider = ?, system_prompt = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$provider, $systemPrompt, $isActive, $existingId]);
    } else {
        $insertStmt = $db->prepare("
            INSERT INTO organizer_ai_config (organizer_id, provider, system_prompt, is_active, updated_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([$organizerId, $provider, $systemPrompt, $isActive]);
    }

    jsonSuccess([
        'provider'      => $provider,
        'system_prompt' => $systemPrompt,
        'is_active'     => $isActive === 'true',
    ], 'Configurações de IA salvas com sucesso.');
}

function resolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}
