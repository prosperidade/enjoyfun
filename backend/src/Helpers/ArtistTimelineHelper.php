<?php

function artistParseTimestamp(?string $value): ?DateTimeImmutable
{
    $normalized = trim((string)($value ?? ''));
    if ($normalized === '') {
        return null;
    }

    try {
        return new DateTimeImmutable(str_replace('T', ' ', $normalized));
    } catch (Throwable) {
        return null;
    }
}

function artistFormatTimestamp(?DateTimeImmutable $value): ?string
{
    return $value?->format('Y-m-d H:i:s');
}

function artistAddMinutesToTimestamp(?string $value, ?int $minutes): ?string
{
    $base = artistParseTimestamp($value);
    $normalizedMinutes = $minutes !== null ? (int)$minutes : null;

    if ($base === null || $normalizedMinutes === null || $normalizedMinutes < 0) {
        return null;
    }

    return artistFormatTimestamp($base->modify('+' . $normalizedMinutes . ' minutes'));
}

function artistCalculateMarginBetweenTimestamps(?string $referenceAt, ?string $deadlineAt): ?int
{
    $reference = artistParseTimestamp($referenceAt);
    $deadline = artistParseTimestamp($deadlineAt);

    if ($reference === null || $deadline === null) {
        return null;
    }

    $marginSeconds = $deadline->getTimestamp() - $reference->getTimestamp();
    return (int)floor($marginSeconds / 60);
}

function artistCalculateShowEndAt(?string $showStartAt, ?int $durationMinutes): ?string
{
    $start = artistParseTimestamp($showStartAt);
    $duration = (int)($durationMinutes ?? 0);

    if ($start === null || $duration <= 0) {
        return null;
    }

    return artistFormatTimestamp($start->modify('+' . $duration . ' minutes'));
}

function artistCalculatePlannedEtaMinutes(int $etaBaseMinutes, ?int $etaPeakMinutes = null, int $bufferMinutes = 0): int
{
    $base = max(0, $etaBaseMinutes);
    $peak = $etaPeakMinutes !== null ? max(0, $etaPeakMinutes) : null;
    $buffer = max(0, $bufferMinutes);

    return (int)(($peak ?? $base) + $buffer);
}

function artistCalculateWindowMarginMinutes(?string $originAt, ?int $plannedEtaMinutes, ?string $deadlineAt): ?int
{
    $origin = artistParseTimestamp($originAt);
    $deadline = artistParseTimestamp($deadlineAt);
    $eta = (int)($plannedEtaMinutes ?? -1);

    if ($origin === null || $deadline === null || $eta < 0) {
        return null;
    }

    $arrival = $origin->modify('+' . $eta . ' minutes');
    $marginSeconds = $deadline->getTimestamp() - $arrival->getTimestamp();

    return (int)floor($marginSeconds / 60);
}

function artistBuildDerivedTimeline(array $timeline, ?int $performanceDurationMinutes = null): array
{
    $derived = $timeline;
    if (empty($derived['show_end_at'])) {
        $derived['show_end_at'] = artistCalculateShowEndAt(
            $derived['show_start_at'] ?? null,
            $performanceDurationMinutes
        );
    }

    return $derived;
}

function artistStringContainsAnyToken(string $haystack, array $tokens): bool
{
    foreach ($tokens as $token) {
        if ($token !== '' && str_contains($haystack, $token)) {
            return true;
        }
    }

    return false;
}

function artistResolveTransferPhase(
    ?string $routeCode,
    ?string $originLabel = null,
    ?string $destinationLabel = null
): string {
    $route = strtolower(trim((string)($routeCode ?? '')));
    $origin = strtolower(trim((string)($originLabel ?? '')));
    $destination = strtolower(trim((string)($destinationLabel ?? '')));

    $venueTokens = ['venue', 'palco', 'stage', 'backstage', 'show', 'soundcheck'];
    $departureTokens = ['departure', 'return', 'saida', 'exit', 'outbound', 'checkout', 'post-show', 'postshow', 'aftershow'];
    $arrivalTokens = ['arrival', 'landing', 'hotel', 'checkin', 'check-in', 'transfer-in', 'airport', 'aeroporto'];

    if (
        artistStringContainsAnyToken($route, $departureTokens) ||
        artistStringContainsAnyToken($origin, $venueTokens)
    ) {
        return 'departure';
    }

    if (artistStringContainsAnyToken($destination, $venueTokens)) {
        return 'arrival';
    }

    if (artistStringContainsAnyToken($route, $arrivalTokens)) {
        return 'arrival';
    }

    if (
        artistStringContainsAnyToken($destination, ['hotel', 'airport', 'aeroporto']) ||
        artistStringContainsAnyToken($origin, ['hotel', 'airport', 'aeroporto'])
    ) {
        return 'arrival';
    }

    return 'other';
}

function artistSummarizeTransferWindows(array $transfers): array
{
    $summary = [
        'arrival_eta_minutes' => null,
        'departure_eta_minutes' => null,
        'arrival_routes' => [],
        'departure_routes' => [],
        'other_routes' => [],
    ];

    $arrivalTotal = 0;
    $departureTotal = 0;

    foreach ($transfers as $transfer) {
        $phase = (string)($transfer['route_phase'] ?? artistResolveTransferPhase(
            $transfer['route_code'] ?? null,
            $transfer['origin_label'] ?? null,
            $transfer['destination_label'] ?? null
        ));
        $minutes = isset($transfer['planned_eta_minutes']) ? max(0, (int)$transfer['planned_eta_minutes']) : null;

        if ($phase === 'arrival' && $minutes !== null) {
            $arrivalTotal += $minutes;
            $summary['arrival_routes'][] = $transfer;
            continue;
        }

        if ($phase === 'departure' && $minutes !== null) {
            $departureTotal += $minutes;
            $summary['departure_routes'][] = $transfer;
            continue;
        }

        $summary['other_routes'][] = $transfer;
    }

    if (!empty($summary['arrival_routes'])) {
        $summary['arrival_eta_minutes'] = $arrivalTotal;
    }
    if (!empty($summary['departure_routes'])) {
        $summary['departure_eta_minutes'] = $departureTotal;
    }

    return $summary;
}
