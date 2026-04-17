# Auditoria Completa — 59 HTMLs Stitch vs App Participante
## Data: 2026-04-16

### Legenda
- **Bloco**: componente React Native em `blocks/`
- **Intent**: case no `handleB2CChat` do backend
- **Dados**: tabela no banco com registros

---

## STATUS POR HTML

| # | HTML Stitch | Bloco RN | Intent Backend | Dados no Banco | Status |
|---|------------|----------|---------------|----------------|--------|
| 1 | `enjoyfun_immersive_hub` | EventHub | welcome | events, tickets | OK |
| 2 | `ai_concierge_chat_flow` | ConciergeFlow | welcome | -- | OK |
| 3 | `main_stage_line_up_overlay` | Lineup (legacy) | lineup | artists | OK |
| 4 | `main_map_overview` | Map (legacy) | map | event_pdv_points, event_stages | OK |
| 5 | `intelligent_agenda` | Agenda | agenda | event_sessions (8) | OK |
| 6 | `interactive_ticket_cards_update` | TicketCard | tickets | tickets (97) | OK |
| 7 | `cashless_card_hub` | CashlessHub | cashless | digital_cards (R$150) | OK |
| 8 | `detailed_ticket_view` | TicketDetail | -- | -- | FALTA INTENT |
| 9 | `cart_o_digital_interativo` | DigitalCard | -- | -- | FALTA INTENT |
| 10 | `main_map_friend_locations` | MapFriends | friends | tickets (join users) | OK |
| 11 | `main_map_with_friend_ping_interaction` | MapFriends | friends | -- | OK (variante) |
| 12 | `map_zoom_main_stage` | MapZoomStage | -- | event_stages | FALTA INTENT |
| 13 | `map_zoom_with_friend_activity_feed` | MapZoomStage | -- | -- | FALTA INTENT |
| 14 | `map_zoom_with_live_stream_preview_1` | MapZoomStage | -- | -- | FALTA INTENT |
| 15 | `map_zoom_with_live_stream_preview_2` | MapZoomStage | -- | -- | (variante do 14) |
| 16 | `localiza_o_vaga_42_no_mapa` | MapParking | parking | event_parking_config | OK |
| 17 | `sector_parking_grid` | ParkingGrid | parking | event_parking_config | OK |
| 18 | `confirma_o_de_vaga_42` | ParkingConfirm | -- | -- | FALTA INTENT |
| 19 | `main_stage_line_up_ticket_purchase` | LineupPurchase | -- | ticket_types | FALTA INTENT + DADOS |
| 20 | `main_stage_live_stream` | LiveStream | -- | -- | FALTA INTENT + DADOS |
| 21 | `main_stage_vip_highlighted_purchase` | LineupPurchase | -- | -- | (variante do 19) |
| 22 | `main_stage_zoom_re_entry` | MapZoomStage | -- | -- | FALTA INTENT |
| 23 | `zoom_3d_main_stage` | MapZoomStage | -- | -- | FALTA INTENT |
| 24 | `zoom_3d_main_stage_refinado` | -- | -- | -- | (variante do 23) |
| 25 | `zoom_3d_refinado_main_stage` | -- | -- | -- | (variante do 23) |
| 26 | `sess_o_ao_vivo_participante` | LiveSession | -- | -- | FALTA INTENT + DADOS |
| 27 | `dashboard_de_performance_organizador` | OrganizerDashboard | -- | -- | FALTA INTENT |
| 28 | `seating_map_galactic_arena` | SeatingArena | -- | event_sectors | FALTA INTENT |
| 29 | `assento_reservado_arena_gal_ctica_1` | SeatReserved | -- | -- | FALTA INTENT |
| 30 | `assento_reservado_arena_gal_ctica_2` | -- | -- | -- | (variante do 29) |
| 31 | `banquete_mapa_de_assentos` | SeatingBanquet | tables | event_tables (10+15) | OK |
| 32 | `mapa_de_assentos_baile_de_formatura` | SeatingBanquet | tables | event_tables | OK (variante) |
| 33 | `mapa_de_assentos_est_dio_de_futebol_1` | SeatingArena | -- | -- | FALTA INTENT |
| 34 | `mapa_de_assentos_est_dio_de_futebol_2` | -- | -- | -- | (variante do 33) |
| 35 | `mapa_do_lounge_pr_festa` | SeatingBanquet | -- | -- | FALTA INTENT |
| 36 | `confirma_o_de_presen_a_rsvp` | RSVPConfirm | -- | event_participants | FALTA INTENT |
| 37 | `3d_expo_floorplan` | Floorplan3D | -- | -- | FALTA INTENT + DADOS |
| 38 | `planta_baixa_3d_vis_o_geral` | Floorplan3D | -- | -- | (variante do 37) |
| 39 | `planta_baixa_3d_baile_de_formatura` | Floorplan3D | -- | -- | (variante do 37) |
| 40 | `rea_vip_executiva` | VipArea | -- | event_sectors (vip) | FALTA INTENT |
| 41 | `perfil_do_expositor_b2b` | ExhibitorProfile | -- | -- | FALTA INTENT + DADOS |
| 42 | `incoming_friend_ping_notification` | FriendPing | -- | -- | FALTA INTENT |
| 43 | `join_friend_transition_animation` | FriendPing | -- | -- | (variante do 42) |
| 44 | `networking_squad` | NetworkingSquad | -- | -- | FALTA INTENT + DADOS |
| 45 | `matchmaking_inteligente_com_ia` | NetworkingSquad | -- | -- | (variante do 44) |
| 46 | `networking_em_realidade_aumentada_ar` | NetworkingSquad | -- | -- | (variante do 44) |
| 47 | `passe_multi_acesso_formatura` | MultiAccessPass | sub_events | event_sub_events | PARCIAL (intent sub_events retorna timeline, nao multi_access_pass) |
| 48 | `corporate_nexus_hub` | EventTypeHub | events (switch) | events | OK |
| 49 | `graduation_2024_nexus_hub` | EventTypeHub | events (switch) | events | OK |
| 50 | `wedding_gala_event_hub` | EventTypeHub | events (switch) | events | OK |
| 51 | `sports_arena_dashboard` | EventTypeHub | -- | -- | FALTA DADOS (nenhum evento sports) |
| 52 | `hub_de_pr_festas_formatura` | EventTypeHub | sub_events | event_sub_events | PARCIAL |
| 53 | `galeria_de_fotos_casamento` | PhotoGallery | -- | -- | FALTA INTENT + DADOS |
| 54 | `itiner_rio_do_casamento` | Itinerary | ceremony | event_ceremony_moments (9) | OK |
| 55 | `lista_de_presentes_casamento` | GiftRegistry | gifts | -- | FALTA DADOS (sem tabela de presentes) |
| 56 | `pulsating_qr_code_success_animation` | QRSuccess | -- | -- | FALTA INTENT (dispara apos validacao) |
| 57 | `purchase_success_animation` | QRSuccess | -- | -- | (variante do 56) |
| 58 | `refined_teleportation_transition` | -- | -- | -- | ANIMACAO (nao precisa intent) |
| 59 | `tactile_neon_purchase_button_update` | NeonButton | -- | -- | COMPONENTE (usado dentro de outros blocos) |

---

## RESUMO

| Status | Quantidade | % |
|--------|-----------|---|
| OK (bloco + intent + dados) | 18 | 30% |
| FALTA INTENT (bloco existe, backend nao serve) | 19 | 32% |
| FALTA INTENT + DADOS | 8 | 14% |
| PARCIAL (funciona mas nao com o bloco certo) | 3 | 5% |
| VARIANTE (coberto por outro bloco) | 10 | 17% |
| ANIMACAO/COMPONENTE (nao precisa intent) | 1 | 2% |

---

## PROXIMAS SPRINTS NECESSARIAS

### Sprint C1 — Conectar intents faltantes (blocos prontos, so falta backend)
19 intents a criar:

| Intent | Bloco | Trigger keywords |
|--------|-------|-----------------|
| `ticket_detail` | TicketDetail | "detalhes do ingresso", "ver ingresso", "qr grande" |
| `digital_card` | DigitalCard | "meu cartao", "cartao digital", "pass" |
| `stage_zoom` | MapZoomStage | "palco principal", "detalhe do palco", "zoom palco" |
| `parking_confirm` | ParkingConfirm | "confirmar vaga", "reservar vaga" |
| `buy_ticket` | LineupPurchase | "comprar ingresso", "quero comprar", "ingressos disponiveis" |
| `live` | LiveStream | "ao vivo", "live", "transmissao", "stream" |
| `live_session` | LiveSession | "sessao ao vivo", "palestra ao vivo" |
| `dashboard` | OrganizerDashboard | "dashboard", "metricas", "numeros do evento" |
| `seating` | SeatingArena | "assentos", "mapa de assentos", "arena" |
| `seat_confirm` | SeatReserved | "confirmar assento", "meu assento" |
| `lounge` | SeatingBanquet/VipArea | "lounge", "area vip", "vip" |
| `rsvp` | RSVPConfirm | "confirmar presenca", "rsvp", "vou comparecer" |
| `floorplan` | Floorplan3D | "planta", "planta baixa", "3d", "floorplan" |
| `exhibitor` | ExhibitorProfile | "expositor", "stand", "estande" |
| `networking` | NetworkingSquad | "networking", "conhecer pessoas", "conexoes" |
| `gallery` | PhotoGallery | "fotos", "galeria", "album" |
| `vip` | VipArea | "vip", "area vip", "premium", "exclusivo" |
| `qr_success` | QRSuccess | (disparado apos acao, nao por keyword) |
| `multi_pass` | MultiAccessPass | "passe", "multi acesso", "todos os eventos" |

### Sprint C2 — Criar dados faltantes no banco
| Dado | Tabela | Para qual bloco |
|------|--------|----------------|
| Ticket types com preco | ticket_types | LineupPurchase (tiers) |
| Evento tipo sports | events | SportsHub |
| Fotos do casamento | organizer_files ou nova tabela | PhotoGallery |
| Lista de presentes | nova tabela ou products adaptado | GiftRegistry |
| Expositores | event_exhibitors | ExhibitorProfile |

### Sprint C3 — Enriquecer welcome por tipo de evento
Quando o usuario troca pra um evento do tipo wedding, o welcome deve mostrar blocos especificos de casamento (itinerario, mesa, RSVP). Hoje mostra o mesmo welcome generico pra todos.

### Sprint C4 — Visual final
Polir cada bloco pra ficar identico ao HTML do Stitch.
