# Roadmap de Implementação — Módulos 1 e 2

> Stack: Node.js / TypeScript  
> Metodologia: fases sequenciais dentro de cada módulo · os 2 módulos correm em paralelo  
> Estimativas: dias úteis de desenvolvimento de um desenvolvedor sênior full-stack

---

## Visão geral das fases

```
MÓDULO 1 — Logística de Artistas          MÓDULO 2 — Gestão Financeira
─────────────────────────────────         ─────────────────────────────
Fase 1.1 · Base de artistas               Fase 2.1 · Configuração financeira
Fase 1.2 · Logística operacional          Fase 2.2 · Fornecedores e contratos
Fase 1.3 · Janela apertada               Fase 2.3 · Contas a pagar
Fase 1.4 · Alertas automáticos           Fase 2.4 · Pagamentos e baixas
Fase 1.5 · Consumação e cartões          Fase 2.5 · Orçamento e consolidados
Fase 1.6 · Arquivos                      Fase 2.6 · Dashboard e gráficos
Fase 1.7 · Importação CSV/XLSX           Fase 2.7 · Importação e exportação
Fase 1.8 · UI/UX completo               Fase 2.8 · UI/UX completo
```

---

## MÓDULO 1 — Logística Operacional de Artistas

---

### Fase 1.1 — Base de artistas e vínculo com evento

**Estimativa:** 4–5 dias úteis  
**Entrega:** CRUD completo de artistas e vínculo com evento, listagem funcional.

#### Backend

- [ ] Criar migration: `artists`
- [ ] Criar migration: `event_artists`
- [ ] `ArtistsService` — CRUD completo com validação de documento único
- [ ] `EventArtistsService` — vínculo, listagem com filtros, detalhe consolidado
- [ ] Guards de `organizer_id` em todos os endpoints
- [ ] Testes unitários: validação de documento, conflito de vínculo

#### Frontend

- [ ] Tela 01 — Lista de artistas do evento (tabela + filtros + skeleton)
- [ ] Drawer — Adicionar/Editar artista no evento
- [ ] Cards de resumo no header do artista
- [ ] Navegação base: abas do detalhe do artista

#### Critério de aceite

- Artista criado, editado, listado e removido
- Vínculo com evento funcional com status e cachê
- `organizer_id` isolado corretamente

---

### Fase 1.2 — Logística operacional

**Estimativa:** 4–5 dias úteis  
**Entrega:** Bloco de logística geral e itens detalhados com totalizadores.

#### Backend

- [ ] Migration: `artist_logistics`
- [ ] Migration: `artist_logistics_items`
- [ ] `LogisticsService` — CRUD com constraint 1:1 por vínculo
- [ ] `LogisticsItemsService` — CRUD com cálculo automático de `total_amount`
- [ ] Endpoint de resumo de custos logísticos por artista
- [ ] Validação: item pago não pode ser removido

#### Frontend

- [ ] Tela 04 — Aba Logística: bloco geral (voo, hotel, transfer, notas)
- [ ] Tabela de itens de logística com ícones por tipo
- [ ] Modal: Adicionar/Editar item de logística
- [ ] Totalizadores: total itens, total pago, total pendente

#### Critério de aceite

- Logística geral preenchida e editável
- Itens criados com `total_amount` calculado
- Custo logístico aparece no card de custo total do artista

---

### Fase 1.3 — Linha do tempo / Janela apertada

**Estimativa:** 5–6 dias úteis  
**Entrega:** Timeline operacional completa com janelas calculadas.

#### Backend

- [ ] Migration: `artist_operational_timeline`
- [ ] Migration: `artist_transfer_estimations`
- [ ] `TimelineService` — CRUD com constraint 1:1, cálculo de `calculated_windows`
- [ ] `TransferService` — CRUD com `planned_eta_minutes` calculado
- [ ] Endpoint de visão consolidada: todos os artistas do evento
- [ ] Testes: cálculo correto de buffers e janelas

#### Frontend

- [ ] Tela 04 — Aba Timeline: formulário de timeline
- [ ] Componente de timeline visual (pontos de controle com ETAs)
- [ ] Tabela de estimativas de deslocamento
- [ ] Tela 10 — Visão consolidada da timeline de todos os artistas

#### Critério de aceite

- Timeline salva com todos os campos
- `calculated_windows` correto em todos os cenários
- Visão consolidada do evento funcional

---

### Fase 1.4 — Alertas automáticos

**Estimativa:** 4–5 dias úteis  
**Entrega:** Motor de alertas funcionando com os 4 tipos de janela e badges de cor.

#### Backend

- [ ] Migration: `artist_operational_alerts`
- [ ] `AlertCalculatorService` — lógica completa das 4 janelas
- [ ] Acionamento automático após save de timeline e transfers
- [ ] Endpoint: listar alertas por evento e por artista
- [ ] Endpoint: resolver alerta com justificativa
- [ ] Endpoint: recalcular manualmente
- [ ] Testes: todos os cenários de severidade (verde, amarelo, laranja, vermelho, cinza)

#### Frontend

- [ ] Badge de janela na lista de artistas (Tela 01) com cores corretas
- [ ] Painel de alertas na aba Timeline (Tela 04)
- [ ] Tela 09 — Central de alertas do evento
- [ ] Modal de resolução de alerta
- [ ] Cards de resumo de alertas (críticos / apertados / atenção / resolvidos)

#### Critério de aceite

- Salvar/editar timeline dispara recálculo automático
- Badge de cor correto em todos os cenários
- Resolução de alerta registra usuário e timestamp

---

### Fase 1.5 — Consumação e cartões

**Estimativa:** 4–5 dias úteis  
**Entrega:** Emissão, controle de saldo e extrato de cartões.

#### Backend

- [ ] Migration: `artist_benefits`
- [ ] Migration: `artist_cards`
- [ ] Migration: `artist_card_transactions`
- [ ] Migration: `artist_team_members`
- [ ] `CardsService` — emissão com geração de `card_number` e `qr_token`
- [ ] `TransactionsService` — consume, adjust, cancel com validação de saldo
- [ ] `TeamService` — CRUD de equipe
- [ ] Validações: saldo insuficiente, cartão não ativo
- [ ] Testes: todos os cenários de transação

#### Frontend

- [ ] Tela 06 — Aba Equipe
- [ ] Tela 07 — Aba Cartões: listagem e totalizadores
- [ ] Modal: Emitir cartão (com pós-emissão QR code)
- [ ] Drawer: Extrato do cartão com histórico de transações
- [ ] Ações: bloquear, cancelar, ajustar limite

#### Critério de aceite

- Cartão emitido com `card_number` e `qr_token` únicos
- Transação de consumo atualiza `consumed_amount` e `balance`
- Cartão bloqueado rejeita transações

---

### Fase 1.6 — Arquivos e anexos

**Estimativa:** 2–3 dias úteis  
**Entrega:** Upload, listagem e remoção de arquivos por artista.

#### Backend

- [ ] Migration: `artist_files`
- [ ] `FilesService` — upload para storage (S3 ou equivalente)
- [ ] Validações: tamanho máximo 50 MB, tipos permitidos
- [ ] Endpoint de listagem por tipo de arquivo

#### Frontend

- [ ] Tela 08 — Aba Arquivos: listagem com tipo, nome, tamanho e data
- [ ] Componente de drag & drop para upload
- [ ] Preview inline de PDF antes de confirmar
- [ ] Confirmação de exclusão

#### Critério de aceite

- Upload funcional com validação de tipo e tamanho
- Arquivo acessível via `file_url`
- Remoção com confirmação

---

### Fase 1.7 — Importação CSV / XLSX

**Estimativa:** 4–5 dias úteis  
**Entrega:** Fluxo completo de preview + confirmação para todos os tipos de importação.

#### Backend

- [ ] Migration: `artist_import_batches`
- [ ] Migration: `artist_import_rows`
- [ ] `ImportService` — parse de CSV e XLSX (biblioteca: `xlsx` / `papaparse`)
- [ ] Pipeline de validação por tipo: artists, logistics, timeline, cards, team
- [ ] Preview com token temporário (Redis, TTL 15 min)
- [ ] Processamento assíncrono pós-confirmação
- [ ] Endpoint de status e resultado do lote

#### Frontend

- [ ] Tela 11 — Importação: passo 1 (tipo + upload)
- [ ] Tela 11 — Importação: passo 2 (preview com erros por linha)
- [ ] Tela 11 — Importação: passo 3 (resultado final)
- [ ] Download do relatório de erros em CSV
- [ ] Listagem de lotes anteriores

#### Critério de aceite

- Preview nunca persiste dados
- Linhas inválidas exibidas com erros específicos por campo
- Importação processada com relatório de sucesso/falha

---

### Fase 1.8 — Polimento de UI/UX

**Estimativa:** 3–4 dias úteis  
**Entrega:** Experiência completa e consistente em todas as telas do módulo.

- [ ] Toasts de feedback em todas as ações
- [ ] Confirmações de exclusão padronizadas
- [ ] Estados de loading (skeletons em todas as tabelas e cards)
- [ ] Estados vazios com mensagens e CTAs
- [ ] Responsividade mobile (tabelas com scroll horizontal)
- [ ] Acessibilidade: labels, aria, foco de teclado
- [ ] Testes end-to-end dos fluxos principais

---

## MÓDULO 2 — Gestão Financeira Operacional do Evento

---

### Fase 2.1 — Configuração financeira base

**Estimativa:** 3–4 dias úteis  
**Entrega:** Categorias, centros de custo e orçamento configuráveis.

#### Backend

- [ ] Migration: `event_cost_categories`
- [ ] Migration: `event_cost_centers`
- [ ] Migration: `event_budget`
- [ ] Migration: `event_budget_lines`
- [ ] `CategoriesService` — CRUD com validação de código único
- [ ] `CostCentersService` — CRUD com `budget_limit` e alertas de estouro
- [ ] `BudgetService` — constraint 1:1 por evento, linhas de orçamento

#### Frontend

- [ ] Configurações: CRUD de categorias (tela simples de settings)
- [ ] Tela de centros de custo por evento
- [ ] Tela 05 — Orçamento: visão geral + por categoria + por centro de custo

#### Critério de aceite

- Categoria com código único por organizer
- Centro de custo com teto e alerta de estouro na response
- Orçamento total e por categoria editável

---

### Fase 2.2 — Fornecedores e contratos

**Estimativa:** 3–4 dias úteis  
**Entrega:** Cadastro completo de fornecedores com contratos e histórico financeiro.

#### Backend

- [ ] Migration: `suppliers`
- [ ] Migration: `supplier_contracts`
- [ ] `SuppliersService` — CRUD com validação de CNPJ/CPF (dígitos verificadores)
- [ ] `ContractsService` — CRUD + upload de PDF do contrato
- [ ] Endpoint: histórico financeiro consolidado do fornecedor

#### Frontend

- [ ] Tela 04 — Fornecedores: listagem com filtros
- [ ] Drawer: Cadastrar/Editar fornecedor (dados bancários + PIX)
- [ ] Seção de contratos no detalhe do fornecedor
- [ ] Upload do PDF do contrato

#### Critério de aceite

- CNPJ e CPF validados com dígitos verificadores
- Fornecedor com contrato vinculado ao evento
- Histórico financeiro calculado corretamente

---

### Fase 2.3 — Contas a pagar

**Estimativa:** 4–5 dias úteis  
**Entrega:** Lançamento, edição e listagem de contas a pagar com status automático.

#### Backend

- [ ] Migration: `event_payables`
- [ ] `PayablesService` — CRUD completo
- [ ] Cálculo automático de status (`calculatePayableStatus`)
- [ ] Validações: source_type, categoria ativa, centro de custo válido
- [ ] Alerta de estouro de teto do centro de custo (warning na response)
- [ ] Endpoint de contas vencidas com `days_overdue`
- [ ] Endpoint de contas a vencer nos próximos N dias
- [ ] Testes: todos os status e transições

#### Frontend

- [ ] Tela 02 — Contas a Pagar: tabela com filtros avançados e totalizadores
- [ ] Drawer: Nova Conta a Pagar
- [ ] Tela 03 — Detalhe da conta com histórico
- [ ] Tela 07 — Contas Vencidas
- [ ] Badge de status com cores por estado

#### Critério de aceite

- Status calculado automaticamente após cada operação
- Filtros funcionando por todos os campos
- Contas vencidas detectadas corretamente

---

### Fase 2.4 — Pagamentos e baixas

**Estimativa:** 4–5 dias úteis  
**Entrega:** Registro de pagamentos parciais/totais, estorno e comprovantes.

#### Backend

- [ ] Migration: `event_payments`
- [ ] Migration: `payment_attachments`
- [ ] `PaymentsService` — registro com validação de saldo devedor
- [ ] `PaymentsService.reverse()` — estorno com transação atômica
- [ ] `AttachmentsService` — upload de comprovantes e NFs
- [ ] Job cron: `markOverduePayables` (diário, 06:00)
- [ ] Testes: pagamento parcial, quitação, estorno, excesso de valor

#### Frontend

- [ ] Modal: Registrar Pagamento (com botão "Quitar total")
- [ ] Listagem de pagamentos no detalhe da conta
- [ ] Ação de estorno com confirmação
- [ ] Upload de comprovante vinculado ao pagamento
- [ ] Tela de histórico de pagamentos do evento

#### Critério de aceite

- Pagamento parcial atualiza `paid_amount` e status para `partial`
- Quitação total muda status para `paid`
- Estorno reverte atomicamente e recalcula status
- Job de overdue rodando diariamente

---

### Fase 2.5 — Orçamento, consolidados e views

**Estimativa:** 4–5 dias úteis  
**Entrega:** Relatórios financeiros por categoria, centro de custo e artista.

#### Backend

- [ ] View/query: `financial_summary` — resumo completo do evento
- [ ] View/query: `cost_by_category` — previsto vs. realizado por categoria
- [ ] View/query: `cost_by_cost_center` — uso vs. teto por centro
- [ ] View/query: `cost_by_artist` — cachê + logística + consumação
- [ ] View/query: `payables_overdue` — vencidas com `days_overdue`
- [ ] View/query: `upcoming_payables` — a vencer nos próximos N dias
- [ ] Integração: custo logístico do Módulo 1 alimenta financeiro do Módulo 2

#### Frontend

- [ ] Tela 05 — Orçamento: previsto vs. realizado (tabela completa)
- [ ] Tela 06 — Custo por artista (tabela com breakdown)
- [ ] Seção "Por categoria" no dashboard
- [ ] Seção "Por centro de custo" no dashboard

#### Critério de aceite

- Custos de logística do Módulo 1 aparecem no consolidado do Módulo 2
- Previsto vs. realizado correto para todas as categorias
- `grand_total` por artista correto (cachê + log + consumação)

---

### Fase 2.6 — Dashboard e gráficos

**Estimativa:** 3–4 dias úteis  
**Entrega:** Dashboard financeiro completo com cards e gráficos interativos.

#### Backend

- [ ] Endpoint otimizado: `GET /financial/summary` (agregação única)
- [ ] Cache de 2 minutos no dashboard (Redis) para eventos grandes

#### Frontend

- [ ] Tela 01 — Dashboard: 8 cards de resumo com comportamentos de cor
- [ ] Gráfico: Previsto vs. Realizado (barras comparativas por categoria)
- [ ] Gráfico: Custo por categoria (donut)
- [ ] Gráfico: Ranking de custo por artista (barras horizontais)
- [ ] Seção: Pendências próximas (próximos 7 dias)
- [ ] Biblioteca de gráficos: Chart.js ou Recharts

#### Critério de aceite

- Dashboard carrega em < 2s
- Todos os links dos cards navegam para a tela correta
- Gráficos responsivos e com tooltip de valores

---

### Fase 2.7 — Importação e exportação

**Estimativa:** 4–5 dias úteis  
**Entrega:** Importação CSV/XLSX com preview + exportação de relatórios.

#### Backend

- [ ] Migration: `financial_import_batches`
- [ ] Migration: `financial_import_rows`
- [ ] `FinancialImportService` — pipeline de validação por tipo
- [ ] Preview com token Redis TTL 15 min
- [ ] `ExportService` — geração de XLSX com múltiplas abas (biblioteca: `exceljs`)
- [ ] Exportações: payables, payments, by-artist, by-category, fechamento completo

#### Frontend

- [ ] Tela 08 — Importação: 3 passos (tipo → preview → resultado)
- [ ] Tela 09 — Exportação: seleção de relatório + filtros + download
- [ ] Download do relatório de erros da importação

#### Critério de aceite

- Preview correto sem persistência
- XLSX exportado com formatação adequada (header, totais, largura de colunas)
- Fechamento completo com todas as abas

---

### Fase 2.8 — Polimento de UI/UX

**Estimativa:** 3–4 dias úteis

- [ ] Toasts de feedback para todas as ações
- [ ] Aviso de estouro de teto de centro de custo (toast amarelo)
- [ ] Estados de loading em todas as telas
- [ ] Estados vazios com mensagens e CTAs
- [ ] Confirmações obrigatórias (estorno, cancelamento)
- [ ] Responsividade mobile
- [ ] Testes end-to-end dos fluxos principais

---

## Cronograma estimado (paralelo)

```
Semana    Módulo 1 (Logística)           Módulo 2 (Financeiro)
────────  ───────────────────────────    ──────────────────────────────
S1–S2     1.1 Base de artistas           2.1 Configuração financeira
S3–S4     1.2 Logística operacional      2.2 Fornecedores e contratos
S5–S6     1.3 Linha do tempo             2.3 Contas a pagar
S7        1.4 Alertas automáticos        2.4 Pagamentos e baixas (início)
S8        1.4 Alertas (finalização)      2.4 Pagamentos (finalização)
S9–S10    1.5 Consumação e cartões       2.5 Consolidados e views
S11       1.6 Arquivos                   2.6 Dashboard e gráficos
S12–S13   1.7 Importação CSV/XLSX        2.7 Importação e exportação
S14       1.8 Polimento UI/UX            2.8 Polimento UI/UX
────────  ───────────────────────────    ──────────────────────────────
TOTAL     ~14 semanas (1 dev sênior por módulo, paralelo)
          ~7 semanas (2 devs sênior full-stack, paralelo)
```

---

## Dependências entre módulos

| Módulo 1 → Módulo 2 | Quando |
|---|---|
| Custo logístico do artista cria conta a pagar no M2 | Fase 1.2 entregue antes de 2.3 |
| Consumação dos cartões aparece no consolidado financeiro | Fase 1.5 entregue antes de 2.5 |
| Custo por artista no M2 lê dados de logística do M1 | Fase 2.5 depende de 1.2 e 1.5 |

---

## Ordem de prioridade para MVP mínimo viável

Se o prazo for reduzido, esta é a ordem mínima para ter os 2 módulos funcionais em produção:

```
MVP Módulo 1                    MVP Módulo 2
1.1 Base de artistas            2.1 Categorias e centros de custo
1.2 Logística operacional       2.2 Fornecedores
1.3 Linha do tempo              2.3 Contas a pagar
1.4 Alertas automáticos         2.4 Pagamentos e baixas
                                2.6 Dashboard (simplificado)
```

Fases deixadas para após MVP: 1.5 cartões, 1.6 arquivos, 1.7 importação, 1.8 polimento, 2.5 consolidados, 2.7 exportação, 2.8 polimento.

---

*Roadmap de Implementação · EnjoyFun · Módulos 1 e 2 · v1.0*
