# ADR — EMAS (Embedded Multi-Agent System) v1

**Status:** Aceito — 2026-04-11
**Contexto:** [execucaobacklogtripla.md](../execucaobacklogtripla.md) — sprint de migração completa em 31 dias úteis.
**Substitui parcialmente:** [adr_ai_multiagentes_strategy_v1.md](adr_ai_multiagentes_strategy_v1.md) — mantém os agentes mas reescreve a fundação de contexto, sessão, roteamento e rendering.

---

## Contexto

O Hub de IA atual (Sprint 19+25) entrega 12 agentes, 33 skills, chat conversacional e Adaptive UI. Auditoria de 2026-04-11 (após smoke test do Sprint 5+6) identificou 6 falhas estruturais que impedem evento real:

1. **Contexto inflado:** `AIContextBuilderService.php` ~2600 linhas montando o mundo todo a cada msg → custo alto e prompt > 8k tokens em chamadas triviais.
2. **Sessão ambígua:** `findOrCreateSession` agrupa por organizer + agent, sem isolar superfície (Bar vs Food vs Dashboard) nem evento. Vazamento cross-surface confirmado no smoke.
3. **Roteamento frágil:** `AIIntentRouter` decide só no início e ignora o `agent_key` que o frontend já dá como hint.
4. **Tool-use opcional:** orquestrador pode responder direto sem chamar tool → IA inventa números (especialmente em superfícies operacionais).
5. **Bot global vira FAQ:** sem ownership claro, o bot flutuante mistura ajuda da plataforma com dados de evento.
6. **Mobile fora do contrato:** `enjoyfun-app` consome um payload V2 antigo, sem `surface` nem `event_id`.

Continuar empilhando feature em cima dessa base = mais dívida. A decisão é refundar com 7 princípios e migrar tudo atrás de feature flags.

---

## Decisão

Adotar a arquitetura **EMAS — Embedded Multi-Agent System** com os princípios abaixo. A migração roda em 6 sprints + 1 setup, em 3 frentes paralelas (Backend / Mobile / Frontend Web), sem dívida técnica intermediária.

### 1. Sessão por chave composta

```
session_key = "{organizer_id}:{event_id}:{surface}:{agent_scope}"
```

Frontend envia `surface`, `event_id`, `conversation_mode` e (opcional) `agent_key`. Backend resolve a key. Trocar de surface arquiva a sessão anterior automaticamente. Isolamento cross-tenant + cross-surface garantido por RLS nas tabelas `ai_conversation_sessions` e `ai_conversation_messages`.

### 2. Lazy context builder

`AIContextBuilderService` é refeito para entregar **só DNA do organizador + metadados do evento + snapshot mínimo da página atual**. Tudo o resto vira **tool call sob demanda**. Alvo: prompt inicial < 2000 tokens, custo médio por mensagem ↓ ≥ 50%.

### 3. Tool-use obrigatório na 1ª mensagem

`AIOrchestratorService` força `tool_choice: required` na primeira interação de cada turn de surface operacional. Temperatura cai para 0.25. Bounded loop V2 limita re-chamadas. Resultado: a IA **vê** os dados antes de falar — não inventa.

### 4. Roteamento híbrido com hint

`AIIntentRouter` continua Tier 1 (keyword) → Tier 2 (LLM-assisted), mas o `agent_key` enviado pelo frontend vira **bônus +5** no score do candidato correspondente. Reavaliação acontece a cada mensagem (não só no início), e cada decisão grava `routing_trace_id` em `ai_routing_events` (nova tabela).

### 5. EmbeddedAIChat por surface

UI muda do paradigma "página dedicada de IA" para **chat embarcado em cada superfície operacional** (Bar, Food, Shop, POS, Parking, Artists, Workforce, Tickets, Dashboard, Files). Componente único reutilizável `EmbeddedAIChat.{jsx,tsx}` recebe `surface`, `eventId`, `contextData`. Rendering via `AdaptiveUIRenderer` com 13 tipos de bloco.

### 6. Bot global = Platform Guide Agent

O bot flutuante deixa de tentar responder sobre dados de evento. Vira um **agente especialista da plataforma EnjoyFun**: tutoriais passo-a-passo, navegação assistida, diagnóstico de configuração. Detalhes em [adr_platform_guide_agent_v1.md](adr_platform_guide_agent_v1.md).

### 7. Camadas de memória

Quatro camadas escaláveis:

| Camada | Onde | Quando |
|---|---|---|
| Conversation buffer | `ai_conversation_messages` | sempre |
| Recall relacional | `ai_agent_memories` (top-3 por agente, recall via SQL) | S3 |
| Embeddings | `pgvector` em `ai_agent_memories` | S5 |
| MemPalace sidecar | container externo, semântica de longo prazo | S6 |

---

## Frentes paralelas

| Frente | Coordenador | Jurisdição | Por quê |
|---|---|---|---|
| 🔧 Backend | Claude Chat 1 | `backend/`, `database/`, `nginx/`, `docker*`, `tests/`, `docs/` | Refactor PHP profundo, migrations, RAG, segurança |
| 📱 Mobile | Claude Chat 2 | `enjoyfun-app/**` | RN/Expo é ecossistema próprio (TS, EAS, app stores) |
| 🌐 Frontend Web | Codex VS Code | `frontend/src/**`, `frontend/public/**` | Codex no IDE é ótimo para componentes React escopados |

Regra inviolável: os 3 lados nunca editam o mesmo arquivo. Conflito de merge = violação de processo. Detalhes em §2 de `execucaobacklogtripla.md`.

---

## Feature flags (12)

Todas default OFF, registradas em [backend/config/features.php](../backend/config/features.php):

`FEATURE_AI_EMBEDDED_V3`, `FEATURE_AI_LAZY_CONTEXT`, `FEATURE_AI_PT_BR_LABELS`, `FEATURE_AI_PLATFORM_GUIDE`, `FEATURE_AI_RAG_PRAGMATIC`, `FEATURE_AI_MEMORY_RECALL`, `FEATURE_AI_TOOL_WRITE`, `FEATURE_AI_PGVECTOR`, `FEATURE_AI_VOICE_PROXY`, `FEATURE_AI_MEMPALACE`, `FEATURE_AI_SSE_STREAMING`, `FEATURE_AI_SUPERVISOR`.

Liga uma flag → ativa o módulo. Rollback = desliga a flag. Zero código condicional duplicado em produção.

---

## Consequências

### Positivas
- Custo por mensagem cai ≥ 50% (lazy context + tool-use real)
- Isolamento cross-surface garantido por contrato (não por convenção)
- Mobile alinhado ao mesmo payload do web
- Bot global deixa de inventar dados — vira guia confiável da plataforma
- Cada melhoria nasce atrás de flag → rollback instantâneo
- Versionamento de prompts e skills (S4) destrava A/B test e ADRs futuros

### Negativas
- Refactor de 31 dias úteis em 3 frentes simultâneas exige disciplina de processo
- 12 flags ativas no fim do programa → matriz de teste maior
- pgvector e MemPalace adicionam dependências de infra (mitigadas por flags)
- Mobile precisa rebuild EAS para consumir o novo payload (S1+S6)

### Riscos mitigados
- **Conflito de merge entre frentes** → mapa de territórios §2 + branch naming
- **Contrato muda no meio do sprint** → contratos congelados após dia 1 do S1
- **Custo OpenAI dispara** → `AIBillingService` já tem caps + alerta em `/ai/health` (S4)

---

## Alternativas consideradas

1. **Iterar sobre o Hub atual sem refundar** — rejeitado: 6 falhas estruturais não saem com patch.
2. **LangGraph / CrewAI / AutoGen** — rejeitado: dependência externa pesada, perdemos controle de prompt e RLS.
3. **Single agent gigante** — rejeitado: contraria a separação de domínios (Bar ≠ Workforce ≠ Documents).
4. **Mover tudo para serverless / edge functions** — rejeitado: PHP 8.4 + Postgres single-node é o que está provado em produção; refundar IA + infra simultaneamente é risco demais.

---

## Critérios de aceite (gate de produção, fim do S6)

- 10 superfícies com `EmbeddedAIChat` operacional
- Pergunta no Bar usa contexto Bar, não vaza
- Trocar de surface arquiva sessão automaticamente
- IA chama tool antes de responder na 1ª msg
- Custo médio por mensagem cai ≥ 50% vs baseline 2026-04-11
- Latência p95 < 3s sem stream / first token < 1s com stream
- Cross-tenant retorna 0 rows em todas as tabelas de IA
- Load test 100 VUs PASS · Security scan 25 checks PASS
- Mobile usa payload V3 · Build 0 warnings · Zero código morto
