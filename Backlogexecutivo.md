ENJOYFUN
Auditoria Consolidada & Arquitetura Definitiva
Sistema de IA Multiagentes

Data: 11 de Abril de 2026
Versão: 1.0 — Consolidação de 3 auditorias independentes
Classificação: Interno / Estratégico



1. Sumário Executivo
Este documento consolida três auditorias independentes realizadas no sistema de IA multiagentes da EnjoyFun, unificando diagnósticos, eliminando redundâncias e convergindo para um plano de ação definitivo.
1.1 Veredicto Unificado
A plataforma possui uma fundação técnica sólida (11K+ linhas de backend, 12 agentes, 33+ tools, bounded loop, multi-provider, MCP, memória, auditoria) mas falha em entregar valor real ao usuário por 8 defeitos estruturais + 4 falhas de arquitetura que transformam o hub multiagente em um chatbot genérico.
1.2 Scorecard Consolidado
Dimensão	Nota	Estado
Hub multiagente dinâmico	8/10	Estrutura DB-driven entregue, falta observabilidade
Experiência do usuário (UX IA)	4/10	Bot global contradiz ADR; agentes invisíveis ao usuário
Memória (Mem Palace)	6/10	Persistência boa, sem semântica vetorial
RAG / Busca Documental	5/10	Resumos de 200 chars, sem retrieval real
Governança de migrations	7/10	Boa, com dívida no topo (059→068)
Segurança multi-tenant	8/10	RLS forte, gaps em ai_memories e ai_reports
Qualidade de resposta IA	4/10	Eager loading + sem tool_choice = respostas genéricas
Skills operacionais	3/10	Skills do dev toolkit, não da plataforma


2. Mapa Completo de Defeitos (Consolidação das 3 Auditorias)
As 3 auditorias identificaram defeitos que se sobrepõem. Abaixo está a lista unificada e deduplicada, classificada por severidade.
2.1 Defeitos Críticos (Bloqueiam valor)
D1 — Bot Flutuante Global (Modelo Errado)
•	Origem: Auditorias 1 e 2
•	Sintoma: UnifiedAIChat.jsx é position:fixed global. Usuário não sabe qual agente responde. Contradiz a ADR que exige bots embedded por superfície.
•	Causa raiz: O componente não injeta contexto da página. Surface inferido por pathname sem dados específicos da tela.
•	Impacto: Destrói o diferencial competitivo. IA parece genérica.
•	Solução: Criar <EmbeddedAIChat surface={...} contextData={...} /> inline em cada página de domínio. Downgrade do bot global para FAQ.
D2 — Eager Loading Brutal no Contexto
•	Origem: Auditoria 2
•	Sintoma: AIContextBuilderService.php tem 2.600+ linhas despejando TODOS os dados (meals, parking, timeline, arquivos) no prompt inicial.
•	Causa raiz: Filosofia de contexto eager em vez de lazy tool-calling.
•	Impacto: Explode janela de contexto, custo 3-5x maior por mensagem, latência alta, LLM cego por excesso de informação.
•	Solução: Reduzir contexto inicial a DNA do negócio + metadados. Agentes usam tools sob demanda (Lazy Context). Reduz custo em ~60%.
D3 — Intent Router Travado
•	Origem: Auditoria 1
•	Sintoma: Usuário fica preso no agente inicial. confidence: 1.0 hardcoded em AIController.php:361.
•	Solução: agent_key como hint (+5 score), não lock. Reavaliação por mensagem.
D4 — Sessão Vaza Entre Páginas
•	Origem: Auditoria 1
•	Sintoma: session_id global por organizer, sem escopo por (organizer, surface, event_id).
•	Solução: Session key composta: {organizer_id}:{surface}:{event_id}. Auto-archive ao trocar surface.
D5 — File Hub Primitivo (Falsa RAG)
•	Origem: Auditorias 1, 2 e 3
•	Sintoma: loadOrganizerFilesSummary pega últimos 5-10 arquivos, trunca em 200 chars. Sem busca semântica, sem leitura real.
•	Solução: Curto prazo: tool read_organizer_file(file_id) + search_documents(category, keyword). Longo prazo: pgvector + embeddings + retrieval híbrido.
D6 — Respostas Genéricas (LLM Ignora Tools)
•	Origem: Auditorias 1 e 2
•	Sintoma: Prompt enorme com persona + DNA + contrato, mas pergunta chega como texto puro. LLM responde sem chamar tools.
•	Solução: tool_choice: required na primeira mensagem. Temperature 0.4→0.25. Prompt: SEMPRE use ferramentas antes de responder.
D7 — API Keys Expostas
•	Origem: Auditorias 1 e 3
•	Sintoma: OPENAI_API_KEY e GEMINI_API_KEY em plaintext no .env, possivelmente no git history.
•	Solução: Rotacionar keys imediatamente. Re-encriptar organizer_ai_providers com pgcrypto.
2.2 Defeitos Moderados
D8 — Labels em Inglês nos Cards
•	Sintoma: Revenue, Transactions, Tickets Sold em inglês. prettyLabel() faz apenas ucfirst.
•	Solução: Dicionário PT-BR com 60+ termos + fallback no prompt forçando português.
D9 — Componentes Legados V1
•	Sintoma: ParkingAIAssistant.jsx, ArtistAIAssistant.jsx, WorkforceAIAssistant.jsx coexistindo com V2.
•	Solução: Deletar. Substituídos por EmbeddedAIChat.
D10 — Catálogo Hardcoded vs DB
•	Origem: Auditorias 2 e 3
•	Sintoma: allToolDefinitions() e agentCatalog() em arrays PHP coexistem com ai_agent_registry/ai_skill_registry no banco. Risco de divergência silenciosa.
•	Solução: DB como fonte de verdade. Runtime faz merge com fallback canônico. Versionamento semântico por skill.
D11 — Governança de Migrations Desalinhada
•	Origem: Auditoria 3
•	Sintoma: drift_replay_manifest.json fecha em 059, repo em 068. migrations_applied.log desatualizado.
•	Solução: Atualizar manifest e log para 068. Rodar replay completo com psql.
D12 — RLS Incompleto em Tabelas de IA
•	Origem: Auditoria 3
•	Sintoma: ai_agent_memories e ai_event_reports sem RLS formal (apenas filtros lógicos + trigger guard).
•	Solução: Aplicar RLS equivalente ao das tabelas de conversa (migration 064).


3. Arquitetura Definitiva — EMAS (Embedded Multi-Agent System)
Convergência das 3 propostas de arquitetura em um modelo único e coerente com a ADR oficial.
3.1 Princípio Central
Desistimos do Super Bot Deus que sabe de tudo. Passamos a criar Micro-Agentes Especialistas que só conhecem a própria jurisdição, ativados por superfície, com contexto lazy e tools sob demanda.
3.2 As 4 Camadas da Arquitetura EMAS
Camada A — Embedded Agents (Frontend)
•	Cada página de domínio instancia <EmbeddedAIChat surface={...} contextData={...} />
•	Sessão isolada por {organizer}:{surface}:{event_id}
•	Suggestion pills contextuais por superfície
•	O usuário vê UM assistente por tela; o runtime resolve o agente especialista
Página	Surface	Agente Default	Context Builder
Bar/Food/Shop	bar	bar	Produtos, estoque, vendas do dia
Parking	parking	logistics	Veículos, fluxo, capacidade
Artists	artists	artists	Timeline, riders, logística
Workforce	workforce	logistics	Equipes, turnos, cobertura
Finance	finance	contracting	Orçamento, pagamentos, fornecedores
OrganizerFiles	documents	documents	Arquivos parseados com conteúdo
Dashboard	dashboard	management	KPIs gerais do evento
Tickets	tickets	marketing	Vendas, lotes, demanda
Camada B — Lazy Context & Tool-First (Backend)
•	AIContextBuilderService refatorado: apenas DNA do negócio + metadados do evento
•	100% do poder do agente vem das Skills/Tools (AIToolRuntimeService)
•	Bounded Loop V2 sempre ativo com tool_choice: required na primeira mensagem
•	Agente faz call real (ex: get_parking_live_snapshot) — usuário vê a IA 'pensando'
Camada C — Semantic RAG (File Hub Real)
•	Curto prazo: tools read_organizer_document(file_id) e search_documents(category, keyword)
•	Longo prazo: pgvector + embeddings por tenant com chunking semântico
•	Retrieval híbrido: BM25 + vetor + rerank
•	Documento íntegro canônico para auditoria + chunks semânticos para busca
Camada D — Supervisor Route (Global / WhatsApp)
•	Quando não há página específica (Hub Central, WhatsApp), Supervisor Agent classifica e delega
•	Tool delegate_to_expert(agent_id, context) substitui o keyword-matching frágil
•	Bot flutuante global downgraded para FAQ e navegação assistida


4. Banco de Dados — Evolução Necessária
4.1 Migrations Pendentes
Migration	Propósito	Prioridade
069_rls_ai_memory_reports.sql	RLS para ai_agent_memories e ai_event_reports	CRÍTICA
070_ai_session_composite_key.sql	Session key composta (org:surface:event) + índice	CRÍTICA
071_ai_skill_versioning.sql	Adicionar version, deprecated_at, successor_key em ai_skills	ALTA
072_ai_document_embeddings.sql	pgvector + tabela de embeddings por documento/tenant	ALTA
073_ai_label_translations.sql	Tabela de traduções PT-BR para labels adaptativos	MÉDIA
074_manifest_sync_068.sql	Atualizar drift_replay_manifest para 068	CRÍTICA
4.2 Schema pgvector para RAG
A extensão pgvector deve ser habilitada no PostgreSQL existente. Tabela proposta:
•	document_embeddings: id, organizer_id, event_id, file_id, chunk_index, chunk_text, embedding VECTOR(1536), metadata JSONB, created_at
•	Índice: ivfflat com cosine distance, particionado por organizer_id
•	Pipeline: upload → parser → chunking semântico → embedding (OpenAI text-embedding-3-small) → INSERT


5. Skills a Construir
Lista completa de skills que devem ser implementadas no Skills Warehouse da plataforma, organizadas por prioridade.
5.1 Skills Prioritárias (Sprint 1-2)
Skill	Tipo	Agentes	Descrição
read_organizer_file	read	documents, all	Lê parsed_data completo de um arquivo por file_id
search_documents	read	documents, all	Busca arquivos por categoria, keyword, nome
get_pos_sales_snapshot	read	bar	Vendas do dia por produto, setor, hora
get_stock_critical_items	read	bar	Itens com estoque abaixo do mínimo
get_parking_live_snapshot	read	logistics	Fluxo atual de veículos, capacidade, taxa
get_event_kpi_dashboard	read	management	KPIs consolidados do evento
get_finance_summary	read	contracting	Orçamento vs realizado, inadimplência
get_event_shift_coverage	read	logistics	Cobertura de turnos por equipe
get_artist_contract_status	read	artists, contracting	Status de contratos por artista
get_ticket_demand_signals	read	marketing	Sinais de demanda por lote/canal
5.2 Skills de Segunda Onda (Sprint 3-4)
Skill	Tipo	Agentes	Descrição
ticket_pricing_optimizer	generate	marketing	Sugestão de precificação por lote baseada em histórico
demand_forecast	generate	data_analyst	Previsão de demanda com dados + memória
rider_parser	read	artists	Parsear rider de artista via Gemini 1M context
budget_analyzer	read	contracting, management	Análise orçamento vs gasto real detalhado
post_event_report	generate	management	Relatório completo pós-evento multi-agente
workforce_planner	generate	logistics	Planejamento automático de equipe por área
campaign_builder	generate	content, marketing	Criar copy + cronograma de campanha
delegate_to_expert	system	supervisor	Roteamento inteligente do Supervisor Agent
semantic_search_docs	read	documents	Busca vetorial por similaridade (pgvector)
cross_event_analytics	read	data_analyst	Padrões entre eventos do mesmo organizador
5.3 Skills de Escrita (com Aprovação)
Skill	Tipo	Gate	Descrição
create_budget_line	write	confirm_write	Criar linha de orçamento com aprovação
import_payables_csv	write	confirm_write	Importar contas a pagar de CSV
send_campaign_message	write	confirm_write	Disparar campanha via Resend/Z-API
update_stock_quantity	write	confirm_write	Ajustar estoque com confirmação
create_task_assignment	write	confirm_write	Atribuir tarefa de workforce


6. Ferramentas, MCPs e Integrações Recomendadas
6.1 MCP Servers a Integrar
MCP Server	Propósito	Prioridade
MemPalace Sidecar	Memória semântica: diary, KG, search vetorial	CRÍTICA
Google Calendar MCP	Agenda de artistas, prazos, timeline do evento	ALTA
Gmail MCP	Envio/leitura de emails de fornecedores e artistas	MÉDIA
Slack/Discord MCP	Alertas operacionais para equipe durante evento	MÉDIA
Asaas MCP (financeiro)	Consulta de cobranças, pagamentos, inadimplência	ALTA
WhatsApp (Z-API/Evolution)	Concierge do público + vendas conversacionais	ALTA
6.2 Ferramentas de Infraestrutura
Ferramenta	Uso	Justificativa
pgvector (extensão PG)	Banco vetorial para RAG	Evita serviço externo (Pinecone/Weaviate). Tudo no mesmo PG.
Redis	Cache de contexto + fila de tool calls	Reduz latência de context building. Pub/sub para streaming SSE.
Gemini 1M Context	Documentos grandes sem chunking	Riders, contratos longos, planilhas inteiras via GeminiLongContextService
OpenAI text-embedding-3-small	Embeddings para RAG	Melhor custo-benefício para embeddings. 1536 dims.
SSE (Server-Sent Events)	Streaming word-by-word no chat	UX premium de digitação em tempo real
Docker Compose	MemPalace sidecar + Redis	Infraestrutura local e produção consistentes
6.3 Stack de Observabilidade para IA
•	SLIs por agente/skill: latência, erro, fallback rate, tool timeout
•	Alarmes: memory_persist_failed_rate, fallback_to_hardcoded_rate, tool_execution_partial_rate
•	Dashboard operacional: agentes ativos, memória acumulada, skills carregadas, custo por mensagem
•	Scorecard de qualidade: assertividade percebida, taxa de fallback humano, NPS por agente


7. Backlog Executivo — Sprints com Agentes Paralelos
Cada sprint é desenhado para que as trilhas rodem em paralelo sem conflito de merge, com ownership claro por arquivo/módulo.
Sprint 1 — Fundação EMAS (5 dias)
Objetivo: Criar a infraestrutura base da nova arquitetura sem quebrar nada do que já funciona.
Trilha A: Frontend (sem conflito com B)
Ticket	Arquivo	Descrição	Aceite
FE-01	EmbeddedAIChat.jsx (NOVO)	Componente reutilizável com surface, contextData, agentKey	Renderiza chat inline com sessão isolada
FE-02	AdaptiveUIRenderer.jsx	Integrar com EmbeddedAIChat	Cards e gráficos renderizam dentro do embed
FE-03	Dashboard.jsx	Primeiro embed: surface=dashboard	Chat funcional com KPIs no contexto
FE-04	OrganizerFiles.jsx	Segundo embed: surface=documents	Chat lê e responde sobre arquivos
FE-05	Deletar 3 componentes V1	ParkingAI, ArtistAI, WorkforceAI	npm run build sem erros
Trilha B: Backend (sem conflito com A)
Ticket	Arquivo	Descrição	Aceite
BE-01	AIConversationService.php	findOrCreateSession com key composta	Sessões isoladas por org:surface:event
BE-02	AIController.php	Remover short-circuit L361. agent_key como hint	IntentRouter roda sempre
BE-03	AIIntentRouterService.php	agent_key como bonus +5, não bypass	Agente muda se intenção muda
BE-04	AdaptiveResponseService.php	Dicionário PT-BR 60+ termos	Labels em português em todas as respostas
BE-05	AIOrchestratorService.php	tool_choice:required na 1ª msg. Temp 0.25	IA usa tools antes de responder
Trilha C: Banco de Dados (sem conflito com A/B)
Ticket	Arquivo	Descrição	Aceite
DB-01	069_rls_ai_memory.sql	RLS em ai_agent_memories e ai_event_reports	SELECT com tenant 999 retorna 0 rows
DB-02	070_session_composite.sql	Session key composta + índice	findOrCreateSession usa índice
DB-03	074_manifest_sync.sql	Sync manifest/log para 068	CI check_database_governance PASS
Sprint 2 — Contexto Lazy + File Hub (5 dias)
Objetivo: Substituir eager loading por tool-calling sob demanda. Criar RAG operacional.
Trilha A: Context Refactor (Backend)
Ticket	Arquivo	Descrição	Aceite
CTX-01	AIContextBuilderService.php	Reduzir de 2600 para ~500 linhas. Apenas DNA + metadados	Prompt inicial < 2000 tokens
CTX-02	AIToolRuntimeService.php	Criar read_organizer_file tool	Agente lê arquivo completo sob demanda
CTX-03	AIToolRuntimeService.php	Criar search_documents tool	Agente busca por categoria/keyword
CTX-04	AIPromptCatalogService.php	Prompt: SEMPRE responda em PT-BR. SEMPRE use tools.	Verificação manual em 5 surfaces
Trilha B: Mais Embeds (Frontend)
Ticket	Arquivo	Descrição	Aceite
EMB-01	BarPage.jsx	Embed surface=bar com vendas e estoque	Chat responde sobre produtos
EMB-02	ArtistsPage.jsx	Embed surface=artists com timeline	Chat responde sobre artistas
EMB-03	WorkforcePage.jsx	Embed surface=workforce com turnos	Chat responde sobre equipes
EMB-04	ParkingPage.jsx	Embed surface=parking com fluxo	Chat responde sobre veículos
Trilha C: Segurança
Ticket	Arquivo	Descrição	Aceite
SEC-01	.env + consoles	Rotacionar OPENAI_API_KEY e GEMINI_API_KEY	Keys antigas revogadas nos consoles
SEC-02	FEATURE_AI_TOOL_WRITE	Smoke test do workflow de aprovação write	Tool write pede confirmação na UI
SEC-03	organizer_ai_providers	Re-encriptar com pgcrypto key válida	Sem warning de descriptografia
Sprint 3 — RAG Semântico + MemPalace (5 dias)
Objetivo: Elevar a IA de 'chatbot que consulta SQL' para 'agente com memória semântica e busca vetorial'.
Trilha A: pgvector + Embeddings
Ticket	Arquivo	Descrição	Aceite
RAG-01	072_ai_embeddings.sql	Criar tabela document_embeddings com pgvector	CREATE EXTENSION vector OK
RAG-02	AIEmbeddingService.php (NOVO)	Pipeline: parse → chunk semântico → embedding → INSERT	Arquivo uploaded gera embeddings
RAG-03	AIToolRuntimeService.php	Tool semantic_search_docs via cosine similarity	Busca retorna trechos relevantes
RAG-04	AIContextBuilderService.php	Injetar top-5 chunks relevantes no contexto da mensagem	Resposta cita trecho do documento
Trilha B: MemPalace Sidecar
Ticket	Arquivo	Descrição	Aceite
MEM-01	docker/mempalace/	Dockerfile + config com wing enjoyfun_hub e 19 rooms	docker-compose up mempalace OK
MEM-02	AIMemoryBridgeService.php (NOVO)	Bridge PHP → MemPalace via MCP (diary, KG, search)	diary_write + diary_read funcionais
MEM-03	AIOrchestratorService.php	Auto-log pós-execução: diary + KG fact (fire-and-forget)	Cada execução grava memória
MEM-04	AIPromptCatalogService.php	Injetar recall de memória relevante no prompt por agente	Agente lembra contexto cross-sessão
Trilha C: Observabilidade
Ticket	Arquivo	Descrição	Aceite
OBS-01	AIMonitoringService.php (NOVO)	SLIs por agente: latência, erro, fallback, tool timeout	Métricas queryáveis via endpoint
OBS-02	071_ai_skill_versioning.sql	Versioning semântico em ai_skills	Rollout seguro por coorte
OBS-03	Dashboard de saúde IA (frontend)	Painel com agentes ativos, memória, custo, erros	Visível em /ai/health
Sprint 4 — Supervisor + SSE + Polish (5 dias)
Objetivo: Roteamento inteligente global, streaming em tempo real e polish para demo/produção.
Trilha A: Supervisor Agent
Ticket	Arquivo	Descrição	Aceite
SUP-01	AISupervisorService.php (NOVO)	LLM classificador que delega para agente especialista	Intent precisa > 90% em 20 testes
SUP-02	UnifiedAIChat.jsx	Downgrade: apenas FAQ + navegação. Sem dados de negócio.	Perguntas de dados redirecionam ao embed
SUP-03	WhatsApp Concierge	Integrar Supervisor com canal Z-API/Evolution	Mensagem WhatsApp resolve via agente
Trilha B: Streaming SSE
Ticket	Arquivo	Descrição	Aceite
SSE-01	AIStreamingService.php (NOVO)	SSE endpoint para streaming word-by-word	EventSource conecta e recebe tokens
SSE-02	EmbeddedAIChat.jsx	Renderização progressiva via EventSource	Texto aparece token-by-token na UI
SSE-03	Redis pub/sub	Canal por session_id para streaming	Sem polling, push real
Trilha C: Hardening + Demo
Ticket	Arquivo	Descrição	Aceite
HARD-01	Smoke tests E2E	Fluxo completo pré→durante→pós evento com agentes	Zero erros críticos em cenário de demo
HARD-02	Demo flow investidores	5 perguntas que mostram o diferencial competitivo	Roteiro validado com respostas reais
HARD-03	Performance tuning	Latência < 2s para first token	Medido em 10 chamadas sequenciais
HARD-04	Cleanup final	Código morto, imports não usados, console.logs	Build limpo, 0 warnings


8. Mapa de Arquivos por Camada
8.1 Backend (PHP)
Arquivo	Ação	Sprint
AIController.php	MODIFICAR — remover lock de agent, usar findOrCreateSession	S1
AIConversationService.php	MODIFICAR — session key composta	S1
AIIntentRouterService.php	MODIFICAR — agent como hint, não bypass	S1
AdaptiveResponseService.php	MODIFICAR — dicionário PT-BR	S1
AIOrchestratorService.php	MODIFICAR — tool_choice, temperature, auto-log memória	S1+S3
AIContextBuilderService.php	REFATORAR — de 2600 para ~500 linhas (lazy context)	S2
AIToolRuntimeService.php	EXPANDIR — novas tools de leitura e busca	S2
AIPromptCatalogService.php	MODIFICAR — forçar PT-BR, forçar tools	S2
AIEmbeddingService.php	NOVO — pipeline de embeddings para RAG	S3
AIMemoryBridgeService.php	NOVO — bridge PHP→MemPalace via MCP	S3
AIMonitoringService.php	NOVO — SLIs e métricas por agente	S3
AISupervisorService.php	NOVO — roteamento inteligente global	S4
AIStreamingService.php	NOVO — SSE streaming endpoint	S4
8.2 Frontend (React)
Arquivo	Ação	Sprint
EmbeddedAIChat.jsx	NOVO — componente reutilizável de chat embedded	S1
Dashboard.jsx	MODIFICAR — adicionar embed surface=dashboard	S1
OrganizerFiles.jsx	MODIFICAR — adicionar embed surface=documents	S1
BarPage.jsx	MODIFICAR — adicionar embed surface=bar	S2
ArtistsPage.jsx	MODIFICAR — adicionar embed surface=artists	S2
WorkforcePage.jsx	MODIFICAR — adicionar embed surface=workforce	S2
ParkingPage.jsx	MODIFICAR — adicionar embed surface=parking	S2
UnifiedAIChat.jsx	MODIFICAR — downgrade para FAQ	S4
ParkingAIAssistant.jsx	DELETAR	S1
ArtistAIAssistant.jsx	DELETAR	S1
WorkforceAIAssistant.jsx	DELETAR	S1
AIHealthDashboard.jsx	NOVO — painel de saúde da IA	S3
8.3 Banco de Dados
Migration	Tabelas Afetadas	Sprint
069_rls_ai_memory.sql	ai_agent_memories, ai_event_reports	S1
070_session_composite.sql	ai_conversation_sessions	S1
071_ai_skill_versioning.sql	ai_skills	S3
072_ai_embeddings.sql	document_embeddings (NOVA)	S3
073_ai_label_translations.sql	ai_label_translations (NOVA)	S1
074_manifest_sync.sql	drift_replay_manifest.json	S1


9. Decisões Requeridas do Founder
#	Decisão	Opções	Recomendação
1	Bot flutuante global	A) Downgrade FAQ  B) Remover  C) Manter	A — Downgrade para FAQ sem dados
2	Eager→Lazy Context	A) Migrar agora  B) Manter eager	A — Migrar (reduz custo 60%)
3	Streaming SSE	A) Sprint 4  B) Fase posterior	A — Sprint 4 (UX premium)
4	pgvector agora ou tools SQL primeiro?	A) pgvector S3  B) Tools SQL primeiro	B curto prazo → A no Sprint 3
5	MemPalace vs memória relacional	A) MemPalace sidecar  B) Expandir PG	A — MemPalace (semântica > relacional)
6	Ordem dos embeds	A) Dashboard+Files primeiro  B) Bar+Artists	A — Dashboard+Files (maior impacto)


10. Conclusão
A EnjoyFun tem, comprovadamente, um dos sistemas de IA para eventos mais avançados do mercado brasileiro. O diferencial competitivo existe no backend — mas está invisível para o usuário.
Os 4 sprints propostos transformam um monólito conversacional com enumeração em um hub de agentes especialistas embedded, com memória semântica, RAG real e UX premium de IA.
Investimento estimado: 20 dias de trabalho focado. Retorno: diferencial competitivo que nenhuma plataforma de eventos no Brasil oferece.


Documento gerado pela consolidação de 3 auditorias independentes + análise de 80+ arquivos do projeto.


Veredicto técnico

Vocês não estão longe. O sistema já tem peças de valor: catálogo de agentes/skills, orchestrator, bounded loop, tabelas de conversa, base para MCP, estrutura para memória e uma malha backend razoável. O que está quebrando a percepção e a confiabilidade é a combinação de:

chat global flutuante como centro da UX,
roteamento travado no agente inicial,
sessão vazando entre superfícies,
contexto exagerado e mal injetado,
uso fraco de tools,
documents/file hub sem retrieval real,
memória ainda não semântica,
coexistência de legado com V2.

Em outras palavras: a arquitetura prometida é multiagente, mas a execução percebida pelo usuário ainda é de um chatbot generalista com enumeração de agentes.

O desenho certo

A melhor forma de organizar isso, sem perder nada, é consolidar a arquitetura em 6 camadas.

1. Camada de experiência

Cada página de negócio precisa ter seu próprio chat embutido, contextual e isolado. O bot global não pode continuar como peça principal. A própria auditoria já aponta isso com clareza ao propor <EmbeddedAIChat> por surface, mantendo o global apenas como ajuda geral.

Minha recomendação definitiva:

manter o bot global, mas rebaixado para help/navigation
adotar embedded specialist bots em todas as superfícies de negócio
fazer o hub de agentes virar console administrativo e observability center
2. Camada de sessão e contexto

A sessão precisa ser escopada por:
organizer_id + surface + event_id + agent_scope

Hoje o vazamento entre páginas destrói o isolamento cognitivo do sistema. A correção sugerida no documento é certa: chave composta por surface/evento e arquivamento ao trocar de contexto.

Eu acrescentaria mais um nível:

conversation_mode: embedded, global_help, admin_preview, whatsapp, api
context_version: para saber com qual formato de contexto a conversa foi aberta
routing_trace_id: para observabilidade do roteamento
3. Camada de roteamento agentic

O erro mais grave do runtime é o lock em confidence: 1.0 para o agente inicial. Isso mata a agenticidade. O documento está certo ao dizer que agent_key deve ser hint, nunca lock absoluto.

Minha recomendação aqui é sair do modelo “roteador por palavras” para um roteamento em 3 níveis:

Tier 0 — surface default: se está na página Bar, começa em bar_operations
Tier 1 — intent router contextual: reavalia a cada mensagem
Tier 2 — supervisor delegation: quando há ambiguidade real, um supervisor decide transferir ou compor resposta

Isso permite:

sticky agent moderado,
troca de agente quando o assunto muda,
handoff explícito entre especialistas,
prevenção de “routing thrash”.
4. Camada de tools e skills

O documento acerta quando diz que o modelo responde genericamente porque nada o obriga de verdade a usar as ferramentas. Também acerta ao propor tool_choice: required na primeira mensagem e reforço de prompt para uso obrigatório de tools antes da resposta.

Minha posição:

primeira interação da sessão: tools obrigatórias
perguntas factuais/operacionais: tools-first
perguntas estratégicas/opinativas: tools opcionais, mas com verificação
respostas sem tool, quando cabíveis, precisam deixar claro que são inferências
O principal redesenho técnico

A plataforma precisa migrar de Eager Context Monolith para Lazy Context + Tool-grounded Agentic Loop.

Hoje o AIContextBuilderService parece estar tentando despejar o sistema inteiro no prompt. Isso é ruim por custo, latência, ruído e alucinação operacional. A própria auditoria técnica mais profunda aponta isso de forma explícita.

O desenho certo é:

contexto inicial mínimo,
metadados fortes,
recuperação sob demanda via tools,
memória recuperada por relevância,
RAG documental e semântico para arquivos,
adaptive response só depois da evidência.
O que eu adotaria como arquitetura-alvo
Frontend
EmbeddedAIChat por domain page
GlobalHelpChat separado
AgentHubAdmin para gestão
AdaptiveResponseRenderer unificado
SurfaceContextBuilder por página
ConversationStore central com isolamento de sessão
Backend
AIController
SessionManager
IntentRouterV2
SupervisorRouter
AgentResolver
PromptComposer
Orchestrator
ToolRuntime
MemoryRetriever
DocumentRetriever
AdaptiveResponseService
ApprovalWorkflow
Dados
ai_agent_registry
ai_skill_registry
ai_agent_skills
ai_conversation_sessions
ai_conversation_messages
ai_agent_memories
organizer_files
document_embeddings
memory_embeddings
routing_events
tool_execution_logs
agent_handoffs
approval_requests
Problemas e soluções definitivas
1. Bot flutuante global

Solução: manter só como ajuda de plataforma, sem acesso a dados operacionais. Isso está alinhado com a recomendação do documento e é a opção mais segura.

2. Router travado

Solução: remover hard lock, usar agent_key como hint com score bônus, reavaliar toda mensagem, registrar handoff no trace.

3. Vazamento de sessão

Solução: session key composta, auto-archive por mudança de surface, estado isolado no frontend.

4. Labels em inglês

Solução: dicionário de labels PT-BR no backend e, melhor ainda, normalização semântica por chave de domínio. O documento propõe o dicionário de 60+ termos, o que é correto para o curto prazo.

5. File hub fraco

Solução: parar de injetar resuminhos como se isso fosse RAG. Criar search_organizer_files e read_organizer_file reais. O documento já propõe isso e está certo.

6. Respostas genéricas

Solução: tools-first, menor temperatura, prompts mais duros, guardrails de “não inventar”, e ranking das tools por domínio.

7. API keys

Solução: mesmo que não entrem agora, precisam entrar no backlog executivo como bloqueador de escala pública. A auditoria tratou isso como risco crítico corretamente.

8. Legado V1 convivendo com V2

Solução: freeze imediato do legado, compat layer temporária, remoção por sprint controlada.

9. Mem Palace sem semântica real

O documento mais profundo acerta aqui: hoje é memória operacional relacional, não memória semântica enterprise. Precisa de embeddings, retrieval, ranking e política de retenção.

10. Governança de migrations

Também é um achado importante: drift e replay incompletos corroem confiabilidade entre ambientes. Isso tem que entrar cedo no plano.

Skills que vocês devem construir

Aqui está a lista que eu considero essencial para esse ecossistema.

Skills de core orchestration
route_intent
handoff_to_agent
summarize_context
build_surface_context
validate_response_grounding
request_user_approval
execute_write_action
Skills de dados operacionais
get_dashboard_kpis
get_bar_live_snapshot
get_inventory_status
get_low_stock_alerts
get_artist_timeline
get_artist_rider_details
get_workforce_coverage
get_shift_gaps
get_parking_live_snapshot
get_ticket_sales_snapshot
get_finance_summary
get_supplier_status
Skills documentais e RAG
search_organizer_files
read_organizer_file
extract_contract_terms
extract_financial_table
compare_documents
search_semantic_memory
retrieve_document_evidence
cite_sources_for_answer
Skills de memória
store_agent_memory
retrieve_agent_memory
promote_memory_to_long_term
forget_memory_by_policy
link_memory_to_event
summarize_memory_cluster
Skills de segurança e operação
check_feature_flag_state
validate_tenant_scope
audit_tool_execution
log_routing_decision
report_fallback_incident
detect_silent_failure
Skills de frontend/adaptive UI
render_business_cards
translate_metric_labels_ptbr
suggest_next_actions
generate_surface_suggestions
MCPs e integrações que vão ajudar muito
Essenciais
PostgreSQL com pgvector
Redis para cache curto e filas leves
S3 ou storage equivalente para documentos
observabilidade com OpenTelemetry
filas com BullMQ, RabbitMQ ou equivalente
tracing de tool execution
feature flags centralizadas
Muito valiosas
gateway MCP para ferramentas internas
conector de documentos
conector de planilhas
OCR/parse estruturado para PDFs e imagens
serviço de embeddings versionado
reranker semântico
approval workflow service
prompt registry/versioning
Recomendação de stack prática
banco operacional: PostgreSQL
vetor: pgvector no início
cache: Redis
mensageria: Redis Streams ou RabbitMQ
tracing: OpenTelemetry + dashboard
documentos: pipeline com parser + normalização + embeddings
guardrails: policy engine simples por skill/action
Banco de dados: o que eu mudaria

Adicionar tabelas e colunas para:

session_key
conversation_mode
surface
routing_trace_id
agent_handoffs
tool_execution_logs
approval_requests
document_embeddings
memory_embeddings
embedding_version
source_evidence
response_grounding_score

Aplicaria RLS também em memória e relatórios, como a auditoria sugere, para fechar o isolamento.

Backlog executivo com sprints, preparado para agentes paralelos

Vou propor um plano para rodar em paralelo sem conflito, com trilhas independentes.

Sprint 0 — estabilização e governança

Objetivo: parar sangramento.

Trilha A:

corrigir lock do router
corrigir isolamento de sessão
congelar componentes V1
criar logs de roteamento

Trilha B:

atualizar governança de migrations
validar replay
revisar RLS de memória e relatórios

Trilha C:

observabilidade de fallback
métricas de tool usage
alarms de degradação silenciosa

Entregável:
plataforma deixa de se comportar como bot global confuso.

Sprint 1 — embedded architecture foundation

Objetivo: mudar a UX e o modelo mental.

Trilha A:

criar EmbeddedAIChat
implementar stores isoladas por surface
contexto por página

Trilha B:

findOrCreateSession
session key composta
archive por mudança de surface

Trilha C:

downgrade do bot global
mini preview do agente no hub admin

Entregável:
Dashboard e OrganizerFiles com agentes embutidos.

Sprint 2 — tools-first and real retrieval

Objetivo: parar de responder genericamente.

Trilha A:

search_organizer_files
read_organizer_file
retrieval real por arquivos

Trilha B:

forçar tools na primeira mensagem
reduzir temperatura
endurecer prompt

Trilha C:

tradução de labels
adaptive response PT-BR

Entregável:
respostas baseadas em evidência, não em abstrações.

Sprint 3 — semantic memory and vector layer

Objetivo: transformar memória em ativo real.

Trilha A:

document_embeddings
memory_embeddings
pipeline de embeddings

Trilha B:

retrieval híbrido lexical + vetorial
ranking de evidências
memória de curto e longo prazo

Trilha C:

políticas de retenção
versionamento de embeddings
cache de retrieval

Entregável:
Mem Palace funcional de verdade.

Sprint 4 — domain rollout

Objetivo: escalar sem perder controle.

Trilha A:

Bar
Artists
Workforce

Trilha B:

Parking
Tickets
Finance

Trilha C:

QA de prompts, tool schemas e renderer
smoke tests por domain

Entregável:
agentes especialistas em todas as superfícies críticas.

Sprint 5 — write actions, approvals and hardening

Objetivo: entrar no nível enterprise.

Trilha A:

FEATURE_AI_TOOL_WRITE
approval workflow
ações reais com confirmação

Trilha B:

auditoria de execução
rollback seguro
política de permissão por skill

Trilha C:

stress tests
chaos/fallback tests
custo e latência por agente

Entregável:
plataforma pronta para ações operacionais com segurança.

Como rodar com agentes paralelos sem conflito

Dividam em 5 squads ou agentes de execução:

**Agente 1:

O envio da mensagem expirou. Tente novamente.

Repetir
continuar de onde parou por favor
Pensou por dois segundos

Perfeito. Vou continuar a partir do diagnóstico e consolidar tudo em uma análise definitiva, organizada como arquitetura alvo, plano de correção, backlog executivo por sprints paralelos, desenho de skills, ferramentas, MCPs e trilha de execução com baixo conflito. Tudo abaixo está ancorado no documento da auditoria que você anexou.

Leitura executiva

O documento mostra um cenário muito claro: vocês já têm base técnica forte, mas a experiência real ainda se comporta como um chat global genérico, e não como uma plataforma de agentes especialistas embutidos por domínio. Os defeitos centrais identificados são: bot flutuante global errado para o produto, roteamento que trava no agente inicial, vazamento de sessão entre páginas, labels sem localização, File Hub sem contexto útil, respostas genéricas por falta de tool-calling obrigatório, chaves expostas e coexistência de componentes legados. A solução proposta no próprio material aponta corretamente para uma Embedded Specialist Architecture, com bots inline por página, hub de agentes para configuração e bot global rebaixado para ajuda geral.

Minha conclusão técnica é mais forte do que a do texto: o problema não é falta de agentes; é falta de materialização operacional dos agentes. O sistema já tem peças de multiagentes, mas o fluxo principal ainda está organizado como um “superchat com contexto ruim”. Isso gera falsa sensação de inteligência, custo alto, pouca precisão e baixa percepção de valor.

Veredito técnico definitivo

A melhor direção é esta:

manter o Hub de Agentes,
substituir o protagonismo do chat flutuante por agentes embedded,
migrar de eager-context para lazy-tooling controlado,
adotar RAG híbrido com banco vetorial,
formalizar memória em camadas,
e transformar tools em skills versionadas e observáveis.

Em termos de decisão arquitetural, eu recomendo:

1. Bot global: opção A do documento, mas com prazo para virar quase irrelevante. Ele deve existir só para onboarding, navegação, FAQ da plataforma e redirecionamento contextual. Nunca como agente de negócio.

2. Streaming: sim, entra, mas não no primeiro bloco crítico. Primeiro corrigimos arquitetura, roteamento, sessão, tools e contexto. Streaming entra logo depois, porque melhora muito UX, mas não corrige inteligência.

3. FEATURE_AI_TOOL_WRITE: sim, entra na sprint, mas sob aprovação explícita, trilha de auditoria, escopo estreito e rollback. O próprio texto já sinaliza isso como desejado.

4. Sobre “pgcrypto key” que você disse não ter entendido: isso significa que existe ao menos um registro com chave/API criptografada que não está sendo descriptografada com a chave-mestra atual da aplicação. Em prática, há três causas comuns: a chave de criptografia do ambiente mudou, o dado foi gravado com outra key, ou o registro está corrompido. O melhor caminho é: identificar quais linhas falham, exportar metadados sem expor segredo, validar se ainda são necessárias e, se forem, regravar/recriptografar com a key atual. Se não forem, apagar essas rows. Não é para decidir no escuro; é para fazer diagnóstico dirigido.

Arquitetura alvo recomendada
Camada 1 — Domain Embedded Agents

Cada página relevante da plataforma deve carregar seu agente próprio, com sessão própria, contexto próprio e conjunto de skills próprio.

Exemplo de mapeamento alvo:

Dashboard → agente de gestão executiva
Bar/Food/Shop → agente de operação comercial e estoque
Parking → agente de logística operacional
Artists → agente de artistas e produção
Workforce → agente de escala e operação humana
Finance → agente financeiro/contratos
Organizer Files → agente documental/RAG
Tickets → agente comercial e demanda

Isso já está alinhado com o desenho proposto no documento, e eu considero esse o eixo principal da transformação.

Camada 2 — Agent Hub

O hub continua existindo, mas como console de governança:

catálogo de agentes
ativação/desativação
provider e modelo por agente
skills atribuídas
limites e orçamento
analytics por agente
DNA global do organizador
DNA por evento
memória do agente
preview do agente

O hub deixa de ser “onde a IA acontece” e vira “onde a IA é configurada”.

Camada 3 — Global Assistant downgraded

O bot flutuante continua temporariamente, mas com escopo controlado:

ajuda sobre a plataforma
onboarding
“onde eu faço isso?”
“qual módulo devo usar?”
delegação para o agente da página

Sem leitura de dados operacionais sensíveis. O documento já recomenda esse downgrade, e eu concordo.

Re-arquitetura interna do sistema de IA
1. Orquestração

Hoje, o problema central é que o sistema deixa o LLM “livre demais” para responder sem usar ferramentas. O documento aponta isso explicitamente, inclusive sugerindo tool_choice: "required" na primeira mensagem e redução de temperatura. Isso deve ser implementado.

Minha recomendação:

primeira interação da sessão: uso de tools obrigatório
interações seguintes: tool_choice automático, mas com policy forte
roteador sem lock duro por agent_key
sticky agent só como peso, nunca como prisão
bounded loop com limite por custo, tempo e profundidade
planner separado do executor, mesmo que inicialmente no mesmo serviço
2. Contexto

O documento também acerta ao apontar excesso de eager-loading no contexto. Isso mata custo, latência e precisão. A correção é um modelo híbrido:

contexto inicial mínimo
organizer_id
event_id
surface
timezone
idioma
DNA do negócio
snapshot pequeno da página
contexto expandido por skill/tool
buscar vendas
ler arquivo
consultar estoque
recuperar histórico
buscar memória relevante
consultar vetor/RAG

Ou seja: menos dump, mais recuperação dirigida.

3. Sessão

O documento mostra vazamento entre páginas e propõe chave composta por organizer, surface e event. Isso é obrigatório.

Minha recomendação final de chave de sessão:

session_key = organizer_id:event_id:surface:agent_scope:user_scope

E incluir:

archive automático quando troca de surface
retomada opcional por surface
resumo de handoff entre agentes
marcação de sessão ativa/inativa
limite de histórico em janela curta + memória resumida
4. Memória

O material fala em Mem Palace e também aponta que a memória atual ainda é relacional e insuficiente semanticamente. A direção correta é memória em quatro camadas:

Memória 0 — turn memory

contexto do turno atual

Memória 1 — session memory

resumo curto da conversa da sessão

Memória 2 — agent working memory

fatos persistentes por agente/evento

Memória 3 — semantic organizational memory

vetorial + relacional + documentos + eventos passados

Isso transforma “mem palace” de conceito em arquitetura operacional.

Banco de dados e desenho de dados

Você pediu abrangência em todas as camadas. Então o desenho ideal do banco fica assim:

Camadas de persistência

Relacional transacional

agents
skills
sessions
messages
approvals
tool_runs
audit_logs
provider_configs
event_context_snapshots

Relacional analítico leve

agent_usage_daily
token_costs
skill_success_rate
routing_metrics
prompt_versions
fallback_incidents

Vetorial

document_chunks_embeddings
memory_embeddings
entity_embeddings
playbooks_embeddings
Tabelas novas ou reforçadas
ai_agent_registry
ai_skill_registry
ai_agent_skill_map
ai_conversation_sessions
ai_conversation_messages
ai_tool_executions
ai_approval_requests
ai_memory_items
ai_memory_links
ai_memory_embeddings
ai_prompt_versions
ai_route_decisions
ai_surface_configs
ai_provider_bindings
ai_rag_documents
ai_rag_chunks
ai_rag_embeddings
ai_incidents
Regras obrigatórias
RLS forte para tudo que é tenant/event scoped
chaves compostas por organizer/event quando fizer sentido
soft delete só onde houver necessidade real
trilha de auditoria em tool write
versionamento de skills e prompts
índices por organizer/event/surface/agent
particionamento futuro em mensagens, tool runs e embeddings se volume crescer
RAG e banco vetorial

O documento já conclui corretamente que o File Hub atual não é RAG de verdade. Ele só injeta resuminhos e isso não resolve perguntas precisas.

Minha recomendação definitiva:

Fase 1 — RAG pragmático
skill search_organizer_files
skill read_organizer_file
skill list_related_files
skill extract_file_entities
parsed_data completo sob demanda
sem vetor ainda, mas com busca SQL + full text + filtros
Fase 2 — RAG híbrido
pgvector
embeddings por chunk semântico
chunking por estrutura lógica, não por tamanho cego
lexical + vetorial + reranker
citações por trecho
score de confiança
cache de retrieval
Fase 3 — RAG agentic
planner decide:
procurar arquivo
filtrar categoria
recuperar chunks
ler documento inteiro
pedir confirmação
comparar múltiplos documentos
síntese com evidência e rastreabilidade
Stack sugerida
PostgreSQL + pgvector
embeddings OpenAI ou Voyage ou Gemini embeddings, conforme custo/qualidade
BM25/FTS no próprio Postgres ou OpenSearch mais adiante
reranking opcional depois
pipeline de ingestão assíncrona
Skills que vocês devem construir

Você pediu todas as skills que deveriam existir. Vou organizar por domínio.

A. Skills centrais de plataforma
route_intent
resolve_surface_context
load_event_snapshot
load_organizer_dna
load_agent_profile
load_session_summary
save_session_summary
search_memory
save_memory_fact
list_available_skills
delegate_to_agent
request_user_approval
record_audit_event
estimate_operation_risk
translate_business_labels_ptbr
B. Skills de dados operacionais
get_bar_sales_snapshot
get_bar_stock_status
get_low_stock_alerts
get_ticket_sales_snapshot
get_ticket_demand_insights
get_artist_schedule
get_artist_logistics_status
get_workforce_coverage
get_shift_risks
get_parking_live_snapshot
get_parking_bottlenecks
get_finance_overview
get_supplier_payment_status
get_dashboard_kpis
get_event_health_summary
C. Skills documentais e RAG
search_organizer_files
read_organizer_file
read_file_excerpt
extract_file_entities
compare_documents
answer_from_documents
search_document_semantic
list_documents_by_category
find_latest_relevant_document
cite_document_evidence
D. Skills de memória
write_working_memory
read_working_memory
promote_memory_to_long_term
summarize_event_history
link_related_memories
forget_obsolete_memory
score_memory_relevance
E. Skills de ação com aprovação
create_task_with_approval
update_record_with_approval
send_notification_with_approval
generate_report_with_approval
publish_summary_with_approval
propose_operational_action
rollback_last_action
F. Skills de engenharia/ops internas
diagnose_agent_route
diagnose_tool_usage
inspect_prompt_version
inspect_session_trace
inspect_rag_pipeline
report_agent_incident

Essas 60 já cobrem a espinha dorsal. O documento menciona um ecossistema já avançado de agentes e tools; portanto, o trabalho não é inventar do zero, é formalizar, separar, versionar e encaixar no fluxo correto.

Ferramentas e integrações recomendadas
LLM / Providers
OpenAI para raciocínio geral e tool use forte
Gemini como segundo provider
opcionalmente Claude para sumarização/documentos, se fizer sentido no custo
Banco e busca
PostgreSQL
pgvector
Redis para cache curto e filas leves
opcional: OpenSearch no futuro
Observabilidade
OpenTelemetry
Sentry
Prometheus + Grafana
logs estruturados por session_id, agent_key, skill_key, tool_run_id
Filas e processamento
fila assíncrona para ingestão documental
fila para embeddings
fila para sumarização de memória
fila para relatórios executivos
Frontend
componente único EmbeddedAIChat
renderer adaptativo padronizado
suporte a streaming SSE
painéis de tool activity
pills de ação por domínio
Segurança
segredo fora de .env plaintext em produção
Vault, Secret Manager ou equivalente
rotação por ambiente
trilha de auditoria em writes
política de aprovação humana
MCPs que valem integrar

Como você perguntou de MCP e integrações que ajudam, eu recomendo separar em três categorias.

MCPs de conhecimento interno
banco relacional interno
storage de arquivos
índice vetorial
catálogo de eventos
analytics/KPIs
MCPs de operação
ERP/financeiro
tickets
workforce/escalas
estoque/PDV
parking/logística
notificações
MCPs de engenharia
observabilidade
incidentes
repositório de código
documentação técnica
gestão de backlog

Regra de ouro: MCP de leitura entra antes. MCP de escrita só entra depois de approval workflow sólido.

Arquivos de backend que devem mudar

Com base no documento, estes são os principais focos imediatos:

AIController.php
corrigir lock de roteamento, garantir hint e não prisão, melhor gestão de sessão.
AIConversationService.php
isolamento por surface/event, create-or-find session, archive por troca de superfície.
AIIntentRouterService.php
score com peso, sticky agent moderado, sem hard lock, rastreio da decisão.
AIContextBuilderService.php
reduzir eager context, corrigir file hub, preparar contexto mínimo + retrieval sob demanda.
AIPromptCatalogService.php
políticas mais rígidas: PT-BR, usar tool antes de responder, sem inventar dados.
AIOrchestratorService.php
required tools na primeira rodada, bounded loop confiável, tuning de temperatura.
AIToolRuntimeService.php
adicionar skills documentais, memória, governança e execução observável.
AdaptiveResponseService.php
dicionário PT-BR, padronização semântica de labels.
Arquivos de frontend que devem mudar
UnifiedAIChat.jsx
downgrade funcional para ajuda geral.
EmbeddedAIChat.jsx
criar componente principal reutilizável.
OrganizerFiles.jsx
embed do agente documental.
páginas de Bar/Food/Shop
embed do agente operacional comercial.
Dashboard
embed executivo.
Artists
embed do agente de artistas.
remover componentes legados V1
ParkingAIAssistant.jsx, ArtistAIAssistant.jsx, WorkforceAIAssistant.jsx.
Backlog executivo com sprints paralelos sem conflito

Vou estruturar como trilhas paralelas. Cada trilha mexe em fronteiras claras para evitar colisão.

Sprint 1 — Foundation Architecture

Objetivo: corrigir a fundação sem ainda espalhar mudanças por toda a plataforma.

Trilha A — Backend core

session isolation
routing fix
tool policy hardening
prompt hardening
logs de decisão do router
labels PT-BR

Trilha B — Frontend shell

criar EmbeddedAIChat
adaptar renderer base
preparar container e props por surface
downgrade do global chat

Trilha C — DB/schema

novas colunas de session key e índices
tabelas de tool executions / route decisions
seeds/config de surface-agent map

Trilha D — QA/observabilidade

testes de troca de agent
testes de troca de página
smoke tests de PT-BR
métricas por agent/skill

Saída esperada

já existe embedded agent funcional em ambiente interno
já não há vazamento de sessão
já não há travamento no agente inicial
Sprint 2 — First Embedded Rollout

Objetivo: colocar valor visível em páginas críticas.

Trilha A

Dashboard embed
Organizer Files embed

Trilha B

context builders mínimos dessas páginas
suggestion pills contextuais

Trilha C

skill read_organizer_file
skill search_organizer_files
tool trace UI

Saída

a IA finalmente responde com contexto de tela e documento real
Sprint 3 — Operational Domains

Objetivo: ampliar para operação real.

Trilha A

Bar/Food/Shop embed
Artists embed

Trilha B

skills de estoque, vendas, artistas, agenda

Trilha C

memory layer 1 e 2
session summarization
handoff summary

Saída

agentes operacionais úteis por domínio
Sprint 4 — Writes, Approval, and Safety

Objetivo: habilitar ações com segurança.

Trilha A

FEATURE_AI_TOOL_WRITE
workflow de aprovação
auditoria

Trilha B

actions com rollback
simulate-before-write

Trilha C

observabilidade de writes
risco por operação

Saída

agentes deixam de ser só analíticos e passam a executar com controle
Sprint 5 — RAG Hybrid

Objetivo: evoluir documents para inteligência semântica.

Trilha A

pgvector
embeddings pipeline

Trilha B

chunking semântico
busca híbrida
reranking

Trilha C

evidências/citações
score de confiança
testes de recuperação

Saída

File Hub vira RAG real
Sprint 6 — Mem Palace Enterprise

Objetivo: memória escalável e semântica.

Trilha A

memory embeddings
relevance search
promotion pipeline

Trilha B

policy engine
retenção
deduplicação

Trilha C

dashboards de memória
custos e qualidade

Saída

memória útil entre sessões, eventos e agentes
Sprint 7 — Cleanup and Scale

Objetivo: consolidar.

remover legados
otimizar custo
refinar providers
streaming SSE
fallback inteligente
canary rollout por agente
Como rodar agentes paralelos sem conflito

Você pediu explicitamente isso. A resposta é: separação por domínio, contrato e ownership.

Squads paralelos

Squad 1 — Core AI Backend

controller
orchestrator
router
conversation
tool runtime

Squad 2 — Frontend Agent Experience

embedded component
adaptive renderer
streaming
pages integration

Squad 3 — Data & RAG

file hub
parsing
vetorial
retrieval
memory

Squad 4 — Platform & Security

secrets
approvals
audit
observability
CI/CD
Regras para não conflitar
um owner por serviço
feature flags por trilha
contrato de payload versionado
migration-first para mudanças de schema
ADR curto para cada mudança estrutural
integração por páginas em waves
freeze de legado antes da remoção
Critérios de aceite essenciais
Pergunta em página Bar usa contexto de Bar e não sessão anterior.
Pergunta sobre Artists, feita após entrar por outro agente, consegue rerotear.
Arquivo específico pode ser lido de verdade pela IA.
Labels aparecem em português de negócio.
Primeira resposta usa tools.
Bot global não acessa dados sensíveis.
Writes exigem aprovação e ficam auditados.
Métricas mostram agent, skill, route, tool e custo por sessão.
Riscos reais
tentar fazer tudo ao mesmo tempo
manter eager context por medo de o LLM “não usar tools”
implantar vector DB antes de corrigir o File Hub básico
liberar tool write sem approval forte
não versionar prompt/skills
não medir fallback silencioso
Ordem ideal de implementação

Minha ordem final recomendada é:

session isolation
routing fix
embedded chat base
tool usage hardening
Organizer Files + Dashboard
Bar + Artists
document read/search skills
approval flow
vector RAG
memory enterprise
streaming
cleanup final
Decisão final que eu recomendo para vocês

Se eu estivesse respondendo como arquiteto responsável, minha orientação formal seria:

aprovar a Embedded Specialist Architecture
aprovar o downgrade do bot global
aprovar a migração gradual de eager-context para lazy-tooling
aprovar tool write com approval nesta trilha
adiar vector DB para depois do conserto do File Hub básico
tratar Mem Palace como programa de evolução, não como correção pontual
fazer rollout por 4 páginas primeiro: Dashboard, OrganizerFiles, Bar e Artists, exatamente como o próprio documento sugere.

O próximo passo ideal é eu transformar isso em um plano operacional fechadinho, com:
épicos, histórias, tarefas técnicas por arquivo, critério de aceite, dependências e ordem de execução por squad.

Como você prefere um passo a passo detalhado, estruturei o Backlog Executivo focando na transição do modelo de "Chatbot Genérico" para o modelo de "Agentes Especialistas Embutidos" (EMAS).Aqui está a organização estratégica para não perdermos nada do que foi auditado:1. Veredito Técnico: O "Pulo do Gato"Hoje, o sistema sofre de "Ecesso de Bagagem". Ele tenta carregar todos os dados do evento de uma vez (Eager Loading), o que confunde a IA e encarece o processo. A mudança central será: IA Preguiçosa (Lazy Context). Ela só saberá o básico e usará "Ferramentas" (Tools) para buscar o que precisar no momento.2. Backlog Estruturado (Prioridade Máxima)Abaixo, organizei as tarefas em trilhas paralelas para que o desenvolvimento não trave.Fase 1: Fundação & UX (A Mudança Visual)Fim do Bot Global: O chat flutuante vira apenas um "Manual de Ajuda".Nascimento dos Bots de Domínio: Cada tela (Bar, Financeiro, Artistas) terá seu próprio assistente que já "nasce" sabendo onde está.Isolamento de Sessão: Se o usuário sair da tela de Bar e for para Estacionamento, a IA deve "limpar a mesa" e focar no novo contexto.Fase 2: Inteligência Operacional (RAG Real)File Hub: Parar de ler apenas resumos de arquivos. A IA agora terá ferramentas para "abrir o arquivo e ler o parágrafo X".Tradução PT-BR: Padronizar todos os termos técnicos para o português de eventos (ex: de "Revenue" para "Faturamento").Fase 3: Memória e SegurançaMemPalace: Dar à IA a capacidade de lembrar o que foi decidido em reuniões ou chats anteriores de forma organizada.Criptografia: Proteger as chaves da OpenAI e Gemini (o erro do pgcrypto indica que precisamos apenas re-sincronizar a chave mestra do banco).Visualizador de Backlog e Roadmap EnjoyFun v2.0Use este simulador para explorar as etapas do projeto e ver quais arquivos serão impactados em cada Sprint.Show me the visualization3. Mapa de Execução por Arquivos (Para seu Controle)André, para facilitar sua vida na hora de abrir o VS Code, aqui estão os arquivos "quentes" que vamos mexer primeiro:CamadaArquivo PrincipalO que vamos fazer?BackendAIContextBuilderService.phpReduzir de 2600 linhas para ~500. Tirar o "lixo" e deixar só o DNA.BackendAIController.phpDestravar o roteador para que a IA possa mudar de ideia se o usuário mudar de assunto.FrontendEmbeddedAIChat.jsx(Novo) Criar este componente que você vai "colar" em todas as páginas.Bancomigrations/069_rls...Garantir que um organizador nunca veja os dados de memória de outro.4. Recomendações de Ouro (Dicas do Vovô para o Vovô)Não tenha medo de deletar: Os arquivos ParkingAIAssistant.jsx e similares da V1 estão poluindo seu projeto. Eles serão substituídos pelo novo componente único.Atenção às chaves: Antes de começar a Sprint 1, vamos rotacionar suas chaves de API. Segurança em primeiro lugar.Vá devagar no RAG: Antes de tentar usar o "pgvector" (que é complexo), vamos focar nas Tools de leitura de arquivo (Sprint 2). Elas já vão resolver 80% do seu problema de a IA "falar bobagem" sobre os documentos.Podemos começar detalhando a tarefa FE-01 (Criação do componente de chat embutido)? Se estiver pronto, me dê o sinal verde!

## 5. Evolução Estratégica: Arquitetura Híbrida & Gamificação B2C (Project Stitch / Meta-Engines 3D)

*Aprovado em 2026-04-12* - A visão técnica da plataforma decreta o uso de uma **Arquitetura Espacial Híbrida** para alcançar nível de excelência Consumer-Grade sem penalizar a operação administrativa:

- **B2B / Operacional (Core React Native):** Gestão financeira, leitura de dashboards, configurações e formulários de inputs dinâmicos ("FormBlock" da Fase 1) continuam rodando 100% nativos via React Native/Expo. Isso assegura fluidez (60fps), baixíssimo consumo de bateria, segurança biométrica e build ágil (Over-The-Air configs).
- **B2C / Experiencial (Gamificação 3D Integrada):** A jornada do "Participante/Festeiro" (Compra Ingressos, Navegação Interativa da Planta Baixa 3D, Interação Espacial de Line-up) passará a ser processada por Motores e Meta-Agentes 3D (como ecossistema Three.js WebGL / Project Stitch / Google Genie / WebGPU). Essa frente "video game" será embutida (_Embedded WebView_ ultra otimizada) dentro do app base, separando por completo a renderização 3D da tela de boletos/B2B operativos, garantindo peso levíssimo nas Lojas (App Store/Play Store) com UI hipnotizante e interatividade comparável a entretenimentos imersivos AAA.

- **Motor Multi-Vertical (Theming & Adaptação):** Devido ao escopo amplo da plataforma (Casamentos, Formaturas, Eventos Esportivos, Feiras de Negócios e Congressos), o *B2C Hub* gamificado opera como um framework camaleão. A UI Espacial e o comportamento do Agente AI herdarão o "DNA do Evento" (tema visual, topologia 3D, tom de voz) renderizando a interface de forma exclusiva. Um Casamento terá UI minimalista e romântica (mapa do buffet/cerimônia), enquanto um Congresso Corporativo terá layout brutalista técnico (mapa de palestras/expositores).