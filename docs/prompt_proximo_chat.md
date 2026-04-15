# Prompt para proximo chat — EnjoyFun

Cole isso no inicio do proximo chat:

---

Leia o CLAUDE.md e docs/progresso28.md antes de qualquer acao.

## Contexto

Sessao anterior (71 commits em 2 dias):
- Auditoria completa de 8 telas do frontend (30+ bugs corrigidos)
- Arquitetura multi-evento customizavel implementada (ADR + 13 migrations + 10 controllers + 12 module sections)
- 12 tipos de evento + custom com modulos arrastáveis
- SuperAdmin com 4 abas (Organizadores, APIs/Tokens, Saude, Financeiro)
- Convite digital publico com RSVP (/convite/:slug/:token)
- Upload de arte do convite + banner no GuestTicket
- Integração mobile (backend detecta stages/sectors/sessions como blocos)
- Todas as migrations 087-101 aplicadas no banco
- Dark theme consistente em toda a plataforma

## Stack ativa
- Backend: PHP 8.4 porta 8080
- Frontend: React + Vite porta 3003
- Banco: PostgreSQL 18.2 (enjoyfun, 127.0.0.1:5432)
- Redis: porta 6380 (docker-compose.services.yml)

## Documentacao de referencia
- CLAUDE.md — estado real do sistema (atualizado 2026-04-15)
- docs/adr_multi_event_customizable_v1.md — arquitetura multi-evento
- docs/progresso28.md — diario completo das 2 sessoes
- docs/runbook_local.md — bootstrap + migrations + endpoints
- enjoyfunuiux/stitch_eventverse_immersive_hub/ — 62 mockups de UI/UX do app participante

## Proximas missoes (por prioridade)

### P1 — Conectar produtos aos PDV points
- Hoje: products tem campo `sector` (bar/food/shop) mas nao `pdv_point_id`
- Problema: estoque critico no Dashboard agrupa por tipo, nao por bar individual
- Solucao: migration adicionando `pdv_point_id` em products + atualizar ProductService + StockForm com dropdown de PDV point
- Impacto: Dashboard mostra "Bar Palco 1: 3 itens criticos" em vez de "Bar: 5 itens"

### P2 — Mobile app (5 blocos novos)
- Local: enjoyfun-mobile/enjoyfun-app/src/components/blocks/
- Backend ja detecta e formata (AdaptiveResponseService atualizado)
- Criar: EventStagesBlock.tsx, EventSectorsBlock.tsx, EventSessionsBlock.tsx, EventTablesBlock.tsx, EventMapsBlock.tsx
- Registrar no AdaptiveUIRenderer.tsx
- Atualizar types.ts com novas interfaces
- Referencia: ver blocos existentes (LineupBlock, MapBlock, TimelineBlock)

### P3 — Componentes visuais avancados
- MapBuilder: drag-and-drop de palcos/bares num canvas (pra planta do evento)
- SeatingChart: mesas com drag-and-drop de convidados (casamento/formatura)
- AgendaBuilder: sessoes/palestras em timeline visual (congresso/corporativo)
- Estes componentes seriam reutilizaveis e substituiriam os CRUDs simples atuais

### P4 — SuperAdmin fase 2
- Self-registration: tela publica pra organizador se cadastrar + aprovacao pelo admin
- Billing: modelo pre-pago pra APIs, controle de tokens por organizador
- Agente IA de auditoria: varredura automatica com alertas urgente/critico/saudavel
- Planos: 3 niveis com porcentagem automatica

### P5 — B2C Participant App
- Telas no enjoyfun-app: ingressos, cashless/cartao, menu/pedidos
- Referencia visual: enjoyfunuiux/ (mockups prontos)
- Conectar com dados de event_stages, event_sectors, mapas
- Landing page publica do evento (SEO)

## Regras
- NAO mexer em agentes IA (AIPromptCatalogService, AIOrchestratorService, AIIntentRouterService)
- Cada fix = 1 commit atomico
- Dark theme padrao em toda a plataforma
- organizer_id vem SEMPRE do JWT
- Table existence checks em todo campo novo (graceful fallback)
- Logar como admin@enjoyfun.com.br (organizer_id=2)
