# ADR — Voice Proxy (Whisper backend) v1

**Status:** Aceito — 2026-04-11
**Contexto:** Pendência HIGH em [CLAUDE.md](../CLAUDE.md) seção 🟡 PENDÊNCIAS DE SEGURANÇA + decisão de arquitetura do Sprint 5 do [execucaobacklogtripla.md](../execucaobacklogtripla.md).
**Implementação:** Sprint 5 (BE-S5-C* + MO-S5-*).

---

## Contexto

O app mobile EnjoyFun (`enjoyfun-app/`, Expo SDK 52) tem voz nativa funcionando ponta-a-ponta desde 2026-04-10:

- Botão mic no `ChatInput` grava via `expo-audio`
- Áudio enviado direto pro endpoint Whisper da OpenAI (`api.openai.com/v1/audio/transcriptions`)
- TTS opcional na resposta via `expo-speech`

A chamada do Whisper hoje sai **direto do device** usando `EXPO_PUBLIC_OPENAI_KEY`. Isso significa que:

1. **A chave OpenAI vai parar no bundle JS do app**
2. **APK Android é decompilável** — qualquer atacante extrai a chave em minutos
3. **iOS é menos trivial mas não imune** — `strings` no `.ipa` revela
4. **Rotacionar a chave significa rebuild + republish nas stores** — dor operacional alta
5. **Cap de gasto não funciona** porque a chave é do organizador inteiro, não do device

CLAUDE.md classifica isso como **HIGH severity, pós D-Day**. O Sprint 5 fecha esse buraco.

---

## Decisão

Mover a chamada Whisper (e qualquer chamada futura a APIs externas com chave secreta) do device para o **backend EnjoyFun**, atrás de um endpoint autenticado.

### 1. Endpoint backend

```
POST /api/ai/voice/transcribe
Content-Type: multipart/form-data
Authorization: Bearer <jwt>
X-Client: mobile

Body: file=<audio.m4a> · language=pt|en|es
```

Implementado em `AIController.php` (nova action `transcribe`). Atrás da feature flag `FEATURE_AI_VOICE_PROXY` (default off).

### 2. Pipeline

1. AuthMiddleware valida JWT (mesmo do `/ai/chat`)
2. `AIRateLimitService` aplica rate limit por organizador (20 req/min, configurável)
3. `AIBillingService` checa cap de gasto do organizador antes de chamar o provider
4. Backend abre stream para `api.openai.com/v1/audio/transcriptions` com a `OPENAI_API_KEY` lida do `.env` do servidor (nunca do device)
5. Resposta volta ao mobile como `{ text, language_detected, duration_ms }`
6. `AuditService::log` registra a transcrição (sem o áudio bruto, só metadados + hash do arquivo)
7. Áudio é descartado do servidor após a transcrição (zero retention)

### 3. Mobile

- `enjoyfun-app/src/lib/voice.ts` deixa de chamar `api.openai.com` direto
- Passa a chamar `apiClient.post('/ai/voice/transcribe', formData)` reusando o JWT do `expo-secure-store`
- `EXPO_PUBLIC_OPENAI_KEY` é **removida** do `app.config.*` e do bundle no S5
- Próximo build EAS sai sem a chave embarcada
- Mobile passa a respeitar `FEATURE_AI_VOICE_PROXY` (lido via constante de build) — fallback grava sem transcrever quando flag off

### 4. TTS

A síntese de voz (`expo-speech`) **continua nativa do device** porque usa o engine local do iOS/Android — não depende de chave externa, não vaza nada. Sem mudança.

### 5. Provider plugável

`AIVoiceTranscriptionService` (novo serviço backend) abstrai o provider. Default: OpenAI Whisper. Trocar para Gemini Audio, AssemblyAI, ou self-hosted Whisper.cpp = trocar implementação, sem mexer no endpoint nem no mobile.

---

## Consequências

### Positivas
- Chave OpenAI **nunca mais sai do servidor**
- Rotação de chave = `.env` no servidor + restart, sem rebuild de app
- Rate limiting e billing cap funcionam de verdade (organizador-scoped)
- Audit trail completo de uso de voz (compliance)
- Provider plugável → futuro multi-tenant com chave própria por organizador
- APK decompilado vira inofensivo

### Negativas
- Latência adicional do hop device → backend → OpenAI (~100-300ms a mais)
- Backend precisa aceitar uploads multipart de áudio (já aceita uploads, baixo esforço)
- Sem internet pro backend = sem voz (mas sem internet = sem chat também, então é equivalente)
- Custo de banda do servidor sobe (áudio é mais pesado que texto)

### Riscos mitigados
- **Áudio sensível parado no servidor** → zero retention, descarte imediato pós-transcrição
- **Backend vira gargalo de voz** → rate limit + horizontal scaling do PHP-FPM
- **Chave do servidor vazada** → mesma protecao das outras chaves: `.env` fora do git, rotação documentada no runbook

---

## Alternativas consideradas

1. **Self-hosted Whisper.cpp no servidor** — adiado: viável mas exige GPU pra latência aceitável; fica como opção pós-S6
2. **Manter chave no device atrás de obfuscation** — rejeitado: obfuscation não é segurança, só atrasa
3. **Rotacionar chave OpenAI a cada release do app** — rejeitado: dor operacional incompatível com cadência de release
4. **Usar a Web Speech API do device (sem provider externo)** — rejeitado: qualidade ruim em PT-BR offline, e a API não existe consistente em RN
5. **Stream WebRTC direto pro OpenAI Realtime API** — adiado: feature interessante mas exige refactor da lib de voz; fica pro pós-S6

---

## Critérios de aceite

- `POST /api/ai/voice/transcribe` retorna 200 com texto transcrito quando flag on
- Retorna 503 quando flag off (não 404, pra mobile saber que existe mas está desligado)
- Rate limit por organizador funcional (testado com 25 req em 1 min)
- Billing cap bloqueia transcrição quando organizador estourou o limite
- Mobile build do S5 não contém `EXPO_PUBLIC_OPENAI_KEY` em lugar nenhum (verificado por `grep` no bundle)
- Áudio descartado do servidor em < 1s após resposta (verificado em log)
- Audit log registra todas as transcrições com `organizer_id`, `user_id`, `duration_ms`, `provider`, `cost_usd`
- Smoke test: gravação no mobile → transcrição no backend → texto no chat (S5)
- Security scan 25 checks PASS sem nenhum hit em "API key in mobile bundle" (S6)
