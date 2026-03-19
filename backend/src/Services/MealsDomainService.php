<?php
namespace EnjoyFun\Services;

use DateTimeImmutable;
use DateTimeZone;
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

    private const ALLOWED_SERVICE_CODES = [
        'breakfast',
        'lunch',
        'afternoon_snack',
        'dinner',
        'supper',
        'extra',
    ];

    private const DEFAULT_SORT_ORDERS = [
        'breakfast' => 10,
        'lunch' => 20,
        'afternoon_snack' => 30,
        'dinner' => 40,
        'supper' => 50,
        'extra' => 60,
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

    public static function resolveOperationalConsumptionContext(
        PDO $db,
        int $organizerId,
        int $eventId,
        ?int $eventDayId,
        ?int $eventShiftId,
        ?string $consumedAt,
        ?string $operationalTimezone = null
    ): array {
        $baseContext = self::resolveEventContext(
            $db,
            $organizerId,
            $eventId > 0 ? $eventId : null,
            $eventDayId,
            $eventShiftId
        );
        $resolvedEventId = (int)($baseContext['event_id'] ?? $eventId);
        if ($resolvedEventId <= 0) {
            throw new Exception('Evento operacional inválido para registro de refeição.', 400);
        }
        $resolvedOperationalTimezone = self::resolveOperationalTimezone(
            $db,
            $organizerId,
            $resolvedEventId,
            $operationalTimezone
        );
        $resolvedConsumedAt = self::normalizeConsumedAt($consumedAt, $resolvedOperationalTimezone);

        $matchedEventDay = self::resolveEventDayByConsumedAt($db, $organizerId, $resolvedEventId, $resolvedConsumedAt);
        $resolvedEventDayId = isset($baseContext['event_day_id']) && $baseContext['event_day_id'] !== null
            ? (int)$baseContext['event_day_id']
            : null;

        if ($resolvedEventDayId !== null) {
            if ($matchedEventDay === null) {
                throw new Exception(
                    'Nenhum dia operacional cobre o horário informado. Revise as janelas de event_days do evento.',
                    409
                );
            }
            if ((int)$matchedEventDay['id'] !== $resolvedEventDayId) {
                throw new Exception(
                    'event_day_id não corresponde ao dia operacional do horário informado. O Meals resolve o dia automaticamente pelo consumed_at.',
                    409
                );
            }
        } else {
            if ($matchedEventDay === null) {
                throw new Exception(
                    'Nenhum dia operacional cobre o horário informado. Revise as janelas de event_days do evento.',
                    409
                );
            }
            $resolvedEventDayId = (int)$matchedEventDay['id'];
        }

        $matchedEventShift = self::resolveEventShiftByConsumedAt(
            $db,
            $organizerId,
            $resolvedEventId,
            $resolvedEventDayId,
            $resolvedConsumedAt
        );
        $resolvedEventShiftId = isset($baseContext['event_shift_id']) && $baseContext['event_shift_id'] !== null
            ? (int)$baseContext['event_shift_id']
            : null;

        if ($resolvedEventShiftId !== null) {
            $validatedShiftContext = self::resolveEventContext(
                $db,
                $organizerId,
                $resolvedEventId,
                $resolvedEventDayId,
                $resolvedEventShiftId
            );
            $resolvedEventShiftId = isset($validatedShiftContext['event_shift_id']) && $validatedShiftContext['event_shift_id'] !== null
                ? (int)$validatedShiftContext['event_shift_id']
                : null;

            if ($matchedEventShift === null || $resolvedEventShiftId !== (int)$matchedEventShift['id']) {
                throw new Exception(
                    'event_shift_id não corresponde ao turno operacional do horário informado. O Meals resolve o turno automaticamente pelo consumed_at.',
                    409
                );
            }
        } else {
            $resolvedEventShiftId = $matchedEventShift !== null ? (int)$matchedEventShift['id'] : null;
        }

        return [
            'event_id' => $resolvedEventId,
            'event_day_id' => $resolvedEventDayId,
            'event_shift_id' => $resolvedEventShiftId,
            'consumed_at' => $resolvedConsumedAt,
            'operational_timezone' => $resolvedOperationalTimezone,
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

    public static function buildDefaultMealServiceDrafts(PDO $db, int $organizerId, int $eventId): array
    {
        self::resolveEventContext($db, $organizerId, $eventId, null, null);
        return self::buildDefaultServicesPayload($eventId, self::getLegacyMealUnitCost($db, $organizerId));
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
        $current = self::listEventMealServices($db, $organizerId, $eventId, true);
        $currentById = [];
        $currentByCode = [];
        $finalByCode = [];
        foreach ($current as $service) {
            $currentById[(int)$service['id']] = $service;
            $currentByCode[(string)$service['service_code']] = $service;
            $finalByCode[(string)$service['service_code']] = [
                'id' => (int)$service['id'],
                'service_code' => (string)$service['service_code'],
                'label' => (string)$service['label'],
                'sort_order' => (int)$service['sort_order'],
                'starts_at' => (string)($service['starts_at'] ?? ''),
                'ends_at' => (string)($service['ends_at'] ?? ''),
                'unit_cost' => round((float)($service['unit_cost'] ?? 0), 2),
                'is_active' => !empty($service['is_active']),
            ];
        }
        $normalizedInputServices = [];

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
            ) VALUES (
                :event_id,
                :service_code,
                :label,
                :sort_order,
                :starts_at,
                :ends_at,
                :unit_cost,
                :is_active,
                NOW(),
                NOW()
            )
        ");
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
                throw new InvalidArgumentException('Cada item de services deve ser um objeto válido.');
            }

            $serviceId = (int)($service['id'] ?? 0);
            $serviceCode = self::normalizeServiceCode((string)($service['service_code'] ?? ''));
            $currentRow = $serviceId > 0
                ? ($currentById[$serviceId] ?? null)
                : ($serviceCode !== '' ? ($currentByCode[$serviceCode] ?? null) : null);
            if ($serviceCode === '') {
                throw new InvalidArgumentException('service_code do serviço de refeição é obrigatório.');
            }

            $label = trim((string)($service['label'] ?? ($currentRow['label'] ?? '')));
            if ($label === '') {
                throw new InvalidArgumentException('label do serviço de refeição é obrigatório.');
            }

            $sortOrder = (int)($service['sort_order'] ?? ($currentRow['sort_order'] ?? 0));
            if ($sortOrder <= 0) {
                $sortOrder = self::defaultSortOrderForServiceCode($serviceCode);
            }
            if ($sortOrder <= 0) {
                throw new InvalidArgumentException(sprintf('sort_order inválido para o serviço "%s".', $label));
            }
            $startsAt = self::normalizeTime((string)($service['starts_at'] ?? ($currentRow['starts_at'] ?? '')));
            $endsAt = self::normalizeTime((string)($service['ends_at'] ?? ($currentRow['ends_at'] ?? '')));
            if ($startsAt === null || $endsAt === null) {
                throw new InvalidArgumentException(sprintf('Os horários de início e fim do serviço "%s" são obrigatórios.', $label));
            }
            if ($startsAt === $endsAt) {
                throw new InvalidArgumentException(sprintf('A janela do serviço "%s" não pode ter início e fim iguais.', $label));
            }
            $unitCost = round((float)($service['unit_cost'] ?? ($currentRow['unit_cost'] ?? 0)), 2);
            if ($unitCost < 0) {
                throw new InvalidArgumentException('unit_cost não pode ser negativo.');
            }
            $isActive = filter_var($service['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive === null) {
                $isActive = true;
            }

            $normalizedService = [
                'id' => $currentRow ? (int)$currentRow['id'] : null,
                'service_code' => $serviceCode,
                'label' => $label,
                'sort_order' => $sortOrder,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'unit_cost' => $unitCost,
                'is_active' => $isActive,
            ];
            $normalizedInputServices[] = $normalizedService;

            if ($currentRow && (string)$currentRow['service_code'] !== $serviceCode) {
                unset($finalByCode[(string)$currentRow['service_code']]);
            }
            $finalByCode[$serviceCode] = $normalizedService;
        }

        self::validateMealServiceGrid(array_values($finalByCode));

        foreach ($normalizedInputServices as $service) {
            $currentRow = $currentByCode[(string)$service['service_code']] ?? null;
            if ($currentRow) {
                $update->execute([
                    ':label' => (string)$service['label'],
                    ':sort_order' => (int)$service['sort_order'],
                    ':starts_at' => (string)$service['starts_at'],
                    ':ends_at' => (string)$service['ends_at'],
                    ':unit_cost' => (float)$service['unit_cost'],
                    ':is_active' => !empty($service['is_active']),
                    ':id' => (int)$currentRow['id'],
                    ':event_id' => $eventId,
                ]);
                continue;
            }
            $insert->execute([
                ':event_id' => $eventId,
                ':service_code' => (string)$service['service_code'],
                ':label' => (string)$service['label'],
                ':sort_order' => (int)$service['sort_order'],
                ':starts_at' => (string)$service['starts_at'],
                ':ends_at' => (string)$service['ends_at'],
                ':unit_cost' => (float)$service['unit_cost'],
                ':is_active' => !empty($service['is_active']),
            ]);
        }

        return self::listEventMealServices($db, $organizerId, $eventId, true);
    }

    public static function resolveMealServiceSelection(PDO $db, int $organizerId, int $eventId, ?int $mealServiceId = null, ?string $mealServiceCode = null, ?string $consumedAt = null): ?array
    {
        $services = self::listEventMealServices($db, $organizerId, $eventId, true);
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
                if (self::serviceMatchesTimeReference($startsAt, $endsAt, $timeReference)) {
                    return $service;
                }
            }
        }

        return null;
    }

    public static function buildCostContext(PDO $db, int $organizerId, int $eventId): array
    {
        $services = self::listEventMealServices($db, $organizerId, $eventId, true);
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
        ?int $eventDayId = null,
        ?int $eventShiftId = null,
        ?string $sector = null,
        ?int $mealServiceId = null,
        ?string $mealServiceCode = null,
        ?string $offlineRequestId = null,
        ?string $consumedAt = null,
        ?string $operationalTimezone = null
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
            $consumedAt,
            $operationalTimezone
        );
    }

    public static function registerOperationalMeal(
        PDO $db,
        int $organizerId,
        int $participantId,
        int $eventId,
        ?int $eventDayId = null,
        ?int $eventShiftId = null,
        ?string $sector = null,
        ?int $mealServiceId = null,
        ?string $mealServiceCode = null,
        ?string $offlineRequestId = null,
        ?string $consumedAt = null,
        ?string $operationalTimezone = null
    ): array {
        $offlineRequestId = trim((string)($offlineRequestId ?? ''));
        $context = self::resolveOperationalConsumptionContext(
            $db,
            $organizerId,
            $eventId,
            $eventDayId,
            $eventShiftId,
            $consumedAt,
            $operationalTimezone
        );
        $eventId = (int)($context['event_id'] ?? 0);
        $eventDayId = (int)($context['event_day_id'] ?? 0);
        $eventShiftId = isset($context['event_shift_id']) && $context['event_shift_id'] !== null
            ? (int)$context['event_shift_id']
            : null;
        $resolvedConsumedAt = (string)($context['consumed_at'] ?? self::normalizeConsumedAt($consumedAt, $operationalTimezone));
        $selectedMealService = self::resolveMealServiceSelection(
            $db,
            $organizerId,
            $eventId,
            $mealServiceId,
            $mealServiceCode,
            $resolvedConsumedAt
        );
        if (!$selectedMealService) {
            $availableServices = self::listEventMealServices($db, $organizerId, $eventId, true);
            if (empty($availableServices)) {
                throw new Exception('Nenhum serviço de refeição ativo foi encontrado para este evento.', 409);
            }

            throw new Exception(
                'Nenhum serviço de refeição cobre o horário informado. Selecione a refeição manualmente ou revise as janelas configuradas no evento.',
                409
            );
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
            'participant_id' => $participantId,
            'event_id' => $eventId,
            'event_day_id' => $eventDayId,
            'event_shift_id' => $eventShiftId,
            'sector' => $sector !== null ? trim((string)$sector) : null,
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

    private static function resolveEventDayByConsumedAt(PDO $db, int $organizerId, int $eventId, string $consumedAt): ?array
    {
        $stmt = $db->prepare("
            SELECT
                ed.id,
                ed.event_id,
                ed.date,
                ed.starts_at,
                ed.ends_at
            FROM event_days ed
            JOIN events e ON e.id = ed.event_id
            WHERE ed.event_id = :event_id
              AND e.organizer_id = :organizer_id
              AND (
                    (ed.starts_at IS NOT NULL AND ed.ends_at IS NOT NULL AND CAST(:consumed_at AS timestamp) >= ed.starts_at AND CAST(:consumed_at AS timestamp) <= ed.ends_at)
                    OR ((ed.starts_at IS NULL OR ed.ends_at IS NULL) AND CAST(:consumed_at AS date) = ed.date)
                  )
            ORDER BY COALESCE(ed.starts_at, ed.date::timestamp) ASC, ed.id ASC
            LIMIT 2
        ");
        $stmt->execute([
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
            ':consumed_at' => $consumedAt,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 0) {
            return null;
        }
        if (count($rows) > 1) {
            throw new Exception('Mais de um dia operacional cobre o horário informado. Revise as janelas de event_days do evento.', 409);
        }

        return $rows[0];
    }

    private static function resolveEventShiftByConsumedAt(
        PDO $db,
        int $organizerId,
        int $eventId,
        int $eventDayId,
        string $consumedAt
    ): ?array {
        if ($eventDayId <= 0) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT
                es.id,
                es.event_day_id,
                es.name,
                es.starts_at,
                es.ends_at
            FROM event_shifts es
            JOIN event_days ed ON ed.id = es.event_day_id
            JOIN events e ON e.id = ed.event_id
            WHERE es.event_day_id = :event_day_id
              AND ed.event_id = :event_id
              AND e.organizer_id = :organizer_id
              AND es.starts_at IS NOT NULL
              AND es.ends_at IS NOT NULL
              AND CAST(:consumed_at AS timestamp) >= es.starts_at
              AND CAST(:consumed_at AS timestamp) <= es.ends_at
            ORDER BY es.starts_at ASC, es.id ASC
            LIMIT 2
        ");
        $stmt->execute([
            ':event_day_id' => $eventDayId,
            ':event_id' => $eventId,
            ':organizer_id' => $organizerId,
            ':consumed_at' => $consumedAt,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 0) {
            return null;
        }
        if (count($rows) > 1) {
            throw new Exception('Mais de um turno operacional cobre o horário informado. Revise as janelas de event_shifts do evento.', 409);
        }

        return $rows[0];
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

    private static function normalizeServiceCode(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        if (!in_array($normalized, self::ALLOWED_SERVICE_CODES, true)) {
            throw new InvalidArgumentException('service_code do serviço de refeição é inválido.');
        }

        return $normalized;
    }

    private static function defaultSortOrderForServiceCode(string $serviceCode): int
    {
        $normalizedCode = strtolower(trim($serviceCode));
        return (int)(self::DEFAULT_SORT_ORDERS[$normalizedCode] ?? 0);
    }

    private static function validateMealServiceGrid(array $services): void
    {
        if (empty($services)) {
            throw new InvalidArgumentException('Nenhum serviço de refeição foi informado para validação.');
        }

        $sortOrders = [];
        $activeServices = [];

        foreach ($services as $service) {
            $label = trim((string)($service['label'] ?? $service['service_code'] ?? 'Serviço'));
            $sortOrder = (int)($service['sort_order'] ?? 0);
            $startsAt = self::normalizeTime((string)($service['starts_at'] ?? ''));
            $endsAt = self::normalizeTime((string)($service['ends_at'] ?? ''));

            if ($sortOrder <= 0) {
                throw new InvalidArgumentException(sprintf('sort_order inválido para o serviço "%s".', $label));
            }
            if (isset($sortOrders[$sortOrder])) {
                throw new InvalidArgumentException(sprintf(
                    'sort_order duplicado na grade de refeições: "%s" e "%s" usam a mesma posição operacional.',
                    $sortOrders[$sortOrder],
                    $label
                ));
            }
            $sortOrders[$sortOrder] = $label;

            if ($startsAt === null || $endsAt === null) {
                throw new InvalidArgumentException(sprintf('Os horários do serviço "%s" estão incompletos.', $label));
            }
            if ($startsAt === $endsAt) {
                throw new InvalidArgumentException(sprintf('A janela do serviço "%s" não pode ter início e fim iguais.', $label));
            }

            if (!empty($service['is_active'])) {
                $activeServices[] = [
                    'service_code' => (string)($service['service_code'] ?? ''),
                    'label' => $label,
                    'sort_order' => $sortOrder,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ];
            }
        }

        if (count($activeServices) === 0) {
            throw new InvalidArgumentException('Mantenha ao menos um serviço de refeição ativo na grade.');
        }

        usort($activeServices, static fn(array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        $previousRank = 0;
        $previousLabel = null;
        foreach ($activeServices as $service) {
            $rank = self::resolveMealServiceRank(
                (int)$service['sort_order'],
                (string)$service['service_code']
            );
            if ($rank <= $previousRank) {
                throw new InvalidArgumentException(sprintf(
                    'sort_order incompatível com a regra de cota: "%s" precisa ficar depois de "%s" sem compartilhar o mesmo slot operacional.',
                    (string)$service['label'],
                    (string)$previousLabel
                ));
            }

            $previousRank = $rank;
            $previousLabel = (string)$service['label'];
        }

        $activeCount = count($activeServices);
        for ($leftIndex = 0; $leftIndex < $activeCount; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $activeCount; $rightIndex++) {
                $leftService = $activeServices[$leftIndex];
                $rightService = $activeServices[$rightIndex];
                if (!self::mealServiceWindowsOverlap($leftService, $rightService)) {
                    continue;
                }

                throw new InvalidArgumentException(sprintf(
                    'As janelas de "%s" (%s-%s) e "%s" (%s-%s) se sobrepõem. Ajuste a grade antes de salvar.',
                    (string)$leftService['label'],
                    (string)$leftService['starts_at'],
                    (string)$leftService['ends_at'],
                    (string)$rightService['label'],
                    (string)$rightService['starts_at'],
                    (string)$rightService['ends_at']
                ));
            }
        }
    }

    private static function mealServiceWindowsOverlap(array $leftService, array $rightService): bool
    {
        $leftSegments = self::splitMealServiceWindowIntoSegments(
            (string)($leftService['starts_at'] ?? ''),
            (string)($leftService['ends_at'] ?? '')
        );
        $rightSegments = self::splitMealServiceWindowIntoSegments(
            (string)($rightService['starts_at'] ?? ''),
            (string)($rightService['ends_at'] ?? '')
        );

        foreach ($leftSegments as $leftSegment) {
            foreach ($rightSegments as $rightSegment) {
                if (
                    max($leftSegment['start'], $rightSegment['start']) <=
                    min($leftSegment['end'], $rightSegment['end'])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function splitMealServiceWindowIntoSegments(string $startsAt, string $endsAt): array
    {
        $start = self::timeToSeconds($startsAt);
        $end = self::timeToSeconds($endsAt);
        if ($start === null || $end === null) {
            return [];
        }

        if ($start < $end) {
            return [
                ['start' => $start, 'end' => $end],
            ];
        }

        return [
            ['start' => $start, 'end' => 86399],
            ['start' => 0, 'end' => $end],
        ];
    }

    private static function timeToSeconds(string $value): ?int
    {
        $normalized = self::normalizeTime($value);
        if ($normalized === null) {
            return null;
        }

        [$hours, $minutes, $seconds] = array_map('intval', explode(':', $normalized));
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private static function buildDefaultServicesPayload(int $eventId, float $fallbackUnitCost): array
    {
        return array_map(static function (array $service) use ($eventId, $fallbackUnitCost): array {
            return [
                'id' => null,
                'event_id' => $eventId,
                'service_code' => (string)$service['service_code'],
                'label' => (string)$service['label'],
                'sort_order' => (int)$service['sort_order'],
                'starts_at' => (string)$service['starts_at'],
                'ends_at' => (string)$service['ends_at'],
                'unit_cost' => round($fallbackUnitCost, 2),
                'is_active' => true,
                'created_at' => null,
                'updated_at' => null,
            ];
        }, self::DEFAULT_SERVICES);
    }

    public static function normalizeConsumedAt(?string $value, ?string $operationalTimezone = null): string
    {
        $trimmed = trim((string)($value ?? ''));
        $timezone = self::createOperationalTimezone($operationalTimezone);

        if ($trimmed === '') {
            $now = $timezone !== null ? new DateTimeImmutable('now', $timezone) : new DateTimeImmutable('now');
            return $now->format('Y-m-d H:i:s');
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?(?:\.\d+)?$/', $trimmed, $matches)) {
            return sprintf(
                '%s %s:%s',
                $matches[1],
                $matches[2],
                $matches[3] ?? '00'
            );
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/i', $trimmed)) {
            if ($timezone === null) {
                throw new Exception(
                    'consumed_at com timezone/offset exige event_timezone no evento ou operational_timezone explícita para evitar deriva no dia ou turno operacional.',
                    409
                );
            }

            try {
                return (new DateTimeImmutable($trimmed))
                    ->setTimezone($timezone)
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                throw new Exception('consumed_at inválido. Use ISO 8601 ou YYYY-MM-DD HH:MM[:SS].', 400);
            }
        }

        throw new Exception('consumed_at inválido. Use ISO 8601 ou YYYY-MM-DD HH:MM[:SS].', 400);
    }

    private static function resolveOperationalTimezone(
        PDO $db,
        int $organizerId,
        int $eventId,
        ?string $requestedOperationalTimezone = null
    ): ?string {
        $requestedTimezone = self::normalizeOperationalTimezoneName($requestedOperationalTimezone);
        $eventTimezone = null;

        if (self::columnExists($db, 'events', 'event_timezone')) {
            $stmt = $db->prepare("
                SELECT NULLIF(TRIM(COALESCE(event_timezone, '')), '')
                FROM events
                WHERE id = ? AND organizer_id = ?
                LIMIT 1
            ");
            $stmt->execute([$eventId, $organizerId]);
            $eventTimezone = self::normalizeOperationalTimezoneName($stmt->fetchColumn());
        }

        if ($eventTimezone !== null && $requestedTimezone !== null && $eventTimezone !== $requestedTimezone) {
            throw new Exception(
                'operational_timezone diverge da event_timezone configurada para este evento. Alinhe o payload ao cadastro do evento.',
                409
            );
        }

        return $eventTimezone ?? $requestedTimezone;
    }

    private static function createOperationalTimezone(?string $operationalTimezone): ?DateTimeZone
    {
        $timezoneName = self::normalizeOperationalTimezoneName($operationalTimezone);
        if ($timezoneName === null) {
            return null;
        }

        try {
            return new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new Exception('operational_timezone inválida. Use um identificador IANA, por exemplo America/Sao_Paulo.', 400);
        }
    }

    private static function normalizeOperationalTimezoneName(mixed $value): ?string
    {
        $timezoneName = trim((string)($value ?? ''));
        return $timezoneName !== '' ? $timezoneName : null;
    }

    private static function serviceMatchesTimeReference(?string $startsAt, ?string $endsAt, string $timeReference): bool
    {
        $normalizedStart = self::normalizeTime((string)($startsAt ?? ''));
        $normalizedEnd = self::normalizeTime((string)($endsAt ?? ''));
        if ($normalizedStart === null || $normalizedEnd === null) {
            return false;
        }

        if ($normalizedStart <= $normalizedEnd) {
            return $timeReference >= $normalizedStart && $timeReference <= $normalizedEnd;
        }

        return $timeReference >= $normalizedStart || $timeReference <= $normalizedEnd;
    }

    private static function extractTimeReference(?string $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return date('H:i:s');
        }

        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return self::normalizeTime($value);
        }

        return substr(self::normalizeConsumedAt($value), 11, 8);
    }

    private static function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
