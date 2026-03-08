# EnjoyFun — Prompts Oficiais para o Codex v1

## Objetivo
Transformar toda a direção oficial da EnjoyFun em prompts de execução detalhados para o Codex, organizados por fases e sprints, com baixa chance de conflito no Git.

### Regra central de execução
Cada PR deve conter **no máximo 3 entregas**.

Isso existe para:
- reduzir conflito entre branches
- facilitar revisão
- diminuir risco de quebra estrutural
- manter contexto claro para o Codex
- permitir validação incremental

---

## 1. Regras oficiais para o Codex

Todo prompt abaixo deve respeitar estas regras:

1. Ler primeiro a direção oficial da EnjoyFun.
2. Não criar arquitetura paralela à definida nos documentos oficiais.
3. Não misturar domínios na mesma PR.
4. Nunca exceder 3 entregas por PR.
5. Não alterar arquivos fora do escopo sem necessidade real.
6. Preservar compatibilidade quando a mudança for transicional.
7. Toda query sensível deve respeitar `organizer_id`.
8. Toda credencial sensível deve ser tratada como dado seguro.
9. Sempre manter foco em multi-tenant, white label e operação real de eventos.

---

## 2. Estratégia oficial de fases

## Fase 1 — Fundação e segurança
Objetivo: fortalecer a base técnica.

## Fase 2 — Estrutura do tenant
Objetivo: organizar Branding, Channels, AI Config e Financeiro.

## Fase 3 — Dashboard e métricas
Objetivo: entregar o novo painel executivo e operacional.

## Fase 4 — Participants e Workforce
Objetivo: estruturar o domínio de pessoas do evento.

## Fase 5 — Consolidação operacional
Objetivo: iniciar unificação do motor de vendas e preparação premium.

---

## 3. Sprints e prompts por fase

# FASE 1 — FUNDAÇÃO E SEGURANÇA

## Sprint 1.1 — Auth + Auditoria + Tenant Isolation

### PR 1 — Autenticação e contrato de payload
**Máximo 3 entregas:**
1. Revisar e alinhar `JWT.php`
2. Revisar e alinhar `AuthMiddleware.php`
3. Revisar `AuthController.php` para refletir o contrato oficial de autenticação

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun e respeite a arquitetura definida.

Objetivo desta PR:
Corrigir e padronizar a camada de autenticação, garantindo consistência entre helper JWT, middleware e controller de auth.

Escopo permitido:
- backend/src/Helpers/JWT.php
- backend/src/Middleware/AuthMiddleware.php
- backend/src/Controllers/AuthController.php

Entregas obrigatórias:
1. Remover fallback inseguro de segredo ou estratégia frágil equivalente.
2. Definir um contrato consistente de payload autenticado, incluindo pelo menos: user id, organizer_id, role, name/email quando necessário.
3. Garantir que AuthController e AuthMiddleware usem o mesmo contrato sem ambiguidade.

Restrições:
- Não alterar outros controllers nesta PR.
- Não mexer no frontend.
- Não introduzir outra estratégia paralela de autenticação.

Critérios de aceite:
- Auth padronizado.
- Payload consistente.
- Código mais claro e previsível.
"""

---

### PR 2 — Auditoria coerente com auth
**Máximo 3 entregas:**
1. Revisar `AuditService.php`
2. Ajustar chamadas críticas de auditoria em auth e rotas sensíveis
3. Garantir rastreio de tenant e usuário onde aplicável

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Alinhar a auditoria ao contrato oficial de autenticação e ao modelo multi-tenant.

Escopo permitido:
- backend/src/Services/AuditService.php
- pontos de chamada relacionados a autenticação e endpoints críticos já tocados pela auditoria

Entregas obrigatórias:
1. Garantir consistência entre os campos esperados pela auditoria e os campos realmente fornecidos pelo payload autenticado.
2. Garantir que user_id, user_email, organizer_id e event_id sejam registrados quando aplicável.
3. Evitar mudanças fora do escopo desta integração.

Restrições:
- Não refatorar o sistema inteiro de auditoria.
- Não mudar rotas sem necessidade.

Critérios de aceite:
- Logs coerentes.
- Sem campos essenciais nulos por desalinhamento de payload.
"""

---

### PR 3 — Tenant isolation em rotas críticas
**Máximo 3 entregas:**
1. Revisar TicketController
2. Revisar ParkingController
3. Revisar GuestController

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Reforçar tenant isolation em rotas críticas de tickets, parking e guests.

Escopo permitido:
- backend/src/Controllers/TicketController.php
- backend/src/Controllers/ParkingController.php
- backend/src/Controllers/GuestController.php

Entregas obrigatórias:
1. Garantir que listagem, leitura por ID, update, delete e ações críticas respeitem organizer_id derivado do auth.
2. Não confiar em organizer_id vindo do body.
3. Preservar compatibilidade das rotas existentes.

Restrições:
- Não mexer em Bar/Food/Shop nesta PR.
- Não alterar frontend.

Critérios de aceite:
- Sem risco óbvio de vazamento entre tenants nas rotas revisadas.
"""

---

## Sprint 1.2 — Schema-base

### PR 4 — Consolidar schema oficial
**Máximo 3 entregas:**
1. Consolidar `schema_real.sql`
2. Integrar formalmente `guests` ao desenho oficial
3. Organizar comentários/estrutura do schema para refletir o estado real

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Consolidar o schema oficial do projeto para que ele seja a fonte única de verdade.

Escopo permitido:
- database/schema_real.sql
- database/guests.sql

Entregas obrigatórias:
1. Revisar divergências entre schema principal e estruturas paralelas.
2. Garantir que a tabela guests esteja refletida de forma oficial e coerente.
3. Deixar o schema mais claro para manutenção futura.

Restrições:
- Não criar ainda todas as tabelas futuras de participants/workforce.
- Não alterar backend/frontend.

Critérios de aceite:
- Schema consolidado.
- Menos divergência entre scripts.
"""

---

### PR 5 — Tabelas do Tenant Settings Hub
**Máximo 3 entregas:**
1. Criar `organizer_channels`
2. Criar `organizer_ai_config`
3. Criar `organizer_payment_gateways` e `organizer_financial_settings`

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base de dados do Tenant Settings Hub.

Escopo permitido:
- database/schema_real.sql
- arquivos SQL auxiliares, se houver necessidade controlada

Entregas obrigatórias:
1. Criar tabela organizer_channels.
2. Criar tabela organizer_ai_config.
3. Criar tabelas organizer_payment_gateways e organizer_financial_settings.

Regras:
- Todas devem ser multi-tenant por organizer_id.
- Campos sensíveis devem prever armazenamento seguro.
- Não misturar branding nessas tabelas.

Critérios de aceite:
- Estrutura de tenant pronta para Channels, AI e Financeiro.
"""

---

### PR 6 — Multi-day e base inicial de Participants
**Máximo 3 entregas:**
1. Criar `event_days`
2. Criar `event_shifts`
3. Criar `participant_categories` e `event_participants`

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base mínima para eventos multi-day e Participants Hub.

Escopo permitido:
- database/schema_real.sql

Entregas obrigatórias:
1. Criar event_days.
2. Criar event_shifts.
3. Criar participant_categories e event_participants.

Restrições:
- Não migrar guests ainda.
- Não criar ainda workforce_assignments, participant_checkins e participant_meals nesta PR.

Critérios de aceite:
- Banco preparado para festivais e base unificada de participantes.
"""

---

# FASE 2 — ESTRUTURA DO TENANT

## Sprint 2.1 — Settings Hub backend

### PR 7 — Branding e separação conceitual de Settings
**Máximo 3 entregas:**
1. Manter OrganizerSettingsController focado em Branding
2. Preparar separação conceitual de subdomínios
3. Ajustar service/contrato se necessário

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Reposicionar o módulo de settings para que OrganizerSettingsController fique focado em Branding.

Escopo permitido:
- backend/src/Controllers/OrganizerSettingsController.php
- services relacionados, se necessário e com escopo mínimo

Entregas obrigatórias:
1. Deixar claro que OrganizerSettingsController responde por Branding.
2. Reduzir acoplamento conceitual com Channels, AI e Financeiro.
3. Preparar base para os próximos controllers específicos.

Restrições:
- Não implementar ainda todos os novos controllers.
- Não alterar frontend nesta PR.
"""

---

### PR 8 — Organizer Channels backend
**Máximo 3 entregas:**
1. Criar `OrganizerChannelsController`
2. Criar `OrganizerChannelService`
3. Implementar CRUD básico + teste de conexão conceitual

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base backend do domínio Channels por organizador.

Escopo permitido:
- backend/src/Controllers/
- backend/src/Services/
- rotas necessárias no index.php

Entregas obrigatórias:
1. Criar OrganizerChannelsController.
2. Criar OrganizerChannelService.
3. Implementar CRUD básico e endpoint de teste de conexão para providers iniciais.

Providers prioritários:
- resend
- zapi
- evolution

Restrições:
- Não implementar automações complexas.
- Não mexer em Branding nem AI Config nesta PR.
"""

---

### PR 9 — Organizer AI Config backend
**Máximo 3 entregas:**
1. Criar `OrganizerAIConfigController`
2. Criar `AIConfigService`
3. Implementar CRUD básico + teste de conexão

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base backend do domínio de IA por organizador.

Escopo permitido:
- backend/src/Controllers/
- backend/src/Services/
- rotas necessárias

Entregas obrigatórias:
1. Criar OrganizerAIConfigController.
2. Criar AIConfigService.
3. Implementar CRUD básico e teste de conexão para provider/modelo/chave.

Restrições:
- Não criar ainda agentes avançados.
- Não misturar com Dashboard ou Messaging.
"""

---

## Sprint 2.2 — Financeiro backend

### PR 10 — Organizer Finance backend
**Máximo 3 entregas:**
1. Criar `OrganizerFinanceController`
2. Criar `PaymentGatewayService`
3. Implementar CRUD básico de gateways

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base backend do domínio Financeiro por organizador.

Escopo permitido:
- backend/src/Controllers/
- backend/src/Services/
- rotas necessárias

Entregas obrigatórias:
1. Criar OrganizerFinanceController.
2. Criar PaymentGatewayService.
3. Implementar CRUD básico para organizer_payment_gateways.

Gateways prioritários:
- Mercado Pago
- PagSeguro
- Asaas
- Pagar.me
- InfinityPay

Restrições:
- Não implementar ainda conciliação premium.
- Não acoplar com checkout real nesta PR.
"""

---

### PR 11 — Teste de conexão e gateway principal
**Máximo 3 entregas:**
1. Endpoint de teste de conexão
2. Definição de gateway principal
3. Ajuste de financial settings básico

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Complementar o financeiro do tenant com recursos mínimos de operação.

Escopo permitido:
- OrganizerFinanceController
- PaymentGatewayService
- estruturas financeiras relacionadas

Entregas obrigatórias:
1. Criar endpoint de teste de conexão para gateway.
2. Permitir marcar gateway principal.
3. Implementar leitura/escrita básica de organizer_financial_settings.

Restrições:
- Não integrar ainda com tickets/recargas/estacionamento.
"""

---

# FASE 3 — DASHBOARD E MÉTRICAS

## Sprint 3.1 — Backend de métricas

### PR 12 — MetricsDefinitionService + DashboardService
**Máximo 3 entregas:**
1. Criar `MetricsDefinitionService`
2. Criar `DashboardService`
3. Centralizar KPIs básicos executivos

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar o núcleo backend do novo dashboard.

Escopo permitido:
- backend/src/Services/
- controller de dashboard/admin relacionado

Entregas obrigatórias:
1. Criar MetricsDefinitionService.
2. Criar DashboardService.
3. Centralizar KPIs executivos básicos: receita total, receita por setor, tickets vendidos, float, recargas, saldo remanescente, estacionamento, participantes por categoria.

Restrições:
- Não criar ainda dashboard analítico.
- Não espalhar KPI em controllers diferentes.
"""

---

### PR 13 — Dashboard Executivo backend
**Máximo 3 entregas:**
1. Criar service executivo
2. Criar endpoint dedicado
3. Garantir filtros-base por evento/período/setor

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Implementar o backend do Dashboard Executivo v1.

Escopo permitido:
- DashboardService
- novo ExecutiveDashboardService
- controller/rota correspondente

Entregas obrigatórias:
1. Criar ExecutiveDashboardService.
2. Criar endpoint dedicado para dashboard executivo.
3. Implementar filtros base: evento, período, setor quando aplicável.

Restrições:
- Não incluir ainda comparativos entre eventos.
"""

---

### PR 14 — Dashboard Operacional backend
**Máximo 3 entregas:**
1. Criar service operacional
2. Criar endpoint dedicado
3. Consolidar timeline + estoque crítico + status técnico base

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Implementar o backend do Dashboard Operacional v1.

Escopo permitido:
- DashboardService
- novo OperationalDashboardService
- controller/rota correspondente

Entregas obrigatórias:
1. Criar OperationalDashboardService.
2. Criar endpoint dedicado para dashboard operacional.
3. Entregar dados base para timeline por setor, estoque crítico e status técnico mínimo.

Restrições:
- Não criar alertas inteligentes ainda.
"""

---

## Sprint 3.2 — Frontend de dashboard

### PR 15 — Base visual do Dashboard Executivo
**Máximo 3 entregas:**
1. Criar `KpiCard`
2. Criar `KpiGrid`
3. Criar tela base do Dashboard Executivo v1

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base visual do Dashboard Executivo v1 no frontend.

Escopo permitido:
- frontend/src/pages/
- frontend/src/components/ ou estrutura equivalente

Entregas obrigatórias:
1. Criar componente KpiCard.
2. Criar componente KpiGrid.
3. Criar a tela base do Dashboard Executivo v1 conectada ao backend novo.

Restrições:
- Não misturar com dashboard operacional na mesma tela.
"""

---

### PR 16 — Base visual do Dashboard Operacional
**Máximo 3 entregas:**
1. Criar `DashboardFilterBar`
2. Criar `SectionPanel`
3. Criar tela base do Dashboard Operacional v1

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base visual do Dashboard Operacional v1 no frontend.

Escopo permitido:
- frontend/src/pages/
- componentes compartilhados

Entregas obrigatórias:
1. Criar DashboardFilterBar.
2. Criar SectionPanel.
3. Criar a tela base do Dashboard Operacional v1 conectada ao backend novo.

Restrições:
- Não misturar com o dashboard executivo na mesma página.
"""

---

# FASE 4 — PARTICIPANTS E WORKFORCE

## Sprint 4.1 — Base backend

### PR 17 — Participant backend base
**Máximo 3 entregas:**
1. Criar `ParticipantController`
2. Criar `ParticipantService`
3. Implementar CRUD/listagem básica em cima de `event_participants`

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base backend do Participants Hub.

Escopo permitido:
- backend/src/Controllers/
- backend/src/Services/
- rotas necessárias

Entregas obrigatórias:
1. Criar ParticipantController.
2. Criar ParticipantService.
3. Implementar CRUD/listagem básica usando event_participants.

Restrições:
- Não migrar totalmente guests ainda.
"""

---

### PR 18 — Workforce backend base
**Máximo 3 entregas:**
1. Criar `WorkforceController`
2. Criar `WorkforceService`
3. Implementar importação base + vínculo de turno/cargo/setor

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base backend do Workforce Ops.

Escopo permitido:
- backend/src/Controllers/
- backend/src/Services/
- rotas necessárias

Entregas obrigatórias:
1. Criar WorkforceController.
2. Criar WorkforceService.
3. Implementar importação base e vínculo com turno/cargo/setor.

Restrições:
- Não criar ainda toda a camada analítica.
"""

---

### PR 19 — Check-in e refeições base
**Máximo 3 entregas:**
1. Criar `ParticipantCheckinController`
2. Criar `MealController`
3. Implementar registro básico de check-in/out e consumo de refeição

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Entregar a base operacional de check-in/out e refeições para Participants/Workforce.

Escopo permitido:
- controllers e services novos do domínio
- rotas necessárias

Entregas obrigatórias:
1. Criar ParticipantCheckinController.
2. Criar MealController.
3. Implementar registro básico de check-in/out e refeição por participante.

Restrições:
- Não criar ainda regras avançadas de exceção.
"""

---

## Sprint 4.2 — Frontend de Participants/Workforce

### PR 20 — Navegação e base visual
**Máximo 3 entregas:**
1. Preparar navegação Guest vs Workforce
2. Criar `ParticipantTabs`
3. Criar tabela base de participantes

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base visual do Participants Hub no frontend.

Escopo permitido:
- frontend/src/pages/
- frontend/src/components/

Entregas obrigatórias:
1. Preparar navegação separando Guest Management e Workforce Ops.
2. Criar ParticipantTabs.
3. Criar tabela base de participantes.

Restrições:
- Não reescrever tudo do Guest atual de uma vez.
"""

---

### PR 21 — Workforce Ops frontend v1
**Máximo 3 entregas:**
1. Tela base de workforce
2. Filtros de cargo/setor/turno
3. Painel simples de presença

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Criar a base visual do Workforce Ops v1.

Escopo permitido:
- frontend/src/pages/
- components relacionados

Entregas obrigatórias:
1. Criar tela base de workforce.
2. Criar filtros por cargo, setor e turno.
3. Criar painel simples de presença.

Restrições:
- Não implementar ainda analytics avançado.
"""

---

# FASE 5 — CONSOLIDAÇÃO OPERACIONAL

## Sprint 5.1 — Sales Engine inicial

### PR 22 — Services compartilhados de Sales
**Máximo 3 entregas:**
1. Criar `ProductService`
2. Criar `CheckoutService`
3. Criar `SalesReportService`

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Extrair a lógica comum de Bar/Food/Shop para services compartilhados.

Escopo permitido:
- backend/src/Services/
- ajustes mínimos em controllers para delegação

Entregas obrigatórias:
1. Criar ProductService.
2. Criar CheckoutService.
3. Criar SalesReportService.

Restrições:
- Não remover ainda os controllers setoriais.
- Manter compatibilidade.
"""

---

### PR 23 — Delegação parcial em Bar/Food/Shop
**Máximo 3 entregas:**
1. Delegar catálogo
2. Delegar checkout
3. Delegar relatórios básicos

### Prompt para o Codex
"""
Leia primeiro os documentos oficiais da EnjoyFun.

Objetivo desta PR:
Fazer Bar, Food e Shop passarem a delegar lógica para os services comuns.

Escopo permitido:
- BarController.php
- FoodController.php
- ShopController.php
- services recém-criados

Entregas obrigatórias:
1. Delegar catálogo.
2. Delegar checkout.
3. Delegar relatórios básicos.

Restrições:
- Não reescrever rotas.
- Não alterar frontend nesta PR.
"""

---

## 4. Ordem oficial recomendada de uso destes prompts

### Primeiro usar
- PR 1 a PR 6

### Depois
- PR 7 a PR 11

### Depois
- PR 12 a PR 16

### Depois
- PR 17 a PR 21

### Depois
- PR 22 e PR 23

---

## 5. Resultado esperado

Com estes prompts, o Codex passa a trabalhar de forma incremental, com baixa chance de conflito no Git, cada PR com no máximo 3 entregas e cada fase respeitando a arquitetura oficial da EnjoyFun.

