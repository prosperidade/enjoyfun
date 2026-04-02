<?php

namespace EnjoyFun\Services;

use PDO;

require_once __DIR__ . '/../Helpers/WorkforceTreeHelper.php';
require_once __DIR__ . '/MealsDomainService.php';
require_once __DIR__ . '/WorkforceTreeUseCaseService.php';

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
        $summary = self::emptyWorkforceSummary();
        $timeline = [
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
        $treeSnapshot = self::emptyWorkforceTreeSnapshot();
        $topSectors = [];
        $topRoles = [];
        $recentAssignments = [];
        $focusSectorSummary = null;
        $selectedManagerTree = null;
        $leadershipDigest = [];
        $effectiveFocus = null;
        $effectiveFocusSource = 'none';

        $requestedFocusSector = self::resolveWorkforceFocusSector($context);
        $selectedManager = [
            'name' => self::nullableText($context['selected_manager_name'] ?? null),
            'role_name' => self::nullableText($context['selected_manager_role_name'] ?? null),
            'role_class' => self::nullableText($context['selected_manager_role_class'] ?? null),
            'event_role_id' => self::nullablePositiveInt($context['selected_manager_event_role_id'] ?? null),
            'root_event_role_id' => self::nullablePositiveInt($context['selected_manager_root_event_role_id'] ?? null),
            'sector' => $requestedFocusSector !== '' ? $requestedFocusSector : null,
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
                $timeline = self::buildWorkforceStructure($db, $eventId);
                $eventRoleRows = self::buildWorkforceEventRoleRows($db, $organizerId, $eventId);
                $operationalHeadcount = self::buildWorkforceOperationalHeadcountByTree($db, $organizerId, $eventId);
                $treeSnapshot = self::buildWorkforceTreeSnapshot($eventRoleRows, $operationalHeadcount);
                $summary = self::mergeWorkforceSummaryWithTreeSnapshot(
                    self::buildWorkforceSummary($db, $organizerId, $eventId),
                    $treeSnapshot
                );
                $topSectors = self::buildWorkforceSectorBreakdown($db, $organizerId, $eventId);
                $topRoles = self::buildWorkforceRoleBreakdown($db, $organizerId, $eventId);
                $recentAssignments = self::buildRecentWorkforceAssignments($db, $organizerId, $eventId);
                $selectedManager = self::enrichSelectedManagerContext($selectedManager, $treeSnapshot, $requestedFocusSector);
                $selectedManagerTree = self::buildWorkforceSelectedManagerTree($selectedManager, $treeSnapshot);
                $selectedManagerConfig = self::buildSelectedManagerOperationalConfig($db, $organizerId, $eventId, $selectedManager);
                $costSnapshot = self::buildWorkforceCostSnapshot($db, $organizerId, $eventId);
                $attentionSectors = self::buildWorkforceAttentionSectors($treeSnapshot, $costSnapshot);
                $mealExecutionSnapshot = self::buildWorkforceMealExecutionSnapshot($db, $organizerId, $eventId);
                $leadershipDigest = self::buildWorkforceLeadershipDigest($treeSnapshot);
                $effectiveFocusMeta = self::resolveWorkforceEffectiveFocus(
                    $selectedManager,
                    $requestedFocusSector,
                    $attentionSectors
                );
                $effectiveFocusSector = (string)($effectiveFocusMeta['sector'] ?? '');
                $effectiveFocusSource = (string)($effectiveFocusMeta['source'] ?? 'none');
                if ($effectiveFocusSector !== '') {
                    $focusSectorSummary = self::buildWorkforceFocusSectorSummary(
                        $db,
                        $organizerId,
                        $eventId,
                        $effectiveFocusSector,
                        $treeSnapshot
                    );
                }
                $focusCostSnapshot = self::buildWorkforceFocusCostSnapshot($costSnapshot, $effectiveFocusSector);
                $focusMealExecution = self::buildWorkforceFocusMealExecutionSnapshot($mealExecutionSnapshot, $effectiveFocusSector);
                $effectiveFocus = self::buildWorkforceEffectiveFocusPayload(
                    $effectiveFocusSector,
                    $effectiveFocusSource,
                    $treeSnapshot,
                    $focusSectorSummary,
                    $focusCostSnapshot,
                    $focusMealExecution
                );
            } else {
                $selectedManagerConfig = null;
                $costSnapshot = ['summary' => [], 'by_sector' => [], 'by_role_managerial' => []];
                $focusCostSnapshot = null;
                $attentionSectors = [];
                $mealExecutionSnapshot = ['summary' => [], 'by_sector' => []];
                $focusMealExecution = null;
                $effectiveFocusSector = '';
            }
        } else {
            $selectedManagerConfig = null;
            $costSnapshot = ['summary' => [], 'by_sector' => [], 'by_role_managerial' => []];
            $focusCostSnapshot = null;
            $attentionSectors = [];
            $mealExecutionSnapshot = ['summary' => [], 'by_sector' => []];
            $focusMealExecution = null;
            $effectiveFocusSector = '';
        }

        return array_merge($context, $eventMeta, $summary, $timeline, [
            'surface' => 'workforce',
            'module' => 'workforce',
            'screen' => 'workforce',
            'sector' => 'workforce',
            'context_origin' => 'builder',
            'focus_sector_requested' => $requestedFocusSector !== '' ? $requestedFocusSector : null,
            'focus_sector' => $effectiveFocusSector !== '' ? $effectiveFocusSector : ($requestedFocusSector !== '' ? $requestedFocusSector : null),
            'focus_sector_source' => $effectiveFocusSource,
            'selected_manager_context' => $selectedManager,
            'workforce_tree_status' => $treeStatus,
            'workforce_structure' => $timeline,
            'workforce_timeline' => $timeline,
            'workforce_tree_snapshot' => $treeSnapshot,
            'workforce_leadership_digest' => $leadershipDigest,
            'workforce_sectors' => $topSectors,
            'workforce_top_roles' => $topRoles,
            'workforce_recent_assignments' => $recentAssignments,
            'workforce_focus_sector' => $focusSectorSummary,
            'workforce_effective_focus' => $effectiveFocus,
            'workforce_selected_manager_tree' => $selectedManagerTree,
            'workforce_cost_snapshot' => $costSnapshot,
            'workforce_focus_costs' => $focusCostSnapshot,
            'selected_manager_operational_config' => $selectedManagerConfig,
            'workforce_attention_sectors' => $attentionSectors,
            'workforce_meal_execution_snapshot' => $mealExecutionSnapshot,
            'workforce_focus_meal_execution' => $focusMealExecution,
            'top_products' => [],
            'stock_levels' => [],
        ]);
    }

    private static function buildWorkforceTreeStatusSafe(PDO $db, int $organizerId, int $eventId): array
    {
        try {
            $status = WorkforceTreeUseCaseService::getStatus($db, $organizerId, $eventId, true, 'all');
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

    private static function emptyWorkforceSummary(): array
    {
        return [
            'members_total' => 0,
            'assignments_total' => 0,
            'managerial_assignments_total' => 0,
            'operational_assignments_total' => 0,
            'assignments_with_root_manager' => 0,
            'assignments_missing_bindings' => 0,
            'active_sectors_count' => 0,
            'leadership_positions_total' => 0,
            'leadership_filled_total' => 0,
            'leadership_placeholder_total' => 0,
            'planned_workforce_total' => 0,
            'filled_workforce_total' => 0,
            'manager_roots_total' => 0,
            'managerial_child_roles_total' => 0,
        ];
    }

    private static function emptyWorkforceTreeSnapshot(): array
    {
        return [
            'leadership_positions_total' => 0,
            'leadership_filled_total' => 0,
            'leadership_placeholder_total' => 0,
            'planned_members_total' => 0,
            'filled_members_total' => 0,
            'operational_members_total' => 0,
            'manager_roots_total' => 0,
            'managerial_child_roles_total' => 0,
            'tracked_sectors_total' => 0,
            'manager_roots' => [],
            'sector_overview' => [],
        ];
    }

    private static function mergeWorkforceSummaryWithTreeSnapshot(array $summary, array $treeSnapshot): array
    {
        return array_merge(self::emptyWorkforceSummary(), $summary, [
            'leadership_positions_total' => (int)($treeSnapshot['leadership_positions_total'] ?? 0),
            'leadership_filled_total' => (int)($treeSnapshot['leadership_filled_total'] ?? 0),
            'leadership_placeholder_total' => (int)($treeSnapshot['leadership_placeholder_total'] ?? 0),
            'planned_workforce_total' => (int)($treeSnapshot['planned_members_total'] ?? 0),
            'filled_workforce_total' => (int)($treeSnapshot['filled_members_total'] ?? 0),
            'manager_roots_total' => (int)($treeSnapshot['manager_roots_total'] ?? 0),
            'managerial_child_roles_total' => (int)($treeSnapshot['managerial_child_roles_total'] ?? 0),
        ]);
    }

    private static function buildWorkforceEventRoleRows(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_event_roles') || !self::tableExists($db, 'workforce_roles')) {
            return [];
        }

        $stmt = $db->prepare('
            SELECT
                wer.*,
                wr.name AS role_name,
                COALESCE(wr.sector, \'\') AS role_sector,
                parent.public_id AS parent_public_id,
                root.public_id AS root_public_id,
                ep.qr_token AS leader_qr_token,
                p.name AS leader_participant_name,
                p.email AS leader_participant_email,
                p.phone AS leader_participant_phone,
                u.email AS leader_user_email
            FROM public.workforce_event_roles wer
            JOIN public.workforce_roles wr ON wr.id = wer.role_id
            LEFT JOIN public.workforce_event_roles parent ON parent.id = wer.parent_event_role_id
            LEFT JOIN public.workforce_event_roles root ON root.id = wer.root_event_role_id
            LEFT JOIN public.event_participants ep ON ep.id = wer.leader_participant_id
            LEFT JOIN public.people p ON p.id = ep.person_id
            LEFT JOIN public.users u ON u.id = wer.leader_user_id
            WHERE wer.organizer_id = :organizer_id
              AND wer.event_id = :event_id
              AND wer.is_active = true
            ORDER BY wer.sort_order ASC, wer.parent_event_role_id ASC NULLS FIRST, wer.role_class ASC, wr.name ASC, wer.id ASC
        ');
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':event_id' => $eventId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $normalized = \workforceNormalizeEventRoleRow($row);
            $normalized['role_name'] = (string)($row['role_name'] ?? '');
            $normalized['role_sector'] = self::normalizeWorkforceSector((string)($row['role_sector'] ?? ''));
            $normalized['sector'] = self::normalizeWorkforceSector(
                (string)($normalized['sector'] ?? $normalized['role_sector'] ?? '')
            );
            $normalized['cost_bucket'] = \normalizeCostBucket(
                (string)($row['cost_bucket'] ?? ''),
                (string)($normalized['role_name'] ?? '')
            );
            $normalized['role_class'] = \workforceResolveRoleClass(
                (string)($normalized['role_name'] ?? ''),
                (string)($normalized['cost_bucket'] ?? '')
            );
            $normalized['parent_public_id'] = (string)($row['parent_public_id'] ?? '');
            $normalized['root_public_id'] = (string)($row['root_public_id'] ?? '');
            $normalized['leader_qr_token'] = (string)($row['leader_qr_token'] ?? '');
            $normalized['leader_participant_name'] = (string)($row['leader_participant_name'] ?? '');
            $normalized['leader_participant_email'] = (string)($row['leader_participant_email'] ?? '');
            $normalized['leader_participant_phone'] = (string)($row['leader_participant_phone'] ?? '');
            $normalized['leader_user_email'] = (string)($row['leader_user_email'] ?? '');
            $normalized['leader_email'] = $normalized['leader_participant_email'] !== ''
                ? $normalized['leader_participant_email']
                : $normalized['leader_user_email'];
            $normalized['leader_display_name'] = trim((string)($normalized['leader_participant_name'] ?? '')) !== ''
                ? (string)$normalized['leader_participant_name']
                : (trim((string)($normalized['leader_name'] ?? '')) !== ''
                    ? (string)$normalized['leader_name']
                    : (string)($normalized['role_name'] ?? ''));
            $normalized['leader_display_phone'] = trim((string)($normalized['leader_participant_phone'] ?? '')) !== ''
                ? (string)$normalized['leader_participant_phone']
                : (string)($normalized['leader_phone'] ?? '');
            $normalized['leader_bound'] = \workforceHasLeadershipIdentity($normalized);
            return $normalized;
        }, $rows);
    }

    private static function buildWorkforceOperationalHeadcountByTree(PDO $db, int $organizerId, int $eventId): array
    {
        $counts = [
            'by_event_role_id' => [],
            'by_root_event_role_id' => [],
        ];

        if (
            !self::tableExists($db, 'workforce_assignments')
            || !self::tableExists($db, 'event_participants')
            || !self::tableExists($db, 'workforce_roles')
        ) {
            return $counts;
        }

        $hasEventRoleId = self::columnExists($db, 'workforce_assignments', 'event_role_id');
        $hasRootEventRoleId = self::columnExists($db, 'workforce_assignments', 'root_manager_event_role_id');
        if (!$hasEventRoleId && !$hasRootEventRoleId) {
            return $counts;
        }

        $assignmentSqlContext = self::buildWorkforceAssignmentSqlContext($db, 'wa', 'wr');
        $costBucketExpr = $assignmentSqlContext['cost_bucket_expr'];
        $eventRoleExpr = $hasEventRoleId ? 'COALESCE(wa.event_role_id, 0)::int' : '0::int';
        $rootEventRoleExpr = $hasRootEventRoleId ? 'COALESCE(wa.root_manager_event_role_id, 0)::int' : '0::int';

        $stmt = $db->prepare("
            SELECT
                {$eventRoleExpr} AS event_role_id,
                {$rootEventRoleExpr} AS root_event_role_id,
                COUNT(*)::int AS assignments_total
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$assignmentSqlContext['event_role_join']}
            {$assignmentSqlContext['role_settings_join']}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
              AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
              AND {$costBucketExpr} <> 'managerial'
            GROUP BY 1, 2
        ");
        $params = [':event_id' => $eventId];
        if (!empty($assignmentSqlContext['requires_organizer_id'])) {
            $params[':organizer_id'] = $organizerId;
        }
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $assignmentsTotal = (int)($row['assignments_total'] ?? 0);
            $eventRoleId = (int)($row['event_role_id'] ?? 0);
            $rootEventRoleId = (int)($row['root_event_role_id'] ?? 0);

            if ($eventRoleId > 0) {
                $counts['by_event_role_id'][$eventRoleId] = (int)($counts['by_event_role_id'][$eventRoleId] ?? 0) + $assignmentsTotal;
            }
            if ($rootEventRoleId > 0) {
                $counts['by_root_event_role_id'][$rootEventRoleId] = (int)($counts['by_root_event_role_id'][$rootEventRoleId] ?? 0) + $assignmentsTotal;
            }
        }

        return $counts;
    }

    private static function buildWorkforceTreeSnapshot(array $eventRoleRows, array $operationalHeadcount): array
    {
        $snapshot = self::emptyWorkforceTreeSnapshot();
        if ($eventRoleRows === []) {
            return $snapshot;
        }

        $roots = [];

        foreach ($eventRoleRows as $row) {
            if ((string)($row['cost_bucket'] ?? 'operational') !== 'managerial') {
                continue;
            }

            $eventRoleId = (int)($row['id'] ?? 0);
            if ($eventRoleId <= 0) {
                continue;
            }

            $rootEventRoleId = (int)($row['root_event_role_id'] ?? $eventRoleId);
            if ($rootEventRoleId <= 0) {
                $rootEventRoleId = $eventRoleId;
            }
            if (!isset($roots[$rootEventRoleId])) {
                $roots[$rootEventRoleId] = [
                    'event_role_id' => 0,
                    'root_event_role_id' => $rootEventRoleId,
                    'public_id' => '',
                    'root_public_id' => '',
                    'role_id' => 0,
                    'role_name' => '',
                    'role_class' => 'manager',
                    'authority_level' => '',
                    'sector' => 'geral',
                    'leader_name' => '',
                    'leader_email' => '',
                    'leader_phone' => '',
                    'leader_bound' => false,
                    'operational_members_total' => (int)($operationalHeadcount['by_root_event_role_id'][$rootEventRoleId] ?? 0),
                    'leadership_positions_total' => 0,
                    'leadership_filled_total' => 0,
                    'leadership_placeholder_total' => 0,
                    'managerial_child_roles_total' => 0,
                    'planned_team_size' => 0,
                    'filled_team_size' => 0,
                    'child_roles' => [],
                    'leadership_roles' => [],
                ];
            }

            $isRoot = (int)($row['parent_event_role_id'] ?? 0) <= 0;
            $roleSummary = [
                'event_role_id' => $eventRoleId,
                'root_event_role_id' => $rootEventRoleId,
                'public_id' => (string)($row['public_id'] ?? ''),
                'parent_event_role_id' => (int)($row['parent_event_role_id'] ?? 0) ?: null,
                'parent_public_id' => (string)($row['parent_public_id'] ?? ''),
                'role_id' => (int)($row['role_id'] ?? 0),
                'role_name' => (string)($row['role_name'] ?? ''),
                'role_class' => (string)($row['role_class'] ?? 'manager'),
                'authority_level' => (string)($row['authority_level'] ?? ''),
                'sector' => self::normalizeWorkforceSector((string)($row['sector'] ?? '')) ?: 'geral',
                'leader_name' => (string)($row['leader_display_name'] ?? $row['role_name'] ?? ''),
                'leader_email' => (string)($row['leader_email'] ?? ''),
                'leader_phone' => (string)($row['leader_display_phone'] ?? ''),
                'leader_bound' => !empty($row['leader_bound']),
                'direct_operational_members_total' => (int)($operationalHeadcount['by_event_role_id'][$eventRoleId] ?? 0),
                'is_root' => $isRoot,
            ];

            $root = &$roots[$rootEventRoleId];
            $root['leadership_positions_total']++;
            if ($roleSummary['leader_bound']) {
                $root['leadership_filled_total']++;
            } else {
                $root['leadership_placeholder_total']++;
            }

            if ($isRoot) {
                $root['event_role_id'] = $eventRoleId;
                $root['public_id'] = (string)($row['public_id'] ?? '');
                $root['root_public_id'] = (string)($row['root_public_id'] ?? $row['public_id'] ?? '');
                $root['role_id'] = (int)($row['role_id'] ?? 0);
                $root['role_name'] = (string)($row['role_name'] ?? '');
                $root['role_class'] = (string)($row['role_class'] ?? 'manager');
                $root['authority_level'] = (string)($row['authority_level'] ?? '');
                $root['sector'] = $roleSummary['sector'];
                $root['leader_name'] = $roleSummary['leader_name'];
                $root['leader_email'] = $roleSummary['leader_email'];
                $root['leader_phone'] = $roleSummary['leader_phone'];
                $root['leader_bound'] = $roleSummary['leader_bound'];
            } else {
                $root['managerial_child_roles_total']++;
                $root['child_roles'][] = $roleSummary;
            }

            $root['leadership_roles'][] = $roleSummary;
            unset($root);
        }

        $sectorOverview = [];
        $managerRoots = array_values($roots);
        foreach ($managerRoots as &$root) {
            $root['planned_team_size'] = (int)$root['operational_members_total'] + (int)$root['leadership_positions_total'];
            $root['filled_team_size'] = (int)$root['operational_members_total'] + (int)$root['leadership_filled_total'];

            usort($root['child_roles'], static function (array $left, array $right): int {
                $leftOrder = self::workforceRoleClassSortValue((string)($left['role_class'] ?? 'operational'));
                $rightOrder = self::workforceRoleClassSortValue((string)($right['role_class'] ?? 'operational'));
                if ($leftOrder !== $rightOrder) {
                    return $leftOrder <=> $rightOrder;
                }

                return strcasecmp((string)($left['role_name'] ?? ''), (string)($right['role_name'] ?? ''));
            });
            usort($root['leadership_roles'], static function (array $left, array $right): int {
                if (!empty($left['is_root']) !== !empty($right['is_root'])) {
                    return !empty($left['is_root']) ? -1 : 1;
                }

                $leftOrder = self::workforceRoleClassSortValue((string)($left['role_class'] ?? 'operational'));
                $rightOrder = self::workforceRoleClassSortValue((string)($right['role_class'] ?? 'operational'));
                if ($leftOrder !== $rightOrder) {
                    return $leftOrder <=> $rightOrder;
                }

                return strcasecmp((string)($left['role_name'] ?? ''), (string)($right['role_name'] ?? ''));
            });

            $sector = self::normalizeWorkforceSector((string)($root['sector'] ?? '')) ?: 'geral';
            if (!isset($sectorOverview[$sector])) {
                $sectorOverview[$sector] = [
                    'sector' => $sector,
                    'manager_roots_total' => 0,
                    'leadership_positions_total' => 0,
                    'leadership_filled_total' => 0,
                    'leadership_placeholder_total' => 0,
                    'operational_members_total' => 0,
                    'planned_team_size' => 0,
                    'filled_team_size' => 0,
                    'leaders' => [],
                    'leadership_roles' => [],
                ];
            }

            $sectorOverview[$sector]['manager_roots_total']++;
            $sectorOverview[$sector]['leadership_positions_total'] += (int)$root['leadership_positions_total'];
            $sectorOverview[$sector]['leadership_filled_total'] += (int)$root['leadership_filled_total'];
            $sectorOverview[$sector]['leadership_placeholder_total'] += (int)$root['leadership_placeholder_total'];
            $sectorOverview[$sector]['operational_members_total'] += (int)$root['operational_members_total'];
            $sectorOverview[$sector]['planned_team_size'] += (int)$root['planned_team_size'];
            $sectorOverview[$sector]['filled_team_size'] += (int)$root['filled_team_size'];
            $sectorOverview[$sector]['leaders'][] = [
                'event_role_id' => (int)($root['event_role_id'] ?? 0),
                'role_name' => (string)($root['role_name'] ?? ''),
                'leader_name' => (string)($root['leader_name'] ?? ''),
                'role_class' => (string)($root['role_class'] ?? 'manager'),
            ];
            foreach ((array)($root['leadership_roles'] ?? []) as $leadershipRole) {
                $sectorOverview[$sector]['leadership_roles'][] = [
                    'event_role_id' => (int)($leadershipRole['event_role_id'] ?? 0),
                    'role_name' => (string)($leadershipRole['role_name'] ?? ''),
                    'leader_name' => (string)($leadershipRole['leader_name'] ?? ''),
                    'role_class' => (string)($leadershipRole['role_class'] ?? 'operational'),
                    'leader_bound' => !empty($leadershipRole['leader_bound']),
                    'is_root' => !empty($leadershipRole['is_root']),
                ];
            }
        }
        unset($root);

        usort($managerRoots, static function (array $left, array $right): int {
            $leftSector = (string)($left['sector'] ?? 'geral');
            $rightSector = (string)($right['sector'] ?? 'geral');
            if ($leftSector !== $rightSector) {
                return strcasecmp($leftSector, $rightSector);
            }

            return strcasecmp((string)($left['role_name'] ?? ''), (string)($right['role_name'] ?? ''));
        });

        $sectorOverviewRows = array_values($sectorOverview);
        usort($sectorOverviewRows, static function (array $left, array $right): int {
            if ((int)($left['planned_team_size'] ?? 0) !== (int)($right['planned_team_size'] ?? 0)) {
                return (int)($right['planned_team_size'] ?? 0) <=> (int)($left['planned_team_size'] ?? 0);
            }

            return strcasecmp((string)($left['sector'] ?? ''), (string)($right['sector'] ?? ''));
        });

        $snapshot['manager_roots'] = $managerRoots;
        $snapshot['sector_overview'] = array_map(static function (array $row): array {
            $row['leaders'] = array_slice((array)($row['leaders'] ?? []), 0, 8);
            $row['leadership_roles'] = array_slice((array)($row['leadership_roles'] ?? []), 0, 16);
            return $row;
        }, $sectorOverviewRows);
        $snapshot['manager_roots_total'] = count($managerRoots);
        $snapshot['tracked_sectors_total'] = count($sectorOverviewRows);

        foreach ($managerRoots as $root) {
            $snapshot['leadership_positions_total'] += (int)($root['leadership_positions_total'] ?? 0);
            $snapshot['leadership_filled_total'] += (int)($root['leadership_filled_total'] ?? 0);
            $snapshot['leadership_placeholder_total'] += (int)($root['leadership_placeholder_total'] ?? 0);
            $snapshot['operational_members_total'] += (int)($root['operational_members_total'] ?? 0);
            $snapshot['planned_members_total'] += (int)($root['planned_team_size'] ?? 0);
            $snapshot['filled_members_total'] += (int)($root['filled_team_size'] ?? 0);
            $snapshot['managerial_child_roles_total'] += (int)($root['managerial_child_roles_total'] ?? 0);
        }

        return $snapshot;
    }

    private static function buildWorkforceSummary(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles')) {
            return self::emptyWorkforceSummary();
        }

        $assignmentSqlContext = self::buildWorkforceAssignmentSqlContext($db, 'wa', 'wr');
        $costBucketExpr = $assignmentSqlContext['cost_bucket_expr'];
        $rootManagerExpr = self::columnExists($db, 'workforce_assignments', 'root_manager_event_role_id')
            ? "COUNT(*) FILTER (WHERE COALESCE(wa.root_manager_event_role_id, 0) > 0)::int AS assignments_with_root_manager,
               COUNT(*) FILTER (WHERE COALESCE(wa.root_manager_event_role_id, 0) <= 0)::int AS assignments_missing_bindings,"
            : "0::int AS assignments_with_root_manager,
               0::int AS assignments_missing_bindings,";
        $normalizedSectorExpr = $assignmentSqlContext['normalized_sector_expr'];

        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT wa.participant_id)::int AS members_total,
                COUNT(*)::int AS assignments_total,
                COUNT(*) FILTER (WHERE {$costBucketExpr} = 'managerial')::int AS managerial_assignments_total,
                COUNT(*) FILTER (WHERE {$costBucketExpr} <> 'managerial')::int AS operational_assignments_total,
                {$rootManagerExpr}
                COUNT(DISTINCT {$normalizedSectorExpr})::int AS active_sectors_count
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$assignmentSqlContext['event_role_join']}
            {$assignmentSqlContext['role_settings_join']}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
              AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
        ");
        $params = [':event_id' => $eventId];
        if (!empty($assignmentSqlContext['requires_organizer_id'])) {
            $params[':organizer_id'] = $organizerId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return array_merge(self::emptyWorkforceSummary(), [
            'members_total' => (int)($row['members_total'] ?? 0),
            'assignments_total' => (int)($row['assignments_total'] ?? 0),
            'managerial_assignments_total' => (int)($row['managerial_assignments_total'] ?? 0),
            'operational_assignments_total' => (int)($row['operational_assignments_total'] ?? 0),
            'assignments_with_root_manager' => (int)($row['assignments_with_root_manager'] ?? 0),
            'assignments_missing_bindings' => (int)($row['assignments_missing_bindings'] ?? 0),
            'active_sectors_count' => (int)($row['active_sectors_count'] ?? 0),
        ]);
    }

    private static function buildWorkforceSectorBreakdown(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles')) {
            return [];
        }

        $assignmentSqlContext = self::buildWorkforceAssignmentSqlContext($db, 'wa', 'wr');
        $costBucketExpr = $assignmentSqlContext['cost_bucket_expr'];
        $normalizedSectorExpr = $assignmentSqlContext['normalized_sector_expr'];

        $stmt = $db->prepare("
            SELECT
                {$normalizedSectorExpr} AS sector,
                COUNT(*)::int AS assignments_total,
                COUNT(DISTINCT wa.participant_id)::int AS members_total,
                COUNT(*) FILTER (WHERE {$costBucketExpr} = 'managerial')::int AS managerial_total
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$assignmentSqlContext['event_role_join']}
            {$assignmentSqlContext['role_settings_join']}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
              AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
            GROUP BY 1
            ORDER BY assignments_total DESC, sector ASC
            LIMIT 6
        ");
        $params = [':event_id' => $eventId];
        if (!empty($assignmentSqlContext['requires_organizer_id'])) {
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

        $assignmentSqlContext = self::buildWorkforceAssignmentSqlContext($db, 'wa', 'wr');
        $costBucketExpr = $assignmentSqlContext['cost_bucket_expr'];

        $stmt = $db->prepare("
            SELECT
                wr.name AS role_name,
                {$costBucketExpr} AS cost_bucket,
                COUNT(*)::int AS assignments_total
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$assignmentSqlContext['event_role_join']}
            {$assignmentSqlContext['role_settings_join']}
            WHERE ep.event_id = :event_id
              AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
              AND LOWER(COALESCE(wr.name, '')) NOT LIKE '%externo%'
            GROUP BY wr.name, {$costBucketExpr}
            ORDER BY assignments_total DESC, wr.name ASC
            LIMIT 8
        ");
        $params = [':event_id' => $eventId];
        if (!empty($assignmentSqlContext['requires_organizer_id'])) {
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

    private static function buildRecentWorkforceAssignments(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'workforce_assignments') || !self::tableExists($db, 'event_participants') || !self::tableExists($db, 'workforce_roles') || !self::tableExists($db, 'people')) {
            return [];
        }

        $assignmentSqlContext = self::buildWorkforceAssignmentSqlContext($db, 'wa', 'wr');
        $normalizedSectorExpr = $assignmentSqlContext['normalized_sector_expr'];

        $stmt = $db->prepare("
            SELECT
                p.name AS participant_name,
                wr.name AS role_name,
                {$normalizedSectorExpr} AS sector,
                wa.created_at
            FROM public.workforce_assignments wa
            JOIN public.event_participants ep ON ep.id = wa.participant_id
            JOIN public.people p ON p.id = ep.person_id
            JOIN public.workforce_roles wr ON wr.id = wa.role_id
            {$assignmentSqlContext['event_role_join']}
            {$assignmentSqlContext['role_settings_join']}
            WHERE ep.event_id = :event_id
            ORDER BY wa.created_at DESC NULLS LAST, wa.id DESC
            LIMIT 6
        ");
        $params = [':event_id' => $eventId];
        if (!empty($assignmentSqlContext['requires_organizer_id'])) {
            $params[':organizer_id'] = $organizerId;
        }
        $stmt->execute($params);
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

    private static function buildWorkforceFocusSectorSummary(
        PDO $db,
        int $organizerId,
        int $eventId,
        string $focusSector,
        array $treeSnapshot = []
    ): ?array
    {
        if ($focusSector === '') {
            return null;
        }

        $summary = [
            'sector' => $focusSector,
            'assignments_total' => 0,
            'members_total' => 0,
            'managerial_total' => 0,
            'assignments_missing_bindings' => 0,
            'manager_roots_total' => 0,
            'leadership_positions_total' => 0,
            'leadership_filled_total' => 0,
            'leadership_placeholder_total' => 0,
            'operational_members_total' => 0,
            'planned_team_size' => 0,
            'filled_team_size' => 0,
            'leadership_roles' => [],
        ];

        if (self::tableExists($db, 'workforce_assignments') && self::tableExists($db, 'event_participants') && self::tableExists($db, 'workforce_roles')) {
            $assignmentSqlContext = self::buildWorkforceAssignmentSqlContext($db, 'wa', 'wr');
            $costBucketExpr = $assignmentSqlContext['cost_bucket_expr'];
            $rootManagerExpr = self::columnExists($db, 'workforce_assignments', 'root_manager_event_role_id')
                ? "COUNT(*) FILTER (WHERE COALESCE(wa.root_manager_event_role_id, 0) <= 0)::int AS assignments_missing_bindings"
                : "0::int AS assignments_missing_bindings";
            $normalizedSectorExpr = $assignmentSqlContext['normalized_sector_expr'];

            $stmt = $db->prepare("
                SELECT
                    COUNT(*)::int AS assignments_total,
                    COUNT(DISTINCT wa.participant_id)::int AS members_total,
                    COUNT(*) FILTER (WHERE {$costBucketExpr} = 'managerial')::int AS managerial_total,
                    {$rootManagerExpr}
                FROM public.workforce_assignments wa
                JOIN public.event_participants ep ON ep.id = wa.participant_id
                JOIN public.workforce_roles wr ON wr.id = wa.role_id
                {$assignmentSqlContext['event_role_join']}
                {$assignmentSqlContext['role_settings_join']}
                WHERE ep.event_id = :event_id
                  AND {$normalizedSectorExpr} = :focus_sector
            ");
            $params = [
                ':event_id' => $eventId,
                ':focus_sector' => $focusSector,
            ];
            if (!empty($assignmentSqlContext['requires_organizer_id'])) {
                $params[':organizer_id'] = $organizerId;
            }
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $summary['assignments_total'] = (int)($row['assignments_total'] ?? 0);
            $summary['members_total'] = (int)($row['members_total'] ?? 0);
            $summary['managerial_total'] = (int)($row['managerial_total'] ?? 0);
            $summary['assignments_missing_bindings'] = (int)($row['assignments_missing_bindings'] ?? 0);
        }

        foreach ((array)($treeSnapshot['sector_overview'] ?? []) as $sectorRow) {
            if (self::normalizeWorkforceSector((string)($sectorRow['sector'] ?? '')) !== $focusSector) {
                continue;
            }

            $summary['manager_roots_total'] = (int)($sectorRow['manager_roots_total'] ?? 0);
            $summary['leadership_positions_total'] = (int)($sectorRow['leadership_positions_total'] ?? 0);
            $summary['leadership_filled_total'] = (int)($sectorRow['leadership_filled_total'] ?? 0);
            $summary['leadership_placeholder_total'] = (int)($sectorRow['leadership_placeholder_total'] ?? 0);
            $summary['operational_members_total'] = (int)($sectorRow['operational_members_total'] ?? 0);
            $summary['planned_team_size'] = (int)($sectorRow['planned_team_size'] ?? 0);
            $summary['filled_team_size'] = (int)($sectorRow['filled_team_size'] ?? 0);
            $summary['leadership_roles'] = array_values(array_map(static function (array $leader): array {
                return [
                    'event_role_id' => (int)($leader['event_role_id'] ?? 0),
                    'role_name' => (string)($leader['role_name'] ?? ''),
                    'leader_name' => (string)($leader['leader_name'] ?? ''),
                    'role_class' => (string)($leader['role_class'] ?? 'manager'),
                    'leader_bound' => !empty($leader['leader_bound']),
                    'is_root' => !empty($leader['is_root']),
                ];
            }, (array)($sectorRow['leadership_roles'] ?? [])));
            break;
        }

        return $summary;
    }

    private static function enrichSelectedManagerContext(array $selectedManager, array $treeSnapshot, string $focusSector): array
    {
        $matchedRoot = self::matchSelectedManagerRoot($selectedManager, $treeSnapshot, $focusSector);
        if ($matchedRoot === null) {
            return $selectedManager;
        }

        return array_merge($selectedManager, [
            'name' => self::nullableText($selectedManager['name'] ?? null)
                ?? self::nullableText($matchedRoot['leader_name'] ?? null)
                ?? self::nullableText($matchedRoot['role_name'] ?? null),
            'role_name' => self::nullableText($selectedManager['role_name'] ?? null)
                ?? self::nullableText($matchedRoot['role_name'] ?? null),
            'role_class' => self::nullableText($matchedRoot['role_class'] ?? null)
                ?? self::nullableText($selectedManager['role_class'] ?? null),
            'role_id' => self::nullablePositiveInt($matchedRoot['role_id'] ?? null)
                ?? self::nullablePositiveInt($selectedManager['role_id'] ?? null),
            'event_role_id' => self::nullablePositiveInt($matchedRoot['event_role_id'] ?? null)
                ?? self::nullablePositiveInt($selectedManager['event_role_id'] ?? null),
            'root_event_role_id' => self::nullablePositiveInt($matchedRoot['root_event_role_id'] ?? null)
                ?? self::nullablePositiveInt($selectedManager['root_event_role_id'] ?? null),
            'sector' => self::nullableText($matchedRoot['sector'] ?? null)
                ?? self::nullableText($selectedManager['sector'] ?? null),
            'leader_email' => self::nullableText($matchedRoot['leader_email'] ?? null),
            'leader_phone' => self::nullableText($matchedRoot['leader_phone'] ?? null),
            'planned_team_size' => (int)($matchedRoot['planned_team_size'] ?? 0),
            'filled_team_size' => (int)($matchedRoot['filled_team_size'] ?? 0),
            'leadership_positions_total' => (int)($matchedRoot['leadership_positions_total'] ?? 0),
            'leadership_filled_total' => (int)($matchedRoot['leadership_filled_total'] ?? 0),
            'leadership_placeholder_total' => (int)($matchedRoot['leadership_placeholder_total'] ?? 0),
            'operational_members_total' => (int)($matchedRoot['operational_members_total'] ?? 0),
            'leadership_roles' => array_map(static function (array $role): array {
                return [
                    'event_role_id' => (int)($role['event_role_id'] ?? 0),
                    'role_name' => (string)($role['role_name'] ?? ''),
                    'role_class' => (string)($role['role_class'] ?? 'operational'),
                    'leader_name' => (string)($role['leader_name'] ?? ''),
                    'leader_bound' => !empty($role['leader_bound']),
                ];
            }, (array)($matchedRoot['leadership_roles'] ?? [])),
        ]);
    }

    private static function buildWorkforceSelectedManagerTree(array $selectedManager, array $treeSnapshot): ?array
    {
        $matchedRoot = self::matchSelectedManagerRoot($selectedManager, $treeSnapshot, (string)($selectedManager['sector'] ?? ''));
        if ($matchedRoot === null) {
            return null;
        }

        return [
            'event_role_id' => (int)($matchedRoot['event_role_id'] ?? 0),
            'root_event_role_id' => (int)($matchedRoot['root_event_role_id'] ?? 0),
            'public_id' => (string)($matchedRoot['public_id'] ?? ''),
            'root_public_id' => (string)($matchedRoot['root_public_id'] ?? ''),
            'role_id' => (int)($matchedRoot['role_id'] ?? 0),
            'role_name' => (string)($matchedRoot['role_name'] ?? ''),
            'role_class' => (string)($matchedRoot['role_class'] ?? 'manager'),
            'authority_level' => (string)($matchedRoot['authority_level'] ?? ''),
            'sector' => (string)($matchedRoot['sector'] ?? 'geral'),
            'leader_name' => (string)($matchedRoot['leader_name'] ?? ''),
            'leader_email' => (string)($matchedRoot['leader_email'] ?? ''),
            'leader_phone' => (string)($matchedRoot['leader_phone'] ?? ''),
            'leader_bound' => !empty($matchedRoot['leader_bound']),
            'leadership_positions_total' => (int)($matchedRoot['leadership_positions_total'] ?? 0),
            'leadership_filled_total' => (int)($matchedRoot['leadership_filled_total'] ?? 0),
            'leadership_placeholder_total' => (int)($matchedRoot['leadership_placeholder_total'] ?? 0),
            'operational_members_total' => (int)($matchedRoot['operational_members_total'] ?? 0),
            'planned_team_size' => (int)($matchedRoot['planned_team_size'] ?? 0),
            'filled_team_size' => (int)($matchedRoot['filled_team_size'] ?? 0),
            'child_roles' => array_map(static function (array $role): array {
                return [
                    'event_role_id' => (int)($role['event_role_id'] ?? 0),
                    'role_name' => (string)($role['role_name'] ?? ''),
                    'role_class' => (string)($role['role_class'] ?? 'operational'),
                    'leader_name' => (string)($role['leader_name'] ?? ''),
                    'leader_email' => (string)($role['leader_email'] ?? ''),
                    'leader_phone' => (string)($role['leader_phone'] ?? ''),
                    'leader_bound' => !empty($role['leader_bound']),
                    'direct_operational_members_total' => (int)($role['direct_operational_members_total'] ?? 0),
                ];
            }, (array)($matchedRoot['child_roles'] ?? [])),
            'leadership_roles' => array_map(static function (array $role): array {
                return [
                    'event_role_id' => (int)($role['event_role_id'] ?? 0),
                    'role_name' => (string)($role['role_name'] ?? ''),
                    'role_class' => (string)($role['role_class'] ?? 'operational'),
                    'leader_name' => (string)($role['leader_name'] ?? ''),
                    'leader_bound' => !empty($role['leader_bound']),
                    'direct_operational_members_total' => (int)($role['direct_operational_members_total'] ?? 0),
                    'is_root' => !empty($role['is_root']),
                ];
            }, (array)($matchedRoot['leadership_roles'] ?? [])),
        ];
    }

    private static function buildSelectedManagerOperationalConfig(
        PDO $db,
        int $organizerId,
        int $eventId,
        array $selectedManager
    ): ?array {
        $eventRoleId = (int)($selectedManager['event_role_id'] ?? 0);
        if (
            $eventRoleId > 0
            && self::tableExists($db, 'workforce_event_roles')
            && self::tableExists($db, 'workforce_roles')
        ) {
            $stmt = $db->prepare('
                SELECT
                    wer.id AS event_role_id,
                    wer.role_id,
                    wr.name AS role_name,
                    COALESCE(wer.cost_bucket, \'\') AS cost_bucket,
                    COALESCE(wer.role_class, \'\') AS role_class,
                    COALESCE(wer.authority_level, \'none\') AS authority_level,
                    COALESCE(wer.max_shifts_event, 1)::int AS max_shifts_event,
                    COALESCE(wer.shift_hours, 8)::numeric AS shift_hours,
                    COALESCE(wer.meals_per_day, 4)::int AS meals_per_day,
                    COALESCE(wer.payment_amount, 0)::numeric AS payment_amount
                FROM public.workforce_event_roles wer
                JOIN public.workforce_roles wr ON wr.id = wer.role_id
                WHERE wer.organizer_id = :organizer_id
                  AND wer.event_id = :event_id
                  AND wer.id = :event_role_id
                LIMIT 1
            ');
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':event_id' => $eventId,
                ':event_role_id' => $eventRoleId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($row !== []) {
                $roleName = (string)($row['role_name'] ?? '');
                $costBucket = \normalizeCostBucket((string)($row['cost_bucket'] ?? ''), $roleName);
                return [
                    'event_role_id' => (int)($row['event_role_id'] ?? 0),
                    'role_id' => (int)($row['role_id'] ?? 0),
                    'role_name' => $roleName,
                    'cost_bucket' => $costBucket,
                    'role_class' => (string)($row['role_class'] ?? \workforceResolveRoleClass($roleName, $costBucket)),
                    'authority_level' => (string)($row['authority_level'] ?? 'none'),
                    'max_shifts_event' => (int)($row['max_shifts_event'] ?? 1),
                    'shift_hours' => (float)($row['shift_hours'] ?? 8),
                    'meals_per_day' => (int)($row['meals_per_day'] ?? 4),
                    'payment_amount' => (float)($row['payment_amount'] ?? 0),
                    'source' => 'event_role',
                ];
            }
        }

        $roleId = (int)($selectedManager['role_id'] ?? 0);
        if (
            $roleId > 0
            && self::tableExists($db, 'workforce_role_settings')
            && self::tableExists($db, 'workforce_roles')
        ) {
            $stmt = $db->prepare('
                SELECT
                    wrs.role_id,
                    wr.name AS role_name,
                    COALESCE(wrs.cost_bucket, \'\') AS cost_bucket,
                    COALESCE(wrs.max_shifts_event, 1)::int AS max_shifts_event,
                    COALESCE(wrs.shift_hours, 8)::numeric AS shift_hours,
                    COALESCE(wrs.meals_per_day, 4)::int AS meals_per_day,
                    COALESCE(wrs.payment_amount, 0)::numeric AS payment_amount
                FROM public.workforce_role_settings wrs
                JOIN public.workforce_roles wr ON wr.id = wrs.role_id
                WHERE wrs.organizer_id = :organizer_id
                  AND wrs.role_id = :role_id
                LIMIT 1
            ');
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':role_id' => $roleId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($row !== []) {
                $roleName = (string)($row['role_name'] ?? '');
                $costBucket = \normalizeCostBucket((string)($row['cost_bucket'] ?? ''), $roleName);
                return [
                    'event_role_id' => null,
                    'role_id' => (int)($row['role_id'] ?? 0),
                    'role_name' => $roleName,
                    'cost_bucket' => $costBucket,
                    'role_class' => \workforceResolveRoleClass($roleName, $costBucket),
                    'authority_level' => 'none',
                    'max_shifts_event' => (int)($row['max_shifts_event'] ?? 1),
                    'shift_hours' => (float)($row['shift_hours'] ?? 8),
                    'meals_per_day' => (int)($row['meals_per_day'] ?? 4),
                    'payment_amount' => (float)($row['payment_amount'] ?? 0),
                    'source' => 'role_settings',
                ];
            }
        }

        return null;
    }

    private static function buildWorkforceCostSnapshot(PDO $db, int $organizerId, int $eventId): array
    {
        $snapshot = [
            'summary' => [
                'planned_members_total' => 0,
                'filled_members_total' => 0,
                'leadership_positions_total' => 0,
                'leadership_filled_total' => 0,
                'leadership_placeholder_total' => 0,
                'operational_members_total' => 0,
                'estimated_payment_total' => 0.0,
                'estimated_hours_total' => 0.0,
                'estimated_meals_total' => 0,
                'meal_unit_cost' => 0.0,
                'meal_services_costs_label' => '',
                'event_meal_services' => [],
                'estimated_meals_cost_total' => 0.0,
                'estimated_total_cost' => 0.0,
                'managerial_roles_payment_total' => 0.0,
                'operational_members_payment_total' => 0.0,
            ],
            'by_sector' => [],
            'by_role_managerial' => [],
        ];

        if ($eventId <= 0) {
            return $snapshot;
        }

        $mealUnitCost = self::loadMealUnitCost($db, $organizerId);
        $mealCostContext = MealsDomainService::buildCostContext($db, $organizerId, $eventId);
        $activeMealServices = $mealCostContext['active_services'] ?? [];
        $snapshot['summary']['meal_unit_cost'] = round($mealUnitCost, 2);
        $snapshot['summary']['meal_services_costs_label'] = MealsDomainService::buildServiceCostLabel($mealCostContext['services'] ?? []);
        $snapshot['summary']['event_meal_services'] = $mealCostContext['services'] ?? [];

        $bySector = [];
        $byRoleManagerial = [];
        $totalEstimatedPayment = 0.0;
        $totalEstimatedHours = 0.0;
        $totalEstimatedMeals = 0;
        $totalEstimatedMealsCost = 0.0;
        $managerialRolesPaymentTotal = 0.0;
        $operationalMembersPaymentTotal = 0.0;
        $plannedMembersTotal = 0;
        $filledMembersTotal = 0;
        $leadershipPositionsTotal = 0;
        $leadershipFilledTotal = 0;
        $leadershipPlaceholderTotal = 0;
        $operationalMembersTotal = 0;

        $ensureSectorRow = static function (array &$collection, string $sector): void {
            if (!isset($collection[$sector])) {
                $collection[$sector] = [
                    'sector' => $sector,
                    'planned_members_total' => 0,
                    'filled_members_total' => 0,
                    'leadership_positions_total' => 0,
                    'leadership_filled_total' => 0,
                    'leadership_placeholder_total' => 0,
                    'operational_members_total' => 0,
                    'estimated_payment_total' => 0.0,
                    'estimated_hours_total' => 0.0,
                    'estimated_meals_total' => 0,
                    'estimated_meals_cost_total' => 0.0,
                    'estimated_total_cost' => 0.0,
                ];
            }
        };

        if (self::tableExists($db, 'workforce_assignments') && self::tableExists($db, 'event_participants') && self::tableExists($db, 'workforce_roles')) {
            $hasMemberSettings = self::tableExists($db, 'workforce_member_settings');
            $hasEventRoles = self::tableExists($db, 'workforce_event_roles') && self::columnExists($db, 'workforce_assignments', 'event_role_id');
            $assignmentSqlContext = self::buildWorkforceAssignmentSqlContext($db, 'wa', 'r');
            $memberSettingsJoin = $hasMemberSettings ? 'LEFT JOIN public.workforce_member_settings wms ON wms.participant_id = ep.id' : '';
            $eventRoleJoin = $assignmentSqlContext['event_role_join'];
            $roleSettingsJoin = $assignmentSqlContext['role_settings_join'];
            $sectorExpr = $assignmentSqlContext['normalized_sector_expr'];
            $assignmentCostBucketExpr = $assignmentSqlContext['cost_bucket_expr'];

            $maxShiftsExpr = $hasMemberSettings && $hasEventRoles
                ? "COALESCE(wms.max_shifts_event, wer.max_shifts_event, 1)"
                : ($hasMemberSettings
                    ? "COALESCE(wms.max_shifts_event, 1)"
                    : ($hasEventRoles ? "COALESCE(wer.max_shifts_event, 1)" : '1'));
            $shiftHoursExpr = $hasMemberSettings && $hasEventRoles
                ? "COALESCE(wms.shift_hours, wer.shift_hours, 8)"
                : ($hasMemberSettings
                    ? "COALESCE(wms.shift_hours, 8)"
                    : ($hasEventRoles ? "COALESCE(wer.shift_hours, 8)" : '8'));
            $mealsExpr = $hasMemberSettings && $hasEventRoles
                ? "COALESCE(wms.meals_per_day, wer.meals_per_day, 4)"
                : ($hasMemberSettings
                    ? "COALESCE(wms.meals_per_day, 4)"
                    : ($hasEventRoles ? "COALESCE(wer.meals_per_day, 4)" : '4'));
            $paymentExpr = $hasMemberSettings && $hasEventRoles
                ? "COALESCE(wms.payment_amount, wer.payment_amount, 0)"
                : ($hasMemberSettings
                    ? "COALESCE(wms.payment_amount, 0)"
                    : ($hasEventRoles ? "COALESCE(wer.payment_amount, 0)" : '0'));

            $stmt = $db->prepare("
                SELECT
                    {$sectorExpr} AS sector,
                    {$paymentExpr}::numeric AS payment_amount,
                    {$maxShiftsExpr}::int AS max_shifts_event,
                    {$shiftHoursExpr}::numeric AS shift_hours,
                    {$mealsExpr}::int AS meals_per_day
                FROM public.workforce_assignments wa
                JOIN public.event_participants ep ON ep.id = wa.participant_id
                JOIN public.workforce_roles r ON r.id = wa.role_id
                {$memberSettingsJoin}
                {$eventRoleJoin}
                {$roleSettingsJoin}
                WHERE ep.event_id = :event_id
                  AND LOWER(COALESCE(wa.sector, '')) <> 'externo'
                  AND LOWER(COALESCE(r.name, '')) NOT LIKE '%externo%'
                  AND {$assignmentCostBucketExpr} <> 'managerial'
            ");
            $params = [':event_id' => $eventId];
            if (!empty($assignmentSqlContext['requires_organizer_id'])) {
                $params[':organizer_id'] = $organizerId;
            }
            $stmt->execute($params);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $sector = self::normalizeWorkforceSector((string)($row['sector'] ?? '')) ?: 'geral';
                $paymentAmount = (float)($row['payment_amount'] ?? 0);
                $maxShifts = (int)($row['max_shifts_event'] ?? 1);
                $shiftHours = (float)($row['shift_hours'] ?? 8);
                $mealsPerDay = (int)($row['meals_per_day'] ?? 4);
                $estimatedPayment = round($paymentAmount * $maxShifts, 2);
                $estimatedHours = round($maxShifts * $shiftHours, 2);
                $estimatedMeals = $maxShifts * $mealsPerDay;
                $estimatedMealsCost = round(
                    $maxShifts * MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost),
                    2
                );

                $ensureSectorRow($bySector, $sector);
                $bySector[$sector]['planned_members_total']++;
                $bySector[$sector]['filled_members_total']++;
                $bySector[$sector]['operational_members_total']++;
                $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
                $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
                $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
                $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

                $plannedMembersTotal++;
                $filledMembersTotal++;
                $operationalMembersTotal++;
                $operationalMembersPaymentTotal += $estimatedPayment;
                $totalEstimatedPayment += $estimatedPayment;
                $totalEstimatedHours += $estimatedHours;
                $totalEstimatedMeals += $estimatedMeals;
                $totalEstimatedMealsCost += $estimatedMealsCost;
            }
        }

        if ($eventId > 0 && self::tableExists($db, 'workforce_event_roles') && self::tableExists($db, 'workforce_roles')) {
            $sectorExpr = "COALESCE(NULLIF(" . self::workforceNormalizedSectorExpr(
                "COALESCE(NULLIF(TRIM(wer.sector), ''), 'geral')"
            ) . ", ''), 'geral')";
            $stmt = $db->prepare("
                SELECT
                    wer.id AS event_role_id,
                    {$sectorExpr} AS sector,
                    wr.name AS role_name,
                    COALESCE(wer.role_class, '') AS role_class,
                    COALESCE(wer.cost_bucket, '') AS cost_bucket,
                    COALESCE(wer.payment_amount, 0)::numeric AS payment_amount,
                    COALESCE(wer.max_shifts_event, 1)::int AS max_shifts_event,
                    COALESCE(wer.shift_hours, 8)::numeric AS shift_hours,
                    COALESCE(wer.meals_per_day, 0)::int AS meals_per_day,
                    COALESCE(wer.leader_user_id, 0)::int AS leader_user_id,
                    COALESCE(wer.leader_participant_id, 0)::int AS leader_participant_id,
                    COALESCE(wer.leader_name, '') AS leader_name,
                    COALESCE(wer.leader_cpf, '') AS leader_cpf
                FROM public.workforce_event_roles wer
                JOIN public.workforce_roles wr ON wr.id = wer.role_id
                WHERE wer.organizer_id = :organizer_id
                  AND wer.event_id = :event_id
                  AND wer.is_active = true
                ORDER BY sector ASC, wr.name ASC
            ");
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':event_id' => $eventId,
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $roleName = (string)($row['role_name'] ?? 'Cargo Gerencial');
                $costBucket = \normalizeCostBucket((string)($row['cost_bucket'] ?? ''), $roleName);
                if ($costBucket !== 'managerial') {
                    continue;
                }

                $sector = self::normalizeWorkforceSector((string)($row['sector'] ?? '')) ?: 'geral';
                $paymentAmount = (float)($row['payment_amount'] ?? 0);
                $maxShifts = (int)($row['max_shifts_event'] ?? 1);
                $shiftHours = (float)($row['shift_hours'] ?? 8);
                $mealsPerDay = (int)($row['meals_per_day'] ?? 0);
                $isFilled = \workforceHasLeadershipIdentity($row);
                $estimatedPayment = round($paymentAmount * $maxShifts, 2);
                $estimatedHours = round($maxShifts * $shiftHours, 2);
                $estimatedMeals = $maxShifts * $mealsPerDay;
                $estimatedMealsCost = round(
                    $maxShifts * MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost),
                    2
                );

                $ensureSectorRow($bySector, $sector);
                $bySector[$sector]['planned_members_total']++;
                $bySector[$sector]['leadership_positions_total']++;
                if ($isFilled) {
                    $bySector[$sector]['filled_members_total']++;
                    $bySector[$sector]['leadership_filled_total']++;
                } else {
                    $bySector[$sector]['leadership_placeholder_total']++;
                }
                $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
                $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
                $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
                $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

                $byRoleManagerial[] = [
                    'event_role_id' => (int)($row['event_role_id'] ?? 0),
                    'sector' => $sector,
                    'role_name' => $roleName,
                    'role_class' => (string)($row['role_class'] ?? \workforceResolveRoleClass($roleName, $costBucket)),
                    'estimated_payment_total' => $estimatedPayment,
                    'estimated_hours_total' => $estimatedHours,
                    'estimated_meals_total' => $estimatedMeals,
                    'estimated_meals_cost_total' => $estimatedMealsCost,
                    'filled_members_total' => $isFilled ? 1 : 0,
                    'leadership_placeholder_total' => $isFilled ? 0 : 1,
                ];

                $plannedMembersTotal++;
                $leadershipPositionsTotal++;
                if ($isFilled) {
                    $filledMembersTotal++;
                    $leadershipFilledTotal++;
                } else {
                    $leadershipPlaceholderTotal++;
                }
                $managerialRolesPaymentTotal += $estimatedPayment;
                $totalEstimatedPayment += $estimatedPayment;
                $totalEstimatedHours += $estimatedHours;
                $totalEstimatedMeals += $estimatedMeals;
                $totalEstimatedMealsCost += $estimatedMealsCost;
            }
        }

        foreach ($bySector as &$row) {
            $row['estimated_payment_total'] = round((float)($row['estimated_payment_total'] ?? 0), 2);
            $row['estimated_hours_total'] = round((float)($row['estimated_hours_total'] ?? 0), 2);
            $row['estimated_meals_cost_total'] = round((float)($row['estimated_meals_cost_total'] ?? 0), 2);
            $row['estimated_total_cost'] = round(
                (float)($row['estimated_payment_total'] ?? 0) + (float)($row['estimated_meals_cost_total'] ?? 0),
                2
            );
        }
        unset($row);

        usort($byRoleManagerial, static function (array $left, array $right): int {
            $leftSector = (string)($left['sector'] ?? '');
            $rightSector = (string)($right['sector'] ?? '');
            if ($leftSector !== $rightSector) {
                return strcasecmp($leftSector, $rightSector);
            }

            return strcasecmp((string)($left['role_name'] ?? ''), (string)($right['role_name'] ?? ''));
        });

        $snapshot['summary'] = [
            'planned_members_total' => $plannedMembersTotal,
            'filled_members_total' => $filledMembersTotal,
            'leadership_positions_total' => $leadershipPositionsTotal,
            'leadership_filled_total' => $leadershipFilledTotal,
            'leadership_placeholder_total' => $leadershipPlaceholderTotal,
            'operational_members_total' => $operationalMembersTotal,
            'estimated_payment_total' => round($totalEstimatedPayment, 2),
            'estimated_hours_total' => round($totalEstimatedHours, 2),
            'estimated_meals_total' => $totalEstimatedMeals,
            'meal_unit_cost' => round($mealUnitCost, 2),
            'meal_services_costs_label' => MealsDomainService::buildServiceCostLabel($mealCostContext['services'] ?? []),
            'event_meal_services' => $mealCostContext['services'] ?? [],
            'estimated_meals_cost_total' => round($totalEstimatedMealsCost, 2),
            'estimated_total_cost' => round($totalEstimatedPayment + $totalEstimatedMealsCost, 2),
            'managerial_roles_payment_total' => round($managerialRolesPaymentTotal, 2),
            'operational_members_payment_total' => round($operationalMembersPaymentTotal, 2),
        ];
        $snapshot['by_sector'] = array_values($bySector);
        usort($snapshot['by_sector'], static function (array $left, array $right): int {
            if ((float)($left['estimated_total_cost'] ?? 0) !== (float)($right['estimated_total_cost'] ?? 0)) {
                return (float)($right['estimated_total_cost'] ?? 0) <=> (float)($left['estimated_total_cost'] ?? 0);
            }

            return strcasecmp((string)($left['sector'] ?? ''), (string)($right['sector'] ?? ''));
        });
        $snapshot['by_role_managerial'] = $byRoleManagerial;

        return $snapshot;
    }

    private static function buildWorkforceFocusCostSnapshot(array $costSnapshot, string $focusSector): ?array
    {
        if ($focusSector === '') {
            return null;
        }

        foreach ((array)($costSnapshot['by_sector'] ?? []) as $row) {
            if (self::normalizeWorkforceSector((string)($row['sector'] ?? '')) === $focusSector) {
                return $row;
            }
        }

        return null;
    }

    private static function buildWorkforceAttentionSectors(array $treeSnapshot, array $costSnapshot): array
    {
        $costBySector = [];
        foreach ((array)($costSnapshot['by_sector'] ?? []) as $sectorCost) {
            $sectorKey = self::normalizeWorkforceSector((string)($sectorCost['sector'] ?? ''));
            if ($sectorKey === '') {
                continue;
            }
            $costBySector[$sectorKey] = $sectorCost;
        }

        $rows = array_map(static function (array $sectorRow) use ($costBySector): array {
            $sectorKey = self::normalizeWorkforceSector((string)($sectorRow['sector'] ?? '')) ?: 'geral';
            $costRow = $costBySector[$sectorKey] ?? [];
            return [
                'sector' => $sectorKey,
                'manager_roots_total' => (int)($sectorRow['manager_roots_total'] ?? 0),
                'leadership_positions_total' => (int)($sectorRow['leadership_positions_total'] ?? 0),
                'leadership_filled_total' => (int)($sectorRow['leadership_filled_total'] ?? 0),
                'leadership_placeholder_total' => (int)($sectorRow['leadership_placeholder_total'] ?? 0),
                'planned_team_size' => (int)($sectorRow['planned_team_size'] ?? 0),
                'filled_team_size' => (int)($sectorRow['filled_team_size'] ?? 0),
                'operational_members_total' => (int)($sectorRow['operational_members_total'] ?? 0),
                'estimated_total_cost' => round((float)($costRow['estimated_total_cost'] ?? 0), 2),
                'estimated_meals_total' => (int)($costRow['estimated_meals_total'] ?? 0),
                'leadership_roles' => array_slice((array)($sectorRow['leadership_roles'] ?? []), 0, 8),
            ];
        }, (array)($treeSnapshot['sector_overview'] ?? []));

        usort($rows, static function (array $left, array $right): int {
            if ((int)($left['leadership_placeholder_total'] ?? 0) !== (int)($right['leadership_placeholder_total'] ?? 0)) {
                return (int)($right['leadership_placeholder_total'] ?? 0) <=> (int)($left['leadership_placeholder_total'] ?? 0);
            }
            if ((int)($left['planned_team_size'] ?? 0) !== (int)($right['planned_team_size'] ?? 0)) {
                return (int)($right['planned_team_size'] ?? 0) <=> (int)($left['planned_team_size'] ?? 0);
            }
            if ((float)($left['estimated_total_cost'] ?? 0) !== (float)($right['estimated_total_cost'] ?? 0)) {
                return (float)($right['estimated_total_cost'] ?? 0) <=> (float)($left['estimated_total_cost'] ?? 0);
            }

            return strcasecmp((string)($left['sector'] ?? ''), (string)($right['sector'] ?? ''));
        });

        return array_slice($rows, 0, 8);
    }

    private static function buildWorkforceLeadershipDigest(array $treeSnapshot): array
    {
        $rows = array_map(
            static fn(array $sectorRow): array => self::mapWorkforceLeadershipSectorDigestRow($sectorRow),
            (array)($treeSnapshot['sector_overview'] ?? [])
        );

        usort($rows, static function (array $left, array $right): int {
            if ((int)($left['leadership_placeholder_total'] ?? 0) !== (int)($right['leadership_placeholder_total'] ?? 0)) {
                return (int)($right['leadership_placeholder_total'] ?? 0) <=> (int)($left['leadership_placeholder_total'] ?? 0);
            }
            if ((int)($left['leadership_positions_total'] ?? 0) !== (int)($right['leadership_positions_total'] ?? 0)) {
                return (int)($right['leadership_positions_total'] ?? 0) <=> (int)($left['leadership_positions_total'] ?? 0);
            }

            return strcasecmp((string)($left['sector'] ?? ''), (string)($right['sector'] ?? ''));
        });

        return array_slice($rows, 0, 8);
    }

    private static function mapWorkforceLeadershipSectorDigestRow(array $sectorRow): array
    {
        return [
            'sector' => self::normalizeWorkforceSector((string)($sectorRow['sector'] ?? '')) ?: 'geral',
            'manager_roots_total' => (int)($sectorRow['manager_roots_total'] ?? 0),
            'leadership_positions_total' => (int)($sectorRow['leadership_positions_total'] ?? 0),
            'leadership_filled_total' => (int)($sectorRow['leadership_filled_total'] ?? 0),
            'leadership_placeholder_total' => (int)($sectorRow['leadership_placeholder_total'] ?? 0),
            'planned_team_size' => (int)($sectorRow['planned_team_size'] ?? 0),
            'filled_team_size' => (int)($sectorRow['filled_team_size'] ?? 0),
            'operational_members_total' => (int)($sectorRow['operational_members_total'] ?? 0),
            'leaders' => array_slice(array_map(static function (array $leader): array {
                $roleName = (string)($leader['role_name'] ?? '');
                $leaderName = trim((string)($leader['leader_name'] ?? ''));
                return [
                    'event_role_id' => (int)($leader['event_role_id'] ?? 0),
                    'role_name' => $roleName,
                    'role_class' => (string)($leader['role_class'] ?? 'operational'),
                    'leader_name' => $leaderName,
                    'leader_display_name' => $leaderName !== '' ? $leaderName : $roleName,
                    'leader_bound' => !empty($leader['leader_bound']),
                    'is_root' => !empty($leader['is_root']),
                ];
            }, (array)($sectorRow['leadership_roles'] ?? [])), 0, 12),
        ];
    }

    private static function resolveWorkforceEffectiveFocus(
        array $selectedManager,
        string $requestedFocusSector,
        array $attentionSectors
    ): array {
        $selectedSector = self::normalizeWorkforceSector((string)($selectedManager['sector'] ?? ''));
        $selectedManagerPresent = $selectedSector !== ''
            && (
                self::nullablePositiveInt($selectedManager['event_role_id'] ?? null) !== null
                || self::nullablePositiveInt($selectedManager['root_event_role_id'] ?? null) !== null
                || self::nullableText($selectedManager['role_name'] ?? null) !== null
                || self::nullableText($selectedManager['name'] ?? null) !== null
            );
        if ($selectedManagerPresent) {
            return [
                'sector' => $selectedSector,
                'source' => 'selected_manager',
            ];
        }

        if ($requestedFocusSector !== '') {
            return [
                'sector' => $requestedFocusSector,
                'source' => 'explicit_sector',
            ];
        }

        foreach ($attentionSectors as $sectorRow) {
            $sector = self::normalizeWorkforceSector((string)($sectorRow['sector'] ?? ''));
            if ($sector !== '') {
                return [
                    'sector' => $sector,
                    'source' => 'attention_sector',
                ];
            }
        }

        return [
            'sector' => '',
            'source' => 'none',
        ];
    }

    private static function buildWorkforceEffectiveFocusPayload(
        string $sector,
        string $source,
        array $treeSnapshot,
        ?array $focusSectorSummary,
        ?array $focusCostSnapshot,
        ?array $focusMealExecution
    ): ?array {
        if ($sector === '') {
            return null;
        }

        foreach ((array)($treeSnapshot['sector_overview'] ?? []) as $sectorRow) {
            if (self::normalizeWorkforceSector((string)($sectorRow['sector'] ?? '')) !== $sector) {
                continue;
            }

            return [
                'sector' => $sector,
                'source' => $source,
                'leadership' => self::mapWorkforceLeadershipSectorDigestRow($sectorRow),
                'sector_summary' => $focusSectorSummary,
                'costs' => $focusCostSnapshot,
                'meal_execution' => $focusMealExecution,
            ];
        }

        return [
            'sector' => $sector,
            'source' => $source,
            'leadership' => null,
            'sector_summary' => $focusSectorSummary,
            'costs' => $focusCostSnapshot,
            'meal_execution' => $focusMealExecution,
        ];
    }

    private static function buildWorkforceMealExecutionSnapshot(PDO $db, int $organizerId, int $eventId): array
    {
        $snapshot = [
            'summary' => [
                'served_meals_total' => 0,
                'served_meals_cost_total' => 0.0,
                'participants_with_meals_total' => 0,
            ],
            'by_sector' => [],
        ];

        if (
            $eventId <= 0
            || !self::tableExists($db, 'participant_meals')
            || !self::tableExists($db, 'event_participants')
            || !self::tableExists($db, 'events')
        ) {
            return $snapshot;
        }

        $mealUnitCost = self::loadMealUnitCost($db, $organizerId);
        $hasEventMealServices = self::tableExists($db, 'event_meal_services');
        $hasAssignmentEventRoles = self::tableExists($db, 'workforce_event_roles')
            && self::columnExists($db, 'workforce_assignments', 'event_role_id');
        $hasAssignmentRoles = self::tableExists($db, 'workforce_roles');
        $mealServiceJoin = $hasEventMealServices ? 'LEFT JOIN public.event_meal_services ems ON ems.id = pm.meal_service_id' : '';
        $participantSectorEventRoleJoin = $hasAssignmentEventRoles
            ? 'LEFT JOIN public.workforce_event_roles wer ON wer.id = wa.event_role_id'
            : '';
        $participantSectorRoleJoin = $hasAssignmentRoles
            ? 'LEFT JOIN public.workforce_roles wr ON wr.id = wa.role_id'
            : '';
        $participantSectorExpr = "COALESCE(NULLIF(" . self::workforceNormalizedSectorExpr(
            "COALESCE(NULLIF(TRIM(wa.sector), ''), "
            . ($hasAssignmentEventRoles ? "NULLIF(TRIM(COALESCE(wer.sector, '')), ''), " : '')
            . ($hasAssignmentRoles ? "NULLIF(TRIM(COALESCE(wr.sector, '')), ''), " : '')
            . "'geral')"
        ) . ", ''), 'geral')";
        $mealCostExpr = $hasEventMealServices
            ? "COALESCE(pm.unit_cost_applied, ems.unit_cost, :meal_unit_cost)"
            : "COALESCE(pm.unit_cost_applied, :meal_unit_cost)";

        $sectorSql = "
            WITH participant_sector AS (
                SELECT
                    ep.id AS participant_id,
                    CASE
                        WHEN COUNT(DISTINCT {$participantSectorExpr}) = 1
                            THEN MAX({$participantSectorExpr})
                        ELSE 'mixed'
                    END AS sector
                FROM public.event_participants ep
                LEFT JOIN public.workforce_assignments wa ON wa.participant_id = ep.id
                {$participantSectorEventRoleJoin}
                {$participantSectorRoleJoin}
                WHERE ep.event_id = :event_id
                GROUP BY ep.id
            )
            SELECT
                COALESCE(ps.sector, 'geral') AS sector,
                COUNT(*)::int AS served_meals_total,
                COUNT(DISTINCT pm.participant_id)::int AS participants_with_meals_total,
                COALESCE(SUM({$mealCostExpr}), 0)::numeric AS served_meals_cost_total
            FROM public.participant_meals pm
            JOIN public.event_participants ep ON ep.id = pm.participant_id
            JOIN public.events e ON e.id = ep.event_id
            LEFT JOIN participant_sector ps ON ps.participant_id = pm.participant_id
            {$mealServiceJoin}
            WHERE ep.event_id = :event_id
              AND e.organizer_id = :organizer_id
            GROUP BY 1
        ";

        $stmt = $db->prepare($sectorSql);
        $stmt->execute([
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
            ':meal_unit_cost' => $mealUnitCost,
        ]);

        $bySector = [];
        $servedMealsTotal = 0;
        $servedMealsCostTotal = 0.0;
        $participantsWithMealsTotal = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $servedMeals = (int)($row['served_meals_total'] ?? 0);
            $servedCost = round((float)($row['served_meals_cost_total'] ?? 0), 2);
            $participantsWithMeals = (int)($row['participants_with_meals_total'] ?? 0);
            $bySector[] = [
                'sector' => self::normalizeWorkforceSector((string)($row['sector'] ?? '')) ?: 'geral',
                'served_meals_total' => $servedMeals,
                'participants_with_meals_total' => $participantsWithMeals,
                'served_meals_cost_total' => $servedCost,
            ];
            $servedMealsTotal += $servedMeals;
            $servedMealsCostTotal += $servedCost;
            $participantsWithMealsTotal += $participantsWithMeals;
        }

        usort($bySector, static function (array $left, array $right): int {
            if ((int)($left['served_meals_total'] ?? 0) !== (int)($right['served_meals_total'] ?? 0)) {
                return (int)($right['served_meals_total'] ?? 0) <=> (int)($left['served_meals_total'] ?? 0);
            }

            return strcasecmp((string)($left['sector'] ?? ''), (string)($right['sector'] ?? ''));
        });

        $snapshot['summary'] = [
            'served_meals_total' => $servedMealsTotal,
            'served_meals_cost_total' => round($servedMealsCostTotal, 2),
            'participants_with_meals_total' => $participantsWithMealsTotal,
        ];
        $snapshot['by_sector'] = $bySector;

        return $snapshot;
    }

    private static function buildWorkforceFocusMealExecutionSnapshot(array $mealExecutionSnapshot, string $focusSector): ?array
    {
        if ($focusSector === '') {
            return null;
        }

        foreach ((array)($mealExecutionSnapshot['by_sector'] ?? []) as $row) {
            if (self::normalizeWorkforceSector((string)($row['sector'] ?? '')) === $focusSector) {
                return $row;
            }
        }

        return null;
    }

    private static function loadMealUnitCost(PDO $db, int $organizerId): float
    {
        if (!self::tableExists($db, 'organizer_financial_settings') || !self::columnExists($db, 'organizer_financial_settings', 'meal_unit_cost')) {
            return 0.0;
        }

        $stmt = $db->prepare('
            SELECT COALESCE(meal_unit_cost, 0)
            FROM public.organizer_financial_settings
            WHERE organizer_id = :organizer_id
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([':organizer_id' => $organizerId]);
        return (float)($stmt->fetchColumn() ?: 0);
    }

    private static function matchSelectedManagerRoot(array $selectedManager, array $treeSnapshot, string $fallbackSector = ''): ?array
    {
        $roots = is_array($treeSnapshot['manager_roots'] ?? null) ? $treeSnapshot['manager_roots'] : [];
        if ($roots === []) {
            return null;
        }

        $selectedRootEventRoleId = (int)($selectedManager['root_event_role_id'] ?? 0);
        if ($selectedRootEventRoleId > 0) {
            foreach ($roots as $root) {
                if ((int)($root['root_event_role_id'] ?? 0) === $selectedRootEventRoleId) {
                    return $root;
                }
            }
        }

        $selectedEventRoleId = (int)($selectedManager['event_role_id'] ?? 0);
        if ($selectedEventRoleId > 0) {
            foreach ($roots as $root) {
                if ((int)($root['event_role_id'] ?? 0) === $selectedEventRoleId) {
                    return $root;
                }

                foreach ((array)($root['leadership_roles'] ?? []) as $role) {
                    if ((int)($role['event_role_id'] ?? 0) === $selectedEventRoleId) {
                        return $root;
                    }
                }
            }
        }

        $sectorKey = self::normalizeWorkforceSector((string)($selectedManager['sector'] ?? $fallbackSector));
        $roleKey = self::normalizeLookupValue($selectedManager['role_name'] ?? null);
        $nameKey = self::normalizeLookupValue($selectedManager['name'] ?? null);

        foreach ($roots as $root) {
            $rootSector = self::normalizeWorkforceSector((string)($root['sector'] ?? ''));
            if ($sectorKey !== '' && $rootSector !== $sectorKey) {
                continue;
            }
            if ($roleKey !== '' && self::normalizeLookupValue($root['role_name'] ?? null) !== $roleKey) {
                continue;
            }
            if (
                $nameKey !== ''
                && self::normalizeLookupValue($root['leader_name'] ?? null) !== $nameKey
                && self::normalizeLookupValue($root['role_name'] ?? null) !== $nameKey
            ) {
                continue;
            }

            return $root;
        }

        if ($sectorKey !== '') {
            $sectorMatches = array_values(array_filter($roots, static function (array $root) use ($sectorKey): bool {
                return self::normalizeWorkforceSector((string)($root['sector'] ?? '')) === $sectorKey;
            }));
            if (count($sectorMatches) === 1) {
                return $sectorMatches[0];
            }
        }

        return null;
    }

    private static function resolveWorkforceFocusSector(array $context): string
    {
        foreach ([
            $context['selected_manager_sector'] ?? null,
            $context['focus_sector'] ?? null,
            $context['manager_sector'] ?? null,
        ] as $candidate) {
            $normalized = self::normalizeWorkforceSector($candidate);
            if ($normalized !== '' && !in_array($normalized, ['workforce', 'general'], true)) {
                return $normalized;
            }
        }

        return '';
    }

    private static function buildWorkforceAssignmentSqlContext(PDO $db, string $assignmentAlias, string $roleAlias): array
    {
        $hasEventRoles = self::tableExists($db, 'workforce_event_roles')
            && self::columnExists($db, 'workforce_assignments', 'event_role_id');
        $hasRoleSettings = self::tableExists($db, 'workforce_role_settings');

        $eventRoleJoin = $hasEventRoles
            ? "LEFT JOIN public.workforce_event_roles wer ON wer.id = {$assignmentAlias}.event_role_id"
            : '';
        $roleSettingsJoin = $hasRoleSettings
            ? "LEFT JOIN public.workforce_role_settings wrs ON wrs.role_id = {$roleAlias}.id AND wrs.organizer_id = :organizer_id"
            : '';

        $costBucketSources = [];
        if ($hasEventRoles) {
            $costBucketSources[] = "NULLIF(TRIM(COALESCE(wer.cost_bucket, '')), '')";
        }
        if ($hasRoleSettings) {
            $costBucketSources[] = "NULLIF(TRIM(COALESCE(wrs.cost_bucket, '')), '')";
        }
        $costBucketSources[] = function_exists('workforceInferCostBucketSql')
            ? \workforceInferCostBucketSql("COALESCE({$roleAlias}.name, '')")
            : "CASE
                    WHEN LOWER(COALESCE({$roleAlias}.name, '')) ~ '(gerente|diretor|coordenador|supervisor|lider|chefe|gestor|manager)'
                        THEN 'managerial'
                    ELSE 'operational'
               END";

        $rawSectorSources = [
            "NULLIF(TRIM(COALESCE({$assignmentAlias}.sector, '')), '')",
        ];
        if ($hasEventRoles) {
            $rawSectorSources[] = "NULLIF(TRIM(COALESCE(wer.sector, '')), '')";
        }
        $rawSectorSources[] = "NULLIF(TRIM(COALESCE({$roleAlias}.sector, '')), '')";
        $rawSectorExpr = "COALESCE(" . implode(', ', array_merge($rawSectorSources, ["'geral'"])) . ")";

        return [
            'event_role_join' => $eventRoleJoin,
            'role_settings_join' => $roleSettingsJoin,
            'requires_organizer_id' => $hasRoleSettings,
            'cost_bucket_expr' => "COALESCE(" . implode(', ', $costBucketSources) . ")",
            'normalized_sector_expr' => "COALESCE(NULLIF(" . self::workforceNormalizedSectorExpr($rawSectorExpr) . ", ''), 'geral')",
        ];
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

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)($value ?? 0);
        return $normalized > 0 ? $normalized : null;
    }

    private static function normalizeLookupValue(mixed $value): string
    {
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (is_string($transliterated) && $transliterated !== '') {
                $normalized = $transliterated;
            }
        }

        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/i', '_', $normalized) ?? '';
        return trim($normalized, '_');
    }

    private static function normalizeWorkforceSector(mixed $value): string
    {
        return self::normalizeLookupValue($value);
    }

    private static function workforceNormalizedSectorExpr(string $expression): string
    {
        return "TRIM(BOTH '_' FROM REGEXP_REPLACE(TRANSLATE(LOWER(COALESCE({$expression}, '')), 'áàãâäéèêëíìîïóòõôöúùûüçñ', 'aaaaaeeeeiiiiooooouuuucn'), '[^a-z0-9]+', '_', 'g'))";
    }

    private static function workforceRoleClassSortValue(string $roleClass): int
    {
        return match (strtolower(trim($roleClass))) {
            'manager' => 0,
            'coordinator' => 1,
            'supervisor' => 2,
            default => 3,
        };
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
