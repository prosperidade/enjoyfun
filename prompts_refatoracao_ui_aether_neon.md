# PROMPTS DE REFATORACAO UI — AETHER NEON DESIGN SYSTEM
## EnjoyFun V2.0 — Cada prompt e autocontido para copiar/colar

---

## DESIGN SYSTEM GLOBAL (REFERENCIA PARA TODOS OS PROMPTS)

```
TEMA: AETHER NEON (Dark Mode Imersivo)
- Background Principal: bg-slate-950 (#0B0F19)
- Acento Primario (Neon Ciano): #00F0FF (cyan-400) — acoes principais, interacoes, hover
- Acento Secundario (Violeta/IA): #8A2BE2 (purple-500) — elementos de IA, badges premium
- Superficies (Glassmorphism): bg-slate-900/50 backdrop-blur-md + border border-slate-800
- Hover sutil: border-cyan-500/20
- Tipografia: Inter ou sans-serif. Textos principais: text-slate-200. Secundarios: text-slate-400
- Gradients: from-cyan-500/10 to-purple-500/10 (fundos de cards destaque)
- Sombras Neon: shadow-[0_0_15px_rgba(0,240,255,0.15)] para elementos interativos
- Icones: Lucide React, tamanho consistente (18-20px), herdam cor do texto
- Bordas: border-slate-700/50 (padrao) ou border-cyan-500/30 (destaque)
- Botao Primario: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold
- Botao Secundario: border border-slate-700 text-slate-300 hover:border-cyan-500/50
- Botao Perigo: border border-red-500/30 text-red-400 hover:bg-red-500/10
- Status badges: green-400/10 (ativo), amber-400/10 (pendente), red-400/10 (erro), slate-400/10 (inativo)
- Tabelas: header bg-slate-800/50, rows hover:bg-slate-800/30, border-b border-slate-800/50
- Inputs: bg-slate-800/50 border-slate-700 focus:border-cyan-500 focus:ring-cyan-500/20
- Modais: bg-slate-900/95 backdrop-blur-xl border-slate-700/50 shadow-2xl
- Cards: bg-slate-900/60 backdrop-blur-md border border-slate-800/60 rounded-2xl
- Animacoes: transition-all duration-300, hover:scale-[1.02] em cards interativos
```

---

# PROMPT 01 — MASTER LAYOUT (Sidebar + Header + Content Shell)

```
MISSAO: REFATORAR O MASTER LAYOUT (DashboardLayout + Sidebar + Header)

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore o layout principal do painel de organizadores do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVOS A REFATORAR:
- frontend/src/layouts/DashboardLayout.jsx
- frontend/src/components/Sidebar.jsx

ESTADO ATUAL DO LAYOUT:
O layout atual usa bg-gray-950 com bordas gray-800. A sidebar e fixa a esquerda (w-64) com bg-gray-950, items de menu com NavLink do React Router, icones lucide-react, estado ativo em purple-600/10 com barra lateral purple-500. O header e sticky (h-16) com bg-gray-900/80 backdrop-blur-md. O conteudo principal ocupa flex-1 com padding responsivo.

ESTRUTURA DO LAYOUT (MANTER):
┌─────────────────────────────────────────┐
│ Sidebar (w-64, fixo)  │ Header (sticky) │
│ - Logo topo           │ h-16            │
│ - Menu principal      ├─────────────────┤
│ - Grupos colapsaveis  │                 │
│ - Rodape versao       │ Main Content    │
│                       │ (flex-1, scroll)│
│ (drawer no mobile)    │                 │
└───────────────────────┴─────────────────┘
+ Widget AI flutuante (bottom-right)

SIDEBAR — O QUE TEM HOJE:
- Header: Logo EnjoyFun (h-16) com imagem ou texto com shadow purple
- Menu: 31 items com icones lucide-react, filtrados por role do usuario
- 3 grupos colapsaveis: "Credenciamento" (Scanner), "Financeiro" (7 sub-items), "Vendas no Local" (Bar/Food/Shop)
- Secao "SISTEMA" com link de Settings
- Footer: "EnjoyFun v2.0" centralizado
- Cores atuais: text-gray-400, hover text-white bg-gray-800/50, ativo text-purple-400 bg-purple-600/10
- Mobile: esconde com -translate-x-full, overlay backdrop

HEADER — O QUE TEM HOJE:
- Esquerda: botao hamburger (mobile) + logo mobile
- Direita: badge de rede (online/offline com cores verde/vermelho), botao sino notificacoes, nome+role do usuario, avatar circular, botao logout
- Background: bg-gray-900/80 backdrop-blur-md border-b border-gray-800

REFATORACAO EXIGIDA — SIDEBAR:
1. Fundo: bg-slate-900/50 backdrop-blur-md com border-r border-slate-800/60
2. Logo: Adicionar um glow sutil ciano (shadow-[0_0_12px_rgba(0,240,255,0.3)])
3. Menu items inativos: text-slate-400 hover:text-slate-200 hover:bg-slate-800/40
4. Menu item ativo: text-cyan-400 bg-cyan-500/10 com borda lateral esquerda (before:bg-cyan-400) de 2px. Font-semibold
5. Grupos colapsaveis: ChevronDown com rotate-180 animado. Sub-items com pl-9, text-slate-500 hover:text-slate-300
6. Secao SISTEMA: label uppercase text-slate-600 tracking-wider
7. Footer: text-slate-600 com versao
8. Divider entre grupos: border-t border-slate-800/40
9. Scrollbar: estilizada ou hidden

REFATORACAO EXIGIDA — HEADER:
1. Fundo: bg-slate-900/40 backdrop-blur-xl border-b border-slate-800/40
2. Badge de rede: manter logica, trocar cores para green-400/10 (online) e red-400/10 (offline)
3. Sino: icone com dot neon ciano (bg-cyan-400) pulsando (animate-pulse)
4. Botao "Assistente IA": border em gradiente ciano→violeta, texto "Assistente IA" com icone Sparkles. Hover com glow sutil
5. Avatar: ring-2 ring-slate-700 hover:ring-cyan-500/50
6. Nome/role: text-slate-300 (nome) e text-slate-500 text-xs (role)
7. Logout: icone LogOut text-slate-500 hover:text-red-400

AREA DE CONTEUDO:
1. Background: bg-slate-950 com um gradient radial muito sutil (radial-gradient(ellipse at 50% 0%, rgba(0,240,255,0.03), transparent 70%))
2. Padding: p-4 sm:p-6 lg:p-8
3. Max-width: max-w-7xl mx-auto

RESPONSIVO (MOBILE):
- Sidebar vira drawer com overlay bg-slate-950/80 backdrop-blur-sm
- Header mostra hamburger + logo compacto
- Transicao suave com cubic-bezier

REGRAS:
- React componentes funcionais + Tailwind CSS puro (sem bibliotecas UI externas)
- Icones SVG inline do Lucide React
- Manter toda logica de navegacao (NavLink, useLocation, roles) — so trocar visual
- Manter o componente UnifiedAIChat flutuante no bottom-right
- NAO alterar rotas nem logica de autenticacao
```

---

# PROMPT 02 — LOGIN

```
MISSAO: REFATORAR A PAGINA DE LOGIN/REGISTRO

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de autenticacao do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Login.jsx

ESTADO ATUAL:
Layout split-screen: painel esquerdo (50%, hidden no mobile) com hero text + features + testimonial + blobs decorativos; painel direito (50%) com formulario. Tab switcher Login/Registro. Campos: nome, CPF, telefone (registro), email, senha (ambos). Botao submit com gradiente purple→pink. Credenciais demo (apenas DEV). Footer com copyright.

ESTRUTURA A MANTER:
┌────────────────────────┬─────────────────────────┐
│ Painel Esquerdo (50%)  │ Painel Direito (50%)    │
│ - Logo EnjoyFun        │ - Logo mobile           │
│ - Hero text gradient   │ - Titulo dinamico       │
│ - 4 features com check │ - Tab Login | Registro  │
│ - Testimonial card     │ - Campos do form        │
│ - Blobs decorativos    │ - Botao submit          │
│ (lg:flex, hidden sm)   │ - Demo creds (dev only) │
│                        │ - Footer                │
└────────────────────────┴─────────────────────────┘

CAMPOS DO FORM:
- Registro: Nome completo, CPF, Telefone, Email, Senha (com toggle eye)
- Login: Email, Senha (com toggle eye)
- Validacao com mensagem de erro em vermelho (text-red-400) por campo

REFATORACAO EXIGIDA:
1. Background geral: bg-slate-950
2. Painel esquerdo: bg-slate-900/30 com blobs decorativos em ciano e violeta (ao inves de purple/pink)
   - Blob 1: bg-cyan-500/20 blur-3xl (top-left, animado)
   - Blob 2: bg-purple-500/20 blur-3xl (bottom-right, animado)
   - Hero text: gradient de text ciano→violeta (background-clip text)
   - Features: icone check dentro de circulo bg-cyan-500/20 border border-cyan-500/30
   - Testimonial card: bg-slate-800/40 backdrop-blur-sm border border-cyan-500/10
3. Painel direito:
   - Tab switcher: bg-slate-800/50 border border-slate-700/50 rounded-xl
   - Tab ativa: bg-cyan-500 text-slate-950 font-semibold shadow-lg
   - Tab inativa: text-slate-400 hover:text-slate-200
   - Inputs: bg-slate-800/50 border-slate-700 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 rounded-xl
   - Labels: text-slate-400 text-sm
   - Erro: text-red-400 text-xs com icone AlertCircle
   - Toggle senha: text-slate-500 hover:text-slate-300
   - Botao submit: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-bold rounded-xl shadow-[0_0_20px_rgba(0,240,255,0.3)] hover:shadow-[0_0_30px_rgba(0,240,255,0.5)] active:scale-[0.98]
   - Botao demo (dev): border border-dashed border-slate-700 hover:border-cyan-500/40 text-slate-500
   - Footer: text-slate-600 text-xs
4. Mobile: blobs como background fixo, logo centralizada com glow ciano
5. Logo: shadow-[0_0_24px_rgba(0,240,255,0.4)]
6. Animacoes: fade-in no form, pulse sutil nos blobs

REGRAS:
- React funcional + Tailwind CSS puro
- Manter toda logica de autenticacao (handleLogin, handleRegister, validacao, AuthContext)
- Manter toggle eye/eye-off na senha
- Manter credenciais demo apenas em DEV
- Manter responsivo (mobile: single column, desktop: split)
```

---

# PROMPT 03 — DASHBOARD

```
MISSAO: REFATORAR A PAGINA DASHBOARD PRINCIPAL

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore o Dashboard principal do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Dashboard.jsx

ESTADO ATUAL:
Pagina com titulo + welcome message, seletor de evento, aviso de cache offline, 3 secoes (Resumo Geral, Operacao do Evento, Apoio a Gestao). Cada secao tem SectionHeader + grid de StatCards + paineis de dados. Cards com cores solidas (green-600, purple-600, yellow-600, etc). Componentes: RevenueBySectorPanel, ParticipantsByCategoryPanel, OperationalNoticePanel, CriticalStockPanel, TopProductsPanel, QuickLinksPanel, EmbeddedAIChat.

ESTRUTURA A MANTER:
┌─────────────────────────────────────┐
│ Titulo + Welcome + Event Selector   │
├─────────────────────────────────────┤
│ SECAO 1: Resumo Geral              │
│ - 5 StatCards (grid 1/2/3 cols)     │
│ - 2 Paineis lado a lado            │
│ - EmbeddedAIChat widget            │
├─────────────────────────────────────┤
│ SECAO 2: Operacao do Evento        │
│ - 3 StatCards (grid 1/2/3 cols)    │
│ - 2 Paineis operacionais           │
├─────────────────────────────────────┤
│ SECAO 3: Apoio a Gestao            │
│ - TopProducts + Users card         │
│ - QuickLinks + Workforce + Finance │
└─────────────────────────────────────┘

STAT CARDS ATUAIS (5 + 3):
1. Vendas Total (green-600) | 2. Ingressos Vendidos (purple-600) | 3. Saldo Cartoes (yellow-600) | 4. Saldo Global (emerald-700) | 5. Presentes (indigo-600)
6. Carros Dentro (cyan-600) | 7. Terminais Offline (rose-600) | 8. Estoque Critico (amber-600)

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100 com icone LayoutDashboard em text-cyan-400
2. Welcome: text-slate-400
3. Event selector: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl. Dropdown com opcoes
4. Aviso cache: bg-amber-500/10 border border-amber-500/20 text-amber-300 rounded-xl
5. SectionHeader: icone em circulo bg-cyan-500/10, titulo text-slate-200, badge com bg-cyan-500/10 text-cyan-400 rounded-full px-3 py-1
6. StatCards — transformar em GLASSMORPHISM:
   - Container: bg-slate-900/60 backdrop-blur-md border border-slate-800/60 rounded-2xl p-5 hover:border-cyan-500/30 transition-all
   - Icone: dentro de circulo com a cor especifica do card (bg-green-500/15, bg-purple-500/15, etc) rounded-xl p-2
   - Label: text-slate-400 text-sm
   - Valor: text-2xl font-bold text-slate-100
   - Subtitulo: text-slate-500 text-xs
   - NAO usar fundo solido colorido — usar borda sutil + icone colorido
   - Opcional: linha decorativa no topo do card com gradiente da cor (h-[2px] bg-gradient-to-r)
7. Paineis (Revenue, Participants, etc): bg-slate-900/60 backdrop-blur-md border border-slate-800/60 rounded-2xl
8. Secao Operacao: badge "Acompanhamento" com bg-cyan-500/10 text-cyan-400
9. Secao Apoio: badge "Apoio" com bg-amber-500/10 text-amber-400
10. EmbeddedAIChat: manter accentColor purple, container com borda purple-500/20
11. Loading state dos cards: skeleton com bg-slate-800/50 animate-pulse rounded

REGRAS:
- React funcional + Tailwind CSS puro
- Manter toda logica de API, filtros, event selector
- Manter componentes filhos (apenas trocar o styling do wrapper/container)
- Responsivo: grid-cols-1 md:grid-cols-2 lg:grid-cols-3 nos StatCards
```

---

# PROMPT 04 — EVENTS + EVENT DETAILS

```
MISSAO: REFATORAR AS PAGINAS DE EVENTOS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore as paginas de listagem e detalhe de eventos do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVOS:
- frontend/src/pages/Events.jsx
- frontend/src/pages/EventDetails.jsx

=== EVENTS.JSX ===
ESTADO ATUAL:
Pagina de listagem com search bar, botao "Novo Evento", tabela de eventos (nome, venue, data, status, capacidade, acoes). Modal de criacao/edicao com form multi-step: dados basicos, modulos habilitados, lotes comerciais, tipos de ingresso, comissarios. Sub-componentes: EventTemplateSelector, EventModulesSelector, StagesSection, SectorsSection, ParkingConfigSection, PdvPointsSection, LocationSection.

ESTRUTURA A MANTER:
- Barra superior: search input + botao "Novo Evento"
- Tabela de eventos com status badges
- Modal de edicao com todas as secoes de form
- CRUD inline de lotes, tipos de ingresso, comissarios

REFATORACAO EXIGIDA (Events):
1. Search input: bg-slate-800/50 border-slate-700 focus:border-cyan-500 pl-10 (com icone Search a esquerda)
2. Botao "Novo Evento": bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl hover:shadow-[0_0_15px_rgba(0,240,255,0.3)]
3. Tabela: header bg-slate-800/50 text-slate-400 uppercase text-xs tracking-wider. Rows hover:bg-slate-800/30 border-b border-slate-800/50
4. Status badges:
   - draft: bg-slate-700/50 text-slate-400
   - published: bg-green-500/15 text-green-400
   - ongoing: bg-cyan-500/15 text-cyan-400
   - finished: bg-slate-700/50 text-slate-400
   - cancelled: bg-red-500/15 text-red-400
5. Modal: bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl shadow-2xl
6. Form inputs no modal: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl
7. Selects (status, event_type, etc): mesmo estilo dos inputs
8. Secoes do form: dividers com border-t border-slate-800/40, labels de secao em text-slate-300 font-semibold
9. Botoes de acao na tabela: icones com hover:text-cyan-400 (edit) e hover:text-red-400 (delete)
10. Sub-tabelas inline (lotes, tipos, comissarios): bg-slate-800/30 rounded-xl p-3, rows menores

=== EVENTDETAILS.JSX ===
ESTADO ATUAL:
Pagina de detalhe com header gradiente (purple→indigo), nome+status, info do venue, datas, capacidade, descricao, badges de integracao, 3 stat cards. Botoes: voltar, editar, excluir.

REFATORACAO EXIGIDA (EventDetails):
1. Header hero: gradient de bg-gradient-to-r from-slate-900 via-cyan-950/50 to-slate-900 com border border-cyan-500/20. Manter icone grande
2. Status badge: estilos consistentes com a tabela de Events
3. Info cards (venue, data, capacidade): bg-slate-800/40 border border-slate-700/50 rounded-xl com icones em text-cyan-400
4. Descricao: bg-slate-800/30 rounded-xl p-4, text-slate-300
5. Stat cards (lotes, comissarios, tipos): bg-slate-900/60 border border-slate-800/60 rounded-2xl, valor em text-cyan-400 text-2xl font-bold
6. Botao voltar: text-slate-400 hover:text-cyan-400
7. Botao editar: border border-slate-700 text-slate-300 hover:border-cyan-500/50
8. Botao excluir: border border-red-500/30 text-red-400 hover:bg-red-500/10
9. Badges de integracao: bg-cyan-500/10 text-cyan-400 rounded-full

REGRAS:
- React funcional + Tailwind CSS puro
- Manter toda logica de CRUD, validacao, modals, sub-componentes
- Manter responsivo (grids adaptativos)
```

---

# PROMPT 05 — TICKETS (Ingressos Comerciais)

```
MISSAO: REFATORAR A PAGINA DE INGRESSOS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de gestao de ingressos do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Tickets.jsx

ESTADO ATUAL:
Titulo "Ingressos Comerciais" com icone Ticket (cyan). Header com botoes Scanner e Quick Sale. Card de filtros com 4 dropdowns (evento, lote, comissario, setor) em grid md:grid-cols-4. EmbeddedAIChat com acento cyan. Tabela de tickets: titular, evento, tipo (badge), lote, comissario, status, acoes (ver QR, transferir). Pagination. Modal QR com TOTP dinamico (timer 30s). Modal Transfer com inputs email/nome.

ESTRUTURA A MANTER:
┌─────────────────────────────────────┐
│ Titulo + Botoes (Scanner, Quick Sale)│
├─────────────────────────────────────┤
│ Filtros: 4 dropdowns em grid        │
├─────────────────────────────────────┤
│ AI Chat Widget                      │
├─────────────────────────────────────┤
│ Tabela de Tickets + Pagination      │
├─────────────────────────────────────┤
│ Modal QR (TOTP timer) | Modal Transfer│
└─────────────────────────────────────┘

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100, icone Ticket em text-cyan-400
2. Botao Scanner: border border-slate-700 text-slate-300 hover:border-cyan-500/50 rounded-xl gap-2
3. Botao Quick Sale: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl
4. Card de filtros: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4
5. Dropdowns: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl
6. Tabela: header bg-slate-800/50 text-slate-400 uppercase text-xs. Rows hover:bg-slate-800/30
7. Status badges: pending (bg-amber-500/15 text-amber-400), paid (bg-green-500/15 text-green-400), used (bg-cyan-500/15 text-cyan-400), cancelled (bg-red-500/15 text-red-400), refunded (bg-slate-700/50 text-slate-400)
8. Acoes: icones hover:text-cyan-400 com transition
9. Pagination: botoes bg-slate-800/50 border-slate-700, ativo bg-cyan-500 text-slate-950
10. Modal QR: bg-slate-900/95 backdrop-blur-xl border border-cyan-500/20 rounded-2xl. Timer countdown com ring em cyan-400. QR code com fundo branco
11. Modal Transfer: mesmo estilo de modal, inputs padrao Aether Neon, botao de confirmar cyan
12. Tipo badge: bg-purple-500/15 text-purple-400 rounded-full text-xs

REGRAS:
- Manter toda logica TOTP, timer de 30s, refresh de QR
- Manter filtros, paginacao, transferencia
- Manter EmbeddedAIChat com acento cyan
```

---

# PROMPT 06 — POS (Ponto de Venda: Bar, Food, Shop)

```
MISSAO: REFATORAR A PAGINA DE PDV (Point of Sale)

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de PDV do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/POS.jsx
(Bar.jsx, Food.jsx e Shop.jsx sao wrappers que renderizam <POS fixedSector="bar|food|shop" />)

ESTADO ATUAL:
Layout full-screen com PosHeader (setor + status offline), PosToolbar (evento + tabs), 3 tabs (pos, stock, reports). Tab POS: grid de produtos a esquerda + carrinho a direita (lg:flex-row). Tab Stock: formulario de produto + lista de estoque. Tab Reports: graficos de vendas + produto mix + custos. Cores: gray-950 base, purple para badges, emerald/red para profit/loss. EmbeddedAIChat com acento purple.

ESTRUTURA A MANTER:
Tab POS:
┌────────────────────┬──────────────┐
│ ProductGrid        │ CartPanel    │
│ (catalogo visual)  │ + Checkout   │
│                    │              │
└────────────────────┴──────────────┘

Tab Stock:
┌─────────────────────────────────────┐
│ Formulario de Produto               │
│ Lista de Estoque com Edit/Delete    │
└─────────────────────────────────────┘

Tab Reports:
┌─────────────────────────────────────┐
│ ReportSummaryCards                   │
│ CostsSummaryCard                    │
│ SalesTimelineChart                  │
│ ProductMixChart                     │
└─────────────────────────────────────┘

REFATORACAO EXIGIDA:
1. Background: bg-slate-950
2. PosHeader: bg-slate-900/40 backdrop-blur-xl border-b border-slate-800/40. Nome do setor em text-cyan-400 font-bold
3. Tabs: bg-slate-800/50 rounded-xl p-1. Tab ativa: bg-cyan-500 text-slate-950 font-semibold rounded-lg. Inativa: text-slate-400 hover:text-slate-200
4. ProductGrid: cards de produto com bg-slate-800/40 border border-slate-700/50 rounded-xl hover:border-cyan-500/30 hover:shadow-[0_0_10px_rgba(0,240,255,0.1)]. Nome text-slate-200, preco text-cyan-400 font-bold
5. CartPanel: bg-slate-900/70 backdrop-blur-md border-l border-slate-800/60 (desktop). Items do cart com border-b border-slate-800/40
6. Checkout: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 w-full rounded-xl font-bold text-lg
7. Total do carrinho: text-3xl font-bold text-cyan-400
8. Card reference input: bg-slate-800/50 border-slate-700 focus:border-cyan-500
9. Stock form: inputs padrao Aether Neon, grid responsivo
10. Stock list: tabela com header bg-slate-800/50, acoes edit/delete
11. Reports cards: bg-slate-900/60 border border-slate-800/60 rounded-2xl. Metricas em text-cyan-400 (receita) e text-emerald-400 (lucro) ou text-red-400 (perda)
12. Graficos: manter cores mas adaptar backgrounds para dark Aether Neon
13. Badge de estoque baixo: bg-amber-500/15 text-amber-400 rounded-full
14. Badge offline: bg-red-500/15 text-red-400 com icone pulsando

REGRAS:
- Manter toda logica de carrinho, checkout, estoque, relatorios
- Manter EmbeddedAIChat
- Responsivo: lg:flex-row no desktop, flex-col no mobile
- Manter offline support
```

---

# PROMPT 07 — CARDS (Cartao Digital / Cashless)

```
MISSAO: REFATORAR A PAGINA DE CARTOES DIGITAIS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de gestao de cartoes cashless do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Cards.jsx

ESTADO ATUAL:
Titulo "Cartao Digital" com icone CreditCard (purple). Event selector + "Novo Cartao" botao. Search input. Layout 2 colunas (lg:grid-cols-2): lista de cartoes a esquerda com pagination, detalhe do cartao selecionado a direita. Detalhe mostra: saldo (card gradiente purple→pink), botoes block/delete, form de recarga, historico de transacoes. Transacoes com trending up/down e cores red/green.

ESTRUTURA A MANTER:
┌──────────────────┬──────────────────┐
│ Lista de Cartoes │ Detalhe do Cartao│
│ - Search         │ - Saldo (card)   │
│ - Cards list     │ - Acoes          │
│ - Pagination     │ - Recarga form   │
│                  │ - Transacoes     │
└──────────────────┴──────────────────┘

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100, icone em text-purple-400
2. Botao "Novo Cartao": bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl
3. Search: bg-slate-800/50 border-slate-700 focus:border-cyan-500 pl-10 rounded-xl
4. Lista de cartoes: bg-slate-900/60 border border-slate-800/60 rounded-2xl. Items com hover:bg-slate-800/40 border-b border-slate-800/40
5. Card selecionado (highlight): border-l-2 border-cyan-400 bg-cyan-500/5
6. Saldo card (detalhe): bg-gradient-to-br from-cyan-950/60 to-purple-950/60 border border-cyan-500/20 rounded-2xl. Saldo em text-4xl font-bold text-cyan-400
7. Status badge: active → bg-green-500/15 text-green-400, inactive → bg-red-500/15 text-red-400
8. Botao Block: border border-amber-500/30 text-amber-400 hover:bg-amber-500/10
9. Botao Delete: border border-red-500/30 text-red-400 hover:bg-red-500/10
10. Form recarga: input bg-slate-800/50, botao bg-cyan-500 text-slate-950 rounded-xl
11. Transacoes: icone trending-up text-green-400 (credito), trending-down text-red-400 (debito). Valores com mesmas cores. Container bg-slate-800/30 rounded-xl
12. Pagination: botoes bg-slate-800/50 border-slate-700
13. Modal novo cartao: bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl
14. Card ID: font-mono text-cyan-400/70 text-xs

REGRAS:
- Manter toda logica de CRUD, block/unblock, recarga, transacoes
- Manter paginacao dupla (cards + transacoes)
- Responsivo: lg:grid-cols-2, detalhe oculto no mobile ate selecionar
```

---

# PROMPT 08 — PARKING (Estacionamento/Portaria)

```
MISSAO: REFATORAR A PAGINA DE ESTACIONAMENTO

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de portaria/estacionamento do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Parking.jsx

ESTADO ATUAL:
Titulo "Portaria" com icone ParkingSquare (cyan). Indicador de capacidade (badge colorido). 2 tabs: Estacionamento e Validar Ingresso. Form de entrada com evento, placa, tipo de veiculo. Event selector. EmbeddedAIChat (cyan). Tabela de registros (placa, tipo, entrada, saida, status, acoes). Pagination. Modais: QR display e resultado de validacao.

ESTRUTURA A MANTER:
┌─────────────────────────────────────┐
│ Titulo + Capacidade Badge           │
│ Tabs: [Estacionamento] [Validar]    │
├─────────────────────────────────────┤
│ Form de entrada (condicional)       │
│ Event Selector                      │
│ AI Chat Widget                      │
├─────────────────────────────────────┤
│ Tabela de Registros + Pagination    │
├─────────────────────────────────────┤
│ Modal QR | Modal Validacao          │
└─────────────────────────────────────┘

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100, icone ParkingSquare em text-cyan-400
2. Capacidade badge: bg-green-500/15 text-green-400 (ok), bg-amber-500/15 text-amber-400 (90%+), bg-red-500/15 text-red-400 (lotado). Rounded-full px-3 py-1
3. Tabs: bg-slate-800/50 rounded-xl p-1. Ativa: bg-cyan-500 text-slate-950. Inativa: text-slate-400
4. Form de entrada: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-5. Inputs padrao Aether Neon. Grid grid-cols-2 gap-4
5. Placa input: font-mono uppercase text-lg (destaque)
6. Botao "Registrar Entrada": bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl
7. Botao "Registrar Saida": border border-amber-500/30 text-amber-400 hover:bg-amber-500/10
8. Tabela: header bg-slate-800/50, placa em font-mono font-bold text-slate-100, tipo em text-slate-400
9. Status badges: pending bg-amber-500/15, parked bg-green-500/15, exited bg-slate-700/50
10. QR Modal: bg-slate-900/95 backdrop-blur-xl, placa grande font-mono text-cyan-400, QR com fundo branco
11. Validacao Modal: bg-green-500/10 border-green-500/20 (entrada/saida ok), bg-red-500/10 border-red-500/20 (erro)
12. Tab Validar: input de ticket + botao "Validar" com icone Search
13. EmbeddedAIChat: acento cyan mantido

REGRAS:
- Manter toda logica de entrada/saida, validacao, offline
- Manter paginacao e filtro de evento
```

---

# PROMPT 09 — MEALS CONTROL (Controle de Refeicoes)

```
MISSAO: REFATORAR A PAGINA DE CONTROLE DE REFEICOES

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de meals do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/MealsControl.jsx (arquivo grande ~3700 linhas)

ESTADO ATUAL:
Titulo "Controle de Refeicoes" com icone UtensilsCrossed (orange). 3 tabs (Refeicoes, Historico, Offline). Tab 1: QR input, meal service pills (selecionaveis), event day/shift selectors, botao registrar, lista de refeicoes do dia. Tab 2: tabela de logs com paginacao. Tab 3: fila offline com retry/mark-failed. Modal de configuracao de custos. Cores: orange accent, gray base.

ESTRUTURA A MANTER:
Tab Refeicoes:
- QR/Token input + copy button
- Meal service pills (selecionaveis, com horarios)
- Event day + shift dropdowns (cascading)
- Botao "Registrar Refeicao"
- Grid de refeicoes consumidas hoje

Tab Historico:
- Tabela de logs (titular, setor, role, servico, hora, data)
- Paginacao

Tab Offline:
- Tabela de fila offline (offline_id, status, payload, data)
- Botoes retry e mark-failed

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100, icone em text-amber-400 (trocar orange por amber para harmonizar com o tema)
2. Tabs: bg-slate-800/50 rounded-xl p-1. Ativa: bg-amber-500 text-slate-950. Inativa: text-slate-400
3. QR input: bg-slate-800/50 border-slate-700 focus:border-cyan-500 font-mono text-lg
4. Meal service pills: bg-slate-800/40 border border-slate-700/50 rounded-xl px-4 py-2. Selecionado: border-cyan-400 bg-cyan-500/10 text-cyan-400. Nao selecionado: text-slate-400 hover:border-slate-600
5. Event day/shift selects: inputs padrao Aether Neon
6. Botao Registrar: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl
7. Grid de refeicoes do dia: cards bg-slate-800/40 border border-slate-700/50 rounded-xl com icone de refeicao
8. Tabela historico: header bg-slate-800/50, rows hover:bg-slate-800/30
9. Tabela offline: status badges (pending bg-amber-500/15, failed bg-red-500/15). Botao retry bg-cyan-500/15 text-cyan-400, mark-failed bg-red-500/15 text-red-400
10. Modal custos: bg-slate-900/95 backdrop-blur-xl, inputs de horario/custo padrao Aether Neon
11. Copy button: hover:text-cyan-400 com tooltip

REGRAS:
- Arquivo grande — APENAS trocar classes de estilo, NAO refatorar logica
- Manter toda logica de QR, TOTP, meal registration, offline queue
- Manter cascading selectors (event day → shift)
```

---

# PROMPT 10 — PARTICIPANTS HUB + TABS

```
MISSAO: REFATORAR O HUB DE PARTICIPANTES E SUAS TABS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore o ParticipantsHub e suas tabs do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVOS:
- frontend/src/pages/ParticipantsHub.jsx
- frontend/src/pages/ParticipantsTabs/GuestManagementTab.jsx
- frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx
- frontend/src/pages/ParticipantsTabs/EditParticipantModal.jsx
- frontend/src/pages/ParticipantsTabs/EditGuestModal.jsx
- frontend/src/pages/ParticipantsTabs/CsvImportModal.jsx
- frontend/src/pages/ParticipantsTabs/AddWorkforceAssignmentModal.jsx
- frontend/src/pages/ParticipantsTabs/WorkforceMemberSettingsModal.jsx
- frontend/src/pages/ParticipantsTabs/BulkWorkforceSettingsModal.jsx
- frontend/src/pages/ParticipantsTabs/BulkMessageModal.jsx
- frontend/src/pages/ParticipantsTabs/WorkforceSectorCostsModal.jsx
- frontend/src/pages/ParticipantsTabs/WorkforceCardIssuanceModal.jsx
- frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx

=== PARTICIPANTSHUB.JSX ===
Header com titulo "Participants Hub", event selector, 2 tabs (Guest Management, Workforce Ops). Renderiza GuestManagementTab ou WorkforceOpsTab condicionalmente.

=== GUESTMANAGEMENTTAB ===
Banner de selecao em massa, search + importar CSV, tabela de convidados com checkboxes, status badges, acoes (copy link, QR, edit, delete). Bulk actions: WhatsApp, Email, Delete.

=== WORKFORCEOPSTAB ===
View de hierarquia: lista de managers → detalhe da equipe. Busca, importacao CSV, tabela de membros, selecao em massa, EmbeddedAIChat. Muitos modais lazy-loaded.

=== MODAIS ===
Todos seguem padrao: overlay fixo + card centralizado + header/body/footer.

REFATORACAO EXIGIDA (TODOS):
1. ParticipantsHub:
   - Titulo: text-2xl font-bold text-slate-100, icone Users em text-cyan-400
   - Event selector: padrao Aether Neon
   - Tabs: bg-slate-800/50 rounded-xl p-1. Ativa: bg-cyan-500 text-slate-950. Inativa: text-slate-400

2. GuestManagementTab:
   - Banner selecao: bg-cyan-500/10 border border-cyan-500/20 rounded-xl
   - Search: padrao Aether Neon
   - Tabela: padrao Aether Neon (header, rows, hover)
   - Checkboxes: accent-cyan-500
   - Status badges: bg-purple-500/15 text-purple-400
   - Bulk actions: WhatsApp (bg-green-500/15 text-green-400), Email (bg-slate-700/50 text-slate-300), Delete (bg-red-500/15 text-red-400)

3. WorkforceOpsTab:
   - Manager cards: bg-slate-800/40 border border-slate-700/50 rounded-xl hover:border-cyan-500/30
   - Manager selecionado: border-cyan-400 bg-cyan-500/5
   - Team table: padrao Aether Neon
   - Search + Import: padrao Aether Neon

4. TODOS OS MODAIS (padrao unico):
   - Overlay: bg-slate-950/80 backdrop-blur-sm
   - Card: bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl shadow-2xl
   - Header: border-b border-slate-800/40, titulo text-slate-100, close button hover:text-red-400
   - Labels: text-slate-400 text-xs uppercase tracking-wider
   - Inputs: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl
   - Botao primario: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950
   - Botao cancelar: border border-slate-700 text-slate-300
   - Grids de form: grid-cols-2 gap-4

5. WorkforceSectorCostsModal — cores especificas:
   - Operacao: text-cyan-400 para subtotais
   - Lideranca: text-amber-400 para subtotais
   - Total: text-emerald-400 para grand total, border border-emerald-500/20

6. WorkforceCardIssuanceModal — badges de elegibilidade:
   - Elegivel: bg-emerald-500/15 text-emerald-400
   - Ja tem: bg-cyan-500/15 text-cyan-400
   - Legado: bg-amber-500/15 text-amber-400
   - Erro: bg-red-500/15 text-red-400

7. BulkMessageModal:
   - WhatsApp: icone e header em text-green-400
   - Email: icone e header em text-cyan-400
   - Textarea: bg-slate-800/50 border-slate-700, rows 6

REGRAS:
- MUITOS arquivos — trocar APENAS styling visual
- Manter toda logica de API, roles, selecao, bulk operations, modais
- Manter lazy loading dos modais
```

---

# PROMPT 11 — GUESTS (Convidados)

```
MISSAO: REFATORAR A PAGINA DE CONVIDADOS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de convidados do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Guests.jsx

ESTADO ATUAL:
Titulo "Convidados" com icone Users (blue). Subtitulo com contagem. Botao "Importar CSV". Search input (debounce 350ms) + event filter select. Tabela: nome, email, telefone, status, evento, acoes (copy link, edit, delete). Status badges: green (used), amber (pending). Pagination. Modal CSV import com event selector + file upload + resultado. Modal edit com nome/email/telefone.

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100, icone Users em text-cyan-400
2. Botao Importar: border border-slate-700 text-slate-300 hover:border-cyan-500/50 rounded-xl
3. Search + Event filter: padrao Aether Neon (bg-slate-800/50 border-slate-700 focus:border-cyan-500)
4. Tabela: header bg-slate-800/50, rows hover:bg-slate-800/30, border-b border-slate-800/50
5. Status badges: used → bg-green-500/15 text-green-400, pending → bg-amber-500/15 text-amber-400
6. Acoes: copy link hover:text-cyan-400, edit hover:text-cyan-400, delete hover:text-red-400
7. Pagination: padrao Aether Neon
8. Modal CSV: padrao modal Aether Neon (bg-slate-900/95 backdrop-blur-xl)
9. Modal Edit: padrao modal Aether Neon
10. Resultado import: CheckCircle text-green-400, AlertCircle text-amber-400

REGRAS:
- Manter debounce no search, filtro de evento, paginacao
- Manter copy link, CSV import, edit/delete
```

---

# PROMPT 12 — USERS (Equipe/Staff)

```
MISSAO: REFATORAR A PAGINA DE EQUIPE/STAFF

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de gestao de equipe do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Users.jsx

ESTADO ATUAL:
Titulo "Equipe / Staff" com icone Users. Contagem de membros. Botao "Novo Membro". Tabela: avatar (iniciais com gradiente), nome, email, telefone, cargo (badge), setor (badge), status toggle, data de cadastro. Modal de criacao: nome, CPF, telefone, email, senha, cargo dropdown, setor dropdown. Cargo badges: purple (gerente), blue (caixa). Setor badges: amber (bar), orange (food), cyan (shop), green (todos).

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100, icone Users em text-cyan-400
2. Botao Novo: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl
3. Tabela: padrao Aether Neon
4. Avatar: bg-gradient-to-br from-cyan-950 to-purple-950 border border-slate-700 text-slate-200
5. Cargo badges: Gerente → bg-purple-500/15 text-purple-400, Caixa → bg-cyan-500/15 text-cyan-400
6. Setor badges: Bar → bg-amber-500/15 text-amber-400, Food → bg-orange-500/15 text-orange-400, Shop → bg-cyan-500/15 text-cyan-400, Todos → bg-green-500/15 text-green-400
7. Status toggle: ativo → bg-green-500 (circle), inativo → bg-red-500 (circle). Track: bg-slate-700
8. Modal: padrao Aether Neon (bg-slate-900/95, inputs, selects)
9. Data cadastro: text-slate-500 text-sm

REGRAS:
- Manter toggle de status, criacao de usuario, roles/sectors
```

---

# PROMPT 13 — SCANNER OPERACIONAL

```
MISSAO: REFATORAR A PAGINA DO SCANNER OPERACIONAL

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina do scanner de credenciais/ingressos do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Operations/Scanner.jsx

ESTADO ATUAL:
Titulo "Scanner Operacional" com icone Camera. Layout mobile-first (max-w-md mx-auto). 2 modos: selecao (evento + setor) e scanning (camera + input manual). Mode selection: dropdown de evento, botoes de setor (Portaria + setores do workforce). Scanning: camera QR (aspect-square), overlay de resultado (success verde, warning amber, error vermelho), input manual + botao validar. Offline vault card com contagem e sync.

REFATORACAO EXIGIDA:
1. Background: bg-slate-950 full-screen
2. Titulo: text-xl font-bold text-slate-100, icone Camera em text-cyan-400
3. Back button: text-slate-400 hover:text-cyan-400
4. Event select: padrao Aether Neon
5. Setor buttons: bg-slate-800/40 border border-slate-700/50 rounded-xl p-4. Hover: border-cyan-500/30 bg-cyan-500/5. Selecionado: border-cyan-400 bg-cyan-500/10
6. Camera viewport: border-2 border-cyan-500/30 rounded-2xl aspect-square. Corners decorativos com linhas cyan
7. Resultado APROVADO: bg-green-500/15 border border-green-500/30, icone CheckCircle text-green-400 grande (80px), texto "APROVADO" em text-green-400 font-bold text-2xl
8. Resultado ATENCAO: bg-amber-500/15 border border-amber-500/30, text-amber-400
9. Resultado NEGADO: bg-red-500/15 border border-red-500/30, text-red-400
10. Input manual: bg-slate-800/50 border-slate-700 focus:border-cyan-500 font-mono
11. Botao Validar: bg-cyan-500 text-slate-950 rounded-xl
12. Botao "Ler Proximo": bg-slate-800/50 border border-slate-700 text-slate-300 rounded-xl
13. Offline vault card: bg-amber-500/10 border border-amber-500/20, text-amber-300. Sync button: bg-cyan-500/15 text-cyan-400
14. Camera start button: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl com icone Camera

REGRAS:
- Layout mobile-first (max-w-md)
- Manter logica de camera, QR scanning, validacao offline, manual input
- Manter modos locked/unlocked de setor
```

---

# PROMPT 14 — MESSAGING (Mensageria)

```
MISSAO: REFATORAR A PAGINA DE MENSAGERIA

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de mensageria do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Messaging.jsx

ESTADO ATUAL:
Titulo com icone. Cards de status de canal (WhatsApp, Email). 2 tabs: Enviar e Historico. Tab Enviar: sub-tabs WhatsApp/Email, input de destinatario, textarea, contador de caracteres, botao enviar. Tab Historico: tabela de mensagens com canal, destino, mensagem, status badge, data. Pagination.

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100
2. Channel status cards: bg-slate-900/60 border border-slate-800/60 rounded-2xl. WhatsApp: icone/badge em green-400. Email: icone/badge em cyan-400. Configurado: bg-green-500/15 text-green-400. Nao configurado: bg-red-500/15 text-red-400
3. Tabs: bg-slate-800/50 rounded-xl p-1. Ativa: bg-cyan-500 text-slate-950. Inativa: text-slate-400
4. Sub-tabs (WhatsApp/Email): WhatsApp ativo: bg-green-500/15 text-green-400 border border-green-500/30. Email ativo: bg-cyan-500/15 text-cyan-400 border border-cyan-500/30
5. Input destinatario: padrao Aether Neon
6. Textarea: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl
7. Contador: text-slate-500 text-xs
8. Botao enviar WhatsApp: bg-green-500 text-white hover:bg-green-600 rounded-xl
9. Botao enviar Email: bg-cyan-500 text-slate-950 rounded-xl
10. Tabela historico: padrao Aether Neon
11. Status badges: sent bg-green-500/15, read bg-cyan-500/15, failed bg-red-500/15, pending bg-amber-500/15

REGRAS:
- Manter logica de envio, historico, paginacao, status de canal
```

---

# PROMPT 15 — SETTINGS + TABS (Incluindo aba de IA)

```
MISSAO: REFATORAR A PAGINA DE CONFIGURACOES E TODAS AS SUAS ABAS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de configuracoes do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVOS:
- frontend/src/pages/Settings.jsx
- frontend/src/pages/SettingsTabs/BrandingTab.jsx
- frontend/src/pages/SettingsTabs/ChannelsTab.jsx
- frontend/src/pages/SettingsTabs/FinanceTab.jsx
- frontend/src/pages/SettingsTabs/AIConfigTab.jsx (wrapper que renderiza AIControlCenter)
- frontend/src/components/AIControlCenter.jsx (componente real da aba de IA)

======================================================================
=== SETTINGS.JSX (Shell com 4 tabs) ===
======================================================================

ESTADO ATUAL:
- Container: p-6 max-w-6xl mx-auto space-y-6 animate-fade-in
- Titulo: h1 "Configuracoes do Organizador" com classe page-title
- Subtitulo: text-gray-400 text-sm
- 4 tabs com icones lucide (Palette, MessageCircle, CreditCard, Bot):
  - "Identidade Visual" | "Canais de Contato" | "Camada Financeira" | "Inteligencia Artificial"
- Tab navigation: flex overflow-x-auto space-x-1 border-b border-gray-800 pb-px
- Tab ativa: border-brand text-brand (border-b-2)
- Tab inativa: border-transparent text-gray-400 hover:text-white hover:border-gray-600
- Conteudo renderizado por switch/case no activeTab

REFATORACAO Settings.jsx:
1. Container: p-6 max-w-6xl mx-auto space-y-6
2. Titulo: text-2xl font-bold text-slate-100
3. Subtitulo: text-slate-400 text-sm
4. Tab navigation: flex overflow-x-auto space-x-1 border-b border-slate-800/40 pb-px mb-6
5. Tab ativa: border-b-2 border-cyan-400 text-cyan-400 font-medium
6. Tab inativa: border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600
7. Tab transition: transition-all duration-300
8. Icones nas tabs: size 18, herdam cor do texto

======================================================================
=== BRANDINGTAB.JSX (Identidade Visual) ===
======================================================================

ESTADO ATUAL:
- Grid 1/3 cols: logo card (col-span-1) + form card (col-span-2)
- Logo card: section-title "Logomarca" com icone Store text-brand
  - Dropzone: w-44 h-44 rounded-xl border-2 border-dashed border-gray-700 bg-gray-900/50
  - Overlay no hover: bg-black/60 com UploadCloud + "Trocar Imagem"
  - Se sem logo: "Sem Logo" em text-gray-500
  - Nota: "Qualquer arquivo de imagem quadrado..." text-xs text-gray-500
- Form card: section-title "Personalizacao White-label" com icone Palette text-brand
  - Grid 2 colunas:
    - "Nome da Plataforma/App" (input text, required)
    - "Subdominio" (input disabled, cursor-not-allowed, "Em breve")
    - "Cor Principal" (input color + hex display font-mono)
    - "Cor Secundaria" (input color + hex display font-mono)
  - Botao "Aplicar Marca" (btn-primary px-8 py-3, icone Save)

REFATORACAO BrandingTab:
1. Grid: grid-cols-1 md:grid-cols-3 gap-8
2. Logo card: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-5
3. Section title: text-slate-200 font-semibold, icone text-cyan-400
4. Dropzone: w-44 h-44 rounded-xl border-2 border-dashed border-slate-700 bg-slate-800/30. Hover overlay: bg-slate-950/70 com UploadCloud text-cyan-400. Hover border: border-cyan-500/40
5. "Sem Logo": text-slate-500
6. Form card: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-6
7. Labels: text-slate-400 text-xs uppercase tracking-wider (input-label)
8. Inputs: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl
9. Subdominio disabled: bg-slate-800/30 text-slate-500 cursor-not-allowed
10. Color inputs: h-10 w-20 p-1 bg-slate-800 border border-slate-700 rounded-lg cursor-pointer
11. Hex display: text-sm font-mono text-slate-500
12. Botao save: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-8 py-3 shadow-[0_0_15px_rgba(0,240,255,0.2)]

======================================================================
=== CHANNELSTAB.JSX (Canais de Contato) ===
======================================================================

ESTADO ATUAL:
- Container: card max-w-4xl p-8 space-y-8
- Info banner: rounded-lg border border-brand/30 bg-brand/10 p-3 text-sm text-gray-200
- Secao Email: section-title "Gateway de E-mail (Resend)" com icone Mail text-brand
  - Descricao text-sm text-gray-400
  - Grid 2 colunas: "Remetente Oficial" (text), "Resend API Key" (password)
- Divider entre secoes
- Secao WhatsApp: section-title "API do WhatsApp (Evolution/Z-API)" com icone MessageSquare text-green-500
  - Descricao text-sm text-gray-400
  - Grid 2 colunas:
    - "URL da API" (url, col-span-2)
    - "Token de Acesso" (password)
    - "Nome da Instancia" (text)
    - "Segredo do Webhook" (password, col-span-2) + nota text-xs text-gray-500
- Botao "Salvar Canais" (btn-primary px-8 py-3, icone Save)

REFATORACAO ChannelsTab:
1. Container: bg-slate-900/60 border border-slate-800/60 rounded-2xl max-w-4xl p-8 space-y-8
2. Info banner: bg-cyan-500/10 border border-cyan-500/20 rounded-xl p-3 text-sm text-slate-200
3. Secao Email header: text-slate-200 font-semibold, icone Mail text-cyan-400
4. Secao WhatsApp header: text-slate-200 font-semibold, icone MessageSquare text-green-400
5. Descricoes: text-sm text-slate-400
6. All inputs: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl
7. Password inputs: mesmas classes + type password
8. Notas: text-xs text-slate-500
9. Divider: border-t border-slate-800/40
10. Botao save: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl

======================================================================
=== FINANCETAB.JSX (Camada Financeira) ===
======================================================================

ESTADO ATUAL:
- Header card: max-w-5xl p-6 border-l-4 border-l-brand
  - section-title "Financial Layer" com icone Zap text-brand
  - Descricao com "Principal" em strong text-brand
  - Info box (bg-gray-900/50 border-gray-800): Moeda Base (BRL) + Taxa EnjoyFun (X%)
- Error banner (condicional): border-yellow-700/40 bg-yellow-900/10 text-yellow-300
- Grid de 5 gateway cards: grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6
- Cada gateway (MP, PS, AS, PG, IP):
  - Icone quadrado: w-10 h-10 rounded-xl, texto bold (MP/PS/AS/PG/IP)
    - Ativo: bg-gradient-to-br from-brand to-purple-600 text-white
    - Inativo: bg-gray-800 text-gray-500
  - Nome + status dot (green-500 ativo / gray-600 inativo)
  - Badge "Principal" (bg-brand/20 text-brand, Star fill) — so no gateway principal
  - VIEW MODE:
    - "Credenciais": Configurado (CheckCircle2 text-green-400) / Pendente (ShieldAlert text-yellow-500)
    - Botao "Configurar" (btn-secondary flex-1)
    - Botao estrela "Tornar Principal" (btn-ghost, icone Star)
  - EDIT MODE (quando clica Configurar, card ganha ring-2 ring-brand):
    - Toggle switch "Status de Operacao" (peer checkbox → green-500 when checked)
    - Input password: credencial (API Key / Access Token) com placeholder dinamico
    - Input text: Public Key (opcional)
    - 3 botoes: "Salvar" (btn-primary flex-1), "Testar Conexao" (btn-secondary, Link2 ou Loader2 spin), "Voltar" (btn-ghost)
  - Card principal: bg-brand/5 border-brand/30
  - Card configurando: ring-2 ring-brand

REFATORACAO FinanceTab:
1. Header card: bg-slate-900/60 border border-slate-800/60 rounded-2xl max-w-5xl p-6 border-l-2 border-l-cyan-400
2. Section title: text-slate-200 font-semibold, icone Zap text-cyan-400
3. "Principal" emphasis: text-cyan-400 font-semibold
4. Info box: bg-slate-800/40 border border-slate-700/50 rounded-xl p-4. Labels text-slate-500 uppercase text-xs. Valores text-lg font-bold text-slate-200
5. Error banner: bg-amber-500/10 border border-amber-500/20 text-amber-300 rounded-xl
6. Gateway cards: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-5
7. Gateway ativo icone: bg-gradient-to-br from-cyan-500 to-cyan-400 text-slate-950
8. Gateway inativo icone: bg-slate-800 text-slate-500
9. Status dot: w-2 h-2 rounded-full. Ativo: bg-green-400. Inativo: bg-slate-600
10. Badge Principal: bg-amber-500/15 text-amber-400 px-2 py-1 rounded text-xs font-semibold, Star fill
11. Card principal: bg-cyan-500/5 border-cyan-500/20
12. Card editando: ring-2 ring-cyan-400
13. Credenciais Configurado: text-green-400 com CheckCircle2
14. Credenciais Pendente: text-amber-400 com ShieldAlert
15. Toggle switch: track bg-slate-700, checked bg-green-500, circle bg-white
16. Inputs edit mode: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl
17. Botao Salvar: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl
18. Botao Testar: border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 rounded-xl
19. Botao Voltar: text-slate-400 hover:text-slate-200
20. Botao Estrela: text-slate-400 hover:text-cyan-400

======================================================================
=== AICONFIGTAB.JSX + AICONTROLCENTER.JSX (Inteligencia Artificial) ===
======================================================================

NOTA: AIConfigTab e um wrapper de 5 linhas que renderiza <AIControlCenter />.
O componente real e AIControlCenter.jsx — este e o arquivo a refatorar.

ESTADO ATUAL DO AICONTROLCENTER:
- Container: space-y-8 fade-in
- Card principal: card space-y-6 p-8
  - Header flex: section-title "Providers de IA" com icone Server text-brand
  - Descricao: "Configure as 3 plataformas..." text-sm text-gray-400
  - Nota: "Campo de API Key em branco..." text-xs text-gray-500
  - Grid de 3 provider cards: xl:grid-cols-3 gap-5

- Cada ProviderCard (OpenAI, Gemini, Claude):
  - Container: card-hover flex flex-col gap-4 border-gray-800
  - Header: nome bold text-white + descricao tool use text-xs text-gray-400
  - Badges: "Configurado" badge-green / "Sem chave" badge-gray + "Padrao" badge-green (se default)

  - Checkbox "Provider ativo":
    - Label flex rounded-xl border-gray-800 bg-gray-900/60 px-3 py-3
    - Titulo text-sm text-gray-200 + descricao text-xs text-gray-500
    - Checkbox h-4 w-4 accent-green-500

  - Checkbox "Fallback padrao":
    - Mesmo layout que "Provider ativo"
    - Checkbox accent-purple-500

  - Input "API Key / segredo":
    - Label com icone ShieldCheck text-gray-400
    - Input type password, placeholder "Digite apenas se quiser atualizar a chave"

  - Select "Modelo":
    - Options do catalogo de modelos (formatModelOptionLabel)
    - Se modelo customizado legado: nota text-amber-400 "Valor legado fora do catalogo"
    - Se modelo selecionado: info text-xs text-gray-500 com descricao + custos (in/out /1M)

  - Input "Base URL (opcional)":
    - Placeholder "Use apenas se houver endpoint customizado/proxy"

  - Footer (mt-auto): 2 botoes
    - "Testar conexao" (btn-secondary, icone Plug ou Loader2 spin quando testando)
    - "Salvar provider" (btn-primary, icone Save)

REFATORACAO AIControlCenter:
1. Container: space-y-8
2. Card principal: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-8
3. Section title: text-slate-200 font-semibold, icone Server text-purple-400 (usar PURPLE para IA)
4. Descricao: text-sm text-slate-400
5. Nota: text-xs text-slate-500

6. Provider cards: bg-slate-800/40 border border-slate-700/50 rounded-2xl p-5 flex flex-col gap-4 hover:border-purple-500/30 transition-all
7. Nome provider: font-bold text-slate-100
8. Tool use info: text-xs text-slate-400
9. Badges:
   - Configurado: bg-green-500/15 text-green-400 rounded-full px-2.5 py-0.5 text-xs
   - Sem chave: bg-slate-700/50 text-slate-400 rounded-full
   - Padrao: bg-purple-500/15 text-purple-400 rounded-full

10. Checkbox labels: bg-slate-800/30 border border-slate-700/50 rounded-xl px-3 py-3
    - Titulo: text-sm text-slate-200
    - Descricao: text-xs text-slate-500
    - Checkbox ativo: accent-green-500
    - Checkbox default: accent-purple-500

11. Input API Key: bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-xl. Label icone ShieldCheck text-slate-400
12. Select Modelo: bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-xl
    - Info modelo: text-xs text-slate-500 (descricao + custos)
    - Legado warning: text-xs text-amber-400
13. Input Base URL: bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-xl

14. Botao Testar: border border-purple-500/30 text-purple-400 hover:bg-purple-500/10 rounded-xl. Spin: Loader2 animate-spin
15. Botao Salvar: bg-purple-500 hover:bg-purple-400 text-white font-semibold rounded-xl. Icone Save

16. Loading state: text-slate-500 animate-pulse
17. Toast success/error: manter logica existente

NOTA DE COR: Na aba de IA, o acento e PURPLE (violeta) ao inves de cyan. Isso diferencia visualmente as configuracoes de IA das configuracoes gerais do sistema.

REGRAS GLOBAIS:
- Manter toda logica de save, test connection, color pickers, file upload, toggles
- Manter applyBrand() e CSS vars no BrandingTab
- Manter placeholder masking de credenciais no ChannelsTab
- Manter fallback legado no FinanceTab
- Manter catalogo de modelos e custos no AIControlCenter
- Manter togglePrincipal, handleTestConnection, handleSaveGateway
- React funcional + Tailwind CSS puro
- Responsivo: grids adaptativos por breakpoint
```

---

# PROMPT 16 — AI ASSISTANTS + AI AGENTS + COMPONENTES DE IA

```
MISSAO: REFATORAR AS PAGINAS E COMPONENTES DE CONFIGURACAO DE IA

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore TODAS as paginas e componentes de configuracao de IA do EnjoyFun aplicando o Design System "Aether Neon".

NOTA IMPORTANTE: O acento de IA e PURPLE (violeta), nao cyan. Cyan e para acoes gerais do sistema. Purple marca tudo que envolve inteligencia artificial.

ARQUIVOS:
- frontend/src/pages/AIAssistants.jsx (pagina principal — 998 linhas)
- frontend/src/pages/AIAgents.jsx (pagina secundaria — 512 linhas)
- frontend/src/components/AIControlCenter.jsx (configuracao de providers)
- frontend/src/components/AIUsageSummary.jsx (barra de uso mensal)
- frontend/src/components/AIBlueprintWorkbench.jsx (blueprint + memorias + relatorios)
- frontend/src/components/AIExecutionFeed.jsx (feed de execucoes com aprovacao)

======================================================================
=== 1. AIASSISTANTS.JSX (PAGINA PRINCIPAL) ===
======================================================================

ESTADO ATUAL COMPLETO:
- Header compacto: icone gradiente purple→pink (w-10 h-10 rounded-xl), titulo "Assistente IA", subtitulo com contagem de ativos em text-purple-400, botao "Definir DNA do Negocio" (bg-gradient purple→pink/20, border-purple-700/40, icone Brain)
- 3 summary cards em grid sm:grid-cols-3: "Assistentes ativos" (X de Y), "Motor de IA" (provider default ou "Nenhum configurado" amber), AIUsageSummary component
- Grid de 12 Agent Cards em grid-cols-1 md:grid-cols-2 xl:grid-cols-3
- Provider Picker Modal (para escolher motor por agente)
- DNA Modal (para definir personalidade/regras do negocio)

AGENT CARDS (12 agentes, cada um com):
- Icone em quadrado gradiente (w-9 h-9 rounded-lg) com cor unica por agente
- Nome amigavel (label_friendly ou FRIENDLY_LABELS) + descricao em text-gray-400
- Toggle ToggleRight/ToggleLeft (28px) — purple-400 quando ativo, gray-600 quando desligado
- Card opacity-60 quando agente desligado
- Card ativo: border-gray-700/40 hover:border-purple-700/40
- Condicional (so quando habilitado):
  - Dropdown "Permissoes" (3 opcoes: confirm_write, manual_confirm, auto_read_only) com descricao abaixo
  - Botao "Motor de IA" que abre Provider Picker (mostra provider + modelo atual, icone Cpu purple-400, Settings2 gray-500)
  - Recomendacao (Star amber-400 com texto) se nenhum override configurado
  - Card "Exemplo de pergunta" (bg-gray-800/50 rounded-lg, texto italic)
  - Footer com botao "Conversar" (bg-purple-600/20 text-purple-300, icone MessageCircle)

GRADIENTES POR AGENTE (MAPEAMENTO — manter variacao de cores):
  marketing: from-pink-600 to-rose-600
  logistics: from-blue-600 to-cyan-600
  management: from-purple-600 to-indigo-600
  bar: from-amber-600 to-orange-600
  contracting: from-green-600 to-teal-600
  feedback: from-violet-600 to-purple-600
  data_analyst: from-cyan-600 to-blue-600
  content: from-fuchsia-600 to-pink-600
  media: from-orange-600 to-red-600
  documents: from-lime-600 to-green-600
  artists: from-emerald-600 to-green-600
  artists_travel: from-sky-600 to-blue-600

PROVIDER PICKER MODAL:
- Overlay: bg-black/70 backdrop-blur-sm
- Card: bg-gray-900 border-gray-700 rounded-2xl max-w-lg max-h-[85vh]
- Header: titulo "Motor de IA do assistente" + nome do agente + close X
- Body (scrollable): botao "Usar padrao do organizador" + lista de providers com modelos
- Cada modelo: nome, descricao, custo ($X.XX/1M in·out), badge "Recomendado" (Star amber-400)
- Selecionado: border-purple-600 bg-purple-600/10 com Check purple-400
- Footer: Cancelar + "Salvar escolha" (bg-purple-600 text-white)

DNA MODAL:
- Overlay: bg-black/70 backdrop-blur-sm
- Card: bg-gray-900 border-purple-700/40 rounded-2xl max-w-2xl max-h-[90vh]
- Header: icone Brain em gradiente purple→pink, titulo "DNA do Negocio", subtitulo, close X
- 2 tabs (Organizador / Evento especifico): border-b-2 purple-500 quando ativo
- Tab Organizador: 5 textareas (Descricao, Tom de voz, Regras, Publico-alvo, Topicos proibidos)
- Tab Evento: dropdown de evento (com emoji de status: verde/breve/calendario) + mesmos 5 textareas
- Dirty indicator: dot amber-400 na tab com alteracoes
- Footer: Fechar + "Salvar DNA do organizador/evento" (bg-gradient purple→pink)
- Unsaved changes dialog: bg-gray-900 border-amber-700/50, "Salvar e continuar"

TEXTAREAS DO DNA:
- Label: text-xs font-semibold text-gray-300
- Hint: text-[11px] text-gray-500
- Textarea: bg-gray-800 border-gray-700 focus:border-purple-600 rounded-lg, maxLength 4000, resize-y

REFATORACAO EXIGIDA (AIAssistants):
1. Header:
   - Icone: w-10 h-10 rounded-xl bg-gradient-to-br from-purple-600 to-purple-400 shadow-[0_0_15px_rgba(139,92,246,0.3)]
   - Titulo: text-2xl font-bold text-slate-100
   - Contagem: text-purple-400
   - Botao DNA: bg-purple-500/15 border border-purple-500/30 hover:bg-purple-500/25 text-purple-300 rounded-xl

2. Summary cards:
   - bg-slate-900/60 backdrop-blur-md border border-slate-800/60 rounded-2xl p-4
   - Label: text-[11px] uppercase tracking-wider text-slate-500
   - Valor: text-2xl font-bold text-slate-100
   - "Nenhum configurado": text-amber-400

3. Agent Cards:
   - Container: bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden transition-all
   - Ativo: hover:border-purple-500/30
   - Desligado: opacity-60 border-slate-800/40
   - Icone gradiente: manter gradientes unicos por agente, adicionar shadow-md
   - Nome: text-sm font-semibold text-slate-100
   - Descricao: text-[11px] text-slate-400
   - Toggle ativo: text-purple-400 hover:text-purple-300
   - Toggle inativo: text-slate-600 hover:text-slate-400
   - Label "Permissoes"/"Motor de IA": text-[10px] text-slate-500 uppercase tracking-wider
   - Select permissoes: bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-lg text-xs text-slate-200
   - Descricao permissao: text-[10px] text-slate-500
   - Botao Motor IA: bg-slate-800/50 border-slate-700 hover:border-purple-500/50 rounded-lg text-xs text-slate-200
   - Icone Cpu: text-purple-400
   - Recomendacao: text-[10px] text-amber-400/80 com Star
   - Card exemplo: bg-slate-800/40 rounded-lg px-3 py-2. Label text-[10px] text-slate-500. Texto italic text-xs text-slate-300
   - Footer: bg-slate-800/30 border-t border-slate-700/30
   - Botao Conversar: bg-purple-500/15 hover:bg-purple-500/25 text-purple-300 hover:text-purple-200 rounded-lg

4. Provider Picker Modal:
   - Overlay: bg-slate-950/80 backdrop-blur-sm
   - Card: bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl shadow-2xl
   - Selecionado: border-purple-500 bg-purple-500/10
   - Check: text-purple-400
   - Badge Recomendado: bg-amber-400/10 text-amber-400
   - Custo: text-[10px] text-slate-500
   - Botao salvar: bg-purple-500 hover:bg-purple-400 text-white rounded-lg

5. DNA Modal:
   - Card: bg-slate-900/95 backdrop-blur-xl border border-purple-500/20 rounded-2xl shadow-2xl
   - Header icone: from-purple-600 to-purple-400 shadow-[0_0_12px_rgba(139,92,246,0.3)]
   - Tabs: border-b-2 border-purple-400 text-slate-100 (ativo) / border-transparent text-slate-400 (inativo)
   - Dirty dot: text-amber-400
   - Textareas: bg-slate-800/50 border-slate-700 focus:border-purple-500 focus:ring-1 focus:ring-purple-500/20 rounded-xl text-sm text-slate-100
   - Labels: text-xs font-semibold text-slate-300
   - Hints: text-[11px] text-slate-500
   - Event select: bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-xl
   - Botao salvar: bg-gradient-to-r from-purple-600 to-purple-400 text-white rounded-xl shadow-[0_0_15px_rgba(139,92,246,0.3)]
   - Unsaved dialog: bg-slate-900/95 border border-amber-500/30

======================================================================
=== 2. AIAGENTS.JSX (PAGINA SECUNDARIA) ===
======================================================================

ESTADO ATUAL COMPLETO:
- Hero header: rounded-[2rem], border-fuchsia-900/30, radial-gradient roxo + linear-gradient dark, blob rosa decorativo
  - Badge "EnjoyFun AI Hub" (border-fuchsia-800/40, bg-fuchsia-950/30, icone Bot)
  - Titulo h1 text-4xl font-black "Agentes, memoria, governanca e automacao..."
  - Paragrafo descritivo text-gray-300
  - 3 stat boxes em grid sm:grid-cols-3: Agentes ativos, Providers, Default
    - Cada box: rounded-2xl border-gray-800 bg-gray-950/70 px-4 py-3
    - Label: text-[11px] uppercase tracking-wider text-gray-500
    - Valor: text-2xl font-black text-white

- Info card: gradiente purple→pink/30, border-purple-800/40, icone Zap em bg-purple-700
  - Titulo "Central de IA do organizer"
  - Subtitulo descritivo

- 3 componentes importados: AIBlueprintWorkbench, AIControlCenter, AIExecutionFeed

- Grid de Agent Cards em sm:grid-cols-2 xl:grid-cols-3 com AgentCard component:
  - Icone quadrado 12x12 com emoji de 3 letras (MKT, LOG, OPS, etc) em gradiente unico
  - Nome (h3 font-bold) + descricao (text-xs text-gray-400)
  - Badge de status (Ativo badge-green, Desligado badge-gray, Catalogo badge-gray)
  - Grid 2x1 com "Provider efetivo" e "Runtime" (Configurado green-400 / Sem chave amber-300)
  - "Superficies alvo": tags rounded-full border-gray-800 bg-gray-900 text-[11px]
  - Checkbox "Agente habilitado" com descricao em label flex rounded-xl
  - Select "Provider do agente"
  - Select "Politica de aprovacao" com descricao
  - Card "Exemplo de uso" (border-dashed border-gray-800 bg-gray-950/60, icone purple-300, italic)
  - Footer: "Atualizado: data" + botao "Salvar" (btn-primary com Save icon)

REFATORACAO EXIGIDA (AIAgents):
1. Hero header:
   - Container: rounded-2xl border border-purple-500/20 bg-[radial-gradient(circle_at_top_left,_rgba(139,92,246,0.15),_transparent_40%),linear-gradient(135deg,_rgba(15,23,42,0.95),_rgba(15,23,42,0.98))]
   - Blob decorativo: rgba(139,92,246,0.12) ao inves de pink
   - Badge: border-purple-500/30 bg-purple-950/30 text-purple-300
   - Titulo: text-4xl font-black text-slate-100
   - Paragrafo: text-slate-400
   - Stat boxes: bg-slate-900/60 border-slate-800/60 rounded-2xl. Label text-slate-500, valor text-slate-100

2. Info card:
   - bg-gradient-to-r from-purple-950/40 to-slate-900/60 border border-purple-500/20 rounded-2xl
   - Icone Zap: bg-purple-600 rounded-xl
   - Titulo: text-slate-100
   - Subtitulo: text-slate-400

3. Agent Cards:
   - Container: bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden hover:border-purple-500/30
   - Desativado: opacity-60
   - Icone quadrado: manter gradientes unicos, shadow-md
   - Nome: font-bold text-slate-100
   - Descricao: text-xs text-slate-400
   - Status badges: Ativo bg-green-500/15 text-green-400, Desligado bg-slate-700/50 text-slate-400, Catalogo bg-purple-500/15 text-purple-400
   - Provider/Runtime mini-cards: bg-slate-800/40 border border-slate-700/50 rounded-xl. Label text-slate-500, valor text-slate-200, runtime verde green-400 / amber amber-300
   - Surface tags: bg-slate-800/50 border border-slate-700/50 rounded-full text-[11px] text-slate-400
   - Checkbox label: bg-slate-800/40 border border-slate-700/50 rounded-xl. Descricao text-xs text-slate-500. Checkbox accent-purple-500
   - Selects: bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-xl text-xs text-slate-200
   - Card exemplo: border-dashed border-slate-700/50 bg-slate-800/30 rounded-xl. Icone text-purple-400. Texto italic text-slate-400
   - Footer: border-t border-slate-700/30. Data text-xs text-slate-500
   - Botao Salvar: bg-purple-500 hover:bg-purple-400 text-white rounded-xl, disabled:bg-slate-700

======================================================================
=== 3. AICONTROLCENTER.JSX (PROVIDERS) ===
======================================================================

ESTADO ATUAL:
Componente de gestao de providers de IA (OpenAI, Gemini, Claude). Lista de provider cards com:
- Formulario por provider: API key (input password), modelo padrao (select), URL base (input)
- Toggle is_active
- Toggle is_default (com badge estrela)
- Botao "Testar Conexao" e "Salvar"
- Status: Configurado (green) / Pendente (amber)
- Icone Server/Plug/ShieldCheck

REFATORACAO EXIGIDA (AIControlCenter):
1. Container: space-y entre provider cards
2. Provider cards: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-5
3. Provider ativo: border-l-2 border-purple-400
4. Provider default: badge estrela bg-amber-500/15 text-amber-400 rounded-full
5. Inputs (API key, URL): bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-xl
6. Select modelo: bg-slate-800/50 border-slate-700 focus:border-purple-500 rounded-xl
7. Toggle active: track bg-slate-700 → bg-purple-500
8. Status Configurado: bg-green-500/15 text-green-400
9. Status Pendente: bg-amber-500/15 text-amber-400
10. Botao Testar: border border-purple-500/30 text-purple-400 hover:bg-purple-500/10 rounded-xl
11. Botao Salvar: bg-purple-500 hover:bg-purple-400 text-white rounded-xl
12. Icone provider: text-purple-400

======================================================================
=== 4. AIUSAGESUMMARY.JSX (USO MENSAL) ===
======================================================================

ESTADO ATUAL:
Card compacto com Zap icon, "Uso da IA este mes", valor em R$ formatado, barra de progresso.
- Normal: text-purple-400 e bg-purple-500
- Warning (>80%): text-amber-400 e bg-amber-500
- Critical (>95%): text-red-400 e bg-red-500
- Track: bg-gray-700/50

REFATORACAO EXIGIDA:
1. Card: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4 (consistente com siblings)
2. Icone Zap: text-purple-400
3. Label: text-xs font-medium text-slate-300
4. Valor normal: text-purple-400 font-bold
5. Warning: text-amber-400
6. Critical: text-red-400
7. Progress track: bg-slate-700/50 rounded-full h-2
8. Progress fill: bg-purple-500 (normal), bg-amber-500 (warn), bg-red-500 (crit)

======================================================================
=== 5. AIBLUEPRINTWORKBENCH.JSX (BLUEPRINT + MEMORIAS + RELATORIOS) ===
======================================================================

ESTADO ATUAL:
Componente com secoes de domain targets (Artists Hub, Finance Hub) com capabilities listadas. Memorias de IA, relatorios de evento. Icones: BookOpen, Brain, Layers3, Map, Sparkles, Radar, FileStack, Wand2.

REFATORACAO EXIGIDA:
1. Container: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-6
2. Section headers: text-slate-200 font-semibold com icone text-purple-400
3. Domain cards: bg-slate-800/40 border border-slate-700/50 rounded-xl p-4
4. Capabilities: text-slate-400 text-xs, bullet points
5. Memorias: bg-slate-800/30 rounded-xl, timestamps text-slate-500
6. Botoes de acao: border border-purple-500/30 text-purple-400 hover:bg-purple-500/10

======================================================================
=== 6. AIEXECUTIONFEED.JSX (FEED DE EXECUCOES) ===
======================================================================

ESTADO ATUAL:
Feed de execucoes dos agentes com:
- Status badges: Sucesso badge-green, Falha badge-red, Bloqueado badge-yellow, Pendente badge-yellow, Executando badge-blue
- Approval badges: Sem aprovacao badge-gray, Aguardando badge-yellow, Aprovado badge-green, Rejeitado badge-red
- Risk levels: Nenhum (gray), Leitura (green), Escrita (amber), Destrutivo (red)
- Botoes Aprovar/Rejeitar com icones ShieldCheck/ShieldX
- Timestamp + duracao formatados

REFATORACAO EXIGIDA:
1. Container: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-6
2. Execution rows: bg-slate-800/30 border border-slate-700/50 rounded-xl p-4 hover:border-slate-600/50
3. Status badges:
   - Sucesso: bg-green-500/15 text-green-400
   - Falha: bg-red-500/15 text-red-400
   - Bloqueado: bg-amber-500/15 text-amber-400
   - Pendente: bg-amber-500/15 text-amber-400
   - Executando: bg-cyan-500/15 text-cyan-400
4. Risk badges:
   - Nenhum: bg-slate-800/50 text-slate-300 border-slate-700
   - Leitura: bg-green-500/10 text-green-300 border-green-500/20
   - Escrita: bg-amber-500/10 text-amber-300 border-amber-500/20
   - Destrutivo: bg-red-500/10 text-red-300 border-red-500/20
5. Botao Aprovar: bg-green-500/15 text-green-400 hover:bg-green-500/25 border border-green-500/30 rounded-xl
6. Botao Rejeitar: bg-red-500/15 text-red-400 hover:bg-red-500/25 border border-red-500/30 rounded-xl
7. Timestamp: text-slate-500 text-xs
8. Agent name: text-purple-400 font-medium
9. Skill/tool name: text-slate-300 font-mono text-xs
10. Refresh button: border border-slate-700 hover:border-purple-500/50 rounded-xl

REGRAS GLOBAIS:
- Cor de acento IA = PURPLE-500 (nao cyan). Cyan e para acoes gerais do sistema
- Manter TODA logica: toggles, saves, provider picker, DNA modal tabs, approval, execution feed
- Manter os 12 gradientes unicos por agente (so ajustar o container ao redor)
- Manter componentes importados (AIBlueprintWorkbench, AIControlCenter, AIExecutionFeed) — refatorar cada um individualmente
- Manter AIUsageSummary como card no grid de summary
- Manter o mapeamento AGENT_ICONS, AGENT_GRADIENTS, FRIENDLY_LABELS, EXAMPLE_PROMPTS
- Manter logica de Provider Picker com recomendacoes e custos
- Manter DNA modal com 2 tabs (Organizador/Evento), 5 textareas, dirty detection, unsaved dialog
- Manter botao "Conversar" que dispara enjoyfun:open-ai-chat
- React funcional + Tailwind CSS puro
```

---

# PROMPT 17 — ANALYTICAL DASHBOARD

```
MISSAO: REFATORAR O DASHBOARD ANALITICO

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore o dashboard analitico do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/AnalyticalDashboard.jsx

ESTADO ATUAL:
Titulo "Dashboard Analitico". Barra de filtros (evento, comparar evento, agrupar por). Secoes: Resumo Analitico, Curva e Performance Comercial, Operacao Comercial Consolidada, Comparativo entre Eventos, Participacao, Analise Financeira. Componentes modulares: AnalyticsFiltersBar, AnalyticsSummaryCards, AnalyticsSalesCurvePanel, AnalyticsRankingPanel, etc.

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100
2. Barra de filtros: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4. Selects padrao Aether Neon
3. SectionHeaders: icone em circulo bg colorido/15, titulo text-slate-200, badge rounded-full
4. Summary cards: bg-slate-900/60 border border-slate-800/60 rounded-2xl com valores em text-cyan-400
5. Paineis de graficos: bg-slate-900/60 border border-slate-800/60 rounded-2xl com headers text-slate-300
6. Ranking tables: header bg-slate-800/50, rows hover:bg-slate-800/30
7. Badges de secao: cores variadas (green, cyan, fuchsia, amber, yellow) sempre em formato /15 + text

REGRAS:
- Manter todos componentes modulares — trocar apenas wrappers e spacing
- Manter filtros, logica de comparacao, graficos
```

---

# PROMPT 18 — ORGANIZER FILES (Hub de Documentos)

```
MISSAO: REFATORAR A PAGINA DE DOCUMENTOS DO ORGANIZADOR

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de gestao de arquivos do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/OrganizerFiles.jsx

ESTADO ATUAL:
Titulo com icone e contagem de arquivos. Filtro de categoria + refresh. Upload area com dropzone dashed, categoria, notas, file input. Search bar com debounce. EmbeddedAIChat. Tabela de arquivos: nome, categoria, tamanho, status de parse, status de embedding, data, acoes. Pagination. Modal de dados parsed.

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100
2. Upload area: border-2 border-dashed border-slate-700 rounded-2xl p-6. Drag: border-cyan-400 bg-cyan-500/5. UploadCloud icon em text-cyan-400
3. Categoria + Notas inputs: padrao Aether Neon
4. Search: padrao Aether Neon
5. Tabela: padrao Aether Neon
6. Status parse badges: Pendente bg-slate-700/50, Processando bg-cyan-500/15 text-cyan-400 (com spinner), Processado bg-green-500/15 text-green-400, Erro bg-red-500/15 text-red-400
7. Status embedding badges: Indexando bg-cyan-500/15, Indexado bg-green-500/15, Erro bg-red-500/15
8. Acoes: re-parse hover:text-cyan-400, delete hover:text-red-400, view hover:text-purple-400
9. Modal parsed data: bg-slate-900/95 backdrop-blur-xl, tabela de preview com bg-slate-800/30
10. Botao refresh: border border-slate-700 hover:border-cyan-500/50 rounded-xl

REGRAS:
- Manter logica de upload, parse, embedding, search, delete
- Manter EmbeddedAIChat
- Manter paginacao e filtro de categoria
```

---

# PROMPT 19 — SUPER ADMIN PANEL

```
MISSAO: REFATORAR O PAINEL DO SUPER ADMIN

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore o painel de super admin do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/SuperAdminPanel.jsx

ESTADO ATUAL:
Titulo e descricao. Grid 2 colunas: form "Novo Organizador" (1/3) com nome, email, senha + tabela "Organizadores Ativos" (2/3) com ID, nome, email, data. Mensagem de sucesso/erro. Tema CLARO atual (white cards) — precisa migrar para dark.

REFATORACAO EXIGIDA:
1. Titulo: text-2xl font-bold text-slate-100
2. Grid: grid-cols-1 lg:grid-cols-3 gap-6
3. Form card: bg-slate-900/60 border border-slate-800/60 rounded-2xl p-6 (col-span-1)
4. Form inputs: padrao Aether Neon
5. Botao criar: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl w-full
6. Tabela card: bg-slate-900/60 border border-slate-800/60 rounded-2xl (col-span-2)
7. Tabela: padrao Aether Neon. ID em font-mono text-cyan-400/70
8. Mensagem sucesso: bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl
9. Mensagem erro: bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl
10. MIGRAR para dark mode completo (remover todos os bg-white, text-gray-900, etc)

REGRAS:
- Manter logica de criacao de organizador, listagem, validacao
```

---

# PROMPT 20 — EVENT FINANCE (TODAS AS SUBPAGINAS)

```
MISSAO: REFATORAR AS PAGINAS FINANCEIRAS DE EVENTOS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore todas as subpaginas financeiras do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVOS:
- frontend/src/pages/EventFinanceDashboard.jsx
- frontend/src/pages/EventFinanceBudget.jsx
- frontend/src/pages/EventFinancePayables.jsx
- frontend/src/pages/EventFinancePayableDetail.jsx
- frontend/src/pages/EventFinanceSuppliers.jsx
- frontend/src/pages/EventFinanceSettings.jsx
- frontend/src/pages/EventFinanceImport.jsx
- frontend/src/pages/EventFinanceExport.jsx

PADRAO VISUAL UNICO PARA TODAS:
1. Titulos: text-2xl font-bold text-slate-100 com icones em text-cyan-400
2. Event selectors: padrao Aether Neon
3. KPI cards: bg-slate-900/60 border border-slate-800/60 rounded-2xl. Valores com cores semanticas (cyan receita, green pago, amber comprometido, red vencido, purple saldo)
4. Tabelas: header bg-slate-800/50 text-slate-400 uppercase text-xs, rows hover:bg-slate-800/30
5. Status badges: paid bg-green-500/15, pending bg-amber-500/15, overdue bg-red-500/15, cancelled bg-slate-700/50
6. Modais: padrao Aether Neon (bg-slate-900/95 backdrop-blur-xl)
7. Progress bars: bg-slate-800 rounded-full, fill com bg-gradient-to-r from-cyan-500 to-green-500 (normal), from-amber-500 to-red-500 (over-budget)
8. Botoes de acao: padrao Aether Neon (primario cyan, secundario outline, perigo red)
9. Valores monetarios: font-mono tabular-nums
10. Cards de estatistica: h-[2px] gradient bar no topo decorativo

ESPECIFICOS POR PAGINA:
- Dashboard: KPI cards flex-wrap, progress bar de utilizacao
- Budget: 4 summary cards grid-cols-2 md:grid-cols-4, variance com TrendingUp/Down coloridos, linhas over-budget com bg-red-500/5
- Payables: search + 2 filter dropdowns, tabela paginada, badge ChevronRight, row overdue com bg-red-500/5
- PayableDetail: 3 stat cards (total, pago, saldo), progress bar, historico de pagamentos, secao artista com border-cyan-500/20
- Suppliers: cards colapsaveis de fornecedores, contratos com status badges, forms inline
- Settings: tabs Categories/CostCenters, tabela com toggle active, modal create/edit, ID em font-mono text-purple-400
- Import: stepper 4 passos (circulos: completo green-500, atual cyan-500, futuro slate-700), dropzone, preview table com valid/invalid badges
- Export: grid de export cards com icones, download buttons

REGRAS:
- Manter toda logica financeira, CRUD, filtros, paginacao
- Manter cancelamento, pagamentos, reversoes
- Valores sempre formatados pt-BR
```

---

# PROMPT 21 — ARTISTS (Catalogo, Detalhe, Importacao)

```
MISSAO: REFATORAR AS PAGINAS DE ARTISTAS

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore as paginas de gestao de artistas do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVOS:
- frontend/src/pages/ArtistsCatalog.jsx
- frontend/src/pages/ArtistDetail.jsx
- frontend/src/pages/ArtistImport.jsx

=== ARTISTSCATALOG ===
Header com icone, titulo, descricao. 3 stat cards. Toggle view (By Event / Catalog). Search + filtros. EmbeddedAIChat. Tabela de artistas com colunas condicionais. Modal de criacao (form extenso). Badges de severidade.

=== ARTISTDETAIL ===
Header com breadcrumb/back. 6 tabs: Bookings, Logistics, Timeline, Alerts, Team, Files. Stat cards por tab. Tabelas, forms de edicao, upload de arquivos. EmbeddedAIChat.

=== ARTISTIMPORT ===
4-step wizard (Configure, Select File, Preview, Confirm). Radio buttons, file dropzone, preview table, result summary.

REFATORACAO EXIGIDA:
1. Catalogo:
   - Stat cards: bg-slate-900/60 border border-slate-800/60 rounded-2xl
   - View toggle: bg-slate-800/50 rounded-xl p-1. Ativo: bg-cyan-500 text-slate-950. Inativo: text-slate-400
   - Search + filtros: padrao Aether Neon
   - Tabela: padrao Aether Neon
   - Severity badges: red bg-red-500/15, yellow bg-amber-500/15, green bg-green-500/15
   - Cache/custo: text-emerald-400 font-mono
   - Modal form: padrao Aether Neon com secoes separadas por border-t border-slate-800/40

2. Detalhe:
   - Tabs: border-b border-slate-800/40. Tab ativa: border-b-2 border-cyan-400 text-cyan-400
   - Stat cards por tab: mesmo estilo glass
   - DetailRow: label text-slate-500, valor text-slate-200
   - Timeline: line vertical bg-slate-700, dots com cores de status
   - Alert cards: severidade com bordas coloridas (border-l-2)
   - Team table: padrao Aether Neon
   - File cards: bg-slate-800/40 rounded-xl com icone de tipo
   - Empty states: icone text-slate-600, texto text-slate-500

3. Import:
   - Stepper: circulos completo bg-green-500, atual bg-cyan-500, futuro bg-slate-700
   - Radio buttons: bg-slate-800/40 border border-slate-700/50. Selecionado: border-cyan-400 bg-cyan-500/10
   - Dropzone: padrao Aether Neon
   - Preview: valid bg-green-500/15, invalid bg-red-500/15
   - Result: success text-green-400, skipped text-amber-400

REGRAS:
- Manter toda logica de CRUD, tabs, import wizard, file upload
- Manter EmbeddedAIChat em catalogo e detalhe
- Arquivos grandes — trocar APENAS classes visuais
```

---

# PROMPT 22 — CUSTOMER APP (Login, Dashboard, Recharge)

```
MISSAO: REFATORAR O APP DO PARTICIPANTE/CLIENTE

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore as paginas do app de participante do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVOS:
- frontend/src/pages/CustomerApp/CustomerLogin.jsx
- frontend/src/pages/CustomerApp/CustomerDashboard.jsx
- frontend/src/pages/CustomerApp/CustomerRecharge.jsx

=== CUSTOMERLOGIN ===
Full-screen com gradiente radial roxo. Card centralizado. Logo com badge Zap. Event name pill. 2 steps: Step 1 (input email/telefone + botao enviar codigo), Step 2 (input OTP 6 digitos + botao entrar). Progress dots. Method indicators.

=== CUSTOMERDASHBOARD ===
Gradiente radial roxo. Header com nome do evento + logout. Balance card (gradiente purple→blue) com saldo grande. Quick actions grid (3 colunas): Recarregar (green), QR Code (purple), Extrato (blue). Secao Ingressos (carousel horizontal snap-x). Secao Transacoes (lista com credito/debito). Ticket modal slide-up com QR.

=== CUSTOMERRECHARGE ===
Gradiente background. Back button + titulo. Step 1: preset amounts grid (R$10, R$25, R$50, R$100) + custom input. Step 2: QR code gerado + Pix copia-e-cola + timer.

REFATORACAO EXIGIDA:
1. Background geral: bg-slate-950 com radial-gradient(circle at 50% 0%, rgba(0,240,255,0.05), transparent 60%)

2. CustomerLogin:
   - Card: bg-slate-900/70 backdrop-blur-xl border border-slate-700/50 rounded-2xl
   - Logo badge: bg-gradient-to-br from-cyan-500 to-purple-500, glow shadow
   - Event pill: bg-purple-500/15 text-purple-400 rounded-full
   - Input: bg-slate-800/50 border-slate-700 focus:border-cyan-500 rounded-xl text-lg
   - Botao enviar: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl
   - OTP input: text-3xl tracking-[0.5em] text-center bg-slate-800/50
   - Progress dots: bg-cyan-400 (ativo), bg-slate-700 (inativo)
   - Method pills: bg-slate-800/50 text-slate-400 rounded-full

3. CustomerDashboard:
   - Balance card: bg-gradient-to-br from-cyan-950/80 to-purple-950/80 border border-cyan-500/20 rounded-2xl. Saldo text-4xl font-bold text-cyan-400. Icone Wallet
   - Quick actions: bg-slate-800/40 border border-slate-700/50 rounded-xl p-4 hover:border-cyan-500/30
   - Recarregar: icone text-green-400. QR: icone text-purple-400. Extrato: icone text-cyan-400
   - Ticket cards (carousel): bg-slate-900/60 border border-slate-800/60 rounded-xl snap-center. Status: Valido bg-green-500/15, Usado bg-slate-700/50, Cancelado bg-red-500/15
   - Transacoes: bg-slate-800/30 rounded-xl. Credito text-emerald-400, Debito text-red-400
   - Ticket modal: bg-slate-900/95 backdrop-blur-xl slide-up animation. QR com fundo branco. Info em text-slate-300

4. CustomerRecharge:
   - Preset amounts: bg-slate-800/40 border border-slate-700/50 rounded-xl. Selecionado: border-cyan-400 bg-cyan-500/10 text-cyan-400 font-bold
   - Custom input: padrao Aether Neon com R$ prefix
   - Summary card: bg-cyan-500/10 border border-cyan-500/20 rounded-xl
   - Botao gerar QR: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl
   - QR gerado: bg-white rounded-xl p-4 (fundo branco para scan)
   - Pix copia-cola: bg-slate-800/50 font-mono text-xs truncate. Botao copiar: bg-cyan-500 text-slate-950. Copiado: bg-green-500 text-white
   - Timer: text-amber-400 text-sm

REGRAS:
- Manter logica OTP (2 steps), balance API, tickets carousel, Pix gerarion
- Manter animacoes (slide-up modal, snap-x carousel)
- Layout mobile-first (max-w-md)
```

---

# PROMPT 23 — DOWNLOAD (PWA)

```
MISSAO: REFATORAR A PAGINA DE DOWNLOAD DO APP

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de download do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/Download.jsx

ESTADO ATUAL:
Dark gradient background. Header hero centralizado. Grid de 3 cards (iOS, Android, PWA) com icones Apple/Smartphone/Download. Secao de beneficios (4 items com CheckCircle). Footer com suporte.

REFATORACAO EXIGIDA:
1. Background: bg-slate-950 com gradient radial ciano sutil
2. Hero: text-4xl font-bold text-slate-100, subtitulo text-slate-400
3. Cards plataforma: bg-slate-900/60 backdrop-blur-md border border-slate-800/60 rounded-2xl p-6 hover:border-cyan-500/30 hover:shadow-[0_0_20px_rgba(0,240,255,0.1)]
4. Icone plataforma: circulo bg-cyan-500/15 com icone text-cyan-400, w-16 h-16
5. Titulo card: text-slate-100 font-semibold
6. Descricao card: text-slate-400 text-sm
7. Botao download: bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 rounded-xl w-full
8. Botao instalado: bg-green-500/15 text-green-400 border border-green-500/30 rounded-xl
9. Beneficios: icone CheckCircle em text-cyan-400, texto text-slate-300
10. Footer: text-slate-600

REGRAS:
- Manter deteccao de plataforma (iOS/Android/Desktop)
- Manter PWA install prompt
- Manter deep links para stores
```

---

# PROMPT 24 — GUEST TICKET (Convite Digital / Credencial)

```
MISSAO: REFATORAR A PAGINA DE CONVITE DIGITAL

Voce e um Especialista em UI/UX e Engenheiro Front-end (React + Tailwind CSS).
Refatore a pagina de convite/credencial digital do EnjoyFun aplicando o Design System "Aether Neon".

ARQUIVO: frontend/src/pages/GuestTicket.jsx

ESTADO ATUAL:
Full-screen centralizado com card de ticket. Header com nome do evento e data. Nome do participante e role. Grid de info (4 cards para workforce: Setor, Turnos, Horas, Refeicoes). Label de settings. Status badge. QR Code em fundo branco. Token de referencia. Logo do evento opcional. Tipo diferente para convidado vs equipe.

REFATORACAO EXIGIDA:
1. Background: bg-slate-950 com gradient radial sutil
2. Ticket card: bg-slate-900/80 backdrop-blur-xl border border-slate-700/50 rounded-2xl shadow-2xl max-w-sm mx-auto
3. Header do evento: bg-gradient-to-br from-cyan-950/60 to-purple-950/60 rounded-t-2xl p-5. Nome em text-slate-100 font-bold, data em text-slate-400
4. Tipo badge: Convidado bg-cyan-500/15 text-cyan-400, Equipe bg-purple-500/15 text-purple-400
5. Nome participante: text-2xl font-bold text-slate-100
6. Info grid (workforce): bg-slate-800/40 border border-slate-700/50 rounded-xl. Label text-slate-500 text-xs uppercase, valor text-slate-200 font-semibold
7. Settings label: bg-slate-800/30 text-slate-500 text-xs rounded-full px-3 py-1
8. Status badge: Validado bg-green-500/15 text-green-400, Pronto bg-cyan-500/15 text-cyan-400
9. QR container: bg-white rounded-xl p-4 mx-auto (fundo branco para scan)
10. Token: font-mono text-slate-500 text-xs tracking-wider
11. Logo evento: rounded-xl com border border-slate-700/50

REGRAS:
- Manter logica de tipo (convidado vs equipe)
- Manter QR code rendering
- Manter dados condicionais de workforce
- Layout mobile-first centralizado
```

---

# FIM DOS PROMPTS

## COMO USAR:
1. Copie o prompt da pagina desejada
2. Cole no seu assistente de codigo (Cursor, Claude Code, etc)
3. Cada prompt ja contem: estado atual, estrutura a manter, refatoracao exigida, regras
4. O Design System "Aether Neon" esta no topo deste documento como referencia global
5. Sempre inclua a secao "DESIGN SYSTEM GLOBAL" junto com o prompt da pagina especifica

## ORDEM RECOMENDADA DE EXECUCAO:
1. Master Layout (Prompt 01) — casca principal, afeta tudo
2. Login (Prompt 02) — primeira impressao
3. Dashboard (Prompt 03) — pagina mais vista
4. Depois, qualquer ordem conforme prioridade
