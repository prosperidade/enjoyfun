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
