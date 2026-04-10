# Auditoria Completa — Financeiro do Evento & Logística de Artistas

**Projeto:** EnjoyFun  
**Data:** 2026-03-25  
**Escopo:** backend, frontend, banco de dados, integrações e consumidores dos módulos `/api/event-finance` e `/api/artists`.

---

## 1) Resumo executivo

O desenho funcional dos dois módulos está **bem encaminhado**, com separação de domínio documentada (`/api/event-finance` e `/api/artists`) e trilha de migrations dedicada. Porém, há fragilidades críticas de coerência multi-tenant e integridade por evento que podem gerar **bugs silenciosos em produção** (dados agregados incorretos, atualização fora de escopo, reconciliação financeira distorcida).

### Nível de risco consolidado
- **Financeiro:** Alto (risco de inconsistência de saldos e visões agregadas)
- **Logística de artistas:** Médio (consistência e rastreabilidade boas, mas faltam guardrails de consumo/integridade entre módulos)
- **Arquitetura/integrações:** Médio-Alto (dependências de validação no app sem reforço completo no banco)
- **Usabilidade:** Médio (bom fluxo base, porém sem camadas de feedback/robustez suficientes em cenários de erro)

---

## 2) Evidências e diagnóstico por domínio

## 2.1 Financeiro do evento

### Ponto forte
- O domínio financeiro já impõe recalcular status de payable via helper (`calculatePayableStatus`/`applyPayableRecalculation`) e usa transações para pagamento/estorno.

### Fragilidades críticas
1. **Resumo por centro de custo agrega contas de outros eventos do mesmo organizer (bug silencioso).**  
   Em `getSummaryByCostCenter`, o `LEFT JOIN event_payables` filtra `organizer_id`, mas **não filtra `event_id`** no join. Isso pode inflar `committed`, `paid` e `pending` no dashboard do evento.
2. **Create payment aceita `event_id` no body sem validar aderência com o `payable_id`.**  
   É possível registrar pagamento com `event_id` divergente do evento da conta (dependendo de dados de entrada), produzindo rastros inconsistentes e dificultando auditoria.
3. **Listagens de payables/overdue fazem JOIN por IDs de categoria/centro/fornecedor sem reforço explícito de `organizer_id` nas tabelas relacionadas.**  
   Mesmo com IDs globais, o padrão multi-tenant fica mais frágil e dependente de premissas implícitas.
4. **`updatePayable` busca registro final por `id` sem filtro de `organizer_id`.**  
   Não altera outro tenant por conta do update anterior, mas o fetch final deveria manter filtro por segurança defensiva.

### Impacto de negócio
- KPI de orçamento comprometido pode ficar incorreto.
- Decisão financeira operacional (pagar ou segurar fornecedor) com base em dados distorcidos.
- Auditoria de fechamento de evento com baixa confiabilidade.

### Recomendação imediata (P0)
- Corrigir join de `getSummaryByCostCenter` para incluir `p.event_id = :ev`.
- Em `createPayment`, travar e validar que `event_payables.event_id == body.event_id` antes do insert.
- Padronizar todos os SELECTs de leitura com **dupla chave de escopo**: `organizer_id + event_id` (quando aplicável).
- Ajustar fetch final de update para `WHERE id = :id AND organizer_id = :organizer_id`.

---

## 2.2 Logística de artistas

### Pontos fortes
- Subrecursos principais estão implementados (bookings, logistics, items, timelines, alerts, team, files, imports/exports).
- Há paginação e filtros básicos nas listagens.

### Fragilidades
1. **Acoplamento funcional com financeiro ainda depende mais de processo do que de mecanismo.**  
   O módulo prevê origem financeira de cachê/logística, mas o vínculo automático com payable ainda pode gerar variações de operação manual.
2. **Possível divergência de tipagem entre tabelas novas e legado (`INTEGER`/`BIGINT`) em chaves de referência de domínio.**  
   Não quebra imediatamente, mas aumenta risco de drift e casts implícitos no médio prazo.
3. **Ausência de trilha de reconciliação explícita artista ↔ payable no material de operação diária.**

### Recomendação (P1)
- Criar política única de “origem financeira” para eventos artísticos (`source_type`, `source_reference_id`, `event_artist_id`) com reconciliação diária automática.
- Padronizar roadmap para convergência de tipos de IDs no domínio novo (preferencialmente BIGINT de ponta a ponta).
- Criar job de integridade que detecte logística com custo sem payable associado (quando regra de negócio exigir).

---

## 2.3 Arquitetura e desenho técnico

### Pontos positivos
- Documentação de módulos e limites de escopo está clara.
- Dispatcher por subrecurso reduz risco de colisão de rotas e facilita evolução incremental.

### Fragilidades
1. **Dependência forte de validação em camada de aplicação sem contrapartida relacional completa no banco.**
2. **Padrão de segurança multi-tenant não está uniformemente reforçado em todos os joins de leitura.**
3. **Módulo financeiro usa regras corretas de status, mas faltam guardrails adicionais de consistência cruzada (evento da conta vs evento do pagamento).
**

### Recomendação (P1)
- Adotar checklist obrigatório por endpoint:
  - `organizer_id` no WHERE principal
  - `event_id` obrigatório para recursos event-scoped
  - joins com condição de escopo explícita
  - teste de regressão para isolamento tenant/evento

---

## 2.4 Usabilidade (frontend)

### Achados
1. **Tela de dashboard financeiro usa cor `gray` em KPI sem classe mapeada no `colorMap`** (inconsistência visual em estados sem alerta).
2. **Fluxos de carregamento silenciosos em vários `catch(() => {})`** (eventos/categorias/fornecedores) reduzem transparência para operador.
3. **Falta de UX de “estado vazio orientado por ação” em alguns fluxos críticos** (ex.: sem centros de custo, usuário tenta criar payable).

### Recomendação (P1/P2)
- Completar mapeamento visual de estado (`gray`) no componente de KPI.
- Substituir `catch(() => {})` por toast contextual + telemetria operacional.
- Adicionar pré-checks de dependências (categoria/centro) antes de abrir modal de criação.

---

## 2.5 Banco de dados

### Achados
1. O schema dos novos módulos está estruturado e com checks úteis (status, valores >= 0, constraints de unicidade).
2. Ainda faltam FKs compostas/garantias que amarrem escopo de organizer/evento em algumas relações de alto impacto financeiro.
3. Há risco de “correção por código” em vez de “proteção por modelagem”, especialmente para coerência `event_payments.event_id` ↔ `event_payables.event_id`.

### Recomendação (P0/P1)
- Criar constraint de consistência para pagamento x payable (via trigger BEFORE INSERT/UPDATE ou redesign para derivar `event_id` diretamente do payable).
- Revisar índices compostos para consultas de dashboard:
  - `event_payables (organizer_id, event_id, status, due_date)`
  - `event_payments (organizer_id, event_id, payable_id, status)`
- Definir política de “escopo obrigatório” em revisões de migration (template SQL com seção de segurança multi-tenant).

---

## 2.6 Integrações e consumidores

### Achados
1. Integrações planejadas estão bem delimitadas em documentação (reuso de cartões e separação de domínio financeiro do organizer).
2. Canais OTP têm fallback `mocked` em ambiente de desenvolvimento (adequado), mas exigem governança de configuração para não induzir falsa percepção de entrega real em testes homologação.
3. Consumidores internos (dashboards e telas de payables) dependem fortemente da qualidade dos agregados de summary; qualquer falha de join afeta decisão operacional.

### Recomendação (P1)
- Definir “matriz de consumidores críticos” (dashboard financeiro, exportações, fechamento) com testes de contrato.
- Criar suíte de testes de integração para endpoints de summary usando massa multi-evento/multi-tenant.
- Incluir monitor de discrepância: `sum(payments posted)` vs `payables.paid_amount` por evento.

---

## 3) Bugs silenciosos priorizados

## P0 (corrigir imediatamente)
1. **Summary por centro de custo sem filtro de evento no JOIN** (agregação incorreta).  
2. **Pagamento criado com event_id potencialmente divergente do payable** (inconsistência de trilha financeira).  
3. **Fetch final de update payable sem filtro de organizer_id** (higiene de segurança/isolamento).

## P1 (próxima sprint)
4. **JOINs defensivos com escopo explícito em toda leitura financeira (categoria/centro/fornecedor).**  
5. **Tratamento de erros silenciosos no frontend (catch vazio).**  
6. **Reforço de reconciliação logística-artista ↔ contas a pagar.**

## P2 (evolução arquitetural)
7. Padronização definitiva de tipos de IDs no domínio novo.  
8. Checklist de segurança de query no PR template + testes de contrato por endpoint.

---

## 4) Plano de execução recomendado

## Fase 1 — Contenção (48h)
- Patch backend P0 (summary join, validação evento no payment, fetch com organizer).
- Teste regressivo manual com dados de 2 eventos do mesmo organizer.
- Publicar nota de mudança e checklist de validação de fechamento financeiro.

## Fase 2 — Confiabilidade (Sprint atual)
- Testes de integração automatizados para `/event-finance/summary*`, `/payables`, `/payments`.
- Telemetria de erros de carregamento no frontend financeiro.
- Dashboard de reconciliação: saldo payable x pagamentos posted.

## Fase 3 — Hardening estrutural (próximas sprints)
- Guardrails de banco para coerência intertabelas críticas.
- Padronização de escopo em todos os módulos event-scoped.
- Política formal de revisão SQL focada em multi-tenant.

---

## 5) Métricas de sucesso pós-correção

- **0 divergência** entre agregado de summary e relatórios de fechamento para o mesmo evento.
- **0 pagamento inconsistente** (`event_id` divergente do payable) após implantação.
- **Redução de incidentes operacionais** de financeiro por erro de visão consolidada.
- **Tempo de diagnóstico menor** por conta de telemetria e erros não silenciosos no frontend.

---

## 6) Conclusão

A base dos módulos é boa e já está em estágio funcional relevante, mas para suportar operação real de evento com confiança financeira, é essencial executar o pacote P0/P1 de hardening de consistência e isolamento. O maior risco hoje não é falta de feature, e sim **confiabilidade de agregados e coerência transacional entre entidades financeiras**.

