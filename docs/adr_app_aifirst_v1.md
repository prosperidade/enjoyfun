# ADR — App AI-First (Expo + PWA + Adaptive UI)

**Status:** Aceito — 2026-04-10
**Contexto:** Sprint 1+2+3 do app conversacional inspirado no Revolut AIR, descrito em `docs/app-aifirst.md`.
**D-Day alvo:** 2026-04-29 (evento real, 5000+ pessoas).

---

## Decisões

### 1. Um codebase, três alvos

| Alvo | Stack | Artefato | Distribuição |
|---|---|---|---|
| iOS | Expo SDK 52 + React Native 0.76 + TypeScript | .ipa via EAS | TestFlight (D-Day) → App Store (pós) |
| Android | mesma base | .apk via EAS preview + .aab produção | APK direto + Internal Testing → Play Store |
| PWA | Vite + React 18 + vite-plugin-pwa | `dist/` estático + `sw.js` | `app.enjoyfun.com.br`, instalável via banner |

Motivação: contrato de blocos adaptativos idêntico nos 3 permite paridade visual. Mobile e web compartilham os mesmos 10 tipos de bloco com renderers nativos.

### 2. Contrato de blocos adaptativos (frozen)

O backend retorna em `POST /ai/chat` (gated por `FEATURE_ADAPTIVE_UI`):

```
{
  session_id, agent_key,
  blocks: [...],           // ordem importa, renderizada top-to-bottom
  text_fallback: "...",    // a11y + clientes legados
  meta: { tokens_in, tokens_out, latency_ms, provider, model },
  execution: null | id     // quando há tool_calls pendentes de aprovação
}
```

Tipos implementados (Sprint 1+2): `insight`, `chart`, `table`, `card_grid`, `actions`, `text`, `timeline`, `lineup`, `map`, `image`. Adicionar novo tipo = atualizar contrato + 3 renderers simultaneamente.

Backward-compat preservado: os campos antigos (`content`, `content_type`, `metadata`) continuam saindo quando a flag está desligada. `AIResponseRenderer.jsx` legado segue funcionando como fallback.

### 3. Auth multi-alvo — cookie HttpOnly no web, SecureStore no mobile

O backend detecta header `X-Client: mobile|ios|android|expo` e devolve o JWT no corpo em vez de cookie. Web continua HttpOnly. Mobile grava no `expo-secure-store` (Keychain iOS / Keystore Android), que é tão seguro quanto o cookie HttpOnly e resolve o fato de RN não ter cookie jar compartilhado.

`authShouldUseAccessCookie()` / `authShouldUseRefreshCookie()` em [AuthController.php](../backend/src/Controllers/AuthController.php) implementam esse gate.

### 4. Charts — react-native-chart-kit, não victory-native

Tentamos victory-native@41 primeiro. Conflito: exige `@shopify/react-native-skia@2.6` que pede React 19, mas Expo SDK 52 = React 18.3. Swap para `react-native-chart-kit` (sem Skia, peer de `react-native-svg` que Expo já traz). Trade-off aceito: menos chart types, mas zero conflito.

### 5. Mapa — iframe OSM no web, preview nativo + Linking no mobile

Para evitar deps pesadas (`react-leaflet`+`leaflet` no web, `react-native-maps` nativo no mobile), o `MapBlock`:
- **Web:** iframe `openstreetmap.org/export/embed.html` com bbox calculado. Zero dep nova.
- **Mobile:** preview estilizado com pin + coordenadas + botão "Abrir no mapa" via `Linking.openURL('https://www.openstreetmap.org/...')`. Zero dep nova.

Quando der tempo pós D-Day, podemos trocar por mapa interativo completo sem mexer no contrato.

### 6. Biometria real para ações de escrita

`expo-local-authentication` → `authenticateAsync` antes de chamar `onAction` em qualquer `ActionsBlock` com `requires_biometric: true`. FaceID / TouchID / PIN do dispositivo. Desktop PWA: `window.confirm()` placeholder até WebAuthn (pós D-Day).

### 7. i18n global — detect locale, LLM responde no idioma

App é global. Locale detectado via `Intl.DateTimeFormat().resolvedOptions().locale` (mobile) e `navigator.language` (web). Enviado em `context.locale` do `sendChatMessage`. Backend `aiResolveLocaleLanguage()` mapeia BCP-47 para 15 idiomas conhecidos e **prepend-a um system message** ao `$payload['messages']` instruindo o LLM a responder naquele idioma. Funciona com OpenAI e Gemini (qualquer provider que respeite role=system).

Strings UI pré-primeira-resposta (welcome prompt, empty states, modals) traduzidas localmente em pt/en/es via `src/lib/i18n.{ts,js}`. Todo o resto vem traduzido do backend.

### 8. PWA — vite-plugin-pwa com manifest endurecido

`frontend/vite.config.js` já tinha o plugin. Endurecemos:
- `theme_color` e `background_color` → `#0A0A0A`
- `start_url`, `scope`, `orientation: portrait`, `display: standalone`
- Ícones 192/512 gerados via [scripts/generate_placeholder_icons.mjs](../scripts/generate_placeholder_icons.mjs)
- Página pública `/baixar` com detecção de plataforma e 3 CTAs (App Store / Play / Install PWA)

### 9. Auto-welcome na primeira abertura

Zero-typing experience: no primeiro mount do `ChatScreen` (mobile) ou na primeira abertura do `UnifiedAIChat` (web), disparamos automaticamente a pergunta `t('welcome_prompt')` (localizada). Resultado: usuário vê o chat já renderizando lineup + timeline + map + alertas sem precisar digitar.

Implementado com `useRef` flag para garantir que só dispara uma vez por sessão de app.

### 10. Riscos aceitos para o D-Day

| Risco | Mitigação |
|---|---|
| App Store review pode demorar 24-72h | TestFlight (Internal) cobre o D-Day; store pública fica pós |
| `EAS projectId` precisa ser interativo | `eas init` fica a cargo do operador antes do primeiro build |
| Ícones são placeholders monocromáticos | Designer entrega antes da build de produção |
| PWA iOS não tem push | Documentado; usuários iOS que quiserem push usam TestFlight |
| `react-native-chart-kit` menos polido que victory | Aceito — charts funcionais, polish no sprint 4 |

---

## Alternativas rejeitadas

- **Bare React Native workflow** — rejeitado: perderíamos EAS Build e OTA updates, custaria tempo de config iOS/Android que não temos.
- **Flutter** — rejeitado: reescrita total, zero reuso do código React/Vite atual.
- **Embutir chat no PWA único sem app nativo** — rejeitado: não atende o requisito de 3 opções de download (Android, iOS, PWA).
- **WebSocket streaming desde o Sprint 1** — deferido: REST simples funciona, streaming entra no Sprint 4 quando o contrato já estiver testado em campo.

---

## Consequências

- Novo tipo de bloco exige 3 PRs coordenados (backend `AdaptiveResponseService` + web `AdaptiveUIRenderer.jsx` + mobile `AdaptiveUIRenderer.tsx`). Workflow: editar o contrato neste ADR primeiro, depois fan-out.
- Mudança de schema de bloco exige bump de `FEATURE_ADAPTIVE_UI` para versão nova (ex.: `FEATURE_ADAPTIVE_UI_V2`) para não quebrar clientes antigos em campo.
- Qualquer adição de locale suportado é 2-line change: mapa em `i18n.{ts,js}` + `aiResolveLocaleLanguage()` no backend.
