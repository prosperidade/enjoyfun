# Prompt para proximo chat — EnjoyFun

Cole isso no inicio do proximo chat:

---

Leia o CLAUDE.md e docs/progresso29.md antes de qualquer acao.

## Contexto

Sessao anterior (4 commits):
- P1: Produtos vinculados a PDV points especificos (migration 102, pdv_point_id em products)
- P2 parcial: Backend emite block types especializados (event_stages, event_sectors, event_sessions). Web fallback implementado. Mobile blocos PENDENTES (divida tecnica)
- P3: 3 componentes visuais avancados criados e integrados:
  - MapBuilder: canvas drag-and-drop de palcos/bares/lojas com zoom e snap-to-grid
  - SeatingChart: mapa de mesas visual com anel de ocupacao e convidados
  - AgendaBuilder: timeline Gantt multi-track por palco, 6 tipos de sessao

## Stack ativa
- Backend: PHP 8.4 porta 8080
- Frontend: React + Vite porta 3003
- Banco: PostgreSQL 18.2 (enjoyfun, 127.0.0.1:5432)
- Redis: porta 6380 (docker-compose.services.yml)

## Documentacao de referencia
- CLAUDE.md — estado real do sistema (atualizado 2026-04-15)
- docs/progresso29.md — diario desta sessao
- docs/progresso28.md — diario da sessao anterior (71 commits)
- docs/adr_multi_event_customizable_v1.md — arquitetura multi-evento
- docs/runbook_local.md — bootstrap + migrations + endpoints
- enjoyfunuiux/stitch_eventverse_immersive_hub/ — 62 mockups de UI/UX do app participante

## Proximas missoes (por prioridade)

### P1 — Mobile blocks (divida tecnica)
- 5 blocos no enjoyfun-mobile/enjoyfun-app/src/components/blocks/
- EventStagesBlock.tsx, EventSectorsBlock.tsx, EventSessionsBlock.tsx, EventTablesBlock.tsx, EventMapsBlock.tsx
- Types novos no types.ts + registro no AdaptiveUIRenderer.tsx
- Descricao completa em docs/progresso29.md (FASE 2)

### P2 — SuperAdmin fase 2
- Self-registration: tela publica pra organizador se cadastrar + aprovacao pelo admin
- Billing: modelo pre-pago pra APIs, controle de tokens por organizador
- Agente IA de auditoria: varredura automatica com alertas urgente/critico/saudavel
- Planos: 3 niveis com porcentagem automatica

### P3 — B2C Participant App
- Telas no enjoyfun-app: ingressos, cashless/cartao, menu/pedidos
- Referencia visual: enjoyfunuiux/ (mockups prontos)
- Conectar com dados de event_stages, event_sectors, mapas
- Landing page publica do evento (SEO)

### P4 — Polimento dos componentes visuais
- MapBuilder: persistir posicoes dos elementos no banco (campo JSON em events)
- SeatingChart: drag de convidados para mesas (atribuicao visual)
- AgendaBuilder: drag vertical de sessoes pra mudar horario
- Instalar @dnd-kit/core se drag nativo nao for suficiente

## Regras
- NAO mexer em agentes IA (AIPromptCatalogService, AIOrchestratorService, AIIntentRouterService)
- Cada fix = 1 commit atomico
- Dark theme padrao em toda a plataforma
- organizer_id vem SEMPRE do JWT
- Table existence checks em todo campo novo (graceful fallback)
- Logar como admin@enjoyfun.com.br (organizer_id=2)
