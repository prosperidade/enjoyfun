# progresso17.md - Fechamento do feed de execucoes na pagina /ai (`2026-03-27`)

## 0. Handoff oficial desta rodada

- Esta passada continua os cortes de IA registrados em `docs/progresso15.md` e `docs/progresso16.md`.
- O backend do historico de execucoes ja existia e estava valido.
- O gap real encontrado foi no frontend: a pagina `/ai` ainda nao consumia `GET /api/ai/executions`.

---

## 1. Escopo fechado nesta passada

- adicao de `listAIExecutions` em `frontend/src/api/ai.js`
- criacao de `frontend/src/components/AIExecutionFeed.jsx`
- integracao do feed na pagina `frontend/src/pages/AIAgents.jsx`

---

## 2. O que passou a aparecer na UI

- resumo das execucoes recentes
- botao de atualizacao manual do feed
- cards com:
  - status da execucao
  - status de aprovacao
  - agente efetivo ou fluxo legado
  - superficie e entrypoint
  - provider e modelo
  - evento, usuario e duracao
  - preview de prompt
  - preview de resposta ou erro
  - contador de tool calls

---

## 3. Decisao desta passada

- o feed novo ficou autocontido em componente proprio para nao acoplar o carregamento de execucoes ao fluxo de configuracao de agentes/providers
- assim, se o endpoint de execucoes oscilar, a tela de configuracao principal continua isolada do impacto

---

## 4. Validacao executada

- `npx vite build --logLevel error`

---

## 5. Proximo corte recomendado

- adicionar filtro por `agent_key`, `surface` e `execution_status` na UI
- decidir se vale abrir um endpoint de detalhe por execucao
- comecar a preencher `tool_calls_json` quando tool use entrar no runtime
