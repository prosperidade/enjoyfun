<?php
/**
 * AIEmbeddingService.php
 * BE-S5-A3: Pipeline parse → chunk → embedding → INSERT document_embeddings.
 * Gated by FEATURE_AI_PGVECTOR.
 */

namespace EnjoyFun\Services;

use PDO;

final class AIEmbeddingService
{
    private const CHUNK_SIZE = 500;
    private const CHUNK_OVERLAP = 100;
    private const EMBEDDING_MODEL = 'text-embedding-3-small';
    private const EMBEDDING_DIMENSIONS = 1536;

    /**
     * Generate embeddings for a parsed organizer file.
     * Fire-and-forget: logs errors but does not throw.
     */
    public static function generateEmbeddings(PDO $db, int $organizerId, int $fileId): bool
    {
        require_once __DIR__ . '/../../config/features.php';
        if (!class_exists('Features') || !\Features::enabled('FEATURE_AI_PGVECTOR')) {
            return false;
        }

        try {
            // 1. Load parsed data
            $stmt = $db->prepare("SELECT parsed_data FROM public.organizer_files WHERE id = :id AND organizer_id = :org AND parsed_status = 'parsed'");
            $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
            $rawData = $stmt->fetchColumn();
            if (!$rawData) { return false; }

            $parsed = json_decode($rawData, true);
            $text = self::extractText($parsed);
            if (trim($text) === '') { return false; }

            // 2. Chunk text
            $chunks = self::chunkText($text);
            if (empty($chunks)) { return false; }

            // 3. Generate embeddings via OpenAI
            $apiKey = trim((string)getenv('OPENAI_API_KEY'));
            if ($apiKey === '') {
                error_log('[AIEmbeddingService] OPENAI_API_KEY not configured');
                return false;
            }

            $embeddings = self::callEmbeddingsApi($apiKey, $chunks);
            if ($embeddings === null) { return false; }

            // 4. Delete old embeddings for this file
            $db->prepare("DELETE FROM public.document_embeddings WHERE file_id = :fid AND organizer_id = :org")
               ->execute([':fid' => $fileId, ':org' => $organizerId]);

            // 5. Insert new embeddings
            $insertStmt = $db->prepare("
                INSERT INTO public.document_embeddings (organizer_id, file_id, chunk_index, chunk_text, embedding)
                VALUES (:org, :fid, :idx, :text, :emb)
            ");

            foreach ($embeddings as $i => $emb) {
                $vectorStr = '[' . implode(',', $emb) . ']';
                $insertStmt->execute([
                    ':org'  => $organizerId,
                    ':fid'  => $fileId,
                    ':idx'  => $i,
                    ':text' => $chunks[$i],
                    ':emb'  => $vectorStr,
                ]);
            }

            error_log("[AIEmbeddingService] Generated " . count($embeddings) . " embeddings for file {$fileId}");
            return true;

        } catch (\Throwable $e) {
            error_log('[AIEmbeddingService] Error: ' . $e->getMessage());
            return false;
        }
    }

    /** Extract text from parsed_data (CSV rows → joined, JSON → json_encode). */
    private static function extractText(array $parsed): string
    {
        $rows = $parsed['rows'] ?? $parsed['data'] ?? null;
        if (is_array($rows)) {
            $lines = [];
            $headers = $parsed['headers'] ?? $parsed['keys'] ?? [];
            if (!empty($headers)) {
                $lines[] = implode(' | ', $headers);
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $lines[] = implode(' | ', array_map('strval', $row));
                } elseif (is_string($row)) {
                    $lines[] = $row;
                }
            }
            return implode("\n", $lines);
        }
        return json_encode($parsed, JSON_UNESCAPED_UNICODE);
    }

    /** Split text into overlapping chunks. */
    private static function chunkText(string $text): array
    {
        $chunks = [];
        $len = strlen($text);
        $pos = 0;
        while ($pos < $len) {
            $chunk = substr($text, $pos, self::CHUNK_SIZE);
            if (trim($chunk) !== '') {
                $chunks[] = trim($chunk);
            }
            $pos += self::CHUNK_SIZE - self::CHUNK_OVERLAP;
        }
        return $chunks;
    }

    /** Call OpenAI embeddings API. Returns array of float arrays, or null on error. */
    private static function callEmbeddingsApi(string $apiKey, array $texts): ?array
    {
        $payload = json_encode([
            'model' => self::EMBEDDING_MODEL,
            'input' => $texts,
            'dimensions' => self::EMBEDDING_DIMENSIONS,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("[AIEmbeddingService] OpenAI API error HTTP {$httpCode}");
            return null;
        }

        $decoded = json_decode($response, true);
        $data = $decoded['data'] ?? [];
        if (empty($data)) { return null; }

        // Sort by index and extract embedding arrays
        usort($data, fn($a, $b) => ($a['index'] ?? 0) - ($b['index'] ?? 0));
        return array_map(fn($d) => $d['embedding'] ?? [], $data);
    }

    /** Generate embedding for a single query string. */
    public static function embedQuery(string $apiKey, string $query): ?array
    {
        $result = self::callEmbeddingsApi($apiKey, [$query]);
        return $result[0] ?? null;
    }
}
