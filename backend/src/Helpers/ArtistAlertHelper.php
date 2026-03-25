<?php

require_once __DIR__ . '/ArtistTimelineHelper.php';

function artistResolveAlertSeverity(?int $marginMinutes, bool $hasRequiredData = true): string
{
    if (!$hasRequiredData || $marginMinutes === null) {
        return 'gray';
    }

    if ($marginMinutes >= 120) {
        return 'green';
    }

    if ($marginMinutes >= 60) {
        return 'yellow';
    }

    if ($marginMinutes >= 0) {
        return 'orange';
    }

    return 'red';
}

function artistBuildAlertMessage(?int $marginMinutes, bool $hasRequiredData = true): string
{
    if (!$hasRequiredData || $marginMinutes === null) {
        return 'Dados insuficientes para calcular a janela operacional.';
    }

    if ($marginMinutes < 0) {
        return 'A janela operacional esta inviavel para o horario planejado.';
    }

    if ($marginMinutes === 0) {
        return 'A janela operacional esta no limite exato.';
    }

    return 'A janela operacional possui margem estimada de ' . $marginMinutes . ' minuto(s).';
}

function artistBuildWindowAlertSnapshot(
    string $alertType,
    string $title,
    ?int $marginMinutes,
    bool $hasRequiredData = true,
    ?string $recommendedAction = null
): array {
    $severity = artistResolveAlertSeverity($marginMinutes, $hasRequiredData);

    return [
        'alert_type' => $alertType,
        'severity' => $severity,
        'title' => $title,
        'message' => artistBuildAlertMessage($marginMinutes, $hasRequiredData),
        'recommended_action' => $recommendedAction,
        'margin_minutes' => $marginMinutes,
    ];
}

function artistBuildOperationalWindows(array $timeline, array $transfers): array
{
    $transferSummary = artistSummarizeTransferWindows($transfers);

    $arrivalEtaMinutes = $transferSummary['arrival_eta_minutes'];
    $departureEtaMinutes = $transferSummary['departure_eta_minutes'];

    $arrivalReferenceAt = $timeline['venue_arrival_at']
        ?? artistAddMinutesToTimestamp($timeline['landing_at'] ?? null, $arrivalEtaMinutes);

    $departureOriginAt = $timeline['venue_exit_at'] ?? $timeline['show_end_at'] ?? null;
    $departureReferenceAt = $timeline['venue_exit_at']
        ? artistAddMinutesToTimestamp($timeline['venue_exit_at'], $departureEtaMinutes) ?? $timeline['venue_exit_at']
        : artistAddMinutesToTimestamp($timeline['show_end_at'] ?? null, $departureEtaMinutes);

    return [
        'arrival_soundcheck' => [
            'predicted_at' => $arrivalReferenceAt,
            'target_at' => $timeline['soundcheck_at'] ?? null,
            'planned_eta_minutes' => $arrivalEtaMinutes,
            'margin_minutes' => artistCalculateMarginBetweenTimestamps($arrivalReferenceAt, $timeline['soundcheck_at'] ?? null),
            'has_required_data' => $arrivalReferenceAt !== null && !empty($timeline['soundcheck_at']),
            'source' => !empty($timeline['venue_arrival_at']) ? 'timeline' : ($arrivalEtaMinutes !== null ? 'transfer' : 'insufficient'),
        ],
        'arrival_show' => [
            'predicted_at' => $arrivalReferenceAt,
            'target_at' => $timeline['show_start_at'] ?? null,
            'planned_eta_minutes' => $arrivalEtaMinutes,
            'margin_minutes' => artistCalculateMarginBetweenTimestamps($arrivalReferenceAt, $timeline['show_start_at'] ?? null),
            'has_required_data' => $arrivalReferenceAt !== null && !empty($timeline['show_start_at']),
            'source' => !empty($timeline['venue_arrival_at']) ? 'timeline' : ($arrivalEtaMinutes !== null ? 'transfer' : 'insufficient'),
        ],
        'departure_deadline' => [
            'origin_at' => $departureOriginAt,
            'predicted_at' => $departureReferenceAt,
            'target_at' => $timeline['next_departure_deadline_at'] ?? null,
            'planned_eta_minutes' => $departureEtaMinutes,
            'margin_minutes' => artistCalculateMarginBetweenTimestamps($departureReferenceAt, $timeline['next_departure_deadline_at'] ?? null),
            'has_required_data' => $departureReferenceAt !== null && !empty($timeline['next_departure_deadline_at']),
            'source' => !empty($timeline['venue_exit_at'])
                ? ($departureEtaMinutes !== null ? 'timeline+transfer' : 'timeline')
                : ($departureEtaMinutes !== null ? 'show+transfer' : 'insufficient'),
        ],
        'transfer_summary' => $transferSummary,
    ];
}

function artistBuildOperationalAlertSnapshots(array $timeline, array $transfers): array
{
    $windows = artistBuildOperationalWindows($timeline, $transfers);
    $definitions = [
        'arrival_soundcheck' => [
            'title' => 'Janela de chegada para o soundcheck',
            'recommended_action' => 'Antecipar chegada ao venue ou revisar o deslocamento ate o soundcheck.',
        ],
        'arrival_show' => [
            'title' => 'Janela de chegada para o inicio do show',
            'recommended_action' => 'Replanejar chegada ao venue para proteger o horario de show.',
        ],
        'departure_deadline' => [
            'title' => 'Janela de saida para o proximo compromisso',
            'recommended_action' => 'Revisar saida do venue e o deslocamento do proximo trecho.',
        ],
    ];

    $snapshots = [];
    foreach ($definitions as $alertType => $definition) {
        $window = $windows[$alertType];
        $snapshot = artistBuildWindowAlertSnapshot(
            $alertType,
            $definition['title'],
            $window['margin_minutes'],
            (bool)$window['has_required_data'],
            $definition['recommended_action']
        );
        $snapshot['predicted_at'] = $window['predicted_at'] ?? null;
        $snapshot['target_at'] = $window['target_at'] ?? null;
        $snapshot['planned_eta_minutes'] = $window['planned_eta_minutes'] ?? null;
        $snapshot['source'] = $window['source'] ?? 'insufficient';
        $snapshots[] = $snapshot;
    }

    return $snapshots;
}

function artistShouldPersistOperationalAlert(array $snapshot): bool
{
    return in_array((string)($snapshot['severity'] ?? ''), ['gray', 'yellow', 'orange', 'red'], true);
}

function artistResolveAlertSeverityWeight(string $severity): int
{
    return match ($severity) {
        'red' => 40,
        'orange' => 30,
        'yellow' => 20,
        'gray' => 10,
        default => 0,
    };
}

function artistResolveHighestAlertSeverity(array $severities, string $default = 'green'): string
{
    $highestSeverity = $default;
    $highestWeight = artistResolveAlertSeverityWeight($default);

    foreach ($severities as $severity) {
        $normalized = (string)$severity;
        $weight = artistResolveAlertSeverityWeight($normalized);
        if ($weight > $highestWeight) {
            $highestSeverity = $normalized;
            $highestWeight = $weight;
        }
    }

    return $highestSeverity;
}

function artistResolveTimelineStatusFromSnapshots(array $snapshots): string
{
    if ($snapshots === []) {
        return 'incomplete';
    }

    $highestSeverity = artistResolveHighestAlertSeverity(array_column($snapshots, 'severity'), 'green');

    return match ($highestSeverity) {
        'red' => 'critical',
        'orange', 'yellow' => 'attention',
        'gray' => 'incomplete',
        default => 'ready',
    };
}
