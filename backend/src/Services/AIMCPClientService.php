<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/SecretCryptoService.php';

final class AIMCPClientService
{
    private const DISCOVERY_TIMEOUT_SECONDS = 10;
    private const EXECUTION_TIMEOUT_SECONDS = 15;

    /**
     * Discover tools from an MCP server and cache them in the database.
     */
    public static function discoverTools(PDO $db, int $organizerId, int $serverId): array
    {
        $server = self::requireServer($db, $organizerId, $serverId);
        $url = rtrim($server['server_url'], '/') . '/mcp/tools/list';

        $response = self::httpRequest('POST', $url, [], self::buildAuthHeaders($server), self::DISCOVERY_TIMEOUT_SECONDS);

        if (!is_array($response) || !isset($response['tools']) || !is_array($response['tools'])) {
            self::updateDiscoveryStatus($db, $serverId, 'failed');
            throw new RuntimeException('MCP server nao retornou uma lista de tools valida.', 502);
        }

        $db->beginTransaction();
        try {
            // Remove old tools not in the new list
            $existingStmt = $db->prepare("SELECT tool_name FROM public.organizer_mcp_server_tools WHERE mcp_server_id = :sid AND organizer_id = :org");
            $existingStmt->execute([':sid' => $serverId, ':org' => $organizerId]);
            $existingNames = array_column($existingStmt->fetchAll(PDO::FETCH_ASSOC), 'tool_name');

            $newNames = array_map(static fn(array $t): string => trim((string)($t['name'] ?? '')), $response['tools']);
            $toRemove = array_diff($existingNames, $newNames);

            if ($toRemove !== []) {
                $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
                $deleteStmt = $db->prepare("DELETE FROM public.organizer_mcp_server_tools WHERE mcp_server_id = ? AND organizer_id = ? AND tool_name IN ({$placeholders})");
                $deleteStmt->execute(array_merge([$serverId, $organizerId], array_values($toRemove)));
            }

            // Upsert discovered tools
            $upsertStmt = $db->prepare("
                INSERT INTO public.organizer_mcp_server_tools (mcp_server_id, organizer_id, tool_name, tool_description, input_schema_json, type, risk_level, discovered_at, updated_at)
                VALUES (:sid, :org, :name, :desc, :schema, :type, 'write', NOW(), NOW())
                ON CONFLICT (mcp_server_id, tool_name) DO UPDATE SET
                    tool_description = EXCLUDED.tool_description,
                    input_schema_json = EXCLUDED.input_schema_json,
                    type = EXCLUDED.type,
                    discovered_at = NOW(),
                    updated_at = NOW()
            ");

            $count = 0;
            foreach ($response['tools'] as $tool) {
                $name = trim((string)($tool['name'] ?? ''));
                if ($name === '') continue;

                $upsertStmt->execute([
                    ':sid' => $serverId,
                    ':org' => $organizerId,
                    ':name' => $name,
                    ':desc' => trim((string)($tool['description'] ?? '')),
                    ':schema' => json_encode($tool['inputSchema'] ?? $tool['input_schema'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                    ':type' => self::inferToolType($name, $tool),
                ]);
                $count++;
            }

            self::updateDiscoveryStatus($db, $serverId, 'succeeded');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            self::updateDiscoveryStatus($db, $serverId, 'failed');
            throw $e;
        }

        return ['discovered_count' => $count, 'removed_count' => count($toRemove ?? [])];
    }

    /**
     * Execute a tool call on an external MCP server.
     */
    public static function executeToolCall(PDO $db, int $organizerId, int $mcpServerId, string $toolName, array $arguments): array
    {
        $server = self::requireServer($db, $organizerId, $mcpServerId);
        $url = rtrim($server['server_url'], '/') . '/mcp/tools/call';

        $payload = [
            'name' => $toolName,
            'arguments' => $arguments,
        ];

        $response = self::httpRequest('POST', $url, $payload, self::buildAuthHeaders($server), self::EXECUTION_TIMEOUT_SECONDS);

        if (!is_array($response)) {
            throw new RuntimeException("MCP server retornou resposta invalida para tool '{$toolName}'.", 502);
        }

        // Extract text content from MCP standard response format
        $content = $response['content'] ?? $response['result'] ?? $response;
        if (is_array($content)) {
            $textParts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $textParts[] = $part['text'] ?? '';
                }
            }
            if ($textParts !== []) {
                return ['mcp_result' => implode("\n", $textParts), 'raw' => $content];
            }
        }

        return ['mcp_result' => $content, 'raw' => $response];
    }

    /**
     * Build MCP tool catalog for the tool runtime, filtered by surface and agent.
     */
    public static function buildMCPToolCatalog(PDO $db, int $organizerId, string $surface, string $agentKey): array
    {
        if (!self::tableExists($db, 'organizer_mcp_servers')) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT t.tool_name, t.tool_description, t.input_schema_json, t.type, t.risk_level, t.mcp_server_id,
                   s.label AS server_label, s.allowed_agent_keys, s.allowed_surfaces
            FROM public.organizer_mcp_server_tools t
            JOIN public.organizer_mcp_servers s ON s.id = t.mcp_server_id AND s.organizer_id = t.organizer_id
            WHERE t.organizer_id = :org AND t.is_enabled = true AND s.is_active = true
            ORDER BY s.label, t.tool_name
        ");
        $stmt->execute([':org' => $organizerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tools = [];
        foreach ($rows as $row) {
            $allowedAgents = json_decode($row['allowed_agent_keys'] ?? '[]', true) ?: [];
            $allowedSurfaces = json_decode($row['allowed_surfaces'] ?? '[]', true) ?: [];

            $matchesAgent = $allowedAgents === [] || in_array($agentKey, $allowedAgents, true);
            $matchesSurface = $allowedSurfaces === [] || in_array($surface, $allowedSurfaces, true);

            if (!$matchesAgent && !$matchesSurface) {
                continue;
            }

            $tools[] = [
                'name' => 'mcp_' . $row['tool_name'],
                'description' => '[MCP: ' . ($row['server_label'] ?? 'External') . '] ' . ($row['tool_description'] ?? ''),
                'input_schema' => json_decode($row['input_schema_json'] ?? '{}', true) ?: ['type' => 'object', 'properties' => new \stdClass()],
                'aliases' => ['mcp_' . $row['tool_name'], $row['tool_name']],
                'type' => $row['type'] ?? 'read',
                'risk_level' => $row['risk_level'] ?? 'write',
                'source' => 'mcp',
                'mcp_server_id' => (int)$row['mcp_server_id'],
                'mcp_tool_name' => $row['tool_name'],
                'surfaces' => $allowedSurfaces,
                'agent_keys' => $allowedAgents,
            ];
        }

        return $tools;
    }

    // ──────────────────────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────────────────────

    private static function requireServer(PDO $db, int $organizerId, int $serverId): array
    {
        $stmt = $db->prepare("
            SELECT id, server_url, auth_type, encrypted_auth_credential, is_active, label
            FROM public.organizer_mcp_servers
            WHERE id = :id AND organizer_id = :org
            LIMIT 1
        ");
        $stmt->execute([':id' => $serverId, ':org' => $organizerId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$server) {
            throw new RuntimeException('MCP server nao encontrado.', 404);
        }
        if (!filter_var($server['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('MCP server esta desativado.', 409);
        }

        return $server;
    }

    private static function buildAuthHeaders(array $server): array
    {
        $authType = strtolower(trim((string)($server['auth_type'] ?? 'none')));
        $encrypted = trim((string)($server['encrypted_auth_credential'] ?? ''));

        if ($authType === 'none' || $encrypted === '') {
            return [];
        }

        $credential = SecretCryptoService::decrypt($encrypted, 'mcp_server:' . $server['id']);

        return match ($authType) {
            'bearer' => ['Authorization: Bearer ' . $credential],
            'api_key' => ['X-API-Key: ' . $credential],
            'basic' => ['Authorization: Basic ' . base64_encode($credential)],
            default => [],
        };
    }

    private static function httpRequest(string $method, string $url, array $payload, array $extraHeaders, int $timeoutSeconds): mixed
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json',
            ], $extraHeaders),
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($payload !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        // SSL CA bundle detection
        $caBundlePaths = [getenv('AI_CA_BUNDLE'), getenv('CURL_CA_BUNDLE'), getenv('SSL_CERT_FILE')];
        foreach ($caBundlePaths as $path) {
            if ($path && file_exists($path)) {
                curl_setopt($ch, CURLOPT_CAINFO, $path);
                break;
            }
        }

        $rawResponse = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($rawResponse === false || $curlError !== '') {
            throw new RuntimeException("Erro de conexao com MCP server: {$curlError}", 502);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $preview = substr((string)$rawResponse, 0, 200);
            throw new RuntimeException("MCP server retornou HTTP {$httpCode}: {$preview}", 502);
        }

        $decoded = json_decode((string)$rawResponse, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('MCP server retornou resposta nao-JSON.', 502);
        }

        return $decoded;
    }

    private static function updateDiscoveryStatus(PDO $db, int $serverId, string $status): void
    {
        try {
            $db->prepare("UPDATE public.organizer_mcp_servers SET last_discovery_at = NOW(), last_discovery_status = :status, updated_at = NOW() WHERE id = :id")
               ->execute([':status' => $status, ':id' => $serverId]);
        } catch (\Throwable) {
            // Non-critical, don't propagate
        }
    }

    private static function inferToolType(string $name, array $tool): string
    {
        $readHints = ['get', 'list', 'read', 'fetch', 'search', 'find', 'preview', 'analyze', 'summarize'];
        $lower = strtolower($name);
        foreach ($readHints as $hint) {
            if (str_contains($lower, $hint)) {
                return 'read';
            }
        }
        return 'write';
    }

    private static function tableExists(PDO $db, string $tableName): bool
    {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t LIMIT 1");
        $stmt->execute([':t' => $tableName]);
        return (bool)$stmt->fetchColumn();
    }
}
