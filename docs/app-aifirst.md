APP AI-FIRST
Arquitetura da Experiência Conversacional
EnjoyFun — Abril 2026 | Inspirado no modelo Revolut AIR
_______________
1. O que o Revolut AIR ensina
Em 9 de abril de 2026, o Revolut lançou o AIR (AI by Revolut) para 13 milhões de clientes no Reino Unido. A diretora de IA da empresa resumiu em uma frase que define a era que estamos entrando:
A era de navegar por abas e menus interminaveis acabou.
O AIR não é um chatbot de suporte. É um copiloto financeiro embutido no app que substitui a navegação tradicional por conversa. O usuário pergunta "para onde está indo meu dinheiro?" e recebe análise de gastos. Diz "congela meu cartão" e o cartão congela. Diz "quanto posso economizar este mês?" e recebe um plano. Tudo numa única interface de chat, sem sair da conversa.
O que o AIR faz e o que aprendemos
Capacidade do AIR	Equivalente na EnjoyFun
Insights de gastos por conversa	Insights de vendas, custos, margem por conversa
Congelar cartão perdido via chat	Fechar lote de ingressos, pausar PDV via chat
Rastrear investimentos	Rastrear logística de artistas, status de fornecedores
Gerenciar assinaturas	Gerenciar equipe, turnos, credenciais
Planejar viagem e orçamento	Planejar evento completo conversando com a IA
Zero data retention com providers	Zero data retention + PII scrubbing + aprovação humana
Swipe down para ativar	Chat como tela principal do app (não precisa swipe)

Onde a EnjoyFun vai além do AIR
O Revolut AIR é conversacional: o usuário pergunta e recebe texto. A EnjoyFun é conversacional e adaptativa: o usuário pergunta e recebe a interface certa para aquele momento. Gráficos, mapas, plantas 3D, timelines, formulários, imagens do local, line-up interativo. A IA não devolve texto — ela se transforma no que o usuário precisa ver.
Revolut AIR = conversação. EnjoyFun = conversação + interface adaptativa + múltiplos tipos de usuário (organizador, público, staff, artista). Isso não existe em nenhuma plataforma de eventos do mundo.
2. Princípios de design do app
Princípio	Regra	Inspiração
Chat é a home	O app abre direto no chat. Sem menu, sem dashboard estático.	Revolut AIR
Resposta adaptativa	A IA retorna blocos de UI, não texto puro	Generative UI (2026)
Glassmorphism dark	Base escura (#0A0A0A a #1A1A2E) com painéis translucidos	Tendência AI-First 2026
Voz como first-class	Botão de microfone persistente, não escondido	65% crescimento voice YoY
Modo dual (mobile)	IA-only no app. Desktop: dual (clássico + conversacional)	Adoção progressiva
Humano decide	Toda ação de escrita exige confirmação biométrica ou tap	Revolut biometric
Transparência total	Usuário sabe quando é IA, o que ela acessa, por que sugere	Ética AI 2026
Offline-first	Info do evento cacheada. Chat funciona online.	Evento = campo

3. Adaptive UI Engine — como a IA renderiza
A IA não retorna texto. Ela retorna um JSON de blocos adaptativos que o frontend renderiza como componentes React nativos. Cada bloco tem tipo, dados e ações contextuais.
3.1 Os 12 tipos de bloco
Tipo	Renderiza como	Quando usar	Exemplo
chart	Gráfico Recharts (bar/line/pie)	Dados numéricos	Vendas por dia, ocupação, custos
insight	Card com destaque + ícone	Análise textual	"Margem líquida estimada: 18%"
table	Tabela responsiva scrollável	Dados comparativos	Artistas com status, fornecedores
image	Imagem fullwidth com caption	Conteúdo visual	Fotos do venue, banner gerado
image_3d	Viewer 3D interativo (Three.js)	Planta do local	Mapa 3D do venue com zoom/rotate
form	Formulário inline com validação	Ação necessária	Criar evento, adicionar artista
actions	Botões de ação contextual	Próximos passos	Criar campanha / Ver detalhes
card_grid	Grid de KPI cards	Visão geral	Ingressos vendidos, receita, margem
timeline	Timeline vertical interativa	Sequência temporal	Agenda de shows, schedule do dia
map	Mapa interativo (Mapbox/Leaflet)	Dados espaciais	Venue, estacionamento, palcos
lineup	Carousel de artistas com fotos	Line-up	Artistas por palco e horário
audio	Player de áudio inline	Resumo falado	Briefing de 2min do dia (futuro)

3.2 Exemplo de resposta adaptativa
Usuário pergunta: "Como estão as vendas do meu evento?"
A IA retorna 3 blocos: (1) chart com vendas por dia, (2) insight dizendo "342 de 500 ingressos vendidos, 68%. No ritmo atual esgota em 2 dias.", (3) actions com botões "Criar campanha de urgência" e "Ver detalhes por lote". Tudo renderizado como componentes nativos, não como texto.
3.3 Como funciona tecnicamente
Etapa	Componente	Tecnologia
1. Usuário envia mensagem	ChatScreen (Expo RN)	React Native + WebSocket
2. Intent classification	IntentRouterService.php	Classifica intenção → agente
3. Agente executa	AIOrchestratorService.php	Multi-provider (OpenAI/Gemini/Claude)
4. Tools coletam dados	AIToolRuntimeService.php	25 read tools automáticas
5. Resposta estruturada	AdaptiveResponseService.php	Converte para blocos JSON
6. Renderização	AdaptiveUIRenderer.jsx	Recharts, Three.js, Mapbox, etc
7. Memória gravada	AIMemoryBridgeService.php	MemPalace (fire-and-forget)

4. Jornada por tipo de usuário
4.1 Organizador / Produtor
Abre o app → chat com IA. A IA já sabe qual evento está ativo (contexto por evento selecionado). O organizador pergunta qualquer coisa e recebe a interface adaptativa certa. Não precisa saber onde fica cada funcionalidade. Não precisa decorar menus. A IA é o menu.
O que pergunta	O que recebe
"Como estão as vendas?"	chart + insight + actions (criar campanha)
"Cria uma campanha de urgência"	Texto pronto + preview banner + botões enviar
"Analisa o rider do DJ X"	Upload → Gemini 1M lê inteiro → tabela de necessidades
"Quanto gastei vs orçamento?"	chart comparativo + insight + table de desvios
"Artista Y tem hotel?"	card do artista + status logística + alerta se pendente
"Relatório final do evento"	Relatório multi-agente com seções + export PDF
"Cria um evento novo"	form inline guiado pela IA, campo a campo
"Mostra a planta do venue"	image_3d interativo com palcos, bares, banheiros

4.2 Público / Participante
A experiência mais inovadora. O fã abre o app, vê seu evento em destaque, e entra numa tela imersiva. Nada estático. Tudo é conversacional e visual.
O que pergunta	O que recebe
"Que horas toca o DJ X?"	card do artista + horário + palco + mapa de localização
"Me mostra o local"	image (fotos reais) + image_3d (planta interativa)
"Onde fica o banheiro?"	map interativo com facilidades marcadas
"Qual o line-up?"	lineup carousel por palco com horários
"Vai chover?"	insight com previsão do tempo + dica
"Quero comprar ingresso"	card_grid com lotes disponíveis + botão comprar
"Tem estacionamento?"	map + vagas disponíveis + preços
(tela inicial)	Hero com foto do evento + countdown + actions rápidas

4.3 Staff / Equipe
O que pergunta	O que recebe
"Quantos ingressos validei?"	card_grid com métricas do turno
"Estoque do bar?"	table de itens + alertas críticos
"Qual meu próximo turno?"	timeline do dia com seus horários
"Onde servir refeição?"	map com pontos de refeição + horários

5. Stack tecnológico
5.1 App Mobile
Componente	Tecnologia	Razão
Framework	Expo React Native (SDK 52+)	Crossplatform, OTA updates, TestFlight + APK
Chat UI	Custom ChatScreen + FlatList	Performance em listas longas de mensagens
Gráficos	react-native-chart-kit ou Victory	Leve, nativo, customizável
3D Viewer	expo-three + Three.js	Plantas 3D interativas no mobile
Mapas	react-native-maps + Mapbox	Venue, estacionamento, facilidades
Voz	expo-speech + Whisper API	Speech-to-text / text-to-speech
Offline	expo-sqlite + AsyncStorage	Cache de info do evento para campo
Push	expo-notifications + FCM/APNs	Alertas proativos da IA
Biometria	expo-local-authentication	Aprovação de ações de escrita
Streaming	WebSocket / SSE	First token < 2s, streaming da resposta

5.2 Backend (já existente + novos services)
Service	Função	Estado
AIOrchestratorService.php	Orquestra agentes, bounded loop, multi-provider	Produção (2.012 linhas)
AIToolRuntimeService.php	Executa 33+ tools com aprovação	Produção (2.324 linhas)
AIContextBuilderService.php	Contexto rico por superfície	Produção (~3.000 linhas)
IntentRouterService.php	Classifica intenção → agente correto	Novo
AdaptiveResponseService.php	Converte resposta em blocos adaptativos	Novo
ConversationSessionService.php	Threads persistentes com memória	Novo
AIMemoryBridgeService.php	Bridge para MemPalace (memória entre eventos)	Novo
GeminiLongContextService.php	Documentos inteiros via Gemini 1M	Novo

5.3 Design System AI-First
Elemento	Especificação
Base	Dark mode (#0A0A0A). Claro como opção.
Painéis de IA	Glassmorphism: fundo translucido com blur, bordas sutis
Mensagens do usuário	Bolhas alinhadas à direita, cor accent (#E94560)
Respostas da IA	Blocos adaptativos alinhados à esquerda, sem bolha
Loading	Skeleton screens com shimmer. Nunca spinner genérico.
Streaming	Texto aparece word-by-word. Blocos aparecem com fade-in.
Tipografia	Inter ou SF Pro. 16px base. Hierarquia clara.
Animações	Micro-animações em ações (tap, expand, transition). 200ms max.
Acessibilidade	WCAG 2.2 AA. Contraste mínimo 4.5:1. VoiceOver compatível.

6. Segurança e privacidade
Seguimos os mesmos princípios do Revolut AIR, adaptados para eventos:
Controle	Implementação
Zero data retention	Providers (OpenAI/Gemini/Claude) não armazenam dados. PII scrubbing antes de enviar.
Acesso mínimo	IA só acessa dados que o usuário já pode ver no app.
Biometria para ações	Qualquer ação de escrita exige FaceID/TouchID ou confirmação.
Isolamento de sessão	Cada conversa pertence a um organizer+event. Nunca cruza.
Auditoria completa	Toda interação grava: prompt, resposta, tools usadas, tokens, latência.
Rate limiting	Caps por sessão e por organizador. Migrar para Redis.
Feature flags	Cada tipo de bloco, cada agente, pode ser desligado sem deploy.
Participante isolado	Público NUNCA vê dados do organizador. RBAC no IntentRouter.

7. Por que isso é um diferencial imenso
7.1 O que existe hoje no mercado de eventos
Plataforma	Interface	IA
Sympla	Menus tradicionais, páginas estáticas	Zero
Eventbrite	Dashboard clássico, relatórios manuais	Mínimo (recomendações)
Ingresse	Bilheteria online, admin básico	Zero
Shotgun	App p/ público, estático	Zero
EnjoyFun	IA como interface, 12 agentes, resposta adaptativa	Motor completo (11K+ linhas)

7.2 O moat
Nenhum concorrente pode copiar isso rapidamente. Não é só adicionar um chatbot — é uma arquitetura inteira: 12 agentes treinados por domínio, 33+ ferramentas que acessam dados reais, motor de 11K linhas, memória entre eventos, documentos inteiros via Gemini 1M, Adaptive UI Engine com 12 tipos de bloco, e tudo rodando com segurança de produção (aprovação humana, RLS, auditoria). Construir isso do zero leva 12-18 meses. Nós já temos.
7.3 A experiência do público como killer feature
O organizador escolhe a EnjoyFun pela plataforma. Mas o público escolhe pelo app. Quando o fã abre o app e conversa com a IA que mostra fotos do local, planta 3D, line-up interativo, mapa de facilidades, previsão do tempo, e permite comprar ingresso sem sair da conversa — ele nunca mais vai querer voltar para um site estático com PDF do mapa. Essa experiência gera viralidade. O público compartilha. O organizador vê o diferencial. O efeito de rede começa.

8. Roadmap do app
Sprint	Entregas do app
Sprint 2 (16-20 Abr)	ChatScreen, AdaptiveUIRenderer (chart, insight, table, actions, card_grid), API de chat
Sprint 3 (21-25 Abr)	Tela do participante (lineup, map, image), push notifications, offline cache
Sprint 4 (26-29 Abr)	Build produção (TestFlight + APK), PWA, onboarding flow, QA em 3+ devices
Pós D-Day	image_3d (Three.js), audio (TTS briefing), form inline, voz (Whisper), dark/light toggle

_______________
A era de navegar por menus acabou. No mercado de eventos, a EnjoyFun é a primeira a entender isso.
EnjoyFun — App AI-First | Abril 2026
