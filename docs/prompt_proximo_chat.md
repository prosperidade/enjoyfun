# Prompt para proximo chat — EnjoyFun

Cole isso no inicio do proximo chat:

---

Leia o CLAUDE.md e docs/progresso29.md antes de qualquer acao.

## Contexto

Sessao anterior (8 commits):
- P1: Produtos vinculados a PDV points (migration 102, pdv_point_id)
- P2 parcial: Block types especializados no backend. Mobile blocos PENDENTES
- P3: 3 componentes visuais: MapBuilder, SeatingChart, AgendaBuilder
- P4: SuperAdmin fase 2 completa:
  - Self-registration (/cadastro) com aprovacao admin
  - 3 planos (Starter 2% / Pro 1% / Enterprise 0.5%)
  - Auditoria automatica (8 checks) com aba dedicada
- P5: B2C Customer App com 3 telas novas:
  - CustomerTickets (/app/:slug/tickets)
  - CustomerCard (/app/:slug/card)
  - CustomerMenu (/app/:slug/menu)

## Stack ativa
- Backend: PHP 8.4 porta 8080
- Frontend: React + Vite porta 3003
- Banco: PostgreSQL 18.2 (enjoyfun, 127.0.0.1:5432)
- Redis: porta 6380 (docker-compose.services.yml)

## Documentacao de referencia
- CLAUDE.md — estado real do sistema
- docs/progresso29.md — diario completo desta sessao
- docs/adr_multi_event_customizable_v1.md — arquitetura multi-evento
- docs/runbook_local.md — bootstrap + migrations
- enjoyfunuiux/stitch_eventverse_immersive_hub/ — mockups UI/UX

## Proximas missoes (por prioridade)

### P1 — Mobile blocks (divida tecnica)
- 5 blocos no enjoyfun-mobile/enjoyfun-app/
- EventStagesBlock, EventSectorsBlock, EventSessionsBlock, EventTablesBlock, EventMapsBlock
- Types + AdaptiveUIRenderer.tsx
- Descricao completa em progresso29.md FASE 2

### P2 — Polimento componentes visuais
- MapBuilder: persistir posicoes no banco (campo JSON)
- SeatingChart: drag de convidados para mesas
- AgendaBuilder: drag vertical para mudar horario
- Considerar @dnd-kit/core

### P3 — B2C expansao
- Checkout real no CustomerMenu (debitar cashless)
- Landing page publica do evento (SEO)
- Push notifications
- Login por codigo WhatsApp

### P4 — SuperAdmin fase 3
- Self-service billing (PIX para planos)
- Dashboard metricas por plano
- Notificacoes de aprovacao (email/WhatsApp)

## Regras
- NAO mexer em agentes IA
- Cada fix = 1 commit atomico
- Dark theme padrao
- organizer_id vem SEMPRE do JWT
- Table existence checks em todo campo novo
