# Runbook — Build e Deploy do App EnjoyFun

**Alvos:** iOS (TestFlight), Android (APK + Internal Testing), PWA web.
**Stack:** Expo SDK 52 + EAS Build + Vite PWA.
**Pré-requisitos:** Node 20+, conta Expo, (opcional) conta Apple Developer e Google Play Console.

---

## 0. Variáveis de ambiente necessárias

### Backend (`backend/.env`)
```
FEATURE_AI_CHAT=true
FEATURE_ADAPTIVE_UI=true
FEATURE_AI_INTENT_ROUTER=true
# demais flags existentes
```

### Frontend web (`frontend/.env` opcional)
```
VITE_FEATURE_AI_V2_UI=true
```

### Mobile (`enjoyfun-app/.env` ou var de ambiente do shell)
```
EXPO_PUBLIC_API_URL=https://api.enjoyfun.com.br/api
```
Para dev em device físico, trocar por IP da LAN: `http://192.168.1.10:8080/api`.

---

## 1. Primeira vez — setup

```bash
# 1.1 Mobile deps
cd enjoyfun-app
npm install

# 1.2 Login Expo / EAS
npm i -g eas-cli
eas login
eas init                      # preenche projectId no app.json automaticamente

# 1.3 Credenciais de assinatura
eas credentials                # iOS: gera cert + profile. Android: gera keystore managed.

# 1.4 Sanity check
npx tsc --noEmit              # deve sair EXIT=0
```

---

## 2. Desenvolvimento local

### Backend dev server
```bash
cd backend
php -S 0.0.0.0:8080 -t public  # ou o script que já uso hoje
```

### Frontend web dev
```bash
cd frontend
npm run dev                    # Vite em :5173
```

### Mobile Expo dev
```bash
cd enjoyfun-app
npx expo start
# pressione i = iOS simulator, a = Android emulator, w = web, ou escaneie QR com Expo Go
```

Para device físico conectado na mesma LAN, exporte `EXPO_PUBLIC_API_URL` com o IP da máquina antes de `expo start`.

---

## 3. Build de preview (interno)

### Android APK direto (distribuição manual / QR no evento)
```bash
cd enjoyfun-app
eas build -p android --profile preview
# ~10-15min. Ao terminar, o CLI devolve URL do APK. Faça upload em enjoyfun.com.br/baixar
```

### iOS simulator build (desenvolvimento)
```bash
eas build -p ios --profile development
# Requer Apple Developer ($99/ano) e device registrado
```

### PWA web preview
```bash
cd frontend
npm run build                  # gera dist/ com sw.js + manifest + precache
# Teste local:
npx serve dist -l 4173
# Abra http://localhost:4173/baixar, verifique PWA install banner
```

---

## 4. Build de produção

### Android .aab para Play Store
```bash
cd enjoyfun-app
eas build -p android --profile production
# Submete ao Google Play Console:
eas submit -p android --latest
```

### iOS .ipa para TestFlight / App Store
```bash
eas build -p ios --profile production
eas submit -p ios --latest
# Primeira vez: responda as perguntas do CLI sobre Apple ID + ASC app record
```

**Prazo de review realista:**
- Android: 2-24h para aprovar Internal Testing, 1-3 dias para pública
- iOS TestFlight: 10-30min para Internal, 24h para External review
- iOS App Store: 24-72h atualmente

### PWA em produção
```bash
cd frontend
npm run build
# Deploy dist/ para o host — nginx atual já tem CSP + HTTPS configurado
rsync -avz dist/ user@servidor:/var/www/app.enjoyfun.com.br/
```

---

## 5. OTA Updates (Expo Updates)

Para hotfixes sem passar pela store:
```bash
cd enjoyfun-app
eas update --branch production --message "fix: ajuste rate limit chart"
# Devices que abrirem o app em até 30s baixam a atualização
```

Limite: OTA **não** pode alterar código nativo (biblioteca nova com módulo nativo, permissão de sistema, plugin do Expo). Se precisar disso, é build novo.

---

## 6. Rollback

### Mobile
- Build anterior no painel EAS → marcar como "Active"
- Para OTA, `eas update:rollback --branch production`

### PWA
- `rsync` da build anterior
- Service worker se auto-atualiza na próxima visita (vite-plugin-pwa com `registerType: 'prompt'`)

---

## 7. Monitoramento pós-deploy

- **Backend:** `tail -f backend_dev_stdout.log backend_dev_stderr.log` (dev) ou Sentry em prod (pendente)
- **Mobile:** Expo dashboard (crashes + update reach). Sentry mobile pendente.
- **Frontend:** browser console + Sentry web pendente
- **Telemetria API:** `observeApiRequestTelemetry` já loga critical endpoints no `/api/health`

---

## 8. Checklist pré-D-Day (2026-04-29)

- [ ] `eas init` executado, projectId no app.json
- [ ] Ícones reais (não placeholders) em `enjoyfun-app/assets/` e `frontend/public/icon-*.png`
- [ ] Build Android preview gerado e APK testado em 2+ devices reais
- [ ] TestFlight iOS aprovado, link distribuído
- [ ] PWA em `app.enjoyfun.com.br` com HTTPS válido
- [ ] Página `/baixar` com os 3 links vivos
- [ ] QR code físico impresso apontando para `/baixar`
- [ ] `FEATURE_ADAPTIVE_UI=true` no `.env` de produção
- [ ] Load test k6 nos endpoints críticos (`tests/load_test_k6.js`)
- [ ] Backend em cluster com fallback (Cloudflare na frente)
- [ ] Rollback plan documentado e testado

---

## 9. Troubleshooting rápido

| Sintoma | Causa provável | Ação |
|---|---|---|
| `npm install` falha com ERESOLVE no mobile | Peer dep de chart lib | Já resolvido: usamos `react-native-chart-kit`. Se voltar, rode `npm install --legacy-peer-deps` |
| Mobile não conecta ao backend | `EXPO_PUBLIC_API_URL` apontando para localhost em device físico | Troque pelo IP da LAN |
| Login mobile trava | Backend está devolvendo cookie HttpOnly | Garanta que o `apiClient` envia `X-Client: mobile` header (já configurado) |
| Chat retorna texto puro em vez de blocks | `FEATURE_ADAPTIVE_UI` desligada | Ligue no `.env` do backend |
| PWA não instala no Android | `icon-192.png` / `icon-512.png` faltando em `frontend/public/` | Rode `node scripts/generate_placeholder_icons.mjs` |
| Build EAS falha "project not configured" | `eas init` não foi rodado | Rode `cd enjoyfun-app && eas init` |
| LLM responde em inglês mesmo com locale pt-BR | `context.locale` não está chegando no backend | Confira `aiResolveLocaleLanguage()` no log e o payload do chat |
| Metro bundle erro `Cannot find module 'babel-preset-expo'` | Dep core faltando no scaffold | `cd enjoyfun-app && npx expo install babel-preset-expo --dev` |
| App crasha na abertura: `"main" has not been registered` | Entry point nao declarado no SDK 54+ | Criar `enjoyfun-app/index.ts` com `registerRootComponent(App)` + setar `"main": "index.ts"` no `package.json` |
| Expo Go rejeita o projeto: `incompatible SDK` | Expo Go do celular eh SDK X, projeto eh Y | Upgrade o projeto: `cd enjoyfun-app && npx expo install expo@latest && npx expo install --fix` (cuidado com React 18→19 breaking) |
| `victory-native` ERESOLVE com React 18 | Skia@2.6 exige React 19 | Removido — usamos `react-native-chart-kit` |
| Login mobile "volta pra tela de login" apos sucesso | Shape errado: mobile lendo `data.token` | Backend devolve `data.data.access_token` — ver `enjoyfun-app/src/api/auth.ts` |
| Chat sem resposta visual (HTTP 200 mas tela vazia) | `sendChatMessage` nao desempacota `body.data` | Corrigido em `enjoyfun-app/src/api/chat.ts` — unwrap `ApiEnvelope<T>` |
| Backend 500 com erros bizarros (`pg_lo_import`, `openssl_cms_encrypt`) em linhas que nao chamam essas funcoes | Bug do dispatcher PHP 8.5.1 Windows | Use PHP 8.4 — ver `docs/runbook_local.md` secao 1.1 |
| Backend 500 `could not find driver` | `extension=pdo_pgsql` nao habilitado no php.ini | Editar php.ini e reiniciar o server |
| Backend HTTPS "unable to get local issuer certificate" | `curl.cainfo` nao setado | Baixar `cacert.pem` do curl.se e apontar no php.ini |
