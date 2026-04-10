<?php
/**
 * EnjoyFun 2.0 — Offline Sync Orchestration Service
 *
 * Extracted from SyncController.
 * Contains the batch processing loop, per-type business logic dispatch,
 * authorization, deduplication, queue audit, and all type-specific processors.
 */
namespace EnjoyFun\Services;

use PDO;
use PDOException;
use Exception;
use Throwable;

class OfflineSyncService
{
    // ─── Batch orchestration ────────────────────────────────────────────

    /**
     * Pre-deduplicate offline IDs in a single query against the offline_queue table.
     *
     * @return array<string, true> Map of already-processed offline_ids.
     */
    public static function preDeduplicateOfflineIds(PDO $db, array $offlineIds): array
    {
        $alreadyProcessed = [];
        if (empty($offlineIds)) {
            return $alreadyProcessed;
        }

        $placeholders = implode(',', array_fill(0, count($offlineIds), '?'));
        $dedup = $db->prepare("SELECT offline_id FROM offline_queue WHERE offline_id IN ({$placeholders})");
        $dedup->execute($offlineIds);
        while ($row = $dedup->fetch(PDO::FETCH_ASSOC)) {
            $alreadyProcessed[$row['offline_id']] = true;
        }

        return $alreadyProcessed;
    }

    /**
     * Process a batch of offline sync items.
     *
     * @param array $items    The raw items from the request body.
     * @param array $actor    The authenticated operator (from JWT).
     * @param PDO   $db       The database connection.
     * @param string $deviceId The device identifier from request header.
     * @return array The batch result with summary and per-item detail.
     */
    public static function processBatch(array $items, array $actor, PDO $db, string $deviceId = 'browser_pos'): array
    {
        // ── Collect offline_ids for batch deduplication ──────────────────
        $allOfflineIds = [];
        foreach ($items as $item) {
            $oid = $item['offline_id'] ?? null;
            if ($oid !== null && $oid !== '') {
                $allOfflineIds[] = (string)$oid;
            }
        }

        $alreadyProcessedIds = self::preDeduplicateOfflineIds($db, $allOfflineIds);

        $processedCount = 0;
        $processedIds = [];
        $processedNewIds = [];
        $deduplicatedIds = [];
        $failedIds = [];
        $errors = [];
        $itemResults = [];

        foreach ($items as $item) {
            $offlineId = $item['offline_id'] ?? null;
            $type      = $item['payload_type'] ?? $item['type'] ?? 'sale';
            $payload   = $item['payload'] ?? $item['data'] ?? [];
            $createdAt = $item['created_offline_at'] ?? $item['created_at'] ?? date('c');

            if (!$offlineId || empty($payload)) {
                error_log("[SyncController] Skipped item with empty offline_id or payload — device={$deviceId}");
                continue;
            }

            // ── Fast-path deduplication (batch pre-check) ───────────────
            if (isset($alreadyProcessedIds[$offlineId])) {
                $processedCount++;
                $processedIds[] = $offlineId;
                $deduplicatedIds[] = $offlineId;
                $itemResults[] = [
                    'offline_id' => $offlineId,
                    'status' => 'duplicate',
                    'error' => null,
                ];
                error_log("[SyncController] Duplicate skipped (batch pre-check) — offline_id={$offlineId} device={$deviceId} type={$type}");
                continue;
            }

            try {
                $db->beginTransaction();

                $payload = OfflineSyncNormalizer::normalize($type, is_array($payload) ? $payload : [], $item);

                self::authorizePayload($db, $actor, $type, $payload);

                // HMAC-SHA256 verification (C07)
                $itemHmac = trim((string)($item['hmac'] ?? ''));
                $isProduction = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') !== 'development';

                if ($itemHmac !== '') {
                    $rawPayload = $item['payload'] ?? $item['data'] ?? [];
                    if (!OfflineHmacService::verify($rawPayload, $itemHmac)) {
                        OfflineHmacService::logRejection($offlineId, $type, $actor);
                        throw new Exception('Assinatura HMAC invalida. Payload rejeitado.', 403);
                    }
                } elseif ($isProduction) {
                    OfflineHmacService::logRejection($offlineId, $type, $actor);
                    throw new Exception('HMAC obrigatorio em producao. Payload sem assinatura rejeitado.', 403);
                } else {
                    error_log("EnjoyFun HMAC Warning — offline_id={$offlineId} type={$type}: HMAC ausente (permitido apenas em dev)");
                }

                // ── Idempotency check with NOWAIT ───────────────────────
                try {
                    $check = $db->prepare('SELECT id FROM offline_queue WHERE offline_id = ? FOR UPDATE NOWAIT');
                    $check->execute([$offlineId]);
                } catch (PDOException $lockEx) {
                    if (str_contains($lockEx->getMessage(), '55P03') || str_contains($lockEx->getMessage(), 'lock_not_available')) {
                        $db->rollBack();
                        $failedIds[] = $offlineId;
                        $errMsg = "Item offline_id={$offlineId} esta sendo processado por outra transacao. Tente novamente.";
                        $errors[] = [
                            'offline_id' => $offlineId,
                            'error'      => $errMsg,
                            'error_code' => 'offline_sync_lock_conflict',
                        ];
                        $itemResults[] = [
                            'offline_id' => $offlineId,
                            'status' => 'error',
                            'error' => $errMsg,
                        ];
                        error_log("[SyncController] Lock conflict (NOWAIT) — offline_id={$offlineId} device={$deviceId} type={$type}");
                        continue;
                    }
                    throw $lockEx;
                }

                if ($check->fetch()) {
                    $db->rollBack();
                    $processedCount++;
                    $processedIds[] = $offlineId;
                    $deduplicatedIds[] = $offlineId;
                    $itemResults[] = [
                        'offline_id' => $offlineId,
                        'status' => 'duplicate',
                        'error' => null,
                    ];
                    error_log("[SyncController] Duplicate skipped (row lock) — offline_id={$offlineId} device={$deviceId} type={$type}");
                    continue;
                }

                $eventId = (int)($payload['event_id'] ?? 0);
                if ($eventId <= 0) {
                    throw new Exception('Evento invalido para sincronizacao offline.', 422);
                }

                self::insertQueueAudit($db, $actor, $eventId, $deviceId, $type, $payload, $offlineId, 'synced', $createdAt);

                // 3. Process business logic based on type
                self::processItemByType($db, $actor, $type, $payload, $offlineId);

                $db->commit();
                $processedCount++;
                $processedIds[] = $offlineId;
                $processedNewIds[] = $offlineId;
                $itemResults[] = [
                    'offline_id' => $offlineId,
                    'status' => 'success',
                    'error' => null,
                ];
                error_log("[SyncController] Processed OK — offline_id={$offlineId} device={$deviceId} type={$type} ts={$createdAt}");
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $failedIds[] = $offlineId;
                $errCode = OfflineSyncNormalizer::resolveErrorCode($e);
                $errors[] = [
                    'offline_id' => $offlineId,
                    'error'      => $e->getMessage(),
                    'error_code' => $errCode,
                ];
                $itemResults[] = [
                    'offline_id' => $offlineId,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                error_log("[SyncController] Error — offline_id={$offlineId} device={$deviceId} type={$type} error={$e->getMessage()}");
            }
        }

        return self::buildBatchResponse($processedCount, $processedIds, $processedNewIds, $deduplicatedIds, $failedIds, $errors, $itemResults);
    }

    /**
     * Build the standardized batch response array.
     */
    public static function buildBatchResponse(
        int $processedCount,
        array $processedIds,
        array $processedNewIds,
        array $deduplicatedIds,
        array $failedIds,
        array $errors,
        array $itemResults
    ): array {
        $summary = [
            'processed' => $processedCount,
            'processed_new' => count($processedNewIds),
            'deduplicated' => count($deduplicatedIds),
            'failed' => count($errors),
            'processed_ids' => $processedIds,
            'processed_new_ids' => $processedNewIds,
            'deduplicated_ids' => $deduplicatedIds,
            'failed_ids' => $failedIds,
            'items' => $itemResults,
        ];

        if (count($errors) > 0) {
            $summary['status'] = 'partial_failure';
            $summary['errors'] = $errors;
        } else {
            $summary['status'] = 'success';
        }

        return $summary;
    }

    // ─── Type dispatcher ────────────────────────────────────────────────

    private static function processItemByType(PDO $db, array $operator, string $type, array $payload, string $offlineId): void
    {
        match ($type) {
            'sale'                  => self::processSale($db, $operator, $payload, $offlineId),
            'meal'                  => self::processMeal($db, $operator, $payload, $offlineId),
            'ticket_validate'       => self::processTicketValidation($db, $operator, $payload),
            'guest_validate'        => self::processGuestValidation($db, $operator, $payload),
            'participant_validate'  => self::processParticipantValidation($db, $operator, $payload),
            'parking_entry'         => self::processParkingEntry($db, $operator, $payload),
            'parking_exit'          => self::processParkingExit($db, $operator, $payload),
            'parking_validate'      => self::processParkingValidation($db, $operator, $payload),
            'topup'                 => self::processTopup($db, $operator, $payload, $offlineId),
            default                 => throw new Exception("Tipo de payload offline nao suportado: {$type}.", 422),
        };
    }

    // ─── Type-specific processors ───────────────────────────────────────

    private static function processSale(PDO $db, array $operator, array $payload, string $offlineId): void
    {
        $eventId = (int)($payload['event_id'] ?? 0);
        $total = (float)($payload['total_amount'] ?? 0);
        $items = $payload['items'] ?? [];
        $sector = strtolower(trim((string)($payload['sector'] ?? 'bar')));
        $cardId = trim((string)($payload['card_id'] ?? ''));

        if ($eventId <= 0) {
            throw new Exception('Evento invalido para sincronizacao offline.', 422);
        }
        if (!in_array($sector, ['bar', 'food', 'shop'], true)) {
            throw new Exception('Setor invalido para sincronizacao offline.', 422);
        }
        if (!is_array($items) || empty($items)) {
            throw new Exception('Nenhum item encontrado para sincronizacao offline.', 422);
        }
        if ($cardId === '') {
            throw new Exception('Registro offline sem card_id canonico.', 422);
        }
        if (!\WalletSecurityService::isCanonicalCardId($cardId)) {
            throw new Exception('Registro offline sem card_id canonico. Revalide o cartao antes de sincronizar.', 422);
        }

        \EnjoyFun\Services\SalesDomainService::processCheckout(
            $db,
            $operator,
            $eventId,
            $items,
            $sector,
            $total,
            $cardId,
            [
                'offline_id' => $offlineId,
                'is_offline' => true,
            ]
        );
    }

    private static function processMeal(PDO $db, array $operator, array $payload, string $offlineId): void
    {
        $organizerId = (int)(($operator['role'] ?? '') === 'admin'
            ? ($operator['organizer_id'] ?? $operator['id'] ?? 0)
            : ($operator['organizer_id'] ?? 0));

        if ($organizerId <= 0) {
            throw new Exception('Organizador invalido para sincronizacao offline de refeicoes.', 422);
        }

        \EnjoyFun\Services\MealsDomainService::registerOperationalMealByReference(
            $db,
            $organizerId,
            isset($payload['participant_id']) ? (int)$payload['participant_id'] : null,
            $payload['qr_token'] ?? null,
            isset($payload['event_day_id']) && (int)$payload['event_day_id'] > 0 ? (int)$payload['event_day_id'] : null,
            isset($payload['event_shift_id']) && (int)$payload['event_shift_id'] > 0 ? (int)$payload['event_shift_id'] : null,
            $payload['sector'] ?? null,
            isset($payload['meal_service_id']) && (int)$payload['meal_service_id'] > 0 ? (int)$payload['meal_service_id'] : null,
            $payload['meal_service_code'] ?? null,
            $offlineId,
            $payload['consumed_at'] ?? null,
            $payload['operational_timezone'] ?? null
        );
    }

    private static function processTicketValidation(PDO $db, array $operator, array $payload): void
    {
        $organizerId = self::resolveOrganizerId($operator);
        if ($organizerId <= 0) {
            throw new Exception('Organizador invalido para sincronizacao offline de ingressos.', 403);
        }

        $eventId = (int)($payload['event_id'] ?? 0);
        $receivedToken = OfflineSyncNormalizer::normalizeScannedToken((string)($payload['token'] ?? ''));
        if ($eventId <= 0 || $receivedToken === '') {
            throw new Exception('Token e evento sao obrigatorios para sincronizacao offline de ingressos.', 422);
        }

        $tokenParts = explode('.', $receivedToken);
        $otpCode = null;
        $qrToken = $receivedToken;

        if (count($tokenParts) === 2 && ctype_digit((string)($tokenParts[1] ?? ''))) {
            $qrToken = $tokenParts[0];
            $otpCode = $tokenParts[1];
        }

        $stmt = $db->prepare("
            SELECT *
            FROM tickets
            WHERE event_id = ?
              AND organizer_id = ?
              AND (qr_token = ? OR order_reference = ?)
            LIMIT 1
        ");
        $stmt->execute([$eventId, $organizerId, $qrToken, $qrToken]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new Exception('Ingresso nao encontrado.', 404);
        }
        if (($ticket['status'] ?? '') === 'used') {
            throw new Exception('Ingresso ja utilizado.', 409);
        }
        if (($ticket['status'] ?? '') === 'cancelled') {
            throw new Exception('Ingresso cancelado.', 409);
        }

        if ($otpCode && !self::verifyTOTP((string)($ticket['totp_secret'] ?? ''), $otpCode)) {
            throw new Exception('QR Code expirado (impressao detectada). Peca para atualizar a tela.', 403);
        }

        $db->prepare("UPDATE tickets SET status = 'used', used_at = NOW() WHERE id = ?")
            ->execute([(int)$ticket['id']]);

        if (class_exists('AuditService')) {
            \AuditService::log(
                \AuditService::TICKET_VALIDATE,
                'ticket',
                (int)$ticket['id'],
                ['status' => $ticket['status']],
                ['status' => 'used'],
                $operator,
                'success',
                ['event_id' => (int)$ticket['event_id']]
            );
        }
    }

    private static function processGuestValidation(PDO $db, array $operator, array $payload): void
    {
        $organizerId = self::resolveOrganizerId($operator);
        if ($organizerId <= 0) {
            throw new Exception('Organizador invalido para sincronizacao offline de convidados.', 403);
        }

        $eventId = (int)($payload['event_id'] ?? 0);
        $token = OfflineSyncNormalizer::normalizeScannedToken((string)($payload['token'] ?? ''));
        $mode = OfflineSyncNormalizer::normalizeScannerMode((string)($payload['mode'] ?? 'portaria'));

        if ($eventId <= 0 || $token === '') {
            throw new Exception('Token e evento sao obrigatorios para sincronizacao offline de convidados.', 422);
        }
        if ($mode !== 'portaria') {
            throw new Exception('Guest so pode ser validado na portaria.', 422);
        }

        $stmt = $db->prepare("
            SELECT id, event_id, name, status, metadata, qr_code_token
            FROM guests
            WHERE event_id = ?
              AND organizer_id = ?
              AND qr_code_token = ?
            LIMIT 1
        ");
        $stmt->execute([$eventId, $organizerId, $token]);
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$guest) {
            throw new Exception('Convidado nao encontrado.', 404);
        }

        $guestStatus = strtolower(trim((string)($guest['status'] ?? '')));
        if (in_array($guestStatus, ['cancelled', 'bloqueado', 'blocked', 'inapto'], true)) {
            throw new Exception('Convidado bloqueado/inapto para validacao.', 403);
        }
        if (in_array($guestStatus, ['presente', 'checked_in', 'checked-in', 'utilizado', 'used'], true)) {
            throw new Exception('Convidado ja realizou check-in.', 409);
        }

        $metadataRaw = $guest['metadata'] ?? '{}';
        $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true) : $metadataRaw;
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $checkedAt = date('c');
        $metadata['checkin_at'] = $checkedAt;
        $metadata['checkin_mode'] = 'portaria';
        $metadata['scanner_source'] = 'offline_sync';

        $updateGuestStmt = $db->prepare(
            "UPDATE guests SET status = 'presente', metadata = ?::jsonb, updated_at = NOW() WHERE id = ? AND organizer_id = ?"
        );
        $updateGuestStmt->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE), (int)$guest['id'], $organizerId]);

        if (class_exists('AuditService')) {
            \AuditService::log(
                'guest.checkin',
                'guest',
                (int)$guest['id'],
                ['status' => $guest['status']],
                ['status' => 'presente'],
                $operator,
                'success',
                ['event_id' => (int)$guest['event_id']]
            );
        }
    }

    private static function processParticipantValidation(PDO $db, array $operator, array $payload): void
    {
        $organizerId = self::resolveOrganizerId($operator);
        if ($organizerId <= 0) {
            throw new Exception('Organizador invalido para sincronizacao offline de participantes.', 403);
        }

        $eventId = (int)($payload['event_id'] ?? 0);
        $token = OfflineSyncNormalizer::normalizeScannedToken((string)($payload['token'] ?? ''));
        $mode = OfflineSyncNormalizer::normalizeScannerMode((string)($payload['mode'] ?? 'portaria'));

        if ($eventId <= 0 || $token === '') {
            throw new Exception('Token e evento sao obrigatorios para sincronizacao offline de participantes.', 422);
        }
        if (!OfflineSyncNormalizer::isScannerModeValid($mode)) {
            throw new Exception("Modo '{$mode}' invalido ou nao suportado.", 422);
        }

        $participant = participantPresenceLockParticipantForTenant($db, $organizerId, null, $token);
        if (!$participant) {
            throw new Exception('Participante nao encontrado ou restrito.', 404);
        }

        $participantId = (int)($participant['id'] ?? 0);
        $participantEventId = (int)($participant['event_id'] ?? 0);
        if ($participantId <= 0 || $participantEventId !== $eventId) {
            throw new Exception('Participante fora do escopo do evento para sincronizacao offline.', 403);
        }

        $participantStatus = strtolower(trim((string)($participant['status'] ?? '')));
        if (in_array($participantStatus, ['blocked', 'bloqueado', 'cancelled', 'inactive', 'inapto'], true)) {
            throw new Exception('Participante bloqueado/inapto para validacao.', 403);
        }

        if (!self::scannerModeAllowsParticipant($db, $participantId, $participantEventId, $organizerId, $mode)) {
            throw new Exception("Modo '{$mode}' nao permitido para este QR de equipe ou setor nao vinculado ao participante.", 422);
        }

        $cfg = workforceResolveParticipantOperationalConfig($db, $participantId);
        $window = participantPresenceResolveOperationalWindow($db, $participantId);
        $result = participantPresenceRegisterAction($db, $participantId, 'check-in', participantPresenceNormalizeGateId($mode), [
            'current_status' => (string)($participant['status'] ?? ''),
            'max_shifts_event' => (int)($cfg['max_shifts_event'] ?? 1),
            'duplicate_checkin_message' => 'Participante ja validado neste turno.',
            'duplicate_checkout_message' => 'Saida ja registrada neste turno.',
            'limit_reached_message' => 'Limite de turnos configurado para este membro foi atingido.',
            'event_day_id' => $window['event_day_id'] ?? null,
            'event_shift_id' => $window['event_shift_id'] ?? null,
            'source_channel' => 'offline_sync',
            'operator_user_id' => isset($operator['id']) ? (int)$operator['id'] : null,
        ]);

        if (class_exists('AuditService')) {
            \AuditService::log(
                'scanner.process.participant',
                'participant',
                $participantId,
                null,
                [
                    'event_id' => $participantEventId,
                    'mode' => $mode,
                    'recorded_at' => $result['recorded_at'] ?? null,
                    'status' => $result['status'] ?? null,
                ],
                $operator,
                'success',
                ['metadata' => ['mode' => $mode], 'event_id' => $participantEventId]
            );
        }
    }

    private static function processParkingEntry(PDO $db, array $operator, array $payload): void
    {
        $organizerId = self::resolveOrganizerId($operator);
        if ($organizerId <= 0) {
            throw new Exception('Organizador invalido para sincronizacao offline de estacionamento.', 403);
        }

        $eventId = (int)($payload['event_id'] ?? 0);
        $licensePlate = strtoupper(trim((string)($payload['license_plate'] ?? '')));
        $vehicleType = trim((string)($payload['vehicle_type'] ?? 'car')) ?: 'car';

        if ($eventId <= 0 || $licensePlate === '') {
            throw new Exception('Placa do veiculo e evento sao obrigatorios.', 422);
        }

        $qrToken = 'PRK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $stmt = $db->prepare("
            INSERT INTO parking_records (event_id, organizer_id, license_plate, vehicle_type, entry_at, status, qr_token, created_at)
            VALUES (?, ?, ?, ?, NULL, 'pending', ?, NOW())
        ");
        $stmt->execute([$eventId, $organizerId, $licensePlate, $vehicleType, $qrToken]);
    }

    private static function processParkingExit(PDO $db, array $operator, array $payload): void
    {
        $record = self::findParkingRecord($db, $operator, $payload);
        if (!$record) {
            throw new Exception('Registro de estacionamento nao encontrado ou acesso negado.', 404);
        }

        if (strtolower(trim((string)($record['status'] ?? ''))) === 'exited') {
            return;
        }

        $stmt = $db->prepare("
            UPDATE parking_records
            SET exit_at = NOW(), status = 'exited', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([(int)$record['id']]);
    }

    private static function processParkingValidation(PDO $db, array $operator, array $payload): void
    {
        $record = self::findParkingRecord($db, $operator, $payload);
        if (!$record) {
            throw new Exception('Ticket de estacionamento nao reconhecido.', 404);
        }

        $currentStatus = strtolower(trim((string)($record['status'] ?? '')));
        $action = OfflineSyncNormalizer::normalizeParkingAction((string)($payload['action'] ?? ''));
        if ($action === '') {
            $action = $currentStatus === 'parked' ? 'exit' : 'entry';
        }

        if ($action === 'entry') {
            if ($currentStatus === 'parked') {
                return;
            }

            $stmt = $db->prepare("
                UPDATE parking_records
                SET status = 'parked', entry_at = NOW(), exit_at = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([(int)$record['id']]);
            return;
        }

        if ($action === 'exit') {
            if ($currentStatus === 'exited') {
                return;
            }

            $stmt = $db->prepare("
                UPDATE parking_records
                SET status = 'exited', exit_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([(int)$record['id']]);
            return;
        }

        throw new Exception('Acao de validacao de estacionamento invalida.', 422);
    }

    /** Payment methods forbidden in offline topup (digital/gateway-dependent). */
    const OFFLINE_TOPUP_BLOCKED_METHODS = [
        'pix', 'card', 'credit_card', 'debit_card', 'web', 'asaas',
        'mercadopago', 'pagarme', 'stripe', 'boleto', 'online',
    ];

    private static function processTopup(PDO $db, array $operator, array $payload, string $offlineId): void
    {
        $eventId = (int)($payload['event_id'] ?? 0);
        $amount  = round((float)($payload['amount'] ?? 0), 2);
        $cardId  = trim((string)($payload['card_id'] ?? ''));
        $paymentMethod = strtolower(trim((string)($payload['payment_method'] ?? 'cash'))) ?: 'cash';

        if ($eventId <= 0) {
            throw new Exception('Evento invalido para recarga offline.', 422);
        }

        // S1-01: Only cash is allowed for offline topup (normalized by OfflineSyncNormalizer)
        if ($paymentMethod !== 'cash') {
            self::logOfflineTopupRejection($operator, $offlineId, $eventId, $paymentMethod, $cardId);
            throw new Exception(
                "Metodo de pagamento '{$paymentMethod}' nao permitido para recarga offline. "
                . 'Apenas dinheiro (cash/manual) e aceito offline. '
                . 'Para pagamentos digitais, use a recarga online.',
                422
            );
        }

        if ($amount <= 0) {
            throw new Exception('Valor de recarga invalido para sincronizacao offline.', 422);
        }
        if ($cardId === '') {
            throw new Exception('Registro offline de recarga sem card_id canonico.', 422);
        }
        if (!\WalletSecurityService::isCanonicalCardId($cardId)) {
            throw new Exception('Registro offline de recarga sem card_id canonico. Revalide o cartao antes de sincronizar.', 422);
        }

        $organizerId = self::resolveOrganizerId($operator);

        \WalletSecurityService::processTransaction(
            $db,
            $cardId,
            $amount,
            'credit',
            $organizerId,
            [
                'description' => 'Recarga de Saldo (offline)',
                'event_id' => $eventId,
                'user_id' => (int)($operator['id'] ?? $operator['sub'] ?? 0),
                'payment_method' => $paymentMethod,
                'offline_id' => $offlineId,
                'is_offline' => true,
            ]
        );

        \AuditService::log(
            \AuditService::CARD_RECHARGE,
            'card',
            $cardId,
            null,
            [
                'recharge_amount' => $amount,
                'payment_method' => $paymentMethod,
                'offline_id' => $offlineId,
                'is_offline' => true,
            ],
            $operator,
            'success'
        );
    }

    /**
     * S1-04: Log and audit rejected offline topup with invalid payment method.
     */
    private static function logOfflineTopupRejection(
        array $operator,
        string $offlineId,
        int $eventId,
        string $rejectedMethod,
        string $cardId
    ): void {
        $userId = (int)($operator['id'] ?? $operator['sub'] ?? 0);
        $organizerId = self::resolveOrganizerId($operator);

        error_log(json_encode([
            'timestamp' => date('c'),
            'level' => 'warning',
            'message' => 'Offline topup rejected: invalid payment method',
            'error_code' => 'offline_payment_method_not_allowed',
            'offline_id' => $offlineId,
            'event_id' => $eventId,
            'payment_method' => $rejectedMethod,
            'card_id' => $cardId,
            'user_id' => $userId,
            'organizer_id' => $organizerId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (class_exists('AuditService')) {
            \AuditService::log(
                'offline_sync.topup_rejected',
                'card',
                $cardId ?: $offlineId,
                null,
                [
                    'reason' => 'offline_payment_method_not_allowed',
                    'payment_method' => $rejectedMethod,
                    'offline_id' => $offlineId,
                    'event_id' => $eventId,
                    'is_offline' => true,
                ],
                $operator,
                'failure'
            );
        }
    }

    // ─── Authorization ──────────────────────────────────────────────────

    public static function authorizePayload(PDO $db, array $operator, string $type, array $payload): void
    {
        $eventId = (int)($payload['event_id'] ?? 0);
        if ($eventId <= 0) {
            throw new Exception('Evento invalido para sincronizacao offline.', 422);
        }

        $organizerId = self::resolveOrganizerId($operator);
        if ($organizerId <= 0) {
            throw new Exception('Operador sem organizer_id valido para sincronizacao offline.', 403);
        }

        static $eventOrganizerCache = [];
        if (!array_key_exists($eventId, $eventOrganizerCache)) {
            $stmt = $db->prepare('SELECT organizer_id FROM events WHERE id = ? LIMIT 1');
            $stmt->execute([$eventId]);
            $eventOrganizer = $stmt->fetchColumn();
            if ($eventOrganizer === false) {
                throw new Exception('Evento nao encontrado para sincronizacao offline.', 404);
            }
            $eventOrganizerCache[$eventId] = (int)$eventOrganizer;
        }

        if ((int)$eventOrganizerCache[$eventId] !== $organizerId) {
            throw new Exception('Evento fora do escopo do operador para sincronizacao offline.', 403);
        }

        if (self::canBypassSectorAcl($operator)) {
            return;
        }

        $userSector = self::resolveUserSector($db, $operator);
        if ($userSector === 'all') {
            return;
        }

        $payloadSector = OfflineSyncNormalizer::normalizeSector((string)($payload['sector'] ?? ''));
        if ($type === 'sale' && $payloadSector === '') {
            throw new Exception('Setor e obrigatorio para sincronizacao offline de vendas.', 422);
        }

        if ($payloadSector !== '' && $payloadSector !== $userSector) {
            throw new Exception('Setor fora do escopo do operador para sincronizacao offline.', 403);
        }
    }

    // ─── Internal helpers ───────────────────────────────────────────────

    public static function resolveOrganizerId(array $operator): int
    {
        if (($operator['role'] ?? '') === 'admin') {
            return (int)($operator['organizer_id'] ?? $operator['id'] ?? 0);
        }

        return (int)($operator['organizer_id'] ?? 0);
    }

    private static function canBypassSectorAcl(array $operator): bool
    {
        $role = strtolower((string)($operator['role'] ?? ''));
        return $role === 'admin' || $role === 'organizer';
    }

    private static function resolveUserSector(PDO $db, array $operator): string
    {
        $tokenSector = OfflineSyncNormalizer::normalizeSector((string)($operator['sector'] ?? ''));
        if ($tokenSector !== '') {
            return $tokenSector;
        }

        $userId = (int)($operator['id'] ?? 0);
        if ($userId <= 0) {
            return 'all';
        }

        $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(sector), ''), 'all') FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $sector = $stmt->fetchColumn();
        return OfflineSyncNormalizer::normalizeSector((string)$sector) ?: 'all';
    }

    private static function findParkingRecord(PDO $db, array $operator, array $payload): ?array
    {
        $organizerId = self::resolveOrganizerId($operator);
        $eventId = (int)($payload['event_id'] ?? 0);
        $parkingId = isset($payload['parking_id']) ? (int)$payload['parking_id'] : 0;
        $qrToken = trim((string)($payload['qr_token'] ?? ''));

        if ($organizerId <= 0 || $eventId <= 0 || ($parkingId <= 0 && $qrToken === '')) {
            throw new Exception('Payload de estacionamento offline incompleto.', 422);
        }

        if ($parkingId > 0) {
            $stmt = $db->prepare("
                SELECT p.id, p.status, p.qr_token
                FROM parking_records p
                JOIN events e ON e.id = p.event_id
                WHERE p.id = ?
                  AND p.event_id = ?
                  AND e.organizer_id = ?
                LIMIT 1
            ");
            $stmt->execute([$parkingId, $eventId, $organizerId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            return $record ?: null;
        }

        $stmt = $db->prepare("
            SELECT p.id, p.status, p.qr_token
            FROM parking_records p
            JOIN events e ON e.id = p.event_id
            WHERE p.qr_token = ?
              AND p.event_id = ?
              AND e.organizer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$qrToken, $eventId, $organizerId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    private static function scannerModeAllowsParticipant(PDO $db, int $participantId, int $eventId, int $organizerId, string $mode): bool
    {
        if ($mode === 'portaria') {
            return true;
        }

        if ($participantId <= 0 || $eventId <= 0 || $organizerId <= 0) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM workforce_assignments wa
            JOIN event_participants ep ON ep.id = wa.participant_id
            JOIN events e ON e.id = ep.event_id
            WHERE wa.participant_id = ?
              AND ep.event_id = ?
              AND e.organizer_id = ?
              AND LOWER(REGEXP_REPLACE(COALESCE(wa.sector, ''), '\s+', '_', 'g')) = ?
            LIMIT 1
        ");
        $stmt->execute([$participantId, $eventId, $organizerId, $mode]);
        return (bool)$stmt->fetchColumn();
    }

    private static function verifyTOTP(string $secret, string $code): bool
    {
        $window = 1;
        $timestamp = floor(time() / 30);
        $key = hex2bin($secret);
        if ($key === false) {
            return false;
        }

        for ($i = -$window; $i <= $window; $i++) {
            $timeSlot = $timestamp + $i;
            $timePacked = pack('N*', 0) . pack('N*', $timeSlot);
            $hash = hash_hmac('sha1', $timePacked, $key, true);
            $offset = ord(substr($hash, -1)) & 0x0F;
            $value = unpack('N', substr($hash, $offset, 4));
            $value = $value[1] & 0x7FFFFFFF;
            $otp = str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
            if (hash_equals($otp, $code)) {
                return true;
            }
        }

        return false;
    }

    private static function parkingRecordHasOrganizerColumn(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'parking_records'
              AND column_name = 'organizer_id'
            LIMIT 1
        ");
        $stmt->execute();
        $cache = (bool)$stmt->fetchColumn();

        return $cache;
    }

    // ─── Queue audit ────────────────────────────────────────────────────

    public static function insertQueueAudit(
        PDO $db,
        array $operator,
        int $eventId,
        string $deviceId,
        string $type,
        array $payload,
        string $offlineId,
        string $status,
        string $createdAt
    ): void {
        $schema = self::offlineQueueSchema($db);

        $columns = ['event_id', 'device_id', 'payload_type', 'payload', 'offline_id', 'status', 'created_offline_at', 'processed_at'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', 'NOW()'];
        $values = [
            $eventId,
            $deviceId,
            $type,
            json_encode($payload),
            $offlineId,
            $status,
            $createdAt,
        ];

        if (!empty($schema['has_organizer_id'])) {
            array_splice($columns, 1, 0, ['organizer_id']);
            array_splice($placeholders, 1, 0, ['?']);
            array_splice($values, 1, 0, [self::resolveOrganizerId($operator)]);
        }

        if (!empty($schema['has_user_id'])) {
            array_splice($columns, 2, 0, ['user_id']);
            array_splice($placeholders, 2, 0, ['?']);
            array_splice($values, 2, 0, [(int)($operator['id'] ?? 0) ?: null]);
        }

        $sql = sprintf(
            'INSERT INTO offline_queue (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
    }

    private static function offlineQueueSchema(PDO $db): array
    {
        static $cache = [];
        $key = spl_object_hash($db);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cache[$key] = [
            'has_organizer_id' => self::offlineQueueColumnExists($db, 'organizer_id'),
            'has_user_id' => self::offlineQueueColumnExists($db, 'user_id'),
        ];

        return $cache[$key];
    }

    private static function offlineQueueColumnExists(PDO $db, string $column): bool
    {
        static $cache = [];
        $key = 'offline_queue.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'offline_queue'
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();

        return $cache[$key];
    }
}
