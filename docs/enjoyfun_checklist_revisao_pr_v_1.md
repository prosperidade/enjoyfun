# EnjoyFun — Checklist Oficial de Revisão por PR v1

## Objetivo
Definir um checklist oficial para revisar qualquer PR da EnjoyFun antes de merge, garantindo consistência com a visão oficial do produto, segurança técnica, multi-tenant, arquitetura e baixo retrabalho.

Este checklist deve ser usado em toda PR feita por:
- equipe interna
- Codex
- qualquer fluxo assistido por IA

---

## 1. Regra central

Nenhuma PR deve ser aprovada apenas porque “funcionou localmente”.

Toda PR precisa ser validada em 4 dimensões:
1. produto
2. arquitetura
3. segurança/dados
4. impacto operacional

---

## 2. Checklist geral obrigatório

### 2.1 Escopo da PR
- [ ] A PR respeita o limite de até 3 entregas?
- [ ] O objetivo da PR está claro?
- [ ] O escopo está compatível com a fase/sprint atual?
- [ ] A PR não mistura domínios desnecessariamente?

### 2.2 Compatibilidade com a direção oficial
- [ ] A PR respeita os documentos oficiais da EnjoyFun?
- [ ] Não cria arquitetura paralela à definida?
- [ ] Não contradiz a separação entre Branding, Channels, AI Config e Financeiro?
- [ ] Não contradiz a separação entre Guest e Workforce?
- [ ] Não reintroduz duplicação que estamos tentando remover?

---

## 3. Checklist de segurança e multi-tenant

### 3.1 Auth e autorização
- [ ] A PR respeita o contrato oficial de autenticação?
- [ ] Não introduz novo fallback inseguro?
- [ ] Não confia em organizer_id vindo do body?
- [ ] Usa o payload autenticado corretamente?

### 3.2 Tenant isolation
- [ ] Toda query sensível respeita `organizer_id`?
- [ ] Leitura por ID está escopada ao tenant?
- [ ] Update está escopado ao tenant?
- [ ] Delete está escopado ao tenant?
- [ ] Não há risco óbvio de vazamento entre organizadores?

### 3.3 Dados sensíveis
- [ ] Credenciais/tokens não estão expostos no código?
- [ ] Dados sensíveis estão preparados para armazenamento seguro?
- [ ] Não há log indevido de tokens ou segredos?

---

## 4. Checklist de banco de dados

### 4.1 Consistência de schema
- [ ] A mudança no banco está refletida no schema oficial?
- [ ] Não cria tabela/coluna fora da modelagem oficial sem necessidade?
- [ ] Não gera divergência entre scripts principais e auxiliares?

### 4.2 Modelagem
- [ ] A modelagem respeita o domínio correto?
- [ ] Não joga configuração financeira dentro de branding?
- [ ] Não joga workforce dentro de guests por conveniência?
- [ ] A tabela/coluna criada tem finalidade clara?
- [ ] Há `organizer_id` quando a entidade é multi-tenant?

### 4.3 Índices e performance
- [ ] A PR precisa de índice novo?
- [ ] Se a query será usada em dashboard/operação, a performance foi considerada?
- [ ] Não cria query muito pesada sem necessidade?

---

## 5. Checklist de backend

### 5.1 Controllers
- [ ] O controller continua fino?
- [ ] O controller não acumulou regra demais?
- [ ] O controller delega para service quando necessário?

### 5.2 Services
- [ ] A regra de negócio foi colocada no service correto?
- [ ] Não foi criada lógica duplicada em outro domínio?
- [ ] O service tem responsabilidade clara?

### 5.3 Queries
- [ ] A query está no lugar certo?
- [ ] Respeita filtros de tenant/evento/período?
- [ ] Está preparada para filtros que o dashboard ou módulo exigem?

### 5.4 Compatibilidade
- [ ] A PR preserva rotas e contratos importantes quando a mudança é transicional?
- [ ] Não quebra módulos antigos sem plano de migração?

---

## 6. Checklist de frontend

### 6.1 Consistência com o produto
- [ ] A UI respeita o módulo correto?
- [ ] Não mistura Dashboard Executivo com Operacional na mesma tela?
- [ ] Não expande Guest de forma errada?
- [ ] Não coloca settings genérico sem separação por domínio?

### 6.2 Componentização
- [ ] A PR reutiliza componentes quando faz sentido?
- [ ] Não repete layout/lógica visual desnecessariamente?
- [ ] O componente criado tem responsabilidade clara?

### 6.3 UX operacional
- [ ] A tela ajuda decisão ou operação real?
- [ ] O fluxo está simples para o usuário certo?
- [ ] Há clareza sobre estados vazios, loading e erro?

---

## 7. Checklist de KPIs e dashboard

### 7.1 Métricas
- [ ] O KPI usado já existe no documento oficial de KPIs?
- [ ] A fórmula do KPI é a oficial?
- [ ] O nome da métrica está consistente?
- [ ] O cálculo foi feito no backend quando necessário?

### 7.2 Dashboard
- [ ] O card responde a uma pergunta de negócio real?
- [ ] O card pertence ao dashboard certo (executivo, operacional ou analítico)?
- [ ] O card não foi criado antes da base de dados necessária?

---

## 8. Checklist de financeiro

### 8.1 Domínio financeiro
- [ ] A PR respeita o Financial Layer oficial?
- [ ] Não mistura gateway com settings genérico?
- [ ] A operação é por tenant?
- [ ] A comissão da EnjoyFun está prevista corretamente?

### 8.2 Gateways
- [ ] O provider está sendo tratado como um gateway do organizador?
- [ ] Existe suporte a ativo/inativo, principal e teste de conexão?
- [ ] Não há acoplamento indevido entre um gateway e outro?

---

## 9. Checklist de Participants e Workforce

### 9.1 Participants
- [ ] A PR respeita a distinção entre participantes e workforce?
- [ ] Não força tudo dentro do Guest atual?
- [ ] Está coerente com categorias oficiais?

### 9.2 Workforce
- [ ] Turno, cargo e setor estão tratados como operação real?
- [ ] Refeição está ligada ao domínio correto?
- [ ] Presença e check-in/out estão em caminho coerente?

---

## 10. Checklist de risco de retrabalho

- [ ] Esta PR cria algo que será descartado logo?
- [ ] Esta PR aumenta acoplamento futuro?
- [ ] Esta PR empurra uma decisão estrutural sem resolver a base?
- [ ] Esta PR cria atalhos que vão dificultar a fase seguinte?

---

## 11. Checklist final de merge

Antes do merge, o revisor deve responder:

1. Esta PR fortalece a EnjoyFun ou só adiciona código?
2. Esta PR respeita a arquitetura oficial?
3. Esta PR preserva multi-tenant e segurança?
4. Esta PR reduz retrabalho ou aumenta retrabalho?
5. Esta PR está realmente pronta para entrar na base?

Se alguma resposta for “não” ou “não sei”, a PR não deve ser aprovada sem ajuste.

---

## 12. Resultado esperado

Com este checklist, a EnjoyFun passa a revisar PRs com critérios oficiais, em vez de apenas “parece bom” ou “funcionou no teste”.

Isso reduz:
- conflito conceitual
- vazamento multi-tenant
- duplicação
- retrabalho
- desvio da visão oficial do produto

