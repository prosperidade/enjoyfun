<?php

namespace EnjoyFun\Services;

final class AIToolApprovalPolicyService
{
    private const DEFAULT_APPROVAL_MODE = 'confirm_write';
    private const DEFAULT_ENTRYPOINT = 'ai/insight';
    private const RISK_LEVELS = ['none', 'read', 'write', 'destructive'];
    private const READ_HINTS = ['get', 'list', 'read', 'fetch', 'search', 'find', 'preview', 'analyze', 'summarize', 'describe'];
    private const WRITE_HINTS = ['create', 'update', 'upsert', 'save', 'sync', 'import', 'issue', 'assign', 'send', 'charge', 'refund', 'enqueue', 'approve', 'reject'];
    private const DESTRUCTIVE_HINTS = ['delete', 'remove', 'cancel', 'revoke', 'purge', 'destroy'];

    public static function resolveExecutionPolicy(array $payload): array
    {
        $organizerId = max(0, (int)($payload['organizer_id'] ?? 0));
        $eventId = self::nullablePositiveInt($payload['event_id'] ?? null);
        $requestingUserId = self::nullablePositiveInt($payload['requesting_user_id'] ?? null);
        $entrypoint = self::normalizeText($payload['entrypoint'] ?? null, 100) ?? self::DEFAULT_ENTRYPOINT;
        $surface = self::normalizeText($payload['surface'] ?? null, 100);
        $agentKey = self::normalizeText($payload['agent_key'] ?? null, 100);
        $approvalMode = self::normalizeApprovalMode($payload['approval_mode'] ?? null);
        $toolCalls = self::normalizeToolCalls((array)($payload['tool_calls'] ?? []), $organizerId, $eventId);
        $riskLevel = self::aggregateRiskLevel($toolCalls);
        $scope = self::buildApprovalScope($organizerId, $eventId, $entrypoint, $surface, $agentKey, $riskLevel, $toolCalls);
        $scopeKey = self::buildScopeKey($scope);

        $policy = [
            'approval_mode' => $approvalMode,
            'approval_status' => 'not_required',
            'approval_risk_level' => $riskLevel,
            'approval_scope_key' => $scopeKey,
            'approval_scope' => $scope,
            'approval_required' => false,
            'approval_denied' => false,
            'tool_runtime_pending' => false,
            'execution_status' => 'succeeded',
            'message' => null,
            'tool_calls' => $toolCalls,
            'approval_requested_by_user_id' => null,
        ];

        if ($toolCalls === []) {
            return $policy;
        }

        if ($approvalMode === 'manual_confirm') {
            $policy['approval_status'] = 'pending';
            $policy['approval_required'] = true;
            $policy['execution_status'] = 'blocked';
            $policy['message'] = 'A policy manual_confirm exige aprovação explícita antes de qualquer tool call da IA.';
            $policy['approval_requested_by_user_id'] = $requestingUserId;
            return $policy;
        }

        if ($approvalMode === 'auto_read_only' && in_array($riskLevel, ['write', 'destructive'], true)) {
            $policy['approval_status'] = 'rejected';
            $policy['approval_denied'] = true;
            $policy['execution_status'] = 'blocked';
            $policy['message'] = 'A policy auto_read_only não permite tools de escrita para este agente.';
            $policy['approval_requested_by_user_id'] = $requestingUserId;
            return $policy;
        }

        if ($approvalMode === 'confirm_write' && in_array($riskLevel, ['write', 'destructive'], true)) {
            $policy['approval_status'] = 'pending';
            $policy['approval_required'] = true;
            $policy['execution_status'] = 'blocked';
            $policy['message'] = 'A policy confirm_write exige aprovação antes de executar tools de escrita.';
            $policy['approval_requested_by_user_id'] = $requestingUserId;
            return $policy;
        }

        $policy['tool_runtime_pending'] = true;
        $policy['execution_status'] = 'pending';
        $policy['message'] = 'O provider propôs tool calls válidos, mas o runtime transacional de tools ainda não foi materializado nesta superfície.';

        return $policy;
    }

    public static function normalizeApprovalMode(mixed $value): string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['manual_confirm', 'confirm_write', 'auto_read_only'], true)
            ? $normalized
            : self::DEFAULT_APPROVAL_MODE;
    }

    private static function normalizeToolCalls(array $toolCalls, int $organizerId, ?int $eventId): array
    {
        $normalized = [];
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $toolName = self::resolveToolName($toolCall) ?? 'tool';
            $arguments = self::extractToolArguments($toolCall);
            $riskLevel = self::resolveRiskLevel($toolCall, $toolName, $arguments);
            $method = self::normalizeHttpMethod($toolCall['method'] ?? ($arguments['method'] ?? null));
            $target = self::normalizeText(
                $toolCall['target'] ?? ($toolCall['resource'] ?? ($toolCall['entity'] ?? ($arguments['target'] ?? ($arguments['resource'] ?? $arguments['entity'] ?? null)))),
                100
            );
            $callOrganizerId = self::nullablePositiveInt($arguments['organizer_id'] ?? ($toolCall['organizer_id'] ?? null)) ?? ($organizerId > 0 ? $organizerId : null);
            $callEventId = self::nullablePositiveInt($arguments['event_id'] ?? ($toolCall['event_id'] ?? null)) ?? $eventId;
            $providerCallId = self::normalizeText($toolCall['id'] ?? ($toolCall['tool_call_id'] ?? null), 120);

            $normalized[] = [
                'provider_call_id' => $providerCallId,
                'tool_name' => $toolName,
                'risk_level' => $riskLevel,
                'method' => $method,
                'target' => $target,
                'read_only' => in_array($riskLevel, ['none', 'read'], true),
                'arguments' => self::sanitizeArgumentsForRuntime($arguments),
                'scope' => array_filter([
                    'organizer_id' => $callOrganizerId,
                    'event_id' => $callEventId,
                    'target' => $target,
                ], static fn(mixed $value): bool => $value !== null && $value !== ''),
                'arguments_preview' => self::buildArgumentsPreview($arguments),
            ];
        }

        return array_values($normalized);
    }

    private static function buildApprovalScope(
        int $organizerId,
        ?int $eventId,
        string $entrypoint,
        ?string $surface,
        ?string $agentKey,
        string $riskLevel,
        array $toolCalls
    ): array {
        $toolFingerprints = array_map(
            static function (array $toolCall): string {
                return implode(':', array_filter([
                    $toolCall['tool_name'] ?? null,
                    $toolCall['risk_level'] ?? null,
                    $toolCall['target'] ?? null,
                    isset($toolCall['scope']['event_id']) ? 'event=' . (int)$toolCall['scope']['event_id'] : null,
                ], static fn(mixed $value): bool => $value !== null && $value !== ''));
            },
            $toolCalls
        );
        sort($toolFingerprints);

        return array_filter([
            'organizer_id' => $organizerId > 0 ? $organizerId : null,
            'event_id' => $eventId,
            'entrypoint' => $entrypoint,
            'surface' => $surface,
            'agent_key' => $agentKey,
            'risk_level' => $riskLevel,
            'tool_fingerprints' => $toolFingerprints,
        ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private static function buildScopeKey(array $scope): string
    {
        $encoded = json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return sha1('ai-tool-approval-scope');
        }

        return sha1($encoded);
    }

    private static function aggregateRiskLevel(array $toolCalls): string
    {
        $highest = 'none';
        foreach ($toolCalls as $toolCall) {
            $candidate = self::normalizeRiskLevel($toolCall['risk_level'] ?? null) ?? 'write';
            if (self::riskWeight($candidate) > self::riskWeight($highest)) {
                $highest = $candidate;
            }
        }

        return $highest;
    }

    private static function resolveToolName(array $toolCall): ?string
    {
        $raw = $toolCall['tool_name']
            ?? $toolCall['name']
            ?? ($toolCall['function']['name'] ?? null)
            ?? ($toolCall['functionCall']['name'] ?? null);

        return self::normalizeText($raw, 100);
    }

    private static function extractToolArguments(array $toolCall): array
    {
        $candidates = [
            $toolCall['arguments'] ?? null,
            $toolCall['args'] ?? null,
            $toolCall['input'] ?? null,
            $toolCall['payload'] ?? null,
            $toolCall['function']['arguments'] ?? null,
            $toolCall['functionCall']['args'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }

            if (is_string($candidate) && trim($candidate) !== '') {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    private static function resolveRiskLevel(array $toolCall, string $toolName, array $arguments): string
    {
        $explicit = self::normalizeRiskLevel(
            $toolCall['risk_level']
                ?? $toolCall['risk']
                ?? $toolCall['permission']
                ?? ($arguments['risk_level'] ?? null)
        );
        if ($explicit !== null) {
            return $explicit;
        }

        if (filter_var($toolCall['read_only'] ?? ($arguments['read_only'] ?? null), FILTER_VALIDATE_BOOLEAN)) {
            return 'read';
        }

        if (filter_var($toolCall['is_write'] ?? ($toolCall['mutates_state'] ?? ($arguments['is_write'] ?? ($arguments['mutates_state'] ?? null))), FILTER_VALIDATE_BOOLEAN)) {
            return 'write';
        }

        $method = self::normalizeHttpMethod($toolCall['method'] ?? ($arguments['method'] ?? null));
        if ($method !== null) {
            return match ($method) {
                'GET', 'HEAD' => 'read',
                'DELETE' => 'destructive',
                'POST', 'PUT', 'PATCH' => 'write',
                default => 'write',
            };
        }

        $action = strtolower($toolName);
        foreach (self::DESTRUCTIVE_HINTS as $hint) {
            if (str_contains($action, $hint)) {
                return 'destructive';
            }
        }
        foreach (self::READ_HINTS as $hint) {
            if (str_contains($action, $hint)) {
                return 'read';
            }
        }
        foreach (self::WRITE_HINTS as $hint) {
            if (str_contains($action, $hint)) {
                return 'write';
            }
        }

        return 'write';
    }

    private static function buildArgumentsPreview(array $arguments): array
    {
        $preview = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $preview[$key] = self::previewScalar($value);
                continue;
            }

            if (is_array($value)) {
                $preview[$key] = '[array:' . count($value) . ']';
                continue;
            }

            $preview[$key] = '[object]';
        }

        if (count($preview) > 12) {
            $preview = array_slice($preview, 0, 12, true);
            $preview['_truncated'] = true;
        }

        return $preview;
    }

    private static function sanitizeArgumentsForRuntime(array $arguments, int $depth = 0): array
    {
        if ($depth >= 3) {
            return [];
        }

        $sanitized = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArgumentsForRuntime($value, $depth + 1);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $sanitized[$key] = $value;
                continue;
            }

            $text = trim((string)$value);
            if ($text === '') {
                $sanitized[$key] = '';
                continue;
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                $sanitized[$key] = mb_strlen($text) > 1000
                    ? rtrim(mb_substr($text, 0, 997)) . '...'
                    : $text;
                continue;
            }

            $sanitized[$key] = strlen($text) > 1000
                ? rtrim(substr($text, 0, 997)) . '...'
                : $text;
        }

        return $sanitized;
    }

    private static function previewScalar(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > 120
                ? rtrim(mb_substr($text, 0, 117)) . '...'
                : $text;
        }

        return strlen($text) > 120
            ? rtrim(substr($text, 0, 117)) . '...'
            : $text;
    }

    private static function normalizeApprovalModeOrNull(mixed $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['manual_confirm', 'confirm_write', 'auto_read_only'], true)
            ? $normalized
            : null;
    }

    private static function normalizeRiskLevel(mixed $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, self::RISK_LEVELS, true) ? $normalized : null;
    }

    private static function riskWeight(string $riskLevel): int
    {
        return match ($riskLevel) {
            'read' => 1,
            'write' => 2,
            'destructive' => 3,
            default => 0,
        };
    }

    private static function normalizeText(mixed $value, int $maxLength): ?string
    {
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($normalized) > $maxLength
                ? trim(mb_substr($normalized, 0, $maxLength))
                : $normalized;
        }

        return strlen($normalized) > $maxLength
            ? trim(substr($normalized, 0, $maxLength))
            : $normalized;
    }

    private static function normalizeHttpMethod(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string)$value));
        return $normalized !== '' ? $normalized : null;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int)$value;
        return $normalized > 0 ? $normalized : null;
    }
}
