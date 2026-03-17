<?php
namespace EnjoyFun\Services;

use PDO;
use Exception;
use InvalidArgumentException;

require_once BASE_PATH . '/src/Helpers/WorkforceEventRoleHelper.php';

class MealsDomainService
{
    private const DEFAULT_SERVICES = [
        [
            'service_code' => 'breakfast',
            'label' => 'Cafe da manha',
            'sort_order' => 10,
            'starts_at' => '06:00:00',
            'ends_at' => '09:30:00',
        ],
        [
            'service_code' => 'lunch',
            'label' => 'Almoco',
            'sort_order' => 20,
            'starts_at' => '11:00:00',
            'ends_at' => '14:30:00',
        ],
        [
            'service_code' => 'afternoon_snack',
            'label' => 'Lanche da tarde',
            'sort_order' => 30,
            'starts_at' => '15:00:00',
            'ends_at' => '17:30:00',
        ],
        [
            'service_code' => 'dinner',
            'label' => 'Jantar',
            'sort_order' => 40,
            'starts_at' => '18:30:00',
            'ends_at' => '22:30:00',
        ],
    ];

    public static function resolveEventContext(PDO $db, int $organizerId, ?int $eventId, ?int $eventDayId, ?int $eventShiftId): array
    {
        $resolvedEventId = $eventId !== null && $eventId > 0 ? $eventId : null;
        $resolvedEventDayId = $eventDayId !== null && $eventDayId > 0 ? $eventDayId : null;
        $resolvedEventShiftId = $eventShiftId !== null && $eventShiftId > 0 ? $eventShiftId : null;

        if ($resolvedEventId !== null) {
            $stmtEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
            $stmtEvent->execute([$resolvedEventId, $organizerId]);
            if (!$stmtEvent->fetchColumn()) {
                throw new Exception('Evento não encontrado ou sem acesso.', 404);
            }
        }

        if ($resolvedEventDayId !== null) {
            $stmtDay = $db->prepare("
                SELECT ed.id, ed.event_id
                FROM event_days ed
                JOIN events e ON e.id = ed.event_id
                WHERE ed.id = ? AND e.organizer_id = ?
                LIMIT 1
            ");
            $stmtDay->execute([$resolvedEventDayId, $organizerId]);
            $day = $stmtDay->fetch(PDO::FETCH_ASSOC);
            if (!$day) {
                throw new Exception('event_day_id inválido ou fora do contexto operacional do organizador.', 404);
            }

            $dayEventId = (int)$day['event_id'];
            if ($resolvedEventId !== null && $dayEventId !== $resolvedEventId) {
                throw new Exception('event_day_id não pertence ao event_id informado.', 400);
            }

            $resolvedEventId = $dayEventId;
        }

        if ($resolvedEventShiftId !== null) {
            $stmtShift = $db->prepare("
                SELECT es.id, es.event_day_id, ed.event_id
                FROM event_shifts es
                JOIN event_days ed ON ed.id = es.event_day_id
                JOIN events e ON e.id = ed.event_id
                WHERE es.id = ? AND e.organizer_id = ?
                LIMIT 1
            ");
            $stmtShift->execute([$resolvedEventShiftId, $organizerId]);
            $shift = $stmtShift->fetch(PDO::FETCH_ASSOC);
            if (!$shift) {
                throw new Exception('event_shift_id inválido ou fora do contexto operacional do organizador.', 404);
            }

            $shiftDayId = (int)$shift['event_day_id'];
            $shiftEventId = (int)$shift['event_id'];

            if ($resolvedEventDayId !== null && $shiftDayId !== $resolvedEventDayId) {
                throw new Exception('event_shift_id não pertence ao event_day_id informado.', 400);
            }

            if ($resolvedEventId !== null && $shiftEventId !== $resolvedEventId) {
                throw new Exception('event_shift_id não pertence ao event_id informado.', 400);
            }

            $resolvedEventDayId = $shiftDayId;
            $resolvedEventId = $shiftEventId;
        }

        return [
            'event_id' => $resolvedEventId,
            'event_day_id' => $resolvedEventDayId,
            'event_shift_id' => $resolvedEventShiftId,
        ];
    }

    public static function resolveParticipant(PDO $db, int $organizerId, ?int $participantId, ?string $qrToken): array
    {
        $participantId = $participantId !== null && $participantId > 0 ? $participantId : null;
        $qrToken = trim((string)($qrToken ?? ''));
        if ($participantId === null && $qrToken === '') {
            throw new Exception('participant_id ou qr_token é obrigatório.', 400);
        }

        $stmtPart = $db->prepare("
            SELECT ep.id, ep.event_id
            FROM event_participants ep
            JOIN events e ON e.id = ep.event_id
            WHERE " . ($participantId !== null ? "ep.id = :participant_id" : "ep.qr_token = :qr_token") . "
              AND e.organizer_id = :organizer_id
            LIMIT 1
        ");
        $params = [':organizer_id' => $organizerId];
        if ($participantId !== null) {
            $params[':participant_id'] = $participantId;
        } else {
            $params[':qr_token'] = $qrToken;
        }
        $stmtPart->execute($params);
        $participant = $stmtPart->fetch(PDO::FETCH_ASSOC);
        if (!$participant) {
            throw new Exception('Participante inválido ou sem acesso.', 403);
        }

        return [
            'participant_id' => (int)$participant['id'],
            'event_id' => (int)$participant['event_id'],
        ];
    }

    public static function tableExists(PDO $db, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
        return $cache[$table];
    }

    public static function columnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }

    public static function getLegacyMealUnitCost(PDO $db, int $organizerId): float
    {
        if (!self::columnExists($db, 'organizer_financial_settings', 'meal_unit_cost')) {
            return 0.0;
        }

        $stmt = $db->prepare("
            SELECT COALESCE(meal_unit_cost, 0)
            FROM organizer_financial_settings
            WHERE organizer_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$organizerId]);
        return round((float)($stmt->fetchColumn() ?: 0), 2);
    }

    public static function ensureEventMealServices(PDO $db, int $organizerId, int $eventId): array
    {
        if (!self::tableExists($db, 'event_meal_services')) {
            return [];
        }

        $stmtCount = $db->prepare("
            SELECT COUNT(*)
            FROM event_meal_services ems
            JOIN events e ON e.id = ems.event_id
            WHERE ems.event_id = ? AND e.organizer_id = ?
        ");
        $stmtCount->execute([$eventId, $organizerId]);
        $count = (int)$stmtCount->fetchColumn();
        if ($count > 0) {
            return self::listEventMealServices($db, $organizerId, $eventId, true);
        }

        $fallbackUnitCost = self::getLegacyMealUnitCost($db, $organizerId);
        $insert = $db->prepare("
            INSERT INTO event_meal_services (
                event_id,
                service_code,
                label,
                sort_order,
                starts_at,
                ends_at,
                unit_cost,
                is_active,
                created_at,
                updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, true, NOW(), NOW())
        ");

        foreach (self::DEFAULT_SERVICES as $service) {
            $insert->execute([
                $eventId,
                $service['service_code'],
                $service['label'],
                $service['sort_order'],
                $service['starts_at'],
                $service['ends_at'],
                $fallbackUnitCost,
            ]);
        }

        return self::listEventMealServices($db, $organizerId, $eventId, true);
    }

    public static function listEventMealServices(PDO $db, int $organizerId, int $eventId, bool $includeInactive = true): array
    {
        if (!self::tableExists($db, 'event_meal_services')) {
            return [];
        }

        $sql = "
            SELECT
                ems.id,
                ems.event_id,
                ems.service_code,
                ems.label,
                ems.sort_order,
                ems.starts_at::text AS starts_at,
                ems.ends_at::text AS ends_at,
                COALESCE(ems.unit_cost, 0)::numeric AS unit_cost,
                COALESCE(ems.is_active, true) AS is_active,
                ems.created_at,
                ems.updated_at
            FROM event_meal_services ems
            JOIN events e ON e.id = ems.event_id
            WHERE ems.event_id = :event_id
              AND e.organizer_id = :organizer_id
        ";
        if (!$includeInactive) {
            $sql .= " AND COALESCE(ems.is_active, true) = true";
        }
        $sql .= " ORDER BY ems.sort_order ASC, ems.id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'event_id' => (int)$row['event_id'],
                'service_code' => (string)$row['service_code'],
                'label' => (string)$row['label'],
                'sort_order' => (int)$row['sort_order'],
                'starts_at' => $row['starts_at'] !== null ? substr((string)$row['starts_at'], 0, 8) : null,
                'ends_at' => $row['ends_at'] !== null ? substr((string)$row['ends_at'], 0, 8) : null,
                'unit_cost' => round((float)$row['unit_cost'], 2),
                'is_active' => filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $rows);
    }

    public static function saveEventMealServices(PDO $db, int $organizerId, int $eventId, array $services): array
    {
        if ($eventId <= 0) {
            throw new InvalidArgumentException('event_id é obrigatório.');
        }
        if (!self::tableExists($db, 'event_meal_services')) {
            throw new Exception('Tabela event_meal_services indisponível nesta base.', 409);
        }
        if (!is_array($services) || empty($services)) {
            throw new InvalidArgumentException('services é obrigatório.');
        }

        self::resolveEventContext($db, $organizerId, $eventId, null, null);
        self::ensureEventMealServices($db, $organizerId, $eventId);
        $current = self::listEventMealServices($db, $organizerId, $eventId, true);
        $currentById = [];
        $currentByCode = [];
        foreach ($current as $service) {
            $currentById[(int)$service['id']] = $service;
            $currentByCode[(string)$service['service_code']] = $service;
        }

        $update = $db->prepare("
            UPDATE event_meal_services
            SET label = :label,
                sort_order = :sort_order,
                starts_at = :starts_at,
                ends_at = :ends_at,
                unit_cost = :unit_cost,
                is_active = :is_active,
                updated_at = NOW()
            WHERE id = :id
              AND event_id = :event_id
        ");

        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $serviceId = (int)($service['id'] ?? 0);
            $serviceCode = strtolower(trim((string)($service['service_code'] ?? '')));
            $currentRow = $serviceId > 0
                ? ($currentById[$serviceId] ?? null)
                : ($serviceCode !== '' ? ($currentByCode[$serviceCode] ?? null) : null);
            if (!$currentRow) {
                throw new InvalidArgumentException('Serviço de refeição inválido para este evento.');
            }

            $label = trim((string)($service['label'] ?? $currentRow['label']));
            if ($label === '') {
                throw new InvalidArgumentException('label do serviço de refeição é obrigatório.');
            }

            $sortOrder = (int)($service['sort_order'] ?? $currentRow['sort_order']);
            $startsAt = self::normalizeTime((string)($service['starts_at'] ?? $currentRow['starts_at'] ?? ''));
            $endsAt = self::normalizeTime((string)($service['ends_at'] ?? $currentRow['ends_at'] ?? ''));
            $unitCost = round((float)($service['unit_cost'] ?? $currentRow['unit_cost'] ?? 0), 2);
            if ($unitCost < 0) {
                throw new InvalidArgumentException('unit_cost não pode ser negativo.');
            }
            $isActive = filter_var($service['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive === null) {
                $isActive = true;
            }

            $update->execute([
                ':label' => $label,
                ':sort_order' => $sortOrder,
                ':starts_at' => $startsAt,
                ':ends_at' => $endsAt,
                ':unit_cost' => $unitCost,
                ':is_active' => $isActive,
                ':id' => (int)$currentRow['id'],
                ':event_id' => $eventId,
            ]);
        }

        return self::listEventMealServices($db, $organizerId, $eventId, true);
    }

    public static function resolveMealServiceSelection(PDO $db, int $organizerId, int $eventId, ?int $mealServiceId = null, ?string $mealServiceCode = null, ?string $consumedAt = null): ?array
    {
        $services = self::ensureEventMealServices($db, $organizerId, $eventId);
        if (empty($services)) {
            return null;
        }

        $activeServices = array_values(array_filter($services, static fn(array $service): bool => !empty($service['is_active'])));
        $pool = !empty($activeServices) ? $activeServices : $services;
        if (empty($pool)) {
            return null;
        }

        if ($mealServiceId !== null && $mealServiceId > 0) {
            foreach ($pool as $service) {
                if ((int)$service['id'] === $mealServiceId) {
                    return $service;
                }
            }
            throw new Exception('meal_service_id não pertence ao evento informado.', 400);
        }

        $normalizedCode = strtolower(trim((string)($mealServiceCode ?? '')));
        if ($normalizedCode !== '') {
            foreach ($pool as $service) {
                if ((string)$service['service_code'] === $normalizedCode) {
                    return $service;
                }
            }
            throw new Exception('meal_service_code não pertence ao evento informado.', 400);
        }

        $timeReference = self::extractTimeReference($consumedAt);
        if ($timeReference !== null) {
            foreach ($pool as $service) {
                $startsAt = $service['starts_at'] ?? null;
                $endsAt = $service['ends_at'] ?? null;
                if ($startsAt !== null && $endsAt !== null && $timeReference >= $startsAt && $timeReference <= $endsAt) {
                    return $service;
                }
            }
        }

        return $pool[0];
    }

    public static function buildCostContext(PDO $db, int $organizerId, int $eventId): array
    {
        $services = self::ensureEventMealServices($db, $organizerId, $eventId);
        $activeServices = array_values(array_filter($services, static fn(array $service): bool => !empty($service['is_active'])));
        $fallbackUnitCost = self::getLegacyMealUnitCost($db, $organizerId);

        return [
            'services' => $services,
            'active_services' => $activeServices,
            'fallback_unit_cost' => $fallbackUnitCost,
        ];
    }

    public static function calculateDailyMealCost(int $mealsPerDay, array $activeServices, float $fallbackUnitCost = 0.0): float
    {
        if ($mealsPerDay <= 0) {
            return 0.0;
        }

        $ordered = array_values($activeServices);
        usort($ordered, static fn(array $a, array $b): int => ((int)$a['sort_order']) <=> ((int)$b['sort_order']));

        $cost = 0.0;
        $remaining = $mealsPerDay;
        foreach ($ordered as $service) {
            if ($remaining <= 0) {
                break;
            }
            $cost += (float)($service['unit_cost'] ?? 0);
            $remaining--;
        }

        if ($remaining > 0 && $fallbackUnitCost > 0) {
            $cost += ($remaining * $fallbackUnitCost);
        }

        return round($cost, 2);
    }

    public static function buildServiceCostLabel(array $services): string
    {
        $activeServices = array_values(array_filter($services, static fn(array $service): bool => !empty($service['is_active'])));
        if (empty($activeServices)) {
            return 'Sem serviços ativos';
        }

        $parts = array_map(static function (array $service): string {
            return sprintf('%s %s', $service['label'], self::formatMoney((float)($service['unit_cost'] ?? 0)));
        }, $activeServices);

        return implode(' | ', $parts);
    }

    public static function registerOperationalMealByReference(
        PDO $db,
        int $organizerId,
        ?int $participantId,
        ?string $qrToken,
        int $eventDayId,
        ?int $eventShiftId = null,
        ?string $sector = null,
        ?int $mealServiceId = null,
        ?string $mealServiceCode = null,
        ?string $offlineRequestId = null,
        ?string $consumedAt = null
    ): array {
        $participant = self::resolveParticipant($db, $organizerId, $participantId, $qrToken);
        return self::registerOperationalMeal(
            $db,
            $organizerId,
            (int)$participant['participant_id'],
            (int)$participant['event_id'],
            $eventDayId,
            $eventShiftId,
            $sector,
            $mealServiceId,
            $mealServiceCode,
            $offlineRequestId,
            $consumedAt
        );
    }

    public static function registerOperationalMeal(
        PDO $db,
        int $organizerId,
        int $participantId,
        int $eventId,
        int $eventDayId,
        ?int $eventShiftId = null,
        ?string $sector = null,
        ?int $mealServiceId = null,
        ?string $mealServiceCode = null,
        ?string $offlineRequestId = null,
        ?string $consumedAt = null
    ): array {
        if ($eventDayId <= 0) {
            throw new Exception('event_day_id é obrigatório.', 400);
        }

        $context = self::resolveEventContext($db, $organizerId, $eventId, $eventDayId, $eventShiftId);
        $eventId = (int)($context['event_id'] ?? 0);
        $eventDayId = (int)($context['event_day_id'] ?? 0);
        $eventShiftId = isset($context['event_shift_id']) && $context['event_shift_id'] !== null
            ? (int)$context['event_shift_id']
            : null;

        $offlineRequestId = trim((string)($offlineRequestId ?? ''));
        $consumedAt = trim((string)($consumedAt ?? ''));
        $resolvedConsumedAt = $consumedAt !== '' ? $consumedAt : date('Y-m-d H:i:s');
        $selectedMealService = self::resolveMealServiceSelection(
            $db,
            $organizerId,
            $eventId,
            $mealServiceId,
            $mealServiceCode,
            $resolvedConsumedAt
        );
        if (!$selectedMealService) {
            throw new Exception('Nenhum serviço de refeição ativo foi encontrado para este evento.', 409);
        }

        $selectedMealServiceId = (int)$selectedMealService['id'];
        $selectedMealCost = round((float)($selectedMealService['unit_cost'] ?? 0), 2);

        self::acquireParticipantDayQuotaLock($db, $participantId, $eventDayId);
        if ($offlineRequestId !== '' && self::columnExists($db, 'participant_meals', 'offline_request_id')) {
            $stmtExistingOffline = $db->prepare("
                SELECT id
                FROM participant_meals
                WHERE offline_request_id = ?
                LIMIT 1
            ");
            $stmtExistingOffline->execute([$offlineRequestId]);
            $existingMealId = (int)($stmtExistingOffline->fetchColumn() ?: 0);
            if ($existingMealId > 0) {
                return [
                    'id' => $existingMealId,
                    'already_processed' => true,
                    'meal_service' => $selectedMealService,
                ];
            }
        }

        $cfg = self::resolveOperationalConfig(
            $db,
            $organizerId,
            $participantId,
            $eventDayId,
            $eventShiftId,
            $sector
        );
        if ((int)($cfg['assignments_in_scope'] ?? 0) <= 0) {
            throw new Exception(
                trim((string)$sector) !== ''
                    ? 'Participante sem assignment elegível no recorte operacional selecionado. O setor filtrado não corresponde à escala deste membro neste dia/turno.'
                    : 'Participante sem assignment elegível no recorte operacional selecionado. O Meals só registra refeição para membros escalados no dia/turno atual.',
                409
            );
        }
        if (($cfg['resolution_status'] ?? 'resolved') === 'ambiguous') {
            throw new Exception(
                'Cota de refeições ambígua para este participante neste recorte. Há múltiplos assignments com baselines diferentes e o sistema não fará escolha automática. Harmonize os cargos ou configure override por membro.',
                409
            );
        }

        $maxMealsPerDay = (int)($cfg['meals_per_day'] ?? 4);
        self::assertDailyQuotaAvailable($db, $participantId, $eventDayId, $maxMealsPerDay);
        self::assertMealServiceAllowed($selectedMealService, $maxMealsPerDay);
        self::assertMealServiceNotConsumed($db, $participantId, $eventDayId, $selectedMealServiceId);

        $hasMealServiceColumn = self::columnExists($db, 'participant_meals', 'meal_service_id');
        $hasUnitCostApplied = self::columnExists($db, 'participant_meals', 'unit_cost_applied');
        $hasOfflineRequestId = self::columnExists($db, 'participant_meals', 'offline_request_id');

        $columns = ['participant_id', 'event_day_id', 'event_shift_id', 'consumed_at'];
        $values = [':participant_id', ':event_day_id', ':event_shift_id', ':consumed_at'];
        $params = [
            ':participant_id' => $participantId,
            ':event_day_id' => $eventDayId,
            ':event_shift_id' => $eventShiftId,
            ':consumed_at' => $resolvedConsumedAt,
        ];

        if ($hasMealServiceColumn) {
            $columns[] = 'meal_service_id';
            $values[] = ':meal_service_id';
            $params[':meal_service_id'] = $selectedMealServiceId;
        }
        if ($hasUnitCostApplied) {
            $columns[] = 'unit_cost_applied';
            $values[] = ':unit_cost_applied';
            $params[':unit_cost_applied'] = $selectedMealCost;
        }
        if ($hasOfflineRequestId) {
            $columns[] = 'offline_request_id';
            $values[] = ':offline_request_id';
            $params[':offline_request_id'] = $offlineRequestId !== '' ? $offlineRequestId : null;
        }

        $stmt = $db->prepare(sprintf(
            'INSERT INTO participant_meals (%s) VALUES (%s) RETURNING id',
            implode(', ', $columns),
            implode(', ', $values)
        ));
        $stmt->execute($params);
        $mealId = (int)$stmt->fetchColumn();

        return [
            'id' => $mealId,
            'already_processed' => false,
            'meal_service' => $selectedMealService,
        ];
    }

    public static function resolveOperationalConfig(
        PDO $db,
        int $organizerId,
        int $participantId,
        int $eventDayId,
        ?int $eventShiftId = null,
        ?string $sector = null
    ): array {
        $hasRoleSettings = self::tableExists($db, 'workforce_role_settings');
        $hasMemberSettings = self::tableExists($db, 'workforce_member_settings');
        $normalizedSector = strtolower(trim((string)($sector ?? '')));
        $roleConfigSelect = $hasRoleSettings
            ? "
                COUNT(DISTINCT COALESCE(wer.meals_per_day, wrs.meals_per_day, 4))::int AS distinct_role_meals_count,
                CASE
                    WHEN COUNT(DISTINCT COALESCE(wer.meals_per_day, wrs.meals_per_day, 4)) = 1
                        THEN MAX(COALESCE(wer.meals_per_day, wrs.meals_per_day, 4))
                    ELSE NULL
                END::int AS single_role_meals_per_day,
                MAX(CASE WHEN wer.id IS NOT NULL OR wrs.role_id IS NOT NULL THEN 1 ELSE 0 END)::int AS has_any_role_settings,
                CASE
                    WHEN COUNT(DISTINCT COALESCE(wer.max_shifts_event, wrs.max_shifts_event, 1)) = 1
                        THEN MAX(COALESCE(wer.max_shifts_event, wrs.max_shifts_event, 1))
                    ELSE NULL
                END::int AS single_role_max_shifts_event
            "
            : "
                1::int AS distinct_role_meals_count,
                4::int AS single_role_meals_per_day,
                0::int AS has_any_role_settings,
                1::int AS single_role_max_shifts_event
            ";
        $eventRoleJoin = workforceEventRolesReady($db) && workforceAssignmentsHaveEventRoleColumns($db)
            ? "LEFT JOIN workforce_event_roles wer ON wer.id = scope.event_role_id"
            : "LEFT JOIN LATERAL (SELECT NULL::integer AS id, NULL::integer AS meals_per_day, NULL::integer AS max_shifts_event) wer ON TRUE";
        $roleSettingsJoin = $hasRoleSettings
            ? "LEFT JOIN workforce_role_settings wrs ON wrs.role_id = scope.role_id AND wrs.organizer_id = :organizer_id"
            : "";
        $memberSettingsJoin = $hasMemberSettings
            ? "LEFT JOIN workforce_member_settings wms ON wms.participant_id = ep.id"
            : "LEFT JOIN LATERAL (
                    SELECT
                        NULL::integer AS participant_id,
                        NULL::integer AS max_shifts_event,
                        NULL::integer AS meals_per_day
               ) wms ON TRUE";
        $sectorScopeSql = '';
        $params = [
            ':participant_id' => $participantId,
            ':event_day_id' => $eventDayId,
            ':event_shift_id' => $eventShiftId,
            ':organizer_id' => $organizerId,
        ];
        if ($normalizedSector !== '') {
            $sectorScopeSql = " AND LOWER(COALESCE(wa.sector, '')) = :sector";
            $params[':sector'] = $normalizedSector;
        }

        $sql = "
            WITH event_scope AS (
                SELECT wa.role_id, wa.event_shift_id,
                       " . (workforceAssignmentsHaveEventRoleColumns($db) ? "wa.event_role_id" : "NULL::integer AS event_role_id") . "
                FROM workforce_assignments wa
                LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
                WHERE wa.participant_id = :participant_id
                  AND (
                        wa.event_shift_id IS NULL
                        OR es.event_day_id = :event_day_id
                      )
                  {$sectorScopeSql}
            ),
            preferred_scope AS (
                SELECT scope.role_id, scope.event_role_id
                FROM event_scope scope
                WHERE CAST(:event_shift_id AS integer) IS NOT NULL
                  AND scope.event_shift_id = CAST(:event_shift_id AS integer)
            ),
            assignment_scope AS (
                SELECT role_id, event_role_id
                FROM preferred_scope
                UNION ALL
                SELECT role_id, event_role_id
                FROM event_scope
                WHERE NOT EXISTS (SELECT 1 FROM preferred_scope)
            ),
            role_config_rollup AS (
                SELECT
                    COUNT(*)::int AS assignments_in_scope,
                    {$roleConfigSelect}
                FROM assignment_scope scope
                {$eventRoleJoin}
                {$roleSettingsJoin}
            )
            SELECT
                CASE WHEN wms.participant_id IS NOT NULL THEN 1 ELSE 0 END::int AS has_member_settings,
                COALESCE(wms.max_shifts_event, 1)::int AS member_max_shifts_event,
                COALESCE(wms.meals_per_day, 4)::int AS member_meals_per_day,
                COALESCE(rcr.assignments_in_scope, 0)::int AS assignments_in_scope,
                COALESCE(rcr.distinct_role_meals_count, 1)::int AS distinct_role_meals_count,
                COALESCE(rcr.single_role_meals_per_day, 4)::int AS single_role_meals_per_day,
                COALESCE(rcr.single_role_max_shifts_event, 1)::int AS single_role_max_shifts_event,
                COALESCE(rcr.has_any_role_settings, 0)::int AS has_any_role_settings
            FROM event_participants ep
            {$memberSettingsJoin}
            LEFT JOIN role_config_rollup rcr ON TRUE
            WHERE ep.id = :participant_id
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $hasMemberSettingsResolved = ((int)($row['has_member_settings'] ?? 0)) > 0;
        $assignmentsInScope = (int)($row['assignments_in_scope'] ?? 0);
        $distinctRoleMealsCount = (int)($row['distinct_role_meals_count'] ?? 1);
        $hasAnyRoleSettings = ((int)($row['has_any_role_settings'] ?? 0)) > 0;
        $isAmbiguous = !$hasMemberSettingsResolved && $assignmentsInScope > 1 && $distinctRoleMealsCount > 1;

        if ($hasMemberSettingsResolved) {
            return [
                'max_shifts_event' => (int)($row['member_max_shifts_event'] ?? 1),
                'meals_per_day' => (int)($row['member_meals_per_day'] ?? 4),
                'config_source' => 'member_override',
                'resolution_status' => 'resolved',
                'assignments_in_scope' => $assignmentsInScope,
                'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
            ];
        }

        if ($isAmbiguous) {
            return [
                'max_shifts_event' => 1,
                'meals_per_day' => null,
                'config_source' => 'ambiguous',
                'resolution_status' => 'ambiguous',
                'assignments_in_scope' => $assignmentsInScope,
                'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
            ];
        }

        if ($assignmentsInScope > 0 && $hasAnyRoleSettings) {
            return [
                'max_shifts_event' => (int)($row['single_role_max_shifts_event'] ?? 1),
                'meals_per_day' => (int)($row['single_role_meals_per_day'] ?? 4),
                'config_source' => 'role_settings',
                'resolution_status' => 'resolved',
                'assignments_in_scope' => $assignmentsInScope,
                'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
            ];
        }

        return [
            'max_shifts_event' => 1,
            'meals_per_day' => 4,
            'config_source' => 'default',
            'resolution_status' => 'resolved',
            'assignments_in_scope' => $assignmentsInScope,
            'scope_mode' => $eventShiftId !== null ? 'shift_preferred' : 'event',
        ];
    }

    public static function normalizeCostBucket(string $value, string $roleName = ''): string
    {
        if (workforceIsOperationalCollectionRoleName($roleName)) {
            return 'operational';
        }

        $normalized = strtolower(trim($value));
        if ($normalized === 'managerial' || $normalized === 'operational') {
            return $normalized;
        }

        $name = strtolower(trim($roleName));
        foreach (['gerente', 'diretor', 'coordenador', 'supervisor', 'lider', 'chefe', 'gestor', 'manager'] as $hint) {
            if ($name !== '' && str_contains($name, $hint)) {
                return 'managerial';
            }
        }

        return 'operational';
    }

    private static function acquireParticipantDayQuotaLock(PDO $db, int $participantId, int $eventDayId): void
    {
        $stmt = $db->prepare('SELECT pg_advisory_xact_lock(?, ?)');
        $stmt->execute([$participantId, $eventDayId]);
    }

    private static function assertDailyQuotaAvailable(PDO $db, int $participantId, int $eventDayId, int $maxMealsPerDay): void
    {
        if ($maxMealsPerDay <= 0) {
            return;
        }

        $stmtCount = $db->prepare("
            SELECT COUNT(*)
            FROM participant_meals
            WHERE participant_id = ?
              AND event_day_id = ?
        ");
        $stmtCount->execute([$participantId, $eventDayId]);
        $countMeals = (int)$stmtCount->fetchColumn();

        if ($countMeals >= $maxMealsPerDay) {
            throw new Exception('Limite de refeições diárias deste membro foi atingido.', 409);
        }
    }

    private static function assertMealServiceAllowed(array $mealService, int $maxMealsPerDay): void
    {
        $rank = self::resolveMealServiceRank((int)($mealService['sort_order'] ?? 0), (string)($mealService['service_code'] ?? ''));
        if ($maxMealsPerDay > 0 && $rank > $maxMealsPerDay) {
            throw new Exception('Este membro não possui cota para esta refeição específica no dia.', 409);
        }
    }

    private static function assertMealServiceNotConsumed(PDO $db, int $participantId, int $eventDayId, int $mealServiceId): void
    {
        if (!self::columnExists($db, 'participant_meals', 'meal_service_id')) {
            return;
        }

        $stmt = $db->prepare("
            SELECT id
            FROM participant_meals
            WHERE participant_id = ?
              AND event_day_id = ?
              AND meal_service_id = ?
            LIMIT 1
        ");
        $stmt->execute([$participantId, $eventDayId, $mealServiceId]);
        if ($stmt->fetchColumn()) {
            throw new Exception('Esta refeição já foi validada para este membro neste dia.', 409);
        }
    }

    private static function resolveMealServiceRank(int $sortOrder, string $serviceCode): int
    {
        $code = strtolower(trim($serviceCode));
        return match ($code) {
            'breakfast' => 1,
            'lunch' => 2,
            'afternoon_snack' => 3,
            'dinner' => 4,
            default => match (true) {
                $sortOrder <= 10 => 1,
                $sortOrder <= 20 => 2,
                $sortOrder <= 30 => 3,
                default => 4,
            },
        };
    }

    private static function normalizeTime(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $trimmed)) {
            throw new InvalidArgumentException('Horário inválido para serviço de refeição.');
        }

        return strlen($trimmed) === 5 ? $trimmed . ':00' : $trimmed;
    }

    private static function extractTimeReference(?string $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return date('H:i:s');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return date('H:i:s');
        }

        return date('H:i:s', $timestamp);
    }

    private static function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
