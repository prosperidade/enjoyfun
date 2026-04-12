<?php
/**
 * AIGroundingValidatorService.php
 * BE-S4-C1: Validates that AI responses are grounded in tool results.
 * Calculates a grounding score (0-100) based on heuristics.
 */

namespace EnjoyFun\Services;

final class AIGroundingValidatorService
{
    /**
     * Calculate grounding score for a response.
     * Higher = more grounded. Deductions for violations.
     *
     * @return array{score: int, violations: string[], warnings: string[]}
     */
    public static function calculateGroundingScore(
        string $response,
        array $toolResults,
        array $toolCalls = []
    ): array {
        $score = 100;
        $violations = [];
        $warnings = [];

        if (trim($response) === '') {
            return ['score' => 0, 'violations' => ['Resposta vazia'], 'warnings' => []];
        }

        $toolDataString = json_encode($toolResults, JSON_UNESCAPED_UNICODE);

        // ── Heuristic 1: Numbers in response not in tool results ────
        // Extracts monetary values (R$ X) and large numbers (3+ digits)
        preg_match_all('/R\$\s?[\d.,]+|\b\d{3,}\b/', $response, $matches);
        $numbersChecked = 0;
        foreach (($matches[0] ?? []) as $num) {
            $cleanNum = preg_replace('/[R$\s.,]/', '', $num);
            if ($cleanNum === '' || strlen($cleanNum) < 3) { continue; }
            $numbersChecked++;
            if (strpos($toolDataString, $cleanNum) === false) {
                $score -= 20;
                $violations[] = "Numero '{$num}' nao encontrado nos resultados das tools";
            }
        }

        // ── Heuristic 2: Temporal claims without grounding ──────────
        $temporalClaims = [
            '/\bhoje\b/iu',
            '/\bagora\b/iu',
            '/\bneste momento\b/iu',
            '/\bate o momento\b/iu',
            '/\besta hora\b/iu',
            '/\besta noite\b/iu',
        ];
        foreach ($temporalClaims as $pattern) {
            if (preg_match($pattern, $response)) {
                // Check if tool results have temporal context
                $hasTemporalContext = preg_match('/period|as_of|date_range|time_filter|today/i', $toolDataString);
                if (!$hasTemporalContext) {
                    $score -= 10;
                    $violations[] = 'Referencia temporal sem confirmacao nos tool results';
                    break;
                }
            }
        }

        // ── Heuristic 3: Response mentions tools were called but no results ─
        if (empty($toolResults) && empty($toolCalls)) {
            $dataPatterns = ['/faturamento.*R\$/iu', '/vendas.*\d/iu', '/\d+\s*ingressos/iu'];
            foreach ($dataPatterns as $dp) {
                if (preg_match($dp, $response)) {
                    $score -= 25;
                    $violations[] = 'Dados numericos na resposta sem nenhuma tool chamada';
                    break;
                }
            }
        }

        // ── Heuristic 4: Entity not found → response should be short ────
        $notFoundInResults = false;
        foreach ($toolResults as $tr) {
            $result = $tr['result'] ?? $tr;
            if (\is_array($result) && (($result['found'] ?? null) === false || ($result['ok'] ?? null) === false || $result === [])) {
                $notFoundInResults = true;
                break;
            }
        }
        if ($notFoundInResults && \strlen($response) > 250) {
            $score -= 15;
            $warnings[] = 'Entidade nao encontrada mas resposta longa (possivel alucinacao)';
        }

        // ── Heuristic 5: "vou buscar" / "um momento" patterns ──────
        if (preg_match('/\bvou buscar\b|\bum momento\b|\bvou consultar\b|\bvou verificar\b/iu', $response)) {
            $score -= 10;
            $violations[] = 'Resposta contem "vou buscar" — execucao deve ser sincrona';
        }

        $score = max(0, min(100, $score));

        return [
            'score'      => $score,
            'violations' => $violations,
            'warnings'   => $warnings,
        ];
    }
}
