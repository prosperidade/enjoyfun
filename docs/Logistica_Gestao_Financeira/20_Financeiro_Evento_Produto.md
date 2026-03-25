# 20 — Financeiro do Evento · Produto

> Escopo funcional do Módulo 2 no modelo final já fechado.

---

## 1. Objetivo

Centralizar o financeiro operacional do evento em domínio próprio, separado do organizer-finance, cobrindo orçamento, fornecedores, contratos, contas a pagar, pagamentos, anexos, resumo, importação e exportação.

---

## 2. Usuários principais

- financeiro do evento
- coordenação geral
- produção com visão de custos

---

## 3. Casos de uso principais

### 3.1 Categorias e centros de custo
Estruturar classificação financeira do evento.

### 3.2 Orçamento
Definir orçamento total e linhas por categoria/centro.

### 3.3 Fornecedores
Cadastrar fornecedor e contratos do evento.

### 3.4 Contas a pagar
Lançar obrigação financeira do evento com origem operacional ou administrativa.

### 3.5 Pagamentos
Registrar pagamentos parciais, totais e estornos.

### 3.6 Anexos
Vincular comprovantes, notas e documentos da baixa.

### 3.7 Resumo executivo
Consolidar previsto, comprometido, pago, pendente e vencido.

### 3.8 Importação e exportação
Entrada e saída em lote com preview, confirmação e geração de arquivos.

---

## 4. Recurso raiz e subrecursos oficiais

### Recurso raiz
`/api/event-finance`

### Subrecursos oficiais
- `/api/event-finance/categories`
- `/api/event-finance/cost-centers`
- `/api/event-finance/budgets`
- `/api/event-finance/budget-lines`
- `/api/event-finance/suppliers`
- `/api/event-finance/contracts`
- `/api/event-finance/payables`
- `/api/event-finance/payments`
- `/api/event-finance/attachments`
- `/api/event-finance/summary`
- `/api/event-finance/imports`
- `/api/event-finance/exports`

---

## 5. Regras funcionais fechadas

- módulo novo não estende `OrganizerFinanceController` para ledger do evento
- domínio próprio em `/event-finance`
- `event_id` obrigatório em operações do evento
- toda conta a pagar exige categoria e centro de custo
- pagamento pode ser parcial
- status financeiro é calculado pelo backend
- cancelamento preserva histórico
- consumação entra no custo consolidado, mas não cria novo motor de cartões

---

## 6. O que este módulo não faz

- logística operacional do artista
- timeline operacional
- alertas de janela
- gestão de equipe do artista
- infraestrutura financeira global do organizer

---

## 7. Telas mínimas esperadas

- dashboard/resumo
- contas a pagar
- detalhe da conta
- pagamentos
- fornecedores e contratos
- orçamento e linhas
- exportação
- importação

Todas em React `.jsx`, aderentes ao frontend existente.
