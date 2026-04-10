BEGIN;

-- ---------------------------------------------------------------------------
-- 041_workforce_ai_integrity_hardening.sql
-- Objetivo:
-- 1) Evitar corrupcao silenciosa na arvore de leadership/workforce
-- 2) Garantir consistencia de tenant/evento em binds de assignments
-- 3) Garantir consistencia entre ai_event_report_sections e ai_event_reports
-- 4) Vincular memoria de IA ao historico de execucoes quando informado
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_workforce_event_roles_parent_not_self'
          AND conrelid = 'public.workforce_event_roles'::regclass
    ) THEN
        ALTER TABLE public.workforce_event_roles
            ADD CONSTRAINT chk_workforce_event_roles_parent_not_self
            CHECK (parent_event_role_id IS NULL OR parent_event_role_id <> id) NOT VALID;
    END IF;
END $$;

CREATE OR REPLACE FUNCTION public.trg_workforce_assignment_event_binding_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_assignment_event_id integer;
    v_event_role_event_id integer;
    v_root_role_event_id integer;
BEGIN
    SELECT ep.event_id
      INTO v_assignment_event_id
      FROM public.event_participants ep
     WHERE ep.id = NEW.participant_id;

    IF v_assignment_event_id IS NULL THEN
        RAISE EXCEPTION 'workforce_assignments: participant_id % invalido ou sem event_id', NEW.participant_id;
    END IF;

    IF NEW.event_role_id IS NOT NULL THEN
        SELECT wer.event_id
          INTO v_event_role_event_id
          FROM public.workforce_event_roles wer
         WHERE wer.id = NEW.event_role_id;

        IF v_event_role_event_id IS NULL THEN
            RAISE EXCEPTION 'workforce_assignments: event_role_id % inexistente', NEW.event_role_id;
        END IF;

        IF v_event_role_event_id <> v_assignment_event_id THEN
            RAISE EXCEPTION 'workforce_assignments: event_role_id % pertence ao evento %, esperado %',
                NEW.event_role_id,
                v_event_role_event_id,
                v_assignment_event_id;
        END IF;
    END IF;

    IF NEW.root_manager_event_role_id IS NOT NULL THEN
        SELECT wer.event_id
          INTO v_root_role_event_id
          FROM public.workforce_event_roles wer
         WHERE wer.id = NEW.root_manager_event_role_id;

        IF v_root_role_event_id IS NULL THEN
            RAISE EXCEPTION 'workforce_assignments: root_manager_event_role_id % inexistente', NEW.root_manager_event_role_id;
        END IF;

        IF v_root_role_event_id <> v_assignment_event_id THEN
            RAISE EXCEPTION 'workforce_assignments: root_manager_event_role_id % pertence ao evento %, esperado %',
                NEW.root_manager_event_role_id,
                v_root_role_event_id,
                v_assignment_event_id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_workforce_assignment_event_binding_guard
    ON public.workforce_assignments;

CREATE TRIGGER trg_workforce_assignment_event_binding_guard
BEFORE INSERT OR UPDATE OF participant_id, event_role_id, root_manager_event_role_id
ON public.workforce_assignments
FOR EACH ROW
EXECUTE FUNCTION public.trg_workforce_assignment_event_binding_guard();

CREATE OR REPLACE FUNCTION public.trg_ai_event_report_section_consistency_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_report_organizer_id integer;
    v_report_event_id integer;
BEGIN
    SELECT r.organizer_id, r.event_id
      INTO v_report_organizer_id, v_report_event_id
      FROM public.ai_event_reports r
     WHERE r.id = NEW.report_id;

    IF v_report_organizer_id IS NULL THEN
        RAISE EXCEPTION 'ai_event_report_sections: report_id % inexistente', NEW.report_id;
    END IF;

    IF NEW.organizer_id <> v_report_organizer_id THEN
        RAISE EXCEPTION 'ai_event_report_sections: organizer_id % divergente do report organizer_id %',
            NEW.organizer_id,
            v_report_organizer_id;
    END IF;

    IF NEW.event_id <> v_report_event_id THEN
        RAISE EXCEPTION 'ai_event_report_sections: event_id % divergente do report event_id %',
            NEW.event_id,
            v_report_event_id;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_ai_event_report_section_consistency_guard
    ON public.ai_event_report_sections;

CREATE TRIGGER trg_ai_event_report_section_consistency_guard
BEFORE INSERT OR UPDATE OF report_id, organizer_id, event_id
ON public.ai_event_report_sections
FOR EACH ROW
EXECUTE FUNCTION public.trg_ai_event_report_section_consistency_guard();

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ai_agent_memories_source_execution'
          AND conrelid = 'public.ai_agent_memories'::regclass
    ) THEN
        ALTER TABLE public.ai_agent_memories
            ADD CONSTRAINT fk_ai_agent_memories_source_execution
            FOREIGN KEY (source_execution_id)
            REFERENCES public.ai_agent_executions(id)
            ON DELETE SET NULL
            NOT VALID;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_binding_guard
    ON public.workforce_assignments (participant_id, event_role_id, root_manager_event_role_id);

CREATE INDEX IF NOT EXISTS idx_ai_event_report_sections_consistency_guard
    ON public.ai_event_report_sections (report_id, organizer_id, event_id);

COMMIT;


# Auditoria Técnica Completa — Workforce, Árvore de Assignments/Lideranças, Banco/Migrations e Agente de IA

**Data da auditoria:** 2026-03-28  
**Escopo:** backend PHP + migrations SQL + trilha de IA orquestrada

## 1) Resumo executivo

A base está funcional, mas com **riscos estruturais silenciosos** em três frentes:

1. **Drift de migrations** no módulo de IA (039/040 criadas, mas não aplicadas no log).
2. **Persistência silenciosa de falhas** no agente de IA (captura exceções e retorna `null`/`void`, sem escalonar para API).
3. **Integridade de árvore/assignments** depende muito da aplicação e pouco do banco (faltavam guard-rails para consistência entre participante ⇄ evento ⇄ event_role).

## 2) Achados detalhados e diagnóstico

## 2.1 Workforce / árvore de assignments e lideranças

### Achado W1 — Integridade entre assignment e event_role dependia apenas da camada de aplicação
- O helper usa `event_role_id` e `root_manager_event_role_id` em `workforce_assignments`, mas até aqui não havia trigger garantindo que esses vínculos são do **mesmo evento** do `participant_id`.
- Isso permitia corrupção silenciosa em cenários de integração/importação (ex.: bind cruzado entre eventos).

**Impacto:** árvore inconsistente, líder raiz incorreto, relatórios de liderança comprometidos.

**Solução aplicada:** trigger `trg_workforce_assignment_event_binding_guard` (migration 041) para bloquear inserts/updates inconsistentes.  

### Achado W2 — Falta de guarda contra autoreferência direta na árvore
- A estrutura de `workforce_event_roles` tinha FK para `parent_event_role_id`, mas sem check explícito para bloquear `parent_event_role_id = id`.

**Impacto:** possibilidade de nó pai apontando para si próprio, quebrando travessias de árvore e backfills.

**Solução aplicada:** constraint `chk_workforce_event_roles_parent_not_self` (NOT VALID, sem bloquear rollout imediato).  

## 2.2 Banco de dados e migrations

### Achado D1 — Drift operacional de migrations de IA
- O log de migrações aplicadas para em `038_ai_orchestrator_foundation.sql`, enquanto já existem `039_ai_agent_execution_history.sql` e `040_ai_memory_and_event_reports.sql` no repositório.

**Impacto:** funcionalidades de histórico/memória/relatórios podem funcionar parcialmente em alguns ambientes e falhar em outros.

**Plano recomendado:**
1. Aplicar 039 e 040 de forma controlada.
2. Validar healthcheck de tabelas (`ai_agent_executions`, `ai_agent_memories`, `ai_event_reports`, `ai_event_report_sections`) após deploy.
3. Validar constraints pendentes.

### Achado D2 — Integridade referencial incompleta entre memória e execução
- `ai_agent_memories.source_execution_id` era apenas campo solto, sem FK para `ai_agent_executions(id)`.

**Impacto:** memórias órfãs e rastreabilidade fraca de auditoria de IA.

**Solução aplicada:** FK `fk_ai_agent_memories_source_execution` (NOT VALID) com `ON DELETE SET NULL` (migration 041).

### Achado D3 — Seções de relatório de IA sem guarda de consistência com relatório pai
- `ai_event_report_sections` tinha FK para `report_id`, mas sem validação explícita de coerência de `organizer_id` e `event_id` com o registro pai.

**Impacto:** risco de contaminação lógica entre eventos/tenants em operações manuais ou ETL.

**Solução aplicada:** trigger `trg_ai_event_report_section_consistency_guard` (migration 041).

## 2.3 Agente de IA implementado

### Achado A1 — Falhas silenciosas em persistência de execução
- `AgentExecutionService::logExecution` captura `Throwable`, registra em log e retorna `null`.
- O orquestrador continua fluxo mesmo sem persistência de trilha.

**Impacto:** perda silenciosa de observabilidade/auditoria e difícil diagnóstico de produção.

**Recomendação:**
- Introduzir modo estrito por ambiente (`AI_AUDIT_STRICT=true`) para transformar falha de persistência em erro 5xx nos ambientes de homologação/staging.
- Em produção, manter degrade gracioso mas com métrica/alerta (SLO de taxa de falha de log).

### Achado A2 — Falhas silenciosas em memória/aprendizado
- `AIMemoryStoreService::recordMemory` também suprime exceções com `error_log`.

**Impacto:** IA responde, mas memória não é gravada e ninguém percebe sem monitoramento.

**Recomendação:**
- Eventos de domínio (`ai.memory.persist_failed`) com contador por organizer e surface.
- Retry assíncrono opcional (outbox).

## 3) Soluções implementadas nesta rodada

Foi adicionada a migration:

- `database/041_workforce_ai_integrity_hardening.sql`

### Conteúdo da 041
- Constraint para impedir self-parent em `workforce_event_roles`.
- Trigger de consistência assignment ⇄ event_role/root_manager_event_role por evento.
- Trigger de consistência `ai_event_report_sections` ⇄ `ai_event_reports` (organizer/event).
- FK opcional de memória para execução (`source_execution_id`).
- Índices de suporte para validações.

## 4) Plano de execução sugerido (próximas 48h)

1. Aplicar migrations pendentes 039, 040 e 041 em staging.
2. Rodar smoke:
   - criação de assignment com event_role válido/inválido,
   - geração de insight IA + verificação de log em `ai_agent_executions`,
   - criação de relatório final e seções.
3. Validar constraints `NOT VALID` em janela controlada.
4. Subir alertas para `AgentExecutionService` e `AIMemoryStoreService` (falha de persistência > 1%).

## 5) Critérios de sucesso

- Zero bind inconsistente entre assignment e árvore de event_role.
- 100% dos insights com trilha em `ai_agent_executions` (ou alerta quando não houver).
- Zero seção de relatório com `event_id/organizer_id` divergente do report pai.
- Ambientes sem drift de schema (039–041 aplicadas e registradas).
