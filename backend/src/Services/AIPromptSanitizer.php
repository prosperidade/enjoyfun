<?php
namespace EnjoyFun\Services;

/**
 * AIPromptSanitizer
 *
 * Sanitizes user input before sending to external AI providers.
 * Handles:
 * - Prompt injection prevention (strip HTML, control chars, length limit)
 * - PII scrubbing (CPF, phone, email)
 * - Time filter whitelisting
 */
final class AIPromptSanitizer
{
    private const MAX_QUESTION_LENGTH = 500;

    private const ALLOWED_TIME_FILTERS = [
        'today', 'week', 'month', 'all',
        '1h', '6h', '12h', '24h',
        '7d', '30d',
    ];

    /**
     * Sanitize a user question for AI processing.
     *
     * @throws \InvalidArgumentException if the input is invalid after sanitization
     */
    public static function sanitizeQuestion(string $input): string
    {
        // Strip HTML tags
        $clean = strip_tags($input);

        // Remove control characters (keep newline, tab, carriage return)
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

        // Trim whitespace
        $clean = trim($clean);

        // Enforce max length
        if (mb_strlen($clean) > self::MAX_QUESTION_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_QUESTION_LENGTH);
        }

        // Reject empty input after sanitization
        if ($clean === '') {
            throw new \InvalidArgumentException('A pergunta não pode ser vazia após sanitização.');
        }

        return $clean;
    }

    /**
     * Validate and whitelist a time filter value.
     * Returns a safe default if the input is not in the allowed list.
     */
    public static function sanitizeTimeFilter(string $input): string
    {
        $normalized = strtolower(trim($input));

        if (in_array($normalized, self::ALLOWED_TIME_FILTERS, true)) {
            return $normalized;
        }

        return 'all';
    }

    /**
     * Scrub PII from text before sending to external AI APIs.
     * Replaces Brazilian CPF, phone numbers, and emails with [REDACTED].
     */
    public static function scrubPII(string $text): string
    {
        // CPF: 000.000.000-00 or 00000000000
        $text = preg_replace(
            '/\b\d{3}\.?\d{3}\.?\d{3}[-.]?\d{2}\b/',
            '[REDACTED_CPF]',
            $text
        );

        // Brazilian phone numbers:
        // +55 (11) 91234-5678, (11) 91234-5678, 11912345678, +5511912345678
        $text = preg_replace(
            '/(?:\+?55\s?)?(?:\(?\d{2}\)?\s?)?\d{4,5}[-\s]?\d{4}\b/',
            '[REDACTED_PHONE]',
            $text
        );

        // Email addresses
        $text = preg_replace(
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            '[REDACTED_EMAIL]',
            $text
        );

        return $text;
    }

    /**
     * Scrub PII from data arrays (used for sales/stock data sent to AI).
     * Recursively processes arrays and strings.
     */
    public static function scrubPIIFromData(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::scrubPII($data);
        }

        if (is_array($data)) {
            $scrubbed = [];
            foreach ($data as $key => $value) {
                $scrubbed[$key] = self::scrubPIIFromData($value);
            }
            return $scrubbed;
        }

        return $data;
    }
}
