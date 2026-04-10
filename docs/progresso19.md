# Progresso 19 — Hub de IA Multi-Agentes: Overhaul Completo

**Data:** 2026-04-06
**Sprint:** IA Multi-Agentes — Diferencial competitivo da plataforma
**Autor:** Andre + Claude

---

## Resumo executivo

O sistema de IA da EnjoyFun foi completamente reescrito e expandido em uma unica sessao de trabalho. Saimos de 6 agentes com prompts de 1 linha e 2 tools para **12 agentes com prompts profissionais multi-secao, 33+ tools, MCP foundation, file hub nativo e componentes embarcados**.

---

## O que foi entregue

### 1. Prompt Overhaul — 12 agentes profissionais

Cada agente agora tem prompt multi-secao com: Identidade, Persona & Tom, Dominio, Formato de Saida, Cadeia de Raciocinio, Exemplos (few-shot), Restricoes, e Temperature calibrada por funcao.

| Agente | Papel | Surfaces | Temperature |
|--------|-------|----------|-------------|
| marketing | Demanda, conversao, campanhas | dashboard, tickets, messaging, customer | 0.5 |
| logistics | Fluxo operacional, filas, cobertura | parking, meals-control, workforce, events | 0.25 |
| management | Sintese executiva, KPIs, decisao | dashboard, analytics, finance, general | 0.25 |
| bar | PDV, estoque, ritmo de venda | bar, food, shop | 0.25 |
| contracting | Fornecedores, contratos, pagamentos | artists, finance, settings | 0.2 |
| feedback | Experiencia do participante | messaging, customer, analytics | 0.5 |
| **data_analyst** (novo) | Cruzamento de dados, padroes, anomalias | dashboard, analytics, finance | 0.2 |
| **content** (novo) | Posts, campanhas, copy, comunicados | messaging, marketing, customer | 0.7 |
| **media** (novo) | Prompts de imagem, briefings visuais | marketing | 0.6 |
| **documents** (novo) | Le planilhas, categoriza custos | finance | 0.2 |
| **artists** (novo) | Logistica, timeline, alertas, custos | artists | 0.3 |
| **artists_travel** (novo) | Passagens, hotel, transfers, fechamento | artists | 0.3 |

**Arquivos:** `AIPromptCatalogService.php`, `AIProviderConfigService.php`

### 2. Skills/Tools — de 2 para 33+

| Grupo | Tools | Tipo |
|-------|-------|------|
| Workforce | get_workforce_tree_status, get_workforce_costs | read |
| Artists (8) | event_summary, logistics_detail, timeline_status, alerts, cost_breakdown, team_composition, transfer_estimations, search_by_status | read |
| Artists Travel (6) | travel_requirements, venue_location, update_logistics, create_logistics_item, update_timeline_checkpoint, close_artist_logistics | read+write |
| Logistics (3) | parking_live_snapshot, meal_service_status, event_shift_coverage | read |
| Management (2) | event_kpi_dashboard, finance_summary | read |
| Bar (2) | pos_sales_snapshot, stock_critical_items | read |
| Marketing (1) | ticket_demand_signals | read |
| Contracting (2) | artist_contract_status, pending_payments | read |
| Data Analyst (2) | cross_module_analytics, event_comparison | read |
| Documents (3) | get_organizer_files, get_parsed_file_data, categorize_file_entries | read+write |
| Content (1) | event_content_context | read |

**Refatoracao:** Tool registry pattern com `allToolDefinitions()`, filtro por surface/agent_key, dispatch via match statement. Backward-compatible.

**Arquivo:** `AIToolRuntimeService.php`

### 3. Surface 'artists' — Context Builder

Novo builder que puxa dados de 11 tabelas do modulo de artistas:
- Booking status distribution (confirmed/pending/cancelled)
- Total cost exposure (cache + logistics items)
- Alert severity distribution (red/orange/yellow)
- Logistics completeness rate
- Timeline status distribution
- Team members summary (hotel/transfer needs)
- Transfer estimations count
- Recent alerts (top 5 by severity)
- Per-artist summary (top 20 by alert priority)
- Artist em foco (detalhe completo quando event_artist_id fornecido)

**Arquivo:** `AIContextBuilderService.php`

### 4. MCP Foundation

**Decisao arquitetural:** MCP para ferramentas EXTERNAS. Upload nativo para arquivos do organizador.

**Migration 056 (aplicada):**
- `organizer_mcp_servers` — servidores MCP por organizador (URL, auth, agentes permitidos)
- `organizer_mcp_server_tools` — tools descobertas automaticamente por servidor

**Service:** `AIMCPClientService.php`
- `discoverTools()` — chama MCP server, cacheia tools no banco
- `executeToolCall()` — forwarda tool call para MCP server externo
- `buildMCPToolCatalog()` — merge tools MCP no catalogo do runtime

**Controller:** `MCPServerController.php` (rota: `/organizer-mcp`)
- CRUD de servidores MCP
- Discovery de tools (`POST /organizer-mcp/{id}/discover`)
- Listagem e configuracao de tools por servidor
- Integrado no tool runtime: MCP tools aparecem automaticamente para os agentes

### 5. Organizer File Hub

**Migration 057 (aplicada):**
- `organizer_files` — hub de arquivos do organizador com parsing automatico
  - Categorias: general, financial, contracts, logistics, marketing, operational, reports, spreadsheets
  - Parsed status: pending, parsing, parsed, failed, skipped
  - `parsed_data` (jsonb): dados extraidos do arquivo

**Controller:** `OrganizerFileController.php` (rota: `/organizer-files`)
- Upload com validacao de MIME e tamanho (max 20MB)
- Auto-parse de CSV e JSON no upload
- CSV parser: detecta delimitador (`,`/`;`), normaliza headers, detecta tipos de coluna (numeric/date/text), limita 500 linhas
- JSON parser: detecta array of objects, extrai keys, limita 500 items
- Reparse sob demanda (`POST /organizer-files/{id}/parse`)
- Delete com limpeza do arquivo fisico

**Frontend:** `OrganizerFiles.jsx` (rota: `/files`)
- Upload com selecao de categoria e notas
- Listagem com filtro por categoria
- Visualizacao de dados parseados (tabela interativa para CSV)
- Deteccao de tipos de coluna
- Re-processamento e exclusao
- Link no Sidebar: "Documentos"

### 6. Frontend — Agentes e Assistentes

**AIAgents.jsx:** 12 agentes com icones dedicados (MicVocal, Plane, Database, PenLine, Image, FileSpreadsheet)

**ArtistAIAssistant.jsx:** Componente embarcado com:
- 4 cards de metricas (artistas confirmados, alertas, custo total, timeline)
- Chat com historico de mensagens
- Context payload com surface=artists e event_artist_id (quando em foco)
- Conectado em `ArtistsCatalog.jsx` (visao geral) e `ArtistDetail.jsx` (foco no artista)

---

## Arquivos criados

| Arquivo | Tipo |
|---------|------|
| `database/056_mcp_servers.sql` | Migration |
| `database/057_organizer_file_hub.sql` | Migration |
| `backend/src/Services/AIMCPClientService.php` | Service |
| `backend/src/Controllers/MCPServerController.php` | Controller |
| `backend/src/Controllers/OrganizerFileController.php` | Controller |
| `frontend/src/components/ArtistAIAssistant.jsx` | Component |
| `frontend/src/pages/OrganizerFiles.jsx` | Page |

## Arquivos modificados

| Arquivo | Mudanca |
|---------|---------|
| `backend/src/Services/AIPromptCatalogService.php` | 12 prompts profissionais + surface artists + buildArtistsPrompt + report blueprint |
| `backend/src/Services/AIProviderConfigService.php` | 12 agentes no metadata |
| `backend/src/Services/AIToolRuntimeService.php` | 33+ tools, registry pattern, MCP merge, dispatch refactored |
| `backend/src/Services/AIContextBuilderService.php` | Surface artists completa (11 tabelas) |
| `backend/public/index.php` | Rotas organizer-mcp e organizer-files |
| `frontend/src/pages/AIAgents.jsx` | 12 agentes com icones |
| `frontend/src/pages/ArtistsCatalog.jsx` | ArtistAIAssistant conectado |
| `frontend/src/pages/ArtistDetail.jsx` | ArtistAIAssistant com foco |
| `frontend/src/components/Sidebar.jsx` | Link "Documentos" |
| `frontend/src/App.jsx` | Rota /files |

---

## Decisoes arquiteturais

1. **Upload nativo vs MCP:** Upload de arquivos do organizador e NATIVO (tabela `organizer_files`). MCP e para ferramentas EXTERNAS (Google Drive, Sheets, APIs). Complementares.

2. **Tool registry pattern:** Todas as tools definidas em `allToolDefinitions()` com campos `surfaces[]` e `agent_keys[]`. `buildToolCatalog()` filtra por contexto. Dispatch via match statement.

3. **MCP tools default risk=write:** Tools de MCP servers externos sempre classificadas como `risk_level: write` por padrao. O organizador pode reclassificar por tool.

4. **Temperature por agente:** Agentes analiticos (management, bar, data_analyst, documents, contracting) usam 0.2-0.25. Agentes criativos (content, media, feedback, marketing) usam 0.5-0.7. Operacionais (logistics, artists) usam 0.25-0.3.

5. **CSV auto-parse:** Parser detecta delimitador, normaliza headers, detecta tipos de coluna. Limite de 500 linhas por seguranca. O agente `documents` le o `parsed_data` via tool.

---

## Proximos passos

- [ ] Smoke test E2E: POST /ai/insight com cada surface (artists, dashboard, workforce, parking, bar)
- [ ] Smoke test: upload de CSV/JSON e consulta ao agente documents
- [ ] Smoke test: MCP server discovery e tool execution
- [ ] Parser de Excel (.xlsx) no OrganizerFileController
- [ ] UI de MCP no frontend (AIControlCenter nova tab)
- [ ] Integrar ArtistAIAssistant no ArtistDetail com stats reais do booking
