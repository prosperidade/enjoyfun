# Módulo 2 — Gestão Financeira Operacional do Evento

> Módulo independente focado no controle financeiro completo do evento: fornecedores, contratos, contas a pagar e pagas, custos por artista/categoria/setor, previsão versus realizado, cartões de consumação e dashboard consolidado.

---

## 1. Escopo do módulo

Este módulo controla tudo que envolve **dinheiro** dentro de um evento:

- Fornecedores e contratos
- Centro de custo e categoria de custo
- Contas a pagar e contas pagas (incluindo artistas e logística)
- Orçamento previsto vs. realizado
- Custos por artista, por categoria, por setor
- Integração com cartões e consumação
- Dashboard financeiro consolidado
- Exportação para fechamento financeiro do evento
- Importação em lote via CSV / XLSX

---

## 2. Entidades principais

```
Evento
  ├── Orçamento do evento (budget)
  │     └── Linhas de orçamento por categoria
  ├── Centros de custo
  ├── Categorias de custo
  ├── Fornecedores
  │     └── Contratos
  ├── Contas a pagar
  │     └── Baixas / Pagamentos realizados
  │           └── Anexos do pagamento
  └── Dashboard / Consolidado financeiro
```

---

## 3. Banco de dados

### 3.1 `event_cost_categories` — Categorias de custo

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| name | varchar | Ex: Artístico, Logística, Estrutura |
| code | varchar | Código interno de referência |
| is_active | boolean | |
| created_at | timestamp | |

**Exemplos de categorias:**

| Código | Nome |
|---|---|
| ART | Artístico (cachê) |
| LOG | Logística (voos, transfer, hotel) |
| HOS | Hospedagem |
| TRP | Transporte |
| ALI | Alimentação |
| PRD | Produção |
| SEG | Segurança |
| EST | Estrutura (palco, som, luz) |
| MKT | Marketing |
| FOR | Fornecedor geral |
| CON | Consumação |
| ADM | Administrativo |

---

### 3.2 `event_cost_centers` — Centros de custo

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| name | varchar | Ex: Palco Principal, Backstage, Bar |
| code | varchar | |
| budget_limit | decimal | **Sugestão: teto de gasto por centro** |
| notes | text | |
| created_at | timestamp | |

**Exemplos de centros de custo:**

| Código | Nome |
|---|---|
| PAL | Palco |
| BAR | Bar / Consumação |
| BAK | Backstage |
| ART | Artistas |
| CAM | Camarim |
| PRD | Produção geral |
| CRE | Credenciamento |
| SEG | Segurança |
| EST | Estrutura |

---

### 3.3 `suppliers` — Fornecedores

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| legal_name | varchar | Razão social |
| trade_name | varchar | Nome fantasia |
| document_type | enum | cpf, cnpj |
| document_number | varchar | |
| phone | varchar | |
| email | varchar | |
| contact_name | varchar | **Sugestão: nome do responsável** |
| pix_key | varchar | |
| pix_key_type | enum | **Sugestão: cpf, cnpj, email, phone, random** |
| bank_name | varchar | |
| bank_branch | varchar | |
| bank_account | varchar | |
| bank_account_type | enum | **Sugestão: checking, savings** |
| category | varchar | **Sugestão: tipo de fornecedor (som, luz, alimentação…)** |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.4 `supplier_contracts` — Contratos com fornecedores *(sugestão)*

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| supplier_id | uuid FK | |
| contract_number | varchar | |
| description | varchar | Objeto do contrato |
| total_amount | decimal | Valor total contratado |
| signed_at | date | |
| valid_until | date | |
| status | enum | draft, signed, active, completed, cancelled |
| file_url | varchar | PDF do contrato |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.5 `event_budget` — Orçamento do evento *(sugestão)*

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| total_budget | decimal | Orçamento total do evento |
| artistic_budget | decimal | Reserva para artístico |
| logistics_budget | decimal | Reserva para logística |
| structure_budget | decimal | Reserva para estrutura |
| marketing_budget | decimal | Reserva para marketing |
| contingency_budget | decimal | **Sugestão: reserva para imprevistos** |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.6 `event_budget_lines` — Linhas do orçamento *(sugestão)*

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| category_id | uuid FK | |
| cost_center_id | uuid FK | |
| description | varchar | |
| budgeted_amount | decimal | Valor previsto |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.7 `event_payables` — Contas a pagar

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| supplier_id | uuid FK nullable | Fornecedor (se aplicável) |
| artist_id | uuid FK nullable | Artista (se for cachê ou logística) |
| contract_id | uuid FK nullable | **Sugestão: vínculo com contrato** |
| category_id | uuid FK | Categoria de custo |
| cost_center_id | uuid FK | Centro de custo |
| source_type | enum | supplier, artist, logistics, internal |
| source_id | uuid nullable | ID da origem |
| description | varchar | |
| amount | decimal | Valor total previsto |
| due_date | date | Vencimento |
| paid_amount | decimal | Valor já pago |
| remaining_amount | decimal | **Sugestão: campo calculado ou virtual** |
| paid_at | datetime | |
| payment_method | enum | pix, ted, boleto, cash, credit_card, other |
| status | enum | pending, partial, paid, overdue, cancelled |
| is_recurrent | boolean | **Sugestão: conta recorrente no evento** |
| notes | text | |
| attachment_count | int | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.8 `event_payments` — Baixas / Pagamentos realizados

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| payable_id | uuid FK | |
| payment_date | date | |
| amount | decimal | |
| payment_method | enum | pix, ted, boleto, cash, credit_card, other |
| reference_number | varchar | Comprovante / número de operação |
| receipt_url | varchar | **Sugestão: URL do comprovante** |
| paid_by | uuid FK | **Sugestão: quem efetuou o pagamento** |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.9 `payment_attachments` — Anexos de pagamento *(sugestão)*

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| payment_id | uuid FK | |
| payable_id | uuid FK | |
| file_type | enum | receipt, invoice, nf, contract, other |
| file_name | varchar | |
| file_url | varchar | |
| mime_type | varchar | |
| size_bytes | int | |
| uploaded_by | uuid FK | |
| created_at | timestamp | |

---

### 3.10 `financial_import_batches` — Importações CSV/XLSX financeiras

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| file_name | varchar | |
| import_type | enum | payables, payments, suppliers, budget_lines |
| status | enum | pending, processing, done, failed |
| total_rows | int | |
| success_rows | int | |
| failed_rows | int | |
| created_by | uuid FK | |
| created_at | timestamp | |

### 3.11 `financial_import_rows` — Linhas da importação

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| batch_id | uuid FK | |
| row_number | int | |
| raw_payload_json | jsonb | |
| resolved_entity_id | uuid nullable | |
| status | enum | pending, success, failed, skipped |
| error_message | text | |
| created_at | timestamp | |

---

## 4. Views e consolidados sugeridos

> Essas views podem ser geradas como queries, views materializadas ou endpoints de API.

### 4.1 `v_event_financial_summary` — Resumo financeiro do evento

```sql
-- Por evento:
total_budgeted     -- Total previsto no orçamento
total_committed    -- Total em contas a pagar (qualquer status)
total_paid         -- Total efetivamente pago
total_pending      -- Total pendente (committed - paid)
total_overdue      -- Total vencido não pago
balance_remaining  -- Total orçamento - total committed
```

### 4.2 `v_cost_by_category` — Custo por categoria

```sql
-- Por evento + categoria:
category_name, budgeted_amount, committed_amount, paid_amount, pending_amount
```

### 4.3 `v_cost_by_cost_center` — Custo por centro de custo

```sql
-- Por evento + centro de custo:
cost_center_name, budget_limit, committed_amount, paid_amount, remaining_limit
```

### 4.4 `v_cost_by_artist` — Custo por artista

```sql
-- Por evento + artista:
artist_name, cache_amount, logistics_cost, consumption_cost, total_cost
```

### 4.5 `v_payables_overdue` — Contas vencidas

```sql
-- Por evento:
payable_id, description, supplier_name, due_date, amount, days_overdue
```

---

## 5. UI / UX

### 5.1 Abas do módulo

```
[ Resumo ]  [ Contas a Pagar ]  [ Pagamentos ]  [ Fornecedores ]
[ Por Categoria ]  [ Por Centro de Custo ]  [ Por Artista ]  [ Importação ]  [ Exportação ]
```

---

### 5.2 Dashboard financeiro — Cards de resumo

| Card | Valor exibido |
|---|---|
| Total previsto | Soma do orçamento aprovado |
| Total comprometido | Soma de todas as contas a pagar |
| Total pago | Soma dos pagamentos realizados |
| Total pendente | Comprometido - pago |
| Contas vencidas | Total de payables com status overdue |
| Custo artístico | Soma por categoria ART |
| Custo logístico | Soma por categoria LOG |
| Saldo disponível | Orçamento - comprometido |

---

### 5.3 Gráficos sugeridos

- **Previsto vs. Realizado** — barra comparativa por categoria
- **Custo por categoria** — pizza ou donut
- **Custo por artista** — ranking horizontal
- **Pendências por vencimento** — timeline de vencimentos futuros
- **Evolução de pagamentos** — linha por semana/mês
- **Custo por centro de custo** — barra empilhada

---

### 5.4 Tela: Contas a Pagar

**Colunas:**

| Descrição | Fornecedor / Artista | Categoria | Centro de Custo | Vencimento | Valor | Pago | Status | Ações |
|---|---|---|---|---|---|---|---|---|

**Filtros:**
- Status (pendente, parcial, pago, vencido, cancelado)
- Categoria de custo
- Centro de custo
- Fornecedor
- Artista
- Período (vencimento)

**Ações por linha:** Registrar pagamento · Editar · Anexar comprovante · Cancelar

---

### 5.5 Tela: Fornecedores

**Colunas:**

| Nome | CNPJ/CPF | Contato | Categoria | Contratos | Total a Pagar | Total Pago | Ações |
|---|---|---|---|---|---|---|---|

**Ações:** Ver contratos · Ver pagamentos · Editar · Inativar

---

### 5.6 Tela: Por Artista

**Colunas:**

| Artista | Cachê | Log. Chegada | Log. Saída | Hotel | Consumação | Total | Status Pagamento |
|---|---|---|---|---|---|---|---|

---

### 5.7 Fluxo de lançamento de conta a pagar

```
1. Selecionar fonte (fornecedor / artista / logística / interno)
2. Selecionar ou cadastrar fornecedor/artista
3. Preencher: descrição, valor, vencimento, categoria, centro de custo
4. Vincular contrato (opcional)
5. Salvar como pendente
6. Registrar pagamento quando efetuado (parcial ou total)
7. Anexar comprovante
```

---

### 5.8 Fluxo de importação CSV / XLSX

```
1. Selecionar tipo (contas a pagar, pagamentos, fornecedores, linhas de orçamento)
2. Upload CSV ou XLSX
3. Preview com validação por linha
4. Indicação de erros: fornecedor não encontrado, campo inválido, duplicata
5. Confirmação e processamento
6. Relatório: X importados · Y falhas
```

**Campos do CSV de contas a pagar:**

```
descricao, fornecedor_documento, artista_nome, categoria_codigo,
centro_custo_codigo, valor, vencimento, metodo_pagamento, observacoes
```

**Campos do CSV de fornecedores:**

```
razao_social, nome_fantasia, cnpj_cpf, telefone, email, pix_chave,
banco, agencia, conta, tipo_conta, categoria, observacoes
```

---

### 5.9 Exportação

| Exportação | Formato | Conteúdo |
|---|---|---|
| Fechamento do evento | XLSX | Todas as contas + status final |
| Contas a pagar abertas | CSV | Pendentes e vencidas |
| Custo por artista | XLSX | Cachê + logística + consumação |
| Custo por categoria | CSV | Previsto vs. realizado |
| Relatório para financeiro | XLSX | Resumo consolidado |

---

## 6. Regras de negócio

1. **Evento é o filtro principal** — tudo isolado por `event_id` + `organizer_id`
2. **Todo lançamento deve ter categoria e centro de custo** — campos obrigatórios
3. **Pagamento pode ser parcial** — status "partial" até quitar o total
4. **Conta vencida** — status "overdue" calculado automaticamente (due_date < hoje e status != paid)
5. **Cachê do artista** é uma conta a pagar com `source_type = artist`
6. **Custos logísticos do artista** alimentam o financeiro como `source_type = logistics`
7. **Consumação é benefício operacional** — aparece nos custos mas não é pagamento a fornecedor
8. **Importação nunca insere sem preview e validação**
9. **Comprovante de pagamento** deve ser obrigatório para pagamentos acima de valor configurável
10. **Conta cancelada** não pode ser excluída — apenas marcada como `cancelled` com justificativa
11. **Orçamento por centro de custo** pode ter teto configurável com alerta de estouro

---

## 7. Integrações previstas

| Integração | Descrição |
|---|---|
| Módulo Logística (Módulo 1) | Custos de logística do artista criam contas a pagar automaticamente |
| Cartões / POS | Consumação dos cartões gera lançamento no financeiro por artista |
| Storage de arquivos | Upload de NF, contratos e comprovantes |
| Exportação CSV/XLSX | Fechamento e relatórios para contabilidade |
| **Sugestão: Assinatura digital** | Contratos assinados eletronicamente direto na plataforma |
| **Sugestão: Open Banking / PIX API** | Confirmar pagamento PIX automaticamente via webhook |
| **Sugestão: NF-e / NFS-e** | Vincular nota fiscal ao lançamento |
| **Sugestão: Financeiro do organizer** | Integração futura com caixa geral do organizador |

---

## 8. Perfis de acesso sugeridos

| Perfil | Acesso |
|---|---|
| **Financeiro** | Visualização e edição completa de todos os módulos |
| **Produção** | Visualização de resumo · lançamento de contas · sem acesso a pagamentos |
| **Operação de bar** | Apenas emissão e consulta de cartões de consumação |
| **Coordenação geral** | Dashboard consolidado · custo por artista · pendências críticas |
| **Somente leitura** | Visualização do dashboard sem edição |

---

## 9. Ordem de implementação sugerida

| Fase | Entrega |
|---|---|
| **Fase 1** | `event_cost_categories` + `event_cost_centers` · configuração base |
| **Fase 2** | `suppliers` + `supplier_contracts` · cadastro de fornecedores |
| **Fase 3** | `event_payables` · contas a pagar + lançamento manual |
| **Fase 4** | `event_payments` + `payment_attachments` · baixas e comprovantes |
| **Fase 5** | `event_budget` + `event_budget_lines` · orçamento previsto |
| **Fase 6** | Views consolidadas · previsto vs. realizado · custo por artista/categoria |
| **Fase 7** | Dashboard financeiro com cards e gráficos |
| **Fase 8** | `financial_import_batches` · importação CSV/XLSX |
| **Fase 9** | Exportação · fechamento financeiro do evento |

---

## 10. Sugestões adicionais não previstas no documento original

1. **`supplier_contracts`** — tabela de contratos separada do fornecedor, com vínculo por evento
2. **`event_budget` e `event_budget_lines`** — orçamento previsto por evento com teto por categoria
3. **`budget_limit` no centro de custo** — alerta visual quando comprometido exceder o teto
4. **`contingency_budget`** — reserva para imprevistos no orçamento do evento
5. **`contact_name` no fornecedor** — nome do responsável pelo fornecedor
6. **`pix_key_type`** — tipo da chave PIX (CPF, CNPJ, e-mail, telefone, aleatória)
7. **`receipt_url` no pagamento** — URL direta do comprovante para consulta rápida
8. **`paid_by` no pagamento** — rastreabilidade de quem efetuou cada baixa
9. **`payment_attachments`** — tabela dedicada a anexos de pagamento (NF, comprovante, contrato)
10. **`remaining_amount` no payable** — campo calculado para facilitar lógica de parcelamento
11. **`is_recurrent`** — flag para contas que se repetem entre eventos (ex: fornecedor fixo)
12. **`v_payables_overdue`** — view de vencidas com campo `days_overdue` para priorização
13. **Assinatura digital de contratos** — eliminar papel e acelerar formalização com fornecedores
14. **Webhook PIX** — confirmação automática de pagamento via Open Banking
15. **Alerta de estouro de orçamento** — notificação quando comprometido ultrapassa o previsto

---

*Módulo 2 — Gestão Financeira Operacional do Evento · EnjoyFun Blueprint*
