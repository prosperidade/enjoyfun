Auditoria — EnjoyFun Participant
Parei a análise técnica aqui porque já identifiquei o problema central que explica por que os agentes não estão entregando. Vou explicar antes de continuar, porque há uma decisão de produto que você precisa tomar primeiro.
O achado crítico: existem dois apps dentro deste zip
O projeto tem duas arquiteturas conflitantes, vivendo no mesmo código:
Arquitetura A — "Immersive Chat" (a que está rodando)

App.tsx monta só LoginScreen → ImmersiveChatScreen
Toda a UI do participante é um chat único que fala com POST /ai/chat e renderiza o que vier em blocks[] via AdaptiveBlockRenderer
Tagline no login: "A era dos menus acabou"
Manifesto claro: chat-first, sem navegação

Arquitetura B — "App tradicional com abas" (código morto)

11 telas prontas: EventHomeScreen, AgendaScreen, LineupScreen, MapScreen, TicketsScreen, CardScreen, SeatingScreen, ConciergeScreen, ProfileScreen, TicketPurchaseScreen, EventSelectorScreen
EventContext.tsx pronto para alimentar essas telas
src/api/b2c.ts com 10+ endpoints REST (/b2c/event, /b2c/lineup, /b2c/map, /b2c/tickets, /b2c/wallet…)
@react-navigation/native, bottom-tabs, native-stack instalados
A pasta src/navigation/ está vazia — ninguém conecta essas telas

Nenhuma dessas 11 telas é importada de lugar algum. Todo o trabalho de autenticação → EventContext → navegação por abas existe em arquivo mas está desligado do App.tsx.
Por que isso trava seus agentes
Quando um agente de IA abre o projeto para "entregar a feature X", ele encontra evidência contraditória:

Se pedem "tela de ingressos" — TicketsScreen.tsx já existe, parece pronto; o agente mexe nela, mas ela nunca renderiza no app real (ImmersiveChatScreen é a única tela pós-login).
Se pedem "adicionar aba de mapa" — as dependências de navigation estão instaladas, o MapScreen existe, mas não há NavigationContainer em lugar nenhum; o agente tenta plugar e quebra o paradigma chat-first.
EventContext oferece selectEvent, mas o ImmersiveChatScreen hardcoda const [eventId] = useState(1) com // TODO: event selector. O contexto existe, nada o consome.
api.ts do b2c aponta para rotas REST; o chat usa uma rota agentic diferente (/ai/chat). São dois backends mentalmente diferentes no mesmo cliente.

Resultado: cada tarefa tem dois "lugares certos" para ser feita, e qualquer caminho deixa metade do repositório dessincronizada. É por isso que eles "não estão dando conta" — não é capacidade, é ambiguidade arquitetural.
Outros problemas técnicos que já apareceram

Config de rede amarrada a IP de LAN — app.json define EXPO_PUBLIC_API_URL: "http://192.168.1.42:8080". Qualquer pessoa que testar fora dessa rede vai ver timeout. Deveria ser variável de ambiente por build (dev/staging/prod).
Estado-órfão no App.tsx — ao logar/deslogar, o app não limpa caches, não desmonta contexto, não propaga evento. Se um dia voltarem à arquitetura B, isso vira bug de sessão.
eventId fixo em 1 — o app em produção só funciona para o evento #1. Não há fallback, não há seletor, não há erro amigável se o evento não existir.
iOS-only glassmorphism — GlassCard só usa BlurView no iOS; Android cai num background semi-transparente sem blur. O design system é "Aether Neon" com vidro, mas metade dos usuários vê outra coisa.
Fontes comentadas — todo typography tem // TODO: load via expo-font. Space Grotesk e Manrope nunca são carregadas. expo-font está nas deps mas nenhum useFonts no App. Usuários veem a fonte do sistema, não a do design.
Sem tratamento de erro de rede no ImmersiveChatScreen — o catch genérico engole tudo e mostra sempre "Desculpe, tive um problema". Sem distinção entre 401, timeout, 500, rede offline.
Sem testes, sem lint config, sem CI visível — só tsc --noEmit. Nada impede regressão.
KeyboardAvoidingView sem keyboardVerticalOffset correto — em iOS com notch, a input bar tende a sumir atrás do teclado.
scrollToEnd com setTimeout(200) — gambiarra clássica; em devices lentos a mensagem nova fica fora da viewport.

Antes de eu continuar a auditoria
Preciso que você decida qual arquitetura é a verdadeira, porque a próxima metade do relatório depende disso:Continuar14:32Ok, vou continuar a auditoria cobrindo as duas arquiteturas e depois dar a recomendação. Primeiro preciso ver as telas órfãs para avaliar em que estado elas estão — se é código sério ou só stub.Visualizou 2 arquivosVisualizou 2 arquivosCódigo completo, sério, com contagem regressiva, navegação entre telas, uso de contexto. NãoA resposta do Claude foi interrompidaTentar novamenteArquitetura A — "Immersive Chat" (a que está rodando)14:33Beleza. Se a arquitetura verdadeira é a Immersive Chat, então todo o lado B (11 telas, EventContext, b2c.ts, react-navigation) é dívida — não é feature que falta entregar, é entulho que está confundindo os agentes. Vou reauditar o app inteiro sob essa ótica.
Deixa eu varrer o resto das telas órfãs só para medir quanto código vai embora, e olhar os pontos finos da ImmersiveChatScreen.Executou 2 comandosExecutou 2 comandosConfirmado: zero referências. As 11 telas e o EventContext são totalmente desconectadas. ~2.100 linhas de código morto + 1 contexto + 1 arquivo de API inteiro.Quem importa EventContext e b2cQuem importa EventContext e b2cImportante: LoginScreen importa login de b2c.ts. Então b2c.ts não some inteiro — só a função de login precisa sobreviver (ou migrar para um auth.ts dedicado). Todo o resto vai embora.Quem usa react-navigationQuem usa react-navigationSó as telas órfãs. Se elas caem, os 3 pacotes @react-navigation/* podem sair do package.json.Ver ConciergeScreen — a tela órfã mais parecida com Immersive; pode ter lógica útilVer ConciergeScreen — a tela órfã mais parecida com Immersive; pode ter lógica útilMuito revelador: ConciergeScreen é praticamente uma versão V1 mais pobre do ImmersiveChatScreen — mesma rota /ai/chat, mesmo backend, só sem blocos adaptativos. Isso prova que a arquitetura B foi escrita antes e o ImmersiveChatScreen foi o pivot para chat-first. Nunca limparam o legado.
Agora os pontos finos dentro da arquitetura A:Executou 2 comandosExecutou 2 comandosConfirmado: o chat é stateless do lado do cliente. Cada sendMessage manda só message + event_id, nunca manda o histórico anterior nem um conversation_id/thread_id. Se o backend /ai/chat depende de memória de sessão via header ou token, ok; mas não há garantia pelo contrato visível aqui. Isso é bug sério para o único paradigma do app.
Tenho material suficiente. Vou fechar o relatório.

Parte 2 da auditoria — relatório fechado para arquitetura A
1. Código morto a remover (urgente)
Isso é o que está travando seus agentes. Todo arquivo abaixo é importado apenas por outro arquivo desta mesma lista — é um ecossistema fechado de código desconectado do app real.
Apagar inteiros:

src/screens/EventHomeScreen.tsx (286 linhas)
src/screens/AgendaScreen.tsx (166)
src/screens/LineupScreen.tsx (145)
src/screens/MapScreen.tsx (161)
src/screens/TicketsScreen.tsx (212)
src/screens/CardScreen.tsx (225)
src/screens/SeatingScreen.tsx (118)
src/screens/ConciergeScreen.tsx (211) — é um ImmersiveChat V1 obsoleto
src/screens/ProfileScreen.tsx (171)
src/screens/TicketPurchaseScreen.tsx (249)
src/screens/EventSelectorScreen.tsx (151)
src/context/EventContext.tsx (71) — ninguém consome
src/navigation/ (pasta vazia)

Total: ~2.100 linhas + 1 pasta. Nenhum import quebra fora deste conjunto.
Enxugar src/api/b2c.ts: todos os endpoints REST ali listados (getEvent, getLineup, getMapPoints, getSectors, getSessions, getTables, getStages, getMenu, getMyTickets, getWallet, getTransactions, requestRecharge) só são chamados pelas telas órfãs. Na arquitetura chat-first, quem busca esses dados é o backend do /ai/chat, não o app. Preservar apenas login, e de preferência mover para um arquivo novo src/api/auth.ts — aí o arquivo b2c.ts inteiro some.
Desinstalar dependências:
@react-navigation/native
@react-navigation/bottom-tabs
@react-navigation/native-stack
react-native-qrcode-svg         (só CardScreen/TicketsScreen usam)
expo-local-authentication       (nunca é chamado no fluxo real)
Bundle encolhe bem. expo-local-authentication tem plugin em app.json e texto de Face ID — tudo ornamento.
2. Problemas reais no ImmersiveChatScreen (o que de fato roda)
Estes são os bugs que afetam o usuário hoje, em ordem de gravidade.
2.1 — Chat não mantém contexto de conversa. Cada POST /ai/chat envia só o turno atual. Não há conversation_id, thread_id nem histórico em context. Se o usuário perguntar "e o horário?" depois de "qual o line-up?", o backend não tem como correlacionar salvo via sessão HTTP opaca. Como todo o app é chat, isso é o bug de produto. Solução: gerar um conversation_id no primeiro render (UUID v4 em memória, ou derivado do token + timestamp), enviar em todos os requests, e opcionalmente mandar os últimos N turnos em context.history até o backend ter memória própria.
2.2 — eventId hardcoded em 1. Linha 40: const [eventId] = useState(1); // TODO: event selector. Se o participante logado tem ingresso para o evento 7, o concierge responde sobre outro evento. Como o app é mono-evento por paradigma, o caminho certo é: o backend, no login, devolver o event_id primário do usuário junto com o user; o front lê esse id via getUser() no useEffect e usa-o. Eliminar o TODO e a possibilidade de fallback silencioso em 1.
2.3 — catch genérico engole a causa. No sendMessage, qualquer erro vira "Desculpe, tive um problema". Sem distinção de 401 (sessão expirou — usuário fica preso num loop sem saber que deveria relogar, apesar do interceptor existir), 5xx (erro de servidor), timeout (pedir pra tentar de novo), offline (mostrar banner). O ImmersiveChatScreen deve inspecionar err.response?.status e renderizar mensagens específicas, além de expor botão "tentar novamente" em vez de deixar o balão morto.
2.4 — Auto-welcome sempre dispara. O useEffect de boas-vindas faz um POST toda vez que ImmersiveChatScreen monta, e monta toda vez que o usuário faz login. Sem cache, sem debounce. Se o usuário deslogar e logar de novo (ou o componente remontar por qualquer razão), você gasta uma chamada LLM desnecessária. Mitigar com cache curto por user_id em SecureStore ou apenas AsyncStorage — ou fazer o welcome estático do lado do cliente e só pedir à IA quando o usuário digita.
2.5 — Ações em blocks disparam texto, não intent estruturada. Linha 157: if (action.label) sendMessage(action.label);. Ou seja, quando o backend devolve um botão "Comprar ingresso", clicar nele manda a string literal "Comprar ingresso" como se o usuário tivesse digitado. Frágil a tradução, capitalização, emoji no label. O contrato correto seria o bloco actions ter um action_id ou intent separado do label; o onAction envia { intent: "buy_ticket", params: {...} }. O backend já deve saber disso — só que o front está tratando como chat em texto puro.
2.6 — Navegação por deep-link inexistente para telas lógicas. Alguns intents só fazem sentido com UI dedicada (escanear QR do ingresso, assinar pagamento biométrico, seleção de assento). Arquitetura chat-first não significa "tudo vira texto"; significa "o chat é o hub". O AdaptiveBlockRenderer precisa de um tipo de bloco route que abra um modal/sheet nativo (ex.: mostrar QR de ingresso em tela cheia). Hoje não tem nada disso — é só renderização declarativa. Quando um agente tenta "implementar o QR code", não existe lugar pra plugar, porque o único contrato é blocks.
2.7 — FlatList do chat sem otimização. Sem inverted, sem maintainVisibleContentPosition, sem onEndReached para paginação. Em conversa longa, scroll fica ruim e scrollToEnd dentro de setTimeout(200) é sintoma, não cura. Padrão canônico em chat RN: inverted={true} e lista reversa.
2.8 — AdaptiveBlockRenderer usa key={i} em todos os sub-arrays. Blocks de cima (BlockRouter) usam block.id || b-${i}, ok. Mas dentro de cada tipo de bloco (KPI, ações, timeline, lineup, markers, sectors, stages) todos os subitens usam índice. Se o backend reordenar items de uma resposta (p.ex. lineup atualizado), React reutiliza componentes errados, imagens piscam, estado interno (se houver) vaza entre items. Usar um id estável do backend ou compor ${block.id}-${item.id || item.name}.
3. Problemas de plataforma que restam
3.1 — EXPO_PUBLIC_API_URL fixada em IP de LAN no app.json. http://192.168.1.42:8080 entra no bundle de produção. Fora da rede do dev, o app não fala com backend nenhum. Precisa ser dinâmico por ambiente — em Expo isso é feito com app.config.ts lendo process.env ou via EAS build profiles (dev/preview/production).
3.2 — HTTP em claro. Fluxo inteiro é http://. iOS bloqueia por ATS por padrão; Android bloqueia cleartext desde API 28. Em produção, obrigatoriamente https://.
3.3 — Fontes do design system nunca carregadas. theme.ts tem cada typography com // TODO: load via expo-font e expo-font está em dependencies + plugin em app.json, mas nenhum useFonts() no App.tsx. Resultado: o visual "Aether Neon" especificado em Space Grotesk/Manrope sai em San Francisco no iOS e Roboto no Android. Precisa de um useFonts no App com splash controlado, e remover os comentários TODO depois.
3.4 — GlassCard degrada feio no Android. BlurView não funciona; fallback é um box com rgba(48,26,77,0.55), sem blur. Design não é honrado em metade do parque. Ou aceitar visualmente (e documentar), ou usar @react-native-community/blur que cobre Android via plugin nativo.
3.5 — console.log('[API] Base URL:', BASE_URL) fora de dev. No client.ts linha 11. Vai para logs de produção. Envolver em if (__DEV__).
3.6 — Sem telemetria, sem Sentry/Crashlytics. Se o app crasha no bolso do participante num festival, você só sabe pela review de 1 estrela. Adicionar @sentry/react-native (ou equivalente) é trivial e paga sozinho.
3.7 — Sem react-query/SWR/cache. O único request "pesado" é o welcome, então talvez não urja, mas se futuramente aparecer outro tipo de bloco que chame dados, já tem biblioteca? Não tem. Hoje usa só axios cru. Ok para agora, mas decidir antes que vire N padrões.
3.8 — react@19.1.0 + react-native@0.81.5. Versões muito na fronteira. Se estiverem com agentes via LLM, os modelos muitas vezes alucinam APIs antigas (hooks de react@18, por exemplo). Pinar e documentar que é RN 0.81/React 19 no README.md ajuda o próprio agente.
3.9 — Sem README.md, sem AGENTS.md, sem CONTRIBUTING.md. Isso é parte grande do "agentes não dão conta". Um agente aberto nesse repo não tem como saber que é chat-first, que as outras telas são legado. Precisa de um AGENTS.md na raiz dizendo em 10 linhas: "este app é chat-first; a única tela pós-login é ImmersiveChatScreen; blocos adaptativos vêm de /ai/chat; não adicione telas, adicione tipos de bloco em AdaptiveBlockRenderer."
3.10 — tsc --noEmit existe, mas strict: true no tsconfig + any abundantes no AdaptiveBlockRenderer. Todo block: any, onAction: (a: any). Você perdeu a força do TS justo na interface contratual mais importante (forma do bloco). Tipar Block = InsightBlock | TextBlock | CardGridBlock | ... como discriminated union travada em type, com schema definido. Aí quando o backend muda o contrato, o front erra em tempo de build, não em runtime no festival.
3.11 — Sem CI, sem testes. Não há diretório __tests__ nem config jest/vitest. Mesmo sem cobertura completa, um teste de snapshot do AdaptiveBlockRenderer por tipo de bloco (12 tipos) captura regressão em minutos.
3.12 — expo-notifications instalado mas nunca registrado. Sem Notifications.requestPermissionsAsync, sem getExpoPushTokenAsync, sem handler. Ornamento na package.json.
4. Higiene organizacional
4.1 — expo ~54.0.0 com React 19.1: verificar compatibilidade oficial. Expo SDK 54 é a linha mais recente; confirmar no release notes se RN 0.81.5 + React 19.1 é exatamente o combo suportado ou se o mínimo é outro. Mismatches aqui geram bugs opacos (ex.: hooks que só funcionam parcialmente).
4.2 — babel-plugin-module-resolver para @/, mas o código importa por caminho relativo. src/screens/ImmersiveChatScreen.tsx usa '../lib/theme', '../components/AdaptiveBlockRenderer'. O alias existe mas não é usado. Ou adotar @/lib/theme em tudo, ou remover o plugin. Duas formas de importar confundem agente (e humano).
4.3 — Muitos arquivos de tamanho grande (ImmersiveChatScreen 413 linhas; AdaptiveBlockRenderer 422). AdaptiveBlockRenderer especialmente devia virar uma pasta src/components/blocks/ com um arquivo por tipo de bloco + um index.tsx com o roteador. Cada novo tipo de bloco que seus agentes forem adicionar vira blocks/QRCode.tsx, blocks/Payment.tsx — isolado, testável, a mudança não toca 400 linhas.
5. Priorização — por onde começar segunda-feira
Em ordem de impacto por esforço, o que mais vai destravar os agentes:

Apagar código morto (30 min, já listado em §1) + escrever um AGENTS.md de 15 linhas explicando a arquitetura. Isso sozinho resolve metade do problema de entrega.
Tipar Block como union discriminada e refatorar AdaptiveBlockRenderer em src/components/blocks/. Agora agentes tem um contrato visível e um lugar único e pequeno pra adicionar comportamento.
Fix do eventId hardcoded + do conversation_id ausente. Dois bugs pequenos, alto impacto no produto.
Extrair action intent dos labels (§2.5) — requer coordenação com o backend, mas sem isso o chat fica frágil.
Configurar env dinâmica + HTTPS — bloqueio para ir pra produção.
Carregar fontes + Sentry + logs dev-only — polimento.
Testes do renderer + CI — prevenção de regressão.

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
