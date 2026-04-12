<?php
/**
 * BE-S4-C3: AI Grounding Validation Test Suite
 * Tests AIGroundingValidatorService heuristics.
 * Run: php tests/ai_grounding_tests_runner.php
 */

require_once __DIR__ . '/../backend/src/Services/AIGroundingValidatorService.php';

$pass = 0;
$fail = 0;
$total = 0;

function runTest(string $name, int $minScore, int $maxScore, string $response, array $toolResults, array $toolCalls = []): void
{
    global $pass, $fail, $total;
    $total++;

    $r = EnjoyFun\Services\AIGroundingValidatorService::calculateGroundingScore($response, $toolResults, $toolCalls);
    $score = $r['score'];

    if ($score >= $minScore && $score <= $maxScore) {
        echo "  PASS [$score] $name\n";
        $pass++;
    } else {
        echo "  FAIL [$score] $name (expected $minScore-$maxScore)\n";
        if (!empty($r['violations'])) {
            echo "        violations: " . implode('; ', $r['violations']) . "\n";
        }
        $fail++;
    }
}

echo "=== AI Grounding Validation Tests ===\n\n";

// --- HIGH GROUNDING (>= 80) ---
echo "-- High grounding --\n";

runTest('Clean response with tool data', 90, 100,
    'Receita do bar: R$ 5000 em 120 transacoes.',
    [['result' => ['revenue' => 5000, 'transactions' => 120]]]
);

runTest('Response says not found (short)', 90, 100,
    'Nao encontrei esse evento na base.',
    [['result' => ['found' => false]]]
);

runTest('Response with period from tool', 90, 100,
    'Nas ultimas 24h foram vendidos 50 itens.',
    [['result' => ['items_sold' => 50, 'period' => 'ultimas 24h']]]
);

runTest('Empty response', 0, 0,
    '',
    []
);

runTest('Small numbers ignored', 90, 100,
    'Foram 12 transacoes e 5 produtos.',
    [['result' => ['transactions' => 12, 'products' => 5]]]
);

runTest('Grounded temporal reference', 90, 100,
    'No periodo retornado pela consulta, o faturamento foi de R$ 8500.',
    [['result' => ['revenue' => 8500, 'period' => 'acumulado total']]]
);

// --- LOW GROUNDING (< 80) ---
echo "\n-- Low grounding --\n";

runTest('Number not in tool results', 50, 80,
    'Faturamento total: R$ 99999 no evento.',
    [['result' => ['revenue' => 5000]]]
);

runTest('Hoje without temporal context', 70, 90,
    'As vendas de hoje estao em R$ 5000.',
    [['result' => ['revenue' => 5000]]]
);

runTest('Data claims without any tool call', 25, 75,
    'O faturamento foi de R$ 10000 com 200 ingressos vendidos.',
    [], []
);

runTest('Entity not found but long response', 60, 85,
    'Nao encontrei o evento na base. Recomendo verificar as configuracoes de branding, revisar o gateway de pagamento, consultar a equipe de suporte, analisar os dados historicos do organizador e planejar uma estrategia de contingencia para o proximo evento com atencao especial ao setup completo.',
    [['result' => ['found' => false]]]
);

runTest('Vou buscar pattern', 70, 90,
    'Vou buscar os dados do bar para voce, um momento.',
    []
);

runTest('Multiple violations stack', 0, 65,
    'Hoje o faturamento do bar foi de R$ 77777. Vou buscar mais detalhes agora.',
    [], []
);

echo "\n=== Results: $pass passed, $fail failed, $total total ===\n";

if ($fail > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
} else {
    echo "ALL TESTS PASSED\n";
    exit(0);
}
