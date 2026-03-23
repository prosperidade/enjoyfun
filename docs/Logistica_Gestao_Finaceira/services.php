<?php
// =============================================================================
// Regras de Negócio, Validações e Lógica de Alertas — EnjoyFun
// PHP puro · PDO para banco de dados
//
// Organização sugerida:
//   src/
//     Services/
//       ArtistService.php
//       TimelineService.php
//       AlertCalculatorService.php
//       PayableService.php
//       CardService.php
//     Validators/
//       DocumentValidator.php
//     Jobs/
//       OverdueCheckerJob.php
// =============================================================================

// =============================================================================
// VALIDAÇÃO DE CPF E CNPJ
// =============================================================================

class DocumentValidator
{
    public static function validateCPF(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }

        // Primeiro dígito verificador
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$cpf[$i] * (10 - $i);
        }
        $remainder = ($sum * 10) % 11;
        if ($remainder === 10 || $remainder === 11) $remainder = 0;
        if ($remainder !== (int)$cpf[9]) return false;

        // Segundo dígito verificador
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int)$cpf[$i] * (11 - $i);
        }
        $remainder = ($sum * 10) % 11;
        if ($remainder === 10 || $remainder === 11) $remainder = 0;

        return $remainder === (int)$cpf[10];
    }

    public static function validateCNPJ(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1+$/', $cnpj)) {
            return false;
        }

        // Primeiro dígito verificador
        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$cnpj[$i] * $weights1[$i];
        }
        $remainder = $sum % 11;
        $d1 = $remainder < 2 ? 0 : 11 - $remainder;
        if ($d1 !== (int)$cnpj[12]) return false;

        // Segundo dígito verificador
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int)$cnpj[$i] * $weights2[$i];
        }
        $remainder = $sum % 11;
        $d2 = $remainder < 2 ? 0 : 11 - $remainder;

        return $d2 === (int)$cnpj[13];
    }
}


// =============================================================================
// MÓDULO 1 — SERVIÇO DE ARTISTAS
// =============================================================================

class ArtistService
{
    public function __construct(private PDO $db) {}

    // R1.02 — Documento único por organizer
    public function checkDocumentConflict(
        string $organizerId,
        string $documentNumber,
        ?string $excludeId = null
    ): bool {
        $sql = 'SELECT id FROM artists
                WHERE organizer_id = :org AND document_number = :doc';

        if ($excludeId) {
            $sql .= ' AND id != :exclude';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':org', $organizerId);
        $stmt->bindValue(':doc', $documentNumber);
        if ($excludeId) {
            $stmt->bindValue(':exclude', $excludeId);
        }
        $stmt->execute();

        return $stmt->fetch() !== false;
    }

    // R1.03 — Um artista por evento (sem duplicata)
    public function checkArtistInEvent(string $eventId, string $artistId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM event_artists WHERE event_id = :event AND artist_id = :artist'
        );
        $stmt->execute([':event' => $eventId, ':artist' => $artistId]);

        return $stmt->fetch() !== false;
    }

    // R1.11 — Artista com eventos ativos não pode ser inativado
    public function hasActiveEvents(string $artistId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM event_artists
             WHERE artist_id = :id AND status IN ('confirmed', 'pending')"
        );
        $stmt->execute([':id' => $artistId]);

        return (int)$stmt->fetchColumn() > 0;
    }

    // R1.07 — Calcular performance_end_datetime automaticamente
    public static function calcPerformanceEnd(
        string $startDatetime,
        int $durationMin
    ): string {
        $start = new DateTime($startDatetime);
        $start->modify("+{$durationMin} minutes");
        return $start->format('Y-m-d H:i:s');
    }
}


// =============================================================================
// MÓDULO 1 — SERVIÇO DE ITENS DE LOGÍSTICA
// =============================================================================

class LogisticsItemService
{
    public function __construct(private PDO $db) {}

    // R1.09 — total_amount calculado no servidor
    public static function calcTotalAmount(int $quantity, float $unitAmount): float
    {
        return round($quantity * $unitAmount, 2);
    }

    // R1.10 — Item já pago não pode ser removido
    public function checkItemIsPaid(string $itemId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT status FROM artist_logistics_items WHERE id = :id"
        );
        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && $row['status'] === 'paid';
    }
}


// =============================================================================
// MÓDULO 1 — SERVIÇO DE CARTÕES
// =============================================================================

class CardService
{
    public function __construct(private PDO $db) {}

    // R1.12 — Saldo não pode ser negativo
    public function checkSufficientBalance(string $cardId, float $amount): array
    {
        $stmt = $this->db->prepare(
            'SELECT credit_amount, consumed_amount, status FROM artist_cards WHERE id = :id'
        );
        $stmt->execute([':id' => $cardId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            return ['ok' => false, 'error' => 'CARD_NOT_FOUND'];
        }

        // R1.13 — Cartão bloqueado/cancelado não aceita transações
        if ($card['status'] !== 'active') {
            return ['ok' => false, 'error' => 'CARD_NOT_ACTIVE', 'status' => $card['status']];
        }

        $balance = (float)$card['credit_amount'] - (float)$card['consumed_amount'];
        if ($amount > $balance) {
            return [
                'ok'        => false,
                'error'     => 'INSUFFICIENT_BALANCE',
                'available' => $balance,
                'requested' => $amount,
            ];
        }

        return ['ok' => true, 'balance' => $balance];
    }

    // Gerar card_number único
    public function generateCardNumber(string $eventId): string
    {
        $year  = date('Y');
        $seq   = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $token = strtoupper(substr(md5($eventId . microtime()), 0, 4));
        return "CARD-{$year}-{$token}-{$seq}";
    }

    // Gerar qr_token único
    public function generateQrToken(): string
    {
        return 'qr_' . bin2hex(random_bytes(16));
    }
}


// =============================================================================
// MÓDULO 1 — MOTOR DE ALERTAS (AlertCalculatorService)
// =============================================================================

class AlertCalculatorService
{
    public function __construct(private PDO $db) {}

    /**
     * Ponto de entrada principal.
     * Chamado após qualquer alteração na timeline ou nos transfers.
     */
    public function recalculate(string $eventArtistId, string $organizerId): void
    {
        $timeline  = $this->getTimeline($eventArtistId, $organizerId);
        $transfers = $this->getTransfers($eventArtistId, $organizerId);
        $ea        = $this->getEventArtist($eventArtistId, $organizerId);

        // Remove alertas anteriores não resolvidos manualmente
        $this->db->prepare(
            'DELETE FROM artist_operational_alerts
             WHERE event_artist_id = :ea AND organizer_id = :org AND is_resolved = FALSE'
        )->execute([':ea' => $eventArtistId, ':org' => $organizerId]);

        if (!$timeline || !$ea['performance_start_datetime']) {
            $this->insertAlert($eventArtistId, $organizerId, $ea['event_id'] ?? '', $ea['artist_id'] ?? '', [
                'alert_type'         => 'insufficient_data',
                'severity'           => 'low',
                'color_status'       => 'gray',
                'message'            => 'Dados insuficientes para calcular a janela operacional.',
                'recommended_action' => 'Preencha a linha do tempo e o horário de apresentação.',
            ]);
            return;
        }

        $alerts = $this->calculateAlerts($timeline, $transfers, $ea);

        foreach ($alerts as $alert) {
            $this->insertAlert($eventArtistId, $organizerId, $ea['event_id'], $ea['artist_id'], $alert);
        }
    }

    // -------------------------------------------------------------------------
    // Lógica de cálculo das janelas
    // -------------------------------------------------------------------------
    private function calculateAlerts(array $timeline, array $transfers, array $ea): array
    {
        $alerts = [];

        $perfStart = new DateTime($ea['performance_start_datetime']);
        $perfEnd   = $ea['performance_end_datetime']
            ? new DateTime($ea['performance_end_datetime'])
            : (clone $perfStart)->modify('+' . ($ea['performance_duration_min'] ?? 60) . ' minutes');

        // -------------------------------------------------------------------
        // JANELA 1: Chegada → Soundcheck
        // -------------------------------------------------------------------
        if ($timeline['soundcheck_datetime'] && $timeline['arrival_datetime']) {
            $soundcheck = new DateTime($timeline['soundcheck_datetime']);
            $arrival    = new DateTime($timeline['arrival_datetime']);
            $bufferMin  = $this->diffMinutes($arrival, $soundcheck);
            $transferEta = $this->getTransferEta($transfers, 'airport_to_venue');
            $effectiveBuffer = $transferEta !== null ? $bufferMin - $transferEta : $bufferMin;

            $alert = $this->classifyBuffer(
                $effectiveBuffer,
                'soundcheck_conflict',
                "Buffer entre chegada e soundcheck: {$effectiveBuffer} minutos.",
                "Antecipar transfer ou reduzir soundcheck."
            );
            if ($alert) $alerts[] = $alert;
        }

        // -------------------------------------------------------------------
        // JANELA 2: Chegada → Início do show
        // -------------------------------------------------------------------
        if ($timeline['arrival_datetime']) {
            $arrival     = new DateTime($timeline['arrival_datetime']);
            $transferEta = $this->getTransferEta($transfers, 'airport_to_venue')
                        ?? $this->getTransferEta($transfers, 'hotel_to_venue')
                        ?? 0;

            $venueArrival = $timeline['venue_arrival_datetime']
                ? new DateTime($timeline['venue_arrival_datetime'])
                : (clone $arrival)->modify("+{$transferEta} minutes");

            $bufferToShow = $this->diffMinutes($venueArrival, $perfStart);

            if ($bufferToShow < 0) {
                $alerts[] = [
                    'alert_type'         => 'stage_conflict',
                    'severity'           => 'critical',
                    'color_status'       => 'red',
                    'message'            => 'CRÍTICO: Chegada prevista ao venue é posterior ao início do show.',
                    'recommended_action' => 'Avaliar alteração de horário de palco ou transfer especial.',
                ];
            } else {
                $alert = $this->classifyBuffer(
                    $bufferToShow,
                    'tight_arrival',
                    "Buffer entre chegada ao venue e show: {$bufferToShow} minutos.",
                    "Antecipar transfer. Ter camarim e produção prontos antes da chegada."
                );
                if ($alert) $alerts[] = $alert;
            }
        }

        // -------------------------------------------------------------------
        // JANELA 3: Fim do show → Próximo compromisso
        // -------------------------------------------------------------------
        if ($timeline['next_departure_deadline']) {
            $deadline    = new DateTime($timeline['next_departure_deadline']);
            $exitEta     = $this->getTransferEta($transfers, 'venue_to_airport')
                        ?? $this->getTransferEta($transfers, 'venue_to_next_event')
                        ?? 0;

            $latestDeparture = (clone $deadline)->modify("-{$exitEta} minutes");
            $bufferAfterShow = $this->diffMinutes($perfEnd, $latestDeparture);

            $alert = $this->classifyBuffer(
                $bufferAfterShow,
                'tight_departure',
                "Buffer entre fim do show e saída necessária: {$bufferAfterShow} minutos.",
                "Transfer aguardando no backstage. Sem tempo para meet & greet."
            );
            if ($alert) $alerts[] = $alert;
        }

        // -------------------------------------------------------------------
        // JANELA 4: ETAs críticos não informados
        // -------------------------------------------------------------------
        $criticalRoutes = ['airport_to_venue', 'venue_to_airport', 'venue_to_next_event'];
        foreach ($criticalRoutes as $route) {
            if ($this->routeIsRelevant($timeline, $route) && $this->getTransferEta($transfers, $route) === null) {
                $alerts[] = [
                    'alert_type'         => 'transfer_risk',
                    'severity'           => 'medium',
                    'color_status'       => 'yellow',
                    'message'            => "Estimativa de deslocamento '{$this->routeLabel($route)}' não informada.",
                    'recommended_action' => "Cadastrar ETA do trecho '{$this->routeLabel($route)}'.",
                ];
            }
        }

        // Se sem alertas de problema → alerta verde
        if (empty($alerts)) {
            $alerts[] = [
                'alert_type'         => 'tight_arrival',
                'severity'           => 'low',
                'color_status'       => 'green',
                'message'            => 'Todas as janelas operacionais estão confortáveis.',
                'recommended_action' => 'Nenhuma ação necessária.',
            ];
        }

        return $alerts;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function classifyBuffer(
        int $minutes,
        string $alertType,
        string $message,
        string $action
    ): ?array {
        if ($minutes >= 30) return null; // confortável, sem alerta

        if ($minutes < 0) {
            return ['alert_type' => $alertType, 'severity' => 'critical', 'color_status' => 'red',
                    'message' => $message, 'recommended_action' => $action];
        }
        if ($minutes < 15) {
            return ['alert_type' => $alertType, 'severity' => 'high', 'color_status' => 'orange',
                    'message' => $message, 'recommended_action' => $action];
        }
        return ['alert_type' => $alertType, 'severity' => 'medium', 'color_status' => 'yellow',
                'message' => $message, 'recommended_action' => $action];
    }

    private function diffMinutes(DateTime $from, DateTime $to): int
    {
        return (int)round(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }

    private function getTransferEta(array $transfers, string $routeType): ?int
    {
        foreach ($transfers as $t) {
            if ($t['route_type'] === $routeType) {
                return (int)$t['planned_eta_minutes'];
            }
        }
        return null;
    }

    private function routeIsRelevant(array $timeline, string $route): bool
    {
        if ($route === 'airport_to_venue')   return !empty($timeline['arrival_airport']);
        if ($route === 'venue_to_airport')   return ($timeline['next_commitment_type'] ?? '') === 'airport';
        if ($route === 'venue_to_next_event') return ($timeline['next_commitment_type'] ?? '') === 'event';
        return false;
    }

    private function routeLabel(string $route): string
    {
        return match($route) {
            'airport_to_venue'    => 'Aeroporto → Venue',
            'venue_to_airport'    => 'Venue → Aeroporto',
            'venue_to_next_event' => 'Venue → Próximo Evento',
            'hotel_to_venue'      => 'Hotel → Venue',
            default               => $route,
        };
    }

    private function insertAlert(
        string $eventArtistId,
        string $organizerId,
        string $eventId,
        string $artistId,
        array  $alert
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO artist_operational_alerts
             (id, organizer_id, event_id, event_artist_id, artist_id,
              alert_type, severity, color_status, message, recommended_action)
             VALUES (uuid_generate_v4(), :org, :event, :ea, :artist,
                     :type, :severity, :color, :message, :action)'
        );
        $stmt->execute([
            ':org'      => $organizerId,
            ':event'    => $eventId,
            ':ea'       => $eventArtistId,
            ':artist'   => $artistId,
            ':type'     => $alert['alert_type'],
            ':severity' => $alert['severity'],
            ':color'    => $alert['color_status'],
            ':message'  => $alert['message'],
            ':action'   => $alert['recommended_action'] ?? null,
        ]);
    }

    private function getTimeline(string $eventArtistId, string $organizerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM artist_operational_timelines
             WHERE event_artist_id = :ea AND organizer_id = :org LIMIT 1'
        );
        $stmt->execute([':ea' => $eventArtistId, ':org' => $organizerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getTransfers(string $eventArtistId, string $organizerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM artist_transfer_estimations
             WHERE event_artist_id = :ea AND organizer_id = :org'
        );
        $stmt->execute([':ea' => $eventArtistId, ':org' => $organizerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getEventArtist(string $eventArtistId, string $organizerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM event_artists WHERE id = :id AND organizer_id = :org LIMIT 1'
        );
        $stmt->execute([':id' => $eventArtistId, ':org' => $organizerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}


// =============================================================================
// MÓDULO 2 — SERVIÇO DE CONTAS A PAGAR
// =============================================================================

class PayableService
{
    public function __construct(private PDO $db) {}

    // R2.07 — Status calculado automaticamente
    public static function calculateStatus(
        float  $amount,
        float  $paidAmount,
        string $dueDate,
        bool   $cancelled = false
    ): string {
        if ($cancelled)              return 'cancelled';
        if ($paidAmount >= $amount)  return 'paid';
        if ($paidAmount > 0)         return 'partial';
        if (new DateTime($dueDate) < new DateTime('today')) return 'overdue';
        return 'pending';
    }

    // R2.06 — Pagamento não pode exceder saldo devedor
    public function checkPaymentAmount(string $payableId, float $paymentAmount): array
    {
        $stmt = $this->db->prepare(
            'SELECT amount, paid_amount, status FROM event_payables WHERE id = :id'
        );
        $stmt->execute([':id' => $payableId]);
        $payable = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payable) {
            return ['ok' => false, 'error' => 'PAYABLE_NOT_FOUND'];
        }
        if ($payable['status'] === 'cancelled') {
            return ['ok' => false, 'error' => 'PAYABLE_IS_CANCELLED'];
        }

        $remaining = (float)$payable['amount'] - (float)$payable['paid_amount'];
        if ($paymentAmount > $remaining) {
            return [
                'ok'        => false,
                'error'     => 'PAYMENT_EXCEEDS_BALANCE',
                'available' => $remaining,
                'requested' => $paymentAmount,
            ];
        }

        return ['ok' => true, 'remaining' => $remaining];
    }

    // R2.09 — Payable com pagamentos não pode ser cancelado
    public function hasPayments(string $payableId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM event_payments WHERE payable_id = :id'
        );
        $stmt->execute([':id' => $payableId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Registrar pagamento com transação atômica
    public function registerPayment(
        string $payableId,
        string $organizerId,
        string $eventId,
        array  $data
    ): array {
        $check = $this->checkPaymentAmount($payableId, (float)$data['amount']);
        if (!$check['ok']) {
            return $check;
        }

        $this->db->beginTransaction();
        try {
            // Inserir pagamento
            $paymentId = $this->generateUuid();
            $stmt = $this->db->prepare(
                'INSERT INTO event_payments
                 (id, organizer_id, event_id, payable_id, payment_date,
                  amount, payment_method, reference_number, receipt_url, paid_by, notes)
                 VALUES (:id, :org, :event, :payable, :date,
                         :amount, :method, :ref, :receipt, :by, :notes)'
            );
            $stmt->execute([
                ':id'      => $paymentId,
                ':org'     => $organizerId,
                ':event'   => $eventId,
                ':payable' => $payableId,
                ':date'    => $data['payment_date'],
                ':amount'  => $data['amount'],
                ':method'  => $data['payment_method'],
                ':ref'     => $data['reference_number'] ?? null,
                ':receipt' => $data['receipt_url'] ?? null,
                ':by'      => $data['paid_by'] ?? null,
                ':notes'   => $data['notes'] ?? null,
            ]);

            // Atualizar paid_amount e remaining_amount no payable
            $stmt = $this->db->prepare(
                'UPDATE event_payables
                 SET paid_amount     = paid_amount + :amount,
                     remaining_amount = amount - (paid_amount + :amount2),
                     updated_at      = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':amount'  => $data['amount'],
                ':amount2' => $data['amount'],
                ':id'      => $payableId,
            ]);

            // Recalcular status
            $payable = $this->fetchPayable($payableId);
            $newStatus = self::calculateStatus(
                (float)$payable['amount'],
                (float)$payable['paid_amount'],
                $payable['due_date']
            );
            $this->db->prepare(
                "UPDATE event_payables SET status = :status, paid_at = CASE WHEN :s = 'paid' THEN NOW() ELSE paid_at END
                 WHERE id = :id"
            )->execute([':status' => $newStatus, ':s' => $newStatus, ':id' => $payableId]);

            $this->db->commit();

            return [
                'ok'                   => true,
                'payment_id'           => $paymentId,
                'payable_status_after' => $newStatus,
                'remaining_amount'     => (float)$payable['amount'] - (float)$payable['paid_amount'],
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'TRANSACTION_FAILED', 'message' => $e->getMessage()];
        }
    }

    // Estornar pagamento com transação atômica
    public function reversePayment(string $paymentId, string $organizerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM event_payments WHERE id = :id AND organizer_id = :org'
        );
        $stmt->execute([':id' => $paymentId, ':org' => $organizerId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) return ['ok' => false, 'error' => 'PAYMENT_NOT_FOUND'];

        $this->db->beginTransaction();
        try {
            // Remover pagamento
            $this->db->prepare('DELETE FROM event_payments WHERE id = :id')
                     ->execute([':id' => $paymentId]);

            // Decrementar paid_amount
            $this->db->prepare(
                'UPDATE event_payables
                 SET paid_amount      = GREATEST(0, paid_amount - :amount),
                     remaining_amount = amount - GREATEST(0, paid_amount - :amount2),
                     paid_at          = NULL,
                     updated_at       = NOW()
                 WHERE id = :id'
            )->execute([
                ':amount'  => $payment['amount'],
                ':amount2' => $payment['amount'],
                ':id'      => $payment['payable_id'],
            ]);

            // Recalcular status
            $payable = $this->fetchPayable($payment['payable_id']);
            $newStatus = self::calculateStatus(
                (float)$payable['amount'],
                (float)$payable['paid_amount'],
                $payable['due_date']
            );
            $this->db->prepare('UPDATE event_payables SET status = :s WHERE id = :id')
                     ->execute([':s' => $newStatus, ':id' => $payment['payable_id']]);

            $this->db->commit();

            return [
                'ok'                   => true,
                'reversed'             => true,
                'payable_status_after' => $newStatus,
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'TRANSACTION_FAILED', 'message' => $e->getMessage()];
        }
    }

    private function fetchPayable(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM event_payables WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}


// =============================================================================
// JOB — Marcar contas vencidas (rodar diariamente via cron)
// =============================================================================

class OverdueCheckerJob
{
    public function __construct(private PDO $db) {}

    /**
     * Adicionar ao crontab:
     *   0 6 * * * php /var/www/enjoyfun/jobs/overdue_checker.php
     */
    public function run(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE event_payables
             SET status = 'overdue', updated_at = NOW()
             WHERE status = 'pending'
               AND due_date < CURRENT_DATE"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
