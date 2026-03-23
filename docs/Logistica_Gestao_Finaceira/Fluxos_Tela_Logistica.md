# Fluxos de Tela — Módulo 1: Logística Operacional de Artistas

> Stack: Node.js / TypeScript · Design system: componentes próprios  
> Convenção: cada tela documenta estado inicial, ações possíveis, estados de loading/erro/vazio e transições

---

## Mapa de navegação

```
/events/:eventId/logistics
  ├── /artists                     → Lista de artistas do evento
  │     └── /:eventArtistId        → Detalhe do artista
  │           ├── /timeline        → Linha do tempo operacional
  │           ├── /logistics       → Logística geral (voo, hotel, transfer)
  │           ├── /team            → Equipe do artista
  │           ├── /cards           → Cartões de consumação
  │           └── /files           → Arquivos e anexos
  ├── /alerts                      → Central de alertas do evento
  ├── /timeline-overview           → Visão consolidada de todos os artistas
  └── /import                      → Importação CSV / XLSX
```

---

## TELA 01 — Lista de Artistas do Evento

**Rota:** `/events/:eventId/logistics/artists`  
**Objetivo:** Visão geral operacional de todos os artistas, com status de janela em destaque.

---

### Layout

```
[Header do evento: nome, data, local]

[Barra de filtros]
  Busca: [___________________]
  Palco: [Todos ▼]   Data: [Todas ▼]   Status: [Todos ▼]   Risco: [Todos ▼]
  [+ Adicionar Artista]  [↑ Importar CSV]

[Tabela]
  Artista | Palco | Data | Horário | Soundcheck | Janela | Logística | Cartões | Ações
  --------|-------|------|---------|------------|--------|-----------|---------|------
  [foto/inicial] Nome artístico
                 Manager: nome | tel

[Paginação]
[Rodapé: X artistas · Y com alertas · Z confirmados]
```

---

### Coluna **Janela** — badge de cor

| Badge | Cor | Condição |
|---|---|---|
| ● Confortável | Verde `#16a34a` | Todos os buffers > 30 min |
| ● Atenção | Amarelo `#ca8a04` | Algum buffer 15–30 min |
| ● Apertado | Laranja `#ea580c` | Algum buffer < 15 min |
| ● Crítico | Vermelho `#dc2626` | Chegada após o show |
| ● Sem dados | Cinza `#6b7280` | Timeline incompleta |

> Click no badge abre painel lateral com resumo dos alertas daquele artista.

---

### Coluna **Logística** — ícones de status

```
✈ voo  🏨 hotel  🚗 transfer  🎤 rider
Cada ícone: verde = preenchido · cinza = pendente
```

---

### Ações por linha

- **Ver detalhe** → navega para `/artists/:eventArtistId`
- **Editar** → abre drawer lateral com form de edição rápida (horário, palco, status)
- **Lançar custo** → abre modal de novo item de logística
- **Emitir cartão** → abre modal de emissão de cartão
- **Ver alertas** → expande painel de alertas inline

---

### Estados

**Loading:** skeleton de 8 linhas na tabela  
**Vazio:** ilustração + "Nenhum artista adicionado a este evento. Adicione manualmente ou importe um CSV."  
**Erro de rede:** banner "Falha ao carregar artistas. [Tentar novamente]"  
**Filtro sem resultado:** "Nenhum artista encontrado com os filtros aplicados. [Limpar filtros]"

---

## TELA 02 — Adicionar / Editar Artista no Evento

**Trigger:** botão "Adicionar Artista" ou drawer de edição rápida  
**Formato:** drawer lateral (não modal — precisa ver a lista por baixo)

---

### Seção 1: Artista

```
[Campo de busca: "Buscar artista cadastrado..."]
   → autocomplete por stage_name / legal_name
   → opção "Cadastrar novo artista" se não encontrar

[Se novo artista — formulário expandido:]
  Nome artístico *        Nome legal *
  Tipo de doc *  Número *
  Telefone       E-mail
  Manager: nome / telefone / e-mail
  Nacionalidade  Gênero musical
```

---

### Seção 2: Apresentação

```
Data da apresentação *    Horário de início *
Duração (min)             Horário de fim  [calculado automaticamente]
Soundcheck                Camarim pronto às
Palco / Área *            Status *  [Confirmado / Pendente / Cancelado]
```

---

### Seção 3: Cachê

```
Valor do cachê *    Moeda [BRL ▼]
Status pagamento    Número do contrato
```

---

### Botões

```
[Cancelar]   [Salvar e fechar]   [Salvar e adicionar logística →]
```

---

### Validações em tempo real

- Horário de fim calculado ao sair do campo "Duração"
- Aviso se horário conflita com outro artista no mesmo palco

---

## TELA 03 — Detalhe do Artista

**Rota:** `/events/:eventId/logistics/artists/:eventArtistId`  
**Objetivo:** Hub central do artista — acesso a todas as subáreas.

---

### Header da tela

```
[← Voltar para lista]

[Avatar/Iniciais]  Nome artístico                          [Editar] [...]
                   Nome legal · Gênero · Nacionalidade
                   Manager: nome · tel · e-mail

[Badge de status: Confirmado]  [Badge de janela: 🔴 Crítico]  [Badge: 3 alertas ativos]
```

---

### Cards de resumo (linha horizontal)

```
┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│ Show         │ │ Chegada      │ │ Custo Total  │ │ Cartões      │
│ 15/Jun 22:00 │ │ 15/Jun 18:10 │ │ R$ 18.700    │ │ 3 ativos     │
│ Palco Princ. │ │ GRU · Avião  │ │ Cachê + Log. │ │ R$ 880 saldo │
└──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘
```

---

### Abas de navegação

```
[Linha do Tempo]  [Logística]  [Equipe]  [Cartões]  [Arquivos]  [Histórico]
```

---

## TELA 04 — Aba: Linha do Tempo Operacional

**Objetivo:** Visualizar e editar a jornada completa do artista com alertas inline.

---

### Subseção: Timeline visual

```
ORIGEM ANTERIOR                                              PRÓXIMO DESTINO
Festival ABC - SP                                            Aeroporto GIG - RJ

  [Pouso GRU]──50min──[Hotel]──20min──[Venue]──30min──[Soundcheck]──[PALCO 22h]──20min──[Saída]──45min──[Limite 00:10]
    18:10               19:00          20:30          21:00           22:00–23:00   23:20             00:10

  ETA: 50 min         ETA: 20 min    ETA: 30 min
  ✓ Confortável       ✓ OK           ⚠ Buffer: 8 min
                                     LARANJA
```

Cada ponto da timeline é clicável e abre edição inline do horário.

---

### Subseção: Alertas ativos

```
┌─────────────────────────────────────────────────────────────────┐
│ 🟠 APERTADO — Buffer soundcheck: 8 minutos                      │
│ Recomendação: Antecipar transfer ou reduzir soundcheck.         │
│ [Marcar como resolvido]  [Ver detalhes]                         │
└─────────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────────┐
│ 🟡 ATENÇÃO — ETA venue→aeroporto não informado                  │
│ Recomendação: Cadastrar estimativa de deslocamento.             │
│ [Cadastrar ETA]  [Marcar como resolvido]                        │
└─────────────────────────────────────────────────────────────────┘
```

---

### Subseção: Formulário de timeline

```
ORIGEM ANTERIOR
  Tipo: [Evento ▼]   Label: [Festival ABC]   Cidade: [São Paulo, SP]

CHEGADA
  Modo: [Avião ▼]   Aeroporto: [GRU]   Data/hora de pouso: [15/06 18:10]
  Hotel: [Hotel Grand]   Check-in: [15/06 19:00]

VENUE
  Chegada ao venue: [15/06 20:30]   Soundcheck: [15/06 21:00]
  Camarim pronto: [15/06 21:30]

SHOW
  Início: [15/06 22:00]  [calculado do event_artist]  (somente leitura)
  Fim: [15/06 23:00]     [calculado automaticamente]  (somente leitura)

SAÍDA
  Saída do venue: [15/06 23:20]
  Próximo destino tipo: [Aeroporto ▼]   Label: [Voo para RJ]   Cidade: [Rio de Janeiro, RJ]
  Horário limite de saída: [16/06 00:10]

OBSERVAÇÕES
  [textarea]

[Salvar timeline]  → dispara recálculo automático de alertas
```

---

### Subseção: Estimativas de deslocamento

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Rota                    │ Sem trânsito │ Com trânsito │ Buffer │ Total      │
├─────────────────────────┼──────────────┼──────────────┼────────┼────────────┤
│ Aeroporto → Venue       │ 45 min       │ 80 min       │ 15 min │ 95 min     │
│ Venue → Aeroporto       │ 40 min       │ 70 min       │ 15 min │ 85 min     │
└─────────────────────────┴──────────────┴──────────────┴────────┴────────────┘
[+ Adicionar estimativa de deslocamento]
```

---

## TELA 05 — Aba: Logística

**Objetivo:** Gerenciar voo, hotel, transfer, rider e hospitalidade.

---

### Subseção: Resumo logístico

```
CHEGADA                           SAÍDA
Data: 15/06/2025                  Data: 16/06/2025
Hora: 18:10                       Hora: 00:30
Origem: São Paulo (GRU)           Destino: Rio de Janeiro (GIG)

HOSPEDAGEM
Hotel: Hotel Grand · Rua das Flores, 100 · SP
Check-in: 15/06 19:00   Check-out: 16/06 12:00
Quarto: Suite dupla, andar alto

CAMARIM / RIDER / HOSPITALIDADE   [Editar]
[bloco de texto livre das notas]
```

---

### Subseção: Itens de logística (tabela)

```
Tipo          │ Descrição                   │ Fornecedor       │ Qtd │ Valor    │ Status
──────────────┼─────────────────────────────┼──────────────────┼─────┼──────────┼─────────
✈ Passagem    │ Voo GRU → VCP 15/06 16:00   │ Gol              │  1  │ R$ 850   │ Pendente
🏨 Hotel      │ 1 noite Hotel Grand          │ Hotel Grand      │  1  │ R$ 650   │ Pago
🚗 Transfer   │ GRU → Arena às 18h           │ Transfer SP      │  1  │ R$ 280   │ Pendente
🎤 Rider      │ 2 cx água, frutas, suco      │ —                │  1  │ R$ 120   │ Pendente
──────────────┴─────────────────────────────┴──────────────────┴─────┴──────────┴─────────
                                                          TOTAL: R$ 1.900  (Pago: R$ 650)

[+ Adicionar item]
```

---

### Modal: Adicionar / Editar item de logística

```
Tipo *         [Passagem ▼]
Fornecedor     [buscar fornecedor...]
Descrição *    [___________________]
Quantidade *   [__]   Valor unitário *  [R$ _______]
Valor total    [calculado: R$ ______]  (somente leitura)
Vencimento     [__/__/____]
Status         [Pendente ▼]
Observações    [textarea]

[Cancelar]  [Salvar]
```

---

## TELA 06 — Aba: Equipe do Artista

```
[+ Adicionar membro]

Nome            │ Função     │ Documento    │ Hotel │ Transfer │ Cartão │ Ações
────────────────┼────────────┼──────────────┼───────┼──────────┼────────┼───────
Carlos Produtor │ Produtor   │ 123.456.789  │  Sim  │  Sim     │ Ativo  │ ✏ 🗑
Ana Roadie      │ Roadie     │ 987.654.321  │  Não  │  Sim     │ —      │ ✏ 🗑
```

**Modal: Adicionar membro**
```
Nome *     Função *      Documento    Telefone
Precisa de hotel? [Sim / Não]
Precisa de transfer? [Sim / Não]
Emitir cartão agora? [Sim / Não] → se Sim, expande campos de cartão
Observações
[Cancelar]  [Salvar]
```

---

## TELA 07 — Aba: Cartões de Consumação

---

### Visão de cartões emitidos

```
[+ Emitir novo cartão]

Beneficiário      │ Tipo        │ Nº Cartão     │ Limite  │ Consumido │ Saldo  │ Status │ Ações
──────────────────┼─────────────┼───────────────┼─────────┼───────────┼────────┼────────┼───────
Artista XYZ       │ Consumação  │ CARD-2025-042 │ R$ 500  │ R$ 120    │ R$ 380 │ Ativo  │ ···
Carlos Produtor   │ Refeição    │ CARD-2025-043 │ R$ 150  │ R$ 80     │ R$ 70  │ Ativo  │ ···
Ana Roadie        │ Backstage   │ CARD-2025-044 │ R$ 100  │ R$ 100    │ R$ 0   │ Esgot. │ ···
```

**Ações do cartão:** Ver extrato · Ajustar limite · Bloquear · Cancelar

---

### Modal: Emitir cartão

```
Beneficiário *    [Artista / Membro da equipe ▼]
  → se Membro: lista os membros cadastrados

Tipo de cartão *  [Consumação ▼ | Refeição | Backstage]
Limite (R$) *     [_______]
Expira em         [__/__/____ __:__]

[Cancelar]  [Emitir cartão]
```

**Pós-emissão:** exibe card com QR code gerado + número do cartão para imprimir.

---

### Drawer: Extrato do cartão

```
CARD-2025-042 · Artista XYZ · Consumação
Limite: R$ 500  |  Consumido: R$ 120  |  Saldo: R$ 380

[Histórico de transações]
Data/hora         │ Tipo      │ Ponto de venda      │ Valor
──────────────────┼───────────┼─────────────────────┼────────
15/06 22:45       │ Consumo   │ Bar Backstage POS 3 │ - R$ 45
15/06 23:10       │ Consumo   │ Bar Backstage POS 1 │ - R$ 75
16/06 00:05       │ Ajuste    │ Produção            │ + R$ 0
──────────────────┴───────────┴─────────────────────┴────────
Total consumido: R$ 120
```

---

## TELA 08 — Aba: Arquivos

```
[↑ Fazer upload]

Tipo          │ Nome do arquivo              │ Tamanho │ Enviado em    │ Ações
──────────────┼──────────────────────────────┼─────────┼───────────────┼───────
Contrato      │ contrato_artistaxyz.pdf      │ 1.2 MB  │ 01/06 09:00   │ 👁 🗑
Rider         │ rider_tecnico_xyz.pdf        │ 450 KB  │ 05/06 14:30   │ 👁 🗑
Passagem      │ passagem_gru_vcp_15jun.pdf   │ 280 KB  │ 10/06 11:00   │ 👁 🗑
Voucher Hotel │ voucher_hotel_grand.pdf      │ 180 KB  │ 10/06 11:05   │ 👁 🗑
```

**Upload:** drag & drop ou seleção de arquivo.  
Tipos aceitos: PDF, JPG, PNG, DOCX, XLSX · Máximo: 50 MB.  
Preview de PDF inline antes de confirmar o tipo e salvar.

---

## TELA 09 — Central de Alertas do Evento

**Rota:** `/events/:eventId/logistics/alerts`  
**Objetivo:** Visão consolidada de todos os alertas de todos os artistas.

---

### Layout

```
[Header: Alertas Operacionais — Evento X · 15/06/2025]

[Filtros: Artista | Severidade | Status (Ativo/Resolvido)]

[Resumo]
┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
│ 🔴 1     │ │ 🟠 3     │ │ 🟡 4     │ │ ✅ 2     │
│ Críticos │ │ Apertado │ │ Atenção  │ │ Resolvid.│
└──────────┘ └──────────┘ └──────────┘ └──────────┘

[Lista de alertas, ordenada por severidade]

┌─────────────────────────────────────────────────────────────────────┐
│ 🔴 CRÍTICO · Artista XYZ · Palco Principal                          │
│ Chegada prevista (19:20) é posterior ao início do show (19:00)      │
│ Recomendação: Avaliar alteração de horário de palco ou transfer      │
│ especial por helicóptero.                                           │
│ [Ver timeline do artista]  [Marcar como resolvido ✓]               │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ 🟠 APERTADO · Banda ABC · Palco 2                                   │
│ Buffer entre fim do show e saída para o aeroporto: 8 minutos        │
│ Recomendação: Transfer aguardando no backstage. Sem tempo para       │
│ meet & greet.                                                       │
│ [Ver timeline do artista]  [Marcar como resolvido ✓]               │
└─────────────────────────────────────────────────────────────────────┘
```

---

### Modal: Marcar como resolvido

```
Artista: Artista XYZ
Alerta: Chegada posterior ao show

Como foi resolvido? *
[textarea — ex: "Horário de palco antecipado para 19:30 pela produção"]

[Cancelar]  [Confirmar resolução]
```

---

## TELA 10 — Visão Consolidada da Timeline (todos os artistas)

**Rota:** `/events/:eventId/logistics/timeline-overview`  
**Objetivo:** Visão cronológica de todos os artistas do evento em um único lugar.

---

### Layout

```
[Header: Timeline Operacional · 15/06/2025]
[Filtros: Palco | Risco | Ordenar por horário de show]

Artista           │ Palco     │ Chegada  │ Venue   │ Show         │ Saída │ Próximo  │ Risco
──────────────────┼───────────┼──────────┼─────────┼──────────────┼───────┼──────────┼────────
Artista XYZ       │ Principal │ 18:10    │ 20:30   │ 22:00–23:00  │ 23:20 │ GIG      │ 🟠
Banda ABC         │ Palco 2   │ 16:00    │ 17:00   │ 18:00–19:00  │ 19:15 │ GRU      │ 🔴
DJ Funk Total     │ Principal │ 14:00    │ 15:00   │ 20:00–21:30  │ 22:00 │ Hotel    │ 🟢
Cantor Folk       │ Palco 3   │ —        │ —       │ 16:00–17:00  │ —     │ —        │ ⚪
```

Clique em qualquer linha abre detalhe do artista.

---

## TELA 11 — Importação CSV / XLSX

**Rota:** `/events/:eventId/logistics/import`

---

### Passo 1: Selecionar tipo e arquivo

```
Tipo de importação *
( ) Artistas do evento
( ) Logística de chegada/saída
( ) Linha do tempo (janela apertada)
( ) Cartões e benefícios
( ) Equipe dos artistas

[Baixar modelo CSV para este tipo]

[Arraste o arquivo aqui ou clique para selecionar]
Formatos aceitos: .csv, .xlsx · Máximo: 10 MB
```

---

### Passo 2: Preview com validação

```
Arquivo: artistas_evento_junho.csv · 12 linhas detectadas

[Resumo]
✅ 11 linhas válidas   ⚠️ 1 linha com erro

[Tabela de preview — todas as linhas]
Linha │ Status  │ Artista           │ Horário │ Palco     │ Cachê    │ Erro
──────┼─────────┼───────────────────┼─────────┼───────────┼──────────┼──────────────────
1     │ ✅ OK   │ Artista XYZ       │ 22:00   │ Principal │ R$ 15k   │ —
2     │ ✅ OK   │ Banda ABC         │ 18:00   │ Palco 2   │ R$ 8k    │ —
7     │ ❌ Erro │ DJ Funk Total     │ —       │ Principal │ R$ 5k    │ horario: obrigatório
...

[Pular linhas com erro e importar as válidas]   [Corrigir antes de importar]

[← Voltar]   [Confirmar importação →]
```

---

### Passo 3: Confirmação

```
✅ 11 artistas importados com sucesso
❌ 1 linha ignorada (DJ Funk Total — campo horário ausente)

[Baixar relatório de erros]   [Ver artistas importados →]
```

---

## Estados globais do módulo

### Loading states
- Tabelas: skeleton de linhas
- Detalhes: skeleton de cards e seções
- Upload: progress bar com percentual

### Toasts de feedback
| Ação | Toast |
|---|---|
| Artista adicionado | ✅ "Artista adicionado ao evento." |
| Timeline salva | ✅ "Linha do tempo salva. Alertas recalculados." |
| Alerta resolvido | ✅ "Alerta marcado como resolvido." |
| Cartão emitido | ✅ "Cartão CARD-2025-042 emitido com sucesso." |
| Erro de validação | ❌ "Verifique os campos destacados." |
| Erro de rede | ❌ "Falha ao salvar. Tente novamente." |

### Confirmações de exclusão
Toda exclusão exige modal de confirmação com nome do item + botão vermelho "Confirmar exclusão".  
Ações irreversíveis (cancelar artista, cancelar cartão) exigem digitar "CONFIRMAR" no campo.

---

*Fluxos de Tela — Módulo 1 · EnjoyFun · v1.0*
