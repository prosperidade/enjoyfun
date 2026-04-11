<?php
/**
 * EventTemplateService.php
 * Manages event templates (system + organizer-custom) and auto-activates
 * the right AI skills/agents when an event is created or updated.
 *
 * System templates (organizer_id IS NULL) are available to all organizers.
 * Custom templates inherit from a system template and can add/remove skills.
 */

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

final class EventTemplateService
{
    // ──────────────────────────────────────────────────────────────
    //  Read operations
    // ──────────────────────────────────────────────────────────────

    /**
     * List all templates available to an organizer:
     * all system templates + their own custom templates.
     */
    public static function listTemplates(PDO $db, int $organizerId): array
    {
        if (!self::tableExists($db)) {
            return self::fallbackSystemTemplates();
        }

        $stmt = $db->prepare('
            SELECT t.*,
                   (SELECT COUNT(*) FROM public.event_template_skills ts WHERE ts.template_id = t.id) AS skills_count,
                   (SELECT COUNT(*) FROM public.event_template_agents ta WHERE ta.template_id = t.id) AS agents_count
            FROM public.event_templates t
            WHERE t.is_active = TRUE
              AND (t.organizer_id IS NULL OR t.organizer_id = :organizer_id)
            ORDER BY t.is_system DESC, t.display_order ASC, t.label ASC
        ');
        $stmt->execute([':organizer_id' => $organizerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([self::class, 'hydrateTemplate'], $rows);
    }

    /**
     * Get a single template with its skills and agents.
     */
    public static function getTemplate(PDO $db, string $templateKey, ?int $organizerId = null): ?array
    {
        if (!self::tableExists($db)) {
            return null;
        }

        // Prefer organizer-custom version, fall back to system
        $stmt = $db->prepare('
            SELECT t.*
            FROM public.event_templates t
            WHERE t.template_key = :template_key
              AND t.is_active = TRUE
              AND (t.organizer_id IS NULL OR t.organizer_id = :organizer_id)
            ORDER BY t.organizer_id IS NOT NULL DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':template_key' => $templateKey,
            ':organizer_id' => $organizerId ?? 0,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $template = self::hydrateTemplate($row);
        $templateId = (int)$row['id'];

        // Load skills
        $skillStmt = $db->prepare('
            SELECT ts.skill_key, ts.is_required, ts.priority,
                   sr.label, sr.description, sr.skill_type, sr.surfaces
            FROM public.event_template_skills ts
            JOIN public.ai_skill_registry sr ON sr.skill_key = ts.skill_key AND sr.is_active = TRUE
            WHERE ts.template_id = :template_id
            ORDER BY ts.priority DESC, sr.label ASC
        ');
        $skillStmt->execute([':template_id' => $templateId]);
        $template['skills'] = array_map(function (array $row): array {
            $row['is_required'] = (bool)$row['is_required'];
            $row['surfaces'] = json_decode($row['surfaces'] ?: '[]', true) ?: [];
            return $row;
        }, $skillStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        // Load agents
        $agentStmt = $db->prepare('
            SELECT ta.agent_key, ta.is_primary, ta.display_order,
                   ar.label, ar.label_friendly, ar.description, ar.icon_key
            FROM public.event_template_agents ta
            JOIN public.ai_agent_registry ar ON ar.agent_key = ta.agent_key
            WHERE ta.template_id = :template_id
            ORDER BY ta.display_order ASC
        ');
        $agentStmt->execute([':template_id' => $templateId]);
        $template['agents'] = array_map(function (array $row): array {
            $row['is_primary'] = (bool)$row['is_primary'];
            return $row;
        }, $agentStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return $template;
    }

    /**
     * Resolve which skill_keys are active for a given event, based on its
     * event_type (template_key) and organizer custom overrides.
     *
     * Returns empty array if no template → caller should allow ALL skills.
     */
    public static function resolveSkillsForEvent(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db)) {
            return [];
        }

        // Get event_type from the event
        $eventType = self::resolveEventType($db, $organizerId, $eventId);
        if ($eventType === null || $eventType === '') {
            return []; // No template — all skills allowed
        }

        // Find the best matching template (custom first, then system)
        $stmt = $db->prepare('
            SELECT t.id
            FROM public.event_templates t
            WHERE t.template_key = :template_key
              AND t.is_active = TRUE
              AND (t.organizer_id IS NULL OR t.organizer_id = :organizer_id)
            ORDER BY t.organizer_id IS NOT NULL DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':template_key' => $eventType,
            ':organizer_id' => $organizerId,
        ]);
        $templateId = $stmt->fetchColumn();

        if (!$templateId) {
            return []; // Template not found — all skills allowed
        }

        // Get active skill_keys for this template
        $skillStmt = $db->prepare('
            SELECT ts.skill_key
            FROM public.event_template_skills ts
            JOIN public.ai_skill_registry sr ON sr.skill_key = ts.skill_key AND sr.is_active = TRUE
            WHERE ts.template_id = :template_id
            ORDER BY ts.priority DESC
        ');
        $skillStmt->execute([':template_id' => (int)$templateId]);

        return $skillStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Resolve the event_type for an event.
     */
    public static function resolveEventType(PDO $db, int $organizerId, int $eventId): ?string
    {
        if ($eventId <= 0) {
            return null;
        }

        try {
            // Check if column exists
            $colCheck = $db->query("
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'events' AND column_name = 'event_type'
                LIMIT 1
            ");
            if (!$colCheck->fetchColumn()) {
                return null;
            }

            $stmt = $db->prepare('
                SELECT event_type FROM public.events
                WHERE id = :event_id AND organizer_id = :organizer_id
                LIMIT 1
            ');
            $stmt->execute([':event_id' => $eventId, ':organizer_id' => $organizerId]);
            $result = $stmt->fetchColumn();

            return ($result !== false && $result !== null && trim((string)$result) !== '')
                ? strtolower(trim((string)$result))
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Write operations
    // ──────────────────────────────────────────────────────────────

    /**
     * Apply a template to an event: set event_type and return activated skills/agents.
     */
    public static function applyTemplateToEvent(
        PDO $db,
        int $organizerId,
        int $eventId,
        string $templateKey
    ): array {
        if (!self::tableExists($db)) {
            return ['applied' => false, 'reason' => 'templates_not_available'];
        }

        $template = self::getTemplate($db, $templateKey, $organizerId);
        if ($template === null) {
            return ['applied' => false, 'reason' => 'template_not_found'];
        }

        // Update event_type on the event
        try {
            $colCheck = $db->query("
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'events' AND column_name = 'event_type'
                LIMIT 1
            ");
            if ($colCheck->fetchColumn()) {
                $stmt = $db->prepare('
                    UPDATE public.events
                    SET event_type = :event_type
                    WHERE id = :event_id AND organizer_id = :organizer_id
                ');
                $stmt->execute([
                    ':event_type'    => $templateKey,
                    ':event_id'      => $eventId,
                    ':organizer_id'  => $organizerId,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[EventTemplateService] Failed to set event_type: ' . $e->getMessage());
        }

        return [
            'applied'       => true,
            'template_key'  => $templateKey,
            'template_label' => $template['label'],
            'skills_activated' => array_map(fn($s) => [
                'key'   => $s['skill_key'],
                'label' => $s['label'],
            ], $template['skills'] ?? []),
            'agents_recommended' => array_map(fn($a) => [
                'key'        => $a['agent_key'],
                'label'      => $a['label_friendly'] ?? $a['label'],
                'is_primary' => $a['is_primary'],
            ], $template['agents'] ?? []),
        ];
    }

    /**
     * Clone a system template as a custom organizer template.
     * The organizer can then add/remove skills from their copy.
     */
    public static function cloneTemplateForOrganizer(
        PDO $db,
        int $organizerId,
        string $sourceTemplateKey,
        string $customLabel
    ): array {
        if (!self::tableExists($db)) {
            throw new RuntimeException('Templates not available — migration 063 required.', 501);
        }

        $source = self::getTemplate($db, $sourceTemplateKey);
        if ($source === null || !$source['is_system']) {
            throw new RuntimeException('Template system "' . $sourceTemplateKey . '" nao encontrado.', 404);
        }

        // Check if organizer already has a custom version
        $existsStmt = $db->prepare('
            SELECT id FROM public.event_templates
            WHERE template_key = :template_key AND organizer_id = :organizer_id
        ');
        $existsStmt->execute([
            ':template_key' => $sourceTemplateKey,
            ':organizer_id' => $organizerId,
        ]);
        if ($existsStmt->fetchColumn()) {
            throw new RuntimeException('Voce ja tem uma versao personalizada deste template.', 409);
        }

        $db->beginTransaction();
        try {
            // Create custom template
            $insertStmt = $db->prepare('
                INSERT INTO public.event_templates
                    (template_key, organizer_id, parent_template_key, label, description,
                     icon_key, color, default_surfaces, default_modules, config_defaults,
                     is_system, display_order)
                VALUES
                    (:template_key, :organizer_id, :parent_key, :label, :description,
                     :icon_key, :color, :surfaces, :modules, :config,
                     FALSE, :display_order)
                RETURNING id
            ');
            $insertStmt->execute([
                ':template_key'  => $sourceTemplateKey,
                ':organizer_id'  => $organizerId,
                ':parent_key'    => $sourceTemplateKey,
                ':label'         => trim($customLabel) !== '' ? trim($customLabel) : $source['label'] . ' (Personalizado)',
                ':description'   => $source['description'],
                ':icon_key'      => $source['icon_key'],
                ':color'         => $source['color'],
                ':surfaces'      => json_encode($source['default_surfaces']),
                ':modules'       => json_encode($source['default_modules']),
                ':config'        => json_encode($source['config_defaults']),
                ':display_order' => $source['display_order'] + 1,
            ]);
            $newTemplateId = (int)$insertStmt->fetchColumn();

            // Copy skills
            foreach ($source['skills'] ?? [] as $skill) {
                $db->prepare('
                    INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
                    VALUES (:template_id, :skill_key, :is_required, :priority)
                    ON CONFLICT (template_id, skill_key) DO NOTHING
                ')->execute([
                    ':template_id' => $newTemplateId,
                    ':skill_key'   => $skill['skill_key'],
                    ':is_required' => $skill['is_required'] ? 1 : 0,
                    ':priority'    => $skill['priority'],
                ]);
            }

            // Copy agents
            foreach ($source['agents'] ?? [] as $agent) {
                $db->prepare('
                    INSERT INTO public.event_template_agents (template_id, agent_key, is_primary, display_order)
                    VALUES (:template_id, :agent_key, :is_primary, :display_order)
                    ON CONFLICT (template_id, agent_key) DO NOTHING
                ')->execute([
                    ':template_id'  => $newTemplateId,
                    ':agent_key'    => $agent['agent_key'],
                    ':is_primary'   => $agent['is_primary'] ? 1 : 0,
                    ':display_order' => $agent['display_order'],
                ]);
            }

            $db->commit();

            return [
                'cloned'       => true,
                'template_id'  => $newTemplateId,
                'template_key' => $sourceTemplateKey,
                'label'        => trim($customLabel) !== '' ? trim($customLabel) : $source['label'] . ' (Personalizado)',
                'parent'       => $sourceTemplateKey,
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Toggle a skill on/off in an organizer's custom template.
     */
    public static function toggleSkillInCustomTemplate(
        PDO $db,
        int $organizerId,
        string $templateKey,
        string $skillKey,
        bool $enable
    ): array {
        // Find the organizer's custom template
        $stmt = $db->prepare('
            SELECT id FROM public.event_templates
            WHERE template_key = :template_key
              AND organizer_id = :organizer_id
              AND is_system = FALSE
        ');
        $stmt->execute([
            ':template_key' => $templateKey,
            ':organizer_id' => $organizerId,
        ]);
        $templateId = (int)$stmt->fetchColumn();

        if ($templateId <= 0) {
            throw new RuntimeException('Template customizado nao encontrado. Clone um template system primeiro.', 404);
        }

        if ($enable) {
            // Add skill
            $db->prepare('
                INSERT INTO public.event_template_skills (template_id, skill_key, is_required, priority)
                VALUES (:template_id, :skill_key, FALSE, 50)
                ON CONFLICT (template_id, skill_key) DO NOTHING
            ')->execute([
                ':template_id' => $templateId,
                ':skill_key'   => $skillKey,
            ]);
        } else {
            // Remove skill
            $db->prepare('
                DELETE FROM public.event_template_skills
                WHERE template_id = :template_id AND skill_key = :skill_key AND is_required = FALSE
            ')->execute([
                ':template_id' => $templateId,
                ':skill_key'   => $skillKey,
            ]);
        }

        return [
            'skill_key' => $skillKey,
            'enabled'   => $enable,
            'template_key' => $templateKey,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private static function hydrateTemplate(array $row): array
    {
        return [
            'id'               => (int)$row['id'],
            'template_key'     => (string)$row['template_key'],
            'organizer_id'     => $row['organizer_id'] !== null ? (int)$row['organizer_id'] : null,
            'parent_template_key' => $row['parent_template_key'] ?? null,
            'label'            => (string)$row['label'],
            'description'      => (string)($row['description'] ?? ''),
            'icon_key'         => (string)($row['icon_key'] ?? 'calendar'),
            'color'            => (string)($row['color'] ?? '#6366f1'),
            'default_surfaces' => json_decode($row['default_surfaces'] ?? '[]', true) ?: [],
            'default_modules'  => json_decode($row['default_modules'] ?? '[]', true) ?: [],
            'config_defaults'  => json_decode($row['config_defaults'] ?? '{}', true) ?: [],
            'is_system'        => (bool)$row['is_system'],
            'is_active'        => (bool)($row['is_active'] ?? true),
            'display_order'    => (int)($row['display_order'] ?? 100),
            'skills_count'     => (int)($row['skills_count'] ?? 0),
            'agents_count'     => (int)($row['agents_count'] ?? 0),
        ];
    }

    /**
     * Fallback template list when the table doesn't exist.
     * Ensures backward compatibility.
     */
    private static function fallbackSystemTemplates(): array
    {
        return [
            [
                'id' => 0,
                'template_key' => 'festival',
                'organizer_id' => null,
                'parent_template_key' => null,
                'label' => 'Festival / Show',
                'description' => 'Para festivais de musica, shows, raves e festas.',
                'icon_key' => 'music',
                'color' => '#8b5cf6',
                'default_surfaces' => [],
                'default_modules' => [],
                'config_defaults' => [],
                'is_system' => true,
                'is_active' => true,
                'display_order' => 10,
                'skills_count' => 33,
                'agents_count' => 12,
            ],
        ];
    }

    private static function tableExists(PDO $db): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $stmt = $db->query("
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'event_templates'
                LIMIT 1
            ");
            $exists = (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}
