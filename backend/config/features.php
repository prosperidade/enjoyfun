<?php
/**
 * EnjoyFun EMAS — Feature Flags Registry
 *
 * Fonte única de verdade para as 12 flags do plano EMAS (execucaobacklogtripla.md §1.5).
 * Todas default OFF. Liga via .env conforme o cronograma de cada sprint.
 *
 * Uso:
 *   require_once __DIR__ . '/../config/features.php';
 *   if (Features::enabled('FEATURE_AI_EMBEDDED_V3')) { ... }
 */
class Features
{
    public const FLAGS = [
        'FEATURE_AI_EMBEDDED_V3'   => false,
        'FEATURE_AI_LAZY_CONTEXT'  => false,
        'FEATURE_AI_PT_BR_LABELS'  => false,
        'FEATURE_AI_PLATFORM_GUIDE'=> false,
        'FEATURE_AI_RAG_PRAGMATIC' => false,
        'FEATURE_AI_MEMORY_RECALL' => false,
        'FEATURE_AI_TOOL_WRITE'    => false,
        'FEATURE_AI_PGVECTOR'      => false,
        'FEATURE_AI_VOICE_PROXY'   => false,
        'FEATURE_AI_MEMPALACE'     => false,
        'FEATURE_AI_SSE_STREAMING' => false,
        'FEATURE_AI_SUPERVISOR'    => false,
    ];

    public static function enabled(string $flag): bool
    {
        if (!array_key_exists($flag, self::FLAGS)) {
            return false;
        }
        $env = getenv($flag);
        if ($env === false || $env === '') {
            return self::FLAGS[$flag];
        }
        $normalized = strtolower(trim((string)$env));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FLAGS) as $flag) {
            $out[$flag] = self::enabled($flag);
        }
        return $out;
    }
}
