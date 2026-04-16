# Prompt para proximo chat — EnjoyFun Participant App (B2C)

Cole isso no inicio do proximo chat:

---

Leia CLAUDE.md, docs/app-aifirst.md e enjoyfunuiux/stitch_eventverse_immersive_hub/aether_neon/DESIGN.md antes de qualquer acao.

## Contexto critico

Temos um app Expo em `c:\Users\Administrador\Desktop\enjoyfun-participant\` com 12 telas e bottom tabs. **ESTA TUDO ERRADO.** O app foi construido como um app convencional com menus e telas estaticas. O conceito real do projeto e AI-First:

- **Chat e a home** — sem menu, sem tabs, sem dashboard estatico
- **IA retorna blocos visuais** — nao texto. Componentes nativos renderizados (mapa, lineup, cards, 3D)
- **O visual e Aether Neon** — glassmorphism brutalism do Stitch, NAO componentes basicos
- **60 telas HTML** em `enjoyfunuiux/stitch_eventverse_immersive_hub/` sao a referencia visual obrigatoria

## O que existe e funciona

- Backend PHP 8.4 em `c:\Users\Administrador\Desktop\enjoyfun\backend\`
- `B2CAppController.php` com 11 endpoints (event, lineup, map, sectors, sessions, tables, stages, menu, tickets, wallet, transactions)
- Agente `b2c_concierge` no `ai_agent_registry` com prompt de participante
- Surface lock: `b2c → b2c_concierge` no IntentRouterService
- App Expo roda no Expo Go (porta 8082, IP 192.168.1.42)
- Login funciona com `admin@enjoyfun.com.br` / `123456`
- Evento mais populado: EnjoyFun 2026 (id=1, 32 produtos, 97 tickets)
- Coluna de data e `starts_at` (NAO `start_date`)

## Os 2 problemas que precisam ser resolvidos

### Problema 1: IA responde como organizador
O agente `b2c_concierge` existe no banco mas a IA cai no fallback do organizador. As tools do orquestrador sao todas de gestao (vendas, margem, workforce). O participante precisa de respostas sobre:
- Lineup (quem toca, que horas, qual palco)
- Mapa (onde fica bar, banheiro, palco)
- Ingresso (status, QR code)
- Cashless (saldo, onde recarregar)
- Agenda (sessoes, palestrantes)

**Solucao necessaria:** Criar um endpoint dedicado `/b2c/chat` que NAO usa o orquestrador do organizador. Esse endpoint deve:
- Receber a mensagem do participante
- Usar o system prompt do b2c_concierge
- Chamar a OpenAI diretamente com context do evento (dados do B2CAppController)
- Retornar blocos adaptativos formatados pro participante
- NUNCA expor dados do organizador

### Problema 2: Visual nao e o Stitch
O AdaptiveBlockRenderer atual tem componentes basicos com cores do Aether Neon mas NAO tem o visual dos mockups HTML. Os mockups tem:
- Holographic Pass com sheen layer e QR code neon
- 3D Isometric Map com POIs flutuantes em glass-card com glow pulsante
- AI Concierge com avatar pulse animation
- Brutalist headers com borda esquerda de 8px
- Glassmorphism real (backdrop-filter blur)
- Neon glow shadows (box-shadow com cor primaria)
- Tipografia Space Grotesk (headlines) + Manrope (body)
- Zero bordas 1px tradicionais

**Solucao necessaria:** Ler cada `code.html` dos mockups e converter fielmente pra React Native. As telas viram blocos que a IA retorna dentro do chat. Instalar `expo-blur` (ja instalado) e `expo-linear-gradient` (ja instalado) pra glassmorphism e gradients.

## Stack ativa
- Backend: PHP 8.4 porta 8080 (`C:\php84\php.exe -S 0.0.0.0:8080 -t public`)
- App: Expo SDK 54 porta 8082 (`.\node_modules\.bin\expo.cmd start --clear --port 8082 --lan`)
- Banco: PostgreSQL (enjoyfun, 127.0.0.1:5432, user postgres)
- IP local: 192.168.1.42

## Arquivos chave pra ler

### Conceito e design
- `docs/app-aifirst.md` — filosofia AI-First completa
- `enjoyfunuiux/stitch_eventverse_immersive_hub/aether_neon/DESIGN.md` — design system
- `enjoyfunuiux/stitch_eventverse_immersive_hub/enjoyfun_immersive_hub/code.html` — tela home
- `enjoyfunuiux/stitch_eventverse_immersive_hub/cashless_card_hub/code.html` — tela cashless
- `enjoyfunuiux/stitch_eventverse_immersive_hub/main_stage_line_up_overlay/code.html` — lineup
- `enjoyfunuiux/stitch_eventverse_immersive_hub/main_map_overview/code.html` — mapa
- `enjoyfunuiux/stitch_eventverse_immersive_hub/interactive_ticket_cards_update/code.html` — tickets
- `enjoyfunuiux/stitch_eventverse_immersive_hub/intelligent_agenda/code.html` — agenda

### Backend
- `backend/src/Controllers/B2CAppController.php` — endpoints existentes
- `backend/src/Services/AIIntentRouterService.php` — surface lock b2c
- `backend/src/Services/AIPromptCatalogService.php` — onde o prompt e composto
- `backend/src/Services/AIOrchestratorService.php` — orquestrador (NAO usar pro B2C)
- `backend/.env` — FEATURE_AI_AGENT_REGISTRY=true

### App mobile
- `enjoyfun-participant/App.tsx` — entry point (Login ou ImmersiveChatScreen)
- `enjoyfun-participant/src/screens/ImmersiveChatScreen.tsx` — tela unica do chat
- `enjoyfun-participant/src/components/AdaptiveBlockRenderer.tsx` — renderizador de blocos
- `enjoyfun-participant/src/components/GlassCard.tsx` — glassmorphism com BlurView
- `enjoyfun-participant/src/lib/theme.ts` — tokens Aether Neon
- `enjoyfun-participant/src/api/client.ts` — axios client (URL 192.168.1.42:8080)
- `enjoyfun-participant/src/api/b2c.ts` — endpoints B2C

## Ordem de execucao

1. **Criar `/b2c/chat`** — endpoint dedicado que busca dados do evento via B2CAppController e monta resposta com blocos adaptativos SEM usar o orquestrador do organizador
2. **Apontar o app pra `/b2c/chat`** em vez de `/ai/chat`
3. **Converter os HTMLs do Stitch** — ler cada code.html e reescrever os blocos do AdaptiveBlockRenderer com o visual REAL dos mockups
4. **Testar no Expo Go** — cada bloco, cada interacao

## Regras inviolaveis
- NAO criar menus, tabs, navegacao tradicional
- NAO usar o orquestrador do organizador (AIOrchestratorService) pro B2C
- NAO inventar visual — usar os HTMLs do Stitch como referencia obrigatoria
- CADA componente convertido deve ser testado antes de seguir pro proximo
- organizer_id vem do JWT, participante NUNCA ve dados do organizador
- Backend em PHP 8.4 (NAO 8.5 — bug do dispatcher)
- Coluna de data e `starts_at` / `ends_at` (NAO start_date/end_date)
