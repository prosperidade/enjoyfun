<?php
$env = file_get_contents(__DIR__ . '/.env.testing');
preg_match('/DB_HOST=(.*)/', $env, $host);
preg_match('/DB_PORT=(.*)/', $env, $port);
preg_match('/DB_DATABASE=(.*)/', $env, $dbname);
preg_match('/DB_USERNAME=(.*)/', $env, $user);
preg_match('/DB_PASSWORD=(.*)/', $env, $pass);

$dsn = "pgsql:host=" . trim($host[1]) . ";port=" . trim($port[1]) . ";dbname=" . trim($dbname[1]);
$db = new PDO($dsn, trim($user[1]), trim($pass[1]));

$sql = "
    WITH event_scope AS (
        SELECT
            wa.id,
            wa.participant_id,
            wa.role_id,
            COALESCE(NULLIF(TRIM(COALESCE(wa.sector, '')), ''), 'geral') AS sector,
            wr_filter.name AS role_name,
            COALESCE(wrs_filter.cost_bucket, 'operational') AS raw_cost_bucket
        FROM workforce_assignments wa
        JOIN event_participants ep_scope ON ep_scope.id = wa.participant_id
        JOIN people p_scope ON p_scope.id = ep_scope.person_id
        LEFT JOIN event_shifts es ON es.id = wa.event_shift_id
        JOIN workforce_roles wr_filter ON wr_filter.id = wa.role_id
        LEFT JOIN workforce_role_settings wrs_filter ON wr_filter.role_id = wa.role_id AND wrs_filter.organizer_id = 1
        WHERE ep_scope.event_id = 1
          AND p_scope.organizer_id = 1
          AND (wa.event_shift_id IS NULL OR es.event_day_id = 2)
    ),
    operational_scope AS (
        SELECT *
        FROM event_scope
        WHERE (
            raw_cost_bucket = 'operational'
            AND LOWER(role_name) NOT SIMILAR TO '%(gerente|diretor|coordenador|supervisor|lider|chefe|gestor)%'
        ) OR raw_cost_bucket = 'operational'
    )
    SELECT * FROM operational_scope;
";

$stmt = $db->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total operational members found: " . count($rows) . "\n";
$roles = [];
foreach($rows as $r) {
    if (!isset($roles[$r['role_name']])) $roles[$r['role_name']] = 0;
    $roles[$r['role_name']]++;
}
print_r($roles);
