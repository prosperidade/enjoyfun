<?php

namespace EnjoyFun\Services;

use PDO;

require_once BASE_PATH . '/src/Helpers/WorkforceControllerSupport.php';
require_once BASE_PATH . '/src/Helpers/WorkforceEventRoleHelper.php';
require_once BASE_PATH . '/src/Services/MealsDomainService.php';

final class FinanceWorkforceCostService
{
    public static function buildReport(
        PDO $db,
        int $organizerId,
        int $eventId = 0,
        int $roleId = 0,
        string $requestedSector = '',
        bool $canBypassSector = false,
        string $userSector = 'all'
    ): array {
        $eventId = max(0, $eventId);
        $roleId = max(0, $roleId);
        $requestedSector = \normalizeSector($requestedSector);
        $userSector = \normalizeSector($userSector) ?: 'all';
        $effectiveSector = $canBypassSector
            ? $requestedSector
            : ($userSector !== 'all' ? $userSector : $requestedSector);
        $hasRoleSettings = \tableExists($db, 'workforce_role_settings');
        $hasMealUnitCost = \columnExists($db, 'organizer_financial_settings', 'meal_unit_cost');
        $hasEventRoles = \workforceEventRolesReady($db) && \workforceAssignmentsHaveEventRoleColumns($db);
        $hasParticipantCheckins = \tableExists($db, 'participant_checkins');

        $mealUnitCost = $hasMealUnitCost ? self::loadMealUnitCost($db, $organizerId) : 0.0;
        $mealCostContext = $eventId > 0
            ? MealsDomainService::buildCostContext($db, $organizerId, $eventId)
            : [
                'services' => [],
                'active_services' => [],
                'fallback_unit_cost' => $mealUnitCost,
            ];
        $activeMealServices = $mealCostContext['active_services'] ?? [];
        $mealServicesCostLabel = MealsDomainService::buildServiceCostLabel($mealCostContext['services'] ?? []);
        $presenceByParticipantId = $hasParticipantCheckins
            ? self::loadPresenceByParticipant($db, $organizerId, $eventId)
            : [];

        $plannedMembersTotal = 0;
        $filledMembersTotal = 0;
        $presentMembersTotal = $hasParticipantCheckins ? 0 : null;
        $leadershipPositionsTotal = 0;
        $leadershipFilledTotal = 0;
        $leadershipPlaceholderTotal = 0;
        $operationalMembersTotal = 0;
        $totalEstimatedPayment = 0.0;
        $totalEstimatedHours = 0.0;
        $totalEstimatedMeals = 0;
        $totalEstimatedMealsCost = 0.0;
        $managerialRolesPaymentTotal = 0.0;
        $operationalMembersPaymentTotal = 0.0;
        $bySector = [];
        $byRole = [];
        $byRoleManagerial = [];
        $operationalMembers = [];
        $items = [];

        $maxShiftsExpr = $hasEventRoles
            ? 'COALESCE(wms.max_shifts_event, wer.max_shifts_event, 1)'
            : 'COALESCE(wms.max_shifts_event, 1)';
        $shiftHoursExpr = $hasEventRoles
            ? 'COALESCE(wms.shift_hours, wer.shift_hours, 8)'
            : 'COALESCE(wms.shift_hours, 8)';
        $mealsExpr = $hasEventRoles
            ? 'COALESCE(wms.meals_per_day, wer.meals_per_day, 4)'
            : 'COALESCE(wms.meals_per_day, 4)';
        $paymentExpr = $hasEventRoles
            ? 'COALESCE(wms.payment_amount, wer.payment_amount, 0)'
            : 'COALESCE(wms.payment_amount, 0)';

        $sql = '
            SELECT
                wa.id AS assignment_id,
                ep.event_id,
                wa.sector,
                wa.role_id,
                r.name AS role_name,
                ep.id AS participant_id,
                p.name AS participant_name,
                ' . $paymentExpr . '::numeric AS payment_amount,
                ' . $maxShiftsExpr . '::int AS max_shifts_event,
                ' . $shiftHoursExpr . '::numeric AS shift_hours,
                ' . $mealsExpr . '::int AS meals_per_day
            FROM workforce_assignments wa
            JOIN event_participants ep ON ep.id = wa.participant_id
            JOIN events e ON e.id = ep.event_id
            JOIN people p ON p.id = ep.person_id
            JOIN workforce_roles r ON r.id = wa.role_id
            LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id
            ' . ($hasEventRoles ? 'LEFT JOIN workforce_event_roles wer ON wer.id = wa.event_role_id' : '') . '
            WHERE e.organizer_id = :organizer_id
        ';

        $params = [':organizer_id' => $organizerId];
        if ($eventId > 0) {
            $sql .= ' AND ep.event_id = :event_id';
            $params[':event_id'] = $eventId;
        }
        if ($roleId > 0) {
            $sql .= ' AND wa.role_id = :role_id';
            $params[':role_id'] = $roleId;
        }
        if ($effectiveSector !== '') {
            $sql .= " AND LOWER(COALESCE(wa.sector, '')) = :sector";
            $params[':sector'] = $effectiveSector;
        }
        $sql .= ' ORDER BY wa.sector ASC, r.name ASC, p.name ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $sector = \normalizeSector((string)($row['sector'] ?? '')) ?: 'geral';
            $roleName = (string)($row['role_name'] ?? 'Sem Cargo');
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
            $participantId = (int)($row['participant_id'] ?? 0);
            $participantIsPresent = !empty($presenceByParticipantId[$participantId]);

            $item = [
                'assignment_id' => (int)($row['assignment_id'] ?? 0),
                'event_id' => (int)($row['event_id'] ?? 0),
                'participant_id' => $participantId,
                'participant_name' => (string)($row['participant_name'] ?? ''),
                'sector' => $sector,
                'role_id' => (int)($row['role_id'] ?? 0),
                'role_name' => $roleName,
                'payment_amount' => $paymentAmount,
                'max_shifts_event' => $maxShifts,
                'shift_hours' => $shiftHours,
                'meals_per_day' => $mealsPerDay,
                'cost_bucket' => 'operational',
                'estimated_payment_total' => $estimatedPayment,
                'estimated_hours_total' => $estimatedHours,
                'estimated_meals_total' => $estimatedMeals,
                'estimated_meals_cost_total' => $estimatedMealsCost,
                'is_present' => $participantIsPresent,
            ];

            $items[] = $item;
            $plannedMembersTotal++;
            $filledMembersTotal++;
            if ($hasParticipantCheckins && $participantIsPresent) {
                $presentMembersTotal++;
            }
            $totalEstimatedPayment += $estimatedPayment;
            $totalEstimatedHours += $estimatedHours;
            $totalEstimatedMeals += $estimatedMeals;
            $totalEstimatedMealsCost += $estimatedMealsCost;
            $operationalMembersPaymentTotal += $estimatedPayment;
            $operationalMembersTotal++;
            $operationalMembers[] = $item;

            self::ensureSectorRow($bySector, $sector, $hasParticipantCheckins);
            $bySector[$sector]['members']++;
            $bySector[$sector]['planned_members_total']++;
            $bySector[$sector]['filled_members_total']++;
            if ($hasParticipantCheckins && $participantIsPresent) {
                $bySector[$sector]['present_members_total']++;
            }
            $bySector[$sector]['operational_members_total']++;
            $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
            $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
            $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
            $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

            $roleKey = $sector . '::' . $roleName;
            if (!isset($byRole[$roleKey])) {
                $byRole[$roleKey] = [
                    'sector' => $sector,
                    'role_name' => $roleName,
                    'cost_bucket' => 'operational',
                    'members' => 0,
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
                ];
            }
            $byRole[$roleKey]['members']++;
            $byRole[$roleKey]['planned_members_total']++;
            $byRole[$roleKey]['filled_members_total']++;
            $byRole[$roleKey]['operational_members_total']++;
            $byRole[$roleKey]['estimated_payment_total'] += $estimatedPayment;
            $byRole[$roleKey]['estimated_hours_total'] += $estimatedHours;
            $byRole[$roleKey]['estimated_meals_total'] += $estimatedMeals;
            $byRole[$roleKey]['estimated_meals_cost_total'] += $estimatedMealsCost;
        }

        if ($eventId > 0 && \workforceEventRolesReady($db)) {
            $sqlManagerial = '
                SELECT
                    wer.id AS event_role_id,
                    wer.role_id,
                    wr.name AS role_name,
                    COALESCE(NULLIF(TRIM(wer.sector), \'\'), \'geral\') AS sector,
                    COALESCE(wer.cost_bucket, \'\') AS cost_bucket,
                    wer.leader_user_id,
                    wer.leader_participant_id,
                    COALESCE(wer.leader_name, \'\') AS leader_name,
                    COALESCE(wer.leader_cpf, \'\') AS leader_cpf,
                    COALESCE(wer.is_placeholder, false) AS is_placeholder,
                    COALESCE(wer.role_class, \'\') AS role_class,
                    COALESCE(wer.payment_amount, 0)::numeric AS payment_amount,
                    COALESCE(wer.max_shifts_event, 1)::int AS max_shifts_event,
                    COALESCE(wer.shift_hours, 8)::numeric AS shift_hours,
                    COALESCE(wer.meals_per_day, 0)::int AS meals_per_day
                FROM workforce_event_roles wer
                JOIN workforce_roles wr ON wr.id = wer.role_id
                WHERE wer.organizer_id = :organizer_id
                  AND wer.event_id = :event_id
                  AND wer.is_active = true
            ';
            $paramsManagerial = [
                ':organizer_id' => $organizerId,
                ':event_id' => $eventId,
            ];
            if ($roleId > 0) {
                $sqlManagerial .= ' AND wer.role_id = :role_id';
                $paramsManagerial[':role_id'] = $roleId;
            }
            if ($effectiveSector !== '') {
                $sqlManagerial .= " AND LOWER(COALESCE(wer.sector, '')) = :sector";
                $paramsManagerial[':sector'] = $effectiveSector;
            }
            $sqlManagerial .= ' ORDER BY wer.sector ASC, wr.name ASC';

            $stmtManagerial = $db->prepare($sqlManagerial);
            $stmtManagerial->execute($paramsManagerial);
            $managerialRows = $stmtManagerial->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($managerialRows as $mRow) {
                $sector = \normalizeSector((string)($mRow['sector'] ?? '')) ?: 'geral';
                $roleName = (string)($mRow['role_name'] ?? 'Cargo Gerencial');
                $costBucket = \normalizeCostBucket((string)($mRow['cost_bucket'] ?? ''), $roleName);
                if ($costBucket !== 'managerial') {
                    continue;
                }

                $paymentAmount = (float)($mRow['payment_amount'] ?? 0);
                $maxShifts = (int)($mRow['max_shifts_event'] ?? 1);
                $shiftHours = (float)($mRow['shift_hours'] ?? 8);
                $mealsPerDay = (int)($mRow['meals_per_day'] ?? 0);
                $isFilled = \workforceHasLeadershipIdentity($mRow);
                $isPlaceholder = !$isFilled;
                $leaderParticipantId = (int)($mRow['leader_participant_id'] ?? 0);
                $leaderIsPresent = $leaderParticipantId > 0 && !empty($presenceByParticipantId[$leaderParticipantId]);
                $estimatedPayment = round($paymentAmount * $maxShifts, 2);
                $estimatedHours = round($maxShifts * $shiftHours, 2);
                $estimatedMeals = $maxShifts * $mealsPerDay;
                $estimatedMealsCost = round(
                    $maxShifts * MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost),
                    2
                );

                $plannedMembersTotal++;
                $totalEstimatedPayment += $estimatedPayment;
                $totalEstimatedHours += $estimatedHours;
                $totalEstimatedMeals += $estimatedMeals;
                $totalEstimatedMealsCost += $estimatedMealsCost;
                $managerialRolesPaymentTotal += $estimatedPayment;
                $leadershipPositionsTotal++;
                if ($isFilled) {
                    $filledMembersTotal++;
                    $leadershipFilledTotal++;
                }
                if ($hasParticipantCheckins && $leaderIsPresent) {
                    $presentMembersTotal++;
                }
                if ($isPlaceholder) {
                    $leadershipPlaceholderTotal++;
                }

                self::ensureSectorRow($bySector, $sector, $hasParticipantCheckins);
                $bySector[$sector]['members']++;
                $bySector[$sector]['planned_members_total']++;
                $bySector[$sector]['leadership_positions_total']++;
                if ($isFilled) {
                    $bySector[$sector]['filled_members_total']++;
                    $bySector[$sector]['leadership_filled_total']++;
                }
                if ($hasParticipantCheckins && $leaderIsPresent) {
                    $bySector[$sector]['present_members_total']++;
                }
                if ($isPlaceholder) {
                    $bySector[$sector]['leadership_placeholder_total']++;
                }
                $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
                $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
                $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
                $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

                $byRoleManagerial[] = [
                    'sector' => $sector,
                    'event_role_id' => (int)($mRow['event_role_id'] ?? 0),
                    'role_id' => (int)($mRow['role_id'] ?? 0),
                    'role_name' => $roleName,
                    'role_class' => (string)($mRow['role_class'] ?? ''),
                    'cost_bucket' => $costBucket,
                    'members' => 1,
                    'planned_members_total' => 1,
                    'filled_members_total' => $isFilled ? 1 : 0,
                    'leadership_positions_total' => 1,
                    'leadership_filled_total' => $isFilled ? 1 : 0,
                    'leadership_placeholder_total' => $isPlaceholder ? 1 : 0,
                    'operational_members_total' => 0,
                    'present_members_total' => $hasParticipantCheckins ? ($leaderIsPresent ? 1 : 0) : null,
                    'estimated_payment_total' => $estimatedPayment,
                    'estimated_hours_total' => $estimatedHours,
                    'estimated_meals_total' => $estimatedMeals,
                    'estimated_meals_cost_total' => $estimatedMealsCost,
                ];
            }
        } elseif ($hasRoleSettings) {
            $sqlManagerial = '
                SELECT
                    wr.id AS role_id,
                    wr.name AS role_name,
                    COALESCE(NULLIF(TRIM(wr.sector), \'\'), \'geral\') AS sector,
                    COALESCE(wrs.cost_bucket, \'\') AS cost_bucket,
                    COALESCE(wrs.payment_amount, 0)::numeric AS payment_amount,
                    COALESCE(wrs.max_shifts_event, 1)::int AS max_shifts_event,
                    COALESCE(wrs.shift_hours, 8)::numeric AS shift_hours,
                    COALESCE(wrs.meals_per_day, 0)::int AS meals_per_day
                FROM workforce_role_settings wrs
                JOIN workforce_roles wr ON wr.id = wrs.role_id
                WHERE wrs.organizer_id = :organizer_id
            ';
            $paramsManagerial = [':organizer_id' => $organizerId];
            if ($roleId > 0) {
                $sqlManagerial .= ' AND wr.id = :role_id';
                $paramsManagerial[':role_id'] = $roleId;
            }
            if ($effectiveSector !== '') {
                $sqlManagerial .= " AND LOWER(COALESCE(wr.sector, '')) = :sector";
                $paramsManagerial[':sector'] = $effectiveSector;
            }
            $sqlManagerial .= ' ORDER BY wr.sector ASC, wr.name ASC';

            $stmtManagerial = $db->prepare($sqlManagerial);
            $stmtManagerial->execute($paramsManagerial);
            $managerialRows = $stmtManagerial->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($managerialRows as $mRow) {
                $sector = \normalizeSector((string)($mRow['sector'] ?? '')) ?: 'geral';
                $roleName = (string)($mRow['role_name'] ?? 'Cargo Gerencial');
                $costBucket = \normalizeCostBucket((string)($mRow['cost_bucket'] ?? ''), $roleName);
                if ($costBucket !== 'managerial') {
                    continue;
                }

                $paymentAmount = (float)($mRow['payment_amount'] ?? 0);
                $maxShifts = (int)($mRow['max_shifts_event'] ?? 1);
                $shiftHours = (float)($mRow['shift_hours'] ?? 8);
                $mealsPerDay = (int)($mRow['meals_per_day'] ?? 0);
                $estimatedPayment = round($paymentAmount * $maxShifts, 2);
                $estimatedHours = round($maxShifts * $shiftHours, 2);
                $estimatedMeals = $maxShifts * $mealsPerDay;
                $estimatedMealsCost = round(
                    $maxShifts * MealsDomainService::calculateDailyMealCost($mealsPerDay, $activeMealServices, $mealUnitCost),
                    2
                );

                $plannedMembersTotal++;
                $filledMembersTotal++;
                $totalEstimatedPayment += $estimatedPayment;
                $totalEstimatedHours += $estimatedHours;
                $totalEstimatedMeals += $estimatedMeals;
                $totalEstimatedMealsCost += $estimatedMealsCost;
                $managerialRolesPaymentTotal += $estimatedPayment;
                $leadershipPositionsTotal++;
                $leadershipFilledTotal++;

                self::ensureSectorRow($bySector, $sector, $hasParticipantCheckins);
                $bySector[$sector]['members']++;
                $bySector[$sector]['planned_members_total']++;
                $bySector[$sector]['filled_members_total']++;
                $bySector[$sector]['leadership_positions_total']++;
                $bySector[$sector]['leadership_filled_total']++;
                $bySector[$sector]['estimated_payment_total'] += $estimatedPayment;
                $bySector[$sector]['estimated_hours_total'] += $estimatedHours;
                $bySector[$sector]['estimated_meals_total'] += $estimatedMeals;
                $bySector[$sector]['estimated_meals_cost_total'] += $estimatedMealsCost;

                $byRoleManagerial[] = [
                    'sector' => $sector,
                    'role_id' => (int)($mRow['role_id'] ?? 0),
                    'role_name' => $roleName,
                    'cost_bucket' => $costBucket,
                    'members' => 1,
                    'planned_members_total' => 1,
                    'filled_members_total' => 1,
                    'leadership_positions_total' => 1,
                    'leadership_filled_total' => 1,
                    'leadership_placeholder_total' => 0,
                    'operational_members_total' => 0,
                    'present_members_total' => null,
                    'estimated_payment_total' => $estimatedPayment,
                    'estimated_hours_total' => $estimatedHours,
                    'estimated_meals_total' => $estimatedMeals,
                    'estimated_meals_cost_total' => $estimatedMealsCost,
                ];
            }
        }

        $bySector = self::finalizeCollection($bySector);
        $byRole = self::finalizeCollection($byRole);
        $byRoleManagerial = self::finalizeCollection($byRoleManagerial);

        usort($bySector, static fn(array $a, array $b): int => strcmp((string)$a['sector'], (string)$b['sector']));
        usort($byRole, static function (array $a, array $b): int {
            $sectorCompare = strcmp((string)$a['sector'], (string)$b['sector']);
            return $sectorCompare !== 0 ? $sectorCompare : strcmp((string)$a['role_name'], (string)$b['role_name']);
        });
        usort($byRoleManagerial, static function (array $a, array $b): int {
            $sectorCompare = strcmp((string)$a['sector'], (string)$b['sector']);
            return $sectorCompare !== 0 ? $sectorCompare : strcmp((string)$a['role_name'], (string)$b['role_name']);
        });

        $estimatedMealsCostTotal = round($totalEstimatedMealsCost, 2);

        return [
            'filters' => [
                'event_id' => $eventId > 0 ? $eventId : null,
                'role_id' => $roleId > 0 ? $roleId : null,
                'sector' => $effectiveSector !== '' ? $effectiveSector : null,
            ],
            'formulas' => [
                'estimated_payment_total' => 'payment_amount * max_shifts_event',
                'estimated_hours_total' => 'max_shifts_event * shift_hours',
                'estimated_meals_total' => 'max_shifts_event * meals_per_day',
                'estimated_meals_cost_total' => 'max_shifts_event * custo_diario_das_refeicoes_ativas_do_evento',
                'estimated_total_cost' => 'estimated_payment_total + estimated_meals_cost_total',
            ],
            'summary' => [
                'members' => $plannedMembersTotal,
                'planned_members_total' => $plannedMembersTotal,
                'filled_members_total' => $filledMembersTotal,
                'present_members_total' => $presentMembersTotal,
                'leadership_positions_total' => $leadershipPositionsTotal,
                'leadership_filled_total' => $leadershipFilledTotal,
                'leadership_placeholder_total' => $leadershipPlaceholderTotal,
                'operational_members_total' => $operationalMembersTotal,
                'estimated_payment_total' => round($totalEstimatedPayment, 2),
                'estimated_hours_total' => round($totalEstimatedHours, 2),
                'estimated_meals_total' => $totalEstimatedMeals,
                'meal_unit_cost' => round($mealUnitCost, 2),
                'meal_services_costs_label' => $mealServicesCostLabel,
                'event_meal_services' => $mealCostContext['services'] ?? [],
                'estimated_meals_cost_total' => $estimatedMealsCostTotal,
                'estimated_total_cost' => round($totalEstimatedPayment + $estimatedMealsCostTotal, 2),
                'managerial_roles_payment_total' => round($managerialRolesPaymentTotal, 2),
                'operational_members_payment_total' => round($operationalMembersPaymentTotal, 2),
            ],
            'by_sector' => $bySector,
            'by_role' => $byRole,
            'by_role_managerial' => $byRoleManagerial,
            'operational_members' => $operationalMembers,
            'items' => $items,
        ];
    }

    private static function loadMealUnitCost(PDO $db, int $organizerId): float
    {
        $stmt = $db->prepare('
            SELECT COALESCE(meal_unit_cost, 0)
            FROM organizer_financial_settings
            WHERE organizer_id = ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([$organizerId]);
        return (float)($stmt->fetchColumn() ?: 0);
    }

    private static function loadPresenceByParticipant(PDO $db, int $organizerId, int $eventId): array
    {
        $sql = '
            SELECT DISTINCT ep.id AS participant_id
            FROM event_participants ep
            JOIN events e ON e.id = ep.event_id
            LEFT JOIN LATERAL (
                SELECT LOWER(COALESCE(pc.action, \'\')) AS last_action
                FROM participant_checkins pc
                WHERE pc.participant_id = ep.id
                ORDER BY pc.recorded_at DESC, pc.id DESC
                LIMIT 1
            ) latest_pc ON TRUE
            WHERE e.organizer_id = :organizer_id
              AND (
                COALESCE(latest_pc.last_action, \'\') = \'check-in\'
                OR (
                    latest_pc.last_action IS NULL
                    AND LOWER(COALESCE(ep.status, \'\')) = \'present\'
                )
              )
        ';
        $params = [':organizer_id' => $organizerId];
        if ($eventId > 0) {
            $sql .= ' AND ep.event_id = :event_id';
            $params[':event_id'] = $eventId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $presenceByParticipantId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $participantId = (int)($row['participant_id'] ?? 0);
            if ($participantId > 0) {
                $presenceByParticipantId[$participantId] = true;
            }
        }

        return $presenceByParticipantId;
    }

    private static function ensureSectorRow(array &$collection, string $sector, bool $hasParticipantCheckins): void
    {
        if (isset($collection[$sector])) {
            return;
        }

        $collection[$sector] = [
            'sector' => $sector,
            'members' => 0,
            'planned_members_total' => 0,
            'filled_members_total' => 0,
            'present_members_total' => $hasParticipantCheckins ? 0 : null,
            'leadership_positions_total' => 0,
            'leadership_filled_total' => 0,
            'leadership_placeholder_total' => 0,
            'operational_members_total' => 0,
            'estimated_payment_total' => 0.0,
            'estimated_hours_total' => 0.0,
            'estimated_meals_total' => 0,
            'estimated_meals_cost_total' => 0.0,
        ];
    }

    private static function finalizeCollection(array $collection): array
    {
        return array_values(array_map(static function (array $row): array {
            $row['members'] = (int)($row['planned_members_total'] ?? $row['members'] ?? 0);
            $row['estimated_payment_total'] = round((float)($row['estimated_payment_total'] ?? 0), 2);
            $row['estimated_hours_total'] = round((float)($row['estimated_hours_total'] ?? 0), 2);
            $row['estimated_meals_cost_total'] = round((float)($row['estimated_meals_cost_total'] ?? 0), 2);
            return $row;
        }, $collection));
    }
}
