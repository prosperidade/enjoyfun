# Progresso 32 — Sprint Lineup + Media Completos + Voz no App Participante
## Data: 2026-04-18

Sessao longa e produtiva. 8 frentes encerradas, app participante virou um organismo completo: lineup 3-niveis, media renderizando em 8 blocos, player de video nativo, voz ponta-a-ponta, endpoint publico de media, workspace de midia no painel organizador.

---

## ENTREGAS

### Sprint E — Lineup navegacional (3 niveis) + midia do artista

| Sprint | Entrega | Estado |
|--------|---------|--------|
| E1 | Migration 106: `photo_url`, `performance_video_url`, `bio`, `genre` em `artists` (idempotente — colunas ja existiam) | Done |
| E2 | `createArtist` / `updateArtist` / `artistRequireArtistById` / `listArtists` aceitam e retornam os 4 campos novos | Done |
| E3 | `ArtistDetail.jsx` no painel com `MediaUpload` (foto + video) + input `genero` + textarea `bio` | Done |
| E4 | `buildStagesListBlocks()` — lista palcos clicaveis | Done |
| E5 | `buildStageDetailBlocks()` — agora inclui `artists` (event_artists JOIN artists) e `sessions` (event_sessions) daquele palco | Done |
| E6 | `buildArtistDetailBlocks()` + intent `artista {nome}` detectada via regex | Done |
| E7 | Intent `lineup` inteligente: 1 palco → detalhe direto; varios → lista | Done |
| E8 | Intent `stages_list` (keyword "palcos") separada do antigo `stage_zoom` | Done |
| E9 | Componente `StagesList.tsx` — cards 16:9 empilhados verticalmente (sem distorcao) | Done |
| E10 | Componente `StageDetail.tsx` — hero + CTA tour 360 + lista clicavel de artistas + agenda de sessions | Done |
| E11 | Componente `ArtistDetail.tsx` — foto fullscreen (`resizeMode=contain`) + botao video + bio + horario | Done |
| E12 | `Lineup.tsx`: overlay ▶ no card do artista quando tem video, tap abre `VideoModal` | Done |

**Fluxo novo:**
1. `"lineup"` → se 1 palco, ja abre StageDetail desse palco; se varios, StagesList
2. Toca num palco → `palco {nome}` → StageDetail com artistas clicaveis
3. Toca num artista → `artista {nome}` → ArtistDetail com foto + video + bio

### Sprint F — Player de video nativo (expo-video)

| Sprint | Entrega |
|--------|---------|
| F1 | `expo-video` + plugin registrado em `app.config.ts` |
| F2 | `VideoModal.tsx` reutilizavel (fullscreen, controles nativos, loop=false, label no canto) |
| F3 | `MapOverview`: Tour 360/3D abrem modal (nao mais `Linking.openURL` pro browser externo) |
| F4 | `Lineup`: card do artista com overlay ▶ abre modal |
| F5 | `StageDetail`: botao "▶ ASSISTIR TOUR 360" abre modal |
| F6 | `ArtistDetail`: botao "▶ ASSISTIR VIDEO" abre modal |
| F7 | `EventSectors`, `SubEventsTimeline`: badge ▶ inline/overlay |

### Sprint G — Endpoint publico de midia (destravou renderizacao)

Problema descoberto na sessao: planta baixa (9.4MB) com Bearer header via `<Image>` do RN Android ficava rodando infinito (Fresco decode issue com auth header + PNG grande).

| Sprint | Entrega |
|--------|---------|
| G1 | Novo endpoint `GET /api/organizer-files/{id}/public` — sem auth, serve apenas MIME `image/*` e `video/*`, Cache-Control 24h, CORS `*` |
| G2 | `b2cResolveFileUrl()` retorna `/public` em vez de `/download` |
| G3 | `authImageSource()` simplificado — nao manda mais headers |
| G4 | Fix do strip `/api/` no `resolveMediaUrl()` (preserva path como vem do backend) |
| G5 | Runbook: PHP **precisa** subir com `-S 0.0.0.0:8080` (nao `localhost:8080`) pra celular conectar via LAN |

### Sprint H — Mapas por intent (parking/seating)

Andre explicou que nao queria cards empilhados — cada mapa vem pela intent correta.

| Sprint | Entrega |
|--------|---------|
| H1 | `buildParkingBlocks` carrega `events.map_parking_url` e injeta em `parking_grid.map_image_url` |
| H2 | `buildSeatingBlocks` carrega `events.map_seating_url` e injeta em `seating_arena.map_image_url` |
| H3 | `ParkingGrid.tsx`: se tem mapa uploaded, substitui o grid isometrico fake pela imagem |
| H4 | `SeatingArena.tsx`: renderiza mapa uploaded como hero antes das secoes |

### Sprint I — Atracoes / Lineup no painel organizador

Movimento conceitual importante: `Atracoes` saiu do grupo "Operacional" (apenas habilita pagina dedicada) pro grupo "Configuracao do Evento" (abre form inline). A pagina dedicada `/artists` continua para logistica/valores/contratos.

| Sprint | Entrega |
|--------|---------|
| I1 | `EventModulesSelector`: `artists` movido de `OPERATIONAL_MODULES` pra `CONFIG_MODULES` com label "Atracoes / Lineup" |
| I2 | Novo componente `ArtistsLineupSection` em `EventModuleSections.jsx` |
| I3 | `ArtistMediaRow` inline com: dropdown de palco (vinculado ao booking), input genero, textarea bio, `MediaUpload` foto, `MediaUpload` video |
| I4 | Filtro por palco no topo com contador por palco |
| I5 | Save automatico on-blur (campos texto) e on-change (MediaUpload) |
| I6 | Trash cancela booking (`POST /artists/bookings/{id}/cancel`) sem deletar artista master |
| I7 | useEffect com `cancelled` flag pra nao vazar state update se user trocar de evento mid-fetch |
| I8 | Fix do loop de re-render: `<button>` aninhado em `<button>` quebrava o React; trocado wrapper por `<div role=button>` |
| I9 | Fix do `stage_name` nulo no PATCH: fallback pra `legal_name` → evita 422 |

### Sprint J — Outros blocos com midia no app

Payload ja vinha do backend, faltava renderizar no app.

| Sprint | Entrega |
|--------|---------|
| J1 | `EventSectors.tsx` (novo) — cards empilhados com `ImageBackground` + overlay gradient + badge ▶ pra video |
| J2 | `Itinerary.tsx` (Ceremony wedding) — `image_url` em cada step no topo do card |
| J3 | `SubEventsTimeline.tsx` (novo) — substitui type generico `timeline` pro bloco de sub-eventos, com foto/video por card. Backend trocado pra retornar `sub_events_timeline` |
| J4 | `SeatingBanquet.tsx` — `layout_image_url` renderizado como hero 16:10 antes do grid de mesas |
| J5 | BlockRouter + types.ts atualizados |

### Sprint K — Voz ponta-a-ponta no app participante

Fluxo completo: grava audio (expo-audio) → envia pro backend (/ai/voice/transcribe) → Whisper transcreve → vira mensagem no chat → opcional TTS nativo.

| Sprint | Entrega |
|--------|---------|
| K1 | `expo-audio` + `expo-speech` instalados via `npx expo install` |
| K2 | Plugin `expo-audio` + `microphonePermission` PT-BR em `app.config.ts` |
| K3 | `src/lib/voice.ts` (novo): `useVoiceRecorder`, `transcribe`, `speak`, `stopSpeaking` |
| K4 | `ImmersiveChatScreen.tsx`: botao mic 🎤/⏹/⋯, botao TTS 🔊/🔈 toggle, cleanup no unmount |
| K5 | Fix do estado: `useAudioRecorderState` nao era reativo o suficiente → adotado pattern do app admin com `micState` local ('idle'/'listening'/'processing') |
| K6 | Fix do payload: backend espera campo `audio` (nao `file`), resposta e `data.transcript` (nao `data.text`) |
| K7 | Fix do axios multipart: `transformRequest: (d) => d` + header `multipart/form-data` explicito pra nao deixar o interceptor do api client setar `application/json` |
| K8 | Logs defensivos `[VOICE.transcribe]` + `[MIC]` pra diagnosticar |
| K9 | Requer `FEATURE_AI_VOICE_PROXY=1` no `backend/.env` (senao 403) |

### Sprint L — D5: MediaWorkspace no painel organizador

Pagina nova com grid visual centralizado de todos os assets do evento.

| Sprint | Entrega |
|--------|---------|
| L1 | `pages/MediaWorkspace.jsx` — grid responsivo 2-6 colunas com thumbnails reais (usa `/public`) |
| L2 | Busca por nome (input com icone) |
| L3 | Filtro por tipo (pills: Tudo / Imagens / Videos / Outros) com contadores dinamicos |
| L4 | Filtro por categoria (select com categorias extraidas do dataset) |
| L5 | Seletor de evento integrado via `EventScopeContext` |
| L6 | Hover actions por card: 🔗 copiar link publico, ↗ abrir em nova aba, 🗑 deletar |
| L7 | Lightbox fullscreen: `<img>`, `<video controls autoplay>` ou iframe pra docs |
| L8 | Rota `/media` em `App.jsx` |
| L9 | Link "Midia do Evento" na `Sidebar.jsx` logo abaixo de "Eventos" com icone Image |

---

## ARQUIVOS CRIADOS/MODIFICADOS

### Banco
- `database/106_artists_media.sql` — ALTER TABLE idempotente (colunas ja existiam)

### Backend
- `src/Controllers/B2CAppController.php`
  - `buildStagesListBlocks` (novo)
  - `buildStageDetailBlocks` (novo; inclui artists + sessions do palco)
  - `buildArtistDetailBlocks` (novo)
  - `buildLineupBlocks` refatorado (usa event_artists do evento; fallback artists master)
  - `buildMapBlocks` sem URLs extras (apenas banner + tour)
  - `buildParkingBlocks` + `map_image_url` do event.map_parking_url
  - `buildSeatingBlocks` + `map_image_url` do event.map_seating_url
  - `buildSubEventBlocks` type mudou pra `sub_events_timeline`
  - `b2cResolveFileUrl` retorna `/public` (era `/download`)
  - `detectB2CIntent`: intent `stages_list` separada, keyword `palco` movido; regex `palco {nome}` e `artista {nome}` antes do match
- `src/Controllers/OrganizerFileController.php` — `orgFilePublicMedia()` novo endpoint publico (MIME image/video only)
- `src/Controllers/AIController.php` — logs defensivos no `transcribeVoice` + enum de erros de upload
- `src/Helpers/ArtistCatalogBookingHelper.php` — INSERT/UPDATE + SELECT com os 4 campos novos
- `src/Helpers/ArtistControllerSupport.php` — `artistRequireArtistById` retorna os 4 campos

### Frontend (painel organizador)
- `frontend/src/pages/MediaWorkspace.jsx` (novo)
- `frontend/src/pages/ArtistDetail.jsx` — editor modal com MediaUpload + genero + bio
- `frontend/src/pages/Events.jsx` — render de `ArtistsLineupSection` quando modulo `artists` habilitado
- `frontend/src/components/EventModuleSections.jsx` — `ArtistsLineupSection`, `ArtistMediaRow` (com dropdown palco + MediaUpload)
- `frontend/src/components/EventModulesSelector.jsx` — `artists` movido pro grupo Config
- `frontend/src/components/Sidebar.jsx` — link "Midia do Evento" abaixo de "Eventos"
- `frontend/src/App.jsx` — rota `/media`

### App Participante (Expo)
- `src/components/VideoModal.tsx` (novo)
- `src/components/blocks/StagesList.tsx` (novo)
- `src/components/blocks/StageDetail.tsx` (novo)
- `src/components/blocks/ArtistDetail.tsx` (novo)
- `src/components/blocks/EventSectors.tsx` (novo)
- `src/components/blocks/SubEventsTimeline.tsx` (novo)
- `src/components/blocks/Lineup.tsx` — overlay ▶ no card do artista + VideoModal
- `src/components/blocks/MapOverview.tsx` — Tour 360/3D via VideoModal + 2 cards extras removidos (reversao)
- `src/components/blocks/ParkingGrid.tsx` — mapa real substitui grid isometrico quando tem upload
- `src/components/blocks/SeatingArena.tsx` — hero com layout uploaded
- `src/components/blocks/SeatingBanquet.tsx` — hero com layout uploaded
- `src/components/blocks/wedding/Itinerary.tsx` — image por step
- `src/components/blocks/index.tsx` — BlockRouter com 4 tipos novos
- `src/components/blocks/types.ts` — union Block atualizado
- `src/lib/media.ts` — `resolveMediaUrl` sem strip `/api/`, `authImageSource` sem headers
- `src/lib/voice.ts` (novo) — gravacao + transcribe + speak + stopSpeaking
- `src/screens/ImmersiveChatScreen.tsx` — botoes mic + TTS
- `app.config.ts` — plugins expo-video + expo-audio

---

## PROBLEMAS EM ABERTO / PENDENTE

### Pendente de investigacao
1. **Dropdown de palco na ArtistsLineupSection nao salva** — o onChange chama PATCH /artists/bookings/{id} mas o booking_stage_name no banco permanece "principal" em vez de "Main Stage" (atualizei manual via psql pra destravar teste). Possivel causa: conflito de payload ou erro silencioso.
2. **Artista "astrix" no app mesmo com booking OK** — com `booking_stage_name = "Main Stage"` no banco, a API `listArtistBookings` retorna 1 item (logs confirmam), mas no filtro "Main Stage" parece ficar oculto. Andre vai re-investigar.

### Documentado e ainda nao implementado
- Confirmacao manual ainda necessaria no dropdown da ArtistsLineupSection se precisar editar booking — caminho alternativo e usar `/artists` pagina dedicada.

### Pre-existente, nao-bloqueante
- Hints de linter PHP (variaveis declaradas nao usadas, `curl_close` depreciado em PHP 8.5) — nao afetam runtime.

---

## COMANDOS DE EXECUCAO

### Backend PHP (precisa de `0.0.0.0` para LAN e `FEATURE_AI_VOICE_PROXY=1` no .env)
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun\backend; C:\php84\php.exe -d upload_max_filesize=200M -d post_max_size=200M -d memory_limit=256M -S 0.0.0.0:8080 -t public
```

### Frontend admin web
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun\frontend; npm run dev -- --port 3003
```

### App participante (Expo SDK 54)
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun-participant; npx expo start --clear --port 8082 --lan
```

Login admin: `admin@enjoyfun.com.br` / `123456`

---

## PROXIMOS PASSOS SUGERIDOS

1. **Investigar dropdown de palco** que nao salva na ArtistsLineupSection (provavel: erro silencioso no PATCH `/artists/bookings/{id}` — precisa confirmar payload + response)
2. **Polir a ArtistsLineupSection** — reativar diagnostico do card vazio quando filtro "Main Stage" e booking esta la
3. **Mapa 3D interativo** (pan/zoom/rotate com `react-native-reanimated`)
4. **Upload de fotos de galeria** (tipo wedding) com album dedicado
5. **D6 — Permissoes de leitura publica** — talvez algumas midias (banner, tour) poderiam ser link publico direto (ja tem /public mas sem ACL por evento)
6. **EAS Build** do app participante pra distribuicao interna (TestFlight/APK)

---

*Sessao encerrada 2026-04-18 com 8 sprints completados em sequencia. Proximo chat deve focar no debug da ArtistsLineupSection (artista nao aparece filtrado) e continuar com media em blocos faltantes (expositores, galeria wedding).*
*Diario em `docs/progresso32.md`. Prompt proxima sessao em `docs/prompt_proximo_chat.md`.*
