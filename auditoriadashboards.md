# Auditoria completa — Dashboard Operacional + Dashboard Analítico

**Data:** 2026-03-25  
**Escopo:** frontend React (Dashboard e Dashboard Analítico), backend PHP (serviços/controladores), banco e integrações consumidas pelos painéis.

---

## 1) Diagnóstico executivo

O produto já está em um estágio funcional bom: os painéis estão separados por propósito (operação vs analítico), existe recorte por `event_id`, há proteção multi-tenant no backend e o analítico já traz bloco de comparação entre eventos.

Os principais riscos encontrados estão em 6 frentes:

1. **Inconsistência semântica de métricas** (mistura de janelas temporais e escopo global vs escopo por evento no mesmo payload).
2. **Possíveis bugs silenciosos de agregação** (ex.: contagem de participantes pode duplicar em cenários de migração).
3. **Escalabilidade de consultas analíticas** (query sem limite temporal opcional pode degradar forte com crescimento da base).
4. **Tratamento de erro/observabilidade insuficiente no frontend** (falhas silenciosas em carregamento de eventos e ausência de telemetria).
5. **Superfície de erro exposta no backend** (mensagens de exceção completas para cliente).
6. **Desalinhamento UX entre filtros disponíveis e filtros aplicados no backend** (filtros reservados aparecem, mas não são executáveis na UI).

---

## 2) Arquitetura e desenho atual (como está)

### 2.1 Frontend
- `Dashboard.jsx` concentra blocos de resumo, operação e conectores de finanças/equipe, com `event_id` local em estado e chamadas paralelas para `/admin/dashboard` e `/organizer-finance/workforce-costs`.
- `AnalyticalDashboard.jsx` usa hook dedicado (`useAnalyticalDashboard`) e renderiza blocos analíticos separados (summary, curva, lotes, comissários, mix, comparação, attendance, financeiro).
- O hook analítico chama `/analytics/dashboard` com `event_id`, `compare_event_id` e `group_by`.

### 2.2 Backend
- `AdminController` delega para `DashboardService`, que monta contrato final via `MetricsDefinitionService` + dados de domínio em `DashboardDomainService`.
- `AnalyticsController` delega para `AnalyticalDashboardService::getDashboardV1`.
- A camada analítica concentra regras de normalização de filtros e composição de payload em um único serviço.

### 2.3 Banco/consulta
- Forte uso de SQL agregada em tempo real.
- Há fallback para heterogeneidade de schema (`tableExists`/`columnExists`) em componentes analíticos/operacionais.

---

## 3) Fragilidades e riscos (com prioridade)

## P0 (corrigir imediatamente)

### P0.1 Vazamento de detalhes internos de erro em API
**Onde:** controladores de dashboard retornam `Exception->getMessage()` ao cliente.  
**Risco:** exposição de detalhes internos (schema, SQL, ambiente), aumento de superfície de ataque e troubleshooting inseguro.

**Solução recomendada:**
- Resposta pública genérica (`Erro interno ao montar dashboard`).
- Log técnico com correlation id no servidor.
- Incluir `error_code` estável para observabilidade de cliente.

---

## P1 (alta prioridade)

### P1.1 Semântica inconsistente de escopo financeiro
**Achado:** no analítico, `remaining_balance` ignora `event_id` e sempre soma `digital_cards` do organizador inteiro.  
**Impacto:** leitura enganosa quando usuário filtra evento específico.

**Solução:**
- Exibir explicitamente como `remaining_balance_global` **ou**
- tornar event-scoped (se houver relação de cartão-evento confiável).

### P1.2 KPI com naming “current” sem recorte real de evento
**Achado:** no operacional, `remaining_balance_current` é igual ao float total, sem lógica adicional de “current/event”.  
**Impacto:** risco de decisão operacional com premissa incorreta.

**Solução:**
- Renomear para refletir valor global, ou
- implementar cálculo por evento (fonte única e auditável).

### P1.3 Possível dupla contagem de convidados
**Achado:** agregação de participantes soma `guests` legado + `event_participants` tipo guest.  
**Impacto:** inflação de headcount/presença em ambientes híbridos/migrados.

**Solução:**
- Estratégia de deduplicação por chave de identidade (documento/telefone/email hash).
- “fonte de verdade” por evento com flag de migração concluída.

### P1.4 Falha silenciosa no carregamento de eventos do Dashboard operacional
**Achado:** o `.catch(() => {})` em `/events` ignora erro sem feedback.  
**Impacto:** usuário perde filtro de evento sem entender a causa.

**Solução:**
- Estado de erro visível na UI.
- Botão de retry.
- telemetria de falha (Sentry/OpenTelemetry).

---

## P2 (médio prazo)

### P2.1 Escalabilidade: curva analítica sem janela temporal padrão
**Achado:** `fetchSalesCurve` agrega toda a história (agrupado por hora/dia) quando sem filtro.
**Impacto:** custo alto de CPU/IO e latência em crescimento de base.

**Solução:**
- Janela padrão (ex.: últimos 30/60/90 dias).
- Permitir override com limites máximos.
- Índices compostos recomendados: `tickets(status, organizer_id, event_id, purchased_at)`.

### P2.2 Filtros “reservados” sem execução efetiva
**Achado:** backend já normaliza `date_from`, `date_to`, `sector`, mas responde em `blocked` e não aplica no dataset.
**Impacto:** percepção de incompletude e ruído de UX.

**Solução:**
- ou esconder até disponibilidade real,
- ou ativar de ponta a ponta com contrato claro de aplicação.

### P2.3 Condições de corrida em troca rápida de filtros
**Achado:** há proteção com `isMounted`, mas sem cancelamento real de request inflight.
**Impacto:** consumo desnecessário de rede e potencial “flicker” em cenários de troca rápida.

**Solução:**
- AbortController/cancel token por ciclo.
- cache por chave de query (React Query/SWR).

---

## 4) Usabilidade — melhorias que **devem** e **podem** ser executadas

## Devem (alto impacto)
1. **Padronizar contexto de período** em todos os cards (ex.: “24h”, “evento selecionado”, “global do organizador”).
2. **Adicionar estado de erro explícito** para carregamento de eventos e conectores.
3. **Adicionar “última atualização”** e ação de refresh manual no topo dos painéis.
4. **Sinalizar métricas globais vs métricas por evento** visualmente (badge).
5. **Comparativo analítico com explicação de elegibilidade** (por que compare está bloqueado e como habilitar).

## Podem (ganho incremental)
1. Drill-down por clique em KPI para tela fonte.
2. Export CSV/PNG por bloco analítico.
3. Favoritos de recorte (evento + group_by + compare).
4. Score de qualidade dos dados (completude e atraso de ingestão).

---

## 5) Banco de dados — diagnóstico e plano

### Problemas identificados
- Dependência de queries agregadas online para leitura pesada.
- Falta de camada materializada para analytics de alta cardinalidade.
- Métricas com semântica mista (global/evento/24h) no mesmo payload.

### Soluções
1. **Camada de serving analítico** com materialized views (ou tabela snapshot) por organizador/evento/dia/hora.
2. **Contratos de métrica versionados** (`analytics_v1`, `analytics_v2`) com dicionário formal.
3. **Índices orientados às consultas reais** dos painéis.
4. **Teste de consistência de métricas** no CI (ex.: soma por setor = total comercial no mesmo recorte).

---

## 6) Integrações e consumidores

### Consumidores primários mapeados
- Dashboard operacional: `/admin/dashboard`, `/events`, `/organizer-finance/workforce-costs`, endpoints de summary financeira.
- Dashboard analítico: `/analytics/dashboard` + eventos.

### Fragilidades
- Falta de versionamento explícito na rota operacional (`/admin/dashboard` sem `/v1`).
- Contrato com campos “reserva futura” sem SLA de ativação.
- Acoplamento da UI a payloads ricos sem camada de feature flags robusta.

### Plano
1. Publicar contrato OpenAPI mínimo por painel.
2. Definir `deprecations` e changelog de contrato.
3. Adotar feature flags para blocos analíticos experimentais.

---

## 7) Bugs silenciosos (resumo)

1. **`remaining_balance` analítico é global mesmo com filtro de evento.**
2. **`remaining_balance_current` operacional sugere recorte que não existe.**
3. **Possível duplicidade de convidados em base híbrida.**
4. **Falha de `/events` silenciosa no dashboard operacional.**
5. **Ausência de cancelamento real de requests em alternância rápida de filtros.**

---

## 8) Roadmap recomendado (30/60/90 dias)

### 0-30 dias
- Sanitizar erros de API e adicionar correlation id.
- Tornar explícito no UI o escopo de cada métrica.
- Corrigir métricas de saldo (global/evento).
- Expor erro de carregamento de eventos e retry.

### 31-60 dias
- Ativar filtros de período/setor no analítico (ou remover da UI até concluir).
- Implementar cancelamento de request + cache de consulta.
- Criar testes automatizados de consistência de KPIs.

### 61-90 dias
- Introduzir camada materializada analítica.
- Versionar contratos dos dois dashboards.
- Publicar SLO de latência e frescor de dados por bloco.

---

## 9) Conclusão

A base atual já suporta operação real, mas há risco de **leitura enganosa de KPI** por semântica mista e algumas falhas silenciosas de UX/consistência. O maior ganho imediato vem de: **(1)** corrigir semântica de métricas de saldo, **(2)** tornar erros observáveis no frontend/backend e **(3)** estabelecer contrato explícito de período/escopo por card.
