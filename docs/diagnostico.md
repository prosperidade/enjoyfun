# Diagnóstico Técnico Atualizado — EnjoyFun

> Data da varredura: 2026-03-08  
> Fonte de verdade: código atual do repositório (`backend/`, `frontend/`, `database/`)  
> Fonte complementar histórica: `docs/progresso.md`

## 1. Visão geral do sistema atual

O sistema está em arquitetura **SPA React + API PHP + PostgreSQL**, com organização por domínios operacionais (tickets, cards, vendas PDV, participantes/workforce, refeições, estacionamento, settings e financeiro).

### Backend (estado atual)
- Entrypoint único em `backend/public/index.php`, roteando por `resource` e delegando para controllers funcionais via `dispatch`.
- Autenticação baseada em JWT com `requireAuth()` no middleware.
- Camada de serviços já presente em domínios críticos (`SalesDomainService`, `DashboardDomainService`, `PaymentGatewayService`, `FinancialSettingsService`, `AuditService`), mas ainda coexistindo com lógica relevante dentro dos controllers.
- Multi-tenant majoritariamente aplicado via `organizer_id` do token e filtros em queries.

### Frontend (estado atual)
- React com rotas protegidas por `PrivateRoute`, `AuthContext` e client Axios com injeção automática de bearer token + tentativa de refresh em 401.
- Painel principal já com módulos operacionais: Dashboard, Settings por abas, Participants Hub, Workforce Ops, Meals Control e fluxo financeiro.
- Estrutura de UX relativamente madura para operação, com filtros, modais e ações em massa em Participants/Workforce.

### Banco (estado atual)
- `database/schema_real.sql` contém boa base das tabelas principais e trigger de imutabilidade no `audit_log`.
- Há desalinhamento entre código atual e dump consolidado do schema em pontos de Workforce/Finance (migrations adicionais não refletidas no dump).

---

## 2. Estado atual por domínio

### Auth e sessão
**Resolvido / consolidado**
- Fluxo de login, refresh token e endpoint `/auth/me` operacional.
- Middleware agora devolve `id`, `sub`, `name`, `email`, `role`, `organizer_id`, reduzindo inconsistência histórica com auditoria e emissão de tickets.

**Parcial**
- Comentários e documentação interna afirmam RS256, porém a implementação real do helper JWT está em **HS256 + HMAC**.
- Existe bypass de emergência de senha hardcoded para e-mails específicos no login.

**Pendente / risco**
- Débito de segurança por credencial de bypass em código de produção.
- Divergência “documentação x implementação” dificulta auditoria e hardening.

### Multi-tenant
**Resolvido / consolidado**
- Padrão predominante: `organizer_id` vem do token e não do body.
- Domínios críticos (participants, workforce, meals, finance, grande parte de tickets/parking) têm validações por organizador.

**Parcial**
- Há pontos específicos ainda com risco de escopo, principalmente em fluxos legados/pontuais (ex.: transferência de ticket sem filtro explícito por `organizer_id` no select inicial).

**Pendente / risco**
- Isolamento depende muito de disciplina manual controller a controller (sem mecanismo de enforcement central no banco, como RLS).

### Auditoria
**Resolvido / consolidado**
- `audit_log` com trigger para bloquear UPDATE/DELETE (imutabilidade estrutural).
- `AuditService` já alinhado para ler `id`/`sub`.

**Parcial**
- Cobertura de auditoria não é transversal: existem domínios com pouca ou nenhuma instrumentação de logs de ação crítica.

**Pendente / risco**
- Ausência de padrão mínimo obrigatório por endpoint sensível (create/update/delete/finance/settings).

### Dashboard
**Resolvido / consolidado**
- Dashboard backend isolado por `organizer_id` e frontend consumindo painel executivo + operacional.
- Conector de custos de Workforce integrado ao dashboard.

**Parcial**
- KPI de estacionamento pode ficar inconsistente com dados operacionais por divergência de modelagem/uso de `organizer_id` em `parking_records`.

**Pendente / risco**
- Métrica de billing IA ainda global (não isolada por tenant), já reconhecida no código como ponto para evolução.

### Settings
**Resolvido / consolidado**
- Settings por abas entregue no frontend (Branding, Channels, AI, Finance).
- Backend segmentado por controllers específicos de settings.

**Parcial**
- Forte dependência de contrato implícito frontend/backend (nomes de campos legados e novos coexistindo).

**Pendente / risco**
- Falta suíte de regressão de contrato para evitar quebra silenciosa entre tabs e endpoints.

### Participants Hub
**Resolvido / consolidado**
- Hub de participantes com CRUD, importação, edição, ações em massa e geração/cópia de links QR.
- Backfill de `qr_token` para participantes antigos sem token.

**Parcial**
- Alta complexidade de filtros e modos (guest x workforce x assigned_only), com múltiplos caminhos de permissão.

**Pendente / risco**
- Necessidade de testes automatizados de regressão nos filtros por categoria/setor/cargo.

### Workforce Ops
**Resolvido / consolidado**
- Estrutura robusta de cargos/alocações, ACL setorial, importação por cargo e configuração operacional por membro.
- Integrações com check-in/refeições e conector financeiro já existentes.

**Parcial**
- Código preparado para colunas opcionais (`manager_user_id`, `source_file_name`, `sector` em roles) via `columnExists`, indicando convivência com bancos em estágios diferentes.

**Pendente / risco**
- Esse “modo compatibilidade” reduz quebra imediata, mas mascara drift de schema e pode esconder lacunas de dados em produção.

### Meals Control
**Resolvido / consolidado**
- Painel operacional implementado com saldo por dia/turno e baixa por QR/token.
- Respeito a limite de refeições por configuração individual de membro.

**Parcial**
- Regras dependem da existência e qualidade de `workforce_member_settings`; em ambientes sem migration aplicada, o domínio degrada.

**Pendente / risco**
- Falta monitoramento explícito de integridade para detectar ausência das estruturas esperadas.

### Financial Layer
**Resolvido / consolidado**
- CRUD de gateways por organizador, definição de gateway principal, ativação/inativação e teste de conexão mockado.
- Frontend FinanceTab remodelado para múltiplos providers.

**Parcial**
- Contrato usa tanto `is_primary` quanto `is_principal` (compatibilidade), aumentando complexidade.

**Pendente / risco**
- Sem constraints fortes no schema dump para impedir duplicidade lógica e inconsistências de principal/ativo por tenant.

---

## 3. Itens consolidados

1. **Settings com arquitetura por abas no frontend + backend dedicado por domínio**.
2. **Participants Hub e Workforce com maturidade funcional relevante** (importação, alocação, filtros, bulk actions).
3. **Meals Control integrado ao modelo operacional de workforce**.
4. **Finance layer multi-provider funcional em nível de configuração de tenant**.
5. **Auditoria imutável no banco e integração funcional com auth**.
6. **Multi-tenant amplamente aplicado em endpoints críticos**.

---

## 4. Fragilidades reais ainda existentes

1. **JWT real (HS256) divergente do discurso arquitetural (RS256)**.
2. **Bypass de login hardcoded ativo para contas específicas**.
3. **Drift entre `schema_real.sql` e código/migrations recentes** (especialmente Workforce/Finance).
4. **Cobertura de auditoria incompleta entre domínios**.
5. **Trechos pontuais com risco de escopo tenant em fluxos específicos** (ex.: transferência de ticket sem validação explícita por `organizer_id` na consulta inicial).
6. **Contrato frontend/backend com aliases legados simultâneos** (`is_primary`/`is_principal`, etc.), elevando risco de regressão silenciosa.

---

## 5. Débitos técnicos priorizados

### Alta prioridade
1. **Remover bypass de emergência no login** e fazer rotação controlada de credenciais.
2. **Decidir e padronizar estratégia JWT** (assumir HS256 oficialmente com hardening, ou migrar de fato para RS256).
3. **Sincronizar schema consolidado com migrations aplicadas** e garantir baseline único confiável (`schema_real.sql`).
4. **Reforçar validação multi-tenant nos fluxos remanescentes de borda** (ticket transfer e rotas correlatas).

### Média prioridade
5. **Cobertura mínima mandatória de auditoria por endpoint sensível**.
6. **Reduzir dualidade de campos de contrato** (normalizar naming de financeiro e payloads).
7. **Adicionar testes de contrato backend/frontend para tabs de Settings e Finance**.

### Baixa prioridade
8. **Incrementar modularização de controllers grandes** (Participants/Workforce) para reduzir acoplamento.
9. **Padronizar telemetria operacional (erros por domínio, taxa de falha por rota).**

---

## 6. Riscos de regressão

1. **Regressão de autenticação** por mudanças em JWT sem plano de migração de tokens ativos.
2. **Regressão silenciosa de tenant isolation** em novos endpoints sem checklist de `organizer_id`.
3. **Regressão de Workforce/Meals** em ambientes com schema incompleto (colunas/tabelas ausentes).
4. **Regressão de UI Financeira** por divergências de contrato entre nomes de campos aceitos.
5. **Regressão de métricas de Dashboard** por inconsistências entre modelagem real e queries de agregação.

---

## 7. Recomendações práticas por prioridade

### Prioridade alta
- Remover imediatamente o bypass do login e validar com teste de autenticação básico (login válido/inválido/refresh).
- Publicar ADR curta de segurança definindo padrão JWT oficial e atualizar código + documentação para convergência.
- Regerar `database/schema_real.sql` após aplicar todas as migrations de workforce/finance em ambiente limpo.
- Executar varredura dirigida de queries de ticket transfer e fluxos similares para blindagem explícita por tenant.

### Prioridade média
- Criar checklist obrigatório por PR para endpoints: autenticação, organizer filter, auditoria, validação de payload.
- Introduzir testes de integração para: Settings (todas as tabs), FinanceTab (CRUD/test gateway), Workforce ACL setorial.
- Padronizar payload financeiro em um único naming e manter compatibilidade apenas com camada de tradução temporária.

### Prioridade baixa
- Quebrar controllers extensos em serviços menores para facilitar testes.
- Evoluir observabilidade (logs estruturados por domínio e correlação por request_id).

---

## 8. Próximas frentes sugeridas

1. **Hardening de Segurança e Auth (curto prazo):** remover bypass, alinhar JWT, revisar segredos e política de expiração.
2. **Consolidação de Banco (curto prazo):** baseline único de schema + verificação automática de drift.
3. **Confiabilidade Operacional (médio prazo):** testes de regressão focados em multi-tenant, workforce e finance.
4. **Governança de Contrato API (médio prazo):** versão interna de payloads e remoção gradual de aliases legados.
5. **Observabilidade/Auditoria (médio prazo):** cobertura de ações críticas em todos os domínios transacionais.

---

## 9. Resumo executivo final

O sistema evoluiu de forma significativa e já possui blocos operacionais robustos (Settings por abas, Participants/Workforce, Meals Control e camada financeira). O principal ganho é a estrutura multi-tenant já amplamente aplicada no backend e refletida nos fluxos do frontend.

Entretanto, o estado atual ainda tem riscos concretos de produção concentrados em **segurança/auth**, **drift de schema** e **consistência contratual backend/frontend**. A próxima fase deve focar menos em novas features e mais em **hardening, consolidação de base e prevenção de regressão** para escalar com previsibilidade.

---

## Observações de validação manual pendente

Alguns pontos exigem validação manual em ambiente executando banco atualizado e navegação real:
- Confirmar no ambiente alvo que migrations de Workforce/Finance foram aplicadas integralmente.
- Validar UX ponta a ponta de FinanceTab com múltiplos gateways reais (sem transação financeira real).
- Verificar coerência dos KPIs de estacionamento no dashboard com dados operacionais de portaria.
