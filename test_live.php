<?php
$baseUrl = 'http://localhost:8000';

function makeRequest($method, $url, $data = null, $token = null) {
    global $baseUrl;
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true
        ]
    ];
    if ($data) {
        $options['http']['content'] = json_encode($data);
    }
    
    $context = stream_context_create($options);
    $response = @file_get_contents($baseUrl . $url, false, $context);
    
    $code = 500;
    if (isset($http_response_header) && is_array($http_response_header)) {
        if (preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0], $match)) {
            $code = intval($match[1]);
        }
    }
    
    return ['code' => $code, 'data' => json_decode($response, true) ?: $response];
}

echo "=== VALIDAÇÃO VIVA: MEALS OPERATION ===" . PHP_EOL;

// 1. Auth Login (SuperAdmin or Organizer)
$loginParams = ['email' => 'admin@enjoyfun.com.br', 'password' => '123456'];
$login = makeRequest('POST', '/api/auth/login', $loginParams);

if ($login['code'] !== 200) {
    echo "Login falhou: " . json_encode($login) . PHP_EOL;
    // Tenta fallback com credencial comum
    $loginParams = ['email' => 'organizer@enjoyfun.com.br', 'password' => '123456'];
    $login = makeRequest('POST', '/api/auth/login', $loginParams);
}

$token = $login['data']['token'] ?? null;
if (!$token) {
    // Busca algum usuário staff no DB para simular
    exec('set PGPASSWORD="070998"; psql -U postgres -d enjoyfun -t -c "SELECT id FROM digital_cards LIMIT 1"', $out);
    die("Falha ao obter token. Abortando. Code " . $login['code'] . PHP_EOL);
}

echo "1. Login OK. Token obtido." . PHP_EOL;

// 2. Load Event Days
$daysResp = makeRequest('GET', '/api/event-days?event_id=1', null, $token);
echo "2. Event Days (Destrava Dia): " . count($daysResp['data']['data'] ?? $daysResp['data'] ?? []) . " dia(s) retornado(s)." . PHP_EOL;
$eventDayId = $daysResp['data']['data'][0]['id'] ?? $daysResp['data'][0]['id'] ?? null;

// 3. Load Event Shifts
if ($eventDayId) {
    $shiftsResp = makeRequest('GET', '/api/event-shifts?event_day_id=' . $eventDayId, null, $token);
    echo "3. Event Shifts (Destrava Turno): " . count($shiftsResp['data']['data'] ?? $shiftsResp['data'] ?? []) . " turno(s) retornado(s)." . PHP_EOL;
    $eventShiftId = $shiftsResp['data']['data'][0]['id'] ?? $shiftsResp['data'][0]['id'] ?? null;
} else {
    echo "3. Event Shifts: IGNORADO (Sem dia)" . PHP_EOL;
}

// 4. Load Balance
if ($eventDayId) {
    $balanceResp = makeRequest('GET', "/api/meals/balance?event_day_id=$eventDayId&event_shift_id=" . ($eventShiftId ?? ''), null, $token);
    echo "4. Meals Balance (Saldo Real): Código " . $balanceResp['code'] . PHP_EOL;
    echo "   -> Resposta saldos: " . json_encode($balanceResp['data']) . PHP_EOL;
}

// 5. Register Meal
if ($eventDayId && $eventShiftId) {
    // Usar um assignment de teste
    $mealData = [
        'event_day_id' => $eventDayId,
        'event_shift_id' => $eventShiftId,
        'assignment_id' => 1 // hardcoded para teste rápido
    ];
    $regResp = makeRequest('POST', '/api/meals/register', $mealData, $token);
    echo "5. Meals Register (Operacional): Código " . $regResp['code'] . PHP_EOL;
    echo "   -> Resposta: " . json_encode($regResp['data']) . PHP_EOL;
}
echo "=== FIM VALIDAÇÃO ===" . PHP_EOL;
