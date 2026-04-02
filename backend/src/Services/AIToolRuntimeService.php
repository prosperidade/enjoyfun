<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once BASE_PATH . '/src/Helpers/WorkforceControllerSupport.php';
require_once __DIR__ . '/FinanceWorkforceCostService.php';
require_once __DIR__ . '/WorkforceTreeUseCaseService.php';

final class AIToolRuntimeService
{
    public static function buildToolCatalog(array $context): array
    {
        $eventId = self::nullablePositiveInt($context['event_id'] ?? null);
        if ($eventId === null) {
            return [];
        }

        return [
            [
                'name' => 'get_workforce_tree_status',
                'description' => 'Read-only diagnostic for the workforce tree of the current event, including readiness, leadership coverage, blockers and missing bindings.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => [
                            'type' => 'integer',
                            'description' => 'Event identifier for the workforce tree analysis.',
                        ],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => [
                    'get_workforce_tree_status',
                    'workforce_tree_status',
                    'workforce.tree_status',
                    'workforce/tree-status',
                    'tree_status',
                    'tree-status',
                    'read_workforce_tree_status',
                ],
            ],
            [
                'name' => 'get_workforce_costs',
                'description' => 'Read-only workforce cost snapshot for the current event, optionally filtered by sector or role.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'event_id' => [
                            'type' => 'integer',
                            'description' => 'Event identifier for the workforce cost report.',
                        ],
                        'role_id' => [
                            'type' => 'integer',
                            'description' => 'Optional role identifier to filter the report.',
                        ],
                        'sector' => [
                            'type' => 'string',
                            'description' => 'Optional sector slug to scope the report.',
                        ],
                    ],
                    'required' => ['event_id'],
                    'additionalProperties' => false,
                ],
                'aliases' => [
                    'get_workforce_costs',
                    'workforce_costs',
                    'finance.workforce_costs',
                    'organizer_finance.workforce_costs',
                    'organizer-finance/workforce-costs',
                    'workforce-costs',
                    'read_workforce_costs',
                ],
            ],
        ];
    }

    public static function buildOpenAiToolDefinitions(array $catalog): array
    {
        $tools = [];
        foreach ($catalog as $tool) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ];
        }

        return $tools;
    }

    public static function buildClaudeToolDefinitions(array $catalog): array
    {
        $tools = [];
        foreach ($catalog as $tool) {
            $tools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['input_schema'],
            ];
        }

        return $tools;
    }

    public static function buildGeminiToolDefinitions(array $catalog): array
    {
        if ($catalog === []) {
            return [];
        }

        $functionDeclarations = [];
        foreach ($catalog as $tool) {
            $functionDeclarations[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => self::convertJsonSchemaToGemini($tool['input_schema'] ?? []),
            ];
        }

        return [
            [
                'functionDeclarations' => $functionDeclarations,
            ],
        ];
    }

    public static function executeReadOnlyTools(PDO $db, array $operator, array $context, array $toolCalls): array
    {
        $updatedToolCalls = [];
        $toolResults = [];
        $executedCount = 0;
        $unsupportedCount = 0;
        $failedCount = 0;

        $canBypassSector = \canBypassSectorAcl($operator);
        $userSector = \resolveUserSector($db, $operator);
        $organizerId = (int)(\resolveOrganizerId($operator) ?: ($context['organizer_id'] ?? 0));

        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $startedAt = (int)round(microtime(true) * 1000);
            $resolvedTool = self::resolveToolDefinition($toolCall['tool_name'] ?? null, $toolCall['target'] ?? null);
            $updatedCall = $toolCall;

            if (!is_array($resolvedTool)) {
                $unsupportedCount++;
                $updatedCall['runtime_status'] = 'unsupported';
                $updatedCall['runtime_message'] = 'Tool read-only ainda não suportada pelo runtime local.';
                $updatedCall['runtime_duration_ms'] = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $updatedToolCalls[] = $updatedCall;
                continue;
            }

            try {
                $result = match ($resolvedTool['name']) {
                    'get_workforce_tree_status' => self::executeWorkforceTreeStatus(
                        $db,
                        $organizerId,
                        $context,
                        (array)($toolCall['arguments'] ?? []),
                        $canBypassSector,
                        $userSector
                    ),
                    'get_workforce_costs' => self::executeWorkforceCosts(
                        $db,
                        $organizerId,
                        $context,
                        (array)($toolCall['arguments'] ?? []),
                        $canBypassSector,
                        $userSector
                    ),
                    default => throw new RuntimeException('Tool read-only reconhecida, mas ainda sem executor implementado.', 501),
                };

                $durationMs = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $resultPreview = self::buildResultPreview($resolvedTool['name'], $result);

                $updatedCall['runtime_status'] = 'completed';
                $updatedCall['runtime_message'] = 'Tool read-only executada automaticamente.';
                $updatedCall['runtime_duration_ms'] = $durationMs;
                $updatedCall['runtime_result_preview'] = $resultPreview;
                $updatedCall['resolved_tool_name'] = $resolvedTool['name'];
                $updatedToolCalls[] = $updatedCall;

                $toolResults[] = [
                    'provider_call_id' => $toolCall['provider_call_id'] ?? null,
                    'tool_name' => $resolvedTool['name'],
                    'status' => 'completed',
                    'duration_ms' => $durationMs,
                    'result_preview' => $resultPreview,
                    'result' => $result,
                ];
                $executedCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $updatedCall['runtime_status'] = 'failed';
                $updatedCall['runtime_message'] = $e->getMessage();
                $updatedCall['runtime_duration_ms'] = max(0, (int)round(microtime(true) * 1000) - $startedAt);
                $updatedCall['resolved_tool_name'] = $resolvedTool['name'];
                $updatedToolCalls[] = $updatedCall;

                $toolResults[] = [
                    'provider_call_id' => $toolCall['provider_call_id'] ?? null,
                    'tool_name' => $resolvedTool['name'],
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ];
            }
        }

        $hasToolCalls = $updatedToolCalls !== [];
        $handledAll = $hasToolCalls && $unsupportedCount === 0 && $failedCount === 0;
        $message = null;

        if ($handledAll) {
            $message = sprintf(
                'Runtime read-only executou %d tool call(s) automaticamente.',
                $executedCount
            );
        } elseif ($hasToolCalls) {
            $parts = [];
            if ($executedCount > 0) {
                $parts[] = sprintf('%d executada(s)', $executedCount);
            }
            if ($unsupportedCount > 0) {
                $parts[] = sprintf('%d não suportada(s)', $unsupportedCount);
            }
            if ($failedCount > 0) {
                $parts[] = sprintf('%d com falha', $failedCount);
            }
            $message = 'Runtime read-only parcial: ' . implode(', ', $parts) . '.';
        }

        return [
            'tool_calls' => $updatedToolCalls,
            'tool_results' => $toolResults,
            'executed_count' => $executedCount,
            'unsupported_count' => $unsupportedCount,
            'failed_count' => $failedCount,
            'handled_all' => $handledAll,
            'message' => $message,
        ];
    }

    public static function buildFallbackInsight(array $runtimeResult): string
    {
        $completed = (int)($runtimeResult['executed_count'] ?? 0);
        if ($completed <= 0) {
            return 'A IA propôs tools read-only, mas nenhuma execução automática foi concluída. Consulte os detalhes em tool_calls.';
        }

        return sprintf(
            'A IA executou %d tool call(s) read-only automaticamente. Consulte tool_results para os dados retornados.',
            $completed
        );
    }

    private static function executeWorkforceTreeStatus(
        PDO $db,
        int $organizerId,
        array $context,
        array $arguments,
        bool $canBypassSector,
        string $userSector
    ): array {
        $eventId = self::nullablePositiveInt($arguments['event_id'] ?? ($context['event_id'] ?? null));
        if ($eventId === null) {
            throw new RuntimeException('event_id é obrigatório para get_workforce_tree_status.', 422);
        }

        return WorkforceTreeUseCaseService::getStatus(
            $db,
            $organizerId,
            $eventId,
            $canBypassSector,
            $userSector
        );
    }

    private static function executeWorkforceCosts(
        PDO $db,
        int $organizerId,
        array $context,
        array $arguments,
        bool $canBypassSector,
        string $userSector
    ): array {
        $eventId = self::nullablePositiveInt($arguments['event_id'] ?? ($context['event_id'] ?? null));
        if ($eventId === null) {
            throw new RuntimeException('event_id é obrigatório para get_workforce_costs.', 422);
        }

        return FinanceWorkforceCostService::buildReport(
            $db,
            $organizerId,
            $eventId,
            self::nullablePositiveInt($arguments['role_id'] ?? null) ?? 0,
            self::normalizeSectorArg($arguments['sector'] ?? ''),
            $canBypassSector,
            $userSector
        );
    }

    private static function resolveToolDefinition(mixed $toolName, mixed $target): ?array
    {
        $candidateNames = [
            self::normalizeToolIdentifier((string)$toolName),
            self::normalizeToolIdentifier((string)$target),
        ];

        foreach (self::buildToolCatalog(['event_id' => 1]) as $tool) {
            $aliases = array_map(
                static fn(string $alias): string => self::normalizeToolIdentifier($alias),
                (array)($tool['aliases'] ?? [])
            );
            $aliases[] = self::normalizeToolIdentifier((string)($tool['name'] ?? ''));
            $aliases = array_values(array_unique(array_filter($aliases, static fn(string $value): bool => $value !== '')));

            foreach ($candidateNames as $candidate) {
                if ($candidate !== '' && in_array($candidate, $aliases, true)) {
                    return $tool;
                }
            }
        }

        return null;
    }

    private static function buildResultPreview(string $toolName, array $result): array
    {
        return match ($toolName) {
            'get_workforce_tree_status' => array_filter([
                'tree_usable' => $result['tree_usable'] ?? null,
                'tree_ready' => $result['tree_ready'] ?? null,
                'source_preference' => $result['source_preference'] ?? null,
                'manager_roots_count' => isset($result['manager_roots_count']) ? (int)$result['manager_roots_count'] : null,
                'managerial_child_roles_count' => isset($result['managerial_child_roles_count']) ? (int)$result['managerial_child_roles_count'] : null,
                'assignments_missing_bindings' => isset($result['assignments_missing_bindings']) ? (int)$result['assignments_missing_bindings'] : null,
                'activation_blockers' => is_array($result['activation_blockers'] ?? null) ? $result['activation_blockers'] : [],
            ], static fn(mixed $value): bool => $value !== null),
            'get_workforce_costs' => array_filter([
                'planned_members_total' => isset($result['planned_members_total']) ? (int)$result['planned_members_total'] : null,
                'filled_members_total' => isset($result['filled_members_total']) ? (int)$result['filled_members_total'] : null,
                'present_members_total' => isset($result['present_members_total']) ? (int)$result['present_members_total'] : null,
                'total_estimated_payment' => isset($result['total_estimated_payment']) ? (float)$result['total_estimated_payment'] : null,
                'total_estimated_hours' => isset($result['total_estimated_hours']) ? (float)$result['total_estimated_hours'] : null,
                'total_estimated_meals' => isset($result['total_estimated_meals']) ? (int)$result['total_estimated_meals'] : null,
                'by_sector_count' => is_array($result['by_sector'] ?? null) ? count($result['by_sector']) : 0,
                'by_role_count' => is_array($result['by_role'] ?? null) ? count($result['by_role']) : 0,
            ], static fn(mixed $value): bool => $value !== null),
            default => [],
        };
    }

    private static function convertJsonSchemaToGemini(array $schema): array
    {
        $type = strtoupper(trim((string)($schema['type'] ?? 'object')));
        $converted = [
            'type' => match ($type) {
                'INTEGER' => 'INTEGER',
                'NUMBER' => 'NUMBER',
                'BOOLEAN' => 'BOOLEAN',
                'ARRAY' => 'ARRAY',
                'STRING' => 'STRING',
                default => 'OBJECT',
            },
        ];

        if (is_array($schema['properties'] ?? null)) {
            $converted['properties'] = [];
            foreach ($schema['properties'] as $key => $property) {
                if (!is_string($key) || !is_array($property)) {
                    continue;
                }
                $converted['properties'][$key] = self::convertJsonSchemaToGemini($property);
                if (isset($property['description'])) {
                    $converted['properties'][$key]['description'] = (string)$property['description'];
                }
            }
        }

        if (is_array($schema['required'] ?? null) && $schema['required'] !== []) {
            $converted['required'] = array_values(array_filter(
                array_map(static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null, $schema['required']),
                static fn(?string $value): bool => $value !== null
            ));
        }

        if (is_array($schema['items'] ?? null)) {
            $converted['items'] = self::convertJsonSchemaToGemini($schema['items']);
        }

        return $converted;
    }

    private static function normalizeToolIdentifier(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(['/', '.', '-'], '_', $normalized);
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
        return trim($normalized, '_');
    }

    private static function normalizeSectorArg(mixed $value): string
    {
        return \normalizeSector((string)$value);
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)($value ?? 0);
        return $normalized > 0 ? $normalized : null;
    }
}
