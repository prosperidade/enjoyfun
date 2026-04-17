# Prompt pro proximo chat — Debug renderizacao de imagens uploaded no app participante

**Cole isso no inicio do novo chat:**

---

Leia `CLAUDE.md` e `docs/progresso31.md` antes de qualquer acao.

## Contexto rapido

Sessao anterior entregou **Sprint D — Media Upload Chain**:
- Organizador sobe imagens/videos no painel web (componente `MediaUpload` com drag-and-drop + preview)
- Backend salva em `organizer_files` + referencia `"file:{id}:{name}"` nos campos das 8 tabelas de evento (events, event_stages, event_sectors, event_pdv_points, event_tables, event_parking_config, event_ceremony_moments, event_sub_events, event_exhibitors)
- Migration 105 aplicada com `image_url`, `video_url`, `video_360_url` em 10 tabelas
- 8 controllers PHP atualizados + bug pre-existente de `organizer_id` ausente no INSERT corrigido
- Helper `b2cResolveFileUrl()` no backend converte refs pra URLs absolutas
- Helper `authImageSource()` no app participante monta `{ uri, headers: { Authorization: Bearer } }` pro `<Image>` do RN
- MapOverview, Lineup e EventHub atualizados pra renderizar imagens uploaded

## Problema em aberto

O organizador **ja subiu** a planta baixa do evento 1 (EnjoyFun 2026):
- `events.map_3d_url = "file:23:plantabaixa.png"` (confirmado no banco)

Backend **retorna corretamente** no `POST /b2c/chat` quando pede "mapa":
```json
{
  "type": "map",
  "map_3d_url": "/api/organizer-files/23/download",
  "markers": [...]
}
```

`curl` com Bearer token baixa o PNG (HTTP 200, 9.4MB de imagem).

**Mas no app participante a imagem NAO aparece** — continua mostrando o grid isometrico fake com POIs antigos.

## Debug ja feito (nao precisa refazer)

1. Backend OK — endpoint retorna URLs corretas
2. Download funciona — `curl` com Bearer → 200
3. Banco OK — `map_3d_url` preenchido
4. `b2cResolveFileUrl()` converte `"file:23:..."` corretamente
5. `authImageSource()` retorna source com headers
6. App Expo rodando em `192.168.1.42:8082` (LAN acessivel)
7. Backend em `localhost:8080` E `192.168.1.42:8080` ambos respondendo

## Log de debug ja instrumentado

Em `enjoyfun-participant/src/components/blocks/MapOverview.tsx` (linhas proximas a 65):
```typescript
if (__DEV__) {
  console.log('[MapOverview] block:', JSON.stringify({
    map_image_url: block.map_image_url,
    map_3d_url: block.map_3d_url,
    tour_video_url: block.tour_video_url,
  }));
  console.log('[MapOverview] realMapSource:', JSON.stringify(realMapSource));
}
```

## Primeira acao no novo chat

Pedir ao Andre pra:
1. Rodar no PowerShell:
   ```powershell
   cd C:\Users\Administrador\Desktop\enjoyfun-participant
   npx expo start --clear --port 8082 --lan
   ```
2. No celular (Expo Go): abre, faz login, digita **"mapa"**
3. **Copia as 2 linhas** `[MapOverview]` que aparecem no terminal do Expo

Com esses logs descobrimos:
| Situacao | Diagnostico | Solucao |
|---------|-------------|---------|
| Nao aparece log | App nao pegou codigo novo | Deletar `node_modules/.cache` ou rebuildar bundle |
| `block` sem URLs | Problema na serializacao do `/b2c/chat` | Verificar response direto com curl |
| `realMapSource = null` | Cache do token vazio no boot (race condition) | Melhorar `App.tsx` pra aguardar token antes de renderizar |
| Tem source mas nao renderiza | MIME/CORS no `<ImageBackground>` do RN | Endpoint publico fallback |

## Solucao alternativa (fallback se tudo falhar)

Criar endpoint publico `GET /organizer-files/{id}/public` que nao exige `requireAuth()`. Protege por ID dificil de adivinhar e escopo de evento. Elimina necessidade de Bearer no header da `<Image>`.

## Arquivos chave

- `docs/progresso31.md` — entregas da sessao + estado do banco
- `enjoyfun-participant/src/lib/media.ts` — resolveMediaUrl + authImageSource + token cache
- `enjoyfun-participant/src/components/blocks/MapOverview.tsx` — usa `authImageSource(block.map_3d_url)`
- `enjoyfun-participant/App.tsx` — preload do token no boot
- `backend/src/Controllers/OrganizerFileController.php` — `/organizer-files/{id}/download` com `requireAuth()`
- `backend/src/Controllers/B2CAppController.php` — `b2cResolveFileUrl()` + `buildMapBlocks()`

## Comandos de execucao

### Backend (PHP 8.4, 200MB upload)
```batch
C:\php84\php.exe -d upload_max_filesize=200M -d post_max_size=200M -d memory_limit=256M -S localhost:8080 -t public
```

### Frontend admin web
```batch
cd C:\Users\Administrador\Desktop\enjoyfun\frontend
npm run dev -- --port 3003
```

### App participante
```powershell
cd C:\Users\Administrador\Desktop\enjoyfun-participant
npx expo start --clear --port 8082 --lan
```

Login: `admin@enjoyfun.com.br` / `123456`

## Depois que resolver o problema de renderizacao

1. **D5 — Workspace de Media dedicado**: pagina nova com grid visual centralizado de TODOS os assets do evento
2. Mapa 3D interativo (pan/zoom/rotate com `react-native-reanimated`)
3. Tecnica Stitch CSS 3D isometrico em mais blocos
4. Voz nativa (EAS Build ou migrar pra `expo-audio` SDK 54)
5. Upload de fotos de galeria (casamento)

## Regras inviolaveis (do CLAUDE.md principal)

1. `organizer_id` vem SEMPRE do JWT
2. Backend PHP **8.4** (nao 8.5 que tem bug do dispatcher)
3. Coluna de data e `starts_at` / `ends_at` (nao `start_date`)
4. App participante e **chat-first** — nao criar telas, criar blocos
