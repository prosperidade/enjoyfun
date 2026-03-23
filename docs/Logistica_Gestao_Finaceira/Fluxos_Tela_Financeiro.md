# Fluxos de Tela — Módulo 2: Gestão Financeira Operacional do Evento

> Stack: Node.js / TypeScript  
> Convenção: cada tela documenta layout, ações, estados e transições

---

## Mapa de navegação

```
/events/:eventId/financial
  ├── /dashboard               → Resumo financeiro (tela inicial)
  ├── /payables                → Contas a pagar
  │     └── /:payableId        → Detalhe da conta + pagamentos
  ├── /payments                → Histórico de pagamentos realizados
  ├── /suppliers               → Fornecedores
  │     └── /:supplierId       → Detalhe do fornecedor
  ├── /contracts               → Contratos (por evento)
  ├── /budget                  → Orçamento previsto vs. realizado
  ├── /by-category             → Custo por categoria
  ├── /by-cost-center          → Custo por centro de custo
  ├── /by-artist               → Custo por artista
  ├── /overdue                 → Contas vencidas
  ├── /import                  → Importação CSV / XLSX
  └── /export                  → Exportação e fechamento
```

---

## TELA 01 — Dashboard Financeiro

**Rota:** `/events/:eventId/financial/dashboard`  
**Objetivo:** Visão executiva do estado financeiro do evento em tempo real.

---

### Layout

```
[Header: Financeiro — Evento X · 15/06/2025]
[Subtítulo: Atualizado há 2 minutos]   [🔄 Atualizar]

━━━━━━━━━━━━━━ CARDS DE RESUMO (linha 1) ━━━━━━━━━━━━━━

┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ PREVISTO    │ │ COMPROMETIDO│ │ PAGO        │ │ PENDENTE    │
│ R$ 500.000  │ │ R$ 420.000  │ │ R$ 310.000  │ │ R$ 110.000  │
│             │ │ 84% do prev.│ │ 73,8% prev. │ │ 26,2% comp. │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘

┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ VENCIDOS    │ │ SALDO LIVRE │ │ ARTÍSTICO   │ │ LOGÍSTICA   │
│ R$ 15.000   │ │ R$ 80.000   │ │ R$ 180.000  │ │ R$ 75.000   │
│ 2 contas    │ │ 16% do prev.│ │ comprometido│ │ comprometido│
│ [🔴 Ver]   │ │             │ │             │ │             │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘

━━━━━━━━━━━━━━ GRÁFICOS ━━━━━━━━━━━━━━

[Coluna esquerda — 60%]          [Coluna direita — 40%]

Previsto vs. Realizado           Custo por Categoria
(barra comparativa por cat.)     (donut com legenda)

━━━━━━━━━━━━━━

Pendências próximas (7 dias)     Custo por Artista (ranking)
┌────────────────────────────┐   ┌──────────────────────────┐
│ Vence em 2 dias            │   │ Artista XYZ    R$ 18.700 │
│ Seg. Empresa XYZ R$ 12.000 │   │ Banda ABC      R$ 12.400 │
│                            │   │ DJ Funk Total  R$ 8.900  │
│ Vence em 5 dias            │   └──────────────────────────┘
│ Marketing dig.  R$  8.000  │
└────────────────────────────┘

[Ver todas as pendências]        [Ver relatório completo]
```

---

### Cards — comportamentos

- **VENCIDOS:** card com borda vermelha e link direto para `/overdue`
- **SALDO LIVRE:** fica vermelho se saldo < 5% do previsto
- Todos os cards são clicáveis e navegam para a respectiva tela de detalhe

---

## TELA 02 — Contas a Pagar

**Rota:** `/events/:eventId/financial/payables`

---

### Layout

```
[Header: Contas a Pagar]
[+ Nova conta]  [↑ Importar]  [↓ Exportar]

[Filtros]
Busca: [___________]
Status: [Todos ▼]   Categoria: [Todas ▼]   Centro: [Todos ▼]
Fornecedor: [Todos ▼]   Artista: [Todos ▼]
Vencimento: [De ____] [Até ____]   Ordenar: [Vencimento ▲ ▼]

[Resumo dos filtros aplicados]
Exibindo: 38 contas · R$ 420.000 total · R$ 310.000 pago · R$ 110.000 pendente

[Tabela]
Descrição             │ Fonte          │ Categoria │ Centro │ Vencimento │ Valor    │ Pago     │ Status   │ Ações
──────────────────────┼────────────────┼───────────┼────────┼────────────┼──────────┼──────────┼──────────┼───────
Locação estrutura pal.│ SomLuz Prod.   │ Estrutura │ Palco  │ 10/06      │ R$ 80.000│ R$ 40.000│ Parcial  │ ···
Cachê · Artista XYZ  │ Artista XYZ    │ Artístico │ Artis. │ 15/06      │ R$ 15.000│ R$ 0     │ Pendente │ ···
Segurança noturna     │ Seg. Total Ltda│ Segurança │ Geral  │ 05/06      │ R$ 12.000│ R$ 0     │ VENCIDO  │ ···
```

**Badge de status:**
- `Pendente` → cinza
- `Parcial` → amarelo
- `Pago` → verde
- `VENCIDO` → vermelho (bold)
- `Cancelado` → tachado / cinza claro

**Ações por linha (menu `···`):**
- Registrar pagamento
- Ver detalhes e histórico
- Editar
- Anexar comprovante / NF
- Cancelar

---

### Drawer: Nova Conta a Pagar

```
Origem *
( ) Fornecedor   ( ) Artista   ( ) Logística   ( ) Interno

[Se Fornecedor]
  Fornecedor *  [buscar...] [+ cadastrar novo]
  Contrato      [selecionar contrato deste fornecedor ▼]

[Se Artista]
  Artista *  [buscar artista no evento...]

Categoria *        [selecionar ▼]
Centro de custo *  [selecionar ▼]

Descrição *        [___________________________]
Valor total *      [R$ ___________]
Vencimento *       [__/__/____]
Método pagamento   [PIX ▼]
Recorrente?        [ ] Sim

Observações        [textarea]

[Cancelar]  [Salvar]
```

---

## TELA 03 — Detalhe da Conta a Pagar

**Rota:** `/events/:eventId/financial/payables/:payableId`

---

### Layout

```
[← Voltar para contas a pagar]

[Header]
Locação de estrutura — Palco Principal
SomLuz Produções · Categoria: Estrutura · Centro: Palco Principal

[Badge: PARCIAL]  Vencimento: 10/06/2025

━━━━━━━━━ VALORES ━━━━━━━━━
┌───────────┐ ┌───────────┐ ┌───────────┐
│ Total     │ │ Pago      │ │ Restante  │
│ R$ 80.000 │ │ R$ 40.000 │ │ R$ 40.000 │
└───────────┘ └───────────┘ └───────────┘

[Registrar pagamento]  [Editar]  [Anexar arquivo]  [Cancelar conta]

━━━━━━━━━ HISTÓRICO DE PAGAMENTOS ━━━━━━━━━
Data       │ Valor     │ Método │ Comprovante       │ Registrado por │ Ações
───────────┼───────────┼────────┼───────────────────┼────────────────┼────────
01/06/2025 │ R$ 40.000 │ PIX    │ comprovante.pdf 👁 │ Ana Financeiro │ Estornar

━━━━━━━━━ ARQUIVOS ANEXADOS ━━━━━━━━━
contrato_somluz.pdf · NF_somluz_12345.pdf

━━━━━━━━━ OBSERVAÇÕES ━━━━━━━━━
[texto das notas]

━━━━━━━━━ HISTÓRICO DE ALTERAÇÕES ━━━━━━━━━
10/06 · 09:15 · Ana Financeiro — conta criada
01/06 · 14:30 · Ana Financeiro — pagamento de R$ 40.000 registrado
```

---

### Modal: Registrar Pagamento

```
Conta: Locação de estrutura — SomLuz Produções
Valor restante: R$ 40.000,00

Data do pagamento *   [__/__/____]
Valor pago *          [R$ ___________]   [Quitar total R$ 40.000]
Método *              [PIX ▼]
Nº comprovante        [_______________]
Upload comprovante    [Escolher arquivo]
Observações           [textarea]

[Cancelar]   [Confirmar pagamento]
```

**Pós-confirmação:** toast "Pagamento de R$ X registrado. Status: Pago." e atualiza badge na tela.

---

## TELA 04 — Fornecedores

**Rota:** `/events/:eventId/financial/suppliers` (ou global: `/suppliers`)

---

### Layout

```
[+ Cadastrar fornecedor]  [↑ Importar]

Busca: [___________]   Categoria: [Todas ▼]

Nome fantasia         │ CNPJ/CPF    │ Contato          │ Categoria │ Contratos │ A Pagar   │ Pago     │ Ações
──────────────────────┼─────────────┼──────────────────┼───────────┼───────────┼───────────┼──────────┼───────
SomLuz Produções      │ 12.345.../01│ Pedro Alves       │ Som e Luz │ 1         │ R$ 40.000 │ R$ 40.000│ ···
Seg. Total Ltda       │ 98.765.../01│ Carlos Segur.     │ Segurança │ 0         │ R$ 12.000 │ R$ 0     │ ···
```

---

### Drawer: Cadastrar / Editar Fornecedor

```
DADOS DA EMPRESA
  Razão social *    Nome fantasia
  Tipo doc *  [CNPJ ▼]   Número *  [__.__.___.____/____-__]
  Categoria   [som e luz]

CONTATO
  Nome do responsável   Telefone   E-mail

DADOS BANCÁRIOS
  Chave PIX   Tipo da chave [E-mail ▼]
  Banco       Agência   Conta   Tipo [Corrente ▼]

OBSERVAÇÕES  [textarea]

[Cancelar]  [Salvar]
```

---

## TELA 05 — Orçamento: Previsto vs. Realizado

**Rota:** `/events/:eventId/financial/budget`

---

### Layout

```
[Header: Orçamento do Evento]
[Editar orçamento]

━━━━━━━━━━ ORÇAMENTO GERAL ━━━━━━━━━━

Total previsto:    R$ 500.000
Total comprometido: R$ 420.000  (84%)
Total pago:        R$ 310.000  (62%)
Saldo disponível:  R$  80.000  (16%)
Reserva imprevistos: R$ 50.000

[Barra de progresso: 84% comprometido]
████████████████████████████████████░░░░░░ 84%
                                    ↑ saldo livre

━━━━━━━━━━ POR CATEGORIA ━━━━━━━━━━

Categoria    │ Previsto     │ Comprometido  │ Pago         │ Pendente     │ Variação   │ %
─────────────┼──────────────┼───────────────┼──────────────┼──────────────┼────────────┼────
Artístico    │ R$ 200.000   │ R$ 180.000    │ R$ 120.000   │ R$ 60.000    │ R$ 20.000  │ 90%
Estrutura    │ R$ 120.000   │ R$ 115.000    │ R$ 80.000    │ R$ 35.000    │ R$  5.000  │ 95%
Logística    │ R$  80.000   │ R$  75.000    │ R$ 55.000    │ R$ 20.000    │ R$  5.000  │ 93%
Marketing    │ R$  50.000   │ R$  30.000    │ R$ 30.000    │ R$  0        │ R$ 20.000  │ 60%
Segurança    │ R$  30.000   │ R$  20.000    │ R$  8.000    │ R$ 12.000    │ R$ 10.000  │ 66%
[+ Mais]

━━━━━━━━━━ POR CENTRO DE CUSTO ━━━━━━━━━━

Centro        │ Teto         │ Comprometido  │ Saldo        │ Uso %      │ Alerta
──────────────┼──────────────┼───────────────┼──────────────┼────────────┼────────
Palco         │ R$ 200.000   │ R$ 195.000    │ R$  5.000    │ 97,5%      │ 🔴
Backstage     │ R$  80.000   │ R$  60.000    │ R$ 20.000    │ 75,0%      │ 🟢
Bar           │ R$  50.000   │ R$  30.000    │ R$ 20.000    │ 60,0%      │ 🟢
Produção      │ R$ 100.000   │ R$  90.000    │ R$ 10.000    │ 90,0%      │ 🟡
```

---

## TELA 06 — Custo por Artista

**Rota:** `/events/:eventId/financial/by-artist`

---

### Layout

```
[Header: Custo por Artista]
[↓ Exportar XLSX]

Artista           │ Palco     │ Show    │ Cachê     │ Logística │ Consumação│ Total     │ Status Pag.
──────────────────┼───────────┼─────────┼───────────┼───────────┼───────────┼───────────┼────────────
Artista XYZ       │ Principal │ 22:00   │ R$ 15.000 │ R$ 3.200  │ R$   500  │ R$ 18.700 │ Pendente
Banda ABC         │ Palco 2   │ 18:00   │ R$  8.000 │ R$ 3.800  │ R$   600  │ R$ 12.400 │ Parcial
DJ Funk Total     │ Principal │ 20:00   │ R$  5.000 │ R$ 2.900  │ R$   300  │ R$  8.200 │ Pago
──────────────────┴───────────┴─────────┴───────────┴───────────┴───────────┴───────────┴────────────
TOTAL                                   │ R$ 28.000 │ R$  9.900 │ R$ 1.400  │ R$ 39.300
                                        │   71,2%   │   25,2%   │    3,6%   │  100%

[Clique em qualquer artista → abre detalhe do artista no módulo de logística]
```

---

## TELA 07 — Contas Vencidas

**Rota:** `/events/:eventId/financial/overdue`

---

### Layout

```
[Header: Contas Vencidas 🔴]
[Alerta: 2 contas vencidas · R$ 15.000 em aberto]

Descrição             │ Fornecedor      │ Venceu em  │ Dias │ Valor     │ Ações
──────────────────────┼─────────────────┼────────────┼──────┼───────────┼───────
Segurança noturna     │ Seg. Total Ltda │ 05/06/2025 │ 10d  │ R$ 12.000 │ Pagar · Editar · Cancelar
Alimentação equipe    │ Buffet SP       │ 08/06/2025 │  7d  │ R$  3.000 │ Pagar · Editar · Cancelar

[Pagar todas em lote] → abre modal de confirmação em lote
```

---

## TELA 08 — Importação CSV / XLSX

**Rota:** `/events/:eventId/financial/import`

---

### Passo 1: Tipo e arquivo

```
Tipo de importação *
( ) Contas a pagar
( ) Pagamentos realizados
( ) Fornecedores
( ) Linhas de orçamento

[Baixar modelo para este tipo]

[Arraste o arquivo aqui ou clique para selecionar]
Formatos aceitos: .csv, .xlsx · Máximo: 10 MB

[Próximo →]
```

---

### Passo 2: Preview de validação

```
Arquivo: contas_pagar_junho.csv · 30 linhas detectadas

✅ 28 válidas   ❌ 2 com erro

Linha │ Status │ Descrição        │ Fornecedor  │ Categoria │ Valor     │ Vencimento │ Erro
──────┼────────┼──────────────────┼─────────────┼───────────┼───────────┼────────────┼──────────────────
1     │ ✅     │ Locação estrutura│ SomLuz      │ Estrutura │ R$ 80.000 │ 10/06      │ —
15    │ ❌     │ Alimentação      │ —           │ —         │ —         │ 10/06      │ valor: obrigatório
22    │ ❌     │ Marketing redes  │ Agência XYZ │ Marketing │ R$  5.000 │ —          │ vencimento: obrigatório

[ ] Pular linhas com erro e importar apenas as válidas

[← Voltar]   [Confirmar importação das 28 válidas →]
```

---

### Passo 3: Resultado

```
✅ 28 contas importadas com sucesso
❌ 2 linhas ignoradas (erros de validação)

[Baixar relatório de erros .csv]   [Ver contas a pagar →]
```

---

## TELA 09 — Exportação e Fechamento

**Rota:** `/events/:eventId/financial/export`

---

### Layout

```
[Header: Exportação Financeira]

━━━━━━━━━━ RELATÓRIOS DISPONÍVEIS ━━━━━━━━━━

┌───────────────────────────────────────────────────────────────────┐
│ 📊 Fechamento Completo do Evento                                  │
│ Exporta todas as abas: resumo, contas, pagamentos, por artista,  │
│ por categoria, por centro de custo, pendências.                   │
│                         [Formato: XLSX ▼]  [↓ Exportar]         │
└───────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────┐
│ 📋 Contas a Pagar                                                 │
│ Filtros: Status [Todas ▼]  Categoria [Todas ▼]                   │
│                         [Formato: XLSX ▼]  [↓ Exportar]         │
└───────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────┐
│ 💰 Pagamentos Realizados                                          │
│ Período: [De ____] [Até ____]                                    │
│                         [Formato: CSV ▼]   [↓ Exportar]         │
└───────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────┐
│ 🎤 Custo por Artista                                              │
│ Inclui: cachê, logística, consumação e total consolidado.        │
│                         [Formato: XLSX ▼]  [↓ Exportar]         │
└───────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────┐
│ 📈 Previsto vs. Realizado (por categoria)                        │
│                         [Formato: CSV ▼]   [↓ Exportar]         │
└───────────────────────────────────────────────────────────────────┘
```

---

## Estados globais do módulo

### Loading states
- Dashboard: skeleton dos cards e gráficos
- Tabelas: skeleton de linhas
- Exportação: spinner com texto "Gerando arquivo..."

### Toasts de feedback

| Ação | Toast |
|---|---|
| Conta criada | ✅ "Conta a pagar lançada com sucesso." |
| Pagamento registrado | ✅ "Pagamento de R$ X registrado. Status: Pago." |
| Estorno realizado | ✅ "Pagamento estornado. Conta voltou para Pendente." |
| Conta cancelada | ✅ "Conta cancelada com justificativa registrada." |
| Fornecedor criado | ✅ "Fornecedor cadastrado com sucesso." |
| Estouro de teto | ⚠️ "Atenção: centro de custo Palco ultrapassará o teto." |
| Erro de validação | ❌ "Verifique os campos destacados." |
| Exportação pronta | ✅ "Arquivo gerado. [Download iniciando...]" |

### Confirmações obrigatórias

| Ação | Tipo de confirmação |
|---|---|
| Estornar pagamento | Modal + texto "Este estorno não poderá ser desfeito." |
| Cancelar conta com pagamentos | Modal + aviso de que os pagamentos precisam ser estornados antes |
| Cancelar conta sem pagamentos | Modal com campo de justificativa (obrigatório) |
| Inativar fornecedor | Modal simples de confirmação |

---

*Fluxos de Tela — Módulo 2 · EnjoyFun · v1.0*
