# Prompt pro proximo chat — Debug ArtistsLineupSection + continuar cobertura de midia

**Cole isso no inicio do novo chat:**

---

Leia `CLAUDE.md`, `docs/progresso32.md` e `docs/runbook_local.md` antes de qualquer acao.

Sessao anterior (2026-04-18) encerrou com 8 sprints completados (Lineup 3-niveis, VideoModal, endpoint publico, voz ponta-a-ponta, MediaWorkspace, e midia em 8 blocos do app). Detalhes completos em `progresso32.md`.

---

## Pendencias do dia — 2026-04-19

### P0 — Bugs em aberto (prioritario, rapido)

1. **Dropdown de palco na `ArtistsLineupSection` nao salva**
   - Arquivo: `frontend/src/components/EventModuleSections.jsx` — funcao `handleUpdateBookingStage`
   - Comportamento: user escolhe "Main Stage" no select, mas o `event_artists.stage_name` no banco continua "principal"
   - Diagnostico pendente: abrir DevTools Network, ver se o PATCH `/artists/bookings/{id}` dispara, qual payload vai, qual a resposta
   - Possivel causa: o body tem `event_id` + `artist_id` que o backend rejeita se nao bater
   - Fix provavel: confirmar que `booking.id` (artist master) esta certo; verificar se backend aceita `stage_name` sozinho no PATCH; ou mandar so o minimo

2. **Artista "astrix" nao aparece no filtro Main Stage da ArtistsLineupSection**
   - Banco: `event_artists.stage_name = "Main Stage"` (confirmado via psql), `legal_name = "astrix"`
   - Logs: `[ArtistsLineupSection] bookings loaded: 1` — a API retornou
   - Mas na UI, filtro "Main Stage" mostra "Nenhum artista vinculado" OU nao mostra o card
   - Suspeita: comparacao case-sensitive, ou `resolveBookingStage` retornando outra string
   - Acao: adicionar `console.log` por booking no filter pra ver o que esta vindo e comparar com stageFilter

### P1 — Cobertura de midia pendente

3. **Expositores / Exhibitors** — `event_exhibitors` ja tem `logo`, `booth_photo`, `presentation_video` no banco e backend envia. Componente `ExhibitorProfile.tsx` no app existe mas provavelmente nao renderiza as 3 midias. Verificar e adicionar.

4. **PDVs** — `event_pdv_points.image_url` ja chega como marker no MapOverview (thumbnail pequena). Considerar: tela/bloco dedicado `pdv_detail` ou grid maior?

5. **Galeria do casamento** — `PhotoGallery.tsx` existe mas nao temos ingestao de multiplas fotos por evento. Avaliar se vale adicionar um bloco visual com upload multiplo (seria nova feature, nao so plumbing).

### P2 — Melhorias naturais

6. **Mapa 3D interativo** — atualmente `ImageBackground` estatico. Com pan/zoom/rotate via `react-native-reanimated` ou `expo-image` com gestures viraria uma experiencia real de diorama.

7. **EAS Build do app participante** — hoje roda via Expo Go, precisa ser TestFlight/APK pra distribuicao. Configurar `eas.json` + criar profile `preview`.

8. **Reativar diagnostico de dropdown palco** — o log `[ArtistsLineupSection] PATCH /artists failed` esta no codigo mas precisa de teste real pra ver se dispara e com qual status.

---

## Contexto importante

### PHP precisa bind em `0.0.0.0:8080` (nao `localhost`)
Se Andre reiniciar o PHP e escolher `localhost:8080`, o celular nao conecta via LAN. Comando certo:
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun\backend; C:\php84\php.exe -d upload_max_filesize=200M -d post_max_size=200M -d memory_limit=256M -S 0.0.0.0:8080 -t public
```

### Voz exige `FEATURE_AI_VOICE_PROXY=1`
No `backend/.env`. Sem isso o endpoint retorna 403.

### Endpoint publico de midia
`GET /api/organizer-files/{id}/public` — sem auth, apenas MIME image/video. Usado pelo app participante em `<Image>` e `<VideoView>`. Mudar o MIME type do upload pode quebrar — usar sempre pelo `MediaUpload` do painel.

### Booking stage_name mismatch
O Andre ainda tem booking com `stage_name = "Main Stage"` no evento 1 (setei manual via `UPDATE event_artists ...`). Se ele apagar e recriar booking, o palco vai voltar ao que ele digitar — e pode nao bater com nenhum palco cadastrado. Corrigir o dropdown da ArtistsLineupSection e a validacao de match case-insensitive resolve.

### Telas que funcionam sob stress
- `/media` pagina completa do MediaWorkspace
- Edicao de evento com modulo `artists` habilitado
- `lineup` → detalhe direto (1 palco) ou lista (varios)
- Voz 🎤 + TTS 🔊 no app

### Regra inviolavel
1. `organizer_id` vem SEMPRE do JWT — nunca do body
2. Backend PHP **8.4** (nao 8.5 que tem bug do dispatcher)
3. App participante e chat-first — nao criar telas nativas, criar blocos
4. Endpoint `/public` serve apenas `image/*` e `video/*` — nao vazar PDFs/CSVs

---

## Primeira acao no novo chat

Abrir DevTools do browser (F12 → Network + Console), ir em Evento → Atracoes / Lineup → expandir card do artista → mudar dropdown de palco → coletar:
1. Request PATCH que disparou (URL, payload, response)
2. Erros no Console

Com isso resolve a pendencia P0-1 rapidamente. Depois testar se o artista aparece no filtro "Main Stage" (P0-2) adicionando log do booking no filter pra debug.

Em paralelo, pode abrir `/media` pra confirmar que a pagina nova esta rodando e testar delete/copy link.
