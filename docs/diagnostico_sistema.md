# Proposta Completa de Transformação da EnjoyFun (Estado Atual do Repositório)

> Base técnica usada: leitura direta do código atual em `backend/`, `frontend/` e `database/` após sincronização local via `git fetch --all --prune`.

## 0) Sincronização do workspace
- `git fetch --all --prune` executado com sucesso.
- `git pull --ff-only` não pôde ser aplicado no branch atual porque ele não possui upstream/tracking remoto configurado.
- Resultado prático: análise feita no estado atual disponível no workspace local.

---

## 1) VISÃO DE NEGÓCIO

### Proposta de valor
A EnjoyFun hoje já opera como plataforma SaaS para operação de eventos com foco em: ingressos, convidados, PDV por setor (bar/food/shop), cashless (cartão digital), estacionamento, scanner e administração. O valor principal é centralizar operação de evento em um único sistema com visão em tempo quase real.

### Perfil dos usuários
- Organizador (dono da operação do evento)
- Admin (gestão ampla da plataforma)
- Manager/staff (operação diária)
- Bartender / equipe de PDV
- Parking staff
- Cliente final (app web para saldo/recarga)

### Operação real do evento
Fluxo operacional atual já suporta preparação de evento, venda de ingressos, operação em PDV com contingência offline e reconciliação posterior.

### Modelo de monetização
Modelo sugerido e coerente com o código atual:
- Assinatura por organizador (white label + módulos)
- Taxa por evento/volume transacional
- Add-on de IA (insights operacionais e analíticos)
- Add-on premium de automações operacionais e auditoria avançada

### Visão futura
Evoluir de “sistema operacional de evento” para **plataforma inteligente de performance operacional**, com previsões (demanda, estoque, filas, equipe), alertas em tempo real e benchmark entre eventos/organizadores.

---

## 2) PROBLEMAS ATUAIS DO SISTEMA

### Problemas críticos
1. **Risco multi-tenant inconsistente entre módulos**: alguns endpoints aplicam `organizer_id` corretamente; outros não aplicam de forma uniforme (ex.: deleções e partes de fluxo de alguns controladores).
2. **Inconsistência arquitetural de IA**: existe `GeminiService`, mas o `AIController` usa OpenAI; naming e estratégia de provedores estão misturados.
3. **Schema divergente da operação real**: `schema_real.sql` não contém tabela `guests`, mas o sistema usa `GuestController` e há `database/guests.sql` separado.
4. **Segurança JWT com fallback hardcoded de segredo**: risco alto em produção.

### Problemas médios
1. Dashboard atual agrega KPIs, mas ainda limitado para visão executiva/operacional/analítica separadas.
2. Repetição de lógica entre `BarController`, `FoodController` e `ShopController` (alto custo de manutenção).
3. Falta de padronização de contratos API (alguns endpoints com formatos distintos).

### Problemas de UX
1. Sobrecarga em páginas únicas (ex.: POS com múltiplas responsabilidades).
2. Guest atual cobre convidados, mas não cobre plenamente Workforce (turnos, presença por função/cargo, refeições por dia/turno).
3. Dashboard sem trilhas claras de decisão por persona (diretoria vs operação).

### Problemas de arquitetura
1. Router em `index.php` monolítico por arquivo controller com função global `dispatch` (escala difícil).
2. Serviços e responsabilidades espalhadas em controllers (domínio pouco encapsulado).
3. Baixa separação de camadas analíticas (fatos/dimensões/materializações).

### Problemas operacionais
1. Pós-evento e reconciliação ainda muito manuais em alguns fluxos.
2. Alertas operacionais em tempo real ainda limitados (estoque crítico, anomalias, atraso de sincronização offline etc.).

---

## 3) FUNCIONALIDADES EXISTENTES (MAPA)

### Módulo: Dashboard
- **Objetivo:** visão geral do evento
- **Status:** funcional básico
- **Tabelas:** `sales`, `tickets`, `digital_cards`, `parking_records`, `users`, `sale_items`, `products`
- **Controllers:** `AdminController`
- **Perfis:** admin/organizer
- **Problemas:** não segmenta executivo vs operacional vs analítico.

### Módulo: Ingressos
- **Objetivo:** gestão e validação de tickets
- **Status:** funcional com riscos de padronização
- **Tabelas:** `tickets`, `ticket_types`, `events`, `users`
- **Controllers:** `TicketController`, `ScannerController`
- **Perfis:** admin/organizer/staff
- **Problemas:** necessidade de reforço de isolamento e auditoria em todos os endpoints.

### Módulo: Guests
- **Objetivo:** lista de convidados, importação CSV, check-in
- **Status:** funcional, porém escopo ainda “híbrido” para necessidades de Workforce
- **Tabelas:** `guests` (arquivo SQL separado), `events`
- **Controllers:** `GuestController`
- **Perfis:** admin/organizer/staff
- **Problemas:** modelagem ainda não pronta para múltiplos tipos (DJ/staff/artista/permuta/food staff) com turnos e regras de refeição.

### Módulo: PDV (Bar/Food/Shop)
- **Objetivo:** vendas, estoque, checkout cashless, relatórios rápidos e insight IA
- **Status:** funcional com offline parcial
- **Tabelas:** `products`, `sales`, `sale_items`, `digital_cards`, `card_transactions`, `offline_queue`
- **Controllers:** `BarController`, `FoodController`, `ShopController`, `SyncController`
- **Perfis:** admin/organizer/staff/bartender
- **Problemas:** duplicação de lógica entre setores e inconsistências pontuais de validação/autorização.

### Módulo: Cartão Digital/Cashless
- **Objetivo:** saldo e transações
- **Status:** funcional
- **Tabelas:** `digital_cards`, `card_transactions`
- **Controllers:** `CardController`, suporte em PDV controllers
- **Perfis:** vários
- **Problemas:** necessidade de trilha analítica mais rica para reconciliação financeira.

### Módulo: Estacionamento
- **Objetivo:** entrada/saída e validação
- **Status:** funcional
- **Tabelas:** `parking_records`
- **Controllers:** `ParkingController`
- **Perfis:** parking_staff/admin/organizer/staff
- **Problemas:** aprofundar métricas (ocupação por hora, SLA de fila, receita por janela/portão).

### Módulo: Usuários / Configuração / Super Admin
- **Objetivo:** administração de pessoas e ambiente SaaS
- **Status:** funcional básico
- **Tabelas:** `users`, `roles`, `user_roles`, `organizer_settings` (controller presente)
- **Controllers:** `UserController`, `SuperAdminController`, `OrganizerSettingsController`
- **Perfis:** admin/organizer
- **Problemas:** RBAC ainda simplificado para operações complexas por setor/cargo.

### Módulo: IA
- **Objetivo:** gerar insights de venda/estoque
- **Status:** funcional com provedores misturados
- **Tabelas:** `ai_usage_logs`
- **Controllers/Services:** `AIController`, `GeminiService`, `AIBillingService`
- **Perfis:** admin/organizer
- **Problemas:** falta estratégia única de provider + governança de custo por tenant/evento.

---

## 4) ARQUITETURA ATUAL

### Stack usada
- **Frontend:** React + Vite
- **Backend:** PHP (roteador manual em `index.php`)
- **Banco:** PostgreSQL
- **Auth:** JWT HS256
- **Offline-first:** fila local frontend + `offline_queue` no backend

### Estrutura de pastas (macro)
- `frontend/src/pages` (telas)
- `backend/src/Controllers` (endpoints)
- `backend/src/Services` (serviços auxiliares)
- `database/*.sql` (schema e scripts)

### Fluxo frontend-backend
React chama `/api/*`, backend roteia para controller por recurso. Controllers fazem SQL direto via PDO.

### organizer_id (multi-tenant)
A estratégia base é boa: `organizer_id` em tabelas principais e filtro no backend por usuário autenticado. Porém a aplicação não é 100% uniforme em todos os métodos.

### Rotas e permissões
`requireAuth([...roles])` controla acesso por papel, mas RBAC ainda coarse-grained para operação de evento complexa (setor/cargo/turno/perímetro).

### Fragilidades
- JWT com segredo fallback hardcoded.
- CORS fixo para localhost.
- Camada de domínio ainda acoplada aos controllers.
- Divergência entre modelagem “real” e módulo Guest.

---

## 5) BANCO DE DADOS — AVALIAÇÃO E MELHORIAS

## Diagnóstico geral
- Pontos fortes: há PK/FK em entidades centrais, índices úteis em auditoria e tickets.
- Gaps: ausência de alguns índices compostos por tenant/evento/tempo; falta esquema analítico; divergência guests.

### Avaliação por grupos de tabelas

1. **Core de eventos (`events`, `users`, `roles`, `user_roles`)**
- Correta para base do produto.
- Melhorar: índices compostos por `organizer_id + status + starts_at`; constraints mais rígidas de consistência.

2. **Ingressos (`ticket_types`, `tickets`)**
- Boa base para emissão/validação.
- Melhorar: índices para relatórios por `organizer_id/event_id/status/used_at`; trilha de gate/checkpoint por tentativa de validação.

3. **Vendas/PDV (`products`, `sales`, `sale_items`)**
- Modelo atual suporta operação.
- Melhorar: introduzir `shift_id`, `pos_terminal_id`, `operator_user_id`, `payment_channel`, `source` (online/offline/sync) para análise operacional.

4. **Cashless (`digital_cards`, `card_transactions`)**
- Estrutura base funcional.
- Melhorar: ledger mais explícito (tipo de movimento, referência operacional, reconciliação por fechamento de caixa).

5. **Parking (`parking_records`)**
- Base funcional com status e token.
- Melhorar: `gate_id`, `lane_id`, `operator_id`, SLA de atendimento, motivo de recusa.

6. **IA/Auditoria (`ai_usage_logs`, `audit_log`)**
- Excelente ter auditoria append-only.
- Melhorar: adicionar `organizer_id` direto em `ai_usage_logs`, `audit_log` para consultas multi-tenant rápidas.

7. **Guest/Workforce**
- Hoje: `guests.sql` separado, não unificado no schema principal.
- Melhorar: unificar e evoluir para modelo de identidades e credenciais operacionais multi-dia/turno.

### Novas tabelas recomendadas
- `people` (identidade única por pessoa)
- `event_participants` (vínculo pessoa-evento com categoria: guest/dj/staff/artista/permutas/food)
- `participant_access_rules` (validade por dia/turno/área)
- `participant_checkins` (entrada/saída por portão)
- `participant_meals` (refeição por dia/turno)
- `workforce_roles` e `workforce_assignments` (cargo/setor/escala)
- `event_days` e `event_shifts` (multi-dia robusto)
- `dashboard_snapshots` (materialização para performance)

---

## 6) TELAS FRONTEND — DIAGNÓSTICO

### Pontos bons
- Navegação clara com Sidebar e segmentação por módulos.
- Dashboard já traz KPIs iniciais úteis.
- POS tem preocupação com modo offline e UX operacional.

### Pontos ruins / melhorias
1. **Componentização insuficiente**: muita lógica e UI no mesmo arquivo (POS, Dashboard).
2. **Padronização visual**: falta design system consistente para cards, filtros, tabelas, estados vazios/erro.
3. **Escalabilidade**: filtros avançados e drill-down ainda limitados.

### O que deve virar componente
- `StatCard`, `KpiGrid`, `DateRangeFilter`, `EventFilter`, `SectorFilter`, `MetricCard`, `ChartPanel`, `EmptyState`, `EntityTabs` (Guest/Workforce).

### O que criar
- Dashboard executivo/operacional/analítico em páginas separadas.
- Workspace Workforce com tabs por categoria e operações de turno/refeição.

---

## 7) BACKEND — CONTROLLERS E REGRAS

### Diagnóstico
- Controllers estão funcionais, porém com lógica de negócio e SQL fortemente acoplados.
- Repetição grande em `Bar/Food/Shop`.
- Segurança e multi-tenant bons em parte, mas necessitam padronização absoluta.

### Reorganização sugerida
1. **Camada Service por domínio**:
- `SalesService`, `InventoryService`, `CashlessService`, `GuestService`, `WorkforceService`, `DashboardService`.
2. **Repository/Query objects** para consultas complexas.
3. **Request validation padronizada** por endpoint.
4. **Policy layer** (RBAC + ABAC por evento/setor/cargo).

### Endpoints faltando/mal desenhados (prioridade)
- `/dashboard/executive`, `/dashboard/operations`, `/dashboard/analytics`
- `/workforce/*` (staff, turnos, presença, refeições)
- `/participants/*` (visão unificada Guest+Workforce)
- `/reports/*` com exports e snapshots

---

## 8) PROPOSTA DE DASHBOARD (EXECUTIVO / OPERACIONAL / ANALÍTICO)

### 8.1 Dashboard Executivo (C-level)
**Objetivo:** resultado financeiro, ocupação, risco operacional e decisão rápida.

**Widgets:**
- Receita total (tickets + PDV + parking)
- Margem estimada
- Ingressos vendidos vs capacidade
- Créditos em float + sobras de saldo
- Recargas antecipadas vs no evento
- Taxa de entrada (presentes vs no-show)
- Receita por setor (bar/food/shop/parking)
- Alertas críticos (estoque, filas, queda de conversão)

### 8.2 Dashboard Operacional (war room)
**Objetivo:** execução em tempo real.

**Widgets:**
- Timeline de vendas por setor (5/15/60 min)
- Filas por portão/estacionamento
- Check-ins e status por categoria (guest/dj/staff/artista)
- Consumo de staff card
- Estoque crítico por produto/setor
- Terminais offline pendentes de sync

### 8.3 Dashboard Analítico
**Objetivo:** aprendizado e melhoria contínua.

**Widgets:**
- Cohort de vendas por lote/comissário
- Heatmap de consumo por hora/dia
- Comparativo entre eventos
- Eficiência de equipe por setor/turno
- Previsões (demanda, ruptura de estoque, recarga)

### Filtros ideais
- Período (24h, semana, custom)
- Evento/Festival
- Dia do evento (D1..Dn)
- Setor
- Turno
- Categoria de participante
- Comissário/lote

### Fontes de dados necessárias
- Fatos transacionais (`sales`, `sale_items`, `tickets`, `card_transactions`, `parking_records`, `participants_checkins`)
- Dimensões (`events`, `event_days`, `event_shifts`, `users`, `workforce_roles`, `products`)

### Prioridade de implementação (Dashboard)
1. Camada de métricas padronizadas + endpoints separados
2. Materialização/snapshots para performance
3. UI por persona com drill-down

### Riscos
- Sem materialização, o dashboard ficará lento em eventos maiores.
- Sem padronização de tenant, risco de dados misturados.

### Oportunidades de IA
- Alertas de anomalia (queda abrupta de vendas, picos incomuns)
- Predição de ruptura de estoque
- Recomendação de remanejamento de equipe
- Resumo executivo automático pós-evento

---

## 9) GUEST + WORKFORCE

## Deve separar?
**Sim.** Recomendo separar conceitualmente em dois módulos, com base de dados unificada.

### Nome recomendado
- **Participants Hub** (núcleo unificado)
  - **Guest Management** (convidados, artistas, DJs, permutas)
  - **Workforce Ops** (staff, escalas, turnos, refeições, presença)

### Modelagem ideal
- Pessoa única (`people`)
- Vínculo por evento (`event_participants`)
- Categoria + papel + setor
- Regras de acesso (dia/turno/área)
- Logs de entrada/saída por checkpoint
- Consumo de refeições por dia/turno

### Fluxo operacional
1. Importar CSV por categoria
2. Enriquecer cadastro (cargo/setor/turno)
3. Gerar credencial QR
4. Check-in/out por turno
5. Validar refeição por política de acesso
6. Fechamento e relatório de presença

### Telas necessárias
- Participantes (lista unificada + filtros)
- Workforce (escalas/turnos)
- Acessos/credenciais
- Refeições
- Relatórios de presença e no-show

### Relatórios necessários
- Presença por categoria/cargo/setor
- No-show por lista
- Horas efetivas por staff
- Refeições por dia/turno/custo

### Integrações
- Scanner
- PDV/staff card
- WhatsApp/e-mail para convocação

### Multi-dia (24h, 4 dias, 7 dias)
- Estruturar por `event_days` + `event_shifts`
- Regras de validade de credencial com janela temporal explícita

---

## 10) FLUXOS REAIS DA OPERAÇÃO (AS-IS + GAPS)

### Organizador
- Define evento, equipe, setores, produtos e regras.
- Gap: pouca visão consolidada executiva/analítica em uma tela com drill-down.

### Staff operacional
- Executa check-in, PDV, parking, scanner.
- Gap: faltam painéis orientados por tarefa, com menos ruído.

### Bar/Food/Shop
- Cadastro de produtos, vendas, sincronização offline.
- Gap: previsões e alertas de ruptura em tempo real ainda básicos.

### Portaria/Guest
- Validação de acesso e presença.
- Gap: sem estrutura robusta de turnos e políticas por categoria.

### Cashless
- Debita no checkout e mantém trilha de transações.
- Gap: reconciliação financeira e relatórios de fechamento podem ser mais auditáveis.

### Pós-evento
- Consolidação de resultados.
- Gap: faltam relatórios comparativos e narrativas automáticas de aprendizado.

---

## 11) RELATÓRIOS E MÉTRICAS NECESSÁRIOS

### Essenciais
- Tickets vendidos/validados/no-show
- Receita por setor
- Top produtos
- Estoque crítico
- Float, recarga, saldo remanescente
- Ocupação de estacionamento
- Presença por categoria

### Importantes
- Conversão por lote/comissário
- Taxa de sincronização offline
- SLA de fila/check-in
- Produtividade por operador/setor

### Avançadas
- Margem operacional por setor
- Elasticidade de preço por hora
- Previsão de demanda e ruptura
- Benchmark entre eventos

### Tempo real
- Alertas de anomalia, ruptura, filas, terminais offline

### Fechamento
- DRE simplificada do evento
- Conciliação por fonte de receita
- Relatório de eficiência operacional

### Decisão futura
- Curva de demanda por perfil de público
- Forecast de equipe ideal por setor
- Planejamento de mix de produtos por evento

---

## 12) OBJETIVOS DOS PRÓXIMOS 90 DIAS (PLANO)

### Prioridade de negócio
1. Dashboard executivo e operacional confiável
2. Reorganizar Guest + Workforce para reduzir caos operacional
3. Fechamento financeiro e operacional com menor retrabalho

### Prioridade técnica
1. Padronização multi-tenant em 100% das queries críticas
2. Refatorar controllers duplicados em serviços
3. Unificar schema (`guests` + novas tabelas de participants/workforce)
4. Segurança JWT/CORS para produção

### Prioridade operacional
1. Turnos, presença, refeição e credencial multi-dia
2. Alertas em tempo real para tomada de decisão durante evento

### Pode esperar
- Features cosméticas não ligadas a decisão e eficiência
- Integrações secundárias não críticas para operação core

---

## 13) ENTREGA FINAL CONSOLIDADA

### 1. Diagnóstico geral do sistema
A plataforma já tem base funcional robusta para operação real de eventos, mas precisa de padronização arquitetural e evolução de dados para virar produto premium multi-tenant escalável.

### 2. Diagnóstico do produto
Produto forte em execução operacional; precisa amadurecer camada analítica e de gestão de workforce.

### 3. Diagnóstico do dashboard
Atual atende visão inicial; falta segmentação por persona e profundidade analítica.

### 4. Diagnóstico da arquitetura
Arquitetura funcional, porém muito acoplada em controllers e com pouca separação de domínio.

### 5. Diagnóstico da modelagem
Modelo atual cobre o core, mas não cobre plenamente participants/workforce multi-dia e analytics avançado.

### 6. Novo dashboard completo (resumo)
3 painéis: Executivo, Operacional, Analítico + filtros globais + drill-down por evento/setor/turno.

### 7. Reorganização Guest + Workforce
Criar **Participants Hub** com dois submódulos (Guest Management e Workforce Ops).

### 8. Novas tabelas e relacionamentos
`people`, `event_participants`, `event_days`, `event_shifts`, `participant_access_rules`, `participant_checkins`, `participant_meals`, `workforce_assignments`, `dashboard_snapshots`.

### 9. Novos endpoints
- `/dashboard/executive|operations|analytics`
- `/participants/*`
- `/workforce/*`
- `/reports/*`
- `/alerts/*` (motor de IA/monitoramento)

### 10. UX/UI
- Design system único
- Páginas orientadas por tarefa/persona
- Menos densidade por tela + mais clareza operacional

### 11. Roadmap por fases
- **Fase 1 (0-30 dias):** segurança/multi-tenant, padronização API, dashboard operacional mínimo
- **Fase 2 (31-60 dias):** Participants Hub + Workforce Ops + relatórios críticos
- **Fase 3 (61-90 dias):** analytics avançado + IA preditiva + benchmark entre eventos

### 12. Backlog priorizado (alto nível)
P0: segurança, tenant isolation, dashboard operacional
P1: participants/workforce multi-dia
P2: analytics avançado + automações IA

### 13. Riscos técnicos e de negócio
- Vazamento de dados entre tenants se filtros falharem.
- Lerdeza de dashboard sem camada analítica/snapshot.
- Dificuldade operacional em eventos multi-dia sem módulo workforce robusto.

### 14. Oportunidades não exploradas
- Score operacional por evento em tempo real
- Benchmark entre casas/festivais
- Recomendação automática de equipe/estoque

### 15. IA aplicada à operação de eventos
- Copiloto de operação (ações sugeridas por setor)
- Forecast de estoque e filas
- Diagnóstico pós-evento com plano de melhoria

### 16. Automações inteligentes
- Alertas proativos via WhatsApp/Push para gestores
- Auto-disparo de checklist por horário/turno
- Rotina de fechamento automático com reconciliação assistida

### 17. Plano para virar plataforma premium
1. Confiabilidade multi-tenant + segurança de produção
2. Excelência operacional (dashboard + workforce)
3. Inteligência preditiva integrada
4. White label empresarial com métricas e governança por tenant

---

## Informações críticas ainda necessárias (para fechar desenho 100% executivo+técnico)
1. Volume real de eventos/mês, tickets/evento e picos de transação por minuto.
2. Estrutura de planos e pricing desejada (SaaS + take rate + add-ons).
3. Quais papéis de usuário finais precisam de permissões granulares por ação.
4. Regras exatas de operação de refeições (limites/dia, exceções, cortesias).
5. Integrações obrigatórias (pagamentos, WhatsApp oficial, ERPs, antifraude).
6. Meta de SLA (latência aceitável em portaria/PDV/scanner).
7. Critérios de compliance/auditoria exigidos por clientes enterprise.
