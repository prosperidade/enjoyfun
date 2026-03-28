# progresso16.md — Histórico materializado de execuções de IA (`2026-03-27`)

## 0. Handoff oficial desta rodada

- Esta passada continua os cortes de IA registrados em `docs/progresso14.md` e `docs/progresso15.md`.
- O foco aqui foi o corte de observabilidade mínima útil.
- O objetivo foi sair de billing residual em `ai_usage_logs` para um histórico próprio de execuções de IA.

---

## 1. Escopo fechado nesta passada

- criação da migration `database/039_ai_agent_execution_history.sql`
- criação de `backend/src/Services/AgentExecutionService.php`
- integração do histórico ao `AIOrchestratorService`
- publicação de leitura mínima via `GET /api/ai/executions`
- conexão da página `/ai` com execuções recentes

---

## 2. O que passou a ser materializado

- organizer
- evento
- usuário
- entrypoint
- superfície
- agente efetivo
- provider e modelo
- approval mode e approval status
- status da execução
- preview sanitizado do prompt
- preview sanitizado da resposta
- snapshot resumido do contexto
- tool calls
- erro
- duração
- timestamps de início e conclusão

---

## 3. Decisões desta passada

- o histórico novo não substitui `ai_usage_logs`
- `ai_usage_logs` continua como trilha de billing
- `ai_agent_executions` vira a trilha operacional da execução
- previews de prompt/resposta entram truncados e sanitizados
- quando a migration ainda não estiver aplicada, a escrita do histórico vira no-op seguro e a leitura retorna vazio

---

## 4. Validação executada

- `php -l backend/src/Services/AgentExecutionService.php`
- `php -l backend/src/Services/AIOrchestratorService.php`
- `php -l backend/src/Controllers/AIController.php`
- validação de frontend pendente de build local

---

## 5. Próximo corte recomendado

- começar a materializar `tool_calls_json` de verdade quando entrar tool use
- decidir se o histórico precisa de endpoint de detalhe por execução
- escolher a primeira superfície para enviar `agent_key` explícito e enriquecer a trilha
