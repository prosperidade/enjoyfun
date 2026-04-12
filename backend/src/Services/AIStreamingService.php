<?php
/**
 * AIStreamingService.php
 * BE-S6-B1: Server-Sent Events (SSE) streaming for AI chat responses.
 * Emits events: token, tool_start, tool_end, block, done, error.
 * Gated by FEATURE_AI_SSE_STREAMING.
 */

namespace EnjoyFun\Services;

final class AIStreamingService
{
    /** Initialize SSE headers. Must be called before any emit. */
    public static function initSSE(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // nginx
        if (function_exists('set_time_limit')) {
            set_time_limit(300); // 5 min max
        }
        ob_implicit_flush(true);
        if (ob_get_level()) { ob_end_flush(); }
    }

    /** Emit a single SSE event. */
    public static function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('fastcgi_finish_request')) { return; } // FPM buffers differently
        flush();
    }

    /** Token-by-token text streaming. */
    public static function emitToken(string $token): void
    {
        self::emit('token', ['text' => $token]);
    }

    /** Tool execution started. */
    public static function emitToolStart(string $toolName): void
    {
        self::emit('tool_start', ['tool' => $toolName, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /** Tool execution ended. */
    public static function emitToolEnd(string $toolName, bool $ok, int $durationMs = 0): void
    {
        self::emit('tool_end', ['tool' => $toolName, 'ok' => $ok, 'duration_ms' => $durationMs]);
    }

    /** Adaptive UI block ready. */
    public static function emitBlock(array $block): void
    {
        self::emit('block', $block);
    }

    /** Stream complete. */
    public static function emitDone(array $meta = []): void
    {
        self::emit('done', array_merge(['timestamp' => gmdate('Y-m-d\TH:i:s\Z')], $meta));
    }

    /** Error during stream. */
    public static function emitError(string $message, int $code = 500): void
    {
        self::emit('error', ['message' => $message, 'code' => $code]);
    }

    /** Check if SSE streaming is enabled. */
    public static function isEnabled(): bool
    {
        require_once __DIR__ . '/../../config/features.php';
        return class_exists('Features') && \Features::enabled('FEATURE_AI_SSE_STREAMING');
    }
}
