# 00 — Visão Geral dos Módulos

> Documento-mãe. Fecha o desenho oficial dos módulos de Logística de Artistas e Financeiro do Evento no EnjoyFun.
> Este documento não reabre rota, stack, tipo de ID, padrão de resposta ou estratégia de integração.

---

## 1. Decisão oficial

O desenho oficial destes módulos no EnjoyFun está fechado com base em:
- roteador real em `index.php`
- padrão do projeto em `enjoyfun_naming_padroes_projeto_v_1.md`
- frontend atual em `WorkforceOpsTab.jsx`
- schema atual em `schema_current.sql`
- escopo financeiro atual em `OrganizerFinanceController.php`

As regras fechadas daqui em diante são:
- API nova em `/api`, nunca `/api/v1`
- roteamento principal por recurso raiz e subrecursos curtos
- frontend novo em React `.jsx`
- backend novo em `Controller + Helpers`
- `Services` apenas para motor reutilizável ou integração real
- banco em `snake_case`, tabelas no plural, PK numérica
- envelope padrão de resposta com `success`, `data`, `message` e `meta` opcional
- escopo por evento via `event_id` em query/body e pelo vínculo do registro

---

## 2. Módulo 1 — Logística de Artistas

### Recurso raiz
`/artists`

### Subrecursos oficiais
- `bookings`
- `logistics`
- `logistics-items`
- `timelines`
- `transfers`
- `alerts`
- `team`
- `files`
- `imports`

### Responsabilidade
O módulo controla a operação do artista no evento:
- cadastro base do artista
- vínculo do artista com o evento
- agenda operacional
- logística de chegada, hospedagem e saída
- timeline operacional
- estimativas de deslocamento
- alertas operacionais
- equipe do artista
- arquivos do artista
- importação em lote

### O que não pertence ao módulo
- contas a pagar do evento
- pagamentos do evento
- orçamento do evento
- ledger financeiro operacional

---

## 3. Módulo 2 — Financeiro do Evento

### Recurso raiz
`/event-finance`

### Subrecursos oficiais
- `categories`
- `cost-centers`
- `budgets`
- `budget-lines`
- `suppliers`
- `contracts`
- `payables`
- `payments`
- `attachments`
- `summary`
- `imports`
- `exports`

### Responsabilidade
O módulo controla o ledger operacional financeiro do evento:
- categorias de custo
- centros de custo
- orçamento do evento
- linhas de orçamento
- fornecedores
- contratos
- contas a pagar
- pagamentos
- anexos financeiros
- visão consolidada
- importação e exportação

### O que não pertence ao módulo
- timeline operacional do artista
- alertas de chegada/logística
- gestão de camarim, rider e equipe
- emissão de cartões como motor próprio

---

## 4. Integração entre módulos

Os módulos se tocam apenas nos pontos abaixo:

### 4.1 Cachê do artista
O cachê do vínculo do artista com o evento pode originar conta a pagar no módulo financeiro.

### 4.2 Custos logísticos
Itens de logística com custo financeiro podem originar contas a pagar no módulo financeiro.

### 4.3 Consumo de cartões
O custo consolidado por artista pode ler consumo dos cartões já existentes no sistema.

Fora desses pontos, os dois módulos permanecem independentes.

---

## 5. Reuso obrigatório de infraestrutura existente

### Cartões
Não serão criadas tabelas paralelas `artist_cards` ou `artist_card_transactions`.

O módulo de logística reaproveita:
- `digital_cards`
- `card_transactions`
- `event_card_assignments`

Se faltar vínculo com artista ou equipe, o ajuste deve ser feito em `event_card_assignments`.
Não se cria um segundo motor de cartão.

### Financeiro do organizer
`organizer_financial_settings` e `organizer_payment_gateways` continuam sendo infraestrutura do organizer.
Não representam o ledger operacional do evento.

`OrganizerFinanceController` não será estendido para contas a pagar e pagamentos do evento.
O domínio novo fica em `/event-finance`.

---

## 6. Lista oficial de tabelas

### Logística de artistas
- `artists`
- `event_artists`
- `artist_logistics`
- `artist_logistics_items`
- `artist_operational_timelines`
- `artist_transfer_estimations`
- `artist_operational_alerts`
- `artist_team_members`
- `artist_files`
- `artist_import_batches`
- `artist_import_rows`

### Financeiro do evento
- `event_cost_categories`
- `event_cost_centers`
- `event_budgets`
- `event_budget_lines`
- `suppliers`
- `supplier_contracts`
- `event_payables`
- `event_payments`
- `event_payment_attachments`
- `financial_import_batches`
- `financial_import_rows`

---

## 7. Documentos oficiais do pacote

### Núcleo obrigatório
- `00_Visao_Geral_Modulos.md`
- `01_Regras_Compartilhadas.md`
- `10_Logistica_Artistas_Produto.md`
- `11_Logistica_Artistas_Modelo_Dados.md`
- `12_Logistica_Artistas_API.md`
- `13_Logistica_Artistas_Fluxos_Tela.md`
- `20_Financeiro_Evento_Produto.md`
- `21_Financeiro_Evento_Modelo_Dados.md`
- `22_Financeiro_Evento_API.md`
- `23_Financeiro_Evento_Fluxos_Tela.md`
- `90_Arquitetura_EnjoyFun.md`
- `91_Integracoes_Aproveitadas.md`
- `92_Roadmap_Implementacao.md`

---

## 8. Regra final

Qualquer documento novo criado depois deste pacote deve obedecer este modelo e não pode reabrir:
- padrão de rota
- stack
- tipo de ID
- padrão de resposta
- estratégia de integração
