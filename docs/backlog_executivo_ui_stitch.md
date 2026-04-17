# Backlog Executivo — App Participante (Trilha B)
## EnjoyFun — Conversao Stitch Aether Neon → React Native (Expo)
### Data: 2026-04-16

---

## RESUMO EXECUTIVO

**59 mockups HTML** em `enjoyfunuiux/stitch_eventverse_immersive_hub/` devem ser convertidos em **blocos adaptativos React Native** dentro da arquitetura **chat-first** do app participante (`enjoyfun-participant/`).

| Item | Valor |
|------|-------|
| Mockups HTML fonte | 59 |
| Blocos RN a criar | ~50 (agrupando variantes) |
| Sprints | 11 (B0 a B10) |
| Tickets total | 76 |
| Repositorio | `enjoyfun-participant/` (Expo SDK 54 + TS) |
| Arquitetura | Chat-first — 1 tela pos-login, blocos via `/ai/chat` |
| Design system | Aether Neon (Space Grotesk + Manrope, glassmorphism, neon glow) |
| Referencia de arquitetura | `AGENTS.md` na raiz |
| Referencia de auditoria | `auditoriaapp-participants.md` |
| Referencia visual | `enjoyfunuiux/stitch_eventverse_immersive_hub/aether_neon/DESIGN.md` |

---

## PRINCIPIO INVIOLAVEL

> **Nao sao telas. Sao blocos.**
> Cada HTML vira um tipo de bloco que a IA retorna via `POST /ai/chat`.
> Nao existe `react-navigation`, nao existe `Stack.Navigator`, nao existem abas.
> O participante conversa com o concierge; o concierge devolve UI sob demanda.

---

## Sprint B0 — Limpeza & Fundacao (2 dias)

Resolver os 12 problemas estruturais da auditoria antes de qualquer conversao visual.

| # | Ticket | Descricao | Arquivo | Prioridade |
|---|--------|-----------|---------|------------|
| B-000 | Apagar 11 telas orfas (2.100 linhas de codigo morto) | EventHomeScreen, AgendaScreen, LineupScreen, MapScreen, TicketsScreen, CardScreen, SeatingScreen, ConciergeScreen, ProfileScreen, TicketPurchaseScreen, EventSelectorScreen | `src/screens/*.tsx` | CRITICO |
| B-001 | Apagar EventContext.tsx | Ninguem consome | `src/context/EventContext.tsx` | CRITICO |
| B-002 | Apagar pasta navigation/ | Vazia | `src/navigation/` | CRITICO |
| B-003 | Migrar login() de b2c.ts para auth.ts dedicado, apagar b2c.ts | Unica funcao usada era login | `src/api/` | CRITICO |
| B-004 | Desinstalar deps orfas | @react-navigation/*, react-native-qrcode-svg, expo-local-authentication | `package.json` | CRITICO |
| B-005 | Carregar fontes Space Grotesk + Manrope via useFonts no App.tsx | Fontes nunca carregadas (TODO no theme.ts) | `App.tsx` | HIGH |
| B-006 | Criar estrutura `src/components/blocks/` com types.ts + index.tsx (BlockRouter) | Contrato tipado: Block = union discriminada | `src/components/blocks/` | CRITICO |
| B-007 | Fix eventId hardcoded (ler do user no login) | Bug critico: useState(1) | `ImmersiveChatScreen.tsx` | CRITICO |
| B-008 | Adicionar conversation_id (UUID v4) em todos os POST /ai/chat | Chat sem contexto de conversa | `ImmersiveChatScreen.tsx` | CRITICO |
| B-009 | Tratamento de erro por status (401/429/5xx/offline) com botao retry | Catch generico engole tudo | `ImmersiveChatScreen.tsx` | HIGH |
| B-010 | Migrar acoes de label-texto para intent estruturada (action_id/intent) | Botoes disparam texto literal | `ImmersiveChatScreen.tsx` | HIGH |
| B-011 | Config env dinamica (app.config.ts por ambiente, nao IP fixo) | IP hardcoded no app.json | `app.config.ts` | HIGH |
| B-012 | Validar AGENTS.md sincronizado na raiz do participant | Agentes precisam saber que e chat-first | `AGENTS.md` | MEDIUM |

**Entregavel:** App limpo, tipado, sem codigo morto, bugs criticos corrigidos. Estrutura `blocks/` pronta pra receber componentes.

---

## Sprint B1 — Blocos Core (3 dias)

Blocos fundamentais que todo tipo de evento usa. Estes sao o MVP do app.

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-100 | Immersive Hub (home card do evento) | `enjoyfun_immersive_hub/code.html` | `blocks/EventHub.tsx` | Card principal do evento com hero image, nome, data, countdown |
| B-101 | AI Concierge Chat Flow (avatar, pulse, sugestoes) | `ai_concierge_chat_flow/code.html` | `blocks/ConciergeFlow.tsx` | Avatar do concierge com animacao pulse, sugestoes rapidas |
| B-102 | Lineup overlay (artistas, horarios, palco) | `main_stage_line_up_overlay/code.html` | `blocks/Lineup.tsx` | Lista de artistas com foto, horario, palco, neon glow |
| B-103 | Mapa geral (POIs, setores, bares, palcos) | `main_map_overview/code.html` | `blocks/MapOverview.tsx` | Mapa 2D com POIs flutuantes em glass-card |
| B-104 | Agenda inteligente (timeline, filtros, agora/proximo) | `intelligent_agenda/code.html` | `blocks/Agenda.tsx` | Timeline com filtro por palco, indicador "agora" |
| B-105 | Tickets interativos (holographic pass, QR neon) | `interactive_ticket_cards_update/code.html` | `blocks/TicketCard.tsx` | Holographic pass com sheen layer e QR code neon |
| B-106 | Cashless Card Hub (saldo, recarga, historico) | `cashless_card_hub/code.html` | `blocks/CashlessHub.tsx` | Saldo grande, botao recarga, ultimas transacoes |
| B-107 | Ticket detalhado (fullscreen, QR grande, countdown) | `detailed_ticket_view/code.html` | `blocks/TicketDetail.tsx` | Modal fullscreen com QR gigante e countdown |
| B-108 | Cartao digital interativo (flip, NFC, dados) | `cart_o_digital_interativo/code.html` | `blocks/DigitalCard.tsx` | Card com flip animation, dados NFC |

**Entregavel:** 9 blocos core — participante ve lineup, mapa, ingressos, saldo e agenda via chat.

**Dependencia:** Backend `/ai/chat` com surface `b2c` retornando esses tipos de bloco.

---

## Sprint B2 — Blocos de Mapa & Navegacao (2 dias)

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-200 | Mapa com amigos (localizacao em tempo real) | `main_map_friend_locations/code.html` | `blocks/MapFriends.tsx` | Avatares de amigos sobre o mapa |
| B-201 | Mapa com ping de amigo (interacao) | `main_map_with_friend_ping_interaction/code.html` | `blocks/MapFriendPing.tsx` | Ping pulsante + "Vem pra ca!" |
| B-202 | Zoom no palco principal | `map_zoom_main_stage/code.html` | `blocks/MapZoomStage.tsx` | Detalhe do palco com artista atual |
| B-203 | Zoom com feed de atividade de amigos | `map_zoom_with_friend_activity_feed/code.html` | `blocks/MapZoomFeed.tsx` | Feed lateral de atividades |
| B-204 | Zoom com preview de live stream 1+2 | `map_zoom_with_live_stream_preview_1/code.html` + `_2` | `blocks/MapZoomLive.tsx` | Thumbnail de live no mapa |
| B-205 | Localizacao de vaga no mapa (estacionamento) | `localiza_o_vaga_42_no_mapa/code.html` | `blocks/MapParking.tsx` | Pin de vaga com direcoes |
| B-206 | Setor/Parking grid | `sector_parking_grid/code.html` | `blocks/ParkingGrid.tsx` | Grid de setores com ocupacao |
| B-207 | Confirmacao de vaga | `confirma_o_de_vaga_42/code.html` | `blocks/ParkingConfirm.tsx` | Confirmacao com numero e QR |

**Entregavel:** Experiencia de mapa completa com social e estacionamento.

---

## Sprint B3 — Blocos de Palco & Live (2 dias)

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-300 | Lineup com compra de ingresso | `main_stage_line_up_ticket_purchase/code.html` | `blocks/LineupPurchase.tsx` | Lineup + CTA de compra integrado |
| B-301 | Live stream do palco | `main_stage_live_stream/code.html` | `blocks/LiveStream.tsx` | Player de live embed |
| B-302 | VIP highlighted purchase | `main_stage_vip_highlighted_purchase/code.html` | `blocks/VipPurchase.tsx` | Destaque VIP com preco e beneficios |
| B-303 | Zoom re-entry no palco | `main_stage_zoom_re_entry/code.html` | `blocks/StageReEntry.tsx` | Transicao de retorno ao palco |
| B-304 | Zoom 3D palco (3 variantes) | `zoom_3d_main_stage/code.html` + refinado | `blocks/Stage3D.tsx` | Vista 3D do palco (melhor variante) |
| B-305 | Sessao ao vivo (participante) | `sess_o_ao_vivo_participante/code.html` | `blocks/LiveSession.tsx` | Sessao com chat ao vivo |
| B-306 | Dashboard performance organizador (B2B embed) | `dashboard_de_performance_organizador/code.html` | `blocks/OrganizerDashboard.tsx` | Metricas do evento para organizador |

**Entregavel:** Experiencia de palco e live completa.

---

## Sprint B4 — Blocos de Assentos & Seating (2 dias)

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-400 | Seating map galactic arena | `seating_map_galactic_arena/code.html` | `blocks/SeatingArena.tsx` | Mapa de arena com setores coloridos |
| B-401 | Assento reservado arena 1+2 | `assento_reservado_arena_gal_ctica_1/code.html` + `_2` | `blocks/SeatReserved.tsx` | Confirmacao de assento com numero |
| B-402 | Banquete mapa de assentos | `banquete_mapa_de_assentos/code.html` | `blocks/SeatingBanquet.tsx` | Layout de mesas circular |
| B-403 | Mapa assentos baile formatura | `mapa_de_assentos_baile_de_formatura/code.html` | `blocks/SeatingProm.tsx` | Assentos + pista de danca |
| B-404 | Mapa assentos estadio futebol 1+2 | `mapa_de_assentos_est_dio_de_futebol_1/code.html` + `_2` | `blocks/SeatingStadium.tsx` | Setores de estadio com preco |
| B-405 | Mapa lounge pre-festa | `mapa_do_lounge_pr_festa/code.html` | `blocks/SeatingLounge.tsx` | Layout de lounge VIP |
| B-406 | RSVP confirmacao de presenca | `confirma_o_de_presen_a_rsvp/code.html` | `blocks/RSVPConfirm.tsx` | Formulario RSVP com meal choice |

**Entregavel:** Sistema de assentos completo para todos os tipos de evento.

---

## Sprint B5 — Blocos 3D & Planta Baixa (2 dias)

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-500 | 3D Expo Floorplan | `3d_expo_floorplan/code.html` | `blocks/Floorplan3D.tsx` | Planta 3D isometrica de expo |
| B-501 | Planta baixa 3D visao geral | `planta_baixa_3d_vis_o_geral/code.html` | `blocks/FloorplanOverview.tsx` | Vista geral 3D do evento |
| B-502 | Planta baixa 3D baile formatura | `planta_baixa_3d_baile_de_formatura/code.html` | `blocks/FloorplanProm.tsx` | Layout 3D do baile |
| B-503 | Area VIP executiva | `rea_vip_executiva/code.html` | `blocks/VipArea.tsx` | Detalhes da area VIP |
| B-504 | Perfil do expositor B2B | `perfil_do_expositor_b2b/code.html` | `blocks/ExhibitorProfile.tsx` | Card de expositor com contato |

**Entregavel:** Plantas baixas e areas VIP para eventos corporativos e formaturas.

---

## Sprint B6 — Blocos Social & Networking (2 dias)

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-600 | Notificacao de ping de amigo | `incoming_friend_ping_notification/code.html` | `blocks/FriendPing.tsx` | Toast animado de ping |
| B-601 | Animacao de join friend | `join_friend_transition_animation/code.html` | `blocks/FriendJoin.tsx` | Transicao ao juntar-se a amigo |
| B-602 | Networking squad | `networking_squad/code.html` | `blocks/NetworkingSquad.tsx` | Grupo de networking formado |
| B-603 | Matchmaking inteligente com IA | `matchmaking_inteligente_com_ia/code.html` | `blocks/AIMatchmaking.tsx` | Sugestoes de conexao por IA |
| B-604 | Networking em realidade aumentada (AR) | `networking_em_realidade_aumentada_ar/code.html` | `blocks/NetworkingAR.tsx` | Preview AR de networking |
| B-605 | Passe multi-acesso formatura | `passe_multi_acesso_formatura/code.html` | `blocks/MultiAccessPass.tsx` | Passe com multiplos sub-eventos |

**Entregavel:** Experiencia social completa — amigos, networking, matchmaking.

---

## Sprint B7 — Hubs por Tipo de Evento (2 dias)

Cada tipo de evento tem um hub visual proprio que o concierge retorna como bloco.

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-700 | Corporate Nexus Hub | `corporate_nexus_hub/code.html` | `blocks/hubs/CorporateHub.tsx` | Hub corporativo: agenda, speakers, networking |
| B-701 | Graduation Nexus Hub | `graduation_2024_nexus_hub/code.html` | `blocks/hubs/GraduationHub.tsx` | Hub formatura: cerimonia, colacao, baile |
| B-702 | Wedding Gala Event Hub | `wedding_gala_event_hub/code.html` | `blocks/hubs/WeddingHub.tsx` | Hub casamento: cerimonia, festa, mesa |
| B-703 | Sports Arena Dashboard | `sports_arena_dashboard/code.html` | `blocks/hubs/SportsHub.tsx` | Hub esportivo: placar, setores, bares |
| B-704 | Hub pre-festas formatura | `hub_de_pr_festas_formatura/code.html` | `blocks/hubs/PromHub.tsx` | Hub pre-festa: sub-eventos, dress code |

**Entregavel:** 5 hubs tematicos — cada tipo de evento tem sua cara no chat.

---

## Sprint B8 — Blocos Wedding & Especializados (1 dia)

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-800 | Galeria de fotos casamento | `galeria_de_fotos_casamento/code.html` | `blocks/wedding/PhotoGallery.tsx` | Grid de fotos com lightbox |
| B-801 | Itinerario do casamento | `itiner_rio_do_casamento/code.html` | `blocks/wedding/Itinerary.tsx` | Timeline do dia do casamento |
| B-802 | Lista de presentes casamento | `lista_de_presentes_casamento/code.html` | `blocks/wedding/GiftRegistry.tsx` | Lista com status de cada presente |

**Entregavel:** Modulo casamento completo.

---

## Sprint B9 — Animacoes & Micro-interacoes (1 dia)

| # | Ticket | HTML Ref | Bloco RN | Descricao |
|---|--------|----------|----------|-----------|
| B-900 | QR Code pulsante success animation | `pulsating_qr_code_success_animation/code.html` | `blocks/animations/QRSuccess.tsx` | QR com pulse neon ao validar |
| B-901 | Purchase success animation | `purchase_success_animation/code.html` | `blocks/animations/PurchaseSuccess.tsx` | Confetti + check neon |
| B-902 | Teleportation transition (refinada) | `refined_teleportation_transition/code.html` | `blocks/animations/Teleport.tsx` | Transicao entre "locais" do mapa |
| B-903 | Tactile neon purchase button | `tactile_neon_purchase_button_update/code.html` | `blocks/animations/NeonButton.tsx` | Botao de compra com feedback haptico |

**Entregavel:** Micro-interacoes que fazem o app parecer premium.

---

## Sprint B10 — Performance & Polimento (1 dia)

| # | Ticket | Descricao |
|---|--------|-----------|
| B-1000 | Migrar FlatList para inverted={true} + lista reversa | Padrao canonico de chat RN |
| B-1001 | Memoizar renderMessage e cada bloco | Isolar re-renders |
| B-1002 | Remover setTimeout(200) do scrollToEnd | Substituir por inverted |
| B-1003 | Corrigir KeyboardAvoidingView offset para iOS notch | Input bar some atras do teclado |
| B-1004 | Corrigir key={i} → key={block.id + item.id} em sub-arrays | React reutiliza componentes errados |
| B-1005 | Envolver console.log em if (__DEV__) | Log vazando em producao |
| B-1006 | Cache do auto-welcome por user_id | Nao gastar LLM em remount |
| B-1007 | Adicionar Sentry/Crashlytics | Zero telemetria de crash |

**Entregavel:** App performatico e observavel.

---

## CRONOGRAMA

```
Semana 1 (16-18 abr):
  Sprint B0 — Limpeza & Fundacao ..................... 2 dias
  
Semana 2 (21-25 abr):
  Sprint B1 — Blocos Core ........................... 3 dias

Semana 3 (28-30 abr):  ← D-Day ~29 abr
  Sprint B2 — Mapa & Navegacao ...................... 2 dias

Semana 4 (01-02 mai):
  Sprint B3 — Palco & Live .......................... 2 dias

Semana 5 (05-08 mai):
  Sprint B4 — Assentos & Seating .................... 2 dias
  Sprint B5 — 3D & Planta Baixa .................... 2 dias

Semana 6 (09-13 mai):
  Sprint B6 — Social & Networking ................... 2 dias
  Sprint B7 — Hubs por Tipo de Evento .............. 2 dias

Semana 7 (14-16 mai):
  Sprint B8 — Wedding & Especializados .............. 1 dia
  Sprint B9 — Animacoes ............................ 1 dia
  Sprint B10 — Performance & Polimento .............. 1 dia
```

**Sprints B0 + B1 sao criticas pro D-Day (~29 abril).**

---

## METRICAS

| Metrica | Valor |
|---------|-------|
| Mockups HTML | 59 |
| Blocos React Native | ~50 |
| Sprints | 11 (B0 a B10) |
| Tickets total | 76 |
| Dias uteis estimados | ~20 |

---

## DEPENDENCIAS CRITICAS

1. **Backend B2C Chat** — Sprint B1 depende de `/ai/chat` com surface `b2c` retornando blocos tipados
2. **Block types.ts** — Sprint B0 ticket B-006 desbloqueia TODAS as sprints seguintes
3. **Fontes** — B-005 deve ser uma das primeiras tarefas
4. **Evento real ~2026-04-29** — B0 + B1 sao obrigatorios antes do D-Day
5. **DESIGN.md** — Ler `enjoyfunuiux/stitch_eventverse_immersive_hub/aether_neon/DESIGN.md` antes de qualquer bloco

---

## REGRAS DE EXECUCAO

1. **Cada HTML e lei** — o visual do Stitch e a referencia obrigatoria, nao invente
2. **Ler o code.html antes de codar** — entender estrutura, cores, espacamentos, animacoes
3. **Testar no Expo Go antes de seguir** — cada bloco convertido deve ser validado
4. **NAO criar telas** — criar BLOCOS em `src/components/blocks/`
5. **NAO reintroduzir react-navigation** — foi removido de proposito
6. **NAO criar EventContext** — estado de evento vive no backend
7. **NAO chamar endpoints REST alem de /auth/login e /ai/chat** — se precisa de dado, peca pro backend devolver como bloco
8. **NAO usar any** — Block e union discriminada, e o contrato com o backend
9. **Design system centralizado** — tokens em theme.ts, nunca hardcode cores
10. **Um ticket = um commit atomico** — facilita review e rollback

---

## MAPEAMENTO COMPLETO: 59 HTMLs → Blocos

| # | Pasta HTML (enjoyfunuiux/stitch_eventverse_immersive_hub/) | Bloco RN | Sprint |
|---|-------------------------------------------------------------|----------|--------|
| 1 | `enjoyfun_immersive_hub` | EventHub | B1 |
| 2 | `ai_concierge_chat_flow` | ConciergeFlow | B1 |
| 3 | `main_stage_line_up_overlay` | Lineup | B1 |
| 4 | `main_map_overview` | MapOverview | B1 |
| 5 | `intelligent_agenda` | Agenda | B1 |
| 6 | `interactive_ticket_cards_update` | TicketCard | B1 |
| 7 | `cashless_card_hub` | CashlessHub | B1 |
| 8 | `detailed_ticket_view` | TicketDetail | B1 |
| 9 | `cart_o_digital_interativo` | DigitalCard | B1 |
| 10 | `main_map_friend_locations` | MapFriends | B2 |
| 11 | `main_map_with_friend_ping_interaction` | MapFriendPing | B2 |
| 12 | `map_zoom_main_stage` | MapZoomStage | B2 |
| 13 | `map_zoom_with_friend_activity_feed` | MapZoomFeed | B2 |
| 14 | `map_zoom_with_live_stream_preview_1` | MapZoomLive | B2 |
| 15 | `map_zoom_with_live_stream_preview_2` | (variante do 14) | B2 |
| 16 | `localiza_o_vaga_42_no_mapa` | MapParking | B2 |
| 17 | `sector_parking_grid` | ParkingGrid | B2 |
| 18 | `confirma_o_de_vaga_42` | ParkingConfirm | B2 |
| 19 | `main_stage_line_up_ticket_purchase` | LineupPurchase | B3 |
| 20 | `main_stage_live_stream` | LiveStream | B3 |
| 21 | `main_stage_vip_highlighted_purchase` | VipPurchase | B3 |
| 22 | `main_stage_zoom_re_entry` | StageReEntry | B3 |
| 23 | `zoom_3d_main_stage` | Stage3D | B3 |
| 24 | `zoom_3d_main_stage_refinado` | (variante do 23) | B3 |
| 25 | `zoom_3d_refinado_main_stage` | (variante do 23) | B3 |
| 26 | `sess_o_ao_vivo_participante` | LiveSession | B3 |
| 27 | `dashboard_de_performance_organizador` | OrganizerDashboard | B3 |
| 28 | `seating_map_galactic_arena` | SeatingArena | B4 |
| 29 | `assento_reservado_arena_gal_ctica_1` | SeatReserved | B4 |
| 30 | `assento_reservado_arena_gal_ctica_2` | (variante do 29) | B4 |
| 31 | `banquete_mapa_de_assentos` | SeatingBanquet | B4 |
| 32 | `mapa_de_assentos_baile_de_formatura` | SeatingProm | B4 |
| 33 | `mapa_de_assentos_est_dio_de_futebol_1` | SeatingStadium | B4 |
| 34 | `mapa_de_assentos_est_dio_de_futebol_2` | (variante do 33) | B4 |
| 35 | `mapa_do_lounge_pr_festa` | SeatingLounge | B4 |
| 36 | `confirma_o_de_presen_a_rsvp` | RSVPConfirm | B4 |
| 37 | `3d_expo_floorplan` | Floorplan3D | B5 |
| 38 | `planta_baixa_3d_vis_o_geral` | FloorplanOverview | B5 |
| 39 | `planta_baixa_3d_baile_de_formatura` | FloorplanProm | B5 |
| 40 | `rea_vip_executiva` | VipArea | B5 |
| 41 | `perfil_do_expositor_b2b` | ExhibitorProfile | B5 |
| 42 | `incoming_friend_ping_notification` | FriendPing | B6 |
| 43 | `join_friend_transition_animation` | FriendJoin | B6 |
| 44 | `networking_squad` | NetworkingSquad | B6 |
| 45 | `matchmaking_inteligente_com_ia` | AIMatchmaking | B6 |
| 46 | `networking_em_realidade_aumentada_ar` | NetworkingAR | B6 |
| 47 | `passe_multi_acesso_formatura` | MultiAccessPass | B6 |
| 48 | `corporate_nexus_hub` | CorporateHub | B7 |
| 49 | `graduation_2024_nexus_hub` | GraduationHub | B7 |
| 50 | `wedding_gala_event_hub` | WeddingHub | B7 |
| 51 | `sports_arena_dashboard` | SportsHub | B7 |
| 52 | `hub_de_pr_festas_formatura` | PromHub | B7 |
| 53 | `galeria_de_fotos_casamento` | PhotoGallery | B8 |
| 54 | `itiner_rio_do_casamento` | Itinerary | B8 |
| 55 | `lista_de_presentes_casamento` | GiftRegistry | B8 |
| 56 | `pulsating_qr_code_success_animation` | QRSuccess | B9 |
| 57 | `purchase_success_animation` | PurchaseSuccess | B9 |
| 58 | `refined_teleportation_transition` | Teleport | B9 |
| 59 | `tactile_neon_purchase_button_update` | NeonButton | B9 |

---

*Backlog gerado em 2026-04-16 — EnjoyFun Platform*
*Fonte: AGENTS.md + auditoriaapp-participants.md + enjoyfunuiux/ (59 HTMLs)*
*Foco exclusivo: Trilha B — App Participante*
