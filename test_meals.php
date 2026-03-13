<?php
$base = 'http://localhost:8000/api';

$loginData = json_encode(['email' => 'admin@enjoyfun.com.br', 'password' => 'enjoyfun']);
$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n" .
                     "Accept: application/json\r\n",
        'content' => $loginData,
        'ignore_errors' => true
    ]
];
$context = stream_context_create($opts);
$res = file_get_contents("$base/auth/login", false, $context);
$data = json_decode($res, true);
$token = $data['data']['token'] ?? null;

if (!$token) {
    die("Login falhou. Resposta: $res\n");
}

function api_get($url, $token) {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $token\r\n" .
                        "Accept: application/json\r\n",
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($opts);
    $res = file_get_contents($url, false, $context);
    return json_decode($res, true);
}

echo "====================================\n";
echo "1. VERIFICANDO EVENTOS\n";
echo "====================================\n";
$events = api_get("$base/events", $token);
$eventId = $events['data'][0]['id'] ?? 1;
$eventName = $events['data'][0]['name'] ?? 'Desconhecido';
echo "Testando Evento ID: $eventId ($eventName)\n\n";

echo "====================================\n";
echo "2. VERIFICANDO EVENT_DAYS E EVENT_SHIFTS\n";
echo "====================================\n";
$days = api_get("$base/event-days?event_id=$eventId", $token);
$countDays = count($days['data'] ?? []);
echo "-> Dias do Evento localizados: $countDays\n";

$shifts = api_get("$base/event-shifts?event_id=$eventId", $token);
$countShifts = count($shifts['data'] ?? []);
echo "-> Turnos do Evento localizados: $countShifts\n\n";

echo "====================================\n";
echo "3. VERIFICANDO SETTINGS FINANCEIROS (MEAL_UNIT_COST)\n";
echo "====================================\n";
$fin = api_get("$base/organizer-finance/settings", $token);
if (isset($fin['data']['meal_unit_cost'])) {
    echo "-> meal_unit_cost: " . $fin['data']['meal_unit_cost'] . "\n\n";
} else {
    echo "-> meal_unit_cost NÃO retornou na chave data. Provando ausência ou nulo.\n";
    echo "Keys retornadas: " . implode(", ", array_keys($fin['data'] ?? [])) . "\n\n";
}

echo "====================================\n";
echo "4. ESTADO DO ENDPOINT DE MEALS/BALANCE\n";
echo "====================================\n";
$meals = api_get("$base/meals/balance?event_id=$eventId", $token);
echo "Chamada sem event_day_id:\n";
echo "-> Success: " . (($meals['success'] ?? false) ? "true" : "false") . "\n";
echo "-> Message: " . ($meals['message'] ?? 'N/A') . "\n";
if (isset($meals['data']['diagnostics'])) {
    echo "-> Status Diagnostics: " . ($meals['data']['diagnostics']['status'] ?? 'N/A') . "\n";
    echo "-> Issues Diagnostics:\n";
    print_r($meals['data']['diagnostics']['issues'] ?? []);
}
echo "\n";

echo "====================================\n";
echo "5. ESTADO DA BASE COMPLEMENTAR DO WORKFORCE\n";
echo "====================================\n";
$wf = api_get("$base/workforce/assignments?event_id=$eventId", $token);
$countWf = count($wf['data'] ?? []);
echo "-> Membros retornados no Workforce: $countWf\n";

if ($countWf > 0) {
    echo "Primeiro membro (exemplo de dados):\n";
    $first = $wf['data'][0] ?? [];
    echo "  - participant_id: " . ($first['participant_id'] ?? '') . "\n";
    echo "  - role: " . ($first['role_name'] ?? '') . "\n";
    echo "  - sector: " . ($first['sector'] ?? '') . "\n";
}

echo "\nTESTE CONCLUIDO.\n";
