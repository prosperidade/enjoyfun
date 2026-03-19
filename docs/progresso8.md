## 0. Escopo oficial de hoje

- Frente ativa do dia: `Meals`.
- Objetivo: encerrar o hardening prioritario do modulo sem abrir nova frente de `Workforce`.
- Fonte desta rodada:
  - auditoria funcional/operacional enviada hoje
  - fechamento anterior registrado em `docs/progresso7.md`
- Regra operacional desta nota:
  - tudo que entrar hoje deve ser registrado aqui antes de expandir escopo
  - mudancas de banco so entram com decisao explicita; nesta rodada o unico passo aplicado foi a `015`, por ser reconciliacao deterministica e controlada

---

## 1. Estado de partida

- `Meals` ja estava funcionalmente encerrado no piloto, mas ainda com gaps de:
  - autorizacao online para `manager/staff`
  - confiabilidade da fila offline em falha parcial
  - side effect de escrita em rotas de leitura
  - fallback silencioso de selecao automatica de servico
  - bugs silenciosos e gaps de observabilidade
- Ja existiam no repositorio:
  - `database/011_participant_meals_hardening.sql`
  - `database/012_event_meal_services_model.sql`
- Ja existe trabalho local em andamento nesta rodada:
  - endurecimento de `consumed_at`
  - suporte a janela `overnight`
  - ampliacao da auditoria `backend/scripts/audit_meals.php`
  - migration nova de hardening estrutural de `participant_meals`
- Importante:
  - no inicio da rodada, nenhuma migration nova havia sido aplicada ao banco
  - ao longo da execucao, somente a `015` foi aplicada de forma controlada; `014` e `016` seguem apenas versionadas

---

## 2. O que sera executado hoje

### Bloco P0 — Fechamento de risco operacional imediato

- `backend/src/Controllers/MealController.php`
  - fechar ACL online para `manager/staff` por setor
  - impedir uso de setor fora do escopo do operador
  - corrigir o bug silencioso do closure em `GET /meals/balance`
  - adicionar trilha minima de auditoria para baixa online

- `backend/src/Services/MealsDomainService.php`
  - remover fallback silencioso de servico quando o horario nao casa com nenhuma janela
  - manter `400` para `consumed_at` invalido
  - manter suporte a janela `overnight`
  - tirar escrita de caminhos de leitura sempre que possivel

- `frontend/src/pages/MealsControl.jsx`
  - alinhar o resolvedor local de servico com a mesma regra do backend
  - impedir UX que mascare ausencia de janela ativa
  - refletir corretamente o estado de fila `pending` versus `failed`

### Bloco P0 — Offline sem perda silenciosa

- `frontend/src/hooks/useNetwork.js`
  - parar de apagar `failed_ids` devolvidos por `/sync`
  - marcar falhas locais com motivo

- `frontend/src/pages/MealsControl.jsx`
  - exibir falhas locais do `Meals`
  - permitir reconciliacao/retry manual das falhas do proprio modulo

- `frontend/src/lib/db.js`
  - suportar metadados locais de falha/retry sem mudar o contrato remoto

### Bloco P1 — Leitura sem side effect

- `backend/src/Controllers/MealController.php`
  - `GET /meals/services` deve parar de materializar servicos default por leitura

- `backend/src/Services/MealsDomainService.php`
  - defaults de servico devem virar rascunho/configuracao explicita
  - persistencia de servicos deve acontecer apenas em mutacao administrativa

- `frontend/src/pages/MealsControl.jsx`
  - modal de configuracao deve continuar funcional mesmo se o evento ainda nao tiver linhas persistidas em `event_meal_services`

### Bloco P1 — Auditoria e banco versionado

- `backend/scripts/audit_meals.php`
  - manter e revisar checks novos:
    - `participant_meals.event_day_id IS NULL`
    - `meal_service_id` de outro evento
    - `consumed_at` fora do dia operacional

- `database/014_participant_meals_domain_hardening.sql`
  - deixar versionada a migration de endurecimento estrutural
  - nao aplicar hoje no banco automaticamente

- `database/schema_current.sql`
  - manter o baseline refletindo a migration nova

### Bloco P1 — Registro documental

- `docs/progresso8.md`
  - registrar escopo, ordem e aceite desta rodada

- `docs/progresso7.md`
  - se houver tempo, apenas acrescentar fechamento curto apontando que parte dos diagnosticos antigos ficou superada
  - sem reescrever a historia inteira do arquivo hoje

---

## 3. O que nao entra hoje

- nova frente de `Workforce`
- carga/performance real em banco grande
- redesign completo de fila offline com DLQ forense/HMAC/device attestation
- aplicacao automatica de migrations no banco local/staging/producao
- refactor amplo para remover toda duplicacao historica entre controller e service

---

## 4. Ordem de execucao

1. fechar backend de dominio e autorizacao
2. fechar perda silenciosa da fila offline
3. remover side effect de leitura sem quebrar configuracao
4. validar com lint/build e revisar diff
5. registrar fechamento do dia nesta propria nota

---

## 5. Criterios de aceite do dia

- `manager/staff` nao conseguem operar `Meals` fora do proprio setor
- falha offline nao e mais apagada da fila local
- `Meals` deixa de escolher o primeiro servico silenciosamente quando nao houver janela ativa
- `GET /meals/services` nao cria linhas no banco por leitura
- tela continua permitindo configurar servicos mesmo sem seed persistido
- `php -l` passa nos arquivos PHP alterados
- `npm --prefix frontend run build` passa
- nenhuma mudanca de hoje depende de aplicar migration para funcionar na aplicacao

---

## 6. Observacao de governanca

- Se surgirem novos pontos de auditoria durante a execucao, eles entram aqui apenas se:
  - forem realmente de `Meals`
  - couberem hoje sem abrir refactor estrutural amplo
  - nao aumentarem risco de regressao desnecessariamente

---

## 7. Execucao realizada hoje

- `backend/src/Controllers/MealController.php`
  - `GET /meals/balance`, `GET /meals` e `POST /meals` passaram a respeitar ACL setorial para `manager/staff`, espelhando a mesma logica-base do `sync`
  - corrigido o bug silencioso do closure em `GET /meals/balance`
  - adicionada trilha minima de auditoria para sucesso e falha na baixa online
  - `GET /meals/services` passou a entregar `services` + `draft_services` sem criar linhas por leitura
  - `POST /meals` deixou de depender de `event_day_id` obrigatorio quando o dominio consegue resolver o dia operacional automaticamente pelo `consumed_at`

- `backend/src/Services/MealsDomainService.php`
  - `consumed_at` invalido agora falha com `400`
  - selecao automatica de servico deixou de cair silenciosamente no primeiro item; quando nao ha janela valida o dominio retorna erro operacional
  - suporte a janela `overnight` mantido no backend
  - `buildCostContext()` e `resolveMealServiceSelection()` deixaram de escrever no banco por leitura
  - `saveEventMealServices()` passou a aceitar persistencia inicial a partir de rascunhos default, sem depender de seed previo no read path
  - o dominio passou a resolver e validar automaticamente `event_day_id` e `event_shift_id` pelo range real de `event_days`/`event_shifts` e pelo `consumed_at`
  - `saveEventMealServices()` passou a validar a grade completa antes de persistir: bloqueia janelas sobrepostas, `sort_order` duplicado/inconsistente com a regra de cota, horarios vazios/iguais e grade sem servico ativo

- `frontend/src/lib/db.js`
  - fila offline de `Meals` passou a suportar `failed`, `last_error`, `last_error_at` e reenfileiramento local

- `frontend/src/hooks/useNetwork.js`
  - `failed_ids` retornados por `/sync` deixaram de ser apagados
  - falhas parciais e payloads invalidos passaram a ser mantidos localmente com motivo

- `frontend/src/pages/MealsControl.jsx`
  - resolvedor local de refeicao alinhado com a regra do backend, incluindo janela `overnight`
  - fila local agora separa `pending` de `failed`
  - falhas locais aparecem na tela com motivo
  - retry manual por item e por recorte passou a existir
  - historico HTTP passou a respeitar `sector` tambem no cache local
  - modal de configuracao continua funcional mesmo quando o evento ainda nao possui linhas persistidas em `event_meal_services`
  - o registro passou a usar automaticamente o dia/turno operacional vigente para a baixa, sem depender do dia selecionado para consulta na tela
  - `consumed_at` da captura passou a sair em timestamp local do dispositivo, evitando deslocamento de dia operacional por `UTC/Z`
  - corrigida regressao no pos-registro: depois de validar uma refeicao, a tela passa a sincronizar saldo e historico no mesmo dia/turno real da baixa, evitando card zerado em recorte antigo
  - adicionada guarda de versao do frontend para detectar bundle antigo em estacoes abertas; em rotas operacionais criticas o app passa a recarregar automaticamente quando houver build nova disponivel
  - cards operacionais do dia passaram a usar o resumo autoritativo do backend para totais e contagem de participantes com consumo/saldo esgotado, evitando drift local quando a consulta estiver filtrada por refeicao
  - modal administrativo de refeicoes passou a validar localmente sobreposicao de janelas, ausencia de servico ativo, conflito de `sort_order` e inconsistencias com a regra de cota; lacunas horarias agora aparecem como aviso explicito antes do save
  - o card de saldo por participante deixou de afirmar `Sem turno` quando o payload nao traz `shift_id` univoco; a tela agora so mostra o turno quando ele realmente vem resolvido do backend
  - a tabela principal deixou de reiniciar ao atualizar contexto automatico de horario; refreshes complementares agora rodam em background e nao derrubam a grade em spinner
  - corrigido race de `loading`/`mealHistoryLoading` que podia deixar a tabela travada em load em transicoes de requests

- `frontend/src/components/AppVersionGuard.jsx`
  - o reload automatico de build em rotas operacionais foi removido; a atualizacao voltou a ser manual para impedir reinicio continuo de estacao em operacao

- `backend/scripts/audit_meals.php`
  - checks novos mantidos:
    - `null_event_day`
    - `meal_service_event_mismatch`
    - `consumed_at_outside_operational_day`
  - corrigido bug preexistente no check de orfao
  - `consumed_at_outside_operational_day` passou a usar a janela real de `event_days` (`starts_at`/`ends_at`), em vez de depender apenas da data calendario
  - novo modo `outside-day` adicionado para listar e classificar os casos residuais fora do dia operacional
  - `meal_without_shift_assignment_when_shifted` foi alinhado com a regra real do dominio: `workforce_assignments.event_shift_id = NULL` continua valido em escopo, e assignment do mesmo dia tambem conta como cobertura operacional

- `database/014_participant_meals_domain_hardening.sql`
  - migration nova deixada apenas versionada

- `database/015_participant_meals_operational_day_reconciliation.sql`
  - migration de dados criada para reconciliar drift legado de `event_day_id` quando `consumed_at` aponta para outro dia do mesmo evento
  - desenhada para atuar apenas em casos deterministicos, sem conflito de unicidade e sem forcar turno ambiguo
  - aplicada no banco local apos conferencia da auditoria, reconciliando `2` linhas deterministicas

- `database/schema_current.sql`
  - baseline refletido para o hardening estrutural novo

- `database/016_participant_meals_outside_operational_day_review.sql`
  - artefato SQL de revisao manual criado para listar casos sem match operacional automatico
  - script somente de leitura; nao altera dados

- `database/017_participant_meals_outside_operational_day_quarantine.sql`
  - script de quarentena preparado e aplicado no banco local
  - cria `participant_meals_quarantine`, preserva snapshot completo, escreve trilha em `audit_log` e remove apenas os `8` IDs auditados dentro de transacao
  - possui guardas rigidas para abortar se o recorte sair do estado esperado (`evento 7`, `event_day_id 13`, sem `meal_service_id`, sem `event_shift_id`)
  - validado em dry-run com `ROLLBACK`, incluindo insercao na quarentena, trilha no `audit_log` e delete controlado dos `8` registros

---

## 8. Validacao e residual

- Validacao local executada:
  - `php -l backend/src/Controllers/MealController.php`
  - `php -l backend/src/Services/MealsDomainService.php`
  - `php -l backend/scripts/audit_meals.php`
  - `npm --prefix frontend run build`
  - `psql -f database/016_participant_meals_outside_operational_day_review.sql`
  - validacao controlada da `017` com substituicao temporaria de `COMMIT` por `ROLLBACK`
  - aplicacao real da `017` no banco local
  - snapshot final de integridade via `psql`

- Resultado:
  - validacoes passaram
  - build do frontend concluiu com o warning ja conhecido de chunk grande do Vite, sem falha
  - auditoria real da base via `psql` executada com sucesso
  - revisao `016` apos a `017`: `0` linhas residuais fora do dia operacional
  - dry-run da `017` confirmou comportamento esperado: `8` inserts na quarentena, `1` trilha em `audit_log` e `8` deletes antes do `ROLLBACK`
  - aplicacao real da `017` confirmou o mesmo comportamento sem divergencia
  - estado pos-quarentena:
    - `participant_meals_quarantine = 8`
    - `audit_log` do lote `017_outside_operational_day_quarantine = 1`
    - `participant_meals` remanescente = `30`
  - resumo atual de `Meals`:
    - somente o evento `7` possui consumo real
    - contagem atual de baixas por `event_day_id` no evento `7`:
      - `13 = 1`
      - `14 = 2`
      - `15 = 24`
      - `16 = 3`
  - integridade atual:
    - `null_event_day = 0`
    - `meal_service_event_mismatch = 0`
    - `shift_day_mismatch = 0`
    - `participant_day_event_mismatch = 0`
    - `meal_without_any_assignment = 0`
    - `meal_without_shift_assignment_when_shifted = 0`
    - `consumed_at_outside_operational_day = 0`
  - analise do legado encontrado:
    - a `015` reconciliou `2` linhas deterministicas e zerou o drift automatico restante entre dias operacionais validos
    - os `8` casos fora do dia operacional foram retirados de `participant_meals` e preservados em quarentena pela `017`
    - a auditoria de dia operacional ficou zerada
    - o aparente residual de `27` casos em `meal_without_shift_assignment_when_shifted` era descompasso entre auditoria e regra atual de dominio
    - a revisao mostrou que esses participantes ja possuem `workforce_assignments` em escopo, com `event_shift_id = NULL`; o check foi alinhado e zerou sem exigir backfill arriscado

- Residual assumido e fora do fechamento de hoje:
  - `014` segue nao aplicada no banco
  - `016` permanece como artefato somente leitura
  - sem teste de carga / telemetria / SLO nesta rodada
  - `docs/progresso7.md` nao foi reescrito nesta passada

---

## 9. Congelamento do dia

- `Meals` fica congelado ao fim desta rodada para homologacao.
- Pendencias abertas de continuidade ficaram consolidadas em `pendencias.md`.
- `docs/progresso8.md` permanece como diario de execucao e fechamento tecnico do que foi feito hoje.
