# Diagnóstico Técnico Atualizado — EnjoyFun

> Data da varredura: 2026-03-08  
> Fonte de verdade: código atual do repositório (`backend/`, `frontend/`, `database/`)  
> Fonte histórica de apoio (não normativa): `docs/progresso.md`

---

## 1. Visão geral do sistema atual

O EnjoyFun hoje opera como uma plataforma **React (SPA)** + **API PHP procedural por controllers** + **PostgreSQL**, com foco em operação de eventos (tickets, PDV, equipe, refeições, estacionamento, configurações de organizador e camada financeira por tenant).

### Backend
- Entrada única em `backend/public/index.php`, roteando recursos por segmento de URL e despachando via função `dispatch` em cada controller.
- Controle de acesso central via `requireAuth()` no middleware.
- Uso de services em áreas já refatoradas (`SalesDomainService`, `DashboardDomainService`, `PaymentGatewayService`, `FinancialSettingsService`, `AuditService`).
- Arquitetura ainda híbrida: parte da regra está em services, parte diretamente nos controllers.

### Frontend
- React + React Router com áreas privadas em `PrivateRoute`.
- Sessão gerida por `AuthContext`, com persistência de token e tentativa automática de refresh no client Axios.
- Módulos operacionais principais ativos na navegação: Dashboard, Settings (abas), Participants Hub, Workforce Ops, Meals Control, Finance.

### Banco
- Dump consolidado em `database/schema_real.sql` com núcleo transacional e trigger de imutabilidade de auditoria.
- Migrações incrementais de workforce existem separadas (`database/003`, `004`, `005`), e o código já está preparado para conviver com bancos em estágios diferentes (checagem dinâmica de colunas).

---

## 2. Estado atual por domínio

### Auth e sessão
**Consolidado**
- Fluxos essenciais existem e estão integrados: login, refresh, logout e `/auth/me`.
- Middleware entrega payload consistente para uso transversal (`id`, `sub`, `name`, `email`, `role`, `organizer_id`, `sector`).

**Parcial**
- Há divergência entre narrativa técnica e implementação real: comentários apontam RS256, helper executa HS256.

**Pendente / risco**
- Existe bypass de emergência hardcoded no login para e-mails específicos.
- Necessário hardening explícito para ambiente de produção.

### Multi-tenant
**Consolidado**
- Padrão majoritário está correto: `organizer_id` vem do JWT, não do body.
- Módulos centrais (participants/workforce/meals/finance/dashboard/tickets/parking) aplicam filtro por organizador na maior parte dos fluxos.

**Parcial**
- Ainda há pontos de borda com risco de escopo em queries específicas (ex.: transferência de ticket validando dono sem cláusula explícita de tenant na busca inicial).

**Pendente / risco**
- Isolamento ainda depende de disciplina de desenvolvimento endpoint a endpoint (sem enforcement estrutural tipo RLS no banco).

### Auditoria
**Consolidado**
- `audit_log` é imutável via trigger (`UPDATE`/`DELETE` bloqueados).
- `AuditService` já compatível com payload atual de auth (`id`/`sub`).

**Parcial**
- Instrumentação é seletiva: há logs em auth, cards, vendas e parking, mas não de forma mandatória em todos os endpoints críticos.

**Pendente / risco**
- Sem política transversal de “auditar toda mutação sensível” por domínio.

### Dashboard
**Consolidado**
- Backend dashboard isolado por `organizer_id` e frontend consumindo executivo + operacional.
- Integração de custo de equipe (`/organizer-finance/workforce-costs`) ativa no dashboard.

**Parcial**
- KPI de estacionamento tem risco de inconsistência por dependência da modelagem de `parking_records.organizer_id` versus joins por evento.

**Pendente / risco**
- Billing de IA permanece agregado globalmente no endpoint administrativo (não estritamente tenant-aware).

### Settings
**Consolidado**
- UI em abas entregue e estável: Branding, Channels, AI Config e Finance.
- Backend separado por domínios de configuração (`organizer-settings`, `organizer-messaging-settings`, `organizer-ai-config`, `organizer-finance`).

**Parcial**
- Contratos com compatibilidade legada coexistente (campos duplicados/aliases), especialmente em financeiro.

**Pendente / risco**
- Ausência de testes de contrato para prevenir regressões entre frontend e backend nas abas.

### Participants Hub
**Consolidado**
- CRUD de participantes, importação, edição e ações em massa estão implementados.
- Fluxo de QR robustecido com backfill de token para participantes antigos.

**Parcial**
- Regras de filtro por modo (`guest`, `assigned_only`, setor/cargo) são complexas e suscetíveis a regressão sem testes automáticos.

**Pendente / risco**
- Falta suíte de regressão específica para combinações de filtros e perfis de acesso.

### Workforce Ops
**Consolidado**
- Domínio evoluído: cargos, alocações, importação setorial/por cargo, ACL setorial, configuração operacional individual.
- Integração com check-in, refeições e conector financeiro de custos.

**Parcial**
- Código usa `columnExists` para comportar schema antigo/novo em tempo de execução.

**Pendente / risco**
- Estratégia de compatibilidade reduz quebra imediata, mas pode mascarar dívida de migração e divergência de ambiente.

### Meals Control
**Consolidado**
- Tela operacional dedicada implementada e integrada ao backend de saldo/consumo.
- Regra de limite diário respeita configuração do membro (`workforce_member_settings`).

**Parcial**
- Depende fortemente da presença de estrutura de workforce completa no banco.

**Pendente / risco**
- Em ambiente com migrations incompletas, comportamento degrada e pode gerar inconsistência operacional.

### Financial Layer
**Consolidado**
- CRUD de gateways por organizador funcional; seleção de principal, ativação/inativação e teste de conexão (modo validação) operando.
- FinanceTab frontend já remodelada para múltiplos providers.

**Parcial**
- Coexistência de nomenclaturas `is_primary` e `is_principal` por compatibilidade.

**Pendente / risco**
- Falta hardening estrutural no schema consolidado para impedir inconsistências lógicas de prioridade/estado entre gateways.

---

## 3. Itens consolidados

1. **Arquitetura operacional por módulos já utilizável em produção assistida** (Dashboard, Participants, Workforce, Meals, Finance, Settings).
2. **Isolamento multi-tenant funcional na maioria dos fluxos críticos** por `organizer_id` derivado de token.
3. **Camada financeira por tenant entregue em nível de configuração e conectividade de gateway**.
4. **Hub de equipe maduro para operação real** (alocação, setor, cargo, parâmetros de trabalho).
5. **Auditoria com imutabilidade estrutural no banco**.

---

## 4. Fragilidades reais ainda existentes

1. **Auth hardening incompleto**: bypass de emergência em login ainda ativo.
2. **JWT com divergência de arquitetura declarada vs implementada** (HS256 real x discurso RS256).
3. **Drift de schema** entre dump consolidado e evolução recente de workforce/finance.
4. **Cobertura de auditoria desigual** entre domínios com mutação de dados.
5. **Riscos pontuais de escopo tenant** em fluxos de borda não totalmente blindados.
6. **Compatibilidade excessiva de contratos** (aliases legados) aumentando risco de regressão silenciosa.

---

## 5. Débitos técnicos priorizados

### Prioridade alta
1. Remover bypass de emergência de autenticação e revisar segurança de credenciais.
2. Definir oficialmente a estratégia JWT e alinhar código + documentação (uma única verdade técnica).
3. Consolidar baseline de banco (aplicar migrations pendentes e regenerar `schema_real.sql`).
4. Revisão dirigida de multi-tenant em fluxos de borda (ticket transfer e similares).

### Prioridade média
5. Instituir padrão mínimo obrigatório de auditoria para endpoints de mutação.
6. Normalizar contratos da camada financeira (eliminar dualidade de campos no médio prazo).
7. Criar testes de contrato backend/frontend para Settings Tabs e FinanceTab.
8. Criar regressão de ACL setorial para Workforce (manager/staff/organizer/admin).

### Prioridade baixa
9. Extrair mais regras de controllers extensos para services testáveis.
10. Melhorar observabilidade com logs estruturados por domínio.

---

## 6. Riscos de regressão

1. Mudanças em auth sem plano de migração de tokens podem derrubar sessões ativas.
2. Novas rotas sem checklist tenant podem reabrir vazamento entre organizadores.
3. Deploy com banco parcialmente migrado pode quebrar Workforce/Meals/Finance de forma silenciosa.
4. Alterações de payload sem testes de contrato podem quebrar Settings/Finance no frontend.
5. Refactors em dashboard podem mascarar inconsistência de KPIs operacionais.

---

## 7. Recomendações práticas por prioridade

### Prioridade alta (execução imediata)
- Remover bypass do login e validar com testes de autenticação (login válido/inválido/refresh/logout).
- Formalizar ADR de segurança para JWT e aplicar convergência técnica/documental.
- Rodar pipeline de migração em banco limpo + atualizar `schema_real.sql` no mesmo PR.
- Fazer checklist de blindagem tenant em toda rota de mutação/listagem crítica.

### Prioridade média
- Criar suíte de regressão mínima para: Settings tabs, FinanceTab, ACL setorial de Workforce, Meals por limite.
- Definir contrato canônico para financeiro e manter camada de compatibilidade temporária com prazo de remoção.
- Exigir auditoria nos endpoints críticos com padrão único de metadados.

### Prioridade baixa
- Modularizar controllers grandes (Participants/Workforce) em serviços por subdomínio.
- Evoluir monitoramento de erros por rota e por tenant para resposta operacional mais rápida.

---

## 8. Próximas frentes sugeridas

1. **PR de Hardening de Auth/Security** (remoção bypass + alinhamento JWT + revisão de segredos).
2. **PR de Consolidação de Schema** (migrations completas + dump atualizado + validação de drift).
3. **PR de Testes de Contrato/ACL** (Settings/Finance/Workforce/Meals).
4. **PR de Auditoria Transversal** (cobertura mínima obrigatória por domínio crítico).
5. **PR de Refactor Estrutural** (quebra incremental de controllers de alta complexidade).

---

## 9. Resumo executivo final

A base funcional do EnjoyFun está **mais madura e operável** do que nas fases anteriores, com avanços claros em operações de equipe, refeições e camada financeira por organizador. O próximo ciclo não deve priorizar expansão de funcionalidades antes de concluir **hardening de segurança, convergência de schema e blindagem de regressão**.

Em resumo: o sistema está **forte em capacidade operacional**, mas ainda **vulnerável em previsibilidade de produção** enquanto persistirem bypass de auth, drift de banco e contratos de API com dualidade legada.

---

## Validações manuais necessárias (explicitamente pendentes)

1. Validar em ambiente real se todas as migrations de Workforce/Finance estão aplicadas antes de fechamento de hardening.
2. Executar teste funcional ponta a ponta da FinanceTab com múltiplos gateways por tenant (sem transação financeira real).
3. Verificar coerência dos KPIs de estacionamento do dashboard versus operação de portaria.
4. Confirmar em QA o comportamento de ACL setorial para manager/staff em importação, alocação e leitura.
