<?php
/**
 * Super Admin Controller — EnjoyFun 2.0 (White Label)
 */

require_once BASE_PATH . '/src/Services/AIBillingService.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    // Proteção: Somente admin entra aqui
    $user = requireAuth(['admin']);

    match (true) {
        $method === 'GET'  && $id === 'billing' && $sub === 'stats' => getGlobalBillingStats($user),
        $method === 'POST' && $id === 'organizers' => createOrganizer($body),
        $method === 'GET'  && $id === 'organizers' => listOrganizers(),
        default => jsonError("Super Admin: Endpoint não encontrado", 404)
    };
}

function getGlobalBillingStats(array $user): void
{
    try {
        $db = Database::getInstance();
        $payload = \EnjoyFun\Services\AIBillingService::getBillingStats($db);

        if (class_exists('AuditService')) {
            AuditService::log(
                'admin.billing.global_view',
                'ai_usage_logs',
                null,
                null,
                null,
                $user,
                'success',
                [
                    'metadata' => [
                        'scope' => 'global',
                        'route' => '/superadmin/billing/stats',
                    ],
                ]
            );
        }

        jsonSuccess($payload);
    } catch (Exception $e) {
        if (str_contains(strtolower($e->getMessage()), 'relation "ai_usage_logs" does not exist')) {
            if (class_exists('AuditService')) {
                AuditService::log(
                    'admin.billing.global_view',
                    'ai_usage_logs',
                    null,
                    null,
                    null,
                    $user,
                    'success',
                    [
                        'metadata' => [
                            'scope' => 'global',
                            'route' => '/superadmin/billing/stats',
                            'ai_usage_logs_missing' => true,
                        ],
                    ]
                );
            }

            jsonSuccess(\EnjoyFun\Services\AIBillingService::emptyBillingStats());
        }

        $ref = uniqid();
        error_log("[SuperAdmin Billing] Error fetching billing stats (Ref: {$ref}) - " . $e->getMessage());
        jsonError("Erro interno ao carregar estatísticas globais de uso (Ref: {$ref})", 500);
    }
}

function createOrganizer(array $body): void
{
    $name = trim($body['name'] ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$name || !$email || !$password) {
        jsonError("Todos os campos são obrigatórios.", 422);
    }

    $db = null;
    $ownTransaction = false; // Initialize $ownTransaction
    try {
        $db = Database::getInstance();
        // Se já não estivermos numa transação, criamos uma nova (permite reutilizar do Controller)
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $ownTransaction = true;
        }

        // 1. Verifica se já existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("Este e-mail já está em uso.");
        }

        // 2. Insere na tabela users usando a nova coluna 'role'
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmtInsert = $db->prepare("
            INSERT INTO users (name, email, password, role, created_at) 
            VALUES (?, ?, ?, 'organizer', NOW()) RETURNING id
        ");
        $stmtInsert->execute([$name, $email, $hashedPassword]);
        $newId = $stmtInsert->fetchColumn();

        // 3. Define o organizer_id como o próprio ID dele (Isolamento)
        $db->prepare("UPDATE users SET organizer_id = ? WHERE id = ?")
           ->execute([$newId, $newId]);

        $db->commit();
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        jsonError("Erro ao criar: " . $e->getMessage(), 500);
    }

    jsonSuccess(['id' => $newId], "Organizador criado com sucesso!", 201);
}

function listOrganizers(): void
{
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, name, email, created_at 
            FROM users 
            WHERE role = 'organizer' AND organizer_id = id 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        
        jsonSuccess(['organizers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        jsonError("Erro ao listar: " . $e->getMessage(), 500);
    }
}
