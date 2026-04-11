<?php
/**
 * AIAgentRegistryService.php
 * Database-driven agent catalog. Replaces hardcoded agentMetadata() when
 * FEATURE_AI_AGENT_REGISTRY is enabled. Falls back to hardcoded catalog
 * when the table does not exist or the flag is off.
 */

namespace EnjoyFun\Services;

use PDO;

final class AIAgentRegistryService
{
    // ──────────────────────────────────────────────────────────────
    //  Feature flag check
    // ──────────────────────────────────────────────────────────────

    public static function isEnabled(): bool
    {
        $flag = getenv('FEATURE_AI_AGENT_REGISTRY');
        return in_array(strtolower((string)$flag), ['1', 'true', 'yes', 'on'], true);
    }

    // ──────────────────────────────────────────────────────────────
    //  Core queries
    // ──────────────────────────────────────────────────────────────

    /**
     * List all agents from the registry.
     * Returns array of agent rows, ordered by display_order.
     */
    public static function listAgents(PDO $db): array
    {
        if (!self::tableExists($db)) {
            return [];
        }

        $stmt = $db->query(
            'SELECT agent_key, label, label_friendly, description, icon_key,
                    surfaces, supports_write, system_prompt, default_provider,
                    display_order, is_system, is_visible, created_at, updated_at
             FROM ai_agent_registry
             WHERE is_visible = TRUE
             ORDER BY display_order ASC, agent_key ASC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'hydrateAgent'], $rows);
    }

    /**
     * Get a single agent by key.
     */
    public static function getAgent(PDO $db, string $agentKey): ?array
    {
        if (!self::tableExists($db)) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT agent_key, label, label_friendly, description, icon_key,
                    surfaces, supports_write, system_prompt, default_provider,
                    display_order, is_system, is_visible, created_at, updated_at
             FROM ai_agent_registry
             WHERE agent_key = :agent_key'
        );
        $stmt->execute(['agent_key' => $agentKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::hydrateAgent($row) : null;
    }

    /**
     * List agents merged with per-organizer overrides from organizer_ai_agents.
     * Returns the registry agents with tenant-specific config overlaid.
     */
    public static function listAgentsForOrganizer(PDO $db, int $organizerId): array
    {
        $registryAgents = self::listAgents($db);
        if (empty($registryAgents)) {
            return [];
        }

        // Load tenant overrides
        $stmt = $db->prepare(
            'SELECT agent_key, provider, is_enabled, approval_mode, config_json,
                    created_at, updated_at
             FROM organizer_ai_agents
             WHERE organizer_id = :org_id'
        );
        $stmt->execute(['org_id' => $organizerId]);
        $tenantRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tenantByKey = [];
        foreach ($tenantRows as $row) {
            $tenantByKey[$row['agent_key']] = $row;
        }

        // Merge: registry provides the base, tenant provides overrides
        $merged = [];
        foreach ($registryAgents as $agent) {
            $key = $agent['agent_key'];
            $tenant = $tenantByKey[$key] ?? null;

            $agent['is_enabled']     = $tenant ? (bool)$tenant['is_enabled'] : true;
            $agent['approval_mode']  = $tenant['approval_mode'] ?? 'confirm_write';
            $agent['tenant_provider'] = $tenant['provider'] ?? null;
            $agent['config_json']    = $tenant ? json_decode($tenant['config_json'] ?: '{}', true) : [];
            $agent['source']         = $agent['is_system'] ? 'catalog' : 'tenant';
            $agent['tenant_configured'] = $tenant !== null;

            $merged[] = $agent;
        }

        return $merged;
    }

    /**
     * Find agents that match a given surface.
     */
    public static function findAgentsForSurface(PDO $db, string $surface): array
    {
        $agents = self::listAgents($db);
        return array_values(array_filter($agents, function ($agent) use ($surface) {
            return in_array($surface, $agent['surfaces'], true);
        }));
    }

    // ──────────────────────────────────────────────────────────────
    //  Write operations (for future admin UI)
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a custom agent in the registry.
     */
    public static function createAgent(PDO $db, array $data): array
    {
        $stmt = $db->prepare(
            'INSERT INTO ai_agent_registry
                (agent_key, label, label_friendly, description, icon_key, surfaces,
                 supports_write, system_prompt, default_provider, display_order, is_system, is_visible)
             VALUES
                (:agent_key, :label, :label_friendly, :description, :icon_key, :surfaces,
                 :supports_write, :system_prompt, :default_provider, :display_order, FALSE, TRUE)
             RETURNING agent_key'
        );
        $stmt->execute([
            'agent_key'        => $data['agent_key'],
            'label'            => $data['label'],
            'label_friendly'   => $data['label_friendly'] ?? $data['label'],
            'description'      => $data['description'] ?? '',
            'icon_key'         => $data['icon_key'] ?? 'bot',
            'surfaces'         => json_encode($data['surfaces'] ?? []),
            'supports_write'   => $data['supports_write'] ?? false ? 'true' : 'false',
            'system_prompt'    => $data['system_prompt'] ?? null,
            'default_provider' => $data['default_provider'] ?? null,
            'display_order'    => $data['display_order'] ?? 200,
        ]);

        return self::getAgent($db, $data['agent_key']);
    }

    /**
     * Update an existing agent's metadata.
     */
    public static function updateAgent(PDO $db, string $agentKey, array $data): ?array
    {
        $fields = [];
        $params = ['agent_key' => $agentKey];

        $allowed = ['label', 'label_friendly', 'description', 'icon_key', 'system_prompt',
                     'default_provider', 'display_order', 'is_visible'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (array_key_exists('surfaces', $data)) {
            $fields[] = 'surfaces = :surfaces';
            $params['surfaces'] = json_encode($data['surfaces']);
        }

        if (array_key_exists('supports_write', $data)) {
            $fields[] = 'supports_write = :supports_write';
            $params['supports_write'] = $data['supports_write'] ? 'true' : 'false';
        }

        if (empty($fields)) {
            return self::getAgent($db, $agentKey);
        }

        $fields[] = "updated_at = NOW()";
        $sql = 'UPDATE ai_agent_registry SET ' . implode(', ', $fields) . ' WHERE agent_key = :agent_key';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return self::getAgent($db, $agentKey);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private static function hydrateAgent(array $row): array
    {
        $row['surfaces'] = json_decode($row['surfaces'] ?: '[]', true) ?: [];
        $row['supports_write'] = (bool)$row['supports_write'];
        $row['is_system'] = (bool)$row['is_system'];
        $row['is_visible'] = (bool)$row['is_visible'];
        $row['display_order'] = (int)$row['display_order'];
        return $row;
    }

    private static function tableExists(PDO $db): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $stmt = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'ai_agent_registry'
                 LIMIT 1"
            );
            $exists = (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}
