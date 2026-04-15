<?php

require_once BASE_PATH . '/src/Middleware/AuthMiddleware.php';
require_once BASE_PATH . '/src/Helpers/Response.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $db = Database::getInstance();

    match (true) {
        $method === 'GET' && $id === 'search' => orgFileSearch($db, $query),
        $method === 'GET' && $id === null => orgFileList($db, $query),
        $method === 'POST' && $id === null => orgFileUpload($db),
        $method === 'GET' && is_numeric($id) && $sub === null => orgFileGet($db, (int)$id),
        $method === 'GET' && is_numeric($id) && $sub === 'parsed' => orgFileGetParsed($db, (int)$id),
        $method === 'GET' && is_numeric($id) && $sub === 'download' => orgFileDownload($db, (int)$id),
        $method === 'POST' && is_numeric($id) && $sub === 'parse' => orgFileReparse($db, (int)$id),
        $method === 'POST' && is_numeric($id) && $sub === 'analyze' => orgFileAnalyzeWithGoogle($db, (int)$id),
        $method === 'DELETE' && is_numeric($id) => orgFileDelete($db, (int)$id),
        default => jsonError('Endpoint nao encontrado em organizer-files.', 404),
    };
}

function orgFileList(PDO $db, array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    $pagination = enjoyNormalizePagination($query, 20, 100);

    $conditions = ['organizer_id = :org'];
    $params = [':org' => $organizerId];

    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId > 0) {
        $conditions[] = 'event_id = :evt';
        $params[':evt'] = $eventId;
    }

    $category = strtolower(trim((string)($query['category'] ?? '')));
    if ($category !== '') {
        $conditions[] = 'category = :cat';
        $params[':cat'] = $category;
    }

    $where = implode(' AND ', $conditions);
    $countStmt = $db->prepare("
        SELECT COUNT(*)
        FROM public.organizer_files
        WHERE {$where}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT id, event_id, category, file_type, original_name, mime_type, file_size_bytes,
               parsed_status, parsed_at, notes, uploaded_by_user_id, created_at,
               embedding_status, google_file_uri
        FROM public.organizer_files
        WHERE {$where}
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    enjoyBindPagination($stmt, $pagination);
    $stmt->execute();

    jsonPaginated(
        $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        $total,
        $pagination['page'],
        $pagination['per_page']
    );
}

/**
 * BE-S3-B1: Full-text search across organizer files (name, notes, parsed_data).
 * GET /organizer-files/search?q=keyword&category=financial
 */
function orgFileSearch(PDO $db, array $query): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    if ($organizerId <= 0) { jsonError('Organizer invalido.', 403); }

    $q = trim((string)($query['q'] ?? ''));
    if ($q === '') { jsonError('Parametro q (keyword) e obrigatorio.', 422); }

    $category = strtolower(trim((string)($query['category'] ?? '')));
    $limit = min(max((int)($query['limit'] ?? 20), 1), 50);

    $where = ["f.organizer_id = :org", "f.parsed_status = 'parsed'"];
    $params = [':org' => $organizerId];

    if ($category !== '') {
        $where[] = 'f.category = :cat';
        $params[':cat'] = $category;
    }

    $eventId = (int)($query['event_id'] ?? 0);
    if ($eventId > 0) {
        $where[] = '(f.event_id = :evt OR f.event_id IS NULL)';
        $params[':evt'] = $eventId;
    }

    // Search in name, notes, AND parsed_data content (cast jsonb to text)
    $kw = '%' . strtolower($q) . '%';
    $where[] = '(LOWER(f.original_name) LIKE :kw OR LOWER(COALESCE(f.notes, \'\')) LIKE :kw OR LOWER(COALESCE(f.parsed_data::text, \'\')) LIKE :kw)';
    $params[':kw'] = $kw;

    $sql = 'SELECT f.id, f.original_name, f.category, f.file_type, f.notes, f.created_at
            FROM public.organizer_files f
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY f.updated_at DESC NULLS LAST
            LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    jsonSuccess([
        'query' => $q,
        'category_filter' => $category ?: 'all',
        'total_matches' => count($files),
        'files' => $files,
    ]);
}

function orgFileUpload(PDO $db): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
    $userId = (int)($operator['id'] ?? $operator['sub'] ?? 0);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Arquivo nao recebido ou com erro de upload.', 422);
    }

    $file = $_FILES['file'];
    $originalName = basename($file['name']);
    $mimeType = $file['type'] ?? '';
    $fileSize = (int)($file['size'] ?? 0);

    // Validate size (max 20MB)
    if ($fileSize > 20 * 1024 * 1024) {
        jsonError('Arquivo excede o limite de 20MB.', 422);
    }

    // Validate MIME
    $allowedMimes = [
        'text/csv', 'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/pdf',
        'application/json',
        'image/jpeg', 'image/png', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if ($mimeType !== '' && !in_array($mimeType, $allowedMimes, true)) {
        jsonError("Tipo de arquivo nao permitido: {$mimeType}.", 422);
    }

    // Determine file type and category
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $fileType = match ($ext) {
        'csv' => 'csv',
        'xls', 'xlsx' => 'excel',
        'pdf' => 'pdf',
        'json' => 'json',
        'jpg', 'jpeg', 'png', 'webp' => 'image',
        'doc', 'docx' => 'document',
        default => 'other',
    };

    $category = strtolower(trim((string)($_POST['category'] ?? 'general')));
    $validCategories = ['general', 'financial', 'contracts', 'logistics', 'marketing', 'operational', 'reports', 'spreadsheets'];
    if (!in_array($category, $validCategories, true)) {
        $category = 'general';
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    // Save file
    $uploadDir = BASE_PATH . '/public/uploads/organizer_files/' . $organizerId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $safeFilename = sprintf('org_%d_%s_%s.%s', $organizerId, date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
    $storagePath = '/uploads/organizer_files/' . $organizerId . '/' . $safeFilename;
    $fullPath = $uploadDir . '/' . $safeFilename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        jsonError('Falha ao salvar o arquivo.', 500);
    }

    // Insert record
    $stmt = $db->prepare("
        INSERT INTO public.organizer_files (organizer_id, event_id, category, file_type, original_name, storage_path, mime_type, file_size_bytes, notes, uploaded_by_user_id, parsed_status)
        VALUES (:org, :evt, :cat, :ft, :name, :path, :mime, :size, :notes, :uid, 'pending')
        RETURNING id
    ");
    $stmt->execute([
        ':org' => $organizerId,
        ':evt' => $eventId > 0 ? $eventId : null,
        ':cat' => $category,
        ':ft' => $fileType,
        ':name' => $originalName,
        ':path' => $storagePath,
        ':mime' => $mimeType,
        ':size' => $fileSize,
        ':notes' => $notes !== '' ? $notes : null,
        ':uid' => $userId > 0 ? $userId : null,
    ]);
    $fileId = (int)$stmt->fetchColumn();

    // Auto-parse CSV and JSON
    if (in_array($fileType, ['csv', 'json'], true)) {
        orgFileAutoparse($db, $fileId, $fullPath, $fileType, $organizerId);
    }

    // Reload record
    $stmt = $db->prepare("SELECT id, event_id, category, file_type, original_name, mime_type, file_size_bytes, parsed_status, parsed_at, notes, created_at, embedding_status, google_file_uri FROM public.organizer_files WHERE id = :id AND organizer_id = :org LIMIT 1");
    $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonSuccess($record, 'Arquivo enviado com sucesso.', 201);
}

function orgFileGet(PDO $db, int $fileId): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("
        SELECT id, event_id, category, file_type, original_name, storage_path, mime_type, file_size_bytes,
               parsed_status, parsed_at, parsed_error, notes, uploaded_by_user_id, created_at, updated_at
        FROM public.organizer_files
        WHERE id = :id AND organizer_id = :org
        LIMIT 1
    ");
    $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        jsonError('Arquivo nao encontrado.', 404);
    }

    jsonSuccess($file);
}

function orgFileGetParsed(PDO $db, int $fileId): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("
        SELECT id, original_name, file_type, parsed_status, parsed_data, parsed_at, parsed_error
        FROM public.organizer_files
        WHERE id = :id AND organizer_id = :org
        LIMIT 1
    ");
    $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        jsonError('Arquivo nao encontrado.', 404);
    }

    $parsedData = is_string($file['parsed_data'] ?? null) ? json_decode($file['parsed_data'], true) : ($file['parsed_data'] ?? null);
    $file['parsed_data'] = $parsedData;

    jsonSuccess($file);
}

function orgFileReparse(PDO $db, int $fileId): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("SELECT storage_path, file_type FROM public.organizer_files WHERE id = :id AND organizer_id = :org LIMIT 1");
    $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        jsonError('Arquivo nao encontrado.', 404);
    }

    $fullPath = BASE_PATH . '/public' . $file['storage_path'];
    if (!file_exists($fullPath)) {
        jsonError('Arquivo fisico nao encontrado no servidor.', 404);
    }

    orgFileAutoparse($db, $fileId, $fullPath, $file['file_type'], $organizerId);

    $stmt = $db->prepare("SELECT parsed_status, parsed_at, parsed_error FROM public.organizer_files WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $fileId]);

    jsonSuccess($stmt->fetch(PDO::FETCH_ASSOC), 'Re-parsing concluido.');
}

function orgFileDownload(PDO $db, int $fileId): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("
        SELECT storage_path, mime_type, original_name, file_size_bytes
        FROM public.organizer_files
        WHERE id = :id AND organizer_id = :org
        LIMIT 1
    ");
    $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        jsonError('Arquivo nao encontrado.', 404);
    }

    $fullPath = BASE_PATH . '/public' . ($file['storage_path'] ?? '');
    if (!file_exists($fullPath)) {
        jsonError('Arquivo fisico nao encontrado no servidor.', 404);
    }

    $mime = $file['mime_type'] ?: 'application/octet-stream';
    $name = $file['original_name'] ?: 'download';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, max-age=3600');
    readfile($fullPath);
    exit;
}

function orgFileDelete(PDO $db, int $fileId): void
{
    $operator = requireAuth(['admin', 'organizer']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("SELECT storage_path FROM public.organizer_files WHERE id = :id AND organizer_id = :org LIMIT 1");
    $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        jsonError('Arquivo nao encontrado.', 404);
    }

    // Delete physical file
    $fullPath = BASE_PATH . '/public' . ($file['storage_path'] ?? '');
    if ($fullPath !== '' && file_exists($fullPath)) {
        @unlink($fullPath);
    }

    $db->prepare("DELETE FROM public.organizer_files WHERE id = :id AND organizer_id = :org")->execute([':id' => $fileId, ':org' => $organizerId]);

    jsonSuccess(null, 'Arquivo removido.');
}

/**
 * POST /organizer-files/{id}/analyze
 * Triggers Google Gemini Long Context analysis for large/complex files (PDF, DOCX, etc.).
 * Uploads to Gemini File API (48h retention) and stores the URI for downstream RAG.
 */
function orgFileAnalyzeWithGoogle(PDO $db, int $fileId): void
{
    $operator = requireAuth(['admin', 'organizer', 'manager']);
    $organizerId = (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);

    $stmt = $db->prepare("SELECT storage_path, file_type, mime_type, file_size_bytes, original_name FROM public.organizer_files WHERE id = :id AND organizer_id = :org LIMIT 1");
    $stmt->execute([':id' => $fileId, ':org' => $organizerId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        jsonError('Arquivo nao encontrado.', 404);
    }

    $fullPath = BASE_PATH . '/public' . $file['storage_path'];
    if (!file_exists($fullPath)) {
        jsonError('Arquivo fisico nao encontrado no servidor.', 404);
    }

    $apiKey = trim((string)getenv('GEMINI_API_KEY'));
    if ($apiKey === '') {
        jsonError('GEMINI_API_KEY nao configurada. Analise Google indisponivel.', 503);
    }

    // Update status to indexing
    $db->prepare("UPDATE public.organizer_files SET embedding_status = 'indexing', updated_at = NOW() WHERE id = :id AND organizer_id = :org")
       ->execute([':id' => $fileId, ':org' => $organizerId]);

    try {
        require_once BASE_PATH . '/src/Services/GeminiService.php';

        $mimeType = $file['mime_type'] ?: 'application/octet-stream';
        $uploaded = \EnjoyFun\Services\GeminiService::uploadFile($fullPath, $mimeType, $file['original_name']);

        if (!$uploaded || empty($uploaded['uri'])) {
            $db->prepare("UPDATE public.organizer_files SET embedding_status = 'failed', updated_at = NOW() WHERE id = :id")
               ->execute([':id' => $fileId]);
            jsonError('Falha no upload para Google File API.', 502);
        }

        $fileUri = $uploaded['uri'];
        $sha256 = hash_file('sha256', $fullPath);

        $db->prepare("UPDATE public.organizer_files SET google_file_uri = :uri, google_file_sha256 = :sha, embedding_status = 'indexed', updated_at = NOW() WHERE id = :id AND organizer_id = :org")
           ->execute([':uri' => $fileUri, ':sha' => $sha256, ':id' => $fileId, ':org' => $organizerId]);

        jsonSuccess([
            'file_id' => $fileId,
            'google_file_uri' => $fileUri,
            'embedding_status' => 'indexed',
        ], 'Arquivo enviado para analise Google com sucesso.');

    } catch (\Throwable $e) {
        $db->prepare("UPDATE public.organizer_files SET embedding_status = 'failed', updated_at = NOW() WHERE id = :id")
           ->execute([':id' => $fileId]);
        error_log('[OrganizerFileController] Google analyze error: ' . $e->getMessage());
        jsonError('Erro ao enviar para Google: ' . substr($e->getMessage(), 0, 200), 500);
    }
}

// ──────────────────────────────────────────────────────────────
//  Auto-parse engine (CSV / JSON)
// ──────────────────────────────────────────────────────────────

function orgFileAutoparse(PDO $db, int $fileId, string $fullPath, string $fileType, int $organizerId = 0): void
{
    $db->prepare("UPDATE public.organizer_files SET parsed_status = 'parsing', updated_at = NOW() WHERE id = :id")->execute([':id' => $fileId]);

    try {
        $parsedData = match ($fileType) {
            'csv' => orgFileParseCsv($fullPath),
            'json' => orgFileParseJson($fullPath),
            default => null,
        };

        if ($parsedData === null) {
            $db->prepare("UPDATE public.organizer_files SET parsed_status = 'skipped', parsed_error = 'Tipo de arquivo nao suportado para parsing automatico.', updated_at = NOW() WHERE id = :id")->execute([':id' => $fileId]);
            return;
        }

        $db->prepare("UPDATE public.organizer_files SET parsed_status = 'parsed', parsed_data = :data, parsed_at = NOW(), parsed_error = NULL, updated_at = NOW() WHERE id = :id")
           ->execute([':data' => json_encode($parsedData, JSON_UNESCAPED_UNICODE), ':id' => $fileId]);

        // BE-S5-A4: Trigger embedding generation after successful parse (fire-and-forget)
        try {
            require_once BASE_PATH . '/src/Services/AIEmbeddingService.php';
            \EnjoyFun\Services\AIEmbeddingService::generateEmbeddings($db, $organizerId, $fileId);
        } catch (\Throwable $embErr) {
            error_log('[OrganizerFileController] Embedding generation failed: ' . $embErr->getMessage());
        }

        // BE-S5-A5: Trigger Google File API Upload (for Long Context / Knowledge Base)
        try {
            require_once BASE_PATH . '/src/Services/GeminiService.php';
            $gFile = \EnjoyFun\Services\GeminiService::uploadFile($fullPath, (string)$fileType, (string)basename($fullPath));
            if ($gFile && isset($gFile['uri'])) {
                $db->prepare("UPDATE public.organizer_files SET google_file_uri = :uri, updated_at = NOW() WHERE id = :id")
                   ->execute([':uri' => $gFile['uri'], ':id' => $fileId]);
            }
        } catch (\Throwable $gErr) {
            error_log('[OrganizerFileController] Google File Upload failed: ' . $gErr->getMessage());
        }
    } catch (\Throwable $e) {
        $db->prepare("UPDATE public.organizer_files SET parsed_status = 'failed', parsed_error = :err, updated_at = NOW() WHERE id = :id")
           ->execute([':err' => substr($e->getMessage(), 0, 500), ':id' => $fileId]);
    }
}

function orgFileParseCsv(string $fullPath): array
{
    $handle = fopen($fullPath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Nao foi possivel abrir o arquivo CSV.');
    }

    // Detect delimiter
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false || $headers === [null]) {
        fclose($handle);
        throw new RuntimeException('CSV sem cabecalho valido.');
    }

    // Normalize headers
    $headers = array_map(static function ($h) {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$h) ?? ''));
        return $normalized !== '' ? $normalized : 'col_' . bin2hex(random_bytes(2));
    }, $headers);

    $rows = [];
    $rowNumber = 0;
    $maxRows = 500; // Limit for safety

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $rowNumber < $maxRows) {
        $rowNumber++;
        $rowData = [];
        foreach ($headers as $index => $header) {
            $rowData[$header] = isset($row[$index]) ? trim((string)$row[$index]) : null;
        }
        $rows[] = $rowData;
    }

    fclose($handle);

    // Auto-detect column types
    $columnTypes = [];
    foreach ($headers as $header) {
        $sample = array_filter(array_column($rows, $header), static fn($v) => $v !== null && $v !== '');
        $sample = array_slice(array_values($sample), 0, 10);

        $isNumeric = count($sample) > 0 && count(array_filter($sample, static fn($v) => is_numeric(str_replace([',', 'R$', ' '], ['', '', ''], $v)))) >= count($sample) * 0.7;
        $isDate = count($sample) > 0 && count(array_filter($sample, static fn($v) => strtotime($v) !== false)) >= count($sample) * 0.7;

        $columnTypes[$header] = $isNumeric ? 'numeric' : ($isDate ? 'date' : 'text');
    }

    return [
        'format' => 'csv',
        'delimiter' => $delimiter,
        'headers' => $headers,
        'column_types' => $columnTypes,
        'rows_count' => $rowNumber,
        'rows' => $rows,
        'truncated' => $rowNumber >= $maxRows,
    ];
}

function orgFileParseJson(string $fullPath): array
{
    $content = file_get_contents($fullPath);
    if ($content === false) {
        throw new RuntimeException('Nao foi possivel ler o arquivo JSON.');
    }

    $data = json_decode($content, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('JSON invalido: ' . json_last_error_msg());
    }

    $isArrayOfObjects = is_array($data) && isset($data[0]) && is_array($data[0]);

    return [
        'format' => 'json',
        'is_array' => is_array($data),
        'is_array_of_objects' => $isArrayOfObjects,
        'rows_count' => $isArrayOfObjects ? count($data) : 1,
        'keys' => $isArrayOfObjects ? array_keys($data[0]) : (is_array($data) ? array_keys($data) : []),
        'data' => $isArrayOfObjects ? array_slice($data, 0, 500) : $data,
        'truncated' => $isArrayOfObjects && count($data) > 500,
    ];
}
