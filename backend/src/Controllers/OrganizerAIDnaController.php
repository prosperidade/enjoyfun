<?php
/**
 * Organizer AI DNA Controller
 * DNA do negocio do organizador injetado no system prompt dos agentes IA.
 */

function organizerAIDnaDispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    match (true) {
        $method === 'GET' && $id === null => organizerAIDnaGet(),
        $method === 'PUT' && $id === null => organizerAIDnaUpdate($body),
        default => jsonError('Organizer AI DNA endpoint nao encontrado.', 404),
    };
}

if (!function_exists('dispatch')) {
    function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
    {
        organizerAIDnaDispatch($method, $id, $sub, $subId, $body, $query);
    }
}

function organizerAIDnaFields(): array
{
    return [
        'business_description',
        'tone_of_voice',
        'business_rules',
        'target_audience',
        'forbidden_topics',
    ];
}

function organizerAIDnaEmptyRow(int $organizerId): array
{
    $row = ['organizer_id' => $organizerId];
    foreach (organizerAIDnaFields() as $field) {
        $row[$field] = null;
    }
    $row['updated_at'] = null;
    $row['created_at'] = null;
    return $row;
}

function organizerAIDnaResolveOrganizerId(array $user): int
{
    if (($user['role'] ?? '') === 'admin') {
        return (int)($user['organizer_id'] ?? $user['id'] ?? 0);
    }
    return (int)($user['organizer_id'] ?? 0);
}

function organizerAIDnaEnsureTable(PDO $db): void
{
    static $cached = null;
    if ($cached === true) return;

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = 'organizer_ai_dna'
        LIMIT 1
    ");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        jsonError(
            'Readiness de ambiente invalida: tabela `organizer_ai_dna` ausente. Aplique a migration 065 antes de usar o DNA do negocio.',
            409
        );
    }
    $cached = true;
}

function organizerAIDnaGet(): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    organizerAIDnaEnsureTable($db);

    $organizerId = organizerAIDnaResolveOrganizerId($user);
    if ($organizerId <= 0) {
        jsonError('organizer_id ausente no contexto de autenticacao.', 403);
    }

    $stmt = $db->prepare('
        SELECT organizer_id, business_description, tone_of_voice, business_rules,
               target_audience, forbidden_topics, updated_at, created_at
        FROM organizer_ai_dna
        WHERE organizer_id = ?
        LIMIT 1
    ');
    $stmt->execute([$organizerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $row = organizerAIDnaEmptyRow($organizerId);
    }

    jsonSuccess($row);
}

function organizerAIDnaUpdate(array $body): void
{
    $user = requireAuth(['admin', 'organizer']);
    $db = Database::getInstance();

    organizerAIDnaEnsureTable($db);

    $organizerId = organizerAIDnaResolveOrganizerId($user);
    if ($organizerId <= 0) {
        jsonError('organizer_id ausente no contexto de autenticacao.', 403);
    }

    $maxLen = 4000;
    $values = [];
    foreach (organizerAIDnaFields() as $field) {
        $raw = $body[$field] ?? null;
        if ($raw === null || $raw === '') {
            $values[$field] = null;
            continue;
        }

        if (!is_string($raw)) {
            jsonError("Campo `{$field}` deve ser texto.", 422);
        }

        $clean = trim($raw);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean);

        if (strlen($clean) > $maxLen) {
            jsonError("Campo `{$field}` excede {$maxLen} caracteres.", 422);
        }

        $values[$field] = $clean !== '' ? $clean : null;
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO organizer_ai_dna
                (organizer_id, business_description, tone_of_voice, business_rules,
                 target_audience, forbidden_topics, updated_at, created_at)
            VALUES
                (:organizer_id, :business_description, :tone_of_voice, :business_rules,
                 :target_audience, :forbidden_topics, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (organizer_id)
            DO UPDATE SET
                business_description = EXCLUDED.business_description,
                tone_of_voice        = EXCLUDED.tone_of_voice,
                business_rules       = EXCLUDED.business_rules,
                target_audience      = EXCLUDED.target_audience,
                forbidden_topics     = EXCLUDED.forbidden_topics,
                updated_at           = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':organizer_id'         => $organizerId,
            ':business_description' => $values['business_description'],
            ':tone_of_voice'        => $values['tone_of_voice'],
            ':business_rules'       => $values['business_rules'],
            ':target_audience'      => $values['target_audience'],
            ':forbidden_topics'     => $values['forbidden_topics'],
        ]);

        $select = $db->prepare('
            SELECT organizer_id, business_description, tone_of_voice, business_rules,
                   target_audience, forbidden_topics, updated_at, created_at
            FROM organizer_ai_dna
            WHERE organizer_id = ?
            LIMIT 1
        ');
        $select->execute([$organizerId]);
        $row = $select->fetch(PDO::FETCH_ASSOC) ?: organizerAIDnaEmptyRow($organizerId);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonError('Falha ao salvar DNA do negocio: ' . $e->getMessage(), 500);
        return;
    }

    jsonSuccess($row, 'DNA do negocio salvo com sucesso.');
}
