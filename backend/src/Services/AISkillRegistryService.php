<?php
/**
 * AISkillRegistryService.php
 * Database-driven skill/tool catalog (Skills Warehouse).
 * Replaces hardcoded allToolDefinitions() when FEATURE_AI_SKILL_REGISTRY is enabled.
 * Falls back gracefully when the table does not exist.
 */

namespace EnjoyFun\Services;

use PDO;

require_once __DIR__ . '/EventTemplateService.php';

final class AISkillRegistryService
{
    // ──────────────────────────────────────────────────────────────
    //  Feature flag
    // ──────────────────────────────────────────────────────────────

    public static function isEnabled(): bool
    {
        $flag = getenv('FEATURE_AI_SKILL_REGISTRY');
        return in_array(strtolower((string)$flag), ['1', 'true', 'yes', 'on'], true);
    }

    // ──────────────────────────────────────────────────────────────
    //  Core queries
    // ──────────────────────────────────────────────────────────────

    /**
     * List skills, optionally filtered by surface and/or agent_key.
     */
    public static function listSkills(PDO $db, ?string $surface = null, ?string $agentKey = null): array
    {
        if (!self::tableExists($db)) {
            return [];
        }

        $where = ['s.is_active = TRUE'];
        $params = [];

        if ($agentKey !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM ai_agent_skills ags
                WHERE ags.skill_key = s.skill_key
                  AND ags.agent_key = :agent_key
                  AND ags.is_active = TRUE
            )';
            $params['agent_key'] = $agentKey;
        }

        $sql = 'SELECT s.skill_key, s.label, s.description, s.skill_type,
                       s.input_schema, s.surfaces, s.risk_level, s.source,
                       s.handler_ref, s.aliases, s.is_active,
                       s.created_at, s.updated_at
                FROM ai_skill_registry s
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY s.skill_key ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $skills = array_map([self::class, 'hydrateSkill'], $rows);

        // Post-filter by surface if requested (JSONB contains check in PHP for portability)
        if ($surface !== null) {
            $skills = array_values(array_filter($skills, function ($skill) use ($surface) {
                return in_array($surface, $skill['surfaces'], true);
            }));
        }

        return $skills;
    }

    /**
     * Get a single skill by key.
     */
    public static function getSkill(PDO $db, string $skillKey): ?array
    {
        if (!self::tableExists($db)) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT skill_key, label, description, skill_type, input_schema,
                    surfaces, risk_level, source, handler_ref, aliases,
                    is_active, created_at, updated_at
             FROM ai_skill_registry
             WHERE skill_key = :skill_key'
        );
        $stmt->execute(['skill_key' => $skillKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::hydrateSkill($row) : null;
    }

    /**
     * Build tool catalog for a specific agent, optionally filtered by surface.
     * This is the new version of AIToolRuntimeService::buildToolCatalog().
     *
     * Returns array of tool definitions in the same format expected by the orchestrator:
     * [['name' => ..., 'description' => ..., 'input_schema' => ..., 'type' => ..., ...], ...]
     */
    public static function buildToolCatalogForAgent(
        PDO $db,
        int $organizerId,
        array $context
    ): array {
        $surface = strtolower(trim((string)($context['surface'] ?? '')));
        $agentKey = strtolower(trim((string)($context['agent_key'] ?? '')));
        $eventId = (int)($context['event_id'] ?? 0);

        if ($eventId <= 0) {
            return [];
        }

        // Get skills for this agent
        $skills = self::listSkills($db, $surface ?: null, $agentKey ?: null);

        // ── Event Template filtering ──────────────────────────────────
        // If the event has a template (event_type), only expose skills
        // mapped to that template. No template = ALL skills (backward compat).
        $eventType = strtolower(trim((string)($context['event_type'] ?? '')));
        if ($eventType !== '' && $eventId > 0) {
            $templateSkills = EventTemplateService::resolveSkillsForEvent($db, $organizerId, $eventId);
            if (!empty($templateSkills)) {
                $skills = array_values(array_filter($skills, function ($skill) use ($templateSkills) {
                    return in_array($skill['skill_key'], $templateSkills, true);
                }));
            }
        }

        // Check write tools feature flag
        $writeToolsEnabled = in_array(
            strtolower((string)getenv('FEATURE_AI_TOOL_WRITE')),
            ['1', 'true', 'yes', 'on'],
            true
        );

        // Build a lookup of canonical hardcoded definitions for input_schema enrichment
        // (the registry stores metadata but the canonical schemas live in the PHP code)
        $canonicalDefs = self::loadCanonicalDefinitions();

        // Convert skills to the tool format expected by the orchestrator
        $tools = [];
        foreach ($skills as $skill) {
            // Skip write skills if write tools are disabled
            if ($skill['skill_type'] === 'write' && !$writeToolsEnabled) {
                continue;
            }

            // Check per-tool rollout flags
            $rolloutFlags = [
                'update_timeline_checkpoint' => 'FEATURE_AI_WRITE_TIMELINE_CHECKPOINT',
            ];
            if (isset($rolloutFlags[$skill['skill_key']])) {
                $toolFlag = getenv($rolloutFlags[$skill['skill_key']]);
                if (!in_array(strtolower((string)$toolFlag), ['1', 'true', 'yes', 'on'], true)) {
                    continue;
                }
            }

            // Enrich from canonical hardcoded definition if available
            $canonical = $canonicalDefs[$skill['skill_key']] ?? null;
            $inputSchema = $skill['input_schema'];
            if ($canonical && (empty($inputSchema) || empty($inputSchema['type']))) {
                $inputSchema = $canonical['input_schema'] ?? $inputSchema;
            }
            // Final safety: ensure minimum valid JSON Schema for OpenAI
            if (empty($inputSchema) || !is_array($inputSchema) || empty($inputSchema['type'])) {
                $inputSchema = [
                    'type' => 'object',
                    'properties' => (object)[],
                    'additionalProperties' => false,
                ];
            }

            $tools[] = [
                'name'         => $skill['skill_key'],
                'description'  => $skill['description'],
                'input_schema' => $inputSchema,
                'aliases'      => $canonical['aliases'] ?? $skill['aliases'],
                'type'         => $skill['skill_type'] === 'generate' ? 'read' : $skill['skill_type'],
                'surfaces'     => $skill['surfaces'],
                'agent_keys'   => self::getAgentKeysForSkill($db, $skill['skill_key']),
                'risk_level'   => $skill['risk_level'],
                'source'       => $skill['source'],
                'handler_ref'  => $skill['handler_ref'],
            ];
        }

        // Merge MCP tools if available
        if ($organizerId > 0) {
            $mcpTools = self::loadMCPSkills($db, $organizerId, $surface, $agentKey);
            $tools = array_merge($tools, $mcpTools);
        }

        return $tools;
    }

    /**
     * Get all agent_keys assigned to a skill.
     */
    public static function getAgentKeysForSkill(PDO $db, string $skillKey): array
    {
        $stmt = $db->prepare(
            'SELECT agent_key FROM ai_agent_skills
             WHERE skill_key = :skill_key AND is_active = TRUE
             ORDER BY priority DESC'
        );
        $stmt->execute(['skill_key' => $skillKey]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get all skill_keys assigned to an agent.
     */
    public static function getSkillKeysForAgent(PDO $db, string $agentKey): array
    {
        $stmt = $db->prepare(
            'SELECT skill_key FROM ai_agent_skills
             WHERE agent_key = :agent_key AND is_active = TRUE
             ORDER BY priority DESC'
        );
        $stmt->execute(['agent_key' => $agentKey]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ──────────────────────────────────────────────────────────────
    //  MCP skill loading
    // ──────────────────────────────────────────────────────────────

    /**
     * Load MCP tools as skills for a given organizer, filtered by surface/agent.
     */
    private static function loadMCPSkills(PDO $db, int $organizerId, string $surface, string $agentKey): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT t.tool_name, t.tool_description, t.input_schema_json,
                        t.type, t.risk_level, s.id AS server_id, s.label AS server_label,
                        s.allowed_agent_keys, s.allowed_surfaces
                 FROM organizer_mcp_server_tools t
                 JOIN organizer_mcp_servers s ON s.id = t.mcp_server_id
                 WHERE t.organizer_id = :org_id
                   AND t.is_enabled = TRUE
                   AND s.is_active = TRUE'
            );
            $stmt->execute(['org_id' => $organizerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $tools = [];
        foreach ($rows as $row) {
            // Check surface filter
            $allowedSurfaces = json_decode($row['allowed_surfaces'] ?: '[]', true) ?: [];
            if (!empty($allowedSurfaces) && $surface && !in_array($surface, $allowedSurfaces, true)) {
                continue;
            }

            // Check agent filter
            $allowedAgents = json_decode($row['allowed_agent_keys'] ?: '[]', true) ?: [];
            if (!empty($allowedAgents) && $agentKey && !in_array($agentKey, $allowedAgents, true)) {
                continue;
            }

            $tools[] = [
                'name'         => 'mcp_' . $row['tool_name'],
                'description'  => $row['tool_description'] ?? '',
                'input_schema' => json_decode($row['input_schema_json'] ?: '{}', true) ?: [],
                'aliases'      => [],
                'type'         => $row['type'] ?? 'read',
                'surfaces'     => $allowedSurfaces,
                'agent_keys'   => $allowedAgents,
                'risk_level'   => $row['risk_level'] ?? 'write',
                'source'       => 'mcp',
                'handler_ref'  => null,
                'mcp_server_id' => (int)$row['server_id'],
            ];
        }

        return $tools;
    }

    // ──────────────────────────────────────────────────────────────
    //  Write operations
    // ──────────────────────────────────────────────────────────────

    /**
     * Import MCP tools into the skill registry.
     */
    public static function importMCPSkills(PDO $db, int $organizerId, int $mcpServerId, array $discoveredTools): int
    {
        $imported = 0;
        foreach ($discoveredTools as $tool) {
            $skillKey = 'mcp_' . ($tool['name'] ?? '');
            if (empty($skillKey) || $skillKey === 'mcp_') {
                continue;
            }

            $stmt = $db->prepare(
                'INSERT INTO ai_skill_registry
                    (skill_key, label, description, skill_type, input_schema, surfaces,
                     risk_level, source, mcp_server_id, handler_ref)
                 VALUES
                    (:skill_key, :label, :description, :skill_type, :input_schema, :surfaces,
                     :risk_level, :source, :mcp_server_id, NULL)
                 ON CONFLICT (skill_key) DO UPDATE SET
                    description = EXCLUDED.description,
                    input_schema = EXCLUDED.input_schema,
                    updated_at = NOW()'
            );
            $stmt->execute([
                'skill_key'     => $skillKey,
                'label'         => $tool['name'],
                'description'   => $tool['description'] ?? '',
                'skill_type'    => $tool['type'] ?? 'read',
                'input_schema'  => json_encode($tool['inputSchema'] ?? $tool['input_schema'] ?? []),
                'surfaces'      => json_encode($tool['surfaces'] ?? []),
                'risk_level'    => $tool['risk_level'] ?? 'write',
                'source'        => 'mcp',
                'mcp_server_id' => $mcpServerId,
            ]);
            $imported++;
        }
        return $imported;
    }

    /**
     * Assign a skill to an agent.
     */
    public static function assignSkillToAgent(PDO $db, string $agentKey, string $skillKey, int $priority = 50): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ai_agent_skills (agent_key, skill_key, priority)
             VALUES (:agent_key, :skill_key, :priority)
             ON CONFLICT (agent_key, skill_key) DO UPDATE SET
                priority = EXCLUDED.priority, is_active = TRUE'
        );
        $stmt->execute([
            'agent_key' => $agentKey,
            'skill_key' => $skillKey,
            'priority'  => $priority,
        ]);
    }

    /**
     * Remove a skill from an agent (soft disable).
     */
    public static function removeSkillFromAgent(PDO $db, string $agentKey, string $skillKey): void
    {
        $stmt = $db->prepare(
            'UPDATE ai_agent_skills SET is_active = FALSE
             WHERE agent_key = :agent_key AND skill_key = :skill_key'
        );
        $stmt->execute(['agent_key' => $agentKey, 'skill_key' => $skillKey]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Load canonical tool definitions from AIToolRuntimeService.
     * Used to enrich DB skills with the exact input_schema.
     * Cached per-request.
     */
    private static function loadCanonicalDefinitions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        // AIToolRuntimeService requires this file, so it's guaranteed to be loaded
        // when buildToolCatalogForAgent() is called from buildToolCatalog().
        if (class_exists('\\EnjoyFun\\Services\\AIToolRuntimeService')
            && method_exists('\\EnjoyFun\\Services\\AIToolRuntimeService', 'getCanonicalToolDefinitions')) {
            $cache = \EnjoyFun\Services\AIToolRuntimeService::getCanonicalToolDefinitions();
        } else {
            $cache = [];
        }
        return $cache;
    }

    private static function hydrateSkill(array $row): array
    {
        $row['input_schema'] = json_decode($row['input_schema'] ?: '{}', true) ?: [];
        $row['surfaces'] = json_decode($row['surfaces'] ?: '[]', true) ?: [];
        $row['aliases'] = json_decode($row['aliases'] ?: '[]', true) ?: [];
        $row['is_active'] = (bool)$row['is_active'];
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
                 WHERE table_schema = 'public' AND table_name = 'ai_skill_registry'
                 LIMIT 1"
            );
            $exists = (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}
