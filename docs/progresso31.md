# Progresso 31 — Upload de Media (Fase 1) + Blocos RN com Imagens
## Data: 2026-04-17

---

## Objetivo da sessao

Permitir que o organizador suba imagens/videos no painel web, e que esses arquivos apareçam automaticamente nos blocos do app participante.

---

## ENTREGAS

### Sprint D — Media Upload Chain

| Sprint | Entrega | Estado |
|--------|---------|--------|
| D1 | Migration 105: `image_url`, `video_url`, `video_360_url` em 10 tabelas | Done |
| D2a | 8 Controllers PHP aceitam campos de media + fix do bug pre-existente de organizer_id ausente no INSERT | Done |
| D2b | Helper `b2cResolveFileUrl()` + 7 builders do `/b2c/chat` passam URLs pros blocos | Done |
| D3 | Componente `MediaUpload.jsx` reutilizavel com drag-and-drop + preview de imagem/video | Done |
| D4 | `ItemRow` helper + integrado em 8 secoes (Stages, Sectors, Parking, PDV, Tables, Exhibitors, Ceremony, SubEvents) + MapsSection refatorado usando MediaUpload (suporte a video) | Done |
| D6 (parcial) | Helper `resolveMediaUrl()` + `authImageSource()` com cache de token + atualizado em **EventHub**, **Lineup**, **MapOverview** RN | Done |

### Cleanup bonus
- Corrigido 19 erros pre-existentes de eslint (37 → 18)
- Ajustado `eslint.config.js` pra tratar `argsIgnorePattern` de componentes PascalCase
- CSP no Vite liberado pra Google Fonts + imagens https + videos
- Limite de upload do OrganizerFileController aumentado pra 200MB
- Allowlist de MIME expandida: videos (mp4, webm, mov), modelos 3D (glb, gltf), mais imagens (gif, svg, avif)

### Outros fixes do backend
- Fixes de `organizer_id` no INSERT: `event_stages`, `event_sectors`, `event_pdv_points`, `event_tables`, `event_parking_config`, `event_ceremony_moments`, `event_sub_events`, `event_exhibitors`
- `EventSectorController`: boolean fix (`allows_reentry` com `true/false` vs `'t'/'f'`)
- `EventService`: aceita campos `tour_video_url`, `tour_video_360_url`

---

## ARQUIVOS CRIADOS/MODIFICADOS

### Frontend (web)
```
frontend/
├── eslint.config.js              # argsIgnorePattern adicionado
├── vite.config.js                # CSP permissivo pra fonts/imagens/videos
└── src/
    ├── components/
    │   ├── MediaUpload.jsx       # NOVO: drag-drop + preview + accept por tipo
    │   └── EventModuleSections.jsx # ItemRow helper + 8 secoes integradas
    └── pages/Events.jsx          # +tour_video_url + tour_video_360_url no form
```

### Backend (PHP)
```
backend/
├── database/105_media_urls_per_entity.sql  # NOVO
└── src/
    ├── Services/EventService.php           # +tour_video_url, tour_video_360_url
    └── Controllers/
        ├── OrganizerFileController.php     # MIME+video, limite 200MB, debug logs
        ├── B2CAppController.php            # b2cResolveFileUrl() + 7 builders
        ├── EventStageController.php        # +image/video/video_360/description + fix organizer_id
        ├── EventSectorController.php       # +image/video + fix organizer_id + fix boolean
        ├── EventPdvPointController.php     # +image_url + fix organizer_id
        ├── EventTableController.php        # +layout_image_url + fix organizer_id
        ├── EventParkingConfigController.php # +map_image_url/video_url + fix organizer_id
        ├── EventCeremonyMomentController.php # +image_url + fix organizer_id
        ├── EventSubEventController.php     # +image_url/video_url + fix organizer_id
        └── EventExhibitorController.php    # +logo/booth_photo/presentation_video + fix organizer_id
```

### App Participante (Expo)
```
enjoyfun-participant/
├── App.tsx                              # preload auth token cache
└── src/
    ├── lib/media.ts                     # NOVO: resolveMediaUrl + authImageSource + token cache
    └── components/blocks/
        ├── EventHub.tsx                 # banner_url como background
        ├── Lineup.tsx                   # hero por palco + foto por artista
        └── MapOverview.tsx              # mapa real uploaded + POIs com fotos
```

---

## PROBLEMA EM ABERTO (prox sessao)

### Sintoma
App nao mostra as imagens subidas mesmo com backend retornando as URLs corretamente.

### Debug ja feito
1. **Backend OK** — endpoint retorna `map_3d_url: "/api/organizer-files/23/download"` e similares
2. **Download funciona** — `curl` com Bearer token baixa 9.4MB do PNG (HTTP 200)
3. **Banco OK** — `events.map_3d_url = "file:23:plantabaixa.png"`
4. **Helper funciona** — `b2cResolveFileUrl()` converte "file:23:..." em "/api/organizer-files/23/download"
5. **App com auth header** — `authImageSource()` retorna `{ uri, headers: { Authorization: Bearer } }`

### Log de debug adicionado em MapOverview.tsx
```typescript
if (__DEV__) {
  console.log('[MapOverview] block:', JSON.stringify({...}));
  console.log('[MapOverview] realMapSource:', JSON.stringify(realMapSource));
}
```

### Proximos passos de investigacao
1. Verificar se o **codigo novo dos blocos** esta bundleado no app (o user pode estar vendo cache antigo)
2. Se `console.log` nao aparece no terminal do Expo, app nao pegou mudancas
3. Se aparece **mas `realMapSource` e `null`**, problema no `authImageSource` (talvez cache do token vazio no boot)
4. Se aparece **com source certo mas imagem nao renderiza**, problema de CORS/MIME no `<ImageBackground>` do RN

### Solucao alternativa (fallback)
Criar endpoint publico `/organizer-files/{hash}/public` que nao exige auth. Hash seria um token assinado por evento pra evitar scraping.

---

## COMANDOS DE EXECUCAO

### Backend (PHP 8.4, upload 200MB)
```batch
C:\php84\php.exe -d upload_max_filesize=200M -d post_max_size=200M -d memory_limit=256M -S localhost:8080 -t public
```

### Frontend (web admin)
```batch
npm run dev -- --port 3003
```

### App Participante
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun-participant
npx expo start --clear --port 8082 --lan
```

Celular (Expo Go): `exp://192.168.1.42:8082`

### Login
- Admin: `admin@enjoyfun.com.br` / `123456`

---

## ESTADO DO BANCO (Event 1 — EnjoyFun 2026)

```
events.map_3d_url = "file:23:plantabaixa.png"
events.map_image_url, map_seating_url, map_parking_url = tambem preenchidos
events.banner_url = NULL (nao uploaded ainda)
events.tour_video_url = NULL

event_stages: 4 palcos sem image_url (organizer_id=2 corrigido hoje)
event_sectors: 4 setores sem image_url
event_pdv_points: 5 PDVs sem image_url
```

O user subiu apenas os 4 mapas (MapsSection) com sucesso. Nao subiu fotos de palcos/PDVs/setores ainda — provavelmente vai fazer depois que o sistema mostrar a planta baixa funcionando.

---

## PROXIMOS PASSOS (apos resolver o problema em aberto)

1. **D5 — Workspace de Media dedicado**: pagina nova com grid visual de TODOS os assets do evento centralizados
2. **Tecnica Stitch CSS 3D isometrico** em mais blocos
3. **Mapa 3D interativo** com pan/zoom/rotate (expo-three ou pinch/zoom de imagem)
4. **Voz nativa** (EAS Build ou migrar pra expo-audio SDK 54)
5. **Fotos de galeria** (casamento) com upload por evento

---

*Sessao encerrada em 2026-04-17 com problema pendente: imagens subidas nao renderizam no app.*
*Proximo chat: prompt em `docs/prompt_proximo_chat.md`*
