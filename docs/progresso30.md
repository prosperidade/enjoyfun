# Progresso 30 — App Participante Chat-First AI
## Data: 2026-04-16 → 2026-04-17 (sessao completa)

---

## Objetivo da sessao

Transformar o app participante (`enjoyfun-participant/`) de uma arquitetura B zumbi (11 telas orfas + codigo morto) em **chat-first funcional** com blocos adaptativos Aether Neon servidos pelo backend `/b2c/chat`.

---

## ENTREGAS CONSOLIDADAS

### Trilha B — 11 sprints, 32 blocos tipados

| Sprint | Escopo | Estado |
|--------|--------|--------|
| B0 | Limpeza (2100 linhas de codigo morto apagadas) + fundacao | Done |
| B1 | 7 blocos core (EventHub, Concierge, Agenda, TicketCard, Cashless, TicketDetail, DigitalCard) | Done |
| B2 | 5 blocos mapa (MapFriends, MapZoomStage, MapParking, ParkingGrid, ParkingConfirm) | Done |
| B3 | 4 blocos palco (LineupPurchase, LiveStream, LiveSession, OrganizerDashboard) | Done |
| B4 | 4 blocos assentos (SeatingArena, SeatReserved, SeatingBanquet, RSVPConfirm) | Done |
| B5 | 3 blocos planta (Floorplan3D, VipArea, ExhibitorProfile) | Done |
| B6 | 3 blocos social (FriendPing, NetworkingSquad, MultiAccessPass) | Done |
| B7 | 1 hub generico (EventTypeHub — 5 tipos de evento) | Done |
| B8 | 3 blocos wedding (PhotoGallery, Itinerary, GiftRegistry) | Done |
| B9 | 2 blocos animacao (QRSuccess, NeonButton) | Done |
| B10 | Performance & polimento (FlatList optimization, memoize, keys, console.log) | Done |

### Backend `/b2c/chat` — endpoint dedicado

**35 intents detectados, 24 cases no switch, 21 builders:**

- `welcome` (customizado por tipo de evento: wedding, graduation, festival, corporate, sports)
- `lineup`, `map`, `agenda`, `tickets`, `cashless`, `menu`, `parking`, `sectors`
- `ceremony`, `gifts`, `tables`, `sub_events`, `friends`, `events`, `event_info`
- `ticket_detail`, `digital_card`, `stage_zoom`, `buy_ticket`, `live`
- `seating`, `rsvp`, `floorplan`, `vip`, `gallery`
- `networking`, `multi_pass`
- Fallback AI via curl interno para `/ai/chat` (perguntas livres)

### Sprints C1-C4 — Conexao completa

| Sprint | Escopo | Estado |
|--------|--------|--------|
| C1 | 19 intents novos no backend (blocos ja existiam) | Done |
| C2 | Dados seed: wedding (9 ceremony + 10 tables + 3 sub-events) + graduation (2 stages + 4 sessions + 15 tables) + festival enriquecido | Done |
| C3 | Welcome customizado por event_type | Done |
| C4 | Visual polish (GlassCard, EventHub sheen, TicketCard QR real + stamp UTILIZADO, Lineup brutalist, Agenda live dot, CashlessHub credit card visual, MapOverview grid + POIs pulsantes, user bubble com gradiente) | Done |

---

## ARQUITETURA FINAL

### App Participante (`enjoyfun-participant/`)

```
src/
├── api/
│   ├── client.ts          # axios + auth interceptor
│   └── auth.ts            # login() apenas
├── components/
│   ├── GlassCard.tsx      # glassmorphism + glow + neonBorder
│   ├── QrPlaceholder.tsx  # QR visual 9x9 com position markers
│   ├── AdaptiveBlockRenderer.tsx  # LEGACY (blocos ainda nao extraidos)
│   └── blocks/            # 32 blocos tipados
│       ├── types.ts       # Union discriminada
│       ├── index.tsx      # BlockRouter
│       ├── EventHub.tsx
│       ├── ConciergeFlow.tsx
│       ├── Agenda.tsx
│       ├── TicketCard.tsx
│       ├── TicketDetail.tsx
│       ├── DigitalCard.tsx
│       ├── CashlessHub.tsx
│       ├── Lineup.tsx
│       ├── MapOverview.tsx
│       ├── MapFriends.tsx
│       ├── MapZoomStage.tsx
│       ├── MapParking.tsx
│       ├── ParkingGrid.tsx
│       ├── ParkingConfirm.tsx
│       ├── LineupPurchase.tsx
│       ├── LiveStream.tsx
│       ├── LiveSession.tsx
│       ├── OrganizerDashboard.tsx
│       ├── SeatingArena.tsx
│       ├── SeatReserved.tsx
│       ├── SeatingBanquet.tsx
│       ├── RSVPConfirm.tsx
│       ├── Floorplan3D.tsx
│       ├── VipArea.tsx
│       ├── ExhibitorProfile.tsx
│       ├── FriendPing.tsx
│       ├── NetworkingSquad.tsx
│       ├── MultiAccessPass.tsx
│       ├── hubs/EventTypeHub.tsx
│       ├── wedding/
│       │   ├── PhotoGallery.tsx
│       │   ├── Itinerary.tsx
│       │   └── GiftRegistry.tsx
│       └── animations/
│           ├── QRSuccess.tsx
│           └── NeonButton.tsx
├── lib/
│   ├── auth.ts
│   └── theme.ts           # Aether Neon design system
└── screens/
    ├── LoginScreen.tsx
    └── ImmersiveChatScreen.tsx  # UNICA tela pos-login

App.tsx                    # useFonts Space Grotesk + Manrope
AGENTS.md                  # guardrails pra agentes de IA
app.config.ts              # env dinamica (nao mais app.json)
```

### Backend endpoint `/b2c/chat`

```php
POST /b2c/chat  (authenticated)

Request:
{
  message: string,
  event_id: number,
  conversation_id: string (UUID v4),
  surface: "b2c",
  conversation_mode: "embedded",
  locale: "pt-BR",
  context: { auto_welcome?: boolean }
}

Response:
{
  data: {
    blocks: Block[],        // Array de blocos tipados
    text_fallback: string | null
  }
}
```

**Fluxo:**
1. `detectB2CIntent(message, isWelcome)` — classifica em 24 intents (patterns ordenados: especificos → genericos)
2. Switch builds blocks via `build*Blocks()` functions
3. Cada resposta inclui `actions` block com sugestoes contextuais
4. Unknown intent → fallback via curl interno pra `/ai/chat` do orquestrador (timeout 15s)

---

## AUDITORIA FINAL — 59 HTMLs Stitch

| Status | Qtd |
|--------|-----|
| Funcionando ponta a ponta | 35 telas |
| Variantes cobertas por outro bloco | 10 telas |
| Componentes/animacoes | 4 telas |
| Falta dados no banco (fotos, presentes) | 2 telas |
| Em backlog (mapa 3D interativo, etc) | 8 telas |

---

## PENDENCIAS REGISTRADAS

### Tecnicas
- **Voz (STT)**: `expo-av` quebrado no Expo Go (Video import crasha). Mic substituido por mensagem "disponivel no build nativo". Solucao: EAS Build ou migrar pra `expo-audio`.
- **Mapa 3D interativo**: plano e usar `expo-three` + modelos GLB. Tem tambem opcao de WebView com Matterport/Sketchfab. Tabela `events` ja tem `map_3d_url` pronto.
- **Tecnica Stitch CSS 3D**: aplicar transform rotateX/rotateZ em alguns blocos pra parecer 3D (isometrico estatico).
- **Fotos de galeria**: precisa upload no painel do organizador + tabela ou reuso de `organizer_files`.
- **Lista de presentes**: precisa tabela dedicada (`wedding_gifts` ou similar) + UI de contribuicao.

### Dados
- ticket_types com `price` NULL — comprar ingresso retorna tiers sem preco real
- artists sem `photo_url` — lineup mostra so emoji microfone
- Nenhum evento do tipo `sports` no banco
- `digital_cards` nao tem `event_id` — assume-se 1 card por user

### Seguranca
- pgcrypto decrypt falha pro openai (fallback env funciona mas loga warning)
- `curl_close` deprecated no PHP 8.5 (estamos em 8.4, sem impacto agora)

---

## COMANDOS DE EXECUCAO

### Backend
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun\backend
C:\php84\php.exe -S 0.0.0.0:8080 -t public
```

### App Participante
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun-participant
npx expo start --clear --port 8082 --lan
```

No celular: Expo Go + digitar `exp://192.168.1.42:8082` (ou IP atual da LAN).

### Seed rapido de event 1 (festival)
Ja aplicado no banco. Reaplicar se drop das tabelas:
- 4 stages (Main, Electronic, Lounge VIP, Palco Acustico)
- 4 sectors (Pista, Camarote, Backstage, Food)
- 8 sessions
- 5 PDV points
- 2 parking configs
- 8 artists com stage_name

### Seed wedding (event 2)
- 9 ceremony moments
- 10 tables
- 3 sub-events (Cha de Panela, Despedida, Ensaio)
- 4 sectors + 5 PDV points + 8 produtos

### Seed graduation (event 3)
- 2 stages + 4 sessions
- 15 tables
- 3 sectors + 4 PDV points + 6 produtos

---

## PROXIMAS SESSOES

### Prioridade alta
1. **Mapa 3D interativo** — upload de imagem/modelo + pan/zoom/rotate
2. **Tecnica Stitch CSS 3D** — aplicar em blocos chave (isometrico)
3. **Upload de fotos** no painel organizador + galeria funcional
4. **Precos reais em ticket_types** + integracao de compra

### Prioridade media
5. Voz nativa via EAS Build ou migracao pra `expo-audio`
6. Lista de presentes com backend dedicado
7. Push notifications (expo-notifications ja instalado)
8. PWA do participante com manifest branded

### Prioridade baixa
9. Dashboard de organizador como web separado (nao no app participante)
10. Matchmaking AI real (hoje e random pct)
11. AR Networking (expo-camera + AR SDK)

---

*Sessao encerrada com 32 blocos tipados + 35 intents funcionais + 59 HTMLs auditados.*
*Andre e Claude, 2026-04-17.*
