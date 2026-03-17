<?php
$env = file_get_contents(__DIR__ . '/.env.testing');
preg_match('/DB_HOST=(.*)/', $env, $host);
preg_match('/DB_PORT=(.*)/', $env, $port);
preg_match('/DB_DATABASE=(.*)/', $env, $dbname);
preg_match('/DB_USERNAME=(.*)/', $env, $user);
preg_match('/DB_PASSWORD=(.*)/', $env, $pass);

$dsn = "pgsql:host=" . trim($host[1]) . ";port=" . trim($port[1]) . ";dbname=" . trim($dbname[1]);
$db = new PDO($dsn, trim($user[1]), trim($pass[1]));

require_once __DIR__ . '/src/Helpers/WorkforceEventRoleHelper.php';
function mealTableExists(PDO $db, string $table): bool { return workforceHelperTableExists($db, $table); }
function workforceAssignmentsHaveEventRoleColumns(PDO $db): bool { return workforceHelperColumnExists($db, 'workforce_assignments', 'event_role_id'); }

$event_id = 1;
$event_day_id = 2;
$organizer_id = 1;

$hasRoleSettings = mealTableExists($db, 'workforce_role_settings');
$roleConfigJoinFilter = $hasRoleSettings ? "LEFT JOIN workforce_role_settings wrs_filter ON wrs_filter.role_id = wa.role_id AND wrs_filter.organizer_id = 1" : "";
$configParts = workforceBuildOperationalConfigSqlParts($db, 'wa', 'wms_filter', 'wrs_filter', 'wer_filter', 'wr_filter.name');

$sql = "
    WITH event_scope AS (
        SELECT
            wa.id,
            wa.participant_id,
            wa.role_id,
            " . (workforceAssignmentsHaveEventRoleColumns($db) ? "wa.event_role_id" : "NULL::integer AS event_role_id") . ",
            COALESCE(NULLIF(TRIM(COALESCE(wa.sector, '')), ''), 'geral') AS sector,
            es.id AS shift_id,
            es.name AS shift_name,
            wr_filter.name AS role_name,
            {$configParts['bucket_expr']} AS raw_cost_bucket
        FROM workforce_assignments wa
        JOIN event_participants ep_scope ON ep_scope.id = wa.participant_id
        JOIN people p_scope ON p_scope.id = ep_scope.person_id
        LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
        JOIN workforce_roles wr_filter ON wr_filter.id = wa.role_id
        {$configParts['event_role_join']}
        {$roleConfigJoinFilter}
        WHERE ep_scope.event_id = 1
          AND p_scope.organizer_id = 1
          AND (
                wa.event_shift_id IS NULL
                OR es.event_day_id = 2
              )
    ),
    operational_scope AS (
        SELECT *
        FROM event_scope
        WHERE raw_cost_bucket = 'operational'
    )
    SELECT * FROM operational_scope;
";

try {
    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Count: " . count($rows) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
