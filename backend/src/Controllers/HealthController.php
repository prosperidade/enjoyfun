<?php

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && ($id === null || $id === '') => healthPing(),
        $method === 'GET' && $id === 'deep' => healthDeepCheck(),
        $method === 'GET' && $id === 'metrics' => healthMetrics(),
        $method === 'GET' && $id === 'workforce' => getWorkforceOperationalHealth($query),
        $method === 'POST' && $id === 'telemetry' => recordClientOperationalTelemetry($body),
        default => jsonError('Endpoint de Health não encontrado.', 404),
    };
}

// ── GET /health — lightweight ping for load balancers ────────────────────────

function healthPing(): void
{
    jsonSuccess(['status' => 'ok', 'timestamp' => time()]);
}

// ── GET /health/deep — comprehensive dependency check for monitoring ─────────

function healthDeepCheck(): void
{
    $checks = [];

    // Database connectivity
    $checks['database'] = checkDatabase();

    // Disk space
    $checks['disk'] = checkDiskSpace();

    // Redis (optional — only if configured)
    $redisHost = trim((string)($_ENV['REDIS_HOST'] ?? ''));
    if ($redisHost !== '') {
        $checks['redis'] = checkRedis($redisHost);
    }

    // Determine overall status
    $statuses = array_column($checks, 'status');
    $hasError = in_array('error', $statuses, true);
    $hasWarning = in_array('warning', $statuses, true);

    if ($hasError) {
        $overall = 'degraded';
    } elseif ($hasWarning) {
        $overall = 'warning';
    } else {
        $overall = 'healthy';
    }

    if (ob_get_length()) ob_clean();
    http_response_code($hasError ? 503 : 200);
    echo json_encode([
        'success' => true,
        'data' => [
            'status' => $overall,
            'checks' => $checks,
            'timestamp' => date('c'),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function checkDatabase(): array
{
    try {
        $start = microtime(true);
        $db = Database::getInstance();
        $stmt = $db->query('SELECT 1');
        $stmt->fetchColumn();
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        return [
            'status' => 'ok',
            'latency_ms' => $latencyMs,
        ];
    } catch (\Throwable $e) {
        logStructured('error', 'Health check: database connection failed', [
            'error' => $e->getMessage(),
        ]);
        return [
            'status' => 'error',
            'message' => 'Connection failed',
        ];
    }
}

function checkDiskSpace(): array
{
    $path = PHP_OS_FAMILY === 'Windows' ? 'C:\\' : '/';
    $freeBytes = @disk_free_space($path);
    $totalBytes = @disk_total_space($path);

    if ($freeBytes === false) {
        return ['status' => 'warning', 'message' => 'Unable to read disk space'];
    }

    $freeMb = round($freeBytes / 1024 / 1024);
    $totalMb = $totalBytes !== false ? round($totalBytes / 1024 / 1024) : null;

    // Warning under 500 MB, error under 100 MB
    if ($freeBytes < 100 * 1024 * 1024) {
        $status = 'error';
    } elseif ($freeBytes < 500 * 1024 * 1024) {
        $status = 'warning';
    } else {
        $status = 'ok';
    }

    $result = [
        'status' => $status,
        'free_mb' => $freeMb,
    ];
    if ($totalMb !== null) {
        $result['total_mb'] = $totalMb;
    }

    return $result;
}

function checkRedis(string $host): array
{
    $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
    try {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($fp === false) {
            return ['status' => 'error', 'message' => "Cannot connect to $host:$port"];
        }
        fwrite($fp, "PING\r\n");
        $response = trim((string)fgets($fp, 128));
        fclose($fp);
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        if ($response === '+PONG') {
            return ['status' => 'ok', 'latency_ms' => $latencyMs];
        }

        return ['status' => 'warning', 'message' => 'Unexpected response', 'latency_ms' => $latencyMs];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'message' => 'Connection failed'];
    }
}

// ── GET /health/metrics — basic operational metrics ──────────────────────────

function healthMetrics(): void
{
    $user = requireAuth(['admin', 'super_admin']);
    $db = Database::getInstance();

    $metrics = [];

    // Active users (distinct users with audit activity in last 15 minutes)
    try {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT user_id) AS active_users
            FROM audit_log
            WHERE occurred_at >= NOW() - INTERVAL '15 minutes'
              AND user_id IS NOT NULL
        ");
        $stmt->execute();
        $metrics['active_users_15m'] = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $metrics['active_users_15m'] = null;
    }

    // Total requests in last hour (from audit_log telemetry)
    try {
        $action = defined('AuditService::API_REQUEST') ? AuditService::API_REQUEST : 'api.request';
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM audit_log
            WHERE action = ?
              AND occurred_at >= NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute([$action]);
        $metrics['requests_last_hour'] = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $metrics['requests_last_hour'] = null;
    }

    // Error count in last hour
    try {
        $action = defined('AuditService::API_REQUEST') ? AuditService::API_REQUEST : 'api.request';
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM audit_log
            WHERE action = ?
              AND result = 'failure'
              AND occurred_at >= NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute([$action]);
        $metrics['errors_last_hour'] = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $metrics['errors_last_hour'] = null;
    }

    // DB connection pool / active backends
    try {
        $stmt = $db->query("
            SELECT count(*) AS total,
                   count(*) FILTER (WHERE state = 'active') AS active,
                   count(*) FILTER (WHERE state = 'idle') AS idle
            FROM pg_stat_activity
            WHERE datname = current_database()
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['db_connections'] = [
            'total' => (int)($row['total'] ?? 0),
            'active' => (int)($row['active'] ?? 0),
            'idle' => (int)($row['idle'] ?? 0),
        ];
    } catch (\Throwable $e) {
        $metrics['db_connections'] = null;
    }

    // Database size
    try {
        $stmt = $db->query("SELECT pg_size_pretty(pg_database_size(current_database())) AS size");
        $metrics['db_size'] = $stmt->fetchColumn();
    } catch (\Throwable $e) {
        $metrics['db_size'] = null;
    }

    // Total organizers
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'organizer'");
        $metrics['total_organizers'] = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $metrics['total_organizers'] = null;
    }

    // Total events
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM events");
        $metrics['total_events'] = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $metrics['total_events'] = null;
    }

    // S1-04: Offline topup rejections (invalid payment method) in last 24h
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM audit_log
            WHERE action = 'offline_sync.topup_rejected'
              AND occurred_at >= NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute();
        $metrics['offline_topup_rejections_24h'] = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $metrics['offline_topup_rejections_24h'] = null;
    }

    jsonSuccess([
        'metrics' => $metrics,
        'timestamp' => date('c'),
    ]);
}

// ── Structured JSON logging utility ──────────────────────────────────────────

function logStructured(string $level, string $message, array $context = []): void
{
    $correlationId = $GLOBALS['ENJOYFUN_REQUEST_CONTEXT']['correlation_id']
        ?? $GLOBALS['ENJOYFUN_CORRELATION_ID']
        ?? null;

    $actor = $GLOBALS['ENJOYFUN_REQUEST_CONTEXT']['actor'] ?? null;

    $entry = [
        'timestamp' => date('c'),
        'level' => $level,
        'message' => $message,
    ];

    if ($correlationId !== null) {
        $entry['correlation_id'] = $correlationId;
    }

    if (is_array($actor)) {
        if (!empty($actor['id'])) {
            $entry['user_id'] = (int)$actor['id'];
        }
        if (!empty($actor['organizer_id'])) {
            $entry['organizer_id'] = (int)$actor['organizer_id'];
        }
    }

    if (!empty($context)) {
        $entry['context'] = $context;
    }

    error_log(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ── Workforce Operational Health (existing) ──────────────────────────────────

function getWorkforceOperationalHealth(array $query): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveHealthOrganizerId($user);
    $windowMinutes = max(5, min(1440, (int)($query['window_minutes'] ?? 60)));
    $eventId = (int)($query['event_id'] ?? 0);

    if (!healthTableExists($db, 'audit_log')) {
        jsonError('Tabela audit_log indisponível para telemetria operacional.', 503);
    }

    if ($eventId > 0) {
        $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $stmtEvent->execute([$eventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) {
            jsonError('Evento inválido para consultar saúde operacional do Workforce.', 403);
        }
    }

    $since = date('c', time() - ($windowMinutes * 60));
    $endpointRows = queryOperationalEndpointMetrics($db, $organizerId, $since, $eventId > 0 ? $eventId : null);
    $clientRows = queryClientTelemetryMetrics($db, $organizerId, $since, $eventId > 0 ? $eventId : null);
    $payload = buildWorkforceHealthPayload($windowMinutes, $eventId, $endpointRows, $clientRows);

    jsonSuccess($payload);
}

function recordClientOperationalTelemetry(array $body): void
{
    $user = requireAuth(['admin', 'organizer', 'manager', 'staff']);
    $db = Database::getInstance();
    $organizerId = resolveHealthOrganizerId($user);
    $eventType = trim((string)($body['event_type'] ?? ''));
    $eventId = (int)($body['event_id'] ?? 0);
    $details = $body['details'] ?? [];

    $allowedEvents = [
        'workforce.snapshot.read_failed' => 'failure',
        'workforce.snapshot.write_failed' => 'failure',
        'workforce.snapshot.fallback_used' => 'success',
        'workforce.card_issuance.preview_failed' => 'failure',
        'workforce.card_issuance.issue_failed' => 'failure',
    ];

    if (!isset($allowedEvents[$eventType])) {
        jsonError('event_type inválido para telemetria operacional.', 422);
    }

    if ($eventId > 0) {
        $stmtEvent = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $stmtEvent->execute([$eventId, $organizerId]);
        if (!$stmtEvent->fetchColumn()) {
            jsonError('Evento inválido para telemetria operacional.', 403);
        }
    }

    if (!is_array($details)) {
        $details = ['raw' => (string)$details];
    }

    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
    if ($detailsJson !== false && strlen($detailsJson) > 4000) {
        jsonError('Payload de details excede o limite de telemetria operacional.', 422);
    }

    AuditService::log(
        defined('AuditService::CLIENT_TELEMETRY') ? AuditService::CLIENT_TELEMETRY : 'client.telemetry',
        'frontend_event',
        $eventType,
        null,
        [
            'origin' => 'workforce',
            'event_type' => $eventType,
        ],
        $user,
        $allowedEvents[$eventType],
        [
            'event_id' => $eventId > 0 ? $eventId : null,
            'metadata' => [
                'origin' => 'workforce',
                'event_type' => $eventType,
                'details' => $details,
            ],
        ]
    );

    jsonSuccess(['recorded' => true], 'Telemetria operacional registrada.', 201);
}

function buildWorkforceHealthPayload(int $windowMinutes, int $eventId, array $endpointRows, array $clientRows): array
{
    $criticalEndpoints = workforceCriticalEndpointCatalog();
    $endpointMap = [];
    foreach ($endpointRows as $row) {
        $endpointMap[(string)$row['endpoint']] = [
            'endpoint' => (string)$row['endpoint'],
            'total_requests' => (int)($row['total_requests'] ?? 0),
            'failed_requests' => (int)($row['failed_requests'] ?? 0),
            'failure_rate_pct' => round((float)($row['failure_rate_pct'] ?? 0), 2),
            'avg_latency_ms' => round((float)($row['avg_latency_ms'] ?? 0), 2),
            'p95_latency_ms' => (int)round((float)($row['p95_latency_ms'] ?? 0)),
            'max_latency_ms' => (int)round((float)($row['max_latency_ms'] ?? 0)),
            'last_seen_at' => $row['last_seen_at'] ?? null,
        ];
    }

    $endpoints = [];
    $endpointsWithFailures = 0;
    foreach ($criticalEndpoints as $definition) {
        $label = $definition['label'];
        $row = $endpointMap[$label] ?? [
            'endpoint' => $label,
            'total_requests' => 0,
            'failed_requests' => 0,
            'failure_rate_pct' => 0.0,
            'avg_latency_ms' => 0.0,
            'p95_latency_ms' => 0,
            'max_latency_ms' => 0,
            'last_seen_at' => null,
        ];
        $row['status'] = resolveEndpointHealthStatus(
            (float)$row['failure_rate_pct'],
            (int)$row['p95_latency_ms'],
            (int)$row['total_requests']
        );
        $row['slo_target'] = $definition['slo_target'];
        if ((int)$row['failed_requests'] > 0) {
            $endpointsWithFailures++;
        }
        $endpoints[] = $row;
    }

    $clientCounts = [];
    foreach ($clientRows as $row) {
        $clientCounts[(string)$row['event_type']] = [
            'count' => (int)($row['total_events'] ?? 0),
            'last_seen_at' => $row['last_seen_at'] ?? null,
        ];
    }

    $snapshotReadFailures = (int)($clientCounts['workforce.snapshot.read_failed']['count'] ?? 0);
    $snapshotWriteFailures = (int)($clientCounts['workforce.snapshot.write_failed']['count'] ?? 0);
    $snapshotFallbackUsed = (int)($clientCounts['workforce.snapshot.fallback_used']['count'] ?? 0);
    $cardIssuancePreviewFailures = (int)($clientCounts['workforce.card_issuance.preview_failed']['count'] ?? 0);
    $cardIssuanceIssueFailures = (int)($clientCounts['workforce.card_issuance.issue_failed']['count'] ?? 0);
    $snapshotInvalidCount = $snapshotReadFailures + $snapshotWriteFailures;

    $syncRow = null;
    foreach ($endpoints as $row) {
        if ($row['endpoint'] === 'POST /sync') {
            $syncRow = $row;
            break;
        }
    }

    $syncFailureRate = (float)($syncRow['failure_rate_pct'] ?? 0);
    $overallStatus = 'healthy';
    if ($snapshotInvalidCount > 0 || $syncFailureRate >= 5.0) {
        $overallStatus = 'degraded';
    } elseif (
        $endpointsWithFailures > 0 ||
        $syncFailureRate > 0 ||
        $snapshotFallbackUsed > 0 ||
        $cardIssuancePreviewFailures > 0 ||
        $cardIssuanceIssueFailures > 0
    ) {
        $overallStatus = 'warning';
    }

    return [
        'status' => $overallStatus,
        'generated_at' => date('c'),
        'window_minutes' => $windowMinutes,
        'event_id' => $eventId > 0 ? $eventId : null,
        'slo_targets' => [
            'endpoint_failure_rate_pct_warning' => 1.0,
            'endpoint_failure_rate_pct_degraded' => 5.0,
            'endpoint_p95_latency_ms_warning' => 1200,
            'endpoint_p95_latency_ms_degraded' => 2500,
            'sync_failure_rate_pct_degraded' => 5.0,
            'snapshot_invalid_count_degraded' => 1,
        ],
        'summary' => [
            'critical_endpoints_total' => count($criticalEndpoints),
            'critical_endpoints_observed' => count(array_filter($endpoints, static fn(array $row): bool => (int)$row['total_requests'] > 0)),
            'critical_endpoints_with_failures' => $endpointsWithFailures,
            'sync_requests_total' => (int)($syncRow['total_requests'] ?? 0),
            'sync_failed_requests' => (int)($syncRow['failed_requests'] ?? 0),
            'sync_failure_rate_pct' => round($syncFailureRate, 2),
            'snapshot_invalid_count' => $snapshotInvalidCount,
            'snapshot_read_failures' => $snapshotReadFailures,
            'snapshot_write_failures' => $snapshotWriteFailures,
            'snapshot_fallback_used' => $snapshotFallbackUsed,
            'card_issuance_preview_failures' => $cardIssuancePreviewFailures,
            'card_issuance_issue_failures' => $cardIssuanceIssueFailures,
        ],
        'endpoints' => $endpoints,
        'client_signals' => [
            'snapshot_read_failed' => [
                'count' => $snapshotReadFailures,
                'last_seen_at' => $clientCounts['workforce.snapshot.read_failed']['last_seen_at'] ?? null,
            ],
            'snapshot_write_failed' => [
                'count' => $snapshotWriteFailures,
                'last_seen_at' => $clientCounts['workforce.snapshot.write_failed']['last_seen_at'] ?? null,
            ],
            'snapshot_fallback_used' => [
                'count' => $snapshotFallbackUsed,
                'last_seen_at' => $clientCounts['workforce.snapshot.fallback_used']['last_seen_at'] ?? null,
            ],
            'card_issuance_preview_failed' => [
                'count' => $cardIssuancePreviewFailures,
                'last_seen_at' => $clientCounts['workforce.card_issuance.preview_failed']['last_seen_at'] ?? null,
            ],
            'card_issuance_issue_failed' => [
                'count' => $cardIssuanceIssueFailures,
                'last_seen_at' => $clientCounts['workforce.card_issuance.issue_failed']['last_seen_at'] ?? null,
            ],
        ],
    ];
}

function queryOperationalEndpointMetrics(PDO $db, int $organizerId, string $since, ?int $eventId = null): array
{
    // Exclui probes antigos do runner de auditoria que foram gravados antes
    // da marcacao synthetic e acabavam poluindo a saude operacional.
    $sql = "
        SELECT
            entity_id AS endpoint,
            COUNT(*) AS total_requests,
            COUNT(*) FILTER (WHERE result <> 'success') AS failed_requests,
            COALESCE(ROUND((COUNT(*) FILTER (WHERE result <> 'success')) * 100.0 / NULLIF(COUNT(*), 0), 2), 0) AS failure_rate_pct,
            COALESCE(ROUND(AVG(NULLIF(metadata->>'latency_ms', '')::numeric), 2), 0) AS avg_latency_ms,
            COALESCE(MAX(NULLIF(metadata->>'latency_ms', '')::numeric), 0) AS max_latency_ms,
            COALESCE(PERCENTILE_DISC(0.95) WITHIN GROUP (ORDER BY NULLIF(metadata->>'latency_ms', '')::numeric), 0) AS p95_latency_ms,
            MAX(occurred_at) AS last_seen_at
        FROM audit_log
        WHERE action = ?
          AND entity_type = 'endpoint'
          AND organizer_id = ?
          AND occurred_at >= ?
          AND COALESCE(metadata->>'synthetic', 'false') <> 'true'
          AND NOT (
              metadata->>'synthetic' IS NULL
              AND LOWER(COALESCE(user_agent, '')) = 'node'
          )
    ";
    $params = [
        defined('AuditService::API_REQUEST') ? AuditService::API_REQUEST : 'api.request',
        $organizerId,
        $since,
    ];

    if ($eventId !== null && $eventId > 0) {
        $sql .= " AND event_id = ? ";
        $params[] = $eventId;
    }

    $sql .= " GROUP BY entity_id ORDER BY entity_id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function queryClientTelemetryMetrics(PDO $db, int $organizerId, string $since, ?int $eventId = null): array
{
    $sql = "
        SELECT
            entity_id AS event_type,
            COUNT(*) AS total_events,
            MAX(occurred_at) AS last_seen_at
        FROM audit_log
        WHERE action = ?
          AND entity_type = 'frontend_event'
          AND organizer_id = ?
          AND occurred_at >= ?
    ";
    $params = [
        defined('AuditService::CLIENT_TELEMETRY') ? AuditService::CLIENT_TELEMETRY : 'client.telemetry',
        $organizerId,
        $since,
    ];

    if ($eventId !== null && $eventId > 0) {
        $sql .= " AND event_id = ? ";
        $params[] = $eventId;
    }

    $sql .= " GROUP BY entity_id ORDER BY entity_id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function resolveEndpointHealthStatus(float $failureRatePct, int $p95LatencyMs, int $totalRequests): string
{
    if ($totalRequests <= 0) {
        return 'no_data';
    }
    if ($failureRatePct >= 5.0 || $p95LatencyMs >= 2500) {
        return 'degraded';
    }
    if ($failureRatePct > 0 || $p95LatencyMs >= 1200) {
        return 'warning';
    }
    return 'healthy';
}

function workforceCriticalEndpointCatalog(): array
{
    return [
        ['label' => 'GET /workforce/tree-status', 'slo_target' => 'erro < 1% | p95 < 1200ms'],
        ['label' => 'GET /workforce/roles', 'slo_target' => 'erro < 1% | p95 < 1200ms'],
        ['label' => 'GET /workforce/event-roles', 'slo_target' => 'erro < 1% | p95 < 1200ms'],
        ['label' => 'GET /workforce/managers', 'slo_target' => 'erro < 1% | p95 < 1200ms'],
        ['label' => 'GET /workforce/assignments', 'slo_target' => 'erro < 1% | p95 < 1500ms'],
        ['label' => 'POST /workforce/assignments', 'slo_target' => 'erro < 1% | p95 < 1500ms'],
        ['label' => 'DELETE /workforce/assignments/:id', 'slo_target' => 'erro < 1% | p95 < 1500ms'],
        ['label' => 'POST /workforce/card-issuance/preview', 'slo_target' => 'erro < 1% | p95 < 1500ms'],
        ['label' => 'POST /workforce/card-issuance/issue', 'slo_target' => 'erro < 1% | p95 < 2000ms'],
        ['label' => 'GET /participants', 'slo_target' => 'erro < 1% | p95 < 1500ms'],
        ['label' => 'POST /participants', 'slo_target' => 'erro < 1% | p95 < 1500ms'],
        ['label' => 'DELETE /participants/:id', 'slo_target' => 'erro < 1% | p95 < 1500ms'],
        ['label' => 'POST /participants/backfill-qrs', 'slo_target' => 'erro < 1% | p95 < 2000ms'],
        ['label' => 'POST /participants/bulk-delete', 'slo_target' => 'erro < 1% | p95 < 2000ms'],
        ['label' => 'POST /workforce/tree-backfill', 'slo_target' => 'erro < 1% | p95 < 2500ms'],
        ['label' => 'POST /workforce/tree-sanitize', 'slo_target' => 'erro < 1% | p95 < 2500ms'],
        ['label' => 'POST /sync', 'slo_target' => 'falha < 5% | p95 < 2000ms'],
    ];
}

function resolveHealthOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }

    return (int)($user['organizer_id'] ?? 0);
}

function healthTableExists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = ?
        )
    ");
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}
