-- Diagnostico operacional do BKL-007
-- Esperado apos a 042:
--   - tabelas operacionais event-scoped zeradas
--   - somente residuos explicitamente quarentenados permanecem no relatorio

SELECT
    table_name,
    null_rows,
    status,
    note
FROM (
    SELECT 'participant_categories' AS table_name, COUNT(*)::bigint AS null_rows, 'must_fix' AS status, 'categorias de participantes devem permanecer tenant-scoped' AS note
    FROM public.participant_categories
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'ticket_types', COUNT(*)::bigint, 'must_fix', 'tipos comerciais nao devem aceitar escopo global legado'
    FROM public.ticket_types
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'sales', COUNT(*)::bigint, 'must_fix', 'vendas devem carregar organizer_id persistido'
    FROM public.sales
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'products', COUNT(*)::bigint, 'must_fix', 'produtos event-scoped devem ser retropreenchidos por event_id'
    FROM public.products
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'parking_records', COUNT(*)::bigint, 'must_fix', 'parking event-scoped deve ser retropreenchido por event_id'
    FROM public.parking_records
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'tickets', COUNT(*)::bigint, 'must_fix', 'tickets comerciais devem carregar organizer_id persistido'
    FROM public.tickets
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'ai_usage_logs', COUNT(*)::bigint, 'must_fix', 'billing/historico de IA deve permanecer tenant-scoped'
    FROM public.ai_usage_logs
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'audit_log', COUNT(*)::bigint, 'quarantined', 'permitido temporariamente apenas para historico legado sem event_id resolvivel'
    FROM public.audit_log
    WHERE organizer_id IS NULL

    UNION ALL

    SELECT 'users', COUNT(*)::bigint, 'quarantined', 'revisar manualmente usuarios organizer legados sem organizer_id'
    FROM public.users
    WHERE organizer_id IS NULL
) residue
WHERE null_rows > 0
ORDER BY
    CASE status
        WHEN 'must_fix' THEN 0
        ELSE 1
    END,
    table_name ASC;
