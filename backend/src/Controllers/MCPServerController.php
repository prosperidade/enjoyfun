<?php

require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Services/AIMCPClientService.php';
require_once BASE_PATH . '/src/Services/SecretCryptoService.php';
require_once BASE_PATH . '/src/Helpers/Response.php';

use EnjoyFun\Middleware\AuthMiddleware;
use EnjoyFun\Services\AIMCPClientService;
use EnjoyFun\Services\SecretCryptoService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    global $db;

    match (true) {
        // GET /organizer-mcp — list servers
        $method === 'GET' && $id === null => mcpListServers($db),

        // POST /organizer-mcp — create server
        $method === 'POST' && $id === null => mcpCreateServer($db, $body),

        // PUT /organizer-mcp/{id} — update server
        ($method === 'PUT' || $method === 'PATCH') && is_numeric($id) && $sub === null => mcpUpdateServer($db, (int)$id, $body),

        // DELETE /organizer-mcp/{id} — delete server
        $method === 'DELETE' && is_numeric($id) && $sub === null => mcpDeleteServer($db, (int)$id),

        // POST /organizer-mcp/{id}/discover — discover tools
        $method === 'POST' && is_numeric($id) && $sub === 'discover' => mcpDiscoverTools($db, (int)$id),

        // GET /organizer-mcp/{id}/tools — list tools
        $method === 'GET' && is_numeric($id) && $sub === 'tools' => mcpListServerTools($db, (int)$id),

        // PUT /organizer-mcp/{id}/tools/{toolId} — update tool
        ($method === 'PUT' || $method === 'PATCH') && is_numeric($id) && $sub === 'tools' && is_numeric($subId) => mcpUpdateServerTool($db, (int)$id, (int)$subId, $body),

        default => jsonError('Endpoint nao encontrado em organizer-mcp.', 404),
    };
}

function mcpListServers(PDO $db): void
{
    $operator = AuthMiddleware::requireAuth($db, ['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("
        SELECT id, label, server_url, auth_type, is_active, allowed_agent_keys, allowed_surfaces,
               last_discovery_at, last_discovery_status, created_at, updated_at
        FROM public.organizer_mcp_servers
        WHERE organizer_id = :org
        ORDER BY label
    ");
    $stmt->execute([':org' => $organizerId]);

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function mcpCreateServer(PDO $db, array $body): void
{
    $operator = AuthMiddleware::requireAuth($db, ['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $label = trim((string)($body['label'] ?? ''));
    $serverUrl = trim((string)($body['server_url'] ?? ''));
    $authType = strtolower(trim((string)($body['auth_type'] ?? 'none')));

    if ($label === '' || $serverUrl === '') {
        jsonError('label e server_url sao obrigatorios.', 422);
    }

    if (!in_array($authType, ['none', 'bearer', 'api_key', 'basic'], true)) {
        jsonError('auth_type invalido. Use: none, bearer, api_key, basic.', 422);
    }

    $credential = trim((string)($body['auth_credential'] ?? ''));
    $encryptedCredential = null;

    $allowedAgents = is_array($body['allowed_agent_keys'] ?? null) ? $body['allowed_agent_keys'] : [];
    $allowedSurfaces = is_array($body['allowed_surfaces'] ?? null) ? $body['allowed_surfaces'] : [];

    $stmt = $db->prepare("
        INSERT INTO public.organizer_mcp_servers (organizer_id, label, server_url, auth_type, encrypted_auth_credential, allowed_agent_keys, allowed_surfaces)
        VALUES (:org, :label, :url, :auth, :cred, :agents, :surfaces)
        RETURNING id
    ");
    $stmt->execute([
        ':org' => $organizerId,
        ':label' => $label,
        ':url' => $serverUrl,
        ':auth' => $authType,
        ':cred' => null,
        ':agents' => json_encode($allowedAgents),
        ':surfaces' => json_encode($allowedSurfaces),
    ]);
    $serverId = (int)$stmt->fetchColumn();

    if ($credential !== '' && $authType !== 'none') {
        $encrypted = SecretCryptoService::encrypt($credential, 'mcp_server:' . $serverId);
        $db->prepare("UPDATE public.organizer_mcp_servers SET encrypted_auth_credential = :cred WHERE id = :id")
           ->execute([':cred' => $encrypted, ':id' => $serverId]);
    }

    jsonSuccess(['id' => $serverId], 'MCP server criado com sucesso.', 201);
}

function mcpUpdateServer(PDO $db, int $serverId, array $body): void
{
    $operator = AuthMiddleware::requireAuth($db, ['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $setClauses = ['updated_at = NOW()'];
    $params = [':id' => $serverId, ':org' => $organizerId];

    foreach (['label', 'server_url', 'auth_type'] as $field) {
        if (array_key_exists($field, $body)) {
            $setClauses[] = "{$field} = :{$field}";
            $params[":{$field}"] = trim((string)$body[$field]);
        }
    }

    if (array_key_exists('is_active', $body)) {
        $setClauses[] = 'is_active = :active';
        $params[':active'] = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    if (array_key_exists('allowed_agent_keys', $body)) {
        $setClauses[] = 'allowed_agent_keys = :agents';
        $params[':agents'] = json_encode(is_array($body['allowed_agent_keys']) ? $body['allowed_agent_keys'] : []);
    }

    if (array_key_exists('allowed_surfaces', $body)) {
        $setClauses[] = 'allowed_surfaces = :surfaces';
        $params[':surfaces'] = json_encode(is_array($body['allowed_surfaces']) ? $body['allowed_surfaces'] : []);
    }

    if (array_key_exists('auth_credential', $body)) {
        $credential = trim((string)$body['auth_credential']);
        $encrypted = $credential !== '' ? SecretCryptoService::encrypt($credential, 'mcp_server:' . $serverId) : null;
        $setClauses[] = 'encrypted_auth_credential = :cred';
        $params[':cred'] = $encrypted;
    }

    $setStr = implode(', ', $setClauses);
    $db->prepare("UPDATE public.organizer_mcp_servers SET {$setStr} WHERE id = :id AND organizer_id = :org")->execute($params);

    jsonSuccess(null, 'MCP server atualizado.');
}

function mcpDeleteServer(PDO $db, int $serverId): void
{
    $operator = AuthMiddleware::requireAuth($db, ['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $db->prepare("DELETE FROM public.organizer_mcp_servers WHERE id = :id AND organizer_id = :org")
       ->execute([':id' => $serverId, ':org' => $organizerId]);

    jsonSuccess(null, 'MCP server removido.');
}

function mcpDiscoverTools(PDO $db, int $serverId): void
{
    $operator = AuthMiddleware::requireAuth($db, ['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $result = AIMCPClientService::discoverTools($db, $organizerId, $serverId);

    jsonSuccess($result, 'Discovery concluido.');
}

function mcpListServerTools(PDO $db, int $serverId): void
{
    $operator = AuthMiddleware::requireAuth($db, ['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("
        SELECT id, tool_name, tool_description, type, risk_level, is_enabled, discovered_at
        FROM public.organizer_mcp_server_tools
        WHERE mcp_server_id = :sid AND organizer_id = :org
        ORDER BY tool_name
    ");
    $stmt->execute([':sid' => $serverId, ':org' => $organizerId]);

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function mcpUpdateServerTool(PDO $db, int $serverId, int $toolId, array $body): void
{
    $operator = AuthMiddleware::requireAuth($db, ['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $setClauses = ['updated_at = NOW()'];
    $params = [':id' => $toolId, ':sid' => $serverId, ':org' => $organizerId];

    if (array_key_exists('is_enabled', $body)) {
        $setClauses[] = 'is_enabled = :enabled';
        $params[':enabled'] = filter_var($body['is_enabled'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    if (array_key_exists('risk_level', $body)) {
        $risk = strtolower(trim((string)$body['risk_level']));
        if (in_array($risk, ['none', 'read', 'write', 'destructive'], true)) {
            $setClauses[] = 'risk_level = :risk';
            $params[':risk'] = $risk;
        }
    }

    $setStr = implode(', ', $setClauses);
    $db->prepare("UPDATE public.organizer_mcp_server_tools SET {$setStr} WHERE id = :id AND mcp_server_id = :sid AND organizer_id = :org")->execute($params);

    jsonSuccess(null, 'Tool atualizada.');
}
