<?php

namespace EnjoyFun\Services;

use PDO;

require_once __DIR__ . '/../Helpers/WorkforceTreeHelper.php';

final class AIContextBuilderService
{
    public static function buildInsightContext(PDO $db, int $organizerId, array $context): array
    {
        $surface = self::resolveSurface($context);
        $baseContext = array_merge(
            [
                'surface' => $surface !== '' ? $surface : 'general',
                'module' => $surface !== '' ? $surface : 'general',
                'screen' => $surface !== '' ? $surface : 'general',
            ],
            $context
        );

        return match ($surface) {
            'parking' => self::buildParkingContext($db, $organizerId, $baseContext),
            'workforce' => self::buildWorkforceContext($db, $organizerId, $baseContext),
            default => self::buildGenericContext($baseContext),
        };
    }

    public static function listSurfaceBlueprints(): array
    {
        return [
            [
                'surface' => 'parking',
                'label' => 'Parking',
                'status' => 'implemented',
                'agent_key' => 'logistics',
                'context_sources' => ['events', 'parking_records'],
                'output_focus' => ['fluxo de entrada/saida', 'gargalos de portaria', 'pressao operacional'],
            ],
            [
                'surface' => 'meals-control',
                'label' => 'Meals Control',
                'status' => 'planned',
                'agent_key' => 'logistics',
                'context_sources' => ['event_meal_services', 'participant_meals', 'event_days'],
                'output_focus' => ['janelas de servico', 'saldo operacional', 'anomalias de consumo'],
            ],
            [
                'surface' => 'workforce',
                'label' => 'Workforce',
                'status' => 'implemented',
                'agent_key' => 'logistics',
                'context_sources' => ['workforce_roles', 'workforce_assignments', 'workforce_role_settings', 'workforce_event_roles', 'event_days', 'event_shifts'],
                'output_focus' => ['cobertura de equipe', 'lacunas de lideranca', 'desbalanceamento operacional'],
            ],
            [
                'surface' => 'events',
                'label' => 'Events',
                'status' => 'planned',
                'agent_key' => 'logistics',
                'context_sources' => ['events', 'event_days', 'event_shifts'],
                'output_focus' => ['agenda operacional', 'riscos de calendario', 'virada para encerramento'],
            ],
            [
                'surface' => 'bar',
                'label' => 'POS Bar',
                'status' => 'legacy_context',
                'agent_key' => 'bar',
                'context_sources' => ['sales', 'products', 'stock'],
                'output_focus' => ['ruptura', 'mix', 'ritmo de venda'],
            ],
        ];
    }

    public static function resolveSurface(array $context): string
    {
        foreach ([$context['surface'] ?? null, $context['sector'] ?? null, $context['module'] ?? null, $context['screen'] ?? null] as $candidate) {
            $normalized = self::normalizeSurface((string)$candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private static function buildGenericContext(array $context): array
    {
        $context['surface'] = self::resolveSurface($context) ?: 'general';
        $context['context_origin'] = $context['context_origin'] ?? 'request';
        return $context;
    }

    private static function buildParkingContext(PDO $db, int $organizerId, array $context): array
    {
        $eventId = (int)($context['event_id'] ?? 0);
        $stats = [
            'records_total' => 0,
            'parked_total' => 0,
            'pending_total' => 0,
            'exited_total' => 0,
            'entries_last_hour' => 0,
            'exits_last_hour' => 0,
            'vehicle_mix' => [],
            'recent_records' => [],
        ];
        $eventMeta = [
            'event_id' => $eventId > 0 ? $eventId : null,
            'event_name' => $context['event_name'] ?? null,
            'event_status' => $context['event_status'] ?? null,
            'event_starts_at' => $context['event_starts_at'] ?? null,
            'event_ends_at' => $context['event_ends_at'] ?? null,
        ];

        if ($eventId > 0) {
            $eventStmt = $db->prepare('
                SELECT id, name, status, starts_at, ends_at
                FROM public.events
                WHERE id = :event_id
                  AND organizer_id = :organizer_id
                LIMIT 1
            ');
            $eventStmt->execute([
                ':event_id' => $eventId,
                ':organizer_id' => $organizerId,
            ]);
            $eventRow = $eventStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($eventRow !== []) {
                $eventMeta = [
                    'event_id' => (int)($eventRow['id'] ?? 0),
                    'event_name' => $eventRow['name'] ?? null,
                    'event_status' => $eventRow['status'] ?? null,
                    'event_starts_at' => $eventRow['starts_at'] ?? null,
                    'event_ends_at' => $eventRow['ends_at'] ?? null,
                ];

                $summaryStmt = $db->prepare("
                    SELECT
                        COUNT(*) AS records_total,
                        COUNT(*) FILTER (WHERE status = 'parked') AS parked_total,
                        COUNT(*) FILTER (WHERE status = 'pending') AS pending_total,
                        COUNT(*) FILTER (WHERE status = 'exited') AS exited_total,
                        COUNT(*) FILTER (WHERE entry_at >= NOW() - INTERVAL '1 hour') AS entries_last_hour,
                        COUNT(*) FILTER (WHERE exit_at >= NOW() - INTERVAL '1 hour') AS exits_last_hour
                    FROM public.parking_records
                    WHERE event_id = :event_id
                ");
                $summaryStmt->execute([':event_id' => $eventId]);
                $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $vehicleMixStmt = $db->prepare('
                    SELECT vehicle_type, COUNT(*) AS total
                    FROM public.parking_records
                    WHERE event_id = :event_id
                    GROUP BY vehicle_type
                    ORDER BY total DESC, vehicle_type ASC
                    LIMIT 6
                ');
                $vehicleMixStmt->execute([':event_id' => $eventId]);
                $vehicleMixRows = $vehicleMixStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $recentRecordsStmt = $db->prepare('
                    SELECT license_plate, vehicle_type, status, entry_at, exit_at
                    FROM public.parking_records
                    WHERE event_id = :event_id
                    ORDER BY COALESCE(entry_at, created_at) DESC
                    LIMIT 5
                ');
                $recentRecordsStmt->execute([':event_id' => $eventId]);
                $recentRecordRows = $recentRecordsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $stats = [
                    'records_total' => (int)($summaryRow['records_total'] ?? 0),
                    'parked_total' => (int)($summaryRow['parked_total'] ?? 0),
                    'pending_total' => (int)($summaryRow['pending_total'] ?? 0),
                    'exited_total' => (int)($summaryRow['exited_total'] ?? 0),
                    'entries_last_hour' => (int)($summaryRow['entries_last_hour'] ?? 0),
                    'exits_last_hour' => (int)($summaryRow['exits_last_hour'] ?? 0),
                    'vehicle_mix' => array_map(static function (array $row): array {
                        return [
                            'vehicle_type' => (string)($row['vehicle_type'] ?? 'unknown'),
                            'total' => (int)($row['total'] ?? 0),
                        ];
                    }, $vehicleMixRows),
                    'recent_records' => array_map(static function (array $row): array {
                        return [
                            'license_plate' => (string)($row['license_plate'] ?? ''),
                            'vehicle_type' => (string)($row['vehicle_type'] ?? ''),
                            'status' => (string)($row['status'] ?? ''),
                            'entry_at' => $row['entry_at'] ?? null,
                            'exit_at' => $row['exit_at'] ?? null,
                        ];
                    }, $recentRecordRows),
                ];
            }
        }

        return array_merge($context, $eventMeta, $stats, [
            'surface' => 'parking',
            'module' => 'parking',
            'screen' => 'parking',
            'sector' => 'parking',
            'context_origin' => 'builder',
            'top_products' => [],
            'stock_levels' => [],
        ]);
    }

    private static function buildWorkforceContext(PDO $db, int $organizerId, array $context): array
    {
        $eventId = (int)($context['event_id'] ?? 0);
        $eventMeta = [
            'event_id' => $eventId > 0 ? $eventId : null,
            'event_name' => $context['event_name'] ?? null,
            'event_status' => $context['event_status'] ?? null,
            'event_starts_at' => $context['event_starts_at'] ?? null,
            'event_ends_at' => $context['event_ends_at'] ?? null,
        ];
        $summary = [
            'members_total' => 0,
            'assignments_total' => 0,
            'managerial_assignments_total' => 0,
            'operational_assignments_total' => 0,
            'assignments_with_root_manager' => 0,
            'assignments_missing_bindings' => 0,
            'active_sectors_count' => 0,
        ];
        $structure = [
            'event_days_total' => 0,
            'registered_shifts_total' => 0,
        ];
        $treeStatus = [
            'migration_ready' => false,
            'assignment_bindings_ready' => false,
            'tree_usable' => false,
            'tree_ready' => false,
            'source_preference' => 'legacy',
            'manager_roots_count' => 0,
            'managerial_child_roles_count' => 0,
            'root_sectors_count' => 0,
            'activation_blockers' => [],
        ];
        $topSectors = [];
        $topRoles = [];
        $recentAssignments = [];
        $focusSectorSummary = null;

        $focusSector = self::resolveWorkforceFocusSector($context);
        $selectedManager = [
            'name' => self::nullableText($context['selected_manager_name'] ?? null),
            'role_name' => self::nullableText($context['selected_manager_role_name'] ?? null),
            'sector' => $focusSector !== '' ? $focusSector : null,
            'planned_team_size_hint' => (int)($context['selected_manager_planned_team_size'] ?? 0),
            'filled_team_size_hint' => (int)($context['selected_manager_filled_team_size'] ?? 0),
            'leadership_total_hint' => (int)($context['selected_manager_leadership_total'] ?? 0),
            'leadership_filled_total_hint' => (int)($context['selected_manager_leadership_filled_total'] ?? 0),
            'operational_total_hint' => (int)($context['selected_manager_operational_total'] ?? 0),
            'loaded_members_hint' => (int)($context['selected_team_members_loaded'] ?? 0),
        ];

        if ($eventId > 0) {
            $eventStmt = $db->prepare('
                SELECT id, name, status, starts_at, ends_at
                FROM public.events
                WHERE id = :event_id
                  AND organizer_id = :organizer_id
                LIMIT 1
            ');
            $eventStmt->execute([
                ':event_id' => $eventId,
                ':organizer_id' => $organizerId,
            ]);
            $eventRow = $eventStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($eventRow !== []) {
                $eventMeta = [
                    'event_id' => (int)($eventRow['id'] ?? 0),
                    'event_name' => $eventRow['name'] ?? null,
                    'event_status' => $eventRow['status'] ?? null,
                    'event_starts_at' => $eventRow['starts_at'] ?? null,
                    'event_ends_at' => $eventRow['ends_at'] ?? null,
                ];

                $treeStatus = self::buildWorkforceTreeStatusSafe($db, $organizerId, $eventId);
                $structure = self::buildWorkforceStructure($db, $eventId);
                $summary = self::buildWorkforceSummary($db, $organizerId, $eventId);
                $topSectors = self::buildWorkforceSectorBreakdown($db, $organizerId, $eventId);
                $topRoles = self::buildWorkforceRoleBreakdown($db, $organizerId, $eventId);
                $recentAssignments = self::buildRecentWorkforceAssignments($db, $eventId);
                if ($focusSector !== '') {
                    $focusSectorSummary = self::buildWorkforceFocusSectorSummary($db, $organizerId, $eventId, $focusSector);
                }
            }
        }

        return array_merge($context, $eventMeta, $summary, $structure, [
            'surface' => 'workforce',
            'module' => 'workforce',
            'screen' => 'workforce',
            'sector' => 'workforce',
            'context_origin' => 'builder',
            'focus_sector' => $focusSector !== '' ? $focusSector : null,
            'selected_manager_context' => $selectedManager,
            'workforce_tree_status' => $treeStatus,
            'workforce_structure' => $structure,
            'workforce_sectors' => $topSectors,
            'workforce_top_roles' => $topRoles,
            'workforce_recent_assignments' => $recentAssignments,
            'workforce_focus_sector' => $focusSectorSummary,
            'top_products' => [],
            'stock_levels' => [],
        ]);
    }

    private static function buildWorkforceTreeStatusSafe(PDO $db, int $organizerId, int $eventId): array
    {
        try {
            if (!function_exists('buildWorkforceTreeStatus')) {
                return [
                    'migration_ready' => false,
                    'assignment_bindings_ready' => false,
                    'tree_usable' => false,
                    'tree_ready' => false,
                    'source_preference' => 'legacy',
                    'manager_roots_count' => 0,
                    'managerial_child_roles_count' => 0,
                    'root_sectors_count' => 0,
                    'activation_blockers' => ['tree_helper_unavailable'],
                ];
            }

            $status = \buildWorkforceTreeStatus($db, $organizerId, $eventId, true, 'all');
            return is_array($status) ? $status : [];
        } catch (\Throwable $e) {
            return [
                'migration_ready' => false,
                'assignment_bindings_ready' => false,
                'tree_usable' => false,
                'tree_ready' => false,
                'source_preference' => 'legacy',
                'manager_roots_count' => 0,
                'managerial_child_roles_count' => 0,
                'root_sectors_count' => 0,
                'activation_blockers' => ['tree_status_unavailable'],
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function buildWorkforceStructure(PDO $db, int $eventId): array
    {
        $eventDaysTotal = 0;
        $registeredShiftsTotal = 0;

        if (self::tableExists($db, 'event_days')) {
            $daysStmt = $db->prepare('
                SELECT COUNT(*)::int
                FROM public.event_days
                WHERE event_id = :event_id
            ');
            $daysStmt->execute([':event_id' => $eventId]);
            $eventDaysTotal = (int)$daysStmt->fetchColumn();
        }

        if (self::tableExists($db, 'event_shifts')) {
            $shiftsStmt = $db->prepare('
                SELECT COUNT(*)::int
                FROM public.event_shifts es
                JOIN public.event_days ed ON ed.id = es.event_day_id
                WHERE ed.event_id = :event_id
            ');
            $shiftsStmt->execute([':event_id' => $eventId]);
            $registeredShiftsTotal = (int)$shiftsStmt->fetchColumn();
        }

        return [
            'event_days_total' => $eventDaysTotal,
            'registered_shifts_total' => $registeredShiftsTotal,
        ];
    }

    private static function buildWorkforceSummary(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles')) {
            return [
                'members_total' => 0,
                'assignments_total' => 0,
                'managerial_assignments_total' => 0,
                'operational_assignments_total' => 0,
                'assignments_with_root_manager' => 0,
                'assignments_missing_bindings' => 0,
                'active_sectors_count' => 0,
            ];
        }

        $costBucketExpr = self::tableExists($db, 'workforce_role_settings')
            ? "COALESCE(wrs.cost_bucket, 'operational')"
            : "'operational'";
        $rootManagerExpr = self::columnExists($db, 'workforce_assignments', 'root_manager_event_role_id')
            ? "COUNT(*) FILTER (WHERE COALESCE(wa.root_manager_event_role_id, 0) > 0)::int AS assignments_with_root_manager,
               COUNT(*) FILTER (WHERE COALESCE(wa.root_manager_event_role_id, 0) <= 0)::int AS assignments_missing_bindings,"
            : "0::int AS assignments_with_root_manager,
               0::int AS assignments_missing_bindings,";
        $roleSettingsJoin = self::tableExists($db, 'workforce_role_settings')
            ? 'LEFT JOIN public.workforce_role_settings wrs ON wrs.role_id = wr.id AND wrs.organizer_id = :organizer_id'
            : '';

        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT wa.participant_id)::int AS members_total,
                COUNT(*)::int AS assignments_total,
                COUNT(*) FILTER (WHERE {$costBucketExpr} = 'managerial')::int AS managerial_assignments_total,
                COUNT(*) FILTER (WHERE {$costBucketExpr} <> 'managerial')::int AS operational_assignments_total,
                {$rootManagerExpr}
                COUNT(DISTINCT LOWER(COALESCE(NULLIF(TRIM(wa.sector), ''), NULLIF(TRIM(wr.sector), ''), 'geral')))::int AS active_sectors_count
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$roleSettingsJoin}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
              AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
        ");
        $params = [':event_id' => $eventId];
        if ($roleSettingsJoin !== '') {
            $params[':organizer_id'] = $organizerId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'members_total' => (int)($row['members_total'] ?? 0),
            'assignments_total' => (int)($row['assignments_total'] ?? 0),
            'managerial_assignments_total' => (int)($row['managerial_assignments_total'] ?? 0),
            'operational_assignments_total' => (int)($row['operational_assignments_total'] ?? 0),
            'assignments_with_root_manager' => (int)($row['assignments_with_root_manager'] ?? 0),
            'assignments_missing_bindings' => (int)($row['assignments_missing_bindings'] ?? 0),
            'active_sectors_count' => (int)($row['active_sectors_count'] ?? 0),
        ];
    }

    private static function buildWorkforceSectorBreakdown(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles')) {
            return [];
        }

        $costBucketExpr = self::tableExists($db, 'workforce_role_settings')
            ? "COALESCE(wrs.cost_bucket, 'operational')"
            : "'operational'";
        $roleSettingsJoin = self::tableExists($db, 'workforce_role_settings')
            ? 'LEFT JOIN public.workforce_role_settings wrs ON wrs.role_id = wr.id AND wrs.organizer_id = :organizer_id'
            : '';

        $stmt = $db->prepare("
            SELECT
                LOWER(COALESCE(NULLIF(TRIM(wa.sector), ''), NULLIF(TRIM(wr.sector), ''), 'geral')) AS sector,
                COUNT(*)::int AS assignments_total,
                COUNT(DISTINCT wa.participant_id)::int AS members_total,
                COUNT(*) FILTER (WHERE {$costBucketExpr} = 'managerial')::int AS managerial_total
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$roleSettingsJoin}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
              AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
            GROUP BY 1
            ORDER BY assignments_total DESC, sector ASC
            LIMIT 6
        ");
        $params = [':event_id' => $eventId];
        if ($roleSettingsJoin !== '') {
            $params[':organizer_id'] = $organizerId;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'sector' => (string)($row['sector'] ?? 'geral'),
                'assignments_total' => (int)($row['assignments_total'] ?? 0),
                'members_total' => (int)($row['members_total'] ?? 0),
                'managerial_total' => (int)($row['managerial_total'] ?? 0),
            ];
        }, $rows);
    }

    private static function buildWorkforceRoleBreakdown(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles')) {
            return [];
        }

        $costBucketExpr = self::tableExists($db, 'workforce_role_settings')
            ? "COALESCE(wrs.cost_bucket, 'operational')"
            : "'operational'";
        $roleSettingsJoin = self::tableExists($db, 'workforce_role_settings')
            ? 'LEFT JOIN public.workforce_role_settings wrs ON wrs.role_id = wr.id AND wrs.organizer_id = :organizer_id'
            : '';

        $stmt = $db->prepare("
            SELECT
                wr.name AS role_name,
                {$costBucketExpr} AS cost_bucket,
                COUNT(*)::int AS assignments_total
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$roleSettingsJoin}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
              AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
            GROUP BY wr.name, {$costBucketExpr}
            ORDER BY assignments_total DESC, wr.name ASC
            LIMIT 8
        ");
        $params = [':event_id' => $eventId];
        if ($roleSettingsJoin !== '') {
            $params[':organizer_id'] = $organizerId;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'role_name' => (string)($row['role_name'] ?? ''),
                'cost_bucket' => (string)($row['cost_bucket'] ?? 'operational'),
                'assignments_total' => (int)($row['assignments_total'] ?? 0),
            ];
        }, $rows);
    }

    private static function buildRecentWorkforceAssignments(PDO $db, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles') || !self::tableExists($db, 'people')) {
            return [];
        }

        $stmt = $db->prepare('
            SELECT
                p.name AS participant_name,
                wr.name AS role_name,
                LOWER(COALESCE(NULLIF(TRIM(wa.sector), \'\'), NULLIF(TRIM(wr.sector), \'\'), \'geral\')) AS sector,
                wa.created_at
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.people p ON p.id = ep.person_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            WHERE ep.event_id = :event_id
            ORDER BY wa.created_at DESC NULLS LAST, wa.id DESC
            LIMIT 6
        ');
        $stmt->execute([':event_id' => $eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'participant_name' => (string)($row['participant_name'] ?? ''),
                'role_name' => (string)($row['role_name'] ?? ''),
                'sector' => (string)($row['sector'] ?? 'geral'),
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows);
    }

    private static function buildWorkforceFocusSectorSummary(PDO $db, int $organizerId, int $eventId, string $focusSector): ?array
    {
        if ($focusSector === '' || !self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles')) {
            return null;
        }

        $costBucketExpr = self::tableExists($db, 'workforce_role_settings')
            ? "COALESCE(wrs.cost_bucket, 'operational')"
            : "'operational'";
        $roleSettingsJoin = self::tableExists($db, 'workforce_role_settings')
            ? 'LEFT JOIN public.workforce_role_settings wrs ON wrs.role_id = wr.id AND wrs.organizer_id = :organizer_id'
            : '';
        $rootManagerExpr = self::columnExists($db, 'workforce_assignments', 'root_manager_event_role_id')
            ? "COUNT(*) FILTER (WHERE COALESCE(wa.root_manager_event_role_id, 0) <= 0)::int AS assignments_missing_bindings"
            : "0::int AS assignments_missing_bindings";

        $stmt = $db->prepare("
            SELECT
                COUNT(*)::int AS assignments_total,
                COUNT(DISTINCT wa.participant_id)::int AS members_total,
                COUNT(*) FILTER (WHERE {$costBucketExpr} = 'managerial')::int AS managerial_total,
                {$rootManagerExpr}
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$roleSettingsJoin}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(NULLIF(TRIM(wa.sector), ''), NULLIF(TRIM(wr.sector), ''), 'geral')) = :focus_sector
        ");
        $params = [
            ':event_id' => $eventId,
            ':focus_sector' => $focusSector,
        ];
        if ($roleSettingsJoin !== '') {
            $params[':organizer_id'] = $organizerId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'sector' => $focusSector,
            'assignments_total' => (int)($row['assignments_total'] ?? 0),
            'members_total' => (int)($row['members_total'] ?? 0),
            'managerial_total' => (int)($row['managerial_total'] ?? 0),
            'assignments_missing_bindings' => (int)($row['assignments_missing_bindings'] ?? 0),
        ];
    }

    private static function resolveWorkforceFocusSector(array $context): string
    {
        foreach ([
            $context['selected_manager_sector'] ?? null,
            $context['focus_sector'] ?? null,
            $context['manager_sector'] ?? null,
        ] as $candidate) {
            $normalized = self::normalizeSurface((string)$candidate);
            if ($normalized !== '' && !in_array($normalized, ['workforce', 'general'], true)) {
                return $normalized;
            }
        }

        return '';
    }

    private static function tableExists(PDO $db, string $tableName): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute([':table_name' => $tableName]);
        return (bool)$stmt->fetchColumn();
    }

    private static function columnExists(PDO $db, string $tableName, string $columnName): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    private static function nullableText(mixed $value): ?string
    {
        $normalized = trim((string)$value);
        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeSurface(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(['_', ' '], '-', $normalized);
        return match ($normalized) {
            'meals' => 'meals-control',
            'analytics-dashboard' => 'analytics',
            default => $normalized,
        };
    }
}
