# Progresso 29 — PDV Points + Componentes Visuais Avancados

**Data:** 2026-04-15
**Sessao:** 4 commits, 3 features novas
**Autor:** Claude Opus 4.6 + Andre

---

## Resumo Executivo

Sprint focada em conectar produtos a PDV points especificos (P1), criar block types especializados no backend (P2 parcial), e implementar 3 componentes visuais avancados para gestao de eventos (P3).

---

## FASE 1 — Produtos vinculados a PDV Points (P1)

### Problema
Produtos tinham apenas `sector` (bar/food/shop), agrupando todos os "bar" juntos. Impossivel saber estoque critico por bar individual (ex: "Bar Palco 1" vs "Bar VIP").

### Solucao
- **Migration 102:** `pdv_point_id` em products (FK nullable para event_pdv_points)
- **ProductService:** list/create/update aceitam e retornam `pdv_point_id` + `pdv_point_name` via JOIN
- **StockForm.jsx:** Dropdown de Ponto de Venda filtrado por setor do PDV atual
- **StockListRow.jsx:** Badge roxo mostrando nome do PDV point vinculado
- **DashboardDomainService:** JOIN hibrido — usa `pdv_point_id` quando disponivel, fallback por sector type
- **CriticalStockPanel:** Mostra `pdv_point_name` por produto no Dashboard

### Graceful fallback
- `columnExists('products', 'pdv_point_id')` em todas as queries
- Funciona com e sem a migration aplicada

### Commit
```
09960cf feat(pos): link products to specific PDV points via pdv_point_id
```

---

## FASE 2 — Block Types Especializados (Backend + Web)

### Mudanca
AdaptiveResponseService agora emite block types `event_stages`, `event_sectors`, `event_sessions` em vez de `table`/`timeline` genericos.

### Web fallback
AdaptiveUIRenderer.jsx mapeia os novos tipos de volta para TableBlock/TimelineBlock com colunas corretas.

### Mobile (PENDENCIA)
5 blocos mobile nao foram escritos (permissao de escrita no worktree mobile negada). Ficam como divida tecnica.

**Blocos pendentes:**
- `EventStagesBlock.tsx` — cards horizontais por palco com icone de tipo e capacidade
- `EventSectorsBlock.tsx` — lista de setores com badge de ajuste de preco
- `EventSessionsBlock.tsx` — agenda com coluna de horario, divider, tipo badge
- `EventTablesBlock.tsx` — cards de mesas com ocupacao
- `EventMapsBlock.tsx` — botao Google Maps + imagens de mapas uploadados

**Types pendentes no mobile:**
```typescript
// Adicionar em enjoyfun-app/src/lib/types.ts
export interface EventStage { id?: number; name: string; stage_type: string; capacity?: number; }
export interface EventStagesBlock { type: 'event_stages'; id: string; title?: string; stages: EventStage[]; }
export interface EventSector { id?: number; name: string; sector_type: string; capacity?: number; price_modifier?: number; }
export interface EventSectorsBlock { type: 'event_sectors'; id: string; title?: string; sectors: EventSector[]; }
export interface EventSession { id?: number; title?: string; name?: string; starts_at: string; ends_at?: string; speaker_name?: string; session_type?: string; }
export interface EventSessionsBlock { type: 'event_sessions'; id: string; title?: string; sessions: EventSession[]; }
export interface EventTable { id?: number; number?: number; table_label?: string; seats?: number; guests_count?: number; section?: string; }
export interface EventTablesBlock { type: 'event_tables'; id: string; title?: string; tables: EventTable[]; }
export interface EventMapItem { image_url?: string; label?: string; }
export interface EventMapsBlock { type: 'event_maps'; id: string; title?: string; maps: EventMapItem[]; google_maps_url?: string; }
```

### Commit
```
e03327d feat(ai): specialized block types for event_stages, event_sectors, event_sessions
```

---

## FASE 3 — Componentes Visuais Avancados (P3)

### 3.1 MapBuilder (`components/MapBuilder.jsx`)
- Canvas interativo 800x500 com grid pontilhado
- Elementos: palco, bar, alimentacao, loja, banheiro, entrada, estacionamento
- Auto-carrega palcos e PDV points cadastrados no evento
- Drag-and-drop com snap-to-grid (20px)
- Zoom (50%-200%) com controle visual
- Editor de label inline ao selecionar elemento
- Toolbar com botao de cada tipo + cores distintas por tipo

### 3.2 SeatingChart (`components/SeatingChart.jsx`)
- Canvas 800x600 com mesas visuais (circulos/retangulos)
- Drag-and-drop de mesas com snap-to-grid
- Anel SVG de ocupacao (progresso circular) por mesa
- Indicacao visual de mesa lotada (verde)
- Painel de detalhes com lista de convidados atribuidos
- Form inline pra adicionar nova mesa
- Substitui o CRUD simples `SeatingSection`

### 3.3 AgendaBuilder (`components/AgendaBuilder.jsx`)
- Timeline Gantt multi-track agrupada por palco
- Grid horaria 07:00-24:00 com blocos posicionados por horario
- Cores por tipo de sessao (6 tipos: keynote, painel, workshop, poster, mesa redonda, intervalo)
- Legenda visual com badge por tipo
- Hover revela botao de delete inline
- Exibe palestrante e horarios no bloco
- Form inline pra nova sessao com datetime-local + dropdown de palco
- Substitui o CRUD simples `SessionsSection`

### Integracao
- `Events.jsx` importa os 3 componentes
- `SeatingChart` substitui `SeatingSection` no modulo "seating"
- `AgendaBuilder` substitui `SessionsSection` no modulo "sessions"
- `MapBuilder` e adicionado apos `MapsSection` no modulo "maps"

### Commit
```
d61fe0d feat(events): visual MapBuilder, SeatingChart, AgendaBuilder components
```

---

## Commits da Sessao (4 total)

```
d61fe0d feat(events): visual MapBuilder, SeatingChart, AgendaBuilder components
e03327d feat(ai): specialized block types for event_stages, event_sectors, event_sessions
09960cf feat(pos): link products to specific PDV points via pdv_point_id
fa51e59 docs: update progresso28 (71 commits), runbook, prompt for next session
```

---

## Pendencias

### Divida Tecnica — Mobile Blocks (P2)
- 5 blocos novos no enjoyfun-mobile/enjoyfun-app/src/components/blocks/
- Types novos no types.ts
- Registro no AdaptiveUIRenderer.tsx mobile
- Codigo pronto (descricao acima), so precisa escrever nos arquivos

### Prioridade 4 — SuperAdmin fase 2
- Self-registration de organizadores (tela publica + aprovacao)
- Billing/pagamento pre-pago pra APIs
- Agente IA de auditoria automatica do sistema
- Planos: 3 niveis com porcentagem automatica

### Prioridade 5 — B2C Participant App
- Telas de ingressos, cashless, menu/pedidos no enjoyfun-app
- Integrar com dados de event_stages, event_sectors, mapas
- Landing page publica do evento (SEO)
- Referencia visual: enjoyfunuiux/ (mockups prontos)

---

*EnjoyFun Platform — Sessao 29 concluida com 4 commits*
*2026-04-15*
