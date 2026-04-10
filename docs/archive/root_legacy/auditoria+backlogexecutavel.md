# Backlog Executavel — Auditoria Consolidada do Sistema Real

> Atualizacao de 2026-04-09: este arquivo permanece como backlog tematico da frente anterior. O parecer atual de prontidao operacional, seguranca, escala e governanca documental esta em `docs/auditoria_prontidao_operacional_2026_04_09.md`.

Data de consolidacao: 2026-04-09
Repositorio validado localmente em `c:\Users\Administrador\Desktop\enjoyfun`

## 1. Objetivo

Este documento substitui a auditoria anterior por um backlog executavel baseado no codigo atual do repositorio.

Ele responde a 4 perguntas:

1. O que da auditoria antiga ainda continua verdadeiro.
2. O que ficou desatualizado porque o codigo evoluiu.
3. O que vale corrigir primeiro.
4. Em que ordem os merges devem acontecer para reduzir risco operacional.

---

## 2. Diagnostico Validado no Codigo Atual

## 2.1. Loop da IA

Status validado:

- Continua sem loop iterativo multi-step com historico estrutural de mensagens `assistant/tool`.
- O fluxo atual ainda e: chamada ao provider -> policy -> execucao local de tools -> segunda passada textual apenas se `insight` vier vazio e `handled_all=true`.
- A segunda passada ainda injeta os resultados das tools como texto no prompt e nao como historico estruturado do provider.

Arquivos e pontos observados:

- `backend/src/Services/AIOrchestratorService.php`
  - `generateInsight`
  - `completeInsightAfterReadOnlyTools`
  - `buildToolFollowUpPrompt`
  - `requestInsight`
  - requests atuais para OpenAI, Gemini e Claude ainda saem com payload montado a partir de `system + user`, nao de um historico canonico.

Conclusao:

- O achado principal da auditoria continua correto.
- Esse item segue sendo prioritario, mas vem depois do hardening financeiro offline.

## 2.2. Topup offline

Status validado:

- O pipeline offline ainda aceita `topup`.
- O backend ainda aceita `payment_method` vindo do payload sem whitelist offline.
- O normalizer apenas padroniza o valor, sem bloquear metodo digital.
- O frontend global de sync ainda envia `topup` normalmente.
- A migration de hardening offline nao protege metodo de pagamento offline.

Arquivos e pontos observados:

- `backend/src/Services/OfflineSyncService.php`
  - `processItemByType`
  - `processTopup`
- `backend/src/Services/OfflineSyncNormalizer.php`
  - `normalizeTopup`
- `frontend/src/hooks/useNetwork.js`
  - `NETWORK_SYNC_TYPES`
  - `normalizePendingSyncRecord`
- `database/025_cashless_offline_hardening.sql`
- `backend/src/Services/WalletSecurityService.php`
  - persistencia de `payment_method` em `card_transactions`

Conclusao:

- O achado da auditoria continua correto e tem risco financeiro real.
- Esse item e o primeiro bloqueador de execucao.

## 2.3. Human-in-the-Loop da IA

Status validado:

- A policy de aprovacao existe e esta consistente.
- O endpoint de aprovar/rejeitar existe.
- A execucao aprovada nao e retomada automaticamente.
- O status muda no banco, mas nao existe um fluxo que execute `tool_calls_json` aprovados e finalize a resposta da IA.

Arquivos e pontos observados:

- `backend/src/Services/AIToolApprovalPolicyService.php`
- `backend/src/Services/AgentExecutionService.php`
  - `approveExecution`
  - `rejectExecution`
  - `applyApprovalDecision`
- `backend/src/Controllers/AIController.php`

Conclusao:

- O achado central da auditoria continua correto.
- Porem, a auditoria ficou parcialmente desatualizada sobre o runtime de tools.

## 2.4. O que a auditoria antiga nao refletia mais

Achados novos importantes:

- O catalogo de tools hoje e bem maior do que a auditoria dizia.
- Ja existem write tools definidas e executores write implementados no runtime.
- O problema principal nao e mais "nao existe write tool"; o problema principal e "nao existe retomada segura apos aprovacao".
- Ja existe um feed de execucoes no frontend, entao o console de aprovacoes nao e greenfield.
- O runtime ja sabe montar catalogo MCP, mas o orquestrador nao passa `db` e `organizerId` ao montar o catalogo enviado ao provider. Na pratica, MCP fica invisivel para o modelo nesse fluxo.

Arquivos e pontos observados:

- `backend/src/Services/AIToolRuntimeService.php`
  - catalogo read e write
  - `dispatchToolExecution`
  - gate `FEATURE_AI_TOOL_WRITE`
- `backend/src/Services/AIOrchestratorService.php`
  - `AIToolRuntimeService::buildToolCatalog($context)` sem `db` e `organizerId`
- `frontend/src/components/AIExecutionFeed.jsx`
- `frontend/src/api/ai.js`

Conclusao:

- O backlog deve focar menos em "inventar tools" e mais em "fechar controle, execucao, idempotencia e UX".

---

## 3. Priorizacao Executiva

Ordem recomendada:

1. Proteger dinheiro offline.
2. Substituir o fallback textual da IA por loop bounded read-only.
3. Materializar retomada de execucao aprovada.
4. Abrir somente 1 write path de baixo risco ponta a ponta.
5. Fechar UX de aprovacao e reconciliacao.

Motivo da ordem:

- `topup` offline mal validado afeta integridade financeira e auditoria.
- Loop bounded melhora qualidade e previsibilidade sem abrir mutacao operacional.
- Escrita da IA so deve avancar depois que a trilha de aprovacao virar execucao real com idempotencia.

---

## 4. Regras de Implementacao

Estas regras valem para todos os sprints:

- Toda mudanca de IA deve sair atras de feature flag.
- Toda write tool deve ter escopo por `organizer_id`, `event_id` e `approval_scope_key`.
- Toda retomada de execucao aprovada deve ser idempotente.
- Toda alteracao em fluxo offline deve falhar no frontend e no backend.
- Nao adicionar constraint global em `card_transactions.payment_method` sem distinguir online x offline.
- Cada sprint precisa sair com checklist de rollback.

Riscos transversais:

- A cobertura automatizada atual e baixa para esses fluxos.
- O custo e a latencia de IA vao subir no bounded loop.
- O feed atual de execucoes mostra historico, mas nao acao; isso pode dar falsa sensacao de prontidao.

---

## 5. Backlog por Sprint

## Sprint 1 — Hardening Financeiro Offline

Objetivo:

- Garantir a invariavel: `topup` offline so aceita `cash/manual`.

Saida esperada:

- Qualquer recarga offline com PIX, cartao ou metodo digital falha de forma deterministica.

### Ticket S1-01 — Bloqueio canonico no backend

Tipo:

- Backend

Arquivos:

- `backend/src/Services/OfflineSyncService.php`

Mudancas:

- Em `processTopup`, normalizar `payment_method`.
- Permitir apenas `cash` e `manual` na entrada.
- Canonizar internamente para um unico contrato, preferencialmente `cash`.
- Rejeitar `pix`, `card`, `credit_card`, `debit_card`, `web`, `asaas` e equivalentes com erro 422.
- Incluir `error_code` operacional padrao, por exemplo `offline_payment_method_not_allowed`.

Criterios de aceite:

- Payload offline `topup` com `payment_method=manual` processa normalmente.
- Payload offline `topup` com `payment_method=cash` processa normalmente.
- Payload offline `topup` com metodo digital retorna 422.
- O erro volta com mensagem clara para reconciliacao.

Cuidados:

- Nao quebrar o endpoint online de recarga em `CardController`.
- Nao alterar regras de pagamento online legitimas.

Consequencias:

- Filas antigas com payload invalido vao falhar ao sincronizar.
- Isso e desejado, mas exige mensagem operacional boa.

Dependencias:

- Nenhuma.

Ordem de merge:

- 1

### Ticket S1-02 — Normalizacao defensiva do payload offline

Tipo:

- Backend

Arquivos:

- `backend/src/Services/OfflineSyncNormalizer.php`

Mudancas:

- Mapear aliases `dinheiro`, `especie`, `cash`, `manual` para `cash`.
- Opcionalmente preservar `payment_method_original` para auditoria e reconciliacao.
- Garantir saida canonica unica para o backend de sync.

Criterios de aceite:

- `manual`, `dinheiro`, `especie` e `cash` saem como `cash`.
- Metodo desconhecido continua identificavel para o bloqueio posterior.

Cuidados:

- Nao mascarar metodo digital como se fosse cash.

Consequencias:

- Menos variacao de payload no banco e no log.

Dependencias:

- S1-01.

Ordem de merge:

- 2

### Ticket S1-03 — Gate local no sync do frontend

Tipo:

- Frontend

Arquivos:

- `frontend/src/hooks/useNetwork.js`

Mudancas:

- Em `normalizePendingSyncRecord`, tratar `topup` como caso especial.
- Se `payment_method` nao for cash/manual, marcar como falha local imediata.
- Nao enviar esse registro ao backend.
- Exibir mensagem operacional orientando reconciliacao.

Criterios de aceite:

- Registro offline invalido nao sai do navegador para `/sync`.
- Registro invalido aparece como falha local com motivo claro.
- Registro valido continua sincronizando.

Cuidados:

- Nao bloquear `sale` e `meal`.
- Nao transformar erro de payload em erro transiente com retry infinito.

Consequencias:

- Menor carga de erro no backend.
- Mais previsibilidade para reconciliacao local.

Dependencias:

- Nenhuma.

Ordem de merge:

- 3

### Ticket S1-04 — Observabilidade minima do hardening offline

Tipo:

- Backend

Arquivos:

- `backend/src/Services/OfflineSyncService.php`
- Opcional: `backend/src/Controllers/HealthController.php`

Mudancas:

- Logar rejeicoes por metodo offline invalido.
- Registrar contagem ou metadado agregavel para acompanhamento operacional.

Criterios de aceite:

- Rejeicao de `topup` offline invalido deixa trilha auditavel.

Cuidados:

- Nao introduzir dependencia pesada de observabilidade agora.

Consequencias:

- Facilita rollout e monitora impacto em campo.

Dependencias:

- S1-01.

Ordem de merge:

- 4

Definicao de pronto da sprint:

- Frontend e backend convergem no mesmo contrato.
- Existe mensagem de falha clara para reconciliacao.
- Nao ha regressao no topup online.

---

## Sprint 2 — Loop Bounded Read-Only da IA

Objetivo:

- Substituir a segunda passada textual por um loop bounded com historico canonico de mensagens.

Saida esperada:

- Perguntas que exigem tools read-only retornam resposta final no mesmo request, em ate 3 passos, sem prompt improvisado de segunda passada.

### Ticket S2-01 — Modelo canonico de mensagens internas

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIOrchestratorService.php`

Mudancas:

- Introduzir uma estrutura canonica `messages`.
- Incluir roles equivalentes a `system`, `user`, `assistant`, `tool`.
- Persistir tool calls e tool results de modo reaproveitavel.

Criterios de aceite:

- Existe uma representacao interna unica de conversa multi-step.
- Ela nao depende de provider especifico.

Cuidados:

- Manter compatibilidade com retorno atual enquanto a flag nova estiver desligada.

Consequencias:

- Abre base para OpenAI, Claude e Gemini com o mesmo fluxo.

Dependencias:

- Nenhuma.

Ordem de merge:

- 1

### Ticket S2-02 — Serializacao provider-aware

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIOrchestratorService.php`

Mudancas:

- Criar adaptadores internos de serializacao por provider.
- OpenAI deve receber mensagens estruturadas com tool calls e tool responses.
- Claude deve receber blocos equivalentes.
- Gemini deve receber `contents/parts` equivalentes.

Criterios de aceite:

- O mesmo historico canonico pode ser serializado para os 3 providers suportados.
- Nenhum provider depende mais de um prompt de follow-up textual para fechar leitura.

Cuidados:

- Nao quebrar a extracao atual de `tool_calls`.
- Garantir compatibilidade com respostas sem tool calls.

Consequencias:

- O codigo de provider fica mais complexo, mas o fluxo fica previsivel.

Dependencias:

- S2-01.

Ordem de merge:

- 2

### Ticket S2-03 — Implementacao do loop bounded

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIOrchestratorService.php`

Mudancas:

- Implementar `runBoundedInteractionLoop(..., int $maxSteps = 3)`.
- Em cada passo:
  - chamar provider com `messages + tools`
  - aplicar policy
  - executar apenas tools read-only automaticamente
  - anexar resultados ao historico
  - encerrar com resposta final ou motivo de termino
- Manter fallback antigo atras de `AI_BOUNDED_LOOP_V2`.

Criterios de aceite:

- Step 1 sem tools retorna `completed`.
- Step 1 com tools read-only retorna texto final no mesmo request se tudo der certo.
- Ao atingir limite, a resposta termina explicitamente com motivo de encerramento.

Cuidados:

- Impor teto de passos e teto de custo.
- Nunca reexecutar writes automaticamente nessa sprint.

Consequencias:

- A latencia media de perguntas com tool usage vai subir.
- A taxa de respostas "consulte tool_results" deve cair.

Dependencias:

- S2-01
- S2-02

Ordem de merge:

- 3

### Ticket S2-04 — Rastreabilidade do loop

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIOrchestratorService.php`
- `backend/src/Services/AgentExecutionService.php`

Mudancas:

- Salvar em `context_snapshot_json`:
  - `loop_step_count`
  - `loop_exit_reason`
  - `tool_roundtrips`
- Preservar compatibilidade com a tabela atual, sem migration obrigatoria.

Criterios de aceite:

- Cada execucao com bounded loop deixa rastro claro do motivo de termino.

Cuidados:

- Nao inflar demais o `context_snapshot_json`.

Consequencias:

- Facilita debug sem abrir schema novo.

Dependencias:

- S2-03.

Ordem de merge:

- 4

### Ticket S2-05 — Corrigir exposicao de catalogo MCP ao provider

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIOrchestratorService.php`
- `backend/src/Services/AIToolRuntimeService.php`

Mudancas:

- Passar `db` e `organizerId` ao montar `buildToolCatalog`.
- Garantir que tools MCP elegiveis entrem no catalogo enviado ao provider.

Criterios de aceite:

- O catalogo MCP disponivel no runtime tambem fica disponivel para o modelo.

Cuidados:

- Respeitar filtro por `surface` e `agent_key`.
- Nao expor tool fora do escopo do tenant.

Consequencias:

- Amplia utilidade da IA sem novo endpoint.

Dependencias:

- Nenhuma.

Ordem de merge:

- 5

Definicao de pronto da sprint:

- O fluxo read-only nao depende mais de `buildToolFollowUpPrompt`.
- O bounded loop esta protegido por flag.
- Cada execucao registra quantidade de passos e motivo de encerramento.

---

## Sprint 3 — Retomada de Execucao Aprovada

Objetivo:

- Transformar aprovacao em execucao real.

Saida esperada:

- Ao aprovar uma execucao pendente, o sistema executa os `tool_calls` aprovados, registra resultado e fecha a resposta final.

### Ticket S3-01 — Executor de retomada apos aprovacao

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AgentExecutionService.php`
- `backend/src/Controllers/AIController.php`
- Opcional: novo service dedicado, por exemplo `backend/src/Services/AIApprovedExecutionRunnerService.php`

Mudancas:

- Ao aprovar, carregar `tool_calls_json`.
- Validar escopo da execucao.
- Executar tools aprovadas.
- Persistir resultado consolidado.
- Fechar execucao com `response_preview` final.

Criterios de aceite:

- Aprovar uma execucao pendente deixa de ser apenas atualizacao de status.
- A execucao muda para `succeeded`, `failed` ou `blocked` com motivo real.

Cuidados:

- Implementar idempotencia antes de abrir rollout.
- Evitar dupla execucao na mesma aprovacao.

Consequencias:

- Endpoint de aprovacao pode ficar mais pesado.
- Em troca, o fluxo vira utilizavel.

Dependencias:

- Sprint 2 concluida ou parcialmente pronta.

Ordem de merge:

- 1

### Ticket S3-02 — Reinjeção do resultado no loop para sintese final

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIOrchestratorService.php`
- Service de retomada criado na S3-01

Mudancas:

- Apos executar tools aprovadas, reinjetar os resultados como mensagens `tool`.
- Solicitar sintese final ao provider.

Criterios de aceite:

- A resposta final da execucao aprovada vem do mesmo mecanismo de historico estruturado.

Cuidados:

- Nao permitir novas writes em cascata sem nova policy.

Consequencias:

- Fecha o ciclo real de Human-in-the-Loop.

Dependencias:

- S3-01

Ordem de merge:

- 2

### Ticket S3-03 — Auditoria e diff de impacto

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AgentExecutionService.php`
- `backend/src/Services/AuditService.php`
- Eventuais services write afetados

Mudancas:

- Registrar por tool:
  - status
  - duracao
  - erro
  - diff resumido do estado alterado

Criterios de aceite:

- Uma aprovacao executada deixa trilha auditavel por tool.

Cuidados:

- Diff deve ser resumido, nao um dump excessivo.

Consequencias:

- Facilita confianca operacional e investigacao.

Dependencias:

- S3-01

Ordem de merge:

- 3

Definicao de pronto da sprint:

- Aprovar deixa de ser metadado e passa a ser acao.
- Existe idempotencia minima.
- Existe trilha de resultado por tool.

---

## Sprint 4 — Primeiro Write Path Seguro

Objetivo:

- Abrir somente 1 write path ponta a ponta, com risco baixo e rollout controlado.

Recomendacao de escopo:

- Comecar por write operacional de artistas.
- Nao comecar por item financeiro, cobranca, estorno ou qualquer write que gere efeito monetario.

### Ticket S4-01 — Escolha e isolamento do primeiro write path

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIToolRuntimeService.php`
- executor write escolhido

Escopo recomendado:

- `update_timeline_checkpoint` ou `update_artist_logistics`

Mudancas:

- Garantir validacao de escopo.
- Garantir retorno padrao de diff e resultado.
- Garantir comportamento idempotente.

Criterios de aceite:

- O write escolhido executa com aprovacao.
- Dupla submissao nao gera estado inconsistente.

Cuidados:

- Nao abrir mais de 1 write path nessa sprint.

Consequencias:

- Reduz superficie de erro no primeiro rollout real.

Dependencias:

- Sprint 3.

Ordem de merge:

- 1

### Ticket S4-02 — Separacao semantica de runtime read e write

Tipo:

- Backend

Arquivos:

- `backend/src/Services/AIToolRuntimeService.php`

Mudancas:

- Revisar nome e fluxo de `executeReadOnlyTools`.
- Separar explicitamente execucao read de execucao write, ou reforcar gate interno com clareza.

Criterios de aceite:

- O nome do metodo e o comportamento real passam a coincidir.
- O fluxo de write nao fica escondido dentro de um runtime com nome enganoso.

Cuidados:

- Refactor sem quebrar chamadas existentes.

Consequencias:

- Diminui risco de regressao conceitual futura.

Dependencias:

- Nenhuma.

Ordem de merge:

- 2

Definicao de pronto da sprint:

- Existe 1 write flow seguro, aprovado, auditado e idempotente.

---

## Sprint 5 — UX de Aprovacao e Reconciliacao

Objetivo:

- Fechar o ciclo operacional no frontend.

Saida esperada:

- O operador consegue aprovar, rejeitar e entender impacto sem ir ao banco ou ao log.

### Ticket S5-01 — API client para approve e reject

Tipo:

- Frontend

Arquivos:

- `frontend/src/api/ai.js`

Mudancas:

- Adicionar funcoes para aprovar e rejeitar execucao.

Criterios de aceite:

- O frontend consegue chamar os endpoints existentes de forma padronizada.

Dependencias:

- Sprint 3.

Ordem de merge:

- 1

### Ticket S5-02 — Estender feed atual para acao

Tipo:

- Frontend

Arquivos:

- `frontend/src/components/AIExecutionFeed.jsx`

Mudancas:

- Mostrar pendencias de aprovacao.
- Exibir `approval_scope_key`, risco, tools propostas e diff resumido.
- Incluir botoes de aprovar e rejeitar.

Criterios de aceite:

- Execucoes pendentes podem ser decididas pela interface.
- O operador entende o que sera feito antes de aprovar.

Cuidados:

- Nao esconder informacao critica em tooltip ou texto colapsado demais.

Consequencias:

- Reduz dependencia de suporte tecnico.

Dependencias:

- S5-01
- Sprint 3

Ordem de merge:

- 2

### Ticket S5-03 — Reconciliacao de fila offline

Tipo:

- Frontend

Arquivos:

- `frontend/src/components/OfflineQueueReconciliationPanel.jsx`
- `frontend/src/hooks/useNetwork.js`

Mudancas:

- Padronizar motivo de falha para `offline_payment_method_not_allowed`.
- Adicionar acao para corrigir metodo e reenfileirar quando aplicavel.

Criterios de aceite:

- O operador consegue corrigir falha de topup offline invalido sem editar IndexedDB manualmente.

Cuidados:

- Garantir que o reenfileiramento nao duplique registro.

Consequencias:

- Fecha o ciclo do hardening financeiro de forma usavel.

Dependencias:

- Sprint 1.

Ordem de merge:

- 3

Definicao de pronto da sprint:

- O fluxo de aprovacao de IA e o de reconciliacao offline estao utilizaveis na interface.

---

## 6. Ordem Recomendada de Merge

Sequencia de PRs:

1. PR-01: S1-01 + S1-02
2. PR-02: S1-03 + S1-04
3. PR-03: S2-01 + S2-02
4. PR-04: S2-03 + S2-04
5. PR-05: S2-05
6. PR-06: S3-01
7. PR-07: S3-02 + S3-03
8. PR-08: S4-01 + S4-02
9. PR-09: S5-01 + S5-02
10. PR-10: S5-03

Justificativa:

- PRs 1 e 2 isolam risco financeiro.
- PRs 3 a 5 trocam a base de orquestracao da IA sem abrir write para producao.
- PRs 6 a 8 so entram depois que a retomada aprovada ja existe.
- PRs 9 e 10 fecham UX depois da espinha dorsal backend estar estavel.

---

## 7. Checklist de Rollout

Para cada sprint:

- Ativar por feature flag em ambiente de homologacao.
- Executar smoke manual por fluxo feliz.
- Executar smoke manual por fluxo de erro.
- Validar logs, auditoria e status persistidos.
- Confirmar estrategia de rollback antes do merge.

Checklist adicional por frente:

- Offline financeiro:
  - validar topup online
  - validar topup offline valido
  - validar topup offline invalido
  - validar reconciliacao
- IA bounded loop:
  - validar pergunta sem tool
  - validar pergunta com 1 tool read-only
  - validar pergunta com 2 passos
  - validar limite de passos
- Aprovacao de IA:
  - validar approve
  - validar reject
  - validar dupla submissao
  - validar auditoria final

---

## 8. Resumo Executivo Final

O que precisa acontecer primeiro:

- Travar financeiramente o `topup` offline.
- Trocar o fallback textual da IA por loop bounded read-only.
- Materializar retomada real apos aprovacao.

O que nao precisa ser refeito do zero:

- Policy de aprovacao.
- Historico de execucoes.
- Feed basico de execucoes.
- Catalogo de varias tools read e write.

O maior erro de sequenciamento a evitar:

- Abrir write path da IA antes de ter idempotencia, retomada aprovada e trilha de impacto por tool.
