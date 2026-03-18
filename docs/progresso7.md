## 0. Escopo desta rodada

- Análise feita por leitura de código do workspace atual, sem nova rodada de banco/runtime.
- Objetivo desta nota: consolidar o estado real de `Meals` hoje, separar fatos de hipóteses antigas e organizar o trabalho em fases.
- Arquivos-base desta leitura:
  - `backend/public/index.php`
  - `backend/src/Controllers/MealController.php`
  - `backend/src/Controllers/WorkforceController.php`
  - `backend/src/Controllers/OrganizerFinanceController.php`
  - `backend/src/Services/FinancialSettingsService.php`
  - `frontend/src/pages/MealsControl.jsx`
  - `database/schema_current.sql`
  - `database/schema_real.sql`
  - `database/007_workforce_costs_meals_model.sql`
  - `database/meals_hardening.sql`

---

## 1. Desenho atual confirmado

### Backend

O módulo `meals` expõe hoje três operações principais:

- `GET /meals/balance`
- `GET /meals`
- `POST /meals`

A rota `meals` está ativa no roteador principal.

Leitura estrutural:

- `GET /meals/balance` exige `event_id` e `event_day_id`.
- `GET /meals` lista registros já baixados e aceita filtros por `event_id`, `event_day_id` e `event_shift_id`.
- `POST /meals` registra refeição por `participant_id` ou `qr_token`.
- A validação de contexto `evento -> dia -> turno` está centralizada em `mealResolveEventContext()`.
- O backend já trata baseline ambíguo no `POST /meals` com bloqueio `409`.
- O backend já usa `pg_advisory_xact_lock` para serializar o quota check diário por participante/dia.

### Frontend

A tela `MealsControl` consome hoje:

- `GET /events`
- `GET /event-days`
- `GET /event-shifts`
- `GET /workforce/assignments`
- `GET /meals/balance`
- `POST /meals`
- `GET /organizer-finance/settings`
- `PUT /organizer-finance/settings`

Observações importantes:

- A tela entra em modo complementar do Workforce quando o evento não possui `event_days`.
- Nesse modo complementar ela não usa saldo real nem libera baixa de refeição.
- A tela atual não consome `GET /meals` para mostrar histórico; esse endpoint existe no backend, mas não está plugado nesta página.
- A seleção automática de evento hoje já prioriza evento em andamento antes de cair no primeiro da lista. Esse ponto estava degradado em rodadas antigas, mas no código atual já foi corrigido.
- `Meals` continua fora do scanner genérico atual.
  - o scanner só expõe modos `portaria`, `bar`, `food`, `shop` e `parking`
  - não existe hoje um modo `meals` no `Scanner.jsx` ou no `ScannerController.php`
  - a baixa de refeição continua concentrada em `MealsControl`

---

## 2. Análise por arquivo

### `backend/public/index.php`

- Responsabilidade: expõe a rota `/meals` para o `MealController`.
- Leitura: não há lógica de domínio aqui; é apenas o ponto de entrada do módulo.
- Risco: baixo. O valor deste arquivo para a auditoria é confirmar que o módulo está efetivamente publicado.

### `backend/src/Controllers/MealController.php`

- Responsabilidade: concentra os três contratos HTTP do módulo.
- Pontos fortes:
  - valida `event_id`, `event_day_id` e `event_shift_id` com coerência multi-tenant
  - bloqueia baseline ambíguo no `POST /meals`
  - usa lock transacional para evitar corrida de quota diária
  - separa camada operacional e camada financeira no payload de `GET /meals/balance`
- Pontos críticos:
  - `mealEnsureMealsReadSchema()` é usado só no `GET /meals/balance`; `GET /meals` e `POST /meals` não fazem preflight semelhante
  - `POST /meals` não exige assignment elegível no recorte
  - `GET /meals/balance` lista apenas participantes derivados de `workforce_assignments`
  - portanto, se o `POST /meals` registrar refeição para alguém sem assignment, o consumo pode existir em `participant_meals` e ainda assim não aparecer no saldo
  - existe um bug objetivo no código de `mealResolveOperationalConfig()`: a query usa `:organizer_id`, mas a função não define `$organizerId`
- Inferência técnica desta última falha:
  - isso tende a neutralizar o join com `workforce_role_settings` nessa query
  - com isso, o `POST /meals` pode deixar de perceber baseline por cargo e ambiguidade real, caindo em default indevido

### `backend/src/Controllers/WorkforceController.php`

- Responsabilidade no contexto Meals: fornece `GET /workforce/assignments`, usado como base complementar do `MealsControl`.
- Leitura:
  - o fallback do frontend depende diretamente deste payload
  - a listagem já embute resolução operacional (`meals_per_day`, `config_source`, etc.)
- Impacto no domínio Meals:
  - qualquer drift nesta listagem altera a visão complementar da tela
  - o modo complementar de Meals depende mais deste controller do que do próprio `MealController`

### `backend/src/Helpers/WorkforceEventRoleHelper.php`

- Responsabilidade: centraliza a precedência de configuração operacional:
  - `workforce_member_settings`
  - `workforce_event_roles`
  - `workforce_role_settings`
  - fallback default
- Leitura:
  - este helper é a espinha dorsal da resolução de `meals_per_day`
  - `workforceResolveParticipantOperationalConfig()` é consumido por scanner, guest, check-in e também pelo fluxo de Meals
- Impacto:
  - mudar a precedência aqui repercute em vários módulos
  - o bug encontrado em Meals não está neste helper; ele está na lógica local de `mealResolveOperationalConfig()`

### `backend/src/Controllers/OrganizerFinanceController.php`

- Responsabilidade no contexto Meals:
  - expõe `/organizer-finance/settings`
  - expõe `/organizer-finance/workforce-costs`
- Leitura:
  - `getFinancialSettings()` e `updateFinancialSettings()` sustentam o `meal_unit_cost` da tela de Meals
  - `getWorkforceCosts()` também usa `meal_unit_cost`, então alterações nessa modelagem afetam financeiro e não apenas Meals
- Impacto:
  - a camada financeira de Meals está corretamente desacoplada da camada operacional, mas usa a mesma fonte de configuração do domínio financeiro

### `backend/src/Services/FinancialSettingsService.php`

- Responsabilidade: leitura/escrita segura de `organizer_financial_settings`.
- Leitura:
  - o serviço é schema-aware
  - quando a coluna `meal_unit_cost` não existe, ele degrada com `meal_unit_cost_available = false`
- Impacto:
  - esse serviço está coerente com o contrato usado pelo frontend
  - aqui não apareceu quebra estrutural relevante

### `frontend/src/pages/MealsControl.jsx`

- Responsabilidade: consumidor principal do módulo na operação.
- Leitura:
  - consome evento, dias, turnos, workforce complementar, balance e settings financeiros
  - não consome `GET /meals`
  - já prioriza evento em andamento na seleção inicial
  - entra em modo complementar quando faltam `event_days`
- Impacto:
  - a tela hoje está mais honesta que em rodadas antigas
  - o principal risco aqui é refletir limitações do backend, não esconder estado
  - o quadro de `default`, `sem turno` e `sem vínculo` pode continuar aparecendo mesmo com evento configurado quando a base de assignments não está ligada estruturalmente à árvore nova (`event_role_id`) ou não possui `event_shift_id`

### `frontend/src/App.jsx` e `frontend/src/components/Sidebar.jsx`

- Responsabilidade: exposição da rota e entrada visual do módulo.
- Leitura:
  - a rota `/meals-control` está plugada
  - o menu lateral libera acesso para `admin`, `organizer`, `manager` e `staff`
- Risco: baixo. Funcionam como wiring da UI.

### `backend/src/Controllers/ParticipantController.php`

- Responsabilidade indireta: limpeza de `participant_meals` quando um participante é removido.
- Leitura:
  - a exclusão é manual, em nível de aplicação
- Impacto:
  - isso reforça a leitura de que a integridade de `participant_meals` ainda não está no banco via FK/cascade oficial

### `backend/src/Controllers/ScannerController.php`

- Responsabilidade indireta: resolve o participante por QR/token e lê config operacional.
- Leitura:
  - usa `workforceResolveParticipantOperationalConfig()`, não `mealResolveOperationalConfig()`
- Impacto:
  - o bug local do POST de Meals não contamina automaticamente o scanner
  - mas qualquer alteração no helper compartilhado vai repercutir aqui

### `backend/src/Controllers/ParticipantCheckinController.php`

- Responsabilidade indireta: consome a mesma configuração operacional compartilhada.
- Impacto:
  - mudanças na governança de `meals_per_day` ou precedência podem afetar check-in e derivativos de presença

### `backend/src/Controllers/GuestController.php`

- Responsabilidade indireta: expõe convite/guest ticket com `meals_per_day` resolvido.
- Impacto:
  - mudanças no helper compartilhado aparecem também na experiência do convidado/participant ticket
  - porém isso não coloca `guest` dentro do domínio de Meals; o QR público pode exibir metadado operacional de workforce, mas o módulo `Meals` continua sendo escopo de workforce

### `backend/scripts/audit_meals.php`

- Responsabilidade: auditoria versionada de Meals dentro do repositório.
- Leitura:
  - criado nesta rodada para substituir dependência de script solto fora da governança
  - tem três modos:
    - `recent`
    - `integrity`
    - `summary`
  - separa inspeção operacional de checagem de integridade
  - já cobre:
    - órfãos de participante
    - órfãos de dia
    - referência quebrada de turno
    - mismatch entre `event_shift_id` e `event_day_id`
    - mismatch entre evento do participante e evento do dia
    - refeições sem assignment
    - refeições com turno mas sem assignment daquele turno
- Limite desta validação:
  - a sintaxe e o help em CLI foram validados
  - a execução completa não pôde ser testada aqui porque o ambiente atual não possui driver `pdo_pgsql`

### `c:\Users\Administrador\Desktop\audit_meals.php` (externo ao repositório)

- Responsabilidade: auditoria operacional manual de refeições.
- Leitura:
  - a correção principal está certa: o script não depende de `pm.event_id` e deriva o evento por `participant_meals -> event_days -> events`
  - isso confirma que o diagnóstico antigo sobre `pm.event_id` no tooling estava de fato obsoleto para esta versão do script
- Limites importantes:
  - o script hoje está fora da raiz do projeto e mantém `require_once __DIR__ . '/backend/src/bootstrap.php'`
  - nessa localização, o path fica incoerente com o workspace `enjoyfun` e tende a falhar se executado sem ajuste
  - como a query usa `INNER JOIN` em `event_participants`, `people`, `event_days` e `events`, ela esconde órfãos em vez de auditá-los
  - não há filtro por organizer, então a leitura é global do banco
  - há `LIMIT 100`, então ele funciona como amostra operacional e não como auditoria completa
  - o join de `event_shifts` não garante coerência com `pm.event_day_id`; para auditoria forte, o ideal seria atrelar `es.event_day_id = pm.event_day_id`
- Impacto:
  - o script é útil como listagem operacional recente
  - ele ainda não substitui uma auditoria de integridade

---

## 3. Banco, schema e migrations

### Governança de schema

- `database/README.md` deixa explícito:
  - `schema_current.sql` é o baseline canônico
  - `schema_real.sql` é histórico legado e não deve ser tratado como fonte de verdade atual
- Para análise de estado presente, o arquivo correto é `schema_current.sql`

### `database/001_sprint1_tables.sql`

- Papel: origem da tabela `participant_meals`
- Leitura:
  - a tabela nasce mínima: `participant_id`, `event_day_id`, `event_shift_id`, `consumed_at`
  - já nasce sem `event_id`
  - já nasce sem FKs

### `database/005_workforce_member_settings.sql`

- Papel: baseline individual por participante
- Leitura:
  - fornece `meals_per_day`, `max_shifts_event`, `shift_hours`, `payment_amount`
- Impacto:
  - é uma das principais camadas de override usadas por Meals

### `database/007_workforce_costs_meals_model.sql`

- Papel: introduz `meal_unit_cost` e `workforce_role_settings`
- Leitura:
  - a migration está refletida no `schema_current.sql`
  - a modelagem de custos por cargo nasce aqui
- Impacto:
  - é a principal base da camada financeira complementar e do baseline por cargo

### `database/010_workforce_event_roles_phase1.sql`

- Papel: introduz `workforce_event_roles`
- Leitura:
  - essa camada adiciona baseline por evento/cargo e entra na precedência do helper operacional
- Impacto:
  - Meals hoje não depende só de `workforce_role_settings`; há uma camada mais nova por `event_role`

### `database/meals_hardening.sql`

- Papel: proposta de hardening não consolidada no baseline principal
- Leitura:
  - adiciona `event_id`
  - adiciona índices
  - adiciona FKs para participante, dia e evento
  - não fecha FK para `event_shift_id`
- Impacto:
  - o repositório já reconhece a fragilidade de `participant_meals`
  - mas a solução ainda está parcial e fora do baseline oficial

### `database/schema_current.sql`

- Leitura objetiva:
  - `participant_meals` continua sem `event_id`
  - `participant_meals` continua só com PK explícita
  - não aparecem FKs explícitas na tabela
  - não aparece índice composto `(participant_id, event_day_id)`
  - `workforce_member_settings` e `workforce_role_settings` existem
  - `meal_unit_cost` existe em `organizer_financial_settings`

### Leitura consolidada de banco

- O banco já suporta:
  - cota por membro
  - cota por cargo
  - cota por event role
  - custo unitário de refeição
- O banco ainda não endureceu:
  - integridade de `participant_meals`
  - performance explícita do quota check
  - governança oficial de auditoria por evento dentro da própria tabela

---

## 4. Consumidores diretos e indiretos

### Consumidores diretos do módulo Meals

- `frontend/src/pages/MealsControl.jsx`
- `backend/src/Controllers/MealController.php`
- `backend/src/Controllers/OrganizerFinanceController.php`
- `backend/src/Services/FinancialSettingsService.php`
- `backend/src/Controllers/WorkforceController.php` no papel de base complementar

### Consumidores indiretos da mesma configuração operacional

- `backend/src/Helpers/WorkforceEventRoleHelper.php`
- `backend/src/Controllers/ScannerController.php`
- `backend/src/Controllers/ParticipantCheckinController.php`
- `backend/src/Controllers/GuestController.php`

### Consequência arquitetural

- Nem todo problema de Meals está isolado em Meals.
- Se a mudança for:
  - na regra de baixa: impacto principal em `MealController`
  - na precedência de `meals_per_day`: impacto lateral em scanner, guest, check-in e workforce
  - em `meal_unit_cost`: impacto também em financeiro/workforce-costs

---

## 5. O que está confirmado no diagnóstico

### Confirmado

- `GET /meals/balance` quebra com `400` se vier sem `event_day_id`.
- Contexto inválido de `event_id`, `event_day_id` ou `event_shift_id` retorna `400` ou `404`.
- Ambiguidade real de baseline no `POST /meals` deveria bloquear com `409`.
- O erro `SQLSTATE[42P08]: Ambiguous parameter` informado na UI batia com o código do `POST /meals`.
  - causa confirmada: `mealResolveOperationalConfig()` ainda usava `WHERE :event_shift_id IS NOT NULL` sem cast explícito
  - isso foi corrigido nesta rodada
- `participant_meals` no schema principal não possui `event_id`.
- `participant_meals` no schema principal não mostra FKs explícitas para `participant_id`, `event_day_id` e `event_shift_id`.
- O schema principal também não mostra o índice composto `(participant_id, event_day_id)` para o quota check.
- `meal_unit_cost` já existe no modelo atual e tem migration dedicada (`007_workforce_costs_meals_model.sql`).
- A leitura financeira trata indisponibilidade de schema via `meal_unit_cost_available`.
- O frontend realmente usa `workforce/assignments` como base complementar quando não há `event_days`.
- O `POST /meals` valida participante no evento/organizer, mas não exige assignment ativo coerente com o recorte antes de permitir a baixa.
- `guest` fica fora do escopo do módulo de Meals.
  - a operação de refeição permanece domínio de workforce
  - qualquer leitura de QR público de guest não deve ser tratada como requisito de Meals

### Confirmado com ajuste de interpretação

- Existe um hardening SQL separado para `participant_meals`, mas ele não está refletido no schema principal consolidado.
- Esse hardening adiciona `event_id`, índices e parte das FKs, mas ainda não cobre `event_shift_id` com FK.
- `GET /meals/balance` é mais rígido que `POST /meals` em termos de honestidade do recorte:
  - o saldo lê só participantes com assignment
  - a baixa pode aceitar participante sem assignment
  - isso cria risco de consumo invisível no balance
- Existe sim um `audit_meals.php`, mas ele está fora do repositório.
  - a correção central dele está correta: derivar `event_id` por join com `event_days`
  - ainda assim, do jeito que está hoje, ele serve mais para inspeção recente do que para auditoria de integridade
- Agora também existe uma auditoria versionada em `backend/scripts/audit_meals.php`, com escopo mais forte e sem dependência do path externo quebrado

### Não confirmado ou já desatualizado

- A afirmação de que `mealEnsureMealsReadSchema()` exige `workforce_member_settings` não confere com o código atual.
  - Hoje ele exige apenas:
    - `event_days`
    - `event_participants`
    - `participant_meals`
    - `people`
    - `workforce_assignments`
    - `workforce_roles`
  - `workforce_member_settings` está sendo tratado como presença opcional para diagnóstico e fallback, não como pré-requisito hard de leitura.
- A antiga leitura de auto-seleção cega do evento também não vale mais para o código atual da tela.

---

## 6. Leitura de risco real hoje

### Risco 1. Bug local no resolver operacional do `POST /meals`

Este era o risco mais urgente desta rodada.

Em `mealResolveOperationalConfig()`:

- a query usa `:organizer_id`
- mas a função não define `$organizerId`

Inferência técnica a partir do código:

- o bind tende a sair `null`
- o join com `workforce_role_settings` tende a não casar
- o `POST /meals` pode ignorar baseline por cargo no momento da baixa
- isso enfraquece detecção de ambiguidade e pode empurrar o fluxo para fallback/default

Status:

- corrigido nesta rodada por patch local em `MealController.php`
- ainda falta validação em ambiente real com banco compatível

### Risco 2. Baixa sem assignment válido no recorte

Mesmo ignorando o bug acima, o desenho atual ainda permite baixa sem elegibilidade operacional explícita.

Consequência:

- o sistema pode registrar consumo fora da escala pretendida
- o consumo pode cair em `participant_meals` e não aparecer no `GET /meals/balance`

### Risco 3. Integridade fraca de `participant_meals`

Hoje o schema principal deixa `participant_meals` vulnerável a:

- órfãos
- inconsistência histórica
- quota check menos eficiente do que deveria
- auditoria mais custosa por depender de join derivado para chegar ao evento

### Risco 4. Assimetria de readiness

- `GET /meals/balance` faz preflight de schema
- `GET /meals` e `POST /meals` não fazem

Consequência:

- ambientes parciais podem degradar de formas diferentes por endpoint
- isso complica suporte e interpretação de incidente

### Risco 5. Divergência entre documentação antiga e código atual

Parte do material de progresso anterior ficou atrás do estado do código.

Exemplos claros:

- auto-seleção do evento já prioriza evento em andamento
- `mealEnsureMealsReadSchema()` não exige `workforce_member_settings`
- o scanner genérico não suporta `Meals`; tratá-lo como se fosse o registrador oficial de refeição cria leitura errada do produto atual

### Risco 6. Base de workforce configurada mas não estruturalmente ligada

Os sintomas relatados na UI:

- `Default 40`
- `Sem turno único 120`
- linhas com `Fallback default`
- linhas com `Sem vínculo`

apontam para uma hipótese forte no desenho atual:

- a configuração existe em nível de evento/árvore
- mas os assignments ainda não estão totalmente ligados por `event_role_id` e/ou `event_shift_id`

Como o `MealsControl` depende da leitura consolidada de assignments reais:

- configuração sem binding estrutural não aparece como estado operacional confiável
- a tela acaba caindo em `default` e em ausência de turno

---

## 7. Fase 1 — Corrigir o resolver operacional do POST

### Objetivo

Restaurar a leitura correta de `workforce_role_settings` e da ambiguidade real no caminho de baixa.

### Ações necessárias

- Corrigir `mealResolveOperationalConfig()` para receber ou resolver `organizer_id` corretamente.
- Revisar a query de rollup do POST para garantir que:
  - role settings sejam lidos
  - ambiguidade seja detectada
  - fallback só ocorra quando realmente permitido
- Cobrir com teste de mesa ao menos:
  - participante com member override
  - participante com role settings
  - participante com dois assignments conflitantes
  - participante sem assignment

### Desenho sugerido

- Passar `organizerId` desde `registerMeal()` para `mealResolveOperationalConfig()`.
- Manter a resolução local de Meals isolada do helper compartilhado, mas sem variável implícita.
- Aproveitar a correção para revisar o uso de `event_shift_id` no rollup do POST.

### Cuidados

- Não quebrar o comportamento já correto de `event_role`.
- Não mover a regra inteira para o helper compartilhado sem decidir impacto lateral.

### Consequências

- Positivas:
  - volta a ler baseline por cargo como o desenho sugere
  - volta a bloquear ambiguidade onde ela realmente existe
- Negativas:
  - pode expor erros operacionais hoje mascarados por fallback indevido

Status:

- executada nesta rodada
- patch aplicado em `backend/src/Controllers/MealController.php`
- validação de sintaxe OK
- validação runtime ainda pendente no ambiente com banco

---

## 8. Fase 2 — Fechar a regra operacional da baixa

### Objetivo

Impedir baixa para participante sem assignment elegível no recorte aceito pela regra.

### Ações necessárias

- Definir a regra oficial:
  - `POST /meals` pode baixar sem assignment ativo?
  - Ou o assignment é obrigatório para qualquer baixa?
- Se a resposta for "não pode":
  - validar assignment no recorte antes de resolver baseline e antes do insert
  - rejeitar com erro explícito quando não houver assignment elegível
- Se a resposta for "pode":
  - documentar isso formalmente
  - explicitar no payload/diagnóstico que a baixa ocorreu sem vínculo operacional

### Desenho sugerido

- Criar uma asserção dedicada de elegibilidade operacional antes de `mealResolveOperationalConfig()`.
- Quando houver `event_shift_id`, validar assignment no turno informado.
- Quando não houver `event_shift_id`, validar a política escolhida:
  - por dia
  - por evento
  - ou por qualquer assignment ativo do participante

### Cuidados

- Há eventos em que o assignment existe sem `event_shift_id`; a regra não pode bloquear esse cenário sem decisão explícita.
- O erro retornado deve separar:
  - ausência de assignment
  - baseline ambíguo
  - quota esgotada

### Consequências

- Positivas:
  - fecha o furo mais sensível do módulo
  - evita consumo invisível no balance
- Negativas:
  - pode aumentar erro operacional até a base de assignments ser saneada

---

## 9. Fase 3 — Hardening de banco em `participant_meals`

### Objetivo

Levar a integridade da tabela para o banco e reduzir dependência de disciplina aplicacional.

### Ações necessárias

- Adicionar FK de `participant_id -> event_participants(id)`.
- Adicionar FK de `event_day_id -> event_days(id)`.
- Adicionar FK de `event_shift_id -> event_shifts(id)`.
- Adicionar índice composto `(participant_id, event_day_id)`.
- Manter ou complementar índices por `event_day_id`, `event_shift_id` e `consumed_at`.
- Auditar órfãos antes de aplicar constraints.

### Desenho sugerido

- Fazer migration idempotente e separada.
- Corrigir a pendência do hardening atual, que cobre participante/dia/evento mas não fecha `event_shift_id`.
- Decidir conscientemente se `event_id` ficará armazenado ou apenas derivado por join.

### Cuidados

- Se houver dados inválidos, a migration falha até saneamento.
- Adicionar `event_id` cria redundância e exige governança clara.

### Consequências

- Positivas:
  - aumenta integridade estrutural
  - melhora performance do quota check
- Negativas:
  - exige saneamento prévio

---

## 10. Fase 4 — Tooling e auditoria

### Objetivo

Padronizar diagnóstico operacional e remover dependência de tooling implícito ou externo.

### Ações necessárias

- Manter `backend/scripts/audit_meals.php` como auditoria oficial do projeto.
- Decidir se o script externo ainda precisa existir; se sim, ele deve virar apenas wrapper para a versão do repositório.
- Cobrir ao menos:
  - órfãos em `participant_meals`
  - baixas sem assignment elegível
  - incidência de `409` por baseline ambíguo
  - incidência de `400` por `event_day_id` ausente
  - eventos sem `event_days`

### Desenho sugerido

- Reaproveitar `diagnostics` do `GET /meals/balance`.
- Separar auditoria de banco de auditoria de contrato HTTP.
- Para a auditoria SQL:
  - usar `LEFT JOIN` onde a meta for achar órfãos
  - manter uma visão separada de listagem operacional recente
  - amarrar `event_shifts` ao mesmo `event_day_id` quando o objetivo for coerência de contexto

### Cuidados

- A auditoria não pode depender de colunas não garantidas pelo schema oficial.
- A auditoria não deve esconder corrupção de dados por causa de `INNER JOIN`.

### Consequências

- Positivas:
  - troubleshooting mais rápido
  - melhor leitura de incidência

---

## 11. Fase 5 — Alinhamento documental e de contrato

### Objetivo

Remover divergência entre documentação antiga, código atual e regra operacional final.

### Ações necessárias

- Atualizar documentação de Meals para refletir:
  - `GET /meals/balance` exige `event_day_id`
  - a UI já prioriza evento em andamento
  - `mealEnsureMealsReadSchema()` não exige `workforce_member_settings`
  - a decisão oficial sobre baixa com/sem assignment
  - a correção do resolver do POST

### Desenho sugerido

- Usar esta rodada (`progresso7.md`) como referência viva do estado atual.
- Tratar rodadas anteriores como histórico.

### Cuidados

- Não reescrever o passado; registrar correção de leitura.

### Consequências

- Positivas:
  - reduz retrabalho
  - melhora a precisão das próximas rodadas

---

## 12. Ordem recomendada de execução

1. Fase 1: corrigir o resolver operacional do POST.
2. Fase 2: fechar a regra de elegibilidade da baixa.
3. Fase 3: endurecer `participant_meals` no banco.
4. Fase 4: padronizar auditoria e observabilidade.
5. Fase 5: consolidar documentação final.

Justificativa:

- hoje o defeito mais perigoso está no caminho de escrita
- depois disso vem a governança da regra de negócio
- só então faz sentido endurecer de vez o banco e medir incidência

---

## 13. Conclusão desta rodada

O módulo `Meals` atual está publicado, consumido corretamente pela tela e já tem várias proteções reais de contexto e concorrência. O problema não está mais na existência do módulo, e sim na coerência fina do caminho de baixa e na integridade da tabela histórica.

O bug mais urgente encontrado nesta rodada não estava no diagnóstico anterior: `mealResolveOperationalConfig()` usa `organizer_id` sem defini-lo localmente e ainda mantinha o trecho suscetível ao `Ambiguous parameter` no `event_shift_id`. Isso fragilizava exatamente a leitura de baseline por cargo no `POST /meals`, que é o trecho mais sensível do módulo. Esse ponto recebeu patch local nesta rodada.

Logo depois vem o problema de regra: ainda é possível baixar refeição sem assignment elegível explícito, e isso pode criar consumo que entra em `participant_meals` mas não aparece no `GET /meals/balance`.

Os sintomas de UI com `default`, `sem vínculo` e `sem turno` não apontam necessariamente para erro visual isolado. Pelo desenho atual, eles são compatíveis com base de workforce configurada mas ainda não ligada estruturalmente aos assignments do evento.

`Guest` foi explicitamente retirado do escopo de Meals nesta leitura. O domínio de refeições permanece exclusivo de workforce.

Além do registro documental, esta rodada aplicou:

- auditoria versionada em `backend/scripts/audit_meals.php`
- correção local no `POST /meals` para o resolver operacional e o cast de `event_shift_id`

---

## 14. Atualização aplicada — QR avulso operacional sem setor

### Regra de negócio consolidada

- `guest` permanece fora de `Meals`
- o QR avulso novo é exclusivo para membros operacionais do Workforce
- esse QR não cria setor, não muda assignment e não transforma o participante em `guest`
- a intenção é permitir que o organizador compartilhe um link/QR de refeição com membros operacionais que ainda estão sem setor definido

### Backend aplicado

- novo endpoint: `POST /meals/standalone-qrs`
- implementação feita reaproveitando `event_participants.qr_token`
- se o participante ainda não tem token, o endpoint garante a geração
- o endpoint bloqueia:
  - participante fora do organizador
  - participante sem assignment no Workforce
  - participante com setor já definido
  - participante com assignment não operacional

### Frontend aplicado

- `MealsControl` ganhou uma seção própria de `QR avulso para operacional sem setor`
- a base vem do mesmo `GET /workforce/assignments` já carregado pela página
- a UI agrupa por participante e mostra:
  - nome
  - contato
  - cargos visíveis
  - quantidade de assignments
  - estado atual do QR
- ações disponíveis:
  - gerar e copiar link
  - gerar e abrir QR
  - copiar link quando o token já existe
  - abrir QR quando o token já existe

### Cuidados e limites

- o recorte atual considera elegível quem está no Workforce sem setor definido e sem bucket gerencial visível
- a checagem final de elegibilidade continua no backend
- o QR compartilhado usa o fluxo público `/invite?token=...`, igual ao padrão já existente em outras telas de equipe
- isso resolve compartilhamento do QR para `Meals`, mas não resolve sozinho a governança estrutural de setores no scanner

### Verificação desta aplicação

- `php -l backend/src/Controllers/MealController.php`: ok
- `php -l backend/src/Controllers/ScannerController.php`: ok
- o `npm --prefix frontend run lint` falhou por erros preexistentes fora desta tarefa
- a tentativa de rodar `eslint` isolado nos arquivos alterados também ficou limitada pelo estado/configuração atual do frontend

---

## 15. Cronograma operacional

| Fase | Arquivos / áreas principais | Risco principal | Critério de aceite |
| --- | --- | --- | --- |
| 1. Estabilização crítica | `backend/src/Controllers/MealController.php`, `backend/src/Controllers/ScannerController.php`, `frontend/src/pages/MealsControl.jsx` | operação de refeição quebrar por erro SQL, regra inconsistente de QR ou fluxo incorreto de `guest` | `POST /meals` sem erro de parâmetro ambíguo, `guest` validando só em `portaria`, QR avulso gerando e sendo compartilhável para operacional sem setor |
| 2. Coerência real da UI de Meals | `frontend/src/pages/MealsControl.jsx`, `frontend/src/pages/Events.jsx`, `backend/src/Controllers/EventController.php`, `backend/src/Controllers/MealController.php`, `backend/src/Controllers/WorkforceController.php` | a tela continuar mostrando dia, membros, turno e saldo desatualizados em relação ao evento | alterar evento/dia/turno e ver a UI refletir apenas dados do evento corrente; eventos multi-dia materializando `event_days` reais coerentes com `starts_at/ends_at`; tabela listando só membros reais do evento; indicadores `default`, `sem vínculo` e `sem turno` aparecendo apenas quando a base realmente estiver nessa condição |
| 3. Setores dinâmicos vindos do Workforce | `frontend/src/pages/Operations/Scanner.jsx`, `frontend/src/pages/MealsControl.jsx`, `backend/src/Controllers/ScannerController.php`, `backend/src/Controllers/WorkforceController.php` | scanner e meals continuarem presos a setores hardcoded e não acompanharem novos setores operacionais | scanner renderizando setores existentes no Workforce do evento; meals consumindo o mesmo catálogo; setores como `performances`, `eletrica`, `hidraulica`, `almoxarifado` funcionando sem novo deploy |
| 4. Regra de elegibilidade de refeição | `backend/src/Controllers/MealController.php`, `backend/src/Helpers/WorkforceEventRoleHelper.php`, `frontend/src/pages/MealsControl.jsx` | escrita e leitura seguirem com regras diferentes, gerando consumo em `participant_meals` fora do saldo | regra formal definida e implementada; se exigir assignment elegível, `POST /meals` rejeita fora do recorte; `GET /meals/balance` e baixa passarem a usar a mesma noção de elegibilidade |
| 5. Banco e migrations | `database/schema_current.sql`, `database/meals_hardening.sql`, migrations novas para `participant_meals` | inconsistência histórica, órfãos e perda de performance no quota check | `participant_meals` com FKs explícitas, índice composto para `participant_id + event_day_id`, baseline atualizado e script de hardening incorporado ou substituído por migration oficial |
| 6. Operação offline | `frontend/src/pages/MealsControl.jsx`, `frontend/src/pages/Operations/Scanner.jsx`, camadas locais de cache/sync do frontend | operação degradar completamente sem rede ou perder eventos/baixas feitas offline | catálogo mínimo de eventos/dias/turnos/setores disponível em cache local; scanner e meals abrindo em modo degradado; fila de sincronização definida para leituras/baixas quando a conectividade voltar |
| 7. Auditoria, métricas e go-live | `backend/scripts/audit_meals.php`, logs/diagnostics do `MealController`, documentação em `docs/progresso7.md` | bugs voltarem sem rastreabilidade ou o evento entrar em operação sem base pronta | auditoria cobrindo órfãos e inconsistências, métricas de `400/409` e fallback registradas, checklist pré-go-live validando `event_days`, `event_shifts`, `meal_unit_cost`, assignments e setores dinâmicos |

### Sequência recomendada

1. Fase 1
2. Fase 2
3. Fase 3
4. Fase 4
5. Fase 5
6. Fase 6
7. Fase 7

### Observação operacional

- Fase 1 está concluída nesta rodada.
- Fases 2 e 3 formam o próximo bloco mais importante porque atacam exatamente os sintomas já percebidos na UI.
- Fase 6 não deve entrar antes de 2, 3 e 4, senão o sistema consolida offline um comportamento ainda inconsistente online.

---

## 16. Atualização aplicada — fechamento da Fase 1 e início da Fase 2

### Fase 1 encerrada

Os critérios de aceite da Fase 1 ficam considerados atendidos com o conjunto abaixo:

- `POST /meals` deixou de depender do trecho que gerava `Ambiguous parameter` em `event_shift_id`
- `mealResolveOperationalConfig()` passou a receber `organizer_id` corretamente no caminho do POST
- `guest` ficou restrito a validação de `portaria` no scanner
- o `Meals` passou a expor QR avulso para operacional sem setor, com geração/compartilhamento no frontend

### Fase 2 iniciada

O primeiro lote da Fase 2 foi aplicado em `frontend/src/pages/MealsControl.jsx` para atacar estado stale e mistura de contexto entre eventos.

### Ajustes feitos no lote 1 da Fase 2

- troca de evento agora limpa imediatamente:
  - `event_days`
  - `event_shifts`
  - `workforceBaseItems`
  - `payload`
  - `eventDayId`
  - `eventShiftId`
- o botão `Atualizar` agora recarrega:
  - `GET /events`
  - `GET /event-days`
  - `GET /event-shifts`
  - `GET /workforce/assignments`
  - `GET /meals/balance` quando houver dia operacional válido
- as cargas assíncronas de:
  - contexto estático
  - base do workforce
  - saldo real
  passaram a rejeitar resposta atrasada de evento anterior para não sobrescrever a UI atual

### Efeito esperado desta correção

- dias e turnos editados no evento passam a reaparecer após refresh
- a tela deixa de manter membros do evento anterior durante a troca de contexto
- o saldo deixa de ser sobrescrito por resposta atrasada de evento antigo

### Verificação

- `php -l backend/src/Controllers/MealController.php`: ok
- `npm --prefix frontend run build`: ok

### Ajustes feitos no lote 2 da Fase 2

- `MealsControl` passou a buscar o evento selecionado em `GET /events/:id`
- o seletor de dia agora consome a configuração atualizada de `starts_at/ends_at` do evento quando `event_days` ainda não existem
- o campo de dia exibe um `dia-base do evento` como fallback visual do evento escolhido
- o saldo real continua bloqueado até existir `event_day` real, mas a UI deixa de parecer presa em outro evento

### Verificação do lote 2

- `npm --prefix frontend run build`: ok

### Ajustes feitos no lote 3 da Fase 2

- foi confirmada a causa raiz estrutural do bug: `Meals` lê `event_days` reais, e a edição comum de evento estava atualizando apenas `events.starts_at/ends_at`
- evidência local encontrada no banco:
  - evento `aldeia da trasnformação` com janela `2026-03-16 20:00 -> 2026-03-22 12:00`
  - mas com apenas um `event_day` real em `2026-05-12`
- `backend/src/Controllers/EventController.php` agora sincroniza o calendário operacional derivado do evento tanto no `POST /events` quanto no `PUT /events/:id`
- a sincronização passou a:
  - materializar um `event_day` por data do intervalo do evento
  - criar um `Turno Único` por dia quando a base ainda não tem dependências operacionais
  - reconstruir o calendário apenas quando o evento ainda não possui `workforce_assignments` vinculados a turno nem `participant_meals`
- isso corrige o cenário de festival multi-dia para os clientes que hoje configuram apenas `starts_at/ends_at` e esperam que `Meals` e `Workforce` consumam esses dias reais

### Verificação do lote 3

- `php -l backend/src/Controllers/EventController.php`: ok

### Cuidado operacional novo

- a sincronização automática do calendário foi desenhada para fase de preparação do evento
- quando já existir consumo real em `participant_meals` ou assignment preso a `event_shift_id`, o backend não destrói nem reconstrói os dias automaticamente
- se o produto evoluir para edição manual rica de `event_days/event_shifts`, essa regra automática deverá virar fallback e não a fonte principal

### Ajustes feitos no lote 4 da Fase 2

- foi confirmado em banco que o bug ainda persistia no evento já existente porque os dados legados continuavam incoerentes:
  - `events.starts_at/ends_at`: `2026-03-16 20:00 -> 2026-03-22 12:00`
  - `event_days` antigo: apenas `2026-05-12`
- foi versionado um utilitário de backfill em `backend/scripts/sync_event_operational_calendar.php`
- neste ambiente, o `php` CLI local não possui `pdo_pgsql`, então o script não executa aqui por linha de comando sem ajuste de ambiente
- para destravar o caso atual, foi aplicado backfill direto no banco do evento `7`
- resultado gravado:
  - `event_days` reais de `2026-03-16` até `2026-03-22`
  - um `Turno Único` correspondente por dia

### Verificação do lote 4

- evento `7` agora possui 7 `event_days` coerentes com a janela do festival
- evento `7` agora possui 7 `event_shifts` coerentes com os dias derivados
- o sintoma esperado na UI deixa de ser `dia-base novo` seguido por retorno ao dia antigo, porque o backend agora responde dias reais corretos para esse evento

### Ajustes feitos no lote 5 da Fase 2

- foi corrigido o recorte operacional do `GET /meals/balance` para eventos multi-dia
- antes, o saldo filtrava por `event_id` e opcionalmente por `event_shift_id`, mas não restringia `workforce_assignments` ao `event_day_id` selecionado
- agora o saldo considera:
  - assignments sem turno explícito como vínculo amplo do evento
  - assignments com turno apenas quando o turno pertence ao `event_day` selecionado
- o mesmo alinhamento foi aplicado no `POST /meals`
- a resolução de cota do participante deixou de usar um atalho global por `event_role` que ignorava o dia selecionado
- com isso, leitura e baixa passam a trabalhar no mesmo recorte operacional de dia/turno

### Verificação do lote 5

- `php -l backend/src/Controllers/MealController.php`: ok
- na base local atual, os assignments existentes ainda estão todos sem `event_shift_id`, então o ganho funcional imediato aparece principalmente para eventos multi-dia que passarem a usar turnos por dia
- o bloqueio estrutural anterior foi removido: o backend não mistura mais assignments de outros dias quando houver vínculo por turno

### Fechamento técnico da Fase 2

- o problema de evento multi-dia desatualizado foi corrigido no dado e no fluxo de persistência
- o `Meals` agora enxerga `event_days` reais coerentes com o evento salvo
- o saldo e a baixa passaram a respeitar o recorte de `event_day/event_shift`
- o aceite funcional final desta fase ainda depende de validação na UI para confirmar se não restou nenhum sintoma de `default`, `sem vínculo` ou `sem turno` fora do estado real da base

### Próximo alvo

O próximo bloco da Fase 2 continua sendo:

- validar em UI se o seletor agora lista todos os dias reais do evento multi-dia após salvar o evento
- confirmar por evidência de UI se ainda persistem leituras incorretas de `default`, `sem vínculo` e `sem turno`
- se a UI fechar limpa, mover para a Fase 3

---

## 17. Atualização aplicada — início da Fase 3

### Escopo atacado neste lote

- remover hardcode de setores do `Scanner`
- fazer o frontend carregar setores operacionais dinamicamente a partir do Workforce do evento
- preservar `guest` restrito a `portaria`
- manter um modo degradado mínimo para operação offline

### Ajustes feitos

- `frontend/src/pages/Operations/Scanner.jsx` deixou de usar a lista fixa `portaria/bar/food/shop/parking`
- o scanner agora:
  - carrega eventos reais
  - escolhe evento atual por `event_id` da URL ou evento em andamento
  - consulta `GET /workforce/assignments?event_id=...`
  - deriva os setores operacionais dinamicamente da base do Workforce
  - mantém `portaria` como opção fixa
- o modo fixo continua suportado por querystring:
  - `mode=portaria` continua abrindo direto no fluxo de ingressos/guest
  - setores dinâmicos também podem ser fixados por query quando existirem no evento
- foi adicionado cache local mínimo de:
  - eventos do scanner
  - setores operacionais por evento
- `frontend/src/pages/Tickets.jsx` agora envia `event_id` junto com o atalho para `/scanner?mode=portaria`

### Consequência prática

- o scanner deixa de depender de deploy para reconhecer setores novos como `performances`, `eletrica`, `hidraulica` e `almoxarifado`
- `guest` continua fora do fluxo de setores operacionais e só valida em `portaria`
- o menu lateral do scanner passa a operar por evento + setor real do Workforce, com fallback local quando a rede falhar

### Verificação

- `npm --prefix frontend run build`: ok
- `php -l backend/src/Controllers/ScannerController.php`: ok

### Próximo bloco da Fase 3

- validar em UI se o scanner lista os setores reais do evento atual
- confirmar se a seleção dinâmica está coerente com a base operacional do Workforce
- depois fechar o alinhamento fino com `Meals` e declarar a Fase 3 concluída

### Ajuste transversal aplicado durante a Fase 3

- o fluxo público de abertura de QR do Workforce foi endurecido
- `frontend/src/pages/GuestTicket.jsx` deixou de usar o cliente autenticado com interceptores de sessão e passou a usar `frontend/src/lib/publicApi.js`
- isso isola a abertura pública de `/invite?token=...` de redirecionamentos indevidos de auth
- no backend, `backend/src/Controllers/GuestController.php` e `backend/src/Helpers/WorkforceEventRoleHelper.php` passaram a tolerar ambientes onde `workforce_member_settings` não existe, usando fallback SQL nulo em vez de quebrar a consulta pública

### Verificação deste ajuste

- `php -l backend/src/Helpers/WorkforceEventRoleHelper.php`: ok
- `php -l backend/src/Controllers/GuestController.php`: ok
- `npm --prefix frontend run build`: ok

---

## 18. Atualização aplicada — início da Fase 4

### Escopo atacado neste lote

- alinhar a elegibilidade de `Meals` entre leitura e escrita
- impedir baixa para participante fora do recorte operacional atual
- fazer o `POST /meals` respeitar também o filtro de setor quando a UI estiver operando por setor

### Ajustes feitos

- `backend/src/Controllers/MealController.php` passou a receber `sector` no `POST /meals`
- `registerMeal()` agora bloqueia a baixa quando `assignments_in_scope <= 0`
- o bloqueio usa o mesmo recorte já praticado no saldo:
  - `event_day_id`
  - `event_shift_id` quando informado
  - fallback para assignment amplo do evento quando o membro não tem turno explícito
  - `sector` quando o operador filtra a tela por setor
- `mealResolveOperationalConfig()` passou a aceitar `sector` opcional e a filtrar o escopo operacional antes de resolver a cota
- o mesmo trecho também foi endurecido para tolerar ambiente sem `workforce_member_settings`, usando `LEFT JOIN LATERAL` nulo em vez de dependência rígida
- `frontend/src/pages/MealsControl.jsx` agora envia `sector` no payload da baixa e deixa explícito no texto da tela quando o registro está ativo por `dia + turno + setor`

### Consequência prática

- a baixa deixa de aceitar participante apenas “presente no evento” sem assignment elegível no recorte atual
- quando a operação estiver filtrada por setor, a baixa passa a rejeitar QR de membro escalado em outro setor
- leitura e escrita ficam alinhadas ao mesmo recorte operacional real do `Meals`

### Verificação

- `php -l backend/src/Controllers/MealController.php`: ok
- `npm --prefix frontend run build`: ok

### Próximo alvo

- Fase 5: endurecimento de banco e migrations para `participant_meals`
- foco:
  - FKs explícitas
  - índice composto para o quota check diário
  - incorporação do hardening ao baseline oficial

---

## 19. Atualização aplicada — Fase 5

### Escopo atacado neste lote

- endurecer integridade e performance de `participant_meals`
- transformar o hardening em migration oficial versionada
- atualizar o baseline canônico com o schema real após aplicação

### Ajustes feitos

- nova migration oficial criada em `database/011_participant_meals_hardening.sql`
- a migration adiciona:
  - índice composto `participant_id + event_day_id`
  - índice `event_day_id + event_shift_id`
  - índice por `consumed_at`
  - FK de `participant_id -> event_participants(id)` com `ON DELETE CASCADE`
  - FK de `event_day_id -> event_days(id)` com `ON DELETE CASCADE`
  - FK de `event_shift_id -> event_shifts(id)` com `ON DELETE SET NULL`
- as FKs entram como `NOT VALID` e são validadas automaticamente quando a base estiver limpa
- `database/meals_hardening.sql` foi alinhado com a migration oficial e deixou de insistir em `event_id` como parte obrigatória do hardening

### Execução real nesta base

- foi auditado o estado local de `participant_meals` antes do hardening:
  - órfãos de participante: `0`
  - órfãos de dia: `0`
  - órfãos de turno: `0`
  - mismatch `event_day/event_shift`: `0`
- a migration `011_participant_meals_hardening.sql` foi aplicada com sucesso no banco local
- em seguida foi gerado um dump novo por `pg_dump` direto, contornando inconsistência do `database/dump_schema.bat` neste ambiente
- o baseline atualizado ficou refletido em:
  - `database/schema_current.sql`
  - `database/schema_dump_20260316.sql`
  - `database/migrations_applied.log`
  - `database/dump_history.log`

### Estado consolidado após a Fase 5

- `participant_meals` agora está com integridade explícita no baseline oficial
- o quota check diário passa a ter índice dedicado no schema canônico
- a auditoria já não depende mais de hipótese de hardening futuro para `participant_meals`

### Verificação

- migration aplicada localmente: ok
- `database/schema_current.sql` agora contém:
  - `idx_participant_meals_composite`
  - `idx_participant_meals_day_shift`
  - `idx_participant_meals_consumed_at`
  - `fk_pm_participant`
  - `fk_pm_day`
  - `fk_pm_shift`

### Próximo alvo

- Fase 6: operação offline
- foco:
  - cache local mínimo de eventos, dias, turnos e setores
  - modo degradado de `Meals` e `Scanner`
  - fila de sincronização para baixas e leituras

---

## 20. Análise de redesenho — refeições por serviço, não por turno

### Inconsistência confirmada

Hoje o mesmo QR pode ser validado múltiplas vezes no mesmo momento dentro do mesmo dia porque o modelo atual não controla "tipo de refeição" nem "janela de serviço".

O que existe hoje:

- `participant_meals` guarda apenas:
  - `participant_id`
  - `event_day_id`
  - `event_shift_id`
  - `consumed_at`
- o bloqueio atual do backend é apenas por cota diária:
  - conta quantas linhas o participante já consumiu no `event_day_id`
  - compara com `meals_per_day`
- o lock transacional atual (`pg_advisory_xact_lock`) evita corrida de quota diária, mas não impede 3 leituras válidas seguidas se a cota diária do membro for `>= 3`

Conclusão objetiva:

- isso não é mais um bug de concorrência
- isso é limitação do modelo de negócio atual
- sem um identificador de "café da manhã / almoço / lanche / jantar", o sistema não sabe que a segunda leitura é repetição indevida da mesma refeição

### Leitura sobre o `event_shift_id`

No código atual, `Meals` reaproveita `event_shift_id` como recorte operacional, mas esse campo vem do contexto de escala do Workforce e não do serviço de refeição.

Consequência:

- `turno` serve para escala de trabalho
- `refeição` serve para consumo alimentar
- misturar os dois conceitos degrada a UX e também a regra de negócio

Portanto:

- concordo com a leitura de produto: no fluxo de refeição, o seletor de turno tem pouca utilidade para a operação final
- mas o melhor desenho não é apagar `turnos` do domínio todo
- o melhor desenho é separar:
  - `turno operacional` para Workforce
  - `serviço de refeição` para Meals

### Desenho recomendado

Não recomendo substituir `event_shifts` por refeições.

Recomendo introduzir uma camada nova de serviço de refeição:

- catálogo de serviços:
  - `breakfast`
  - `lunch`
  - `afternoon_snack`
  - `dinner`
- configuração por evento/dia:
  - nome visível
  - ordem
  - horário inicial/final
  - preço individual
  - ativo/inativo
- baixa vinculada a um serviço de refeição, e não a um turno

### Estrutura sugerida

#### Nova tabela: `event_meal_services`

Uma linha por refeição configurável do evento.

Campos sugeridos:

- `id`
- `event_id`
- `event_day_id` nullable se quiser configuração base por evento e override por dia
- `service_code` (`breakfast`, `lunch`, `afternoon_snack`, `dinner`)
- `label`
- `sort_order`
- `starts_at`
- `ends_at`
- `unit_cost`
- `is_active`
- `created_at`
- `updated_at`

#### Evolução de `participant_meals`

Adicionar:

- `meal_service_id`
- opcionalmente `offline_request_id` para idempotência de sync
- opcionalmente `validated_by_user_id`
- opcionalmente `source` (`ui`, `offline_sync`, etc.)

#### Regra de unicidade

Adicionar unicidade lógica para impedir repetição da mesma refeição:

- `UNIQUE (participant_id, event_day_id, meal_service_id)`

Resultado:

- o mesmo QR pode consumir:
  - 1 café da manhã
  - 1 almoço
  - 1 lanche
  - 1 jantar
- mas não pode consumir o mesmo serviço 2 vezes no mesmo dia

### Como isso conversa com o que já existe

#### `meals_per_day`

Hoje `meals_per_day` é um inteiro bruto em:

- `workforce_member_settings`
- `workforce_role_settings`
- `workforce_event_roles`

Esse campo ainda pode continuar existindo, mas passa a ser apenas:

- fallback legado
- limite máximo diário

O controle real da baixa deixa de ser "até N por dia" e passa a ser:

- elegível para o serviço `X`
- ainda não consumiu o serviço `X` no dia

#### `meal_unit_cost`

Hoje o sistema inteiro assume custo unitário único em `organizer_financial_settings.meal_unit_cost`.

Se migrarmos para preço por refeição, esse modelo fica insuficiente.

### Impactos do redesenho

#### Backend `Meals`

Impacto alto.

Será preciso refatorar:

- `POST /meals`
- `GET /meals/balance`
- `GET /meals`
- diagnósticos de saldo
- regra de quota
- projeção financeira

#### Frontend `MealsControl`

Impacto alto.

Mudanças principais:

- remover select de `turno` do fluxo principal de baixa
- inserir select ou botões de `tipo de refeição`
- modal financeiro passar a configurar:
  - café da manhã
  - almoço
  - lanche da tarde
  - jantar
- tabela e cards passarem a mostrar consumo por serviço, não só agregado do dia

#### Workforce

Impacto médio.

O Workforce pode continuar com `meals_per_day`, mas a UI de configuração tende a precisar evolução futura para responder:

- quais refeições esse cargo/membro pode consumir
- ou se o cargo só usa o conjunto padrão de 4 refeições

#### Financeiro

Impacto alto.

Hoje vários pontos calculam custo assim:

- `estimated_meals_total * meal_unit_cost`

Isso aparece em:

- `MealController`
- `OrganizerFinanceController`
- dashboard de workforce costs
- modais de custo por setor/cargo

Se cada refeição tiver valor próprio, essa conta deixa de ser válida.

Novo cálculo passa a exigir:

- somatório por serviço
- `qty_breakfast * cost_breakfast`
- `qty_lunch * cost_lunch`
- `qty_afternoon_snack * cost_afternoon_snack`
- `qty_dinner * cost_dinner`

#### Offline

Impacto alto, mas positivo se for bem desenhado.

Com `meal_service_id`, o offline melhora porque a sync ganha idempotência real.

Recomendação:

- cada baixa offline deve carregar `offline_request_id`
- ao sincronizar, o backend deve tratar esse id como idempotente
- isso evita duplicação por reenvio

### Consequências práticas da mudança

#### Positivas

- elimina a brecha atual de validar o mesmo QR 3 vezes para a mesma refeição
- aproxima o sistema da operação real de cozinha/refeitório
- permite preço individual por refeição
- melhora auditoria
- melhora offline e sincronização

#### Negativas / custo de mudança

- quebra o modelo atual centrado em `meals_per_day` + `meal_unit_cost`
- exige migration de banco
- exige retrofit de relatórios financeiros
- exige estratégia de compatibilidade para histórico já lançado

### Grau de complexidade

#### Regra anti-duplicidade imediata

Complexidade baixa a média.

Exemplo de mitigação curta:

- bloquear nova leitura se houve consumo do mesmo participante nos últimos `X` segundos

Isso reduz abuso operacional, mas não resolve o desenho.

#### Redesenho correto por serviço de refeição

Complexidade alta.

Porque mexe em:

- banco
- backend
- frontend
- financeiro
- offline
- compatibilidade histórica

### Cuidados obrigatórios

#### 1. Não apagar `event_shift_id` do ecossistema

Ele continua útil para Workforce.

O certo é:

- tirar `turno` do fluxo principal de `Meals`
- não destruir `turno` do domínio operacional

#### 2. Compatibilidade com histórico

Já existem registros antigos em `participant_meals` sem tipo de refeição.

Esses registros precisam permanecer válidos.

Sugestão:

- adicionar `meal_service_id` nullable na primeira migration
- manter leitura legada para registros antigos
- migrar UI e escrita primeiro
- só depois endurecer obrigatoriedade do novo campo

#### 3. Não hardcode de forma rígida demais

Mesmo que hoje o padrão seja:

- café da manhã
- almoço
- lanche da tarde
- jantar

o melhor é modelar por catálogo/configuração, porque alguns eventos podem ter:

- ceia
- brunch
- almoço/jantar apenas
- refeição extra de madrugada

#### 4. Separar custo padrão de custo real por evento

Hoje o custo é do organizador.

Melhor desenho:

- manter `meal_unit_cost` apenas como legado/fallback
- custo real das refeições novas ficar na configuração do evento/serviço

### Migrations necessárias

Sim. Para o redesenho correto, migrations serão necessárias.

#### Lote mínimo recomendado

1. Criar `event_meal_services`
2. Adicionar `meal_service_id` em `participant_meals`
3. Adicionar índice por `meal_service_id`
4. Adicionar unicidade:
   - `UNIQUE (participant_id, event_day_id, meal_service_id)`
5. Adicionar, se adotado offline idempotente:
   - `offline_request_id`
   - `UNIQUE (offline_request_id)`

#### Migrations complementares prováveis

1. Expandir configuração financeira para múltiplos preços por refeição
2. Revisar endpoints e relatórios de `workforce-costs`
3. Eventualmente criar tabela de elegibilidade por refeição, se o produto sair do modelo simples de "todas as 4 refeições para todos"

### Sequência recomendada

#### Etapa 1. Hotfix operacional curto

- impedir múltiplas leituras em janela curtíssima para o mesmo participante
- útil para reduzir abuso imediatamente

#### Etapa 2. Infra de banco

- criar `event_meal_services`
- evoluir `participant_meals`

#### Etapa 3. Backend

- baixa por `meal_service_id`
- balance por serviço
- custo por serviço

#### Etapa 4. Frontend

- trocar `turno` por `refeição`
- modal financeiro com 4 refeições e preços individuais

#### Etapa 5. Offline

- incluir `offline_request_id`
- sync idempotente

### Recomendação final

O melhor desenho dentro do que já existe não é "usar turno para representar refeição".

O melhor desenho é:

- manter `turno` para Workforce
- criar `serviço de refeição` para Meals
- bloquear unicidade por participante/dia/refeição
- migrar custo unitário único para custo por refeição

Se seguirmos nessa direção, o sistema fica coerente com a operação real e resolve a inconsistência de múltiplas validações do mesmo QR para a mesma refeição.

---

## 21. Atualização aplicada — Redesenho por Serviço de Refeição (Seção 20 executada)

### Escopo atacado neste lote

- Criar a infraestrutura de banco para `event_meal_services` e evoluir `participant_meals`
- Conectar a lógica defensiva já existente no `MealsDomainService` ao schema real
- Bloquear duplicidade: mesmo QR não pode consumir a mesma refeição 2x no mesmo dia

### Estado encontrado

Ao iniciar esta rodada, verificou-se que:

- `MealsDomainService` já implementava `ensureEventMealServices`, `resolveMealServiceSelection`, `assertMealServiceNotConsumed` e `assertMealServiceAllowed` de forma defensiva com `columnExists`
- `MealController` já expunha `GET /meals/services` e `PUT /meals/services`
- `MealsControl.jsx` já carregava `mealServices` e enviava `meal_service_id` na baixa e offline
- O gap estava apenas no banco: `event_meal_services` não existia, e `participant_meals` ainda não tinha as colunas necessárias

### Migration aplicada: `database/012_meal_services_redesign.sql`

A migration criou:

1. Tabela `event_meal_services`:
   - FK para `events(id) ON DELETE CASCADE`
   - UNIQUE `(event_id, service_code)` — um serviço por tipo por evento
   - CHECK `service_code IN ('breakfast', 'lunch', 'afternoon_snack', 'dinner', 'supper', 'extra')`
   - Índices: `idx_ems_event_id`, `idx_ems_event_active`

2. Colunas em `participant_meals`:
   - `meal_service_id` — FK para `event_meal_services(id) ON DELETE SET NULL`
   - `unit_cost_applied` — custo real gravado no momento da baixa
   - `offline_request_id` — idempotência de sync offline
   - UNIQUE `(participant_id, event_day_id, meal_service_id)` DEFERRABLE — bloqueia duplicata por serviço/dia
   - UNIQUE `(offline_request_id)` — idempotência absoluta de sync
   - Índices: `idx_pm_meal_service_id`, `idx_pm_offline_request_id`, `idx_pm_participant_day_service`

### Como o bloqueio funciona agora

O fluxo de `POST /meals` agora executa em sequência:

1. `acquireParticipantDayQuotaLock` — lock transacional por participante/dia
2. `assertDailyQuotaAvailable` — verifica cota máxima do dia (`meals_per_day`)
3. `assertMealServiceAllowed` — verifica se o rank do serviço cabe na cota
4. `assertMealServiceNotConsumed` — **novo: consulta explicit de unicidade por serviço/dia**
5. `INSERT` com `meal_service_id` e `unit_cost_applied`
6. O `UNIQUE CONSTRAINT` do banco é a barreira final de segurança

### Verificações desta rodada

- `php -l MealsDomainService.php`: ok
- `php -l MealController.php`: ok
- Migration `012` aplicada com sucesso no banco local
- `\d event_meal_services`: tabela confirmada com FK, UNIQUE e CHECK de service_code
- `\d participant_meals`: 3 colunas novas + 2 constraints UNIQUE + 3 novos índices
- `npm run build`: ok (✓ built in 33.69s)
- `database/schema_current.sql` atualizado via `pg_dump`
- `database/migrations_applied.log` atualizado

### Próximo alvo

- Fase 6: operação offline (cache local mínimo, modo degradado, fila de sincronização)
- Após estabilizar offline, confirmar em UI o comportamento de bloqueio de duplicidade por serviço

---

## 22. Atualização aplicada — Estabilização de Backend (Correções de IDE e Linting)

### Escopo atacado neste lote

- Resolução de avisos de código inacessível ("Unreachable code")
- Padronização de inicialização de conexões de banco em blocos `try/catch`
- Ajustes finos em controllers para evitar confusão de analisadores estáticos

### Ajustes feitos em `backend/src/Controllers/GuestController.php`

- **Correção de Código Inacessível**: O analisador PHP0419 apontava que o `rollBack()` no `catch` de `importGuests` era inacessível a partir de certas falhas de inicialização.
- **Refatoração**:
  - Inicialização de `$db = null` explicitamente antes do bloco `try`.
  - Mudança da checagem no `catch` para `if ($db && $db->inTransaction()) $db->rollBack();`.
- **Efeito**: Garante que `$db` esteja sempre definido no escopo do `catch`, resolvendo o aviso do IDE e padronizando o código com o padrão mais robusto observado no `EventController.php`.

### Ajustes transversais em outros Controllers

- Revisão de fluxos de transação em `BarController.php`, `CardController.php` e `MealController.php` para garantir que erros de parâmetro ambíguo (como o cast de `:event_shift_id`) ou falhas de PDO não deixem transações órfãs.
- Alinhamento de tipos em casts de IDs (int/float) para consistência nas consultas PostgreSQL e prevenção de bugs de tipagem silenciosa.

### Verificação

- `php -l backend/src/Controllers/GuestController.php`: ok
- `php -l backend/src/Controllers/BarController.php`: ok
- `php -l backend/src/Controllers/CardController.php`: ok
- `php -l backend/src/Controllers/MealController.php`: ok

---

## 23. Atualização aplicada — Coerência de liderança e saneamento do evento 7

### Escopo atacado neste lote

- Corrigir a leitura de `Meals` para não excluir cargos diretivos quando houver vínculo real
- Parar de perder liderança por redirect silencioso para `Equipe SETOR`
- Fechar a exclusão unitária de participantes com cleanup completo de dependências
- Auditar e limpar o resíduo operacional da `Equipe BAR` no evento `7`
- Preparar o backend para materializar liderança configurada por `nome + CPF` em membro real do evento

### Ajustes aplicados no backend

- `backend/src/Controllers/MealController.php`
  - o `GET /meals/balance` deixou de filtrar apenas assignments operacionais
  - a leitura agora também considera assignments gerenciais reais quando existirem no evento
  - o fluxo de QR externo foi endurecido para não depender de `updated_at` em `workforce_roles`

- `backend/src/Controllers/ParticipantController.php`
  - a exclusão unitária de participante passou a apagar também:
    - `workforce_assignments`
    - `workforce_member_settings`
    - `participant_checkins`
    - `participant_meals`
  - antes desta correção, a exclusão unitária não reproduzia o cleanup da exclusão em massa

- `backend/src/Controllers/WorkforceController.php`
  - cargos estruturais com liderança real passaram a sincronizar `workforce_assignments` automaticamente
  - o redirect de cargo gerencial para `Equipe SETOR` ficou restrito ao fluxo legado sem `event_role` estrutural
  - o save de liderança agora também tenta materializar `leader_participant_id` por identidade
  - nesta rodada foi adicionada uma regra nova:
    - se o cargo gerencial vier configurado com `leader_name + leader_cpf`
    - e ainda não existir `leader_participant_id`
    - o backend passa a poder criar/reusar `people`, inscrever a pessoa no evento como `Staff`, gerar QR e sincronizar a escala

### Ajustes aplicados no frontend

- `frontend/src/pages/MealsControl.jsx`
  - removido o filtro que descartava fallback gerencial na leitura complementar do Workforce
  - corrigida a coluna de consumo por refeição para ler `consumed_service`
  - adicionada composição por função para expor diretivos, gerentes, coordenadores e supervisores no recorte

- `frontend/src/pages/ParticipantsTabs/AddWorkforceAssignmentModal.jsx`
  - quando o contexto já vem de `manager_event_role`, o modal volta a mostrar cargos gerenciais
  - isso evita esconder liderança válida em árvore já estruturada

### Auditoria objetiva do evento 7

Foi confirmado por consulta direta no banco local:

- o evento `7` tinha `122` participantes e `122` assignments antes do saneamento desta rodada
- a `Equipe BAR` era exatamente o bloco residual que continuava no evento apesar da exclusão operacional esperada
- a `Equipe BAR` correspondia a:
  - `40` participantes
  - `40` assignments
  - `0` `workforce_member_settings`
  - `0` `participant_meals`
  - `0` `participant_checkins`

### Saneamento executado no evento 7

Foi executado cleanup transacional apenas da `Equipe BAR` do evento `7`, com registro em `audit_log`:

- `40` linhas removidas de `workforce_assignments`
- `40` linhas removidas de `event_participants`
- auditoria gravada como `participant.delete` com `metadata.source = manual_bar_cleanup`

Estado após o saneamento inicial da BAR:

- `82` participantes no evento `7`
- `82` assignments no evento `7`
- breakdown real:
  - `Equipe LIMPEZA`: `40`
  - `Equipe SEGURANCA`: `40`
  - `Colaborador Externo`: `2`

### Diagnóstico consolidado da divergência 82 vs 84

O alvo operacional informado ficou:

- equipe base: `80`
- gerentes: `2`
- coordenadores/diretivos: `2`
- total esperado: `84`

O banco local ficou em `82` após remover a `Equipe BAR` porque:

- os cargos diretivos do evento `7` estavam persistidos em `workforce_event_roles`
- eles já tinham `leader_name` e `leader_cpf`
- mas continuavam sem `leader_participant_id` e sem `leader_user_id`
- portanto, para o `Meals`, ainda não eram membros reais do evento

Leitura importante:

- o modal de configuração estava sendo salvo parcialmente
- nome e CPF chegavam ao backend e ficavam gravados na estrutura
- o que faltava era materializar isso em `people + event_participants + workforce_assignments`

### Backfill executado na sequência desta mesma rodada

Depois do saneamento da `BAR`, foi executado backfill local das 4 lideranças já configuradas na estrutura do evento `7`:

- `Gerente de Limpeza`
- `Supervisor de Limpezaa`
- `Gerente de Segurança`
- `Spervisor de Segurança`

O backfill:

- criou/reutilizou `people` pelo `leader_cpf`
- inscreveu essas pessoas no evento como `Staff`
- atualizou `leader_participant_id` em `workforce_event_roles`
- criou `workforce_assignments` com `source_file_name = leadership_sync`

Estado do banco local depois do backfill:

- `86` participantes no evento `7`
- `86` assignments no evento `7`
- breakdown por cargo:
  - `Equipe LIMPEZA`: `40`
  - `Equipe SEGURANCA`: `40`
  - `Gerente de Limpeza`: `1`
  - `Supervisor de Limpezaa`: `1`
  - `Gerente de Segurança`: `1`
  - `Spervisor de Segurança`: `1`
  - `Colaborador Externo`: `2`

### Fechamento funcional do total 84

Foi confirmado que a diferença entre `86` e o total operacional esperado de `84` não era mais de persistência de liderança.

A diferença final eram os `2` `Colaborador Externo`, que continuavam entrando no consolidado principal de membros.

Por isso o frontend foi ajustado para:

- contar `staff + liderança` no consolidado principal de membros
- excluir `Colaborador Externo` do total principal de `Membros`
- excluir `externos` do breakdown principal de setores/funções
- expor `Externos com QR` como grupo separado

Com isso, o consolidado principal esperado para o evento `7` passa a ser:

- `84` membros de staff/leitura principal
- `2` externos com QR, mostrados separadamente

### Estado do código após este lote

- a correção estrutural para materializar liderança por `nome + CPF` já foi aplicada no backend
- isso vale para os próximos salvamentos de cargos diretivos
- o evento `7` já recebeu backfill local das 4 lideranças existentes
- o frontend passou a separar `externos com QR` do total principal de membros

### Verificação

- `php -l backend/src/Controllers/MealController.php`: ok
- `php -l backend/src/Controllers/ParticipantController.php`: ok
- `php -l backend/src/Controllers/WorkforceController.php`: ok
- `npm --prefix frontend run build`: ok

### Ajuste complementar de classificação no breakdown

Na leitura seguinte do `Meals`, surgiu um desvio residual na composição por função:

- a UI mostrava `Gerentes 3`
- a UI mostrava `Supervisores 1`
- o evento `7` na verdade tem `2` gerentes e `2` supervisões vinculadas

Causa confirmada:

- o cargo `Spervisor de Segurança` existe com grafia legada
- o backend já estava sendo endurecido para reconhecer esse padrão
- o frontend ainda não reconhecia `spervisor` no classificador local
- por isso esse membro caía no fallback `managerial => manager`

Correção aplicada:

- `backend/src/Helpers/WorkforceEventRoleHelper.php`
  - `workforceResolveRoleClass()` passou a reconhecer `spervisor` como `supervisor`
  - a ordem de classificação passou a priorizar `coordenador` e `supervisor` antes do fallback gerencial

- `frontend/src/pages/MealsControl.jsx`
  - `classifyRole()` foi alinhado com a mesma tolerância
  - supervisão com grafia legada deixa de ser promovida para `manager`

Resultado esperado após recarregar:

- `Gerentes`: `2`
- `Supervisores`: `2`
- `Operacionais`: `80`
- `Externos com QR`: `2`

### Ajuste responsivo do bloco de breakdown

Na sequência, o bloco `Membros por Setor (Breakdown)` foi reorganizado para melhorar leitura operacional:

- no desktop, setores e composição por função passam a aparecer em uma única linha horizontal
- no mobile, o mesmo conjunto quebra em grade para preservar legibilidade e toque
- a contagem continua a mesma; a mudança deste passo foi apenas de layout e responsividade

### Endurecimento do escopo da tabela de membros

Foi aplicado um endurecimento específico para garantir que a tabela da tela de `Meals` renderize apenas membros do evento selecionado:

- `backend/src/Controllers/MealController.php`
  - cada linha de `GET /meals/balance` agora retorna `event_id`

- `backend/src/Controllers/WorkforceController.php`
  - cada linha de `GET /workforce/assignments` agora retorna `event_id`

- `frontend/src/pages/MealsControl.jsx`
  - respostas online e payloads do cache local passam a ser carimbados com `event_id`
  - a UI filtra explicitamente `payload.items` e `workforceBaseItems` pelo `event_id` atual antes de montar a tabela
  - no modo complementar do Workforce, a tabela deixa de usar assignment cru e passa a consolidar por `participant_id`
  - o estado vazio da tabela também passa a respeitar esse escopo consolidado

Objetivo deste lote:

- impedir mistura entre eventos por cache antigo ou payload residual
- alinhar a semântica da tabela com o título `Membros`, mostrando pessoa do evento e não linha de assignment

### Ajuste de semântica no card operacional

No card `Saldo real do Meals ativo`, a UI estava repetindo a mesma leitura em dois badges:

- `Liderança`
- `Gerencial`

Como, neste contexto da tela, ambos apontavam para o mesmo subconjunto já contabilizado, o badge `Gerencial` foi removido do resumo rápido para evitar redundância visual. A composição detalhada continua no breakdown por função.

### Simplificação do card de QR externo no frontend

Após validação operacional, o card de QR externo seguia exibindo informação demais para o organizador no frontend.

Foi ajustado em `frontend/src/pages/MealsControl.jsx` para:

- deixar o bloco focado só em compartilhamento
- remover exposição visual de detalhes redundantes no card de sessão
- manter apenas as ações `Copiar link` e `WhatsApp`

O QR continua funcional para envio, mas deixa de poluir a tela de operação com card detalhado desnecessário.

### Refeição automática por janela de horário

Foi corrigido o travamento do `Meals` na primeira refeição ativa (`Café da manhã`) mesmo quando o horário operacional já estava em outra janela.

Diagnóstico:

- o backend já resolvia a refeição correta por `starts_at` / `ends_at`
- o frontend estava fixando `mealServiceId` no primeiro serviço ativo carregado
- com isso, a tela deixava de operar em modo automático e passava a forçar sempre a mesma refeição

Correção aplicada em `frontend/src/pages/MealsControl.jsx`:

- `mealServiceId` volta a iniciar vazio por padrão
- o carregamento de serviços não força mais o primeiro item ativo
- a resposta de `GET /meals/balance` não sobrescreve mais o seletor com um id fixo
- o seletor principal passa a mostrar `Automático: ...` quando a refeição é resolvida pelo backend
- o registro de refeição usa a refeição resolvida para o horário atual quando não houver override manual
- em modo automático, a tela atualiza o saldo periodicamente para acompanhar a troca de janela ao longo do dia

Refino adicional aplicado na sequência:

- o frontend passou a resolver a janela ativa pelo relógio local do dispositivo
- `GET /meals/balance` agora recebe `reference_time` quando a tela está em modo automático
- com isso, label, refeição ativa e preço da refeição passam a acompanhar a hora local do aparelho, e não apenas a hora do backend

Regra adicional consolidada depois da validação:

- o operador pode manter o `select` em qualquer opção visual desejada
- o card financeiro de valor unitário passa a seguir a janela ativa do dispositivo
- assim, o preço exibido acompanha automaticamente os valores configurados no modal para café, almoço, lanche e jantar, independentemente do select manual

## 24. Atualização aplicada — histórico HTTP de `GET /meals` plugado na tela

### Escopo atacado neste lote

- fechar o gap entre o backend já exposto em `GET /meals` e a tela `MealsControl`
- permitir leitura rápida das últimas baixas sem sair do módulo
- manter o histórico preso ao mesmo recorte operacional já usado no saldo

### Ajustes aplicados no backend

- `backend/src/Controllers/MealController.php`
  - `listMeals()` passou a aceitar `limit`
  - cada linha agora retorna também:
    - `event_id`
    - `event_day_id`
    - `event_shift_id`
  - isso deixa o histórico mais barato para a UI e reforça o escopo por evento

### Ajustes aplicados no frontend

- `frontend/src/pages/MealsControl.jsx`
  - a tela passou a chamar `GET /meals` com o mesmo recorte atual de:
    - evento
    - dia
    - turno
    - refeição resolvida/selecionada
  - em modo automático, o histórico acompanha a refeição ativa do relógio do dispositivo
  - o card novo mostra as últimas baixas com:
    - horário
    - pessoa
    - refeição
    - turno
    - valor aplicado
  - quando houver filtro de setor, a UI aplica esse filtro localmente sobre o histórico usando a base atual do recorte
  - o histórico também ganhou cache local best-effort por recorte para operação degradada

### Resultado funcional esperado

- o organizador passa a enxergar na própria tela do `Meals` as últimas baixas do recorte ativo
- após registrar uma refeição, salvar serviços ou atualizar o contexto, o histórico é recarregado
- a página deixa de depender apenas do saldo agregado para auditoria rápida de operação

## 25. Atualização aplicada — visibilidade operacional da fila offline do Meals

### Escopo atacado neste lote

- tirar a fila offline do `Meals` do modo “infraestrutura invisível”
- dar leitura local de pendências ao operador sem depender só do badge global da aplicação
- permitir sincronização manual das refeições pendentes diretamente pela tela

### Ajustes aplicados no frontend

- `frontend/src/pages/MealsControl.jsx`
  - a tela passou a carregar da `offlineQueue` apenas os registros `payload_type = meal`
  - foi criado um card operacional próprio de offline com:
    - status de rede do dispositivo
    - total de pendências do `Meals`
    - pendências do evento atual
    - pendências do dia atual
    - estado da sincronização
  - a UI agora lista as últimas refeições ainda não sincronizadas, mostrando:
    - horário de captura
    - pessoa resolvida por QR quando possível
    - QR resumido
    - refeição resolvida/selecionada na captura
    - setor/turno
    - status local pendente
  - foi adicionada ação manual `Sincronizar pendentes`
  - a fila também passa a ser recarregada em:
    - refresh manual da tela
    - registro offline
    - fallback por erro de rede
    - foco da janela
    - volta da conectividade

### Fechamento funcional deste ponto

- o operador do `Meals` agora enxerga claramente quando a baixa ficou só local
- a tela não depende mais apenas do sync em background para explicar o estado das pendências
- o módulo ganhou um caminho explícito de conferência e sincronização sem sair da operação

## 26. Auditoria final e checklist de go-live do Meals

### Estado da ferramenta de auditoria

- `backend/scripts/audit_meals.php` continua sendo a auditoria oficial do projeto
- nesta máquina, o `php` CLI está sem `pdo_pgsql/pgsql`
- por isso o script agora falha com mensagem operacional explícita:
  - `Database connection failed: PHP CLI sem pdo_pgsql/pgsql. Instale a extensao PostgreSQL no PHP ou rode a auditoria via psql.`
- para esta rodada, a auditoria do banco local foi executada via `psql`

### Resultado auditado no evento 7

Resumo por dia:

- `event_day 13` (`2026-03-16`): `11` refeições, `7` participantes únicos
- `event_day 14` a `19`: `0` refeições, `0` participantes

Integridade:

- `orphan_participant`: `0`
- `orphan_day`: `0`
- `missing_shift_reference`: `0`
- `shift_day_mismatch`: `0`
- `participant_day_event_mismatch`: `0`
- `meal_without_any_assignment`: `0`
- `meal_without_shift_assignment_when_shifted`: `2`

Detalhe do único alerta restante:

- refeição `7` — `Alexandre Frota` — `event_day 13` / `event_shift 13` (`Turno Único`)
- refeição `8` — `Gervasio Vaz` — `event_day 13` / `event_shift 13` (`Turno Único`)
- os dois participantes ainda têm assignment no evento, mas o assignment segue com `event_shift_id = NULL`
- portanto, o alerta não aponta refeição órfã; ele aponta inconsistência histórica entre uma baixa antiga “com turno” e uma escala ainda sem vínculo de turno

### Checklist objetivo de go-live

| Item | Estado | Evidência |
| --- | --- | --- |
| `event_days` reais do evento | OK | evento `7` com `7` dias operacionais |
| `event_shifts` do calendário | OK | `7` turnos, um `Turno Único` por dia |
| serviços de refeição configurados | OK | `4` serviços ativos (`café`, `almoço`, `lanche`, `jantar`) |
| valor por refeição configurado | OK | `15 / 20 / 13 / 20` no evento `7` |
| histórico `GET /meals` visível na UI | OK | `MealsControl` já consome e exibe histórico recente |
| fila offline visível e sincronizável | OK | `MealsControl` já mostra pendências e botão de sync |
| integridade estrutural de `participant_meals` | OK | sem órfãos nem mismatch de evento/dia |
| assignments coerentes com turno histórico | PENDENTE | `2` baixas antigas com `event_shift_id` mas sem assignment no mesmo shift |
| árvore operacional principal | OK | `84` staff + liderança e `7` externos no evento atual |
| setores dinâmicos do evento | OK | leitura atual com `limpeza`, `seguranca` e `externo` |

### Leitura final para decisão

O `Meals` está funcionalmente pronto para operação assistida, mas o go-live pleno ainda fica condicionado a decidir o tratamento das `2` refeições históricas com `shift` sem assignment no mesmo `shift`.

Opções objetivas:

- aceitar como exceção histórica conhecida e seguir
- saneá-las no banco antes do go-live
- ou endurecer a auditoria para separar “histórico legado” de “bloqueio de operação atual”

## 27. Saneamento final do banco para go-live do evento 7

### Saneamento executado

As `2` linhas históricas restantes foram saneadas diretamente no banco local, com transação e registro em `audit_log`.

Registros afetados:

- `participant_meals.id = 7` — `Alexandre Frota`
- `participant_meals.id = 8` — `Gervasio Vaz`

Correção aplicada:

- `event_shift_id` foi ajustado de `13` para `NULL`

Motivo técnico:

- essas duas refeições eram legado antigo
- estavam com `event_shift_id = 13`
- mas os assignments reais desses participantes continuavam sem `event_shift_id`
- no mesmo dia, os demais registros dos próprios participantes já estavam sem turno
- portanto, o saneamento correto foi remover o vínculo artificial de turno dessas duas linhas, e não inventar escala nova no Workforce

### Trilho de auditoria gravado

Foram criadas `2` entradas novas em `audit_log`:

- `action = participant_meal.update`
- `metadata.source = manual_go_live_sanitation`
- `metadata.reason = clear_legacy_shift_without_matching_assignment`

### Auditoria pós-saneamento

Integridade do evento `7` após o ajuste:

- `orphan_participant`: `0`
- `orphan_day`: `0`
- `missing_shift_reference`: `0`
- `shift_day_mismatch`: `0`
- `participant_day_event_mismatch`: `0`
- `meal_without_any_assignment`: `0`
- `meal_without_shift_assignment_when_shifted`: `0`

### Fechamento do checklist

O item antes pendente:

- `assignments coerentes com turno histórico`

passa a ficar:

- `OK`

Leitura final desta rodada:

- o banco do `Meals` para o evento `7` ficou saneado para go-live
- o checklist funcional do módulo fica integralmente verde no recorte atual
- a única pendência remanescente fora do produto é de ambiente local do `php` CLI para rodar `audit_meals.php` diretamente sem recorrer ao `psql`

## 28. Cronograma enxuto de hardening pós-go-live do Meals

### Leitura consolidada

- O `Meals` está encerrado funcionalmente no evento piloto.
- O que sobra agora não é mais correção de operação principal; é hardening de domínio, auditoria e banco para reduzir risco estrutural futuro.
- A janela realista para esse fechamento não é de `4 semanas`.
- O recorte atual cabe em `3 lotes principais`, com um `4º lote` opcional se o objetivo for endurecer também a arquitetura offline.

## Lote 1 — Domínio e validação

### Prazo estimado
- `0,5 a 1 dia`

### Arquivos
- `backend/src/Services/MealsDomainService.php`
- `backend/src/Controllers/MealController.php`

### Escopo
- corrigir seleção automática de serviço para janela `overnight`
- validar e normalizar `consumed_at`
- retornar `400` explícito para data/hora inválida

### Risco
- baixo

### Consequência esperada
- entradas inválidas que hoje podem cair em fallback silencioso passam a falhar corretamente

### Mitigação
- teste de serviço `22:00-02:00`
- teste de `consumed_at` válido
- teste de `consumed_at` inválido

### Critério de aceite
- serviço noturno resolve corretamente
- `consumed_at` inválido não gera `500`

## Lote 2 — Auditoria forte

### Prazo estimado
- `0,5 a 1 dia`

### Arquivos
- `backend/scripts/audit_meals.php`

### Escopo
- detectar `meal_service_id` de outro evento
- detectar `consumed_at` fora do dia operacional
- detectar `participant_meals.event_day_id IS NULL`

### Risco
- baixo

### Consequência esperada
- podem aparecer legados antes invisíveis

### Mitigação
- separar resultado em:
  - bloqueante
  - legado
- não misturar auditoria estrutural com feed operacional da UI

### Critério de aceite
- auditoria cobre as inconsistências novas sem escondê-las por `INNER JOIN`

## Lote 3 — Hardening de banco

### Prazo estimado
- `1 a 2 dias`

### Arquivos
- migration nova de `participant_meals`
- `database/schema_current.sql`

### Escopo
- `CHECK unit_cost_applied >= 0`
- `event_day_id NOT NULL`
- coerência `event_shift_id x event_day_id`
- coerência `meal_service_id x evento`

### Risco
- médio

### Consequência esperada
- qualquer drift legado ou gravação manual inconsistente passa a falhar na base

### Mitigação
- rollout em etapas:
  - auditoria e saneamento primeiro
  - constraint `NOT VALID`
  - validação final depois
- só aplicar `NOT NULL` quando a base estiver comprovadamente limpa

### Critério de aceite
- o banco rejeita mismatch estrutural sem depender só da aplicação

## Lote 4 — Offline avançado e trilha forense

### Prazo estimado
- `2 a 4 dias`

### Status
- opcional no recorte atual
- não bloqueia o encerramento prático do `Meals`

### Escopo
- inbox offline
- assinatura/HMAC de request
- trilha com:
  - `device_id`
  - `source`
  - `app_version`
  - `ingested_at`
  - `operator_id`

### Risco
- médio

### Consequência esperada
- muda contrato e reconciliação do sync offline

### Mitigação
- compatibilidade temporária com payload antigo
- ativação progressiva por versão

### Critério de aceite
- sync offline passa a ser rastreável, reconciliável e auditável por dispositivo

## Ordem recomendada

- executar agora:
  - `Lote 1`
  - `Lote 2`
  - `Lote 3`
- deixar `Lote 4` para decisão específica de plataforma/offline

## Estimativa consolidada

- fechamento dos gaps reais da auditoria atual: `2 a 4 dias`
- fechamento com offline forense avançado: `4 a 8 dias`
