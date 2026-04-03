BEGIN;

-- Complementa o indice legado por evento com o recorte global de organizer
-- usado em dashboards e agregacoes de cashless nas ultimas horas.
CREATE INDEX IF NOT EXISTS idx_sales_org_completed_created_at
    ON public.sales USING btree (organizer_id, created_at DESC)
    WHERE status = 'completed';

-- Historico de transacoes do cartao e consultado por card_id + event_id
-- com ordenacao reversa por created_at.
CREATE INDEX IF NOT EXISTS idx_card_transactions_card_event_created_at
    ON public.card_transactions USING btree (card_id, event_id, created_at DESC)
    WHERE event_id IS NOT NULL;

-- Filas ativas de sincronizacao sao lidas por evento/dispositivo
-- apenas enquanto estiverem pendentes.
CREATE INDEX IF NOT EXISTS idx_offline_queue_pending_event_device_created_offline
    ON public.offline_queue USING btree (event_id, device_id, created_offline_at DESC)
    WHERE status = 'pending';

COMMIT;
