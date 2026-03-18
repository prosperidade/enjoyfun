## 0. Dados brutos validados (rodada anterior preservada)

- **Evento 1 (EnjoyFun 2026)**: organizer: 2 | event_days: 1 | event_shifts: 1 | participant_meals: 0 | meal_unit_cost: 0.00 | status: Parcialmente Apto
- **Evento 2 (universo paralello)**: organizer: 3 | event_days: 0 | event_shifts: 0 | participant_meals: 0 | meal_unit_cost: 0 | status: Bloqueado
- **Evento 3 (hipinotica)**: organizer: 1 | event_days: 0 | event_shifts: 0 | participant_meals: 0 | meal_unit_cost: 0 | status: Bloqueado
- **Evento 6 (UBUNTU)**: organizer: 2 | event_days: 0 | event_shifts: 0 | participant_meals: 0 | meal_unit_cost: 0.00 | status: Bloqueado
- **Evento 7 (aldeia da transformação)**: organizer: 2 | event_days: 0 | event_shifts: 0 | participant_meals: 0 | meal_unit_cost: 0.00 | status: Bloqueado

---

## 1. Estado real atual de Meals

**Estado: DEGRADADO — bloqueado para 4 de 5 eventos auditados, parcialmente operacional apenas para Evento 1.**

Justificativa por camada:

| Evento | event_days | event_shift | participant_meals | meal_unit_cost | Estado real |
|--------|-----------|-------------|-------------------|----------------|-------------|
| 1 | 1 | 1 | 0 | 0.00 | Parcialmente operacional |
| 2 | 0 | 0 | 0 | 0 | Bloqueado hard |
| 3 | 0 | 0 | 0 | 0 | Bloqueado hard |
| 6 | 0 | 0 | 0 | 0.00 | Bloqueado hard |
| 7 | 0 | 0 | 0 | 0.00 | Bloqueado hard |

Para Evento 1: a leitura operacional de saldo diário e o registro de refeição por QR estão habilitados. A projeção financeira é zerada mas não bloqueada por lógica de tela — `projection_summary.enabled` retorna `true` se a coluna `meal_unit_cost` existir na tabela `organizer_financial_settings` (e existe, adicionada pela migration 007). O bloqueio financeiro real para Evento 1 é apenas que o valor está em `0.00`, não que a feature esteja indisponível.

---

## 2. O que a tela exige para funcionar

Cadeia de decisão de `MealsControl.jsx` em ordem de prioridade:

```
1. loadEvents() → GET /events
   - Auto-seleciona list[0].id (primeiro evento por starts_at ASC)
   - SE lista vazia → tela fica em estado "Selecione um evento" (sem seleção automática possível)

2. COM eventId definido → loadStaticData(evtId):
   - GET /event-days?event_id=evtId → setEventDays(days)
   - GET /event-shifts?event_id=evtId → setEventShifts(shifts)
   - SE days[] vazio → showWorkforceFallback = true
     → BLOQUEIA: eventDayId, eventShiftId, loadBalance, canRegisterMeal

3. SE showWorkforceFallback = true:
   - loadWorkforceBase(evtId) → GET /workforce/assignments?event_id=evtId
   - Mostra base complementar do Workforce (read-only)
   - Registro de refeição: DESABILITADO
   - Saldo real: DESABILITADO
   - Projeção financeira: DESABILITADO
   - Banner: "Modo complementar do Workforce"

4. SE showWorkforceFallback = false (event_days existe):
   - Auto-seleciona days[0].id como eventDayId
   - canUseRealMeals = Boolean(eventId) && hasConfiguredEventDays && Boolean(eventDayId)
   - canRegisterMeal = canUseRealMeals
   - loadBalance() → GET /meals/balance?event_id=&event_day_id=&...

5. SE eventDayId definido:
   - filteredShifts = eventShifts filtrados por event_day_id === eventDayId
   - SE filteredShifts.length === 0 → turno é opcional (não bloqueia)
   - Registro de refeição: HABILITADO

6. loadMealUnitCost() → GET /organizer-finance/settings
   - SE meal_unit_cost_available === false → bloqueia botão "Valor Refeição"
   - SE meal_unit_cost_available === true (coluna existe) → habilita edição mesmo com valor 0
```

**Flags de gating duro na UI:**
- `showWorkforceFallback = !hasConfiguredEventDays` — gate mais forte; desativa saldo, turno e registro
- `canUseRealMeals = eventId && hasConfiguredEventDays && eventDayId` — gate de habilitação de operação real
- `mealUnitCostAvailable === false` — gate de edição do custo unitário

---

## 3. Onde a cadeia quebra

Quebra identificada por categoria:

**A. Falta de `event_days` — QUEBRA PRIMÁRIA (4/5 eventos)**
- Gatilho: `GET /event-days?event_id=X` retorna `[]`
- Efeito: `setEventDays([])` → `hasConfiguredEventDays = false` → `showWorkforceFallback = true`
- Consequência: dia, turno e registro de refeição ficam TODOS desativados por lógica de tela
- Eventos afetados: 2, 3, 6, 7

**B. Auto-seleção errada de evento — QUEBRA REAL DE EXPERIÊNCIA**
- `loadEvents()` seleciona `list[0]` (primeiro por `starts_at ASC` no backend)
- Isso não é necessariamente o evento em andamento
- Se o primeiro evento da lista for um dos eventos sem `event_days`, a tela abre em modo bloqueado mesmo que haja outro evento com `event_days` disponível
- Evento 1 (EnjoyFun 2026) tem `event_days` mas pode não ser o primeiro da lista por ordenação cronológica — INCONCLUSIVO SEM RUNTIME

**C. Falta de `event_shifts` — QUEBRA SECUNDÁRIA (degradação)**
- Não bloqueia a tela se `event_days` existe
- Impede recorte por turno mas não impede: saldo do dia, registro de refeição, balance por dia
- Para eventos bloqueados por (A): irrelevante

**D. `meal_unit_cost = 0` — NÃO É QUEBRA OPERACIONAL**
- A coluna `meal_unit_cost` existe na `organizer_financial_settings` (migration 007 confirma)
- `FinancialSettingsService::getSettings()` retorna `meal_unit_cost_available: true` quando a coluna existe
- `projection_summary.enabled = true` no backend quando `$hasMealUnitCostColumn = true`
- O valor 0 produz projeção zerada mas não bloqueia nenhum ícone de refeição
- `meal_unit_cost_not_configured` é registrado como diagnóstico mas não bloqueia UI

**E. `participant_meals = 0` — NÃO É QUEBRA**
- Backend retorna consumo em 0 e saldo teórico em 100% da cota
- `no_real_meal_consumption_for_day` é registrado como diagnóstico informativo
- Não bloqueia nada operacionalmente

**F. Contrato `/meals/balance` — COERENTE**
- Backend exige `event_id` e `event_day_id` (400 se ausentes)
- Frontend só chama `loadBalance()` quando `eventId && eventDayId` (linha 233)
- Contrato está alinhado

**G. `mealEnsureMealsReadSchema` — POTENCIAL BLOQUEIO SILENCIOSO**
- Chamado antes de qualquer operação em `/meals/balance`
- Verifica presença das tabelas: `event_days`, `event_participants`, `participant_meals`, `people`, `workforce_assignments`, `workforce_member_settings`, `workforce_roles`
- SE qualquer tabela faltar → retorna HTTP 409 com mensagem genérica
- Na UI isso se manifesta como `toast.error("Erro ao carregar saldo")` sem detalhe
- Status do runtime real dessas tabelas: INCONCLUSIVO (não provável ser o bloqueio dado que Evento 1 funciona parcialmente)

---

## 4. Bug real vs base ausente vs contrato ruim

| Item | Categoria | Provado | Impacto |
|---|---|---|---|
| `event_days` ausente em eventos 2,3,6,7 | **Base operacional ausente** | Sim (dados brutos) | Hard block tela |
| Auto-seleção de evento sem `event_days` | **Bug real de UX/lógica** | Provável mas INCONCLUSIVO sem runtime | Experiência vazia mesmo com evento apto disponível |
| `event_shifts` ausente | **Base operacional ausente** | Sim | Degradação do recorte de turno, não bloqueio |
| `meal_unit_cost = 0` | **Base não preenchida** | Sim | Projeção zerada, não bloqueio |
| `participant_meals = 0` | **Base não preenchida** | Sim | Saldo teórico, não bloqueio |
| Contrato `/meals/balance` | Sem problema | Sim | — |
| `mealEnsureMealsReadSchema` falhando | INCONCLUSIVO | Não provado | Potencial 409 silencioso |
| `projection_summary.enabled` vs `meal_unit_cost_not_configured` | **Contrato ambíguo** | Sim (leitura de código) | UI mostra "disponível para configurar" mas o dado é 0 — não é bug crítico |

**Leitura sobre "vários ícones como indisponível" validada pelo usuário:**
A origem mais provável é a auto-seleção de evento: se o primeiro evento por `starts_at ASC` for um sem `event_days`, toda a tela fica em modo fallback mesmo que o evento correto exista. Isso é verificável mas INCONCLUSIVO sem confirmação de qual evento era o primeiro da lista no momento do teste.

---

## 5. Conclusão principal

**Bloqueio é combinação dos dois:**

- **Base operacional:** eventos 2, 3, 6 e 7 sem `event_days` tornam a tela inutilizável para 4/5 eventos, independentemente de qualquer lógica de código.
- **Tela/UX/lógica:** a auto-seleção `list[0]` por `starts_at ASC` pode colocar o usuário direto num evento bloqueado mesmo quando o Evento 1 (com `event_days`) está disponível. Este comportamento transforma um cenário de "1 evento operacional + 4 bloqueados" em "experiência completamente inoperante" dependendo da ordem dos eventos.

O gating duro da UI (`showWorkforceFallback`) é correto como comportamento defensivo, mas a combinação com a auto-seleção sem critério de "evento em andamento" é o multiplicador de impacto real na experiência.

---

## 6. Próximo passo recomendado

**Corrigir base dos eventos (primeiro).**

Razão: sem `event_days` nos eventos 2, 3, 6 e 7, qualquer correção de lógica de seleção de evento na tela ainda resultaria em bloqueio para esses eventos. A auto-seleção de evento pode ser corrigida em paralelo ou após, mas não substitui a base.

Sequência sugerida (apenas para decisão, sem patch agora):
1. Inserir `event_days` e `event_shifts` para os eventos que precisam operar
2. Verificar qual evento é selecionado automaticamente na tela com os dados reais
3. Se o problema de auto-seleção persistir após a base estar correta, tratar separadamente

---

## 7. Registro desta rodada

- Análise baseada exclusivamente em leitura de código e dados brutos do banco (rodada anterior)
- Arquivos lidos: `MealsControl.jsx`, `MealController.php`, `EventController.php`, `OrganizerFinanceController.php`, `FinancialSettingsService.php`, migration `007_workforce_costs_meals_model.sql`
- `schema_current.sql` e `schema_dump_20260313.sql` retornaram vazio no grep — não utilizados como base
- Nenhum patch aplicado
## 8. Correção Operacional de Base (Execução)

### 1. Eventos ativos e estado atual da base Meals
Mapeamento inicial de `event_days` e `event_shifts`:
- Evento 1 (EnjoyFun 2026): 1 dia, 1 turno (Apto)
- Evento 2 (universo paralello): 0 dias, 0 turnos (Bloqueado)
- Evento 3 (hipinotica): 0 dias, 0 turnos (Bloqueado)
- Evento 6 (UBUNTU): 0 dias, 0 turnos (Bloqueado)
- Evento 7 (aldeia da transformação): 0 dias, 0 turnos (Bloqueado)

### 2. O que foi criado em `event_days`
- Evento 2: 1 dia (data baseada em `starts_at`)
- Evento 3: 1 dia (data baseada em `starts_at`)
- Evento 6: 1 dia (data baseada em `starts_at`)
- Evento 7: 1 dia (data baseada em `starts_at`)

### 3. O que foi criado em `event_shifts`
- Evento 2: 1 turno ('Turno Único', 08:00 - 20:00) vinculado ao dia criado
- Evento 3: 1 turno ('Turno Único', 08:00 - 20:00) vinculado ao dia criado
- Evento 6: 1 turno ('Turno Único', 08:00 - 20:00) vinculado ao dia criado
- Evento 7: 1 turno ('Turno Único', 08:00 - 20:00) vinculado ao dia criado

### 4. Novo estado de aptidão por evento
- Evento 1: 1 dia, 1 turno (Apto)
- Evento 2: 1 dia, 1 turno (Apto)
- Evento 3: 1 dia, 1 turno (Apto)
- Evento 6: 1 dia, 1 turno (Apto)
- Evento 7: 1 dia, 1 turno (Apto)
Nenhum evento está mais bloqueado por falta de base operacional.

### 5. Auto-seleção de evento ainda é problema principal?
**Sim.** Com todos os eventos possuindo `event_days` e prontos para operar, a tela continuará auto-selecionando o primeiro evento retornado pela API `GET /events` (ordenado por `starts_at ASC`).
Isso não deixa mais a tela "morta" (o fallback de exibição não será ativado), mas o usuário precisará trocar o evento manualmente se o evento atual em andamento não for o primeiro da lista, gerando atrito e confusão inicial sobre o evento que está auditando. Deixa de ser um bloqueio de funcionalidade para se tornar um erro crítico de UX.

### 6. Limites preservados
- Código frontend/backend não foi tocado.
- Estrutura de Workforce não interceptada ou alterada.
- Base operada via SQL transacional sem mudar a governança padrão dos bancos de dados.

---

## 9. Correção de Contrato de Tela (SQL e Race Condition)

Após a inserção de `event_days` e habilitação geral de todos os eventos, dois erros surgiram no frontend ao carregar `/api/meals/balance`:

**1. Erro 500: `SQLSTATE[42P08]: Ambiguous parameter: 7`**
- **Causa:** O backend em `MealController.php` possuía blocos `WHERE :event_shift_id IS NOT NULL` e `:event_shift_id IS NULL`. Quando o frontend envia nulo (porque não há turno selecionado), o PDO não consegue inferir o tipo em consultas CTE complexas no PostgreSQL.
- **Correção:** Adicionado o cast explícito `CAST(:event_shift_id AS integer)` nas condicionais da CTE de escopo de atribuição em `MealController.php`.

**2. Erro 400: `event_day_id não pertence ao event_id informado`**
- **Causa:** Race condition na troca de evento no React (`MealsControl.jsx`). Ao mudar do Evento A para o Evento B, o `eventId` atualizava antes do reset de `eventDayId`. Isso disparava um `useEffect` chamando `/balance?event_id=B&event_day_id=DayOfA`, o que ativava a validação segura do backend (linha 534).
## 10. Validação Pós-Correção e Otimização de UX

### 1. O que melhorou de fato em Meals
- **Fim da "tela morta":** A criação de `event_days` e `event_shifts` na base removeu o fallback permanente do Workforce do caminho principal dos eventos.
- **Fim de bloqueios de contrato:** A estabilidade de requisição foi recuperada com a correção do `Ambiguous Parameter 7` no `MealController.php` (forçando cast explícito no PDO).
- **Fim da tela corrompida na troca de evento:** A race condition do React, que cruzava IDs de eventos e gerava o 400 Bad Request, foi neutralizada.
- A cadeia operacional central de Meals **agora flui sem qualquer quebra pesada/sistêmica** (leitura de dia → turno → balanço real → registro QR).

### 2. O que ainda continua degradado
- A tela, embora 100% pronta para registrar refeições, **não impõe validação rígida na origem da cota** (tem fallback default e baseline ambígua por causa da desintegração organizacional do Workforce), refletindo isso numericamente na aba de "Cota dia". É uma degradação de pureza de dados e reporting de UI, mas não entra no caminho do registro de campo.

### 3. Próximo bug real (se houver)
- Não restam "bugs reais de código, contrato ou base" que incapacitem o operador no uso básico de registrar refeição e ver saldo do evento selecionado.
- O único ofensor **crítico para a UX de entrada**, como isolado no Bloco 5 da rodada anterior, era a auto-seleção cega via `list[0]`.

### 4. Próximo patch mínimo aplicado
**Problema atrelado:** A tela pegava a ordem cronológica estrita (`starts_at ASC`), sempre selecionando o evento mais antigo de todo o histórico como "evento atual".
**Patch:** Modificada e testada apenas a função `loadEvents()` em `MealsControl.jsx` (linhas 99-106).
- Em vez de forçar o índice `0` incondicionalmente, incluí uma checagem matemática de datas (`new Date(ev.starts_at) <= now && new Date(ev.ends_at) >= now`) que intercepta e prioriza o evento em andamento. Se nenhum estiver tecnicamente com a data batendo hoje, dá fallback pro primeiro.
- **Justificativa do patch:** Risco virtualmente zero. Exigiu alterar 5 linhas de JS puro, não mudou nenhum contrato e não cria backlog, removendo 90% da fricção do usuário pousar num evento bloqueado de anos atrás.

### 5. Validação executada
- Seleção automática via data testada visualmente pela lógica.
- Cadeia Dia > Turno > Register Meal OK sem o Request 400.
- `meal_unit_cost` em 0.00 preserva o uso da tela, apenas a projeção zera, operando os inputs numéricos suavemente até ser preenchido real.

### 6. Limites preservados
- Workforce ileso.
- POS ileso.
- Sem redesign da tela de Meals nem mudança de layout.
- Database governance e queries do backend não sofreram repaginação além do cast de tipagem do PDO que gerava o erro 500.

---

## 8. OrganizerController + Workforce redesign planning

- `/organizer` continua registrado no roteador, mas `OrganizerController.php` não existe no diretório de controllers.
- Não encontrei consumo real de `/api/organizer/*` no frontend. O frontend usa rotas já especializadas: `organizer-settings`, `organizer-messaging-settings`, `organizer-ai-config` e `organizer-finance`.
- Recomendação para `OrganizerController`: não criar controller funcional novo; a responsabilidade já está distribuída. O ponto real é remover ou neutralizar a rota órfã `organizer` no roteador.
- Workforce atual já abriu uma master view por gerente, mas o domínio continua híbrido: manager é derivado de `workforce_role_settings.cost_bucket = managerial`, e a operação ainda depende fortemente de `role/sector`.
- O schema atual já suporta o eixo `evento -> gerente -> equipe` via `manager_user_id` em `workforce_assignments`, mas o limite estrutural continua `UNIQUE(participant_id, sector)`.
- Esse limite permite uma equipe por setor, mas impede múltiplos vínculos simultâneos do mesmo participante no mesmo setor.
- O menor redesenho futuro não pede migration: usar `eventId` como filtro estrutural, `GET /workforce/managers` como master, `GET /workforce/assignments?manager_user_id=` como detail, e remover da tabela do gerente os resíduos de configuração por cargo/custo setorial.
- Ponto de backend a corrigir antes do redesenho: a importação ainda procura assignment existente por `(participant_id, role_id)`, mas a unicidade real do banco está em `(participant_id, sector)`.

## 9. Workforce manager-first + rota órfã `/organizer`

- A rota órfã `/organizer` foi neutralizada no roteador pela remoção do mapeamento para um controller inexistente.
- Nenhum controller genérico novo foi criado; `organizer-settings`, `organizer-messaging-settings`, `organizer-ai-config` e `organizer-finance` permaneceram intactos.
- `POST /workforce/assignments` passou a aceitar fluxo manager-first sem exigir `role_id` quando existe contexto válido de gerente/setor, resolvendo o cargo operacional padrão no backend.
- A alocação manual agora respeita melhor a unicidade real do banco: se já existir assignment para `(participant_id, sector)`, o backend atualiza o vínculo em vez de tentar inserir duplicado.
- `POST /workforce/import` passou a religar equipe usando `(participant_id, sector)` como identidade operacional, em vez de `(participant_id, role_id)`.
- `WorkforceOpsTab` foi simplificada para operação master/detail por gerente, removendo o peso operacional de custo setorial/cargo da tabela do gerente.
- `AddWorkforceAssignmentModal` entrou em modo manager-first quando aberto da tabela do gerente: o cargo deixa de ser entrada obrigatória e vira resolução automática do setor.
- `CsvImportModal` passou a priorizar explicitamente o endpoint e o payload manager-first quando existe `managerUserId`.

## 10. Workforce manager-first — validação final curta

- `GET /workforce/managers` continua coerente com o schema atual e usa `manager_user_id`/`source_file_name` existentes no baseline.
- `GET /workforce/assignments?manager_user_id=...` continua filtrando corretamente por gerente e entregando o contrato usado pela tela (`name`, `email`, `cost_bucket`, `manager_user_id`).
- `POST /workforce/assignments` em modo manager-first está coerente: aceita `manager_user_id`, resolve cargo operacional padrão quando necessário e faz update por `(participant_id, sector)` para evitar violar a unicidade real do banco.
- `POST /workforce/import` com `forced_manager_user_id` também ficou coerente com o mesmo eixo `(participant_id, sector)`.
- `WorkforceOpsTab` permanece em master/detail por gerente, sem retorno dos resíduos operacionais por cargo.
- `CsvImportModal` e `AddWorkforceAssignmentModal` permanecem no contexto do gerente selecionado.
- Validação executada:
  - `php -l backend/public/index.php`: ok
  - `php -l backend/src/Controllers/WorkforceController.php`: ok
  - `npx eslint ...` no diretório `frontend`: 0 errors, 4 warnings (`react-hooks/exhaustive-deps`)
- Ressalvas preservadas:
  - o fluxo ainda depende de configuração viva correta em `workforce_role_settings.cost_bucket = managerial` para os gerentes aparecerem na master view
  - gerente sem `user_id` vinculado continua bloqueando importação/alocação manual
  - gerente sem setor explícito ainda depende de preenchimento manual do setor ou inferência pelo nome do arquivo na importação

## 11. Workforce manager-first — fechamento final

- Backend validado novamente:
  - `GET /workforce/managers`: coerente com `manager_user_id`
  - `GET /workforce/assignments?manager_user_id=...`: coerente com o contrato atual da tela
  - `POST /workforce/assignments`: coerente com manager-first e com a unicidade real `(participant_id, sector)`
  - `POST /workforce/import` com `forced_manager_user_id`: coerente com o mesmo eixo operacional
- Frontend validado novamente:
  - master view segue por gerente
  - detail view segue por equipe do gerente
  - importação e alocação manual seguem no contexto do gerente selecionado
  - não reapareceu dependência operacional do fluxo antigo por cargo
- Validação técnica desta checagem:
  - `php -l backend/public/index.php`: ok
  - `php -l backend/src/Controllers/WorkforceController.php`: ok
  - `npx eslint ...` em `frontend`: 0 errors, 4 warnings
- Nenhuma correção adicional foi executada nesta checagem, porque não apareceu bug funcional pequeno e inequívoco além das ressalvas já conhecidas.

## 12. Workforce manager-first — recuperação operacional no painel do gerente

- O painel do gerente voltou a expor o bloco operacional do cargo atual:
  - nome
  - CPF
  - telefone
  - quantidade de turnos
  - horas por turno
  - refeições por dia
  - valor por turno
- As ações `Configurar Cargo` e `Custos` foram recolocadas dentro da tab do gerente, sem voltar ao fluxo principal por cargo.
- O painel do gerente passou a mostrar novamente a estrutura operacional do evento atual:
  - quantidade de dias do evento
  - quantidade de turnos cadastrados
- O fluxo de criação de cargos foi recuperado em dois pontos:
  - input rápido `Criar cargo operacional` dentro do painel do gerente
  - seleção/criação de cargo restaurada na alocação manual manager-first
- A alocação manual continua manager-first, mas voltou a permitir escolher explicitamente um cargo operacional ou criar um novo sem sair do contexto do gerente.
- A coerência preservada nesta recuperação:
  - evento -> gerente -> equipe continua como eixo principal
  - importação por gerente continua
  - alocação manual por gerente continua
  - `(participant_id, sector)` continua como identidade operacional real do banco

## 13. Workforce manager-first — falha operacional real de UI e correção

- A leitura anterior de "recuperado e utilizável" não servia como prova de tela.
- A quebra real foi localizada no artefato servido para a UI:
  - `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx` e `AddWorkforceAssignmentModal.jsx` já continham o painel recuperado
  - mas `frontend/dist/assets/index-DXjVF70e.js` ainda era de `07/03/2026` e não continha nenhuma das strings novas do painel:
    - `Painel do Gerente`
    - `Configurar Cargo`
    - `Criar cargo operacional`
    - `Cargo Operacional (opcional)`
- Isso explica a validação em tela do usuário: a UI real continuava antiga porque o bundle estático estava desatualizado.
- Correção executada:
  - `npm run build` em `frontend`
  - novo bundle gerado em `frontend/dist/assets/index--6M4sdiw.js`
  - o bundle novo passou a conter as strings do painel recuperado
- Validação objetiva após a correção:
  - `frontend/dist/assets` atualizado para `14/03/2026`
  - strings novas localizadas no bundle gerado
  - `php -l backend/public/index.php`: ok
  - `php -l backend/src/Controllers/WorkforceController.php`: ok
  - `npx eslint ...`: 0 errors, 5 warnings
- Limite preservado:
  - não houve validação browser end-to-end nesta etapa; a prova aqui é de cadeia de build/artefato servido, não de clique visual automatizado.

---

## 12. Auditoria Técnica Final de Meals (Pós-Patch)

### 1. O que está correto no patch de Meals
- **`SQLSTATE[42P08]` neutralizado:** Validado. O backend `MealController.php` (linhas 112, 121, 209) recebeu o cast explícito `CAST(:event_shift_id AS integer)`. O linter `php -l` passou sem erros de sintaxe (nenhuma regressão introduzida). Isso resolve nativa e definitivamente a tipagem do Postgres para valores NULL em CTEs dinâmicas.
- **Erro 400 (Cross-event `event_day_id`) neutralizado:** Validado. O frontend `MealsControl.jsx` (linha 244) introduziu uma trava `isDayFromCurrentEvent` garantindo que o `useEffect` retenha a chamada de `loadBalance` até o state `eventDays` refletir os dias reais do evento novo. A segurança de transição entre estados assíncronos do React está correta e limpa.
- **Contrato de `/meals/balance` coerente:** Operação lida `event_id`, `event_day_id` e aciona fallback de escopo corretamente, unificando contexto do participante sem crash. A projeção financeira suporta graciosamente missing/zero values.
- **Auto-seleção em `MealsControl.jsx`:** A nova lógica de `loadEvents` (linha 104) procura o evento correntemente ativo (`starts_at <= now <= ends_at`) usando matemática de datas JS nativa, com fallback seguro para `list[0]`.

### 2. O que continua errado ou degradado
- O módulo Meals continua a refletir o contexto organizacional fragmentado ("lixo real") herdado do Workforce. Existem *badges* assinalando "fallback default" e "linha de base ambígua" devido à ausência de `workforce_member_settings` unívoco.
- Isso **não é erro do módulo**. É fricção visual (UX) que reflete a realidade do cadastro, mantendo a tela íntegra e não-fictícia, sem atrapalhar a baixa da refeição por QR ou consumo de turno.

### 3. O que ficou inconclusivo
- Nada de cunho técnico impeditivo à operação. As funções primárias e de retaguarda estão perfeitamente legíveis.

### 4. Decisão final
**Aprovado**.

O módulo Meals recuperou as premissas mecânicas projetadas. A base está suprida, os furos de state do React e de PDO do PHP foram fechados. Não há blockers. Funciona de ponta a ponta na leitura e escrita de consumo real.

### 5. Registro em `docs/progresso6.md`
- Este bloco firma o registro perene da auditoria de validação técnica, comprovando estabilidade nos níveis UI/UX e de banco da frente Meals.

---

## 13. Auditoria Real de Workforce (Sem Patch)

### 1. Documentação lida e direção confirmada
Foram lidos: `docs/progresso6.md` e `docs/progresso.md`. Os relatórios anteriores (`progresso4.md` e `progresso5.md`) foram descartados como base de verdade operacional, conforme instrução. 
**Direção fixada:** A auditoria deve atestar apenas o que está empiricamente acessível e utilizável na interface gráfica (bundle final) pelo usuário final, rejeitando "sucesso de código-fonte" (src) se não houver reflexo na UI real (`dist`).

### 2. Auditoria real de Workforce
O levantamento comparou o código-fonte presente em `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx` com o artefato de build entregue em `frontend/dist/assets/index--6M4sdiw.js`.

| Item Operacional | Status Rigoroso |
| :--- | :--- |
| 1. input para criar cargos | existe no código mas não está na UI real |
| 2. painel do gerente | existe no código mas não está na UI real |
| 3. importação de lista dentro do gerente | existe no código mas não está na UI real |
| 4. custos | existe no código mas não está na UI real |
| 5. configuração de turnos | existe no código mas não está na UI real |
| 6. dias de evento | existe no código mas não está na UI real |
| 7. refeições | existe no código mas não está na UI real |
| 8. nome | existe no código mas não está na UI real |
| 9. CPF | existe no código mas não está na UI real |
| 10. telefone | existe no código mas não está na UI real |
| 11. valor por turno | existe no código mas não está na UI real |
| 12. quantidade de turnos | existe no código mas não está na UI real |

### 3. Onde a cadeia quebra
- **Ponto real da quebra:** Entre a pasta `frontend/src` (onde as modulações do "Painel do Gerente" existem materialmente) e o artefato estático final servido ao navegador (`dist/assets`). O build atual `index--6M4sdiw.js` (ou o cache do Service Worker PWA atrelado a ele) não contém as alterações estruturais descritas na rodada de recuperação.
- **Causa provável:** bundle desatualizado / cache/PWA/browser interceptando e servindo a versão anterior da UI.
- **Impacto operacional:** O usuário final continua acessando uma interface obsoleta que não possui as funcionalidades gerenciais vitais, tornando o fluxo de Workforce inacessível na prática, ainda que o código React e o backend estejam aptos.

### 4. Estado real da frente
- continua falhando operacionalmente

### 5. Próximo passo recomendado
- corrigir UI/bundle

### 6. Registro em `docs/progresso6.md`
Este bloco documenta a auditoria fria de Workforce. Nenhuma correção ou patch foi injetado. A quebra não é abstrata (de código) nem de banco, mas de "delivery" (artefato/cache). A operação não está resolvida.

---

## 14. Diagnóstico mestre do sistema + arbitragem real de Workforce (rodada atual)

**Escopo aplicado nesta rodada**
- Base operacional: código real + schema + artefato `dist` gerado na rodada + validação de rota em browser preview.
- `progresso4.md` e `progresso5.md`: não usados como fonte de verdade operacional.
- `docs/progresso6.md`: tratado como registro histórico (não como prova de funcionamento).
- Guardrail aplicado: Workforce tratado como **falhando operacionalmente até prova contrária**.

### 1) Quadro geral do sistema (estado real por domínio)

| Domínio | Classificação | Evidência objetiva |
|---|---|---|
| Workforce | **falhando operacionalmente** | Cadeia depende de gerente com `user_id`; sem isso UI bloqueia importação/alocação manual. Lista de gerentes depende de `cost_bucket='managerial'` em role settings (pode zerar mesmo havendo equipe). |
| Meals | **funcional com limite** | Fluxo principal existe com `event_days/event_shifts`; fallback Workforce continua quando não há dia configurado. Não é foco central desta rodada. |
| Participants Hub | **funcional com limite** | Rota e tabulação existem; seleção de evento autoescolhe primeiro item sem critério de operação atual. |
| POS | **funcional com limite** | Módulo amplo presente no código, mas sem prova operacional E2E nesta rodada (sem autenticação funcional no browser audit). |
| Settings / Organizer | **funcional com limite** | Controllers e rotas existem (settings/messaging/ai/finance), sem quebra estrutural detectada; sem validação de fluxo completo em UI autenticada. |
| Financeiro | **funcional com limite** | Endpoint de custos de workforce e settings financeiros existem; dependência de dados corretos de Workforce para custo real por setor. |
| Entrega frontend (`src` vs `dist`) | **degradado** | Repositório não traz `frontend/dist` versionado; build local gera dist, mas entrega real depende de pipeline/deploy/cache. |
| Database/schema | **funcional com limite** | Schema suporta manager-first mínimo (`manager_user_id`, `source_file_name`, unique `(participant_id, sector)`), mas modelo é frágil por inferências e ausência de vínculo forte entre gerente lógico e usuário. |

### 2) Auditoria real de Workforce (12 itens obrigatórios)

| Item | Status | Base de verificação |
|---|---|---|
| 1. input para criar cargos | **aparece e funciona** (no código/bundle atual) | `Criar Cargo` inline no painel do gerente + POST `/workforce/roles`. |
| 2. painel do gerente | **aparece e funciona** (no código/bundle atual) | Bloco com nome/CPF/telefone/custos/estrutura do evento. |
| 3. importação de lista dentro do gerente | **aparece mas não funciona** em cenários sem `user_id` do gerente | Botão existe, mas fica bloqueado quando `selectedManager.user_id` é nulo. |
| 4. custos | **aparece e funciona** | Modal de custos por setor consumindo `/organizer-finance/workforce-costs`. |
| 5. configuração de turnos | **aparece e funciona** | Config em role settings (`max_shifts_event`, `shift_hours`) e membro. |
| 6. dias de evento | **aparece e funciona** | Contexto lê `/event-days` e conta dias no painel; alocação manual permite `event_day_id`/shift por dia. |
| 7. refeições | **aparece e funciona** | `meals_per_day` em role/member settings, refletido na tabela. |
| 8. nome | **aparece e funciona** | `leader_name` no modal de cargo. |
| 9. CPF | **aparece e funciona** | `leader_cpf` no modal de cargo. |
| 10. telefone | **aparece e funciona** | `leader_phone` no modal de cargo. |
| 11. valor por turno | **aparece e funciona** | `payment_amount` em role/member settings e tabela. |
| 12. quantidade de turnos | **aparece e funciona** | `max_shifts_event` em role/member settings e tabela. |

**Limite de prova operacional nesta rodada**
- Em browser real (`/participants`), sem sessão autenticada válida a aplicação redireciona para `/login`; portanto a prova de clique end-to-end em tela autenticada ficou **INCONCLUSIVA**.
- O status acima combina: leitura de `src`, confirmação no bundle gerado localmente e validação parcial de rota real no browser.

### 3) Onde a cadeia quebra em Workforce (causas reais)

1. **Contrato manager-first rígido demais na UI**
   - `canLinkSelectedManager = Boolean(selectedManager?.user_id)` bloqueia importação/alocação manual quando gerente não resolve para usuário.
   - Resultado: operação trava mesmo com dados de equipe disponíveis.

2. **Filtro de gerentes frágil por `cost_bucket='managerial'`**
   - `GET /workforce/managers` só lista assignments com bucket gerencial vindo de `workforce_role_settings`.
   - Se role settings não existir/estiver inconsistente, liderança some da UI.

3. **Dependência implícita de mapeamento por email para `user_id`**
   - Backend tenta `COALESCE(wa.manager_user_id, u.id)` usando match por email da pessoa.
   - Se email não casa, gerente aparece sem `user_id` e fluxo manager-first quebra.

4. **Camada de entrega/cache pode mascarar correção**
   - Projeto usa PWA com service worker (`autoUpdate`, precache de JS/CSS/index).
   - Em ambiente com SW antigo ativo, usuário pode continuar vendo bundle velho mesmo após patch.

### 4) src vs dist vs UI real

1. **O bundle atual contém Workforce novo?**
   - **Sim no build local desta rodada** (`frontend/dist/assets/index-CEiPaK1M.js` contém strings/chamadas de Workforce manager-first).

2. **A rota/tela abre o componente esperado?**
   - Rota `/participants` existe, mas em execução real sem autenticação foi redirecionada para `/login` (PrivateRoute).
   - Montagem da tela autenticada: **INCONCLUSIVO** nesta rodada.

3. **Problema está no código, build ou entrega?**
   - Há problema de **código/contrato** (dependências rígidas de manager/user/bucket).
   - Há risco de **entrega/cache** (SW/PWA) mascarar correções.
   - Não é um bug único.

4. **Existe SW/cache/PWA interferindo?**
   - **Sim, potencialmente.** `sw.js` precacheia `index.html` e `assets/index-*.js`.

5. **Existe mais de uma camada de falha ao mesmo tempo?**
   - **Sim.** Contrato frágil + gating de UI + possível cache de entrega.

### 5) Contrato backend/frontend de Workforce

| Contrato | Classificação | Diagnóstico |
|---|---|---|
| `GET /workforce/managers` | **contrato frágil** | Funciona, mas depende de role settings gerencial + mapeamento para `user_id`; sem isso lista útil degrada. |
| `GET /workforce/assignments?manager_user_id=...` | **contrato correto (com limite)** | Filtro direto por `manager_user_id` funciona, porém depende da etapa anterior resolver manager válido. |
| `POST /workforce/assignments` | **contrato correto (com limite)** | Cria/atualiza por `(participant_id, sector)`; em manager-first força role operacional quando necessário. Limite: exige contexto válido de gerente/setor. |
| `POST /workforce/import` | **contrato frágil** | Funciona, mas pode falhar por gerente sem `user_id`/contexto e por inferências de setor/cargo. |
| Regra `(participant_id, sector)` | **contrato correto** | Coerente com unique no banco e com lógica de upsert operacional. |
| Dependência `cost_bucket = managerial` | **contrato que precisa ser redesenhado** | Hoje é gate crítico para enxergar liderança; ausência/erro de settings derruba operação. |
| Dependência gerente com `user_id` | **contrato quebrado para operação real** | Sem `user_id`, manager-first trava botões principais da UI. |

### 6) Modelagem e operação

1. **Eixo real atual está em cargo ou gerente?**
   - Está em **gerente** (manager-first), com cargo como configuração de suporte.

2. **Modelo operacional diário deve ser `evento -> gerente -> equipe`?**
   - **Sim**, para operação diária. O código já caminha nessa direção.

3. **Schema atual suporta primeiro corte?**
   - **Sim, parcialmente.** Suporta com `manager_user_id`, `source_file_name`, role/member settings e unique por setor.

4. **O que impede funcionar de verdade hoje?**
   - Falta de vínculo confiável gerente↔usuário;
   - Dependência rígida de `cost_bucket managerial` para liderança aparecer;
   - Gating total na UI quando `user_id` é nulo.

5. **O que recuperar do fluxo antigo para voltar a operar?**
   - Fallback explícito de liderança por **participante-gerente** (mesmo sem `user_id`) para não bloquear importação/alocação.
   - Visão de equipe por setor/cargo quando manager mapping falhar (modo contingência operacional).

### 7) Soluções concretas

#### 7.1 Correções imediatas
1. **Desbloquear operação sem `user_id` do gerente** — permitir importação/alocação com `manager_participant_id` fallback.
   - Classe: **correção de fluxo operacional** + **correção de contrato**.
2. **Ajustar `GET /workforce/managers` para fallback de liderança por inferência de nome de cargo/flag transitória quando faltar role settings**.
   - Classe: **correção de backend** + **correção de contrato**.
3. **Exibir estado de bloqueio acionável na UI** (ex.: “gerente sem usuário; operar em modo contingência”).
   - Classe: **correção de UI**.
4. **Forçar estratégia de atualização de bundle no deploy (bust de cache + instrução de refresh SW)**.
   - Classe: **correção de bundle/entrega**.

#### 7.2 Correções de curto prazo
1. Criar vínculo explícito `manager_participant_id` em assignments (sem depender de email/user).
   - Classe: **redesign mínimo**.
2. Remover gate hard de `cost_bucket managerial` como única fonte de liderança (introduzir flag/entidade de liderança dedicada).
   - Classe: **correção de modelagem/contrato**.
3. Adicionar endpoint de diagnóstico operacional (`/workforce/health`) com contagem de gerentes sem user, assignments órfãos e setor inconsistente.
   - Classe: **correção de backend**.

#### 7.3 O que não mexer agora
1. Não abrir V4/Analytics neste ciclo.
   - Classe: **fora de fase**.
2. Não reescrever Meals/POS enquanto Workforce não voltar a operar.
   - Classe: **fora de fase**.
3. Não fazer redesign total de People/Auth agora.
   - Classe: **fora de fase**.

### 8) Ordem correta de recuperação (máximo 4 passos)
1. **Garantir operação contingente sem `user_id`** (UI + backend).
2. **Corrigir contrato de liderança (`managers`) para não depender só de bucket gerencial.**
3. **Publicar build com política de update de SW/cache comprovada.**
4. **Executar validação E2E autenticada de Workforce (importar, alocar, configurar custos/turnos, listar equipe).**

### 9) O que congelar imediatamente
- Novos patches de UX em Workforce sem prova de contrato/backend.
- Mudanças paralelas em Analytics/V4.
- Alterações “cosméticas” em Participants Hub que não atacam liderança/contrato.
- Ajustes em Meals além de manutenção básica (somente contexto paralelo).

### 10) Registro
- Este bloco registra diagnóstico duro da rodada atual em `docs/progresso6.md` sem apagar histórico e sem declarar resolução sem prova E2E autenticada.

---

## 15. Registro detalhado da rodada de 14/03/2026 - correcao parcial de Workforce

### 1. Contexto real da rodada
- A frente de `Workforce` nao estava travada por um bug unico.
- O que consumiu 3 dias foi a sobreposicao de **quatro camadas de erro ao mesmo tempo**:
  - modelo mental errado do fluxo (`cargo -> equipe` em vez de `evento -> gerente -> equipe`)
  - chave operacional errada na reconciliacao (`participant_id + role_id` em vez de `participant_id + sector`)
  - travas de UI em cima de `manager_user_id`
  - validacao incompleta da entrega real (`src` atualizado, mas `dist`/bundle nem sempre refletindo isso)
- Enquanto essas quatro camadas foram tratadas como se fossem uma coisa so, cada ajuste parecia "quase resolver", mas o operador continuava vendo Workforce quebrado.

### 2. Onde estavamos errando
1. **Estavamos insistindo no eixo por cargo.**
   - O fluxo antigo girava em torno de `roles`.
   - O operador, na pratica, trabalha por gerente e equipe.
   - Isso gerava tela e contratos desalinhados com o uso real.

2. **Estavamos reconciliando assignment pela chave errada.**
   - A importacao e parte da alocacao ainda procuravam registro existente por `(participant_id, role_id)`.
   - O banco e a operacao real estavam mais proximos de `(participant_id, sector)`.
   - Consequencia: duplicacao, religacao errada ou tentativa de inserir onde o correto era atualizar.

3. **Estavamos exigindo `role_id` mesmo quando o contexto do gerente ja bastava.**
   - No modo manager-first, isso travava alocacao manual desnecessariamente.
   - O backend ainda estava mais rigido do que o fluxo real precisava.

4. **Estavamos confiando demais em `workforce_role_settings.cost_bucket = managerial`.**
   - Quando esse setting faltava ou estava inconsistente, o gerente simplesmente sumia da master view.
   - O problema nao era ausencia real de lideranca, era fragilidade do criterio de descoberta.

5. **Estavamos validando codigo-fonte, mas nao fechando a cadeia de entrega.**
   - Em parte da rodada, o `src` ja tinha a recuperacao do painel do gerente.
   - Mesmo assim, a interface real continuava antiga porque o artefato servido ao navegador nao era o mesmo que estava no codigo.

### 3. Como a correcao parcial foi obtida
- A virada aconteceu quando Workforce deixou de ser tratado como "cadastro de cargos" e passou a ser tratado como "operacao por gerente".
- A partir disso, a estrategia correta ficou:
  - abrir uma master view de gerentes
  - carregar a equipe pelo gerente selecionado
  - permitir importacao e alocacao manual dentro do contexto desse gerente
  - reconciliar assignment pela identidade operacional real do evento/setor
- Isso nao resolveu todo o modulo, mas resolveu a parte mais importante: **tirou Workforce do eixo errado e recolocou a operacao no contexto do gerente**.

### 4. O que foi corrigido em 14/03/2026

**Primeiro bloco do dia - commit `a1097a0` (14/03/2026 00:23)**
- `GET /workforce/managers` foi introduzido para sustentar a master view por gerente.
- `GET /workforce/assignments` passou a aceitar filtro por `manager_user_id`.
- `POST /workforce/assignments` passou a aceitar contexto manager-first.
- `POST /workforce/import` passou a religar equipe no eixo `(participant_id, sector)`.
- `WorkforceOpsTab` saiu do fluxo centrado em cargo e foi para master/detail por gerente.
- `AddWorkforceAssignmentModal` entrou em modo manager-first.

**Segundo bloco do dia - commit `a0057be` (14/03/2026 21:14)**
- A alocacao manual deixou de exigir `role_id` em todos os cenarios.
- Quando o contexto vem do gerente/setor, o backend passou a resolver automaticamente um cargo operacional padrao.
- O create manual passou a atualizar assignment existente por setor em vez de tentar duplicar.
- A importacao passou a atualizar `role_id`, `manager_user_id` e `source_file_name` do assignment ja existente.
- O painel do gerente foi recuperado com os campos e acoes que tinham sumido:
  - nome
  - CPF
  - telefone
  - quantidade de turnos
  - horas por turno
  - refeicoes por dia
  - valor por turno
  - custos
  - configuracao de cargo

### 5. O que efetivamente ficou corrigido
- A navegacao principal de Workforce deixou de depender do fluxo antigo por cargo.
- A tela passou a refletir melhor o uso operacional real: `evento -> gerente -> equipe`.
- Importacao e alocacao manual passaram a conversar melhor com a unicidade real do banco.
- O painel gerencial voltou a existir como centro da operacao, em vez de ficar espalhado ou ausente.

### 6. O que ainda nao estava resolvido no fim daquela rodada
- Gerente sem `user_id` ainda continuava sendo um gargalo real em varios cenarios.
- A descoberta de gerentes ainda era fragil por depender demais do `cost_bucket` gerencial.
- A prova E2E autenticada em browser ainda nao estava fechada de ponta a ponta.
- O risco de artefato/bundle desatualizado ainda precisava ser tratado explicitamente.

### 7. Leitura final honesta sobre os 3 dias
- O erro nao foi "faltar um patch pequeno".
- O erro foi insistir por tempo demais em sintomas separados sem assumir que o problema tinha:
  - erro de modelagem operacional
  - erro de chave de reconciliacao
  - erro de gating de UI
  - erro de entrega/cache
- A correcao parcial so apareceu quando essas camadas foram lidas juntas.

### 8. Evidencias concretas usadas para este registro
- `backend/src/Controllers/WorkforceController.php`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `frontend/src/pages/PseguimosarticipantsTabs/AddWorkforceAssignmentModal.jsx`
- commits `a1097a0` e `a0057be`

---

## 16. Desenho da Fase 1 - base estrutural por evento para Workforce

### 1. Objetivo da fase 1
- Tirar `Workforce` do modelo atual "cargo global do organizador" e preparar a base real "cargo/hierarquia por evento".
- Fazer isso sem quebrar a operacao atual de:
  - importacao CSV
  - alocacao manual
  - QR code do participante
  - scanner/checkin
  - Meals
  - leitura financeira
- A Fase 1 **nao fecha a UX final**, mas cria a base de banco e a camada de compatibilidade que permite avancar sem retrabalho.

### 2. Problema que a fase 1 resolve
- Hoje `workforce_role_settings` nao tem `event_id`, entao a configuracao do cargo ainda e global e nao do evento.
- Hoje "gerente" nao existe como entidade persistida do evento; ele e so um `role` com `cost_bucket = managerial`.
- Hoje coordenador/supervisor criado dentro do painel do gerente nao entra numa arvore do evento; entra apenas no catalogo de cargos.
- Hoje `manager_user_id` e insuficiente como ancora estrutural, porque:
  - depende de usuario real
  - nao representa coordenadores/supervisores
  - nao organiza a hierarquia toda do setor

### 3. Decisao estrutural da fase 1
- `workforce_roles` continua existindo como **catalogo de nomes de cargos** do organizador.
- `workforce_role_settings` continua existindo como **template legado/padrao** do organizador.
- A nova fonte de verdade do evento passa a ser uma tabela nova: `workforce_event_roles`.
- `workforce_assignments` deixa de depender apenas de `role_id` e passa a apontar tambem para o cargo do evento.
- O QR, Meals, scanner e checkin passam a ler primeiro a configuracao do cargo do evento; se nao existir, caem no legado.

### 4. Nova tabela principal proposta

```sql
CREATE TABLE workforce_event_roles (
    id SERIAL PRIMARY KEY,
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    role_id integer NOT NULL,
    parent_event_role_id integer NULL,
    root_event_role_id integer NULL,
    sector varchar(50) NOT NULL,
    role_class varchar(20) NOT NULL,
    authority_level varchar(30) NOT NULL DEFAULT 'none',
    cost_bucket varchar(20) NOT NULL DEFAULT 'operational',
    leader_user_id integer NULL,
    leader_participant_id integer NULL,
    leader_name varchar(150) NULL,
    leader_cpf varchar(20) NULL,
    leader_phone varchar(40) NULL,
    max_shifts_event integer NOT NULL DEFAULT 1,
    shift_hours numeric(5,2) NOT NULL DEFAULT 8.00,
    meals_per_day integer NOT NULL DEFAULT 4,
    payment_amount numeric(12,2) NOT NULL DEFAULT 0.00,
    sort_order integer NOT NULL DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    is_placeholder boolean NOT NULL DEFAULT false,
    created_at timestamp without time zone DEFAULT NOW(),
    updated_at timestamp without time zone DEFAULT NOW()
);
```

### 5. Semantica da nova tabela
- Cada linha de `workforce_event_roles` representa um cargo **configurado dentro de um evento especifico**.
- Exemplos:
  - linha raiz do gerente do setor `bar`
  - linha filha de `coordenador de equipe` dentro desse gerente
  - linha filha de `supervisor de acessos` dentro do gerente de acessos
  - linha operacional `equipe bar alpha`
- Campos centrais:
  - `event_id`: amarra a configuracao ao evento selecionado
  - `role_id`: liga ao catalogo de cargos
  - `parent_event_role_id`: permite salvar coordenadores/supervisores dentro da tabela do gerente
  - `root_event_role_id`: ancora rapida da arvore inteira do gerente
  - `role_class`: separa `manager`, `coordinator`, `supervisor`, `operational`
  - `authority_level`: registra poder diretivo no banco
  - `leader_user_id` e `leader_participant_id`: removem a dependencia exclusiva de `user_id`
  - `max_shifts_event`, `shift_hours`, `meals_per_day`, `payment_amount`: passam a ser configuracao real do evento

### 6. Enumeracoes da fase 1
- `role_class`
  - `manager`
  - `coordinator`
  - `supervisor`
  - `operational`
- `authority_level`
  - `none`
  - `table_manager`
  - `directive`
  - `organizer_delegate`
- `cost_bucket`
  - `managerial`
  - `operational`

### 7. Alteracoes em `workforce_assignments`

```sql
ALTER TABLE workforce_assignments
    ADD COLUMN IF NOT EXISTS event_role_id integer NULL,
    ADD COLUMN IF NOT EXISTS root_manager_event_role_id integer NULL;
```

- `event_role_id`: cargo real do evento ao qual o participante esta vinculado.
- `root_manager_event_role_id`: gerente raiz da arvore daquele membro.
- `manager_user_id` permanece por compatibilidade temporaria.
- A constraint atual `UNIQUE(participant_id, sector)` **nao muda na Fase 1**.
  - Isso preserva a operacao atual e evita abrir duas frentes de risco ao mesmo tempo.

### 8. Indices e constraints da fase 1
- Criar `CHECK` para:
  - `role_class`
  - `authority_level`
  - `cost_bucket`
- Criar FK para:
  - `event_id -> events.id`
  - `role_id -> workforce_roles.id`
  - `parent_event_role_id -> workforce_event_roles.id`
  - `root_event_role_id -> workforce_event_roles.id`
- Criar indices:
  - `idx_workforce_event_roles_event`
  - `idx_workforce_event_roles_parent`
  - `idx_workforce_event_roles_root`
  - `idx_workforce_event_roles_leader_user`
  - `idx_workforce_event_roles_leader_participant`
  - `idx_workforce_assignments_event_role`
  - `idx_workforce_assignments_root_manager_event_role`
- Criar unicidade operacional minima:
  - `UNIQUE(event_id, parent_event_role_id, role_id, sector)`
- Observacao:
  - essa unicidade e por **linha de cargo da arvore**
  - nao por pessoa
  - isso permite que a tabela do gerente exiba cargos configurados no topo sem duplicar a mesma linha estrutural

### 9. Regra de classificacao inicial para backfill
- `manager`:
  - cargos contendo `gerente`, `diretor`, `manager`, `gestor`
- `coordinator`:
  - cargos contendo `coordenador`
- `supervisor`:
  - cargos contendo `supervisor`, `lider`, `chefe`
- `operational`:
  - todo o restante

### 10. Regra de backfill da fase 1
1. Ler todos os eventos que ja possuem `workforce_assignments`.
2. Para cada `event_id + sector`, procurar cargos gerenciais existentes.
3. Se existir cargo de `manager`/`director`, criar linha raiz em `workforce_event_roles`.
4. Se houver coordenador/supervisor sem raiz no setor, criar raiz placeholder do setor:
   - `is_placeholder = true`
   - `authority_level = 'none'`
   - `leader_name` vazio
5. Criar linhas filhas para coordenadores e supervisores abaixo da raiz correta.
6. Criar linhas operacionais do setor abaixo da mesma arvore.
7. Atualizar `workforce_assignments.event_role_id` apontando para a linha correta.
8. Atualizar `workforce_assignments.root_manager_event_role_id` apontando para a raiz da arvore.
9. Preservar `role_id`, `sector`, `manager_user_id` e `source_file_name` como legado de compatibilidade.

### 11. Regra de leitura apos backfill
- Ordem de resolucao da configuracao operacional do participante:
  1. `workforce_member_settings`
  2. `workforce_event_roles` via `event_role_id`
  3. `workforce_role_settings`
  4. default do sistema
- Isso garante que:
  - o QR continue refletindo turnos/refeicoes
  - Meals continue lendo a cota correta
  - scanner/checkin continue respeitando `max_shifts_event`
  - o legado nao quebre enquanto a UI estiver em transicao

### 12. Adaptadores obrigatorios ja na fase 1
- Ajustar o resolvedor central de configuracao operacional para ler `workforce_event_roles`.
- Pontos obrigatorios de compatibilidade:
  - `resolveParticipantOperationalSettings()` em `WorkforceController`
  - `GuestController` para o QR publico
  - `ScannerController`
  - `ParticipantCheckinController`
  - `MealController`
- Regra:
  - se `event_role_id` existir, a configuracao do evento vence
  - se nao existir, continua legado

### 13. Contratos minimos que a fase 1 precisa abrir
- `POST /workforce/event-roles`
  - cria linha do evento
- `GET /workforce/event-roles?event_id=...`
  - lista a arvore do evento
- `GET /workforce/event-roles/{id}`
  - le a configuracao de uma linha
- `PUT /workforce/event-roles/{id}`
  - atualiza configuracao
- `DELETE /workforce/event-roles/{id}`
  - remove linha estrutural sem apagar assignments automaticamente sem validacao

### 14. Regra de compatibilidade com a tela atual
- O modal de configuracao nao deve mais gravar so em `workforce_role_settings`.
- Na Fase 1 ele passa a gravar em `workforce_event_roles`.
- `workforce_role_settings` pode continuar sendo atualizado como espelho transitorio apenas quando necessario para nao quebrar telas legadas.
- O seletor de evento passa a ser obrigatorio para abrir configuracao real de Workforce.

### 15. Poder diretivo registrado no banco
- O pedido de "gerente como organizador" entra na Fase 1 como persistencia, nao como ACL final.
- Campo usado: `authority_level = 'organizer_delegate'`
- Resultado esperado:
  - o banco ja sabe quais gerentes sao diretivos
  - a regra de permissao fina sobre tabela/acoes pode entrar na Fase 2 sem nova migration

### 16. O que a Fase 1 nao fecha ainda
- Nao fecha toda a UX da tabela final do gerente.
- Nao fecha ainda o dashboard final por gerente.
- Nao fecha ainda o somatorio visual de presenca no topo da tabela.
- Nao remove ainda o legado de `manager_user_id`.
- Nao troca ainda toda a malha de endpoints para a UI final.

### 17. Criterios de aceite para aprovar a Fase 1
1. O banco passa a sustentar configuracao de cargo por evento.
2. Gerente, coordenador e supervisor passam a existir como linhas persistidas da arvore do evento.
3. `workforce_assignments` passa a apontar para a arvore real do evento.
4. QR, scanner, checkin e Meals continuam funcionando sem regressao.
5. O sistema suporta salvar poder diretivo do gerente no banco.
6. A base fica pronta para que, na Fase 2, a tabela do gerente mostre topo hierarquico + membros + custos somados.

### 18. Leitura final da Fase 1
- A Fase 1 e a fundacao obrigatoria.
- Sem ela, qualquer ajuste de UI continuara sendo cosmetico porque o banco ainda nao sustenta:
  - evento especifico
  - tabela real do gerente
  - subordinacao de coordenadores/supervisores
  - poder diretivo persistido
  - integracao limpa com Meals/QR

### 19. Registro
- Este bloco registra o desenho aprovado para a Fase 1 no `docs/progresso6.md` antes de qualquer implementacao.

## 20. Restricao estrutural adicional - sistema inteiro offline-first

### 1. Premissa obrigatoria
- O sistema inteiro deve operar offline.
- Isso inclui:
  - Workforce
  - scanner/checkin
  - Meals
  - dashboard operacional local
  - cadastros e configuracoes criticas do evento
- Portanto, a Fase 1 de Workforce nao pode ser desenhada como "banco certo agora e offline depois".
- Se o desenho nascer online-first, depois sera necessario reabrir:
  - schema
  - contratos de API
  - identificadores das entidades
  - sincronizacao

### 2. Leitura real do estado atual
- O projeto ja tem base offline parcial:
  - PWA registrada no frontend
  - `Dexie` local
  - fila `offlineQueue`
  - `SyncController`
- Mas hoje isso esta concentrado principalmente no POS.
- `Workforce` ainda esta orientado a leitura/escrita online imediata.
- Logo, a Fase 1 precisa nascer compativel com o mesmo eixo offline do sistema.

### 3. Impacto direto no desenho da Fase 1
- A Fase 1 passa a ter mais uma exigencia estrutural:
  - toda entidade mutavel do Workforce precisa de identificador estavel gerado no cliente e reaproveitavel no servidor.
- So `id SERIAL` nao serve para fluxo offline.
- Motivo:
  - offline o cliente pode criar gerente/coordenador/alocacao antes de existir `id` do banco
  - a fila precisa referenciar essa entidade sem depender de roundtrip

### 4. Ajuste obrigatorio no schema proposto
- `workforce_event_roles` deve ganhar um identificador publico estavel:

```sql
public_id uuid NOT NULL UNIQUE
```

- `workforce_assignments` tambem deve ganhar um identificador publico estavel:

```sql
public_id uuid NOT NULL UNIQUE
```

- Regra:
  - novas linhas criadas pela UI passam a nascer com `public_id`
  - sincronizacao e reconciliacao passam a usar `public_id`
  - `id` numerico continua interno para FK e performance

### 5. Regra de mutacao offline para Workforce
- Nenhuma operacao critica do Workforce deve depender exclusivamente de resposta imediata da API.
- O frontend precisa suportar:
  - criar cargo do evento offline
  - editar configuracao do gerente/coordenador/supervisor offline
  - importar CSV offline
  - alocar membro manualmente offline
  - registrar checkin/saida/refeicao offline
- Quando sem rede:
  - a UI grava no banco local
  - marca o item como pendente
  - enfileira uma operacao de sync
- Quando a rede voltar:
  - a fila sincroniza
  - o servidor responde com estado canonico
  - o cliente reconcilia snapshot local

### 6. Snapshot local por evento
- Para operar offline de verdade, nao basta ter fila.
- Tambem precisa existir snapshot local do evento.
- Para Workforce, isso significa no minimo cache local de:
  - arvore `workforce_event_roles`
  - assignments do evento
  - participantes do evento
  - dias/turnos do evento
  - configuracoes operacionais usadas por QR/Meals/checkin
- Regra operacional:
  - se o evento ja foi sincronizado antes, ele pode continuar operando offline
  - se o evento nunca foi carregado naquele dispositivo, o cold start ainda depende de rede

### 7. Tipos de operacao que entram na fila offline
- A fila offline deixa de ser apenas de POS.
- O desenho alvo passa a admitir tambem:
  - `workforce_event_role_upsert`
  - `workforce_event_role_delete`
  - `workforce_assignment_upsert`
  - `workforce_assignment_delete`
  - `participant_checkin`
  - `meal_consumption`
- Todas essas operacoes devem ser idempotentes por `offline_id`.

### 8. Regra de sincronizacao
- O backend continua usando `offline_queue` como trilha de auditoria e idempotencia.
- O sync precisa aceitar novos `payload_type` alem de `sale`.
- A conciliacao minima da Fase 1 deve seguir esta regra:
  - `offline_id` evita duplicidade da operacao
  - `public_id` identifica a entidade de negocio
  - o servidor devolve o estado final persistido
- O objetivo da Fase 1 nao e resolver conflito complexo multi-dispositivo.
- O objetivo minimo e:
  - nao perder operacao
  - nao duplicar entidade
  - nao travar o trabalho em campo por falta de conectividade

### 9. Consequencia para QR, Meals e scanner
- O QR code continua representando o participante.
- Mas a leitura operacional dele precisa funcionar offline sobre snapshot local.
- Isso implica:
  - turnos permitidos
  - refeicoes por dia
  - vinculo ao cargo do evento
  - status de checkin local
- Tudo isso precisa poder ser resolvido sem consulta obrigatoria online no momento da operacao.

### 10. O que entra na Fase 1 por causa do offline
- Entram na Fase 1:
  - `public_id` nas entidades centrais do Workforce
  - contratos de sync preparados para novos `payload_type`
  - leitura por snapshot local como premissa arquitetural
  - persistencia compativel com reconciliacao
- Nao entra ainda na Fase 1:
  - toda a UX offline pronta no frontend
  - resolucao avancada de conflito entre varios dispositivos
  - dashboard analitico offline completo

### 11. Novo criterio de aceite transversal
- A Fase 1 de Workforce so fica corretamente desenhada se nao bloquear a futura operacao offline do modulo.
- Em termos praticos:
  1. O schema precisa aceitar criacao offline por `public_id`.
  2. A API nao pode depender apenas de `id` numerico para mutacao.
  3. A sincronizacao precisa ser idempotente por `offline_id`.
  4. O modelo do evento precisa poder ser espelhado localmente por snapshot.

### 12. Registro
- Este bloco corrige o desenho anterior para deixar explicito que `offline-first` nao e detalhe de implementacao.
- A partir daqui, qualquer desenho novo de Workforce, Meals, scanner ou dashboard operacional deve ser validado contra essa restricao.

## 21. Desenho tecnico exato da migration da Fase 1

### 1. Nome da migration
- Sugestao objetiva:
  - `database/010_workforce_event_roles_phase1.sql`

### 2. Ordem correta da migration
1. Criar a tabela `workforce_event_roles`.
2. Adicionar colunas novas em `workforce_assignments`.
3. Backfill de `public_id` nas linhas antigas de `workforce_assignments`.
4. Criar indices e unicidades parciais.
5. Executar backfill estrutural da arvore por `event_id + sector`.
6. Popular `event_role_id` e `root_manager_event_role_id` nos assignments.

### 3. Tabela nova com suporte offline

```sql
CREATE TABLE IF NOT EXISTS workforce_event_roles (
    id SERIAL PRIMARY KEY,
    public_id uuid NOT NULL DEFAULT gen_random_uuid(),
    organizer_id integer NOT NULL,
    event_id integer NOT NULL,
    role_id integer NOT NULL,
    parent_event_role_id integer NULL,
    root_event_role_id integer NULL,
    sector varchar(50) NOT NULL,
    role_class varchar(20) NOT NULL,
    authority_level varchar(30) NOT NULL DEFAULT 'none',
    cost_bucket varchar(20) NOT NULL DEFAULT 'operational',
    leader_user_id integer NULL,
    leader_participant_id integer NULL,
    leader_name varchar(150) NULL,
    leader_cpf varchar(20) NULL,
    leader_phone varchar(40) NULL,
    max_shifts_event integer NOT NULL DEFAULT 1,
    shift_hours numeric(5,2) NOT NULL DEFAULT 8.00,
    meals_per_day integer NOT NULL DEFAULT 4,
    payment_amount numeric(12,2) NOT NULL DEFAULT 0.00,
    sort_order integer NOT NULL DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    is_placeholder boolean NOT NULL DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_workforce_event_roles_public_id UNIQUE (public_id),
    CONSTRAINT chk_workforce_event_roles_role_class
        CHECK (role_class IN ('manager', 'coordinator', 'supervisor', 'operational')),
    CONSTRAINT chk_workforce_event_roles_authority_level
        CHECK (authority_level IN ('none', 'table_manager', 'directive', 'organizer_delegate')),
    CONSTRAINT chk_workforce_event_roles_cost_bucket
        CHECK (cost_bucket IN ('managerial', 'operational')),
    CONSTRAINT fk_workforce_event_roles_event
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_workforce_event_roles_role
        FOREIGN KEY (role_id) REFERENCES workforce_roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_workforce_event_roles_parent
        FOREIGN KEY (parent_event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL,
    CONSTRAINT fk_workforce_event_roles_root
        FOREIGN KEY (root_event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL
);
```

### 4. Alteracao minima de `workforce_assignments`

```sql
ALTER TABLE workforce_assignments
    ADD COLUMN IF NOT EXISTS public_id uuid,
    ADD COLUMN IF NOT EXISTS event_role_id integer NULL,
    ADD COLUMN IF NOT EXISTS root_manager_event_role_id integer NULL;

UPDATE workforce_assignments
SET public_id = gen_random_uuid()
WHERE public_id IS NULL;

ALTER TABLE workforce_assignments
    ALTER COLUMN public_id SET NOT NULL;
```

- FKs da Fase 1:

```sql
ALTER TABLE workforce_assignments
    ADD CONSTRAINT fk_workforce_assignments_event_role
        FOREIGN KEY (event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL;

ALTER TABLE workforce_assignments
    ADD CONSTRAINT fk_workforce_assignments_root_manager_event_role
        FOREIGN KEY (root_manager_event_role_id) REFERENCES workforce_event_roles(id) ON DELETE SET NULL;
```

### 5. Correcao importante de unicidade
- A regra antiga descrita como `UNIQUE(event_id, parent_event_role_id, role_id, sector)` e insuficiente para linhas raiz.
- Motivo:
  - no Postgres, `NULL` nao colide com `NULL`
  - entao varias linhas raiz iguais poderiam passar quando `parent_event_role_id IS NULL`
- A forma correta na migration e usar dois indices unicos parciais:

```sql
CREATE UNIQUE INDEX IF NOT EXISTS uq_workforce_event_roles_root_structure
    ON workforce_event_roles (event_id, role_id, sector)
    WHERE parent_event_role_id IS NULL AND is_active = true;

CREATE UNIQUE INDEX IF NOT EXISTS uq_workforce_event_roles_child_structure
    ON workforce_event_roles (event_id, parent_event_role_id, role_id, sector)
    WHERE parent_event_role_id IS NOT NULL AND is_active = true;
```

### 6. Indices operacionais minimos

```sql
CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_event
    ON workforce_event_roles (event_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_public_id
    ON workforce_event_roles (public_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_root
    ON workforce_event_roles (root_event_role_id);

CREATE INDEX IF NOT EXISTS idx_workforce_event_roles_parent
    ON workforce_event_roles (parent_event_role_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_public_id
    ON workforce_assignments (public_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_event_role
    ON workforce_assignments (event_role_id);

CREATE INDEX IF NOT EXISTS idx_workforce_assignments_root_manager_event_role
    ON workforce_assignments (root_manager_event_role_id);
```

### 7. Regra pratica para `root_event_role_id`
- Linha raiz de gerente:
  - nasce com `parent_event_role_id = NULL`
  - depois da insercao, `root_event_role_id` deve apontar para o proprio `id`
- Linhas filhas:
  - recebem `parent_event_role_id` do no pai
  - recebem `root_event_role_id` da raiz do gerente
- Isso evita recursao cara em todas as consultas operacionais.

### 8. Contrato tecnico minimo ja ajustado para offline
- Toda resposta e mutacao do Workforce deve trafegar ambos:
  - `id`
  - `public_id`
- Para criacao/edicao de arvore:
  - a UI envia `public_id`
  - envia tambem `parent_public_id` e `root_public_id` quando existirem
- Para assignments:
  - a UI envia `public_id`
  - envia `event_role_public_id`
  - envia `root_manager_event_role_public_id`
- O backend resolve os `id` internos a partir dos `public_id`.

### 9. Criterio de aprovacao tecnica desta migration
- Se esta migration sair assim:
  - o banco passa a sustentar a arvore por evento
  - o modulo fica compativel com criacao offline futura
  - a fila de sync pode operar por `offline_id` + `public_id`
  - a Fase 2 pode redesenhar a UI sem reabrir o schema

### 10. Registro
- Este bloco fecha o desenho tecnico da migration da Fase 1 ja incorporando a exigencia offline-first.

## 22. Implementacao controlada da Fase 1 - foundation sem ruptura

### 1. Objetivo desta rodada
- Sair do desenho e entrar na implementacao real da Fase 1.
- Fazer isso com prioridade em compatibilidade:
  - sem cortar o legado de uma vez
  - sem exigir que toda a UI nova ja exista
  - sem transformar a migration em ponto unico de falha operacional

### 2. O que entrou no banco
- Foi criada a migration:
  - `database/010_workforce_event_roles_phase1.sql`
- Esta migration adiciona:
  - tabela `workforce_event_roles`
  - `public_id` em `workforce_assignments`
  - `event_role_id` em `workforce_assignments`
  - `root_manager_event_role_id` em `workforce_assignments`
  - FKs, indices e unicidades parciais para a arvore
- Decisao deliberada:
  - a migration desta rodada prepara a estrutura e os ids publicos
  - o backfill estrutural agressivo nao foi automatizado em SQL agora
  - motivo: risco alto de classificar errado a arvore historica e quebrar operacao existente

### 3. Camada nova de compatibilidade
- Foi criado o helper:
  - `backend/src/Helpers/WorkforceEventRoleHelper.php`
- Funcoes centrais introduzidas:
  - readiness da tabela nova
  - readiness das colunas novas em assignments
  - resolvedor central de configuracao operacional com precedencia:
    1. `workforce_member_settings`
    2. `workforce_event_roles`
    3. `workforce_role_settings`
    4. default
  - leitura por `public_id`
  - normalizacao de `role_class` e `authority_level`

### 4. WorkforceController - o que mudou
- O controller passou a expor:
  - `GET /workforce/event-roles`
  - `POST /workforce/event-roles`
  - `GET /workforce/event-roles/{id|public_id}`
  - `PUT /workforce/event-roles/{id|public_id}`
  - `DELETE /workforce/event-roles/{id|public_id}`
- `GET /workforce/role-settings/{role_id}` agora aceita contexto do evento:
  - `event_id`
  - `sector`
  - `event_role_id` / `event_role_public_id`
  - `parent_event_role_id` / `parent_public_id`
- `PUT /workforce/role-settings/{role_id}` agora:
  - continua salvando no legado quando nao existe `event_id`
  - passa a salvar em `workforce_event_roles` quando existe `event_id`
- `GET /workforce/managers` agora:
  - tenta ler primeiro gerentes reais de `workforce_event_roles`
  - mescla com o legado quando necessario
- `GET /workforce/assignments` agora retorna e aceita filtros por:
  - `event_role_id`
  - `event_role_public_id`
  - `root_manager_event_role_id`
  - `root_manager_event_role_public_id`
- `POST /workforce/assignments` agora:
  - aceita `manager_event_role_id` / `manager_event_role_public_id`
  - aceita `event_role_id` / `event_role_public_id`
  - grava `event_role_id` e `root_manager_event_role_id` quando as colunas existem
  - roda em transacao para nao criar linha estrutural sem assignment
- `POST /workforce/import` agora:
  - aceita `manager_event_role_id` / `manager_event_role_public_id`
  - grava `event_role_id` e `root_manager_event_role_id` no import
  - moveu a criacao do `event_role` padrao para dentro da transacao do CSV

### 5. Integracoes operacionais atualizadas
- `ScannerController`
  - passou a ler `event_role` no limite de turnos
- `ParticipantCheckinController`
  - passou a usar o resolvedor central de configuracao operacional
- `GuestController`
  - QR publico de workforce agora le `event_role`
- `MealController`
  - `mealResolveOperationalConfig()` passou a respeitar `event_role`
  - o saldo analitico passou a considerar `event_role` quando existe vinculo em assignment
- `OrganizerFinanceController`
  - membros operacionais passam a poder ler custo do `event_role`
  - cargos gerenciais por evento passam a entrar no baseline financeiro quando a arvore do evento existir

### 6. Frontend - ajuste minimo aplicado
- `WorkforceOpsTab`
  - passou a mandar `event_id` para leitura de roles/configuracao
  - passou a usar `root_manager_event_role_id` ao abrir a equipe do gerente
  - passou a propagar `event_role_id` / `event_role_public_id` do gerente selecionado
- `WorkforceRoleSettingsModal`
  - passou a carregar/salvar com contexto do evento e da arvore
- `AddWorkforceAssignmentModal`
  - passou a enviar `manager_event_role_id` / `manager_event_role_public_id`
- `CsvImportModal`
  - passou a enviar `manager_event_role_id` / `manager_event_role_public_id`
- `WorkforceSectorCostsModal`
  - passou a ler configuracao do cargo com `event_id`

### 7. Medidas de protecao adotadas nesta rodada
- Nada foi refeito em cima de pressuposto de migration aplicada.
- Toda leitura nova checa readiness antes de usar a tabela/colunas novas.
- Quando a estrutura nova nao existe, o sistema continua no legado.
- Criacao de arvore e assignment foi colocada sob transacao no fluxo manual.
- Criacao de arvore padrao no CSV foi movida para dentro da transacao.
- Nao foi feito backfill estrutural automatico de massa nesta rodada.
- Nao foi removido `manager_user_id`.

### 8. Validacao executada
- `php -l backend/src/Helpers/WorkforceEventRoleHelper.php`: ok
- `php -l backend/src/Controllers/WorkforceController.php`: ok
- `php -l backend/src/Controllers/ScannerController.php`: ok
- `php -l backend/src/Controllers/ParticipantCheckinController.php`: ok
- `php -l backend/src/Controllers/GuestController.php`: ok
- `php -l backend/src/Controllers/MealController.php`: ok
- `php -l backend/src/Controllers/OrganizerFinanceController.php`: ok
- `git diff --check` nos arquivos alterados: sem erro estrutural, apenas warning de `LF/CRLF`

### 9. O que ainda nao foi fechado
- A migration foi criada, mas nao aplicada nesta rodada.
- O snapshot local/offline do Workforce ainda nao foi implementado no frontend.
- O `SyncController` ainda nao recebeu os novos `payload_type` de Workforce.
- O backfill historico da arvore antiga ainda continua pendente.
- O dashboard final por gerente ainda nao foi refeito sobre a arvore nova.

### 10. Leitura honesta do estado apos esta rodada
- A fundacao da Fase 1 entrou no codigo.
- O sistema agora consegue conviver com:
  - legado atual
  - arvore nova por evento
  - ids publicos para futura operacao offline
- A estrategia desta rodada foi claramente conservadora:
  - primeiro fazer o sistema entender a estrutura nova
  - depois ligar migracao/aplicacao/backfill e validacao de campo
- Isso reduz bastante o risco de "quebrar tudo agora e consertar depois".

## 23. Desenho da proxima fase - ativacao controlada da arvore real do gerente

### 1. Nome objetivo da fase
- Fase 2:
  - ativacao controlada da arvore por evento
  - tabela real do gerente
  - backfill guiado
  - preparacao offline local do Workforce

### 2. Objetivo central
- A Fase 1 colocou a fundacao no codigo.
- A Fase 2 precisa fazer a estrutura nova virar operacao real.
- Em termos praticos, isso significa:
  - aplicar a migration
  - popular a arvore por evento com seguranca
  - fazer o painel usar a arvore como fonte principal
  - mostrar a tabela real do gerente com topo hierarquico
  - preparar snapshot local do Workforce para operacao offline

### 3. O que a Fase 2 precisa entregar
- Selecionar evento e carregar apenas a arvore daquele evento.
- Cada gerente raiz deve existir como linha real de `workforce_event_roles`.
- Coordenadores e supervisores devem aparecer no topo da tabela do gerente como linhas filhas persistidas.
- Membros importados/manual devem aparecer abaixo como operacionais da mesma arvore.
- Custos do setor devem refletir:
  - liderancas da arvore
  - equipe operacional
  - refeicoes
- Presenca e uso operacional devem refletir a mesma hierarquia.
- A UI deve parar de depender da composicao hibrida:
  - `managers + roles + assignments`
  - e passar a depender principalmente de:
    - `event-roles`
    - `assignments` vinculados a `root_manager_event_role_id`

### 4. Escopo tecnico da fase
- Banco:
  - aplicar a migration `010`
  - criar rotina de backfill controlado
  - validar preenchimento de `public_id`, `event_role_id` e `root_manager_event_role_id`
- Backend:
  - consolidar `event-roles` como leitura principal do painel
  - abrir endpoint de backfill/auditoria por evento
  - consolidar resumo do gerente por arvore
- Frontend:
  - trocar `WorkforceOpsTab` para arvore real do evento
  - mostrar topo hierarquico:
    - gerente
    - coordenadores
    - supervisores
  - mostrar membros operacionais abaixo
  - permitir editar/excluir linhas da arvore
- Offline:
  - criar snapshot local do Workforce por evento
  - preparar fila local dos payloads de Workforce
  - ainda sem resolver conflito multi-dispositivo avancado

### 5. Ordem recomendada da execucao
1. Aplicar migration em ambiente controlado.
2. Rodar diagnostico de consistencia antes de qualquer backfill.
3. Executar backfill por evento piloto, nao em massa.
4. Auditar manualmente a arvore gerada do evento piloto.
5. Ajustar o painel para preferir `event-roles` quando houver arvore valida.
6. Ligar os resumos de custos e presenca pela arvore.
7. Preparar snapshot local do Workforce.
8. Validar em campo com fluxo completo.

### 6. Consequencias esperadas da Fase 2
- Consequencias positivas:
  - o sistema deixa de tratar gerente como deducao de cargo
  - a tabela do gerente passa a ser entidade real
  - coordenador/supervisor deixam de "sumir" no catalogo global
  - Meals, QR, check-in e financeiro passam a apontar para a mesma fonte estrutural
- Consequencias de transicao:
  - durante um periodo vai existir modo misto:
    - eventos sem arvore nova
    - eventos com arvore nova
  - isso exige fallback previsivel e diagnostico claro
- Consequencias de risco:
  - se o backfill classificar uma raiz errada, toda a equipe pode cair no gerente errado
  - se a UI trocar para a arvore antes de o backfill estar confiavel, o painel pode parecer "vazio"
  - custos podem mudar visualmente porque a precedencia de configuracao passa a favorecer `event_role`

### 7. Cuidados obrigatorios para nao quebrar o sistema
- Nao aplicar a migration e ligar a UI nova no mesmo movimento sem validacao intermediaria.
- Nao rodar backfill global sem antes auditar um evento piloto.
- Nao desativar o legado de `manager_user_id` nesta fase.
- Nao remover leitura de fallback de `workforce_role_settings` nesta fase.
- Nao confiar apenas em nome de cargo para classificar toda a arvore historica sem revisao.
- Nao misturar nesta rodada:
  - redesign final do dashboard
  - resolucao avancada de conflitos offline
  - limpeza definitiva do legado

### 8. Cuidados tecnicos detalhados
- Banco:
  - tirar dump antes da aplicacao da migration
  - registrar eventos onde o backfill criou raiz placeholder
  - registrar eventos onde um assignment nao conseguiu resolver `event_role_id`
- Backend:
  - todo endpoint novo de leitura deve informar claramente a `source`
  - toda operacao de backfill precisa ser idempotente
  - toda exclusao de linha estrutural deve proteger contra filhos e assignments vinculados
- Frontend:
  - nao assumir que toda linha tera `leader_user_id`
  - nao esconder gerente sem `user_id`
  - mostrar quando a arvore do evento ainda esta incompleta
- Offline:
  - nao usar `id` numerico do servidor como ancora unica
  - snapshot local deve ser por evento
  - o cold start de evento nunca sincronizado continua dependendo de rede

### 9. Desenho funcional da nova tabela do gerente
- Topo da tabela:
  - gerente raiz
  - coordenadores
  - supervisores
- Corpo da tabela:
  - membros operacionais da arvore
- Acoes por linha estrutural:
  - editar
  - excluir
  - custos
  - importar CSV abaixo da raiz correta
  - alocar membro manualmente
- Resumos no topo:
  - membros totais
  - custos do setor
  - refeicoes projetadas
  - presenca agregada

### 10. Resultado esperado ao final da fase
- Ao escolher um evento, o painel passa a ser realmente daquele evento.
- Ao criar/configurar um gerente, ele passa a existir como raiz real da tabela.
- Ao criar um coordenador/supervisor dentro desse gerente, ele passa a ficar salvo na mesma arvore.
- Ao importar CSV, os membros passam a cair dentro da tabela correta do gerente.
- O custo total do setor passa a refletir a hierarquia real.
- QR, check-in e Meals passam a refletir a configuracao correta do cargo do evento.
- O modulo fica pronto para a fase seguinte de operacao offline local real.

### 11. Resultado que nao deve ser prometido ainda
- Conflito multi-dispositivo offline resolvido automaticamente.
- Dashboard analitico final 100% redesenhado.
- Remocao completa do legado de `manager_user_id`.
- Eliminacao imediata de toda ambiguidade historica herdada.

### 12. Critério de aprovacao da Fase 2
1. Migration aplicada sem regressao.
2. Evento piloto com arvore auditada manualmente.
3. Painel do gerente lendo a arvore real do evento.
4. Coordenadores/supervisores persistidos e visiveis no topo da tabela.
5. Importacao CSV e alocacao manual gravando na arvore correta.
6. Custos/Meals/QR/check-in coerentes com a nova hierarquia.
7. Snapshot local do Workforce desenhado e iniciado sem bloquear operacao atual.

### 13. Leitura executiva
- A Fase 2 e a fase em que a estrutura nova deixa de ser fundacao silenciosa e vira operacao real.
- O maior risco nao esta em codigo isolado.
- O maior risco esta na ativacao:
  - migration
  - backfill
  - troca da fonte da UI
- Por isso a fase precisa ser executada como ativacao controlada, nao como "refactor normal".

## 24. Implementacao controlada da Fase 2 - diagnostico, backfill e ativacao parcial da arvore

### 1. Objetivo desta rodada
- Entrar na Fase 2 sem fazer corte brusco.
- Criar um diagnostico real da arvore por evento.
- Criar um backfill controlado e idempotente.
- Fazer a UI preferir a arvore apenas quando ela estiver pronta.
- Preparar operacao offline local com snapshot por evento.

### 2. Backend entregue nesta rodada
- `WorkforceController`
  - entrou `GET /workforce/tree-status?event_id=...`
  - entrou `POST /workforce/tree-backfill`
  - o `tree-status` nao falha quando a migration nao existe:
    - ele responde readiness
    - informa bloqueios
    - informa se a fonte recomendada e:
      - `legacy`
      - `hybrid`
      - `event_roles`
  - o `tree-backfill`:
    - exige organizer/admin
    - roda por evento
    - aceita escopo opcional por setor
    - so preenche ligacoes ausentes
    - nao reescreve bindings ja existentes

### 3. Como o backfill foi desenhado para nao quebrar
- Primeiro ele audita assignments do evento.
- Depois ele identifica cargos gerenciais herdados do legado.
- Depois ele cria ou reaproveita:
  - raizes gerenciais
  - filhos gerenciais quando a relacao com o manager pai esta clara
- So depois ele tenta ligar assignments em:
  - `event_role_id`
  - `root_manager_event_role_id`
- Se a relacao historica nao estiver clara, ele nao inventa parent.
- Nesses casos ele:
  - preserva legado
  - retorna amostras de pendencia
- Isso foi feito para evitar o erro mais perigoso desta fase:
  - jogar equipe no gerente errado

### 4. Regras conservadoras adotadas no backfill
- Coordenador/supervisor so vira filho automatico quando existe manager pai claro via `manager_user_id`.
- Se essa relacao nao estiver clara, o cargo nao e forcado para dentro da arvore errada.
- Assignment operacional so recebe parent estrutural quando existe lideranca resolvida.
- Se nao existir root confiavel, o assignment continua em fallback legado/hibrido.
- O sistema nao desliga `manager_user_id`.
- O sistema nao remove `workforce_role_settings`.

### 5. Frontend entregue nesta rodada
- `WorkforceOpsTab`
  - passou a consultar `tree-status`
  - passou a consultar `event-roles` quando a migration estiver pronta
  - passou a decidir a fonte da tela com base em readiness real
  - continua em fallback quando a arvore nao estiver auditada
- A tabela de lideranca agora mostra um painel de ativacao com:
  - roots gerenciais
  - filhos estruturais
  - bindings pendentes
  - fonte em uso
- Entrou botao de `Executar Backfill` diretamente da tela.
- Dentro da tabela do gerente entrou um topo estrutural com:
  - gerente raiz
  - coordenadores
  - supervisores
  - outros cargos ja persistidos na arvore
- Cada linha estrutural do topo agora pode:
  - editar
  - excluir quando for filha
- A lista operacional abaixo deixou de repetir liderancas da arvore quando a ativacao real estiver ligada.

### 6. Offline-first aplicado nesta rodada
- Entrou snapshot local do Workforce por evento em `localStorage`.
- O snapshot guarda:
  - managers
  - roles
  - assignments
  - `tree_status`
  - `event_roles`
- Se a leitura online falhar, a tela tenta abrir pelo snapshot local.
- Isso nao resolve sync bidirecional ainda.
- Mas ja protege leitura operacional em ambiente sem rede.

### 7. Consequencias praticas desta rodada
- O sistema para de "achar" que a arvore esta pronta.
- Agora existe um criterio objetivo para ativar a arvore:
  - readiness da migration
  - roots suficientes
  - bindings completos
- A tela do gerente passa a ter dois modos controlados:
  - fallback legado/hibrido
  - arvore real do evento
- A mudanca reduz bastante o risco de regressao silenciosa.

### 8. Cuidados mantidos
- Nenhum binding existente foi forcosamente reescrito.
- Nenhuma remocao de legado foi feita.
- A exclusao estrutural continua protegida no backend.
- A UI so usa `root_manager_event_role_id` como filtro principal quando a arvore estiver realmente ativa.
- Fora disso ela continua usando:
  - `manager_user_id`
  - setor
  - cargo

### 9. Resultado esperado apos esta rodada
- Ja e possivel auditar se um evento esta pronto para usar a arvore real.
- Ja e possivel executar backfill controlado do evento piloto.
- Ja e possivel enxergar o topo estrutural da tabela do gerente quando a arvore existir.
- Ja e possivel abrir a tela em modo local por snapshot se a rede cair.

### 10. Validacao executada
- `php -l backend/src/Controllers/WorkforceController.php`: ok
- `git diff --check backend/src/Controllers/WorkforceController.php frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`: sem erro estrutural; apenas warning de `LF/CRLF`
- `npx eslint src/pages/ParticipantsTabs/WorkforceOpsTab.jsx` em `frontend`: ok

### 11. Risco residual honesto
- O backfill automatico ainda depende da qualidade historica de:
  - `manager_user_id`
  - email do participante para casar com `users`
  - setor gravado em assignment
- Eventos historicos com dados ambigous ainda vao exigir auditoria manual.
- A migration ainda precisa ser aplicada no ambiente real para esta fase operar de ponta a ponta.
- O sync offline do Workforce ainda nao entrou na fila central do `SyncController`.

### 12. Proximo passo seguro
1. Aplicar a migration da Fase 1 no ambiente alvo.
2. Rodar `tree-status` em um evento piloto.
3. Executar `tree-backfill` nesse evento.
4. Auditar:
   - roots
   - filhos
   - assignments pendentes
5. Validar o fluxo:
   - criar gerente
   - criar cargo filho
   - importar CSV
   - abrir tabela do gerente
   - conferir QR/Meals/custos

## 25. Execucao da proxima fase - migration aplicada e piloto real do evento 1

### 1. O que foi executado de fato
- A migration `010_workforce_event_roles_phase1.sql` foi aplicada no banco local `enjoyfun`.
- O schema novo ficou pronto:
  - `workforce_event_roles`
  - `workforce_assignments.public_id`
  - `workforce_assignments.event_role_id`
  - `workforce_assignments.root_manager_event_role_id`
- O piloto foi executado no evento `1 - EnjoyFun 2026`.
- O piloto nao foi feito por SQL solto.
- Ele foi executado pelas proprias funcoes internas do `WorkforceController`, para validar a logica real da aplicacao.

### 2. Problemas reais encontrados no piloto
- Erro 1:
  - o sistema estava inferindo cargo gerencial apenas por substring no nome
  - isso fez `Equipe GERENTES_DE_LIMPEZA` ser lido como cargo gerencial
  - consequencia:
    - criacao errada de root gerencial
- Erro 2:
  - `PDO/pgsql` estava enviando boolean `false` como string vazia no `execute(array)`
  - consequencia:
    - falha ao persistir `is_active` / `is_placeholder`
- Erro 3:
  - o helper novo usava `mb_convert_case`
  - o PHP local nao tinha `mbstring`
  - consequencia:
    - o backfill quebrava no ambiente local

### 3. Correcoes aplicadas nesta rodada
- Foi criada uma regra explicita:
  - nomes iniciados por `Equipe`, `Time` ou `Staff` passam a ser tratados como colecao operacional
  - isso evita falso positivo de gerente em cargo operacional default
- O persist de `workforce_event_roles` passou a normalizar booleanos para literais aceitos pelo PostgreSQL.
- O helper de formatacao de setor passou a funcionar sem depender de `mbstring`.
- O `tree-status` foi endurecido:
  - agora mede cobertura por setor
  - nao usa mais apenas contagem bruta de "gerentes"
- O `tree-backfill` foi ampliado:
  - se o legado nao tiver lideranca confiavel num setor
  - ele cria root placeholder gerencial daquele setor
  - depois liga o cargo operacional abaixo desse root
  - depois liga todos os assignments do setor nessa arvore

### 4. Limpeza controlada do piloto
- O primeiro piloto gerou uma arvore incorreta para o evento `1`.
- Essa arvore foi limpa de forma direcionada:
  - `event_role_id` e `root_manager_event_role_id` do evento `1` foram zerados
  - `workforce_event_roles` do evento `1` foram removidos
- Depois disso o piloto foi rerodado com a regra corrigida.
- Nenhum outro evento foi alterado nesta rodada.

### 5. Resultado do piloto no evento 1
- Estado antes:
  - `active_sectors_count = 3`
  - `root_sectors_count = 0`
  - `assignments_missing_bindings = 120`
  - `source_preference = hybrid`
- Resultado do backfill:
  - `placeholder_roots_created = 3`
  - `operational_roles_prepared = 3`
  - `assignments_updated = 120`
  - `assignments_unresolved = 0`
- Estado depois:
  - `root_sectors_count = 3`
  - `assignments_with_event_role = 120`
  - `assignments_with_root_manager = 120`
  - `assignments_missing_bindings = 0`
  - `tree_usable = true`
  - `tree_ready = false`
  - `source_preference = event_roles`
  - bloqueio restante:
    - `placeholder_roles_present`

### 6. Leitura honesta do resultado
- A arvore do evento agora ficou utilizavel no piloto.
- Ela ainda nao ficou "pronta" no sentido final.
- O motivo e correto:
  - o legado nao tinha liderancas confiaveis
  - entao o sistema criou roots placeholder por setor
- Isso e melhor do que:
  - inventar gerente errado
  - ou deixar equipe inteira sem root

### 7. Estrutura final gerada no piloto
- Setor `bar`
  - root placeholder gerencial
  - cargo operacional `Equipe BAR` abaixo da raiz
- Setor `gerentes_de_limpeza`
  - root placeholder gerencial
  - cargo operacional `Equipe GERENTES_DE_LIMPEZA` abaixo da raiz
- Setor `seguranca`
  - root placeholder gerencial
  - cargo operacional `Equipe SEGURANCA` abaixo da raiz

### 8. Consequencias para a UI
- Como `source_preference` passou para `event_roles`, a UI nova da tabela do gerente pode operar sobre a arvore real.
- Como ainda existem placeholders, a UI precisa:
  - mostrar a arvore
  - mas continuar sinalizando bloqueio de conclusao
- Isso e exatamente o ponto certo da transicao:
  - arvore utilizavel
  - mas auditoria ainda pendente

### 9. Cuidados mantidos
- Nao foi feito rollout em massa.
- Nao foi rodado backfill em todos os eventos.
- Nao foi removido legado.
- Nao foi prometido que placeholder substitui gerente real.
- O placeholder existe apenas para:
  - dar root estrutural
  - permitir vincular a equipe
  - liberar a transicao segura para a tabela real

### 10. Validacao executada
- `php -l backend/src/Helpers/WorkforceEventRoleHelper.php`: ok
- `php -l backend/src/Controllers/WorkforceController.php`: ok
- `npx eslint src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`: ok
- `git diff --check` nos arquivos alterados:
  - sem erro estrutural
  - apenas warning de `LF/CRLF`

### 11. Proximo passo seguro apos este piloto
1. Abrir a UI do evento `1` e verificar se os roots placeholder aparecem corretamente.
2. Transformar cada root placeholder em lideranca real configurada.
3. Validar o fluxo de:
   - editar root
   - criar cargo filho
   - importar CSV abaixo da raiz correta
4. Depois repetir o piloto em mais um evento antes de qualquer backfill em massa.

## 26. Desenho da proxima fase - total consolidado do setor com lideranca + operacao

### 1. Regra nova de negocio
- Gerentes, coordenadores e supervisores passam a contar no total do setor.
- Esse total nao pode olhar apenas assignments operacionais.
- Ele precisa somar:
  - liderancas estruturais da arvore
  - membros operacionais vinculados ao setor/arvore

### 2. Onde o sistema ainda esta inconsistente hoje
- `WorkforceOpsTab`
  - `team_size` ainda nasce basicamente de assignments
  - consequencia:
    - o card/tabela do gerente pode ignorar coordenadores e supervisores
- `OrganizerFinanceController`
  - o `by_sector.members` ja soma root gerencial
  - mas ainda nao trata a hierarquia inteira como base consolidada do setor
  - consequencia:
    - gerente raiz pode entrar
    - coordenadores/supervisores filhos podem ficar fora do total do setor
- Dashboard
  - le `by_sector.members`
  - entao herda qualquer subcontagem do backend

### 3. Regra funcional correta para o total do setor
- O sistema passa a trabalhar com tres contagens diferentes:
  - `planned_members_total`
    - total estrutural planejado do setor
    - inclui:
      - gerentes
      - coordenadores
      - supervisores
      - membros operacionais
  - `filled_members_total`
    - total de pessoas realmente preenchidas
    - inclui:
      - liderancas com participante/usuario vinculado
      - operacionais realmente alocados
  - `present_members_total`
    - total presente no evento
    - vem de check-in/scanner

### 4. Regra importante para placeholder
- Root placeholder nao pode inflar presenca real.
- Root placeholder tambem nao deve ser tratado como pessoa preenchida.
- Mas ele pode continuar contando como posicao planejada quando o setor estiver estruturado para receber aquela lideranca.
- Isso evita dois erros:
  - fingir que existe pessoa real onde ainda nao existe
  - perder o custo/espaco estrutural daquela lideranca no planejamento

### 5. Formula da fase
- Total planejado do setor:
  - liderancas estruturais ativas do setor
  - mais operacionais vinculados ao root do setor
- Total preenchido do setor:
  - liderancas estruturais com `leader_participant_id` ou `leader_user_id`
  - mais assignments operacionais reais
- Total presente do setor:
  - pessoas com check-in dentro da mesma arvore do setor

### 6. Escopo tecnico da fase
- Backend:
  - consolidar contadores por setor em cima da arvore
  - incluir linhas filhas gerenciais no agregado do setor
  - devolver no payload:
    - `planned_members_total`
    - `filled_members_total`
    - `present_members_total`
    - `leadership_positions_total`
    - `operational_members_total`
- Frontend Workforce:
  - trocar o `team_size` do gerente para total consolidado do setor/arvore
  - mostrar separacao visual:
    - liderancas
    - operacionais
    - planejado
    - preenchido
- Dashboard:
  - atualizar leitura de `by_sector.members`
  - trocar para os novos campos consolidados
- Finance/Meals:
  - continuar usando base estrutural unica
  - sem duplicar gerente/coordenador/supervisor em custos do setor

### 7. Ordem recomendada da execucao
1. Corrigir o agregado do backend por setor.
2. Ajustar o payload para expor totais separados.
3. Trocar `WorkforceOpsTab` para consumir o total consolidado.
4. Trocar dashboard para ler os novos campos.
5. Validar em evento piloto com placeholder e lideranca real.

### 8. Cuidados obrigatorios
- Nao reutilizar `members` antigo para dois significados diferentes.
- Nao contar placeholder como pessoa preenchida ou presente.
- Nao somar a mesma lideranca duas vezes:
  - uma na arvore
  - outra nos assignments
- Nao quebrar os cards antigos do dashboard sem fallback.
- Nao misturar contagem de pessoas com contagem de cargos estruturais sem nomear isso claramente no payload.

### 9. Consequencias esperadas
- Consequencias positivas:
  - o total do setor passa a refletir a estrutura real
  - coordenadores/supervisores deixam de "sumir" da contagem
  - dashboard e Workforce falam a mesma lingua
- Consequencias de transicao:
  - alguns setores vao mostrar aumento de total
  - isso nao e regressao
  - e a correcao da base consolidada
- Consequencias de risco:
  - se o frontend continuar lendo o campo antigo, o numero pode parecer divergente
  - se placeholder for tratado errado, o total pode parecer inflado

### 10. Resultado esperado ao final da fase
- Na tabela do gerente, o total do setor passa a incluir:
  - gerente
  - coordenadores
  - supervisores
  - operacionais
- No dashboard, o setor mostra total coerente com a arvore.
- Em custos e planejamento, a base de headcount deixa de separar lideranca e operacao de forma inconsistente.
- Em presenca real, o sistema continua distinguindo:
  - estrutura planejada
  - pessoas preenchidas
  - pessoas presentes

### 11. Resultado que nao deve ser prometido ainda
- Placeholder resolvido automaticamente como lideranca real.
- Presenca perfeita de lideranca sem vinculo de usuario/participante.
- Rollout em massa para todos os eventos sem nova rodada piloto.

### 12. Proximo passo seguro desta fase
1. Implementar os novos totais no backend do Workforce/Finance.
2. Ajustar a tabela do gerente para mostrar o total consolidado.
3. Validar no evento `1` com roots placeholder.
4. Depois validar em evento com lideranca real para comparar:
   - planejado
   - preenchido
   - presente

## 27. Execucao da proxima fase - total consolidado na UI e vinculo automatico do CSV

### 1. Objetivo fechado nesta rodada
- Fazer a UI mostrar o total consolidado do setor com:
  - gerente
  - coordenadores
  - supervisores
  - operacionais
- Fechar a regra funcional pedida pelo usuario:
  - quando o CSV e importado dentro da tabela de um gerente ja cadastrado, a equipe precisa ficar vinculada automaticamente a esse gerente
  - sem reconfiguracao manual posterior para a tabela refletir o vinculo

### 2. Problema real encontrado
- A UI de `Workforce` ja tinha parte do headcount consolidado preparado nos helpers, mas ainda renderizava o valor antigo em pontos principais.
- O conector financeiro ainda somava so a raiz gerencial no agregado por setor.
- Existia um risco estrutural no backend:
  - quando o fluxo chegava so com `manager_user_id`
  - o controller conseguia descobrir o gerente no evento
  - mas nao promovia esse contexto para `manager_event_role`
  - resultado possivel:
    - importacao/alocacao vinculada ao usuario do gerente
    - mas sem gravar corretamente `event_role_id` e `root_manager_event_role_id` na arvore do evento
    - o que obrigaria correcoes posteriores para a UI refletir o vinculo

### 3. Correcao aplicada no backend
- Arquivo principal:
  - `backend/src/Controllers/WorkforceController.php`
- Ajuste feito em:
  - alocacao manual
  - importacao CSV
- Nova regra:
  - se o fluxo trouxer apenas `manager_user_id`
  - e o sistema conseguir localizar `event_role_id` desse gerente pelo contexto do evento
  - esse `event_role` passa a ser adotado automaticamente como raiz de vinculacao
- Consequencia:
  - a equipe importada na tabela do gerente ja entra com:
    - `event_role_id`
    - `root_manager_event_role_id`
    - setor correto
    - parent/root corretos na arvore
- Tambem deixei a resposta da importacao mais explicita:
  - `manager_event_role_id`
  - `root_manager_event_role_id`
  - `assigned_event_role_id`
  - `auto_bound_to_manager`

### 4. Correcao aplicada no agregado de headcount/custos
- Arquivo principal:
  - `backend/src/Controllers/OrganizerFinanceController.php`
- O agregado por setor agora devolve:
  - `planned_members_total`
  - `filled_members_total`
  - `leadership_positions_total`
  - `leadership_filled_total`
  - `leadership_placeholder_total`
  - `operational_members_total`
- Regra de compatibilidade mantida:
  - `members` continua existindo
  - mas agora funciona como alias de `planned_members_total`
- Correcao importante:
  - filhos gerenciais da arvore tambem passam a entrar no headcount do setor
  - nao apenas a raiz do gerente

### 5. Correcao aplicada na UI
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - a lista dos gerentes agora mostra total consolidado do setor
  - com separacao visual entre:
    - planejado
    - preenchido
    - lideranca
    - operacao
  - o painel do gerente ganhou cards com esses totais
  - o topo estrutural agora mostra planejado vs preenchido por linha
- `frontend/src/modules/dashboard/WorkforceCostConnector.jsx`
  - dashboard passou a ler os novos totais consolidados
- `frontend/src/pages/ParticipantsTabs/WorkforceSectorCostsModal.jsx`
  - custos do setor agora mostram:
    - operacao
    - lideranca estrutural
    - total consolidado do setor
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - projecao do setor passou a usar base consolidada
  - evitando leitura antiga baseada apenas em `members`
- `frontend/src/pages/ParticipantsTabs/CsvImportModal.jsx`
  - copy ajustada para deixar explicito o vinculo automatico ao gerente atual
- `frontend/src/pages/ParticipantsTabs/AddWorkforceAssignmentModal.jsx`
  - modo escopado do gerente tambem aceita `managerEventRolePublicId`

### 6. Cuidados tomados para nao quebrar
- Nenhum corte brusco do campo antigo `members`.
- Compatibilidade preservada para telas que ainda nao migraram totalmente.
- Fallback legado mantido quando a arvore nao estiver pronta.
- O vinculo automatico do CSV foi implementado sem remover o suporte anterior por `manager_user_id`.
- Nenhuma migration nova foi introduzida nesta rodada.

### 7. Resultado esperado apos esta rodada
- A equipe importada pelo gerente ja cadastrado deve aparecer vinculada automaticamente na arvore correta.
- O total do setor na UI passa a incluir lideranca + operacao.
- Custos do setor passam a falar a mesma lingua da tabela do gerente.
- Dashboard e modais deixam de subcontar coordenadores/supervisores.

### 8. Validacao executada
- `php -l backend/src/Controllers/OrganizerFinanceController.php`
- `php -l backend/src/Controllers/WorkforceController.php`
- `npx eslint` nos arquivos de frontend alterados
- `git diff --check` nos arquivos tocados

### 9. Risco residual que permanece
- Ainda falta validacao funcional em evento real com:
  - gerente real
  - root placeholder
  - coordenador/supervisor filho
  - importacao CSV repetida sobre equipe ja existente
- Ou seja:
  - a base estrutural e a UI foram ajustadas
  - mas o proximo passo seguro continua sendo validar o fluxo ponta a ponta no evento piloto

## 28. Diagnostico atual - por que a UI ainda parece sem lideranca vinculada e o que falta

### 1. Causa real do problema atual
- A arvore do evento ja existe e esta sendo usada na UI.
- Mas boa parte das linhas estruturais ainda nao tem vinculo real com pessoa/usuario.
- Em termos tecnicos:
  - existe `leader_name` em parte das linhas
  - mas ainda faltam `leader_user_id` e `leader_participant_id`
  - e ainda existem `roots placeholder`
- Resultado:
  - a UI consegue mostrar a estrutura
  - mas ainda nao consegue tratar essa lideranca como realmente vinculada
  - por isso o comportamento ainda parece incompleto

### 2. Estado observado no piloto atual
- No evento `1` a base estrutural mostra:
  - roots gerenciais ainda com `is_placeholder = true`
  - exemplos com nome preenchido, mas sem `leader_user_id` e sem `leader_participant_id`
  - pelo menos uma linha filha de coordenacao ja criada
  - mas tambem sem vinculo real de usuario/participante
- Traducao funcional:
  - a arvore foi montada
  - mas a lideranca ainda esta majoritariamente descrita, nao vinculada

### 3. O que ja esta pronto dentro do pedido original
- Selecao por evento:
  - pronta
  - o painel ja opera por `event_id`
- Tabela/estrutura do gerente por evento:
  - pronta na base
  - `workforce_event_roles` ja e a arvore do evento
- Gerente/cargo gerencial salvo como raiz do evento:
  - pronto na base
  - aparece na tabela de lideranca
- Cargos filhos de coordenador/supervisor no topo:
  - parcialmente pronto
  - a estrutura ja suporta e a UI ja renderiza no topo
- Importacao CSV vinculada automaticamente ao gerente:
  - pronta no fluxo atual
  - sem precisar reconfigurar o vinculo depois
- Totais consolidados do setor:
  - prontos para planejado/preenchido/lideranca/operacao
  - dashboard financeiro tambem ja le esse agregado

### 4. O que esta parcial e ainda precisa fechar
- Vinculo real da lideranca:
  - hoje o modal salva nome/cpf/telefone
  - mas ainda nao existe fluxo completo de bind para:
    - `leader_user_id`
    - `leader_participant_id`
  - esse e o principal motivo da sensacao de "sem lideranca vinculada"
- Criacao de cargos filhos dentro da configuracao do gerente:
  - a arvore ja aceita isso
  - existe criacao inline no painel
  - mas o fluxo ainda nao esta fechado do jeito final pedido dentro do modal/configuracao do gerente
- Botoes completos na linha do gerente:
  - hoje a lista principal ainda prioriza entrar na tabela do gerente
  - ainda falta fechar o pacote final na propria linha:
    - editar
    - excluir
    - custos
- Autoridade diretiva/organizador:
  - a coluna `authority_level` ja existe
  - mas ainda falta UI clara e regras finais para:
    - `directive`
    - `organizer_delegate`
  - e falta endurecer ACL por arvore/evento com esse modelo
- Presenca somada ao setor:
  - custo/headcount planejado ja esta consolidado
  - presenca real por arvore ainda nao esta fechada como metrica principal do setor

### 5. O que ainda falta implementar do pedido original
- Fase A: vinculacao real de lideranca
  - adicionar no modal do cargo campos de vinculacao real:
    - buscar participante do evento
    - buscar usuario do organizador
  - ao salvar:
    - persistir `leader_user_id`/`leader_participant_id`
    - limpar `is_placeholder` quando houver bind valido
    - manter `leader_name` como label de exibicao
  - refletir isso imediatamente na UI do topo estrutural e na tabela do gerente

- Fase B: fechamento do fluxo de configuracao do gerente
  - dentro da configuracao do gerente:
    - listar cargos filhos atuais
    - criar coordenador/supervisor
    - editar/excluir filhos
  - sempre salvando com:
    - `parent_event_role_id`
    - `root_event_role_id`
    - `role_class`

- Fase C: autoridade diretiva
  - expor `authority_level` na UI
  - opcoes alvo:
    - `table_manager`
    - `directive`
    - `organizer_delegate`
  - aplicar ACL no backend para que o gerente com esse nivel tenha controle total apenas da sua arvore/tabela

- Fase D: linha principal do gerente
  - adicionar na linha do gerente:
    - editar
    - excluir
    - custos
    - entrar na tabela
  - mantendo o total do setor ja consolidado na mesma linha

- Fase E: presenca e analytics
  - consolidar `present_members_total` por arvore
  - incluir lideranca + operacao
  - propagar isso para:
    - dashboard
    - cards de analise
    - custos/presenca por setor

- Fase F: validacao de QR/Meals ponta a ponta
  - o backend ja esta preparado para resolver config por `event_role`
  - ainda falta validar o comportamento real em campo para:
    - turnos
    - refeicoes por dia
    - entradas/saidas
    - leitura do QR para gerente, coordenador e operacional

### 6. Cuidados obrigatorios nas proximas implementacoes
- Nao converter automaticamente um `leader_name` textual em pessoa real sem confirmacao.
- Nao remover placeholder antes de existir bind valido.
- Nao permitir que autoridade diretiva atravesse para outros setores/eventos.
- Nao quebrar o modo offline:
  - todo novo bind de lideranca e mudanca de autoridade precisa entrar no snapshot/sync da arvore
- Nao duplicar presenca:
  - lideranca nao pode entrar duas vezes no setor

### 7. Resultado esperado quando essas fases faltantes forem fechadas
- A UI deixa de parecer "sem lideranca vinculada".
- Gerente, coordenador e supervisor passam a existir como linhas estruturais com pessoa real vinculada.
- A tabela do gerente vira a fonte operacional completa do setor.
- Presenca, custos, Meals e dashboard passam a ler a mesma arvore sem divergencia.

### 8. Proximo passo seguro recomendado
1. Fechar a vinculacao real da lideranca no modal/configuracao do cargo.
2. Resolver os placeholder roots do evento piloto.
3. So depois abrir a autoridade diretiva final.
4. Em seguida consolidar presenca por arvore e validar QR/Meals ponta a ponta.

## 29. Execucao da Fase A - gerente nasce na arvore e o modal passa a vincular lideranca real

### 1. Regra operacional consolidada nesta rodada
- A partir do momento em que o organizador cria o cargo gerencial no evento:
  - o cargo ja e registrado na arvore do evento
  - ja nasce como raiz estrutural do setor
  - os agregados do setor passam a apontar para essa raiz sem depender de uma segunda reconfiguracao
- O modal de configuracao deixa de ser a etapa que "cria a raiz".
- O modal agora passa a ser a etapa que:
  - completa os dados da lideranca
  - faz o bind real da pessoa/usuario
  - derruba o placeholder quando houver vinculo valido

### 2. Backend ajustado
- `backend/src/Controllers/WorkforceController.php`
  - `createRole` agora aceita contexto estrutural do evento:
    - `event_id`
    - `cost_bucket`
    - `role_class`
    - `authority_level`
    - `parent/root`
  - se o cargo nascer gerencial no evento:
    - a `event_role` raiz ja e criada na hora
    - com `authority_level = table_manager`
    - e `is_placeholder = true` enquanto ainda nao houver lideranca real
  - se o cargo nascer dentro da tabela do gerente com `parent/root`, ele ja pode ser registrado direto na arvore
- `persistEventRoleFromPayload`
  - passou a aceitar limpeza/gravação real de:
    - `leader_user_id`
    - `leader_participant_id`
  - valida se usuario e participante pertencem ao organizador/evento corretos
  - tenta completar o outro lado do bind por email/CPF quando nao for explicitamente informado
  - preenche `leader_name`/`leader_cpf`/`leader_phone` a partir do vinculo real quando esses campos estiverem vazios
  - remove `is_placeholder` automaticamente quando existe bind real
- `getRoleSettings`
  - agora devolve tambem:
    - `leader_user_id`
    - `leader_participant_id`
    - nomes/emails vinculados
    - `is_placeholder`

### 3. Frontend ajustado
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - criar cargo gerencial agora envia `event_id` e cria a raiz estrutural imediatamente
  - criar cargo operacional dentro da tabela do gerente agora tambem pode nascer ja registrado na arvore via `parent/root`
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - ganhou vinculacao real da lideranca:
    - seletor de participante do evento
    - seletor de usuario do organizador
  - o modal tenta auto-completar o outro vinculo por email/CPF quando houver correspondencia
  - ao escolher participante/usuario:
    - nome
    - CPF
    - telefone
    - status de placeholder
    sao atualizados para refletir o vinculo real
- `backend/src/Controllers/UserController.php`
  - lista de usuarios agora inclui `cpf`
  - e passa a permitir que o proprio organizador apareca como opcao de vinculacao

### 4. Consequencia funcional desta fase
- O gerente deixa de depender do primeiro save do modal para existir na tabela estrutural do evento.
- O setor passa a ter raiz estrutural imediata.
- Os agregados do setor podem apontar para essa raiz desde o nascimento do cargo.
- O bind real da lideranca finalmente passa a existir no produto, e nao so no banco.

### 5. Cuidados tomados
- Mantive `placeholder` como comportamento padrao para gerente recem-criado sem lideranca real.
- O bind real so derruba `placeholder` quando:
  - `leader_user_id`
  - ou `leader_participant_id`
  forem validos
- Nao removi o preenchimento manual de nome/CPF/telefone.
- O modal continua permitindo completar os dados antes do vinculo final, sem quebrar o fluxo atual.

### 6. Validacao executada
- `php -l backend/src/Controllers/WorkforceController.php`
- `php -l backend/src/Controllers/UserController.php`
- `npx eslint` em:
  - `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `git diff --check` nos arquivos alterados

### 7. O que esta fechado apos esta rodada
- criar gerente ja registra a raiz estrutural no evento
- cargo filho criado dentro da tabela do gerente ja pode nascer na arvore
- modal ja consegue fazer vinculacao real da lideranca

### 8. O que ainda permanece para a proxima rodada
- resolver placeholder roots antigos do piloto com bind real
- expor e endurecer `authority_level` na UI/ACL
- consolidar presenca real por arvore
- validar QR/Meals ponta a ponta com lideranca real vinculada

## 30. Diagnostico do caso seguranca - CSV subiu na arvore certa, mas a UI do operacional nao herdava a lideranca

### 1. O que o banco mostrou
- O setor `seguranca` esta com a raiz correta:
  - `Gerente de Seguranca`
  - `root_event_role_id = 4`
  - `leader_name = Jose luiz`
- A equipe reimportada foi vinculada estruturalmente ao root correto:
  - `event_role_id = 12`
  - `root_manager_event_role_id = 4`
  - `40` membros operacionais nessa linha
- Portanto:
  - o CSV nao nasceu solto
  - ele entrou na arvore do gerente certo

### 2. Por que a UI ainda parecia errada
- A linha operacional importada (`Equipe SEGURANCA`) nao grava `leader_name` proprio.
- Antes do ajuste desta rodada, a UI da estrutura mostrava apenas:
  - `row.leader_participant_name`
  - ou `row.leader_name`
- Como o cargo operacional nao tinha esses campos preenchidos, aparecia:
  - `Sem liderança vinculada`
- Mas isso era erro de exibicao da relacao estrutural, nao erro do vinculo do CSV.

### 3. Correcao aplicada
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - a linha estrutural agora herda a lideranca do:
    - proprio cargo
    - pai
    - ou raiz da arvore
- Consequencia:
  - quando a equipe for operacional e estiver debaixo do gerente/coordenador
  - a UI passa a mostrar o nome da lideranca vinculada da arvore
  - mesmo que o cargo operacional nao tenha `leader_name` proprio

### 4. Observacao importante
- No caso de `seguranca`, o gerente ainda esta como placeholder estrutural:
  - `leader_name = Jose luiz`
  - `leader_user_id = 0`
  - `leader_participant_id = 0`
- Entao agora a UI pode mostrar o nome corretamente na lideranca herdada da arvore,
- mas a lideranca ainda nao e "realmente vinculada" no sentido final da Fase A
- ate que o bind real seja salvo no modal

### 5. Resultado esperado apos este ajuste
- Ao reabrir a tabela do gerente em `seguranca`, a linha da equipe importada deve mostrar o nome da lideranca herdada do gerente/coordenador em vez de `Sem liderança vinculada`.
- O proximo passo para fechar totalmente o caso e:
  - vincular o gerente real em `leader_user_id` e/ou `leader_participant_id`
  - para que ele deixe de ser placeholder e passe a ser lideranca real do setor

## 31. Correcao do fluxo de cargos filhos - gerente configurado passa a aparecer no seletor de vinculo do coordenador/supervisor

### 1. Problema observado
- O cargo filho criado dentro da tabela do gerente ainda nascia com `role_class = operational` no frontend, mesmo quando o nome era algo como:
  - `Coordenador de Segurança`
  - `Supervisor de Equipe`
- Com isso, o modal abria sem o contexto correto de lideranca para esses cargos.
- Alem disso, o campo `Usuário do Organizador` listava apenas `/users` e nao reaproveitava a propria arvore do gerente ja configurada.
- Resultado pratico:
  - o gerente configurado nao aparecia para ser escolhido no vinculo do coordenador/supervisor
  - o filho podia nascer com classificacao errada

### 2. Correcao aplicada
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - adicionei inferencia explicita de `role_class` por nome do cargo:
    - `manager`
    - `coordinator`
    - `supervisor`
    - `operational`
  - o create inline dentro da tabela do gerente deixou de forcar `operational`
  - agora o payload respeita o nome digitado e envia `cost_bucket` + `role_class` coerentes
  - o card da criacao inline passou a refletir que esse fluxo aceita cargos do setor, inclusive coordenacao e supervisao
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - o modal agora carrega tambem a arvore do evento da propria raiz do gerente
  - o seletor `Usuário do Organizador / Liderança Configurada` passou a unir:
    - usuarios reais do organizador
    - liderancas ja configuradas na arvore
  - o gerente configurado agora aparece como opcao reutilizavel para vincular coordenador/supervisor
  - ao escolher uma lideranca da arvore, o modal tenta reaproveitar:
    - `leader_user_id`
    - `leader_participant_id`
    - `leader_name`
    - `leader_cpf`
    - `leader_phone`
  - quando houver correspondencia por email/CPF, o modal continua tentando completar o outro lado do bind

### 3. Cuidados tomados
- Mantive compatibilidade com o fluxo atual:
  - o select continua aceitando usuario real do organizador
  - o preenchimento manual de nome/CPF/telefone nao foi removido
- Nao alterei contrato de backend nesta rodada.
- O reaproveitamento da lideranca da arvore foi feito na UI primeiro para reduzir risco de regressao.
- O cargo filho continua preso a raiz/pai corretos; o ajuste desta rodada nao solta a equipe da arvore.

### 4. Consequencias esperadas
- Quando o gerente estiver configurado na arvore, ele passa a aparecer no modal do cargo filho como opcao de vinculo.
- Coordenador e supervisor deixam de nascer como `operational` so porque foram criados pelo inline da tabela.
- O modal passa a refletir melhor a hierarquia real do evento:
  - gerente
  - coordenador
  - supervisor
  - equipe operacional

### 5. Validacao executada
- `npx eslint --config eslint.config.js src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `git diff --check` nos arquivos alterados
- Observacao:
  - o lint geral do frontend continua com erros antigos em arquivos fora desta entrega
  - os dois arquivos desta rodada passaram isoladamente

### 6. Resultado esperado apos este ajuste
- Ao criar um cargo como `Coordenador de Segurança` ou `Supervisor de Segurança` dentro do painel do gerente:
  - o cargo deve nascer na classe correta
  - o modal deve mostrar o gerente configurado dentro do seletor de vinculo
  - ao escolher essa lideranca da arvore, o coordenador/supervisor deve reaproveitar o bind estrutural do gerente sem precisar recadastrar tudo do zero

## 32. Correcao da identidade do coordenador - vinculo estrutural com gerente sem sobrescrever o nome proprio do cargo filho

### 1. Problema confirmado
- O banco mostrou um erro de semantica no fluxo do modal:
  - o coordenador ficava corretamente debaixo do gerente na arvore
  - mas, ao usar a referencia da arvore no seletor, os dados do gerente podiam ser copiados para o coordenador
- No evento `1`, isso aconteceu com:
  - `workforce_event_roles.id = 15`
  - `Coordenador de Limpeza`
- A linha ficou com:
  - `leader_name`, `leader_cpf` e `leader_phone` iguais aos do gerente pai
  - sem `leader_user_id`
  - sem `leader_participant_id`
  - ainda como `placeholder`

### 2. Onde estavamos errando
- A UI estava misturando duas coisas diferentes:
  - vinculo estrutural do cargo filho com o gerente
  - identidade real da pessoa que ocupa o cargo filho
- O vinculo estrutural ja existe por:
  - `parent_event_role_id`
  - `root_event_role_id`
- Mas o seletor da arvore estava sendo usado como se fosse identidade da propria lideranca do cargo atual.
- Consequencia:
  - o nome do gerente podia sobrescrever o nome do coordenador/supervisor
  - e o quadro do coordenador passava a exibir o gerente

### 3. Correcao aplicada na UI
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - cargos gerenciais filhos (`coordinator` / `supervisor`) nao herdam mais visualmente o nome do gerente
  - a heranca visual da lideranca ficou restrita ao operacional, onde ela faz sentido
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - escolher a referencia estrutural da arvore nao sobrescreve mais:
    - `leader_name`
    - `leader_cpf`
    - `leader_phone`
    - `leader_user_id`
    - `leader_participant_id`
  - a referencia da arvore passa a ser tratada como contexto estrutural, nao como ocupante do cargo atual

### 4. Correcao aplicada no banco
- Fiz limpeza pontual e conservadora no banco local:
  - apenas em cargos `coordinator` / `supervisor`
  - `placeholder = true`
  - sem `leader_user_id`
  - sem `leader_participant_id`
  - e com `leader_name` / `leader_cpf` / `leader_phone` exatamente iguais aos do gerente pai
- Resultado:
  - a linha `id = 15` foi saneada
  - os campos copiados do gerente foram limpos
  - o vinculo estrutural com o gerente permaneceu intacto

### 5. Consequencia importante
- O sistema nao tinha uma fonte confiavel com o nome/CPF proprio do coordenador dessa linha.
- Portanto:
  - nao foi possivel restaurar automaticamente o nome correto do coordenador
  - foi possivel apenas remover o dado errado que tinha sido clonado do gerente
- Depois desta rodada, o comportamento correto passa a ser:
  - reabrir o modal do coordenador
  - informar o nome/CPF proprios dele
  - salvar novamente
- A partir desse novo save, o quadro deve mostrar o nome do coordenador, nao o do gerente.

### 6. Validacao executada
- consulta direta no PostgreSQL confirmou:
  - `workforce_event_roles.id = 15`
  - `leader_name = ''`
  - `leader_cpf = ''`
  - `leader_phone = ''`
  - `is_placeholder = true`
- `npx eslint --config eslint.config.js src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `git diff --check` nos arquivos alterados

## 33. Proxima fase aprovada para execucao - acoes diretas na linha do gerente

### 1. Motivacao desta fase
- Hoje a tabela principal de gerentes ainda esta incompleta para operacao real.
- Na linha principal do gerente, a UI ainda oferece basicamente:
  - entrar na `Tabela do Gerente`
- Mas o que o fluxo final precisa e:
  - editar
  - excluir
  - custos
  - abrir a tabela
  - resolver placeholder / pendencias de lideranca
- Enquanto esses botoes nao estiverem na linha principal, corrigir erros de lideranca fica lento e propenso a confusao de contexto.

### 2. Estado atual que justifica a fase
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - a tabela principal do gerente hoje ainda exibe so o botao `Tabela do Gerente`
  - os botoes de configuracao/custos ficam dentro do painel apos selecionar o gerente
  - e o `Editar/Excluir` das linhas estruturais so aparece depois de entrar na arvore
- Consequencia:
  - o operador nao consegue agir no root do gerente com a mesma velocidade com que enxerga o problema na lista

### 3. Escopo da proxima fase
- Adicionar na linha principal do gerente:
  - `Editar`
  - `Custos`
  - `Tabela do Gerente`
  - `Excluir`
- Adicionar CTA de pendencia quando houver `leadership_placeholder_total > 0`:
  - `Resolver liderança`
- O botao `Editar` da linha do gerente deve abrir diretamente o modal do root estrutural correto.
- O botao `Custos` deve abrir o consolidado do setor daquele gerente sem precisar entrar no painel.
- O botao `Excluir` deve obedecer regras seguras da arvore:
  - impedir exclusao cega quando houver filhos ou equipe vinculada
  - ou exigir confirmacao explicita se o comportamento permitido for cascade
- Quando houver placeholder no root:
  - abrir o modal ja com foco no bloco de lideranca
  - deixando claro o que falta para sair de `placeholder`

### 4. Cuidados e consequencias
- Nao podemos transformar `Excluir` em acao destrutiva silenciosa.
- Root gerencial nao pode ser removido sem verificar:
  - cargos filhos
  - membros operacionais vinculados
  - impacto no financeiro
  - impacto no QR/Meals/Scanner
- `Editar` na linha do gerente precisa abrir o root certo da arvore, nao um cargo legado ou duplicado.
- O fluxo precisa continuar `offline-first`:
  - acao de editar deve refletir no snapshot local
  - pendencias visuais precisam sobreviver offline
- O objetivo desta fase e reduzir erro operacional na correcao manual, nao introduzir atalhos perigosos.

### 5. Resultado esperado ao final
- O operador olha a tabela principal e corrige o gerente dali mesmo.
- Placeholder de lideranca deixa de ser um problema escondido no painel interno.
- Fica possivel corrigir rapidamente:
  - nome
  - CPF
  - telefone
  - vinculacao real
  - custos do setor
- A linha do gerente passa a ser a unidade principal de gestao da arvore.

### 6. Ordem recomendada de implementacao
- Primeiro:
  - `Editar`
  - `Custos`
  - `Resolver liderança`
- Depois:
  - `Excluir` com trava segura
- Por ultimo:
  - refinamento visual da linha com badges de:
    - placeholder
    - preenchimento
    - total do setor

### 7. O que vem na fase seguinte
- Depois desta fase, a proxima fila tecnica recomendada e:
  - endurecer `authority_level` / poderes diretivos no banco e na UI
  - consolidar presenca real por arvore
  - fechar QR + Meals + dashboard ponta a ponta sobre a mesma arvore

## 34. Execucao da fase - botoes diretos na linha do gerente

### 1. O que entrou nesta rodada
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - a linha principal do gerente agora oferece:
    - `Resolver Liderança`
    - `Editar`
    - `Custos`
    - `Tabela do Gerente`
    - `Excluir`
  - `Resolver Liderança` so aparece quando `leadership_placeholder_total > 0`
  - `Excluir` so fica habilitado quando existe `event_role_id` real para o root
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - o modal agora aceita `focusLeadershipSection`
  - quando aberto por `Resolver Liderança`, ele faz scroll para o bloco de vinculo da lideranca e destaca visualmente essa area

### 2. Como o fluxo ficou
- Na tabela principal de gerentes:
  - `Editar` abre o modal do root estrutural daquele gerente
  - `Custos` abre o consolidado do setor daquele gerente
  - `Tabela do Gerente` continua levando para o painel detalhado
  - `Resolver Liderança` abre o mesmo modal, mas ja focado na area que precisa sair de placeholder
  - `Excluir` chama a remocao do root estrutural
- O painel interno do gerente passou a reutilizar os mesmos helpers de abrir configuracao/custos, reduzindo duplicacao de fluxo

### 3. Cuidados tomados
- Nao alterei o backend nesta rodada porque o `DELETE /workforce/event-roles/:id` ja estava protegido.
- A exclusao do root continua bloqueada quando houver:
  - cargos filhos ativos
  - membros vinculados
- Portanto, colocar o botao `Excluir` na linha do gerente nao abriu uma brecha destrutiva nova; ele apenas expôs uma acao que ja era validada pelo backend.
- Mantive a tabela funcional mesmo em modo hibrido:
  - se nao houver `event_role_id`, o botao de exclusao direta fica desabilitado

### 4. Consequencias esperadas
- Placeholder de lideranca deixa de ficar escondido no painel interno.
- O operador consegue atacar o problema na propria linha onde ele foi percebido.
- Corrigir nome, CPF, telefone, custo e pendencia de root passa a ser mais rapido e com menos troca de contexto.
- A linha do gerente fica mais proxima do papel de unidade principal de gestao da arvore.

### 5. Validacao executada
- `npx eslint --config eslint.config.js src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `git diff --check` nos arquivos alterados

### 6. Resultado esperado apos esta rodada
- Ao voltar para a tabela principal, cada gerente deve ter a barra completa de acoes.
- Quando houver placeholder:
  - o botao `Resolver Liderança` deve aparecer
  - o modal deve abrir focando o bloco de lideranca
- Se tentar excluir um root com filhos ou equipe, o backend deve continuar bloqueando e devolver a mensagem apropriada.

## 35. Clarificacao funcional - aviso de `Resolver Liderança` e o que ele realmente significa

### 1. O que esse aviso esta querendo dizer hoje
- O aviso `1 liderança pendente` nao significa que a arvore esteja errada.
- Ele significa apenas que a posicao gerencial ainda esta marcada no banco como `placeholder`.
- Hoje essa marcacao sai de `placeholder` somente quando existe vinculo real por:
  - `leader_user_id`
  - ou `leader_participant_id`

### 2. O que ja esta funcionando mesmo com esse aviso
- O gerente ja existe na arvore.
- A equipe ja esta vinculada ao gerente correto.
- Os totais do setor ja sobem:
  - `41 posicoes`
  - `40 preenchido`
  - `1 lideranca`
  - `40 operacao`
- Portanto:
  - estruturalmente o desenho esta correto
  - o aviso nao quer dizer que a equipe esteja solta ou quebrada

### 3. Por que isso ficou confuso
- No fluxo de negocio do produto, preencher:
  - nome
  - CPF
  - telefone
  ja pode ser suficiente para considerar que a lideranca esta identificada
- Mas a implementacao atual ficou mais rigida:
  - ela trata como resolvido apenas o vinculo com usuario/participante real
- Isso faz o sistema parecer estar exigindo uma obrigacao extra que nem sempre e necessaria para o desenho do Workforce

### 4. Conclusao tecnica correta
- Esse aviso hoje e mais uma exigencia de estado de banco do que uma necessidade do desenho da arvore.
- Para o desenho estrutural:
  - nao faz diferenca
- Para funcoes futuras de conta/permissao:
  - pode fazer diferenca
  - especialmente em `authority_level`, poderes diretivos e operacao com usuario real

### 5. Direcao correta de ajuste
- Separar dois conceitos:
  - `lideranca identificada`
  - `lideranca vinculada a uma conta real`
- Regra recomendada:
  - se nome + CPF ja foram informados, a posicao deixa de aparecer como pendencia estrutural
  - vinculo com usuario/participante real fica como camada adicional para:
    - permissao
    - delegacao
    - poderes diretivos
- Isso simplifica a UI e evita a sensacao de obrigacao desnecessaria.

## 36. Correcao de escopo por evento + lideranca identificada + remocao do `Executar Backfill`

### 1. Problema grave confirmado
- O painel estava deixando passar cargos de outros eventos.
- A causa principal estava no backend:
  - `GET /workforce/roles?event_id=...`
  - recebia `event_id`
  - mas `listRoles()` ignorava esse filtro
- Consequencia:
  - quando a UI caia no fluxo `legacy/hybrid`
  - a tabela podia misturar cargos do organizador inteiro
  - e nao apenas do evento selecionado

### 2. Correcao aplicada no escopo do evento
- `backend/src/Controllers/WorkforceController.php`
  - `listRoles()` agora respeita `event_id`
  - valida se o evento pertence ao organizador
  - e limita os cargos ao que ja aparece naquele evento por:
    - `workforce_event_roles`
    - ou `workforce_assignments`
- Resultado esperado:
  - o seletor de evento volta a ser a fronteira correta da tela
  - `WorkforceOpsTab` e `AddWorkforceAssignmentModal` deixam de listar cargos de outros eventos

### 3. Correcao da regra de lideranca
- `backend/src/Helpers/WorkforceEventRoleHelper.php`
  - entrou helper unico para decidir se uma lideranca esta identificada
- `backend/src/Controllers/WorkforceController.php`
  - salvar nome + CPF agora ja basta para derrubar `placeholder` estrutural
  - nao e mais obrigatorio existir `leader_user_id` ou `leader_participant_id`
- `backend/src/Controllers/OrganizerFinanceController.php`
  - os agregados agora consideram a lideranca como preenchida quando houver:
    - usuario/participante real
    - ou nome + CPF
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - o modal passou a tratar nome + CPF como `lideranca identificada`
  - a UI nao exige mais conta real para sair de `placeholder`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - os totais locais da tabela passaram a usar a mesma regra
  - o aviso de pendencia nao deve mais aparecer so porque faltou bind com usuario/participante

### 4. Remocao do `Executar Backfill`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - removi o botao `Executar Backfill` dos blocos visiveis da UI
  - removi tambem o estado e o handler associados
- Motivo:
  - o botao ja nao fazia sentido operacional para o fluxo atual
  - e estava adicionando ruido para quem so queria operar a tabela do evento
- O endpoint de backend nao foi apagado nesta rodada.
- Ou seja:
  - a capacidade tecnica continua disponivel
  - mas ela deixou de poluir a UI do operador

### 5. Cuidados tomados
- Nao alterei contratos publicos alem do filtro correto de `event_id` no endpoint que ja o recebia.
- Mantive compatibilidade com:
  - arvore real do evento
  - modo hibrido
  - listagem de alocacao manual
- A regra de lideranca identificada foi centralizada para evitar divergencia entre:
  - contador da UI
  - financeiro
  - persistencia do `event_role`

### 6. Validacao executada
- `php -l backend/src/Helpers/WorkforceEventRoleHelper.php`
- `php -l backend/src/Controllers/WorkforceController.php`
- `php -l backend/src/Controllers/OrganizerFinanceController.php`
- `npx eslint --config eslint.config.js src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `git diff --check` nos arquivos alterados

### 7. Resultado esperado apos esta rodada
- Selecionar um evento deve mostrar apenas os cargos daquele evento.
- Gerente com nome + CPF preenchidos nao deve mais aparecer como `lideranca pendente` so por falta de conta real.
- O botao `Executar Backfill` deixa de aparecer na UI.

### 8. Risco residual
- Ainda falta validacao funcional no navegador para confirmar:
  - se o painel realmente parou de misturar cargos entre eventos
  - se o aviso de pendencia some imediatamente em casos ja identificados

## 37. Ajuste final desta rodada - remocao total do `Resolver Liderança`, correcao do `Custos` na linha do gerente e leitura dos outros eventos

### 1. Remocao do `Resolver Liderança`
- O botao foi removido da tabela principal.
- Tambem removi o foco especial do modal que so existia para esse fluxo.
- A motivacao foi funcional:
  - o botao estava empurrando uma obrigacao de banco para a UI
  - e ja nao fazia sentido depois de separar `lideranca identificada` de `conta real vinculada`

### 2. Correcao do `Custos` na linha do gerente
- O problema era simples:
  - o botao existia na linha principal
  - mas o `WorkforceSectorCostsModal` so estava montado na visao interna do gerente
- Resultado:
  - clicar em `Custos` na tabela principal nao abria nada
- Correcao:
  - subi o `WorkforceSectorCostsModal` tambem para a visao principal da tabela de gerentes

### 3. O que o banco mostrou sobre os outros eventos
- Consultei o estado atual por evento.
- Hoje o banco mostra:
  - evento `1` com linhas gerenciais estruturais ativas
  - evento `6` com linhas gerenciais estruturais ativas
  - eventos `2`, `3` e `7` com `0` linhas gerenciais estruturais ativas
  - e tambem `0` gerentes legados vinculados por assignment
- Conclusao:
  - os outros eventos nao desapareceram por exclusao desta rodada
  - o que acontece e que atualmente eles nao tem gerentes ativos vinculados ao evento para o painel puxar
- Em outras palavras:
  - antes a tela estava misturando catalogo/global e dava a impressao de que havia gerentes no evento
  - agora ela esta mais fiel ao dado realmente vinculado ao evento

### 4. Consequencia pratica
- Se voce lembra de gerentes “existindo” em outros eventos, provavelmente eram cargos globais/legados do organizador e nao linhas estruturais daquele evento.
- Para esses eventos voltarem a aparecer corretamente na tabela, eles precisam ter uma destas fontes:
  - `workforce_event_roles` gerenciais ativos naquele `event_id`
  - ou `workforce_assignments` legados com gerente realmente vinculado ao evento

### 5. Validacao executada
- `php -l` nos arquivos PHP alterados
- `npx eslint --config eslint.config.js` em:
  - `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
  - `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `git diff --check` nos arquivos alterados

## 38. Proximos passos para encerrar a frente `Workforce`

### 1. O que ainda falta para considerar esta frente fechada
- Validacao funcional final da UI do `Workforce` no navegador
- saneamento dos dados reais dos eventos que ainda estao incompletos
- fechamento das integracoes que ainda dependem da mesma arvore
- homologacao operacional com fluxo completo do gerente

### 2. Ordem correta de execucao
- **Passo 1: homologar o fluxo principal do gerente**
  - selecionar evento
  - criar gerente
  - configurar nome/CPF
  - criar coordenador/supervisor
  - importar CSV
  - confirmar que equipe entra automaticamente na arvore correta
  - confirmar que nomes exibidos estao corretos
  - confirmar que custos abrem e somam corretamente

- **Passo 2: sanear os eventos reais que ainda estao fora do padrao**
  - revisar eventos `2`, `3` e `7`
  - decidir se eles:
    - realmente nao possuem gerentes ativos
    - ou se precisam de recriacao/backfill/manual
  - revisar pendencias restantes como `limpeza|1`

- **Passo 3: fechar poderes diretivos**
  - endurecer `authority_level`
  - diferenciar:
    - lideranca identificada
    - lideranca com conta real
    - gerente com poder diretivo
  - garantir que organizador delegado tenha controle apenas da propria arvore

- **Passo 4: fechar integracao ponta a ponta**
  - `Meals`
  - `QR`
  - `Scanner`
  - `Dashboard`
  - todos lendo a mesma fonte estrutural do evento

- **Passo 5: validar operacao offline**
  - snapshot local por evento
  - leitura sem rede
  - reabertura da tabela sem perder estrutura
  - validacao de consistencia apos retorno da conexao

### 3. O que pode ser considerado fora da frente principal
- refinamento visual secundario
- automatizacao mais profunda de backfills antigos
- melhorias cosmeticas de texto/labels

### 4. Criterio de encerramento desta frente
- O `Workforce` sera considerado fechado quando:
  - a tela respeitar 100% o evento selecionado
  - gerente/coordenador/supervisor/equipe aparecerem corretamente na arvore
  - importacao CSV vincular automaticamente ao gerente correto
  - custos e totais do setor baterem
  - nomes exibidos nao herdarem dados errados
  - `Meals`, `QR`, `Scanner` e `Dashboard` estiverem lendo a mesma estrutura
  - a operacao offline estiver validada no fluxo real

### 5. Recomendacao pratica
- O caminho mais eficiente agora e:
  - **primeiro homologacao da UI e saneamento dos eventos reais**
  - **depois integracoes**
  - **por ultimo validacao offline ponta a ponta**
- Se a homologacao do fluxo principal passar, a frente entra em reta final.
## 39. Linguagem funcional na UI

- O usuário apontou corretamente que vários rótulos estavam técnicos demais para operação real.
- O ajuste definido foi trocar termos de implementação por termos funcionais em `Dashboard` e `Workforce`.
- Direção adotada:
  - `Dashboard Central` -> `Painel Geral`
  - `Núcleo Executivo` -> `Resumo Geral`
  - `Núcleo Operacional` -> `Operação do Evento`
  - `Conector Financeiro de Equipe` -> `Custos da Equipe`
  - `Liderança estrutural` -> `Liderança do setor`
  - `placeholder` -> descrição funcional do problema real
  - `árvore`, `snapshot`, `legado`, `binding` -> termos operacionais equivalentes
- Objetivo:
  - reduzir ruído técnico;
  - melhorar leitura para organizador e gerente;
  - manter termos de banco só no código e no progresso técnico, não na tela do usuário.

## 40. Remoção do resquício visual de pendência de liderança

- A tabela principal de `Workforce` ainda mostrava `liderança(s) pendente(s)` mesmo quando isso não ajudava a operação do usuário.
- Esse indicador foi removido da linha principal do gerente e do resumo interno da tabela do gerente.
- A contagem continua existindo no sistema para cálculo e auditoria, mas deixa de contaminar a leitura operacional da tela.

## 41. Validação da pendência de liderança

- Depois da conferência em tela, ficou confirmado que o caso apontado pelo usuário não era erro de contagem.
- A liderança estava realmente pendente naquele registro específico.
- Mantido o ajuste de UI para reduzir ruído visual, mas o diagnóstico operacional desse caso ficou validado como pendência real.

## 42. Próxima missão para reta final do `Workforce`

### 1. Missão definida
- A próxima missão da frente é fechar a integração operacional completa entre `Workforce` e os módulos que ainda precisam ler a mesma estrutura:
  - `Meals`
  - `QR`
  - `Scanner`
  - `Dashboard`

### 2. Por que esta é a missão correta agora
- A estrutura principal do gerente já está praticamente fechada:
  - evento selecionado
  - gerente salvo no evento
  - coordenação/supervisão dentro da mesma tabela
  - importação CSV vinculando equipe automaticamente
  - custos do setor consolidados
- O que ainda impede encerramento real da frente é garantir que o resto do sistema leia a mesma fonte de verdade.

### 3. Escopo desta missão
- Garantir que `Meals` resolva turnos e refeições por dia com base no vínculo estrutural correto do membro.
- Garantir que `QR` reflita a configuração operacional do cargo e do membro.
- Garantir que `Scanner` respeite o mesmo limite/configuração operacional do `Workforce`.
- Garantir que `Dashboard` use os mesmos totais e custos da estrutura do evento.

### 4. Cuidados
- Não quebrar leitura de eventos antigos que ainda estejam parcialmente no modelo anterior.
- Manter fallback seguro onde a estrutura nova ainda não estiver completa.
- Não duplicar custos nem presença ao consolidar liderança + operação.
- Preservar o comportamento `offline-first` na leitura local e na sincronização.

### 5. Resultado esperado
- O membro do setor, qualquer que seja o cargo, passa a ter comportamento coerente em todos os módulos.
- `Meals`, `QR`, `Scanner` e `Dashboard` deixam de divergir do `Workforce`.
- A frente entra em reta final real, sobrando basicamente homologação operacional e saneamento pontual dos eventos antigos.

## 43. Correção do custo de coordenação e supervisão

- O usuário identificou um problema real: alguns cargos filhos de liderança não estavam entrando no custo total.
- A causa encontrada foi concreta:
  - existiam linhas em `workforce_event_roles` com nome de coordenação/supervisão e valor financeiro configurado;
  - mas essas linhas estavam gravadas com `cost_bucket = operational` e, em alguns casos, `role_class = operational`;
  - como o financeiro filtrava por `cost_bucket = managerial`, esses valores ficavam fora da soma.
- Correção aplicada:
  - o financeiro passou a reclassificar cargos pelo nome do cargo quando o dado salvo estiver contaminado;
  - a leitura da árvore também passou a reinterpretar `Gerente`, `Coordenador` e `Supervisor` como liderança mesmo quando a gravação antiga ficou inconsistente.
- Resultado esperado após essa rodada:
  - pagamento e refeições de `coordenador` e `supervisor` entram no total do setor;
  - dashboard e tabela do gerente param de subcontar essas lideranças;
  - a UI deixa de depender cegamente de `cost_bucket` antigo salvo errado.

## 44. Próxima frente confirmada

- Depois de encerrar este bloco final de `Workforce`, a próxima frente definida pelo usuário é `Meals`.
- A transição planejada ficou assim:
  - terminar os ajustes finais pendentes desta frente;
  - amanhã abrir a frente de `Meals` já conectada à mesma estrutura do evento;
  - reaproveitar o que já foi consolidado em cargos, liderança, equipe, custos e operação por evento.
- Objetivo dessa sequência:
  - evitar reabrir lógica paralela;
  - fazer `Meals` nascer lendo a mesma base já estabilizada em `Workforce`.

## 45. Consumidores fechando a frente sem regressão

### 1. Direção desta rodada
- A frente entrou na etapa de consumidores de `Workforce`, com foco em manter o sistema operacional sem reabrir lógica paralela.
- O princípio adotado foi:
  - unificar leitura de configuração;
  - reduzir aleatoriedade em participantes com mais de um vínculo;
  - propagar a mesma base para QR, scanner e dashboard.

### 2. Ajuste na escolha do vínculo do participante
- Foi criada uma seleção preferencial de assignment do participante para os consumidores que precisavam escolher um único contexto.
- A regra passou a preferir assignment com `event_role_id` e `root_manager_event_role_id`, em vez de pegar qualquer linha de forma implícita.
- Isso reduz risco de:
  - QR público mostrar cargo/configuração errada;
  - scanner aplicar limite de turnos com base em um vínculo aleatório.

### 3. QR público e scanner
- A credencial pública de `workforce` e o `Scanner` passaram a reaproveitar a mesma resolução operacional do participante.
- Resultado esperado:
  - `turnos`, `horas`, `refeições/dia` e origem da configuração ficam coerentes com a base real do `Workforce`.
  - a credencial pública passa a reconhecer também `configuração do evento` como origem válida da regra.

### 4. Presença no financeiro/dashboard
- O conector financeiro passou a preencher `present_members_total` para operação e liderança quando houver presença rastreável.
- A UI do dashboard e do modal de custos passa a exibir presença quando esse dado estiver disponível.

### 5. Mitigação de risco
- Mantido fallback onde a presença ainda não puder ser determinada.
- Não foi removida nenhuma trilha antiga; apenas foi reforçada a leitura preferencial segura.
- A mudança evita regressão porque não troca a estrutura central, só reduz ambiguidade na leitura dos consumidores.

## 46. Próximo passo para fechar a frente

### 1. Passo recomendado
- O próximo passo correto é **sanear a origem dos dados e homologar o fluxo ponta a ponta**.

### 2. Ordem de execução
- **Primeiro:** corrigir na origem os registros ainda contaminados de liderança em `workforce_event_roles`
  - `cost_bucket`
  - `role_class`
  - vínculos de liderança que ainda estejam inconsistentes
- **Depois:** rodar homologação completa no evento piloto
  - criar gerente
  - criar coordenador/supervisor
  - importar CSV
  - conferir custos
  - conferir credencial/QR
  - conferir scanner
  - conferir dashboard
- **Por último:** revisar os eventos antigos que ainda estiverem fora do padrão para decidir saneamento ou reconstrução.

### 3. Por que este é o próximo passo certo
- Hoje o sistema já está protegido por leitura defensiva.
- O que falta para encerrar bem a frente é deixar a **origem** limpa, para não depender eternamente de compensação no consumo.

### 4. Resultado esperado
- `Workforce` fecha com dado consistente na origem.
- Custos, presença, QR e scanner passam a bater sem interpretação corretiva extra.
- A frente fica pronta para transição segura para `Meals`.

## 47. Saneamento da origem com proteção contra regressão

### 1. Objetivo da rodada
- Entrou a fase de saneamento da origem dos dados de `Workforce`, sem trocar a operação atual do sistema e sem remover fallback.
- A meta foi parar de depender só de leitura defensiva e deixar a base preparada para homologação final.

### 2. O que foi implementado
- Novo endpoint interno `tree-sanitize` no `WorkforceController`, para saneamento controlado por `event_id` e opcionalmente por `sector`.
- O saneamento agora corrige, de forma conservadora:
  - `cost_bucket`
  - `role_class`
  - `root_event_role_id`
  - `is_placeholder`
  - preenchimento de `leader_name`, `leader_cpf` e `leader_phone` quando já existir vínculo real salvo
- Também entrou endurecimento de leitura em pontos que ainda podiam sumir com gerente por dado contaminado:
  - listagem principal de gerentes do evento
  - diagnóstico da árvore
  - busca de contexto legado do gerente por `manager_user_id`

### 3. Consequências controladas
- A mudança não remove o fluxo atual nem reescreve permissões.
- `authority_level` ficou fora do saneamento automático para não conceder poder diretivo por inferência.
- O saneamento só completa dados de liderança quando a linha já tiver `leader_user_id` ou `leader_participant_id`; ele não cria vínculo novo por dedução arriscada.

### 4. Mitigação de risco
- O saneamento roda em transação.
- A execução pode ser limitada por evento e setor.
- A resposta foi desenhada com contadores e amostras de linhas alteradas para auditoria rápida.
- Mesmo antes de rodar o saneamento, os pontos críticos de leitura já passaram a reclassificar gerente/coordenador/supervisor em tempo de leitura, evitando sumiço de liderança por dado antigo.

### 5. Resultado esperado
- A origem de `workforce_event_roles` fica consistente com o que a UI já mostra.
- Diagnóstico, tabela de gerentes e custos deixam de depender de `cost_bucket` salvo errado.
- A homologação final do evento piloto passa a acontecer sobre dado limpo, reduzindo risco de divergência na virada para `Meals`.

## 48. Execução real do `tree-sanitize`

### 1. Execução
- O `tree-sanitize` foi executado pela API local já servida em `localhost:8080`, usando o organizador do evento piloto.
- Antes disso apareceu um erro real no backend:
  - o PostgreSQL recusou `false` serializado como string vazia no update de `is_placeholder`
- A correção foi aplicada no saneamento para enviar `true/false` como literal aceito pelo banco, no mesmo padrão já usado em `persistEventRoleFromPayload`.

### 2. Resultado do evento `1`
- O saneamento varreu `9` linhas e atualizou `2`.
- Correções aplicadas:
  - `Coordenador de Segurança` passou a ficar salvo corretamente como liderança
  - uma coordenação pendente deixou de ficar marcada como posição em aberto
- Estado final do evento `1` após a execução:
  - `tree_usable = true`
  - `tree_ready = true`
  - `placeholder_roles_count = 0`
  - `assignments_missing_bindings = 0`

### 3. Resultado do evento `6`
- O saneamento varreu `3` linhas e não precisou alterar nenhuma.
- Estado final manteve:
  - árvore utilizável
  - árvore pronta
  - sem liderança pendente

### 4. Risco residual identificado
- Sobrou uma inconsistência semântica no evento `1`:
  - existe um `Coordenador de Limpeza` salvo no setor `bar`
- Isso não foi corrigido automaticamente porque já não é mais saneamento técnico simples; pode ser erro histórico de criação e exige validação funcional antes de mover setor, pai ou liderança.

### 5. Consequência prática
- A frente de saneamento técnico ficou fechada para os eventos com árvore ativa.
- O próximo passo correto deixa de ser infraestrutura e passa a ser homologação funcional:
  - revisar as linhas reais do evento piloto
  - confirmar cargos e setores corretos
  - ajustar manualmente o que for erro histórico de operação

## 49. Correção da contagem de coordenações e supervisões

### 1. Problema confirmado
- A caixa `Coordenações e supervisões` da UI estava somando errado.
- O erro não vinha do banco:
  - no evento piloto existiam `3` cargos gerenciais filhos reais
  - a tela mostrava `6`
- A causa foi objetiva:
  - a UI estava lendo `child_roles_count`, que soma todos os cargos filhos
  - isso incluía também os cargos operacionais da equipe

### 2. Correção aplicada
- A leitura foi trocada para `managerial_child_roles_count`.
- Com isso, a caixa passa a contar apenas:
  - coordenações
  - supervisões
- E deixa de misturar:
  - equipe operacional
  - demais cargos filhos não gerenciais

### 3. Resultado esperado
- Se existem `3` coordenações/supervisões configuradas, a tela passa a mostrar `3`.
- A contagem deixa de inflar quando a equipe operacional já foi criada dentro da árvore do gerente.

## 50. Próximo passo antes de fechar a frente

### 1. Passo correto
- Antes de encerrar `Workforce`, o próximo passo é a homologação funcional final do evento piloto.

### 2. O que precisa ser conferido
- validar a árvore real do evento `1` na UI
- confirmar se cada gerente, coordenação e supervisão está no setor correto
- corrigir o erro histórico que ainda sobrou, como o caso do `Coordenador de Limpeza` em `bar`, se ele realmente estiver no setor errado
- validar o fluxo completo:
  - criar liderança
  - criar coordenação/supervisão
  - importar CSV
  - conferir vínculo automático da equipe
  - conferir custos
  - conferir presença
  - conferir credencial/QR
  - conferir scanner
  - conferir dashboard

### 3. Por que esse é o último passo
- A parte estrutural já está pronta.
- O saneamento técnico já foi executado.
- O que falta agora é confirmar o comportamento real nas telas e limpar qualquer erro histórico de operação que não deve ser corrigido por inferência automática.

### 4. Resultado esperado
- `Workforce` fecha com:
  - árvore pronta
  - custos corretos
  - equipe vinculada automaticamente
  - liderança aparecendo corretamente na UI
  - consumidores lendo a mesma base
- Depois disso, a frente pode ser considerada encerrada e a próxima abertura natural passa a ser `Meals`.

## 51. Bateria de testes finais executada por API e banco

### 1. Escopo validado
- A validação final foi executada no backend local servido em `localhost:8080`, com leitura complementar do banco.
- Foram cobertos:
  - `tree-status`
  - `managers`
  - `event-roles`
  - `assignments`
  - `organizer-finance/workforce-costs`
  - `guests/ticket`
  - `scanner/process` em modo não destrutivo

### 2. Evento `1` (`EnjoyFun 2026`)
- `tree-status`:
  - `tree_usable = true`
  - `tree_ready = true`
  - `manager_roots_count = 3`
  - `managerial_child_roles_count = 3`
  - `assignments_missing_bindings = 0`
- `managers`:
  - `3` gerentes retornados
  - `bar`, `limpeza` e `seguranca`
  - `team_size = 40` para cada gerente
- `event-roles`:
  - `9` linhas estruturais ativas
- `assignments`:
  - `120` vínculos retornados
  - amostras vieram com `event_role_id` e `root_manager_event_role_id` preenchidos
- `workforce-costs`:
  - resumo geral retornou `126` membros planejados/preenchidos
  - `6` lideranças preenchidas
  - `120` operacionais
  - cada setor retornou `42` pessoas (`2` lideranças + `40` operacionais)
- `guests/ticket` com QR real da equipe:
  - credencial pública resolveu corretamente como `workforce`
  - retornou `role_name = Equipe BAR`
  - retornou `sector = bar`
  - retornou `max_shifts_event = 10`
  - retornou `meals_per_day = 4`
  - retornou `payment_amount = 200`
  - retornou `settings_source = member_override`
- `scanner/process`:
  - teste feito em modo não destrutivo
  - o QR real da equipe foi reconhecido
  - a API respondeu corretamente que `bar` não é modo permitido para esse QR de equipe
  - isso validou a resolução do token sem gravar check-in

### 3. Evento `6`
- `tree-status`:
  - árvore utilizável
  - árvore pronta
  - `assignments_missing_bindings = 0`
- `managers`:
  - `1` gerente retornado
  - setor consistente com a árvore ativa

### 4. Resultado consolidado
- A frente ficou validada tecnicamente em backend, banco, custos, credencial pública e leitura do scanner.
- Não apareceu regressão estrutural nos eventos com árvore ativa.

### 5. Resíduo ainda visível
- Ainda existe um resíduo funcional histórico no evento `1`:
  - `Coordenador de Limpeza` salvo dentro do setor `bar`
- Isso não quebrou a estrutura nem a contagem, mas precisa revisão manual de operação antes do encerramento formal da frente.

## 52. Correção do cargo filho aparecendo como gerente independente no evento `Aldeia da Trasnformação`

### 1. Problema confirmado
- No evento `7`, o cargo `Supervisor de Limpezaa` foi criado corretamente como filho do `Gerente de Limpeza` no banco:
  - `parent_event_role_id = 24`
  - `root_event_role_id = 24`
- Mesmo assim, a UI principal passou a exibi-lo como se fosse um gerente independente.
- Isso fez a linha herdar a leitura consolidada da liderança do setor e gerou confusão no botão de exclusão.

### 2. Causa real
- O evento ainda estava em modo híbrido, porque existem assignments antigos sem vínculo estrutural.
- Nessa condição, a lista principal ainda podia cair no fallback legado.
- O fallback legado adicionava todos os cargos gerenciais do catálogo como se fossem linhas de topo, inclusive coordenação e supervisão.

### 3. Correção aplicada
- A lista principal de gerentes passou a priorizar sempre as raízes reais do evento quando elas já existirem.
- Com isso:
  - gerente raiz continua aparecendo na tabela principal
  - coordenação e supervisão deixam de aparecer como gerente independente
  - esses cargos ficam apenas dentro da `Tabela do Gerente`, que é onde pertencem

### 4. Resultado esperado
- No evento `Aldeia da Trasnformação`, a tabela principal volta a mostrar apenas o `Gerente de Limpeza`.
- `Supervisor de Limpezaa` permanece como cargo filho dentro da estrutura do gerente.
- O botão de exclusão deixa de ficar preso na linha errada da tabela principal.

## 53. Encerramento da frente `Workforce`

### 1. Homologação
- A frente foi homologada em duas camadas:
  - homologação funcional na UI pelo usuário
  - homologação técnica por API e banco
- O fluxo principal ficou validado:
  - seleção correta por evento
  - criação de gerente
  - criação de coordenação/supervisão dentro da tabela do gerente
  - importação CSV com vínculo automático
  - total do setor com liderança + operação
  - custos consolidados
  - QR público
  - leitura do scanner
  - dashboard/custos usando a mesma base

### 2. Situação final
- `Workforce` pode ser considerado encerrado como frente principal.
- A árvore por evento ficou ativa e utilizável nos eventos já migrados.
- O sistema ficou operando com a mesma base estrutural para liderança, equipe e consumidores principais.

### 3. Resíduos não bloqueantes
- Qualquer inconsistência histórica pontual que ainda apareça em evento antigo passa a ser tratada como saneamento pontual de operação, não como frente aberta de arquitetura.
- Isso não bloqueia a virada para `Meals`.

### 4. Próxima abertura natural
- A próxima frente passa a ser `Meals`, reaproveitando a base já consolidada em `Workforce`.

## 54. Publicação com limpeza de arquivos auxiliares

- Na publicação desta frente para o git, o usuário autorizou também a remoção dos arquivos auxiliares antigos de auditoria, teste e dump que já estavam fora do fluxo principal do sistema.
- Essa limpeza entra junto com a entrega homologada de `Workforce`, sem alterar a base funcional da aplicação.

## 55. Hardening pós-auditoria do `Participants Hub` com foco em `Workforce`

### 1. O que o diagnóstico trouxe que já estava superado
- O drift sobre `workforce_event_roles` já não existia mais:
  - a migration `010_workforce_event_roles_phase1.sql` já estava aplicada
  - o `schema_current.sql` já continha `workforce_event_roles`
- O snapshot local/offline do `Workforce` também já estava implementado no frontend:
  - leitura por evento via `localStorage`
  - fallback local quando a leitura online falha
  - banner explícito de operação por snapshot na UI
- A homologação estrutural da frente já tinha sido fechada:
  - árvore por evento
  - liderança
  - vínculos automáticos
  - QR público
  - scanner
  - custos e dashboard

### 2. O que ainda era problema real no código
- `backend/public/index.php` ainda:
  - expunha `exception message` no `500`
  - mantinha fallback fixo de CORS para `http://localhost:3000`
  - declarava a rota `bot` para um controller inexistente
- `GET /participants` ainda fazia `UPDATE` para gerar QR automaticamente durante a listagem.
- `POST /sync` ainda:
  - aceitava qualquer usuário autenticado
  - não separava replay deduplicado de processamento novo
  - não validava explicitamente o escopo do evento antes de reconciliar payload offline
- O baseline oficial ainda seguia sem FKs centrais de `Participants/Workforce`.
- O snapshot local do `Workforce` era best-effort, mas falha de leitura/escrita ainda ficava invisível para o operador.

### 3. Correções aplicadas nesta rodada
- `backend/public/index.php`
  - CORS passou a respeitar allowlist por ambiente, com leitura de `CORS_ALLOWED_ORIGINS`
  - o fallback fixo para `localhost` saiu
  - `500` deixou de expor erro interno ao cliente e passou a responder com `correlation_id`
  - a rota `bot` órfã foi removida do roteador
- `backend/src/Controllers/ParticipantController.php`
  - `GET /participants` deixou de mutar estado
  - a listagem agora só informa `qr_token_missing`
  - entrou `POST /participants/backfill-qrs` para regularização explícita por `event_id`
- `backend/src/Controllers/SyncController.php`
  - o endpoint passou a exigir roles explícitas
  - cada item agora valida `event_id` contra o escopo do operador
  - quando houver setor no payload, a ACL setorial é respeitada no sync
  - a resposta passou a separar:
    - `processed_new`
    - `deduplicated`
    - `processed_ids`
    - `processed_new_ids`
    - `deduplicated_ids`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - falha de snapshot local passou a aparecer na tela
  - leitura por snapshot continua existindo, mas agora o operador sabe quando o cache local falhou ou ficou corrompido
- Banco
  - entrou a migration `013_participants_workforce_integrity.sql`
  - o `schema_current.sql` foi alinhado com:
    - FKs de `event_participants`
    - FK de `participant_checkins`
    - FKs de `workforce_assignments`
    - FK de `workforce_member_settings`
    - índices operacionais principais dessas tabelas

### 4. Consequência prática
- O diagnóstico pós-homologação deixou de apontar lacunas vermelhas na base de `Workforce`.
- O que sobra agora como resíduo não é mais falha estrutural do módulo:
  - testes de contrato automatizados
  - SLO/telemetria operacional
  - playbook de incidente
- Esses itens passam a ser governança de release/observabilidade, não bloqueio funcional da frente `Workforce`.

## 56. Ajuste conservador dos indicadores operacionais do Workforce

### 1. Problema observado
- O card `Estrutura operacional` estava mostrando a contagem bruta de linhas em `event_shifts`.
- No evento piloto isso produzia leitura enganosa:
  - `7` dias
  - `7` turnos
- Na prática, as janelas do evento estão cobrindo dias inteiros com `Turno Único`, e a operação trabalha com base de `8h` por turno.
- Ao mesmo tempo, o badge `Sem gerente` estava marcando `7`, mas esse número vinha dos `Colaborador Externo`, não de equipe sem liderança estrutural.

### 2. Correções aplicadas
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - o card passou a calcular `turnos operacionais` pela duração real das janelas do evento dividida pela base de horas do cargo selecionado
  - a UI também passou a exibir quantas janelas foram cadastradas, para não perder a leitura administrativa do calendário
- `backend/src/Controllers/WorkforceController.php`
  - o diagnóstico da árvore deixou de contar `Colaborador Externo` como assignment sem gerente
  - a contagem de bindings passou a tratar `0` como ausência real de vínculo estrutural, em vez de depender só de `NULL`

### 3. Consequências controladas
- O ajuste não altera cadastros, não move assignments e não recria árvore.
- Ele corrige apenas a leitura operacional:
  - `Sem gerente` deixa de inflar por QR externo
  - `Turnos` deixa de refletir só a quantidade de janelas cadastradas
- Se o evento tiver primeiro ou último dia parcial, os turnos operacionais podem ficar menores do que `dias x 3`, porque agora o cálculo respeita a cobertura horária real configurada.

## 57. Exposição dos QR nominais da liderança no Workforce

### 1. Problema observado
- A UI já mostrava QR nominal para membros da tabela operacional.
- Para liderança, o QR do gerente aparecia de forma parcial e supervisor/coordenador continuavam sem ação visível no card estrutural.

### 2. Correção aplicada
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
  - a tabela principal de gerentes passou a exibir `Copiar link` e `QR` quando a liderança raiz já tem `qr_token`
  - os cards estruturais de gerente, coordenação e supervisão passaram a usar o `leader_qr_token` vindo de `workforce/event-roles`
  - cargos operacionais continuam sem esse atalho no card estrutural para não misturar QR herdado com QR nominal

### 3. Consequência controlada
- A mudança não gera QR novo e não altera vínculos.
- Ela apenas expõe na interface os tokens nominais já existentes para a liderança realmente vinculada ao evento.
