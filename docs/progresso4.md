# Progresso da Rodada Atual - EnjoyFun 2.0

## Meals Control — leitura honesta inicial

- **Responsável:** Codex
- **Status:** Histórico consolidado da frente
- **Escopo:** frontend / backend / contrato operacional
- **Arquivos principais revisados:** `frontend/src/pages/MealsControl.jsx`, `backend/src/Controllers/MealController.php`, `backend/src/Controllers/WorkforceController.php`, `docs/progresso3.md`, `database/schema_real.sql`
- **Próxima ação sugerida:** atacar primeiro a aderência entre leitura real de `Meals` e base complementar de `workforce/assignments`, sem redesign e sem misturar com Analytics.
- **Bloqueios / dependências:** o workspace ainda não sustenta operação completa por dia/turno em todos os cenários; `GET /meals/balance` continua dependente de `event_day_id`, `meal_unit_cost` não aparece como coluna consolidada no dump principal de `schema_real.sql`, e `workforce_role_settings` ainda não aparece consolidado como base real do workspace.

### Diagnóstico inicial

- O backend de `Meals` está mais seguro contratualmente e já bloqueia combinações incoerentes entre evento, dia e turno.
- A tela deixou de depender só do saldo diário e agora usa `workforce/assignments` como base complementar quando o evento não sustenta leitura diária.
- Mesmo assim, o módulo ainda não pode ser tratado como totalmente aderente à operação real.
- O ponto mais sensível restante não é visual; é a diferença entre:
  - saldo real do `Meals` por dia
  - leitura complementar do `Workforce`
  - camada de custo ainda condicional ao schema real
- Hoje a tela parece mais correta do que antes, mas parte dela ainda depende de fallback e de bases que não estão consolidadas no workspace com a mesma força do contrato esperado.

## 1. estado real atual do Meals

- O módulo hoje está em um estado intermediário mais honesto do que no início da frente, mas ainda não está plenamente aderente à operação real do workspace.
- O backend de `Meals` já endureceu o contrato principal:
  - valida coerência entre `event_id`, `event_day_id` e `event_shift_id`
  - bloqueia cenários cross-event
  - expõe `operational_summary`, `projection_summary`, `diagnostics` e `config_source`
- O frontend deixou de depender apenas do saldo diário e passou a consultar `GET /workforce/assignments` como base complementar do evento.
- Na prática, hoje existem 2 modos operacionais distintos na tela:
  - `Saldo real Meals`: quando há `event_id` + `event_day_id` e o frontend chama `GET /meals/balance`
  - `Base Workforce`: quando o evento não sustenta leitura diária e a tela cai para `workforce/assignments`
- Isso melhorou a honestidade da tela, mas não elimina diferenças estruturais entre:
  - leitura diária real do `Meals`
  - base event-level do `Workforce`
  - camada de custo ainda dependente do schema real

## 2. o que já reflete corretamente o workspace

- O backend já reflete corretamente a integridade mínima do domínio:
  - `GET /meals/balance` exige `event_day_id`
  - `event_day_id` precisa pertencer ao `event_id`
  - `event_shift_id` precisa pertencer ao dia selecionado
  - `POST /meals` resolve o evento do participante antes de registrar consumo
- A separação semântica entre operação e projeção está correta no payload de `GET /meals/balance`:
  - `operational_summary` para saldo/consumo
  - `projection_summary` para custo condicional
  - `diagnostics` para readiness e base insuficiente
- A tela já reflete melhor a base real do evento quando não existem dias operacionais:
  - setores reais vindos de `workforce/assignments`
  - membros reais alocados no evento
  - vínculo de turno quando ele existe em `workforce_assignments.event_shift_id`
  - refeições configuradas resolvidas por membro/cargo/default no retorno de `workforce/assignments`
- O fallback deixou de inventar vazio absoluto quando o problema real é ausência de `event_days`.
- A origem da cota (`member_override`, `role_settings`, `default`) já aparece no modo de saldo real do Meals, o que é operacionalmente útil.
- A tela também está mais honesta ao avisar:
  - evento sem dias
  - dia sem turnos
  - ausência de consumo real
  - uso de fallback default
  - custo indisponível ou não configurado

## 3. o que ainda não reflete corretamente

- A leitura de `turno` ainda não está totalmente correta no modo de saldo real:
  - `consumed_shift` só representa consumo real por turno quando `event_shift_id` está selecionado
  - quando o filtro está em `Todos os turnos`, o backend calcula `consumed_shift` com a mesma base do dia
  - mesmo assim a UI continua chamando isso de `Consumidas turno`
- Isso faz a tela aparentar leitura por turno em cenários em que ela está, na prática, mostrando leitura agregada do dia.
- A contagem de `membros` ainda não é garantidamente equivalente a membros únicos do Workforce:
  - `GET /meals/balance` faz `JOIN` direto com `workforce_assignments`
  - `GET /workforce/assignments` também retorna linhas por assignment
  - se um participante tiver mais de um assignment no evento, a tela pode inflar membros, cotas e totais
- A base complementar do `Workforce` não é equivalente ao saldo real do Meals:
  - ela representa alocação operacional por evento
  - não representa consumo real diário
  - não representa saldo restante real por dia
  - não garante recorte estrito por dia quando o evento possui múltiplos dias
- O frontend ainda trata `Base Workforce` como se fosse uma aproximação suficientemente próxima do recorte atual, mas isso só é verdadeiro parcialmente.
- A camada de custo ainda não reflete com segurança o workspace real em todos os cenários:
  - `meal_unit_cost` não aparece consolidado na definição principal de `organizer_financial_settings` em `schema_real.sql`
  - `workforce_role_settings` também não aparece consolidado como base forte no dump principal
  - isso indica que a leitura financeira continua dependente de migration aplicada ou de compatibilidade dinâmica

## 4. o que está parcial ou frágil

- O fallback está correto como estratégia de não esconder dados, mas é frágil como equivalência operacional:
  - ele evita falso vazio
  - porém não substitui saldo diário real
- A leitura de setores está melhor, mas continua dependente da qualidade de `workforce_assignments.sector`.
- A leitura de turnos está parcial por dois motivos:
  - o saldo real do Meals não é realmente calculado por assignment/turno do Workforce
  - o turno mostrado na tabela do modo real é um apoio complementar escolhido por `pickRelevantAssignment`, não parte nativa do payload de saldo
- A leitura da cota está parcial porque a regra base é robusta apenas enquanto:
  - `workforce_member_settings` existir
  - `workforce_role_settings` existir ou puder ser resolvido
  - não houver múltiplos assignments conflitantes por participante
- A camada financeira está especialmente frágil:
  - a UI a trata como utilizável mesmo quando o backend de saldo não foi consultado
  - em modo de fallback, `projectionEnabled` nasce como `true` no frontend por default, sem confirmação real de readiness do payload de `Meals`
- O módulo ainda tem pontos que estão só aparentando funcionar:
  - card e linha de `Consumidas turno` quando não há turno selecionado
  - contagem de membros quando a unidade real no banco é assignment, não pessoa única
  - leitura de custo como camada aparentemente estável em um workspace cuja base real ainda é condicional

## 5. principais causas dos problemas restantes

- A principal causa é estrutural: o domínio `Meals` continua dependente de base diária real, e o workspace ainda não garante essa base em todos os eventos.
- O backend de `GET /meals/balance` continua corretamente amarrado a `event_day_id`; por isso ele não resolve eventos sem dia, apenas expõe o limite.
- A base complementar usada pelo frontend é de natureza diferente:
  - `workforce/assignments` é base de alocação
  - `meals/balance` é base de saldo e consumo
- A UI ainda mistura em alguns pontos:
  - leitura diária real
  - leitura complementar de alocação
  - inferência visual de turno
- O modelo de dados também contribui para fragilidade:
  - `workforce_assignments` é linha de assignment, não necessariamente linha única por participante
  - `participant_meals` controla consumo por dia/turno, mas a tela tenta enriquecer isso com turno vindo de outra base
- Há ainda uma causa de fundo de ambiente:
  - o dump principal de `schema_real.sql` não consolida de forma limpa tudo o que a frente já trata como disponível
  - isso enfraquece a confiança em `meal_unit_cost` e em baseline por cargo como base real universal do workspace

## 6. melhorias recomendadas sem abrir escopo

- Corrigir primeiro a semântica de turno na tela, sem mudar backend amplo:
  - quando `event_shift_id` não estiver selecionado, não chamar a métrica de `Consumidas turno`
  - deixar explícito que o valor é agregado do dia
- Tratar explicitamente contagem por pessoa versus contagem por assignment:
  - cards de membros devem refletir participantes únicos
  - tabela pode continuar por pessoa, escolhendo uma linha consolidada por participante
- Tornar mais explícito, na UI e no texto operacional, que `Base Workforce` é leitura complementar do evento e não saldo diário.
- Não expandir a camada financeira agora:
  - apenas impedir que ela aparente readiness quando o payload real de `Meals` não confirmou isso
- Se o backend permanecer como está hoje, o frontend precisa assumir com mais rigor:
  - quando está em leitura real de Meals
  - quando está em leitura complementar do Workforce
  - quando está apenas em leitura parcial com apoio de inferência

## 7. plano de ataque para hoje

1. Corrigir a honestidade da leitura por turno no frontend.
2. Remover qualquer rótulo que sugira consumo real por turno quando o filtro estiver em `Todos os turnos`.
3. Revisar cards e highlights para contar participantes únicos, não linhas de assignment.
4. Tornar o estado `Base Workforce` ainda mais explícito como leitura complementar event-level.
5. Endurecer a camada de custo no frontend para não parecer plenamente disponível fora do payload real do Meals.
6. Validar manualmente 3 cenários sem abrir nova frente:
   - evento sem `event_days`
   - evento com `event_day` e sem `event_shift`
   - evento com `event_day` + `event_shift` selecionado

## 8. recomendação do primeiro ajuste concreto

- O primeiro ajuste concreto de hoje deve ser corrigir a semântica de `Consumidas turno` em `MealsControl.jsx`.
- Motivo:
  - é um erro operacional real de leitura
  - passa a impressão de precisão por turno quando o filtro está agregado no dia
  - é correção pequena, local e de alto impacto na honestidade da tela
- Direção recomendada:
  - se `eventShiftId` estiver preenchido, manter leitura de turno
  - se `eventShiftId` estiver vazio, trocar card, helper e linha para linguagem de agregado do dia
  - não abrir backend novo nesta etapa

## Meals Control — ajuste executado hoje: semântica honesta do recorte de consumo

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** remover a leitura falsa de consumo por turno quando o recorte atual está agregado no dia

### Escopo executado
- Ajustado o card operacional final de `MealsControl.jsx` para só usar linguagem de turno quando `eventShiftId` estiver selecionado.
- Quando não há `eventShiftId`, a leitura passa a ser exibida como `Consumo no recorte`, deixando explícito que o backend está retornando agregado do dia.
- Ajustada a linha da tabela para abandonar `Consumidas turno` sem filtro de turno e expor o valor como agregado do dia no recorte atual.

### Escopo preservado
- Nenhuma mudança foi feita no backend.
- Nenhuma regra de cálculo foi alterada.
- Nenhum redesign foi aberto.
- Nenhuma frente nova foi criada.

### Resultado esperado
- A tela deixa de afirmar precisão por turno em um cenário em que o backend não entrega essa granularidade.
- O operador passa a receber uma leitura semanticamente correta sem mudança de contrato.

## Meals Control — PR 6: Consolidação do saldo real por participante único

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** corrigir a distorção entre participante único e linha de assignment no fluxo de saldo real do Meals

### Problema atacado
- `GET /meals/balance` ainda nascia de `workforce_assignments`, o que podia duplicar participantes no payload.
- Isso podia inflar:
  - `members`
  - `meals_per_day_total`
  - `consumed_day_total`
  - `consumed_shift_total`
  - `remaining_day_total`
- No frontend, `payload.items` era tratado como linha operacional final mesmo quando ainda podia vir em granularidade de assignment.

### Escopo executado
- `backend/src/Controllers/MealController.php`
  - `GET /meals/balance` deixou de usar `workforce_assignments` bruto como linha final.
  - O backend agora cria um recorte de assignments compatíveis e consolida o saldo por `participant_id`.
  - `participant_meals` foi preservado como fonte real de consumo por participante/dia.
  - O payload passou a expor contexto complementar honesto por participante:
    - `assignments_in_scope`
    - `has_multiple_assignments`
    - `has_multiple_roles`
    - `has_multiple_sectors`
    - `has_multiple_shifts`
    - `role_id` / `role_name` apenas quando unívocos
    - `sector` apenas quando unívoco
    - `shift_id` / `shift_name` apenas quando unívocos
- `frontend/src/pages/MealsControl.jsx`
  - O modo `Saldo real Meals` passou a consumir diretamente o payload consolidado por participante.
  - A tabela deixou de depender de heurística de assignment como unidade principal.
  - Multiplicidade de assignment passou a aparecer apenas como apoio discreto de contexto.
  - A UI deixou de sugerir cargo, setor ou turno definitivos quando o backend sinaliza multiplicidade relevante.

### Escopo preservado
- Nenhum redesign amplo foi aberto.
- Nenhuma nova arquitetura foi iniciada.
- Nenhuma mistura com Analytics foi feita.
- Nenhuma expansão da camada financeira foi aberta.
- `workforce/assignments` continua sendo apenas base complementar, não equivalente automático de saldo real.

### Resultado esperado
- `members` e totais operacionais deixam de inflar por multi-assignment no saldo real.
- A tabela do modo real passa a ter uma linha por participante.
- Cargo, setor e turno deixam de ser escolhidos arbitrariamente quando o contexto de assignments não é unívoco.

### Limites preservados
- Esta PR não redefine a semântica operacional de `workforce/assignments` no modo fallback.
- Esta PR não resolve toda a ambiguidade estrutural de cota quando um participante possui múltiplos cargos com baselines diferentes.
- Esta PR também não transforma turno complementar do Workforce em origem nativa do consumo do Meals.

## Meals Control — PR 7: Endurecimento da resolução de cota no POST /meals para multi-assignment

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** endurecer a resolução da cota no `POST /meals` para impedir escolha implícita e arbitrária de baseline quando o participante possui multi-assignment ambíguo

### Problema atacado
- `POST /meals` ainda resolvia `meals_per_day` por um `LEFT JOIN workforce_assignments ... LIMIT 1`, o que permitia escolha implícita de assignment/cargo quando o participante possuía múltiplos vínculos.
- Isso podia gerar incoerência operacional entre:
  - saldo consolidado por participante no `GET /meals/balance`
  - limite diário efetivamente aplicado no momento do consumo

### Escopo executado
- `backend/src/Controllers/MealController.php`
  - `mealResolveOperationalConfig()` passou a resolver a cota com recorte preferencial por `event_shift_id` quando disponível, e fallback para escopo do evento quando o turno não resolve o contexto.
  - `workforce_member_settings` continua vencendo como override explícito por participante.
  - Quando há múltiplos assignments com a mesma cota efetiva, o consumo segue permitido.
  - Quando há múltiplos assignments com baselines diferentes e sem override por membro, a resolução passa a retornar ambiguidade explícita.
  - `registerMeal()` agora bloqueia esse cenário com erro operacional claro, em vez de consumir com escolha arbitrária.

### Comportamento preservado
- Cenário com assignment único continua funcionando como antes.
- Cenário com multi-assignment e mesma cota continua funcionando sem mudança de fluxo.
- Nenhuma regra financeira foi alterada.
- Nenhuma mudança visual ampla foi aberta.

### Resultado esperado
- O `POST /meals` deixa de aplicar limite diário com base em uma linha arbitrária de assignment.
- A baixa de refeição só prossegue automaticamente quando a cota está operacionalmente resolvida de forma segura.
- Em cenário ambíguo real, o contrato passa a explicitar o problema em vez de mascará-lo.

### Limites preservados
- Esta PR não redefine a leitura do saldo no `GET /meals/balance`.
- Esta PR não resolve toda a ambiguidade estrutural de cotas conflitantes no workspace; ela apenas impede decisão automática insegura no consumo.
- Esta PR também não cria regra nova de desempate entre cargos.

## Meals Control — PR 8: Validação operacional dirigida e fechamento do baseline honesto

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** validar o fluxo final de Meals após PR 6 e PR 7, sem abrir nova frente e sem maquiar ausência de base viva no workspace

### Validação executada de fato
- **Leitura de código dirigida**
  - `backend/src/Controllers/MealController.php`
  - `backend/src/Controllers/WorkforceController.php`
  - `frontend/src/pages/MealsControl.jsx`
  - `database/schema_real.sql`
- **Checagem executável local**
  - `php -l backend/src/Controllers/MealController.php` passou sem erro sintático.
  - `npx eslint src/pages/MealsControl.jsx` passou sem erro no frontend.
- **Tentativa de validação viva no workspace**
  - `php backend/check_db.php` não conseguiu abrir base real porque o PHP local está sem driver PostgreSQL (`could not find driver`).
  - Portanto esta rodada **não** teve validação real por query, seed, request HTTP contra base viva ou payload operacional retornado por ambiente executando.

### Ajuste local necessário encontrado na validação
- Durante a revisão foi identificado um desalinhamento real:
  - `POST /meals` já resolvia cota com recorte preferencial por `event_shift_id`.
  - `GET /meals/balance` ainda usava `event_shift_id` apenas no consumo por turno, sem aplicar o mesmo recorte preferencial na base de assignments.
- Ajuste executado em `backend/src/Controllers/MealController.php`:
  - `GET /meals/balance` agora aplica escopo preferencial por turno no saldo por participante quando `event_shift_id` é enviado.
  - Quando o participante não possui assignment no turno selecionado, o saldo continua com fallback para o escopo do evento, preservando a leitura honesta já usada no `POST /meals`.
  - O recorte de `consumed_shift` passou a usar `:event_shift_id` de forma explícita no SQL unificado.

### Leitura operacional resultante por cenário
- **Cenário 1 — evento sem `event_days`**
  - Validado por leitura de código.
  - `GET /meals/balance` continua exigindo `event_day_id`; sem dia operacional o saldo real não é calculado.
  - `MealsControl.jsx` assume explicitamente modo complementar do Workforce quando `eventDays.length === 0`, sem fingir saldo real do Meals.
  - Isto já pode ser tratado como comportamento honesto do baseline.
- **Cenário 2 — evento com `event_day` e sem `event_shift`**
  - Validado por leitura de código e coerência de fluxo frontend/backend.
  - `GET /meals/balance` funciona com `event_day_id` e `event_shift_id` nulo.
  - `consumed_shift` vira agregado do dia neste recorte.
  - `POST /meals` aceita baixa com `event_shift_id: null`.
  - Isto já pode ser tratado como baseline correto.
- **Cenário 3 — evento com `event_day` + `event_shift` selecionado**
  - Validado por leitura de código após ajuste local executado nesta rodada.
  - `GET /meals/balance` agora alinha o recorte preferencial de assignment com o `POST /meals` quando há `event_shift_id`.
  - `consumed_shift` passa a representar apenas o turno selecionado.
  - Continua pendente validação viva com base real.
- **Cenário 4 — participante com assignment único**
  - Validado por leitura de código.
  - `GET /meals/balance` consolida corretamente uma linha por participante.
  - `POST /meals` resolve a cota sem ambiguidade.
  - Isto já pode ser tratado como baseline correto.
- **Cenário 5 — participante com múltiplos assignments e mesma cota efetiva**
  - Validado por leitura de código.
  - `POST /meals` segue permitindo a baixa quando a cota efetiva fica resolvida de forma única no escopo aplicável.
  - `GET /meals/balance` preserva uma linha por participante e mantém multiplicidade apenas como contexto.
  - Este cenário está correto, mas condicionado à base real conseguir provar a mesma cota efetiva via `workforce_member_settings`, `workforce_role_settings` ou fallback homogêneo.
- **Cenário 6 — participante com múltiplos assignments e cotas conflitantes**
  - Validado por leitura de código.
  - `POST /meals` já bloqueia ambiguidade real com `409` quando o conflito é detectável no escopo aplicável e não existe override por membro.
  - Na PR 8 este ainda era um limite do `GET /meals/balance`.
  - Esse ponto foi endereçado na PR 9 com diagnóstico explícito de ambiguidade e degradação controlada da cota, sem derrubar o endpoint.
- **Cenário 7 — comportamento do `GET /meals/balance`**
  - Validado por leitura de código, sintaxe e coerência de fluxo com o frontend.
  - O endpoint:
    - exige `event_id` + `event_day_id`
    - valida coerência entre evento, dia e turno
    - retorna saldo consolidado por participante
    - mantém `participant_meals` como fonte real de consumo por dia
    - devolve diagnósticos operacionais e camada financeira complementar apenas quando o schema sustenta isso
  - Falta validação viva de payload real.
- **Cenário 8 — comportamento do `POST /meals`**
  - Validado por leitura de código.
  - O endpoint:
    - resolve o participante antes de usar o contexto do evento
    - valida coerência de `event_day_id` e `event_shift_id`
    - resolve a cota com preferência por turno quando há turno
    - bloqueia ambiguidade real detectável
    - aplica limite diário por `participant_meals`
    - grava consumo em `participant_meals`
  - Falta validação viva de request/response contra base real.

### Baseline honesto após esta rodada
- Já pode ser tratado como baseline estável do Meals:
  - operação diária real exige `event_day_id`
  - leitura complementar do Workforce continua explicitamente separada do saldo real do Meals
  - `GET /meals/balance` opera por participante único, sem inflar saldo por linha de assignment
  - `POST /meals` não escolhe mais baseline arbitrário em multi-assignment detectavelmente ambíguo
  - o recorte com `event_shift_id` ficou coerente entre leitura de saldo e resolução de cota
- Continua correto, porém condicionado à base real existente:
  - resolução por `role_settings` depende da tabela `workforce_role_settings`, que não aparece consolidada no `schema_real.sql`
  - camada financeira complementar depende da coluna `organizer_financial_settings.meal_unit_cost`, que também não aparece consolidada no `schema_real.sql`
  - validação prática dos cenários depende de ambiente com PostgreSQL acessível; isso não existe no workspace atual via PHP local

### Limites conhecidos remanescentes
- O workspace desta rodada não permitiu validação viva porque o PHP local não possui driver PostgreSQL.
- O limite de ambiguidade no `GET /meals/balance` foi endereçado na PR 9 com diagnóstico explícito e degradação controlada da cota.
- Eventos sem `event_days` continuam sem saldo real de Meals por desenho atual; nesses casos a tela permanece corretamente em leitura complementar do Workforce.
- A base complementar do Workforce continua sendo apenas apoio operacional e não equivalência semântica do saldo real do Meals.

## Meals Control — PR 9: Honestidade explícita no GET /meals/balance para conflito real de baseline

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** alinhar a honestidade do `GET /meals/balance` com a honestidade já aplicada no `POST /meals`, sem transformar o endpoint em erro fatal e sem abrir nova frente

### Diagnóstico atacado
- O `GET /meals/balance` ainda resolvia `meals_per_day` com esta lógica:
  - `member_override`
  - `role_settings` quando havia baseline único
  - `default` em todos os demais casos
- Isso deixava um ponto desonesto:
  - participante com multi-assignment e conflito real de cota podia cair em `default`
  - o consumo real seguia correto
  - mas a cota e o saldo derivado aparentavam baseline resolvido quando ele não existia

### Ajuste executado
- `backend/src/Controllers/MealController.php`
  - `GET /meals/balance` agora marca conflito real como `config_source = ambiguous`.
  - O payload agora expõe também:
    - `baseline_status`
    - `has_ambiguous_baseline`
  - Quando o baseline está ambíguo:
    - `meals_per_day` passa a sair `null`
    - `remaining_day` passa a sair `null`
    - consumo real (`consumed_day`, `consumed_shift`) permanece utilizável
  - Os totais derivados de cota no resumo passam a somar apenas membros com baseline realmente resolvido.
  - Os diagnostics agora expõem:
    - `ambiguous_meal_baseline_in_scope`
    - `members_with_ambiguous_baseline`
- `frontend/src/pages/MealsControl.jsx`
  - A UI passou a tratar `ambiguous` como origem própria, sem rotular esse caso como `Fallback`.
  - Linha ambígua agora mostra:
    - badge de baseline ambíguo
    - `N/D` para cota
    - saldo derivado indisponível
    - consumo real preservado
  - O resumo discreto do recorte agora mostra contagem de membros ambíguos.

### Resultado operacional
- Baseline único resolvido:
  - segue como leitura normal do saldo
- `member_override`:
  - continua vencendo explicitamente
- multi-assignment com mesma cota efetiva:
  - continua resolvido e utilizável
- multi-assignment com conflito real:
  - não derruba o endpoint
  - não aparece mais como `default` legítimo
  - preserva consumo real
  - degrada apenas a leitura de cota/saldo derivado daquele subconjunto

### Validação executada
- `php -l backend/src/Controllers/MealController.php` passou.
- `npx eslint src/pages/MealsControl.jsx` passou.
- Não houve validação viva com banco real nesta rodada porque o workspace continua sem driver PostgreSQL no PHP local.

### Limite preservado
- Esta PR não transforma conflito de baseline em erro fatal do `GET /meals/balance`; ela apenas impede que o payload finja baseline resolvido onde ele não existe.

## Meals Control — fechamento da frente como baseline estável com limites conhecidos

- **Responsável:** Codex
- **Status:** Encerrado como baseline estável
- **Objetivo:** consolidar o fechamento da frente de Meals sem abrir nova arquitetura, sem misturar Analytics, hardening geral ou V4, e sem transformar limites conhecidos em nova frente artificial

### Estado final consolidado
- Backend
  - `GET /meals/balance` ficou consolidado como leitura diária real por participante único.
  - O endpoint exige `event_id` + `event_day_id`, valida coerência entre evento, dia e turno e preserva `participant_meals` como fonte real de consumo.
  - O recorte por turno ficou coerente entre saldo e baixa de refeição.
  - A resolução de baseline ficou operacionalmente honesta:
    - `member_override` vence quando existe
    - baseline único por cargo continua resolvido
    - conflito real em multi-assignment não é mais mascarado por `default`
    - consumo real permanece visível mesmo quando a cota precisa ser degradada por ambiguidade
  - `POST /meals` ficou endurecido para bloquear ambiguidade real detectável sem escolha arbitrária.
- Frontend
  - `MealsControl.jsx` distingue de forma explícita:
    - `Saldo real Meals`
    - `Base complementar do Workforce`
  - A tela deixou de fingir leitura por turno quando o recorte está agregado no dia.
  - A UI passou a refletir baseline ambíguo de forma discreta e honesta:
    - badge próprio
    - `N/D` para cota
    - saldo derivado indisponível
    - consumo real preservado
  - O fallback de Workforce permanece visível como apoio operacional, não como equivalência de saldo real.
- Contrato
  - O payload de `GET /meals/balance` hoje já sustenta baseline estável para a frente:
    - `operational_summary`
    - `projection_summary`
    - `diagnostics`
    - `config_source`
    - `baseline_status`
    - `has_ambiguous_baseline`
  - O contrato deixa explícito quando a leitura está:
    - resolvida
    - parcial
    - condicionada
    - ambígua no recorte

### Limites conhecidos preservados
- O workspace local desta frente não permitiu validação viva contra PostgreSQL porque o PHP local segue sem driver PostgreSQL.
- Eventos sem `event_days` continuam sem saldo real diário de Meals; nesses casos a tela permanece corretamente em leitura complementar do Workforce.
- A camada financeira complementar continua dependente da base real do ambiente:
  - `organizer_financial_settings.meal_unit_cost` não aparece consolidado com a mesma força no `schema_real.sql`
  - `workforce_role_settings` também não aparece consolidado no dump principal do workspace
- A base complementar do Workforce continua sendo apoio operacional do evento e não equivalência semântica do saldo real de Meals.

### O que não deve ser reaberto agora
- Não reabrir modelagem ampla de Meals.
- Não reabrir redesign da tela.
- Não reabrir fallback Workforce como se ele precisasse virar saldo real.
- Não reabrir camada financeira dentro da frente Meals.
- Não puxar Analytics, hardening geral ou V4 para dentro deste fechamento.
- Não abrir nova regra para conflito ambíguo no `GET /meals/balance`; o baseline atual já expõe o limite de forma suficientemente honesta para esta fase.

### Próxima frente correta após Meals
- Meals já pode sair de foco como frente própria e ser tratado como baseline estável com limites conhecidos.
- A próxima frente correta não é reabrir Meals, e sim seguir a trilha já separada fora desta frente:
  - hardening geral, quando for a rodada própria disso
  - ou V4, quando a execução migrar para essa frente
- Se Meals voltar a ser tocado no futuro, o gatilho deve ser bug operacional objetivo ou dependência real de base/ambiente, não refinamento artificial da frente já encerrada.

## Hardening do sistema atual — diagnóstico inicial disciplinado e fila real

- **Responsável:** Codex
- **Status:** Executado
- **Escopo:** diagnóstico e priorização de hardening do sistema atual, sem misturar com Meals, Analytics ou V4

### Leitura executada
- `docs/progresso4.md`
- `docs/enjoyfun_hardening_sistema_atual.md`
- `backend/src/Controllers/SyncController.php`
- `backend/src/Controllers/AuthController.php`
- `backend/src/Controllers/WorkforceController.php`
- `backend/src/Controllers/TicketController.php`
- `backend/src/Controllers/OrganizerSettingsController.php`
- `backend/src/Services/DashboardDomainService.php`
- `backend/src/Services/ProductService.php`
- `frontend/src/context/AuthContext.jsx`
- `frontend/src/lib/api.js`
- `frontend/src/hooks/useNetwork.js`
- `frontend/src/modules/pos/hooks/usePosCatalog.js`
- `frontend/src/pages/POS.jsx`

### Resumo executivo
- O sistema atual já entrega operação real em múltiplos domínios, mas o núcleo de hardening ainda concentra risco em quatro frentes:
  - contexto operacional perigoso por default
  - sessão web exposta no frontend
  - mutação silenciosa em rotas de leitura
  - compatibilidade de schema/tenant tratada dentro de request operacional
- O maior risco operacional imediato hoje não está em Meals nem em Analytics:
  - está no PDV/sync offline com contexto de evento perigoso
  - na sessão web baseada em `localStorage`
  - e em rotas GET que ainda mudam dados como efeito colateral

### Achados prioritários
- **Crítica — contexto default de evento no POS/sync**
  - `frontend/src/modules/pos/hooks/usePosCatalog.js` inicia `eventId` como `"1"`.
  - `frontend/src/pages/POS.jsx` monta checkout e fila offline com esse `event_id`.
  - `backend/src/Controllers/SyncController.php` ainda usa `$payload['event_id'] ?? 1` ao registrar a fila.
  - Isso mantém risco real de operação silenciosa no evento errado ou normalização perigosa de contexto.
  - Classificação: bug real + fragilidade operacional.
- **Crítica — sessão web baseada em `localStorage`**
  - `frontend/src/context/AuthContext.jsx` persiste `access_token` e `refresh_token` no browser.
  - `frontend/src/lib/api.js` lê e renova tokens diretamente do `localStorage`.
  - Isso mantém exposição desnecessária a XSS e sessão web frágil.
  - Classificação: dívida técnica perigosa com impacto direto de segurança.
- **Alta — rotas GET com mutação silenciosa de estado**
  - `backend/src/Controllers/WorkforceController.php:listAssignments()` faz `backfillMissingQrTokensForEvent()` antes de responder.
  - `backend/src/Controllers/TicketController.php:listTicketTypes()` pode chamar `ensureLegacyCommercialTicketType()` e inserir/atualizar dados em leitura.
  - Isso quebra semântica de contrato, dificulta auditoria e mascara dependência de base.
  - Classificação: fragilidade operacional + consistência de contrato.
- **Alta — DDL e auto-compatibilidade de schema em request operacional**
  - `backend/src/Controllers/WorkforceController.php:ensureWorkforceRoleSettingsTable()` executa `CREATE TABLE` e `ALTER TABLE` dentro do fluxo.
  - `backend/src/Controllers/OrganizerSettingsController.php:ensureOrganizerSettingsTable()` faz `CREATE TABLE IF NOT EXISTS` e `ALTER TABLE` em request.
  - Isso deixa readiness de ambiente implícita, deriva de schema entre ambientes e efeito colateral por acesso.
  - Classificação: dívida técnica perigosa.
- **Média — escopo multi-tenant ainda depende de compatibilidade com `organizer_id IS NULL`**
  - Há leituras críticas aceitando legado global/null em `DashboardDomainService.php`, `TicketController.php`, `EventController.php` e `ProductService.php`.
  - O padrão é compreensível como compatibilidade, mas mantém risco de drift de escopo e comportamento ambíguo por ambiente.
  - Classificação: limitação estrutural aceitável por enquanto, mas perigosa se não for delimitada.

### O que é hardening e o que não é
- **É hardening do sistema atual**
  - remover defaults perigosos de contexto de evento
  - endurecer sessão web e lifecycle de refresh token
  - eliminar mutação silenciosa em rotas GET
  - trocar auto-DDL em request por readiness explícita de ambiente
  - reduzir drift de escopo por `organizer_id IS NULL`
  - melhorar diagnóstico onde o frontend hoje falha silenciosamente
- **Pertence à V4**
  - reescrever a arquitetura inteira para services/repositories generalizados
  - policy layer ampla e abstrata para todos os domínios
  - remodelagem estrutural profunda de contexto/evento/tenant
  - substituição arquitetural total dos controllers grandes
- **Não deve ser mexido agora**
  - Meals, salvo bug operacional objetivo
  - Analytics
  - redesign de telas
  - expansão funcional
  - backlog infinito de “refatoração bonita” sem risco real associado

### Fila recomendada de execução
1. **PR 1 — Remover defaults perigosos de contexto no POS/sync offline**
   - Maior risco de integridade operacional hoje.
   - Endurece `event_id` explícito no frontend e backend, eliminando `1` como fallback implícito.
2. **PR 2 — Endurecer sessão web atual**
   - Tratar armazenamento e renovação de tokens como trilha de segurança do sistema atual.
   - Mesmo sem V4, isso reduz superfície real de ataque.
3. **PR 3 — Parar mutações silenciosas em rotas GET críticas**
   - Separar leitura de correção automática/backfill em Workforce e Tickets.
   - Reduz surpresa operacional e melhora auditabilidade.
4. **PR 4 — Substituir auto-DDL em request por readiness explícita**
   - Parar de criar/alterar tabela durante request operacional.
   - Falhar com diagnóstico claro quando migration obrigatória não estiver aplicada.
5. **PR 5 — Endurecer compatibilidade de escopo legado (`organizer_id IS NULL`)**
   - Mapear onde o legado continua aceitável e onde precisa virar erro/diagnóstico.
   - Reduz drift multi-tenant sem abrir remodelagem ampla.

### Primeiro PR recomendado
- **PR 1 — Hardening do contexto operacional do POS/sync offline**
- Escopo pequeno, seguro e de alto impacto:
  - remover `eventId = "1"` como default em `usePosCatalog`
  - impedir checkout e gravação offline sem `event_id` explícito e válido
  - remover `?? 1` do `SyncController`
  - garantir que a fila offline e o replay falhem de forma explícita quando o contexto do evento estiver ausente
- Motivo:
  - é o melhor ganho imediato de integridade de dados
  - reduz risco de venda em evento errado
  - não depende de redesign nem de V4

## Hardening do contexto operacional do POS/sync offline

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** eliminar `event_id` implícito no fluxo POS/sync offline e fazer o sistema falhar de forma explícita quando o contexto do evento estiver ausente

### Escopo executado
- Frontend
  - `frontend/src/modules/pos/hooks/usePosCatalog.js`
    - `eventId` deixou de nascer como `"1"` e passou a nascer vazio.
    - o catálogo não tenta mais carregar produtos sem `event_id` válido.
  - `frontend/src/pages/POS.jsx`
    - checkout e gravação de produto agora são bloqueados com mensagem clara quando não existe `event_id` explícito.
    - o payload operacional passa a enviar `event_id` normalizado como número.
    - o checkout fica desabilitado até existir evento selecionado e a UI informa esse bloqueio.
  - `frontend/src/modules/pos/hooks/usePosOfflineSync.js`
    - a fila offline em `localStorage` rejeita novas vendas sem `event_id` válido.
    - o replay não tenta sincronizar itens sem evento; eles permanecem pendentes com diagnóstico explícito.
  - `frontend/src/hooks/useNetwork.js`
    - a fila Dexie (`offlineQueue`) também deixou de enviar registros sem `event_id` válido.
    - quando houver item legado sem evento, a sincronização é bloqueada de forma explícita, sem reatribuição automática.
  - `frontend/src/modules/pos/hooks/usePosReports.js`
    - relatórios e insights do POS deixaram de consultar backend sem evento selecionado.
  - `frontend/src/modules/pos/components/PosToolbar.jsx`
    - o seletor passou a expor placeholder honesto de seleção de evento.
- Backend
  - `backend/src/Controllers/SyncController.php`
    - o registro da fila offline deixou de usar `?? 1`.
    - request sem `event_id` válido agora falha com `422`.
  - `backend/src/Controllers/BarController.php`
  - `backend/src/Controllers/FoodController.php`
  - `backend/src/Controllers/ShopController.php`
    - rotas operacionais do POS (`products`, `sales`, `checkout`, `insights`) agora exigem `event_id` válido em vez de assumir `1`.

### Comportamento preservado
- Nenhum dado existente do evento admin foi migrado, reatribuído ou apagado.
- O fluxo continua funcionando normalmente quando o evento está corretamente selecionado.
- Itens offline antigos sem `event_id` não são mascarados nem forçados para outro evento; permanecem pendentes até correção explícita.

### Validação executada
- `npx eslint src/modules/pos/hooks/usePosCatalog.js src/modules/pos/hooks/usePosReports.js src/modules/pos/components/PosToolbar.jsx src/modules/pos/components/CheckoutPanel.jsx src/modules/pos/hooks/usePosOfflineSync.js src/hooks/useNetwork.js src/pages/POS.jsx`
- `php -l backend/src/Controllers/SyncController.php`
- `php -l backend/src/Controllers/BarController.php`
- `php -l backend/src/Controllers/FoodController.php`
- `php -l backend/src/Controllers/ShopController.php`
- Não houve validação viva com backend/banco real nesta rodada.

## Meals Control — PR final de hardening local e encerramento técnico da fase

- **Responsável:** Codex
- **Status:** Executado e tecnicamente encerrado nesta fase
- **Objetivo:** eliminar a fragilidade real de concorrência no `POST /meals`, revisar a honestidade local do fallback Workforce sem reabrir escopo e deixar a frente apenas para conferência/testes posteriores

### Correção obrigatória executada
- `backend/src/Controllers/MealController.php`
  - `registerMeal()` deixou de operar no padrão frágil `count then insert` sem proteção.
  - O fluxo agora:
    - valida participante e contexto do evento
    - abre transação
    - adquire lock transacional por `participant_id + event_day_id` com `pg_advisory_xact_lock`
    - recalcula a configuração operacional dentro da transação
    - mantém bloqueio de baseline ambíguo
    - reconta o consumo diário sob lock
    - só então insere em `participant_meals`
  - Isso serializa requests simultâneas do mesmo participante no mesmo dia e impede ultrapassar a cota por corrida de concorrência.
  - O contrato HTTP foi preservado:
    - sucesso continua `201`
    - limite diário continua `409`
    - ambiguidade real continua `409`
- Helpers locais adicionados:
  - `mealAcquireParticipantDayQuotaLock()`
  - `mealAssertDailyQuotaAvailable()`

### Ajuste opcional executado
- `frontend/src/pages/MealsControl.jsx`
  - No modo complementar baseado em Workforce, o card de membros deixou de contar linha de assignment como se fosse pessoa.
  - `workforceSummary.members` agora conta pessoas únicas.
  - A UI passou a explicitar quando existe diferença entre:
    - pessoas únicas no card
    - assignments reais na tabela complementar
  - A tabela complementar permaneceu localmente assignment-level; não houve reabertura de modelagem nem consolidação ampla nesse modo.

### Validação executada
- `php -l backend/src/Controllers/MealController.php`
- `npx eslint src/pages/MealsControl.jsx`
- Revisão local do diff para garantir que:
  - a serialização ficou restrita ao escopo `participante + dia`
  - o fallback complementar não voltou a fingir equivalência entre pessoa e assignment

### Limite preservado
- Ainda falta teste vivo de concorrência contra banco real com duas requests simultâneas no mesmo participante/dia.
- O workspace desta rodada permitiu validação de sintaxe e leitura técnica, mas não prova prática de corrida em ambiente operacional.

### Encerramento da fase
- Com este patch, a frente Meals fica tecnicamente encerrada nesta fase.
- A partir daqui, Meals deve permanecer apenas para:
  - conferência posterior
  - testes posteriores
  - bug operacional real, se surgir
- Não há motivo para reabrir modelagem, baseline ou fallback complementar fora desses gatilhos reais.

## Hardening da sessão web atual

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** reduzir a fragilidade prática da sessão web atual sem abrir nova arquitetura de autenticação

### Diagnóstico atacado
- O frontend persistia em `localStorage`:
  - `access_token`
  - `refresh_token`
  - `enjoyfun_user`
- O `api.js` lia e renovava sessão diretamente desse storage.
- Havia ainda dois pontos frágeis adicionais:
  - logs de resposta de login/registro expondo tokens no console
  - `localStorage.clear()` em falha de refresh, apagando estado além da sessão

### Endurecimento executado
- Frontend
  - `frontend/src/lib/session.js`
    - criado gerenciador local de sessão para centralizar leitura, persistência, limpeza e migração de sessão legada.
    - a sessão passou a usar `sessionStorage`, não `localStorage`.
    - sessões antigas em `localStorage` são migradas para `sessionStorage` no primeiro carregamento e removidas do storage legado.
  - `frontend/src/context/AuthContext.jsx`
    - deixou de ler e gravar tokens diretamente em `localStorage`.
    - bootstrap da sessão agora usa o gerenciador local e trata `401` de forma explícita.
    - falhas não autenticadas deixam de limpar sessão agressivamente quando não são `401`.
    - logs de login/registro com payload sensível foram removidos.
  - `frontend/src/lib/api.js`
    - request interceptor passou a ler `access_token` do gerenciador de sessão.
    - response interceptor passou a serializar refresh concorrente com uma única promise compartilhada.
    - falha de refresh limpa apenas a sessão autenticada e redireciona para a rota correta de login, sem usar `localStorage.clear()`.
  - `frontend/src/pages/CustomerApp/CustomerLogin.jsx`
  - `frontend/src/pages/CustomerApp/CustomerDashboard.jsx`
    - fluxo OTP do app do cliente foi alinhado ao mesmo gerenciador de sessão.
    - logout do cliente passou a invalidar refresh token via endpoint antes de limpar a sessão local.
- Backend
  - Nenhuma mudança foi necessária nesta rodada.
  - O backend já rotaciona refresh token em `/auth/refresh`; o endurecimento desta fase era reduzir persistência frouxa e lifecycle frágil no cliente.

### Resultado prático
- Tokens sensíveis deixaram de ficar persistidos em `localStorage`.
- A sessão agora vive por aba/sessão de navegador via `sessionStorage`.
- Refresh concorrente deixa de disparar múltiplas rotações ao mesmo tempo.
- Falha de refresh passou a ser tratada de forma explícita, previsível e contida.

### Limite preservado
- Esta rodada não elimina toda a fragilidade estrutural de sessão porque o sistema ainda depende de token controlado por JavaScript e header `Authorization`.
- Portanto:
  - o risco de XSS não cai a zero
  - não houve migração para cookie `HttpOnly`
  - não foi aberta nova arquitetura de autenticação
- O hardening desta rodada reduz exposição prática e persistência desnecessária, mas não equivale a uma redesign completo de auth.

### Validação executada
- `npx eslint src/context/AuthContext.jsx src/lib/api.js src/lib/session.js src/api/auth.js src/pages/CustomerApp/CustomerLogin.jsx src/pages/CustomerApp/CustomerDashboard.jsx`
- `php -l backend/src/Controllers/AuthController.php`
- checagem local para confirmar ausência de persistência direta dos tokens em `localStorage` no frontend autenticado

## Meals Control — fechamento final da camada financeira/projeção e encerramento da frente

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** auditar a camada financeira complementar de Meals, corrigir o último bug local de consumo do payload e encerrar a frente com honestidade

### Comparação com o histórico da frente
- O core operacional de Meals já estava encerrado antes desta rodada:
  - semântica honesta de turno
  - saldo real consolidado por participante único
  - bloqueio de baseline ambíguo no `POST /meals`
  - diagnóstico explícito de ambiguidade no `GET /meals/balance`
  - serialização local de concorrência no consumo
  - ajuste semântico de pessoas únicas vs assignments no modo complementar
- A única pendência real restante estava na camada financeira complementar:
  - entender por que alterar custo/valor não mudava a leitura visual como esperado
  - distinguir bug local de frontend de limitação real de schema/readiness
- Não houve reabertura de:
  - baseline operacional
  - modelagem ampla de Meals
  - fallback Workforce como saldo real
  - camada financeira nova

### Diagnóstico final da camada financeira/projeção
- Persistência/configuração
  - `backend/src/Controllers/OrganizerFinanceController.php` delega leitura e gravação a `EnjoyFun\Services\FinancialSettingsService`.
  - `backend/src/Services/FinancialSettingsService.php` só persiste `meal_unit_cost` quando a coluna existe de fato em `organizer_financial_settings`.
- Leitura no backend
  - `backend/src/Controllers/MealController.php` só habilita `projection_summary` quando `meal_unit_cost` existe no schema real.
  - Quando a coluna não existe, o endpoint responde de forma honesta com:
    - `projection_summary.enabled = false`
    - `meal_unit_cost = 0`
    - diagnostics com `meal_unit_cost_schema_unavailable`
- Evidência de ambiente/schema
  - `database/schema_real.sql` continua sem `meal_unit_cost` na definição principal de `organizer_financial_settings`.
  - Portanto, no workspace atual, alterar esse valor não fecha a camada financeira por persistência real.
- Consumo do payload no frontend
  - O problema local encontrado em `frontend/src/pages/MealsControl.jsx` não era o cálculo do backend.
  - O frontend ainda:
    - criava fallback de `projectionSummary` com `enabled: true` sem payload real
    - assumia localmente que o valor salvo tinha persistido, mesmo quando o backend devolvia `meal_unit_cost = 0`
- Conclusão do diagnóstico
  - O motivo principal para a tela não mudar como esperado é limitação real de schema/readiness do ambiente.
  - Havia também um bug local de frontend que fazia a camada financeira parecer mais pronta/persistida do que realmente estava.

### Patch final executado
- Frontend apenas
  - `frontend/src/pages/MealsControl.jsx`
    - removeu o fallback local que marcava `projectionSummary.enabled` como `true` sem payload real
    - deixou de reaproveitar `mealUnitCost` local como se fosse leitura persistida do backend
    - `saveMealCost()` passou a usar o valor efetivamente retornado pelo backend após o `PUT`
    - quando o ambiente não sustenta `meal_unit_cost`, a tela deixa isso explícito e não finge atualização aplicada
- Backend
  - Nenhum patch foi necessário.
  - A leitura, os diagnostics e a degradação de `projection_summary` já estavam coerentes com o schema real existente.

### Resultado final e encerramento da frente
- Meals fica encerrado nesta fase como frente de engenharia.
- Operacionalmente, Meals permanece fechado como baseline estável.
- Financeiramente, a camada complementar fica encerrada com honestidade:
  - o código está coerente de ponta a ponta
  - a disponibilidade real continua condicionada ao schema/ambiente
  - o que resta não é nova frente de engenharia local, e sim conferência/teste em ambiente real que sustente `meal_unit_cost`
- A partir daqui, Meals deve permanecer apenas para:
  - conferência posterior
  - testes posteriores
  - bug operacional real, se surgir

### Validação executada
- `npx eslint src/pages/MealsControl.jsx`
- revisão dirigida do fluxo:
  - `backend/src/Services/FinancialSettingsService.php`
  - `backend/src/Controllers/OrganizerFinanceController.php`
  - `backend/src/Controllers/MealController.php`
  - `frontend/src/pages/MealsControl.jsx`
  - `database/schema_real.sql`
- Ainda falta prova viva em ambiente com banco real compatível com `meal_unit_cost`.

## Meals Control — correção de regressão de disponibilidade no frontend após fechamento financeiro

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** restaurar a usabilidade honesta do frontend de Meals após a regressão introduzida no ajuste final da camada financeira/projeção

### Diagnóstico da regressão
- A indisponibilidade financeira passou a vazar para blocos operacionais da tela.
- O problema não estava no core operacional nem no contrato backend de saldo.
- A regressão local do frontend vinha de três acoplamentos indevidos:
  - issues financeiras (`meal_unit_cost_*`) aparecendo em blocos globais de leitura operacional
  - banner genérico de “leitura operacional parcial” disparando mesmo quando o problema era apenas financeiro
  - botão de configuração de custo ficando acoplado a `projectionEnabled`, como se indisponibilidade de projeção tornasse a tela inteira indisponível

### Correção executada
- `frontend/src/pages/MealsControl.jsx`
  - separou issues operacionais de issues financeiras no consumo de `diagnostics`
  - removeu a contaminação de indisponibilidade financeira dos notices e da lista “Leitura operacional do recorte”
  - retirou o badge global de `Custo indisponível` do bloco operacional de contexto
  - liberou novamente o acesso ao modal `Valor Refeição`, sem depender de `projectionEnabled`
  - o modal voltou a abrir a partir do valor configurado em settings, sem reaproveitar estado local enganoso de projeção
  - a mensagem de indisponibilidade financeira ficou restrita ao card financeiro complementar

### Resultado prático
- O saldo operacional do Meals continua visível quando existe.
- O consumo real continua visível quando existe.
- A base complementar do Workforce continua aparecendo quando o evento ainda não sustenta saldo diário.
- A indisponibilidade financeira deixa de derrubar ou “contaminar” a leitura operacional.
- Meals não deve ser tratado como encerrado neste momento; a frente volta a ficar aberta apenas para conferência e testes posteriores dessa regressão.

### Validação executada
- `npx eslint src/pages/MealsControl.jsx`
- revisão local dirigida do fluxo visual e das condições de exibição para confirmar:
  - separação entre indisponibilidade financeira e disponibilidade operacional
  - manutenção do modo complementar baseado em Workforce
  - manutenção da leitura real de saldo/consumo quando disponível

## Meals Control — ajuste final de filtros, estados operacionais e cards da tela

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** alinhar o frontend de Meals com a operação real do evento, sem reabrir backend central

### Diagnóstico atacado
- O frontend ainda misturava dois modos diferentes:
  - saldo real do Meals por `event_day`
  - base complementar do Workforce quando o evento não possui `event_days`
- O filtro de cargo seguia exposto mesmo sem necessidade operacional real nesta tela.
- Os cards do topo no modo complementar ainda exibiam contagens pouco úteis ou semanticamente frágeis:
  - refeições configuradas somadas no agregado
  - assignments com turno
  - assignments sem turno
- A tela ainda conduzia mal os estados:
  - “selecione o dia” aparecia como se fosse ação resolúvel mesmo quando o evento não tinha `event_days`
  - a indisponibilidade de saldo/registro sem base diária não estava explicitada de forma operacionalmente útil

### Ajuste executado
- `frontend/src/pages/MealsControl.jsx`
  - filtros
    - manteve `Evento`
    - manteve `Dia`, vindo de `event_days`
    - manteve `Turno`, vindo de `event_shifts`, que é a base ligada aos assignments do Workforce no workspace real
    - removeu `Cargo`
    - manteve `Setor`, agora puxado apenas da base real do Workforce
  - estados operacionais
    - o modo complementar passou a depender apenas de ausência real de `event_days`
    - evento sem `event_days` deixou de pedir “selecione o dia” como se isso pudesse ser resolvido no filtro
    - o formulário de registro de refeição passou a bloquear explicitamente quando não existe base diária
    - o botão de atualizar passou a recarregar a base complementar do Workforce quando o módulo está nesse modo
  - cards e contexto
    - o modo complementar foi reduzido para cards úteis:
      - pessoas no Workforce
      - assignments visíveis
      - setores visíveis
    - os cards confusos de:
      - refeições configuradas
      - assignments com turno
      - assignments sem turno
      foram removidos do topo
    - os cards financeiros ficaram restritos ao modo com saldo real diário ativo
    - o bloco de contexto passou a explicar explicitamente:
      - qual base está ativa
      - o que está disponível
      - o que está indisponível

### Resultado operacional
- Evento com `event_days`
  - seleção de dia e turno segue funcional
  - saldo real do Meals e registro de refeição ficam disponíveis
- Evento sem `event_days`
  - a tela entra em modo complementar do Workforce de forma explícita
  - saldo real diário, recorte útil de turno e registro de refeição ficam indisponíveis com explicação clara
- Evento com assignments sem vínculo de turno
  - a ausência de turno permanece visível, mas deixa de poluir os cards principais
- A camada financeira continua separada e não interfere na leitura operacional

### Validação executada
- `npx eslint src/pages/MealsControl.jsx`
- revisão local do fluxo para confirmar:
  - remoção do filtro de cargo
  - manutenção do filtro de setor vindo do Workforce
  - coerência entre modos com e sem `event_days`
  - bloqueio honesto do registro de refeição quando não existe base diária
  - redução dos cards para métricas operacionalmente úteis

### Encaminhamento da frente
- Com este ajuste, Meals pode sair de foco como frente ativa de implementação.
- A partir daqui, a frente deve ficar apenas para:
  - conferência posterior
  - testes posteriores
  - bug operacional real, se surgir

## Hardening — parar mutações silenciosas em rotas GET críticas

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** garantir leitura sem efeito colateral em GETs críticos de Workforce e Tickets

### Diagnóstico atacado
- `GET /workforce/assignments` (em `backend/src/Controllers/WorkforceController.php`) executava mutação silenciosa:
  - chamava `backfillMissingQrTokensForEvent(...)` dentro da leitura
  - isso atualizava `event_participants.qr_token` durante listagem
- `GET /tickets/types` (em `backend/src/Controllers/TicketController.php`) executava mutação silenciosa:
  - chamava `ensureLegacyCommercialTicketType(...)` dentro da leitura
  - esse fluxo podia inserir `ticket_types` e atualizar `ticket_batches.ticket_type_id`
- Risco operacional/contratual:
  - quebra de semântica HTTP de leitura
  - baixa previsibilidade para auditoria
  - mascaramento de base legada incompleta

### Hardening executado
- `backend/src/Controllers/WorkforceController.php`
  - removida a chamada de backfill de QR token no `listAssignments`
  - leitura continua retornando dados, mas agora inclui diagnóstico por linha:
    - `qr_token_missing: true|false`
  - quando houver faltantes, a resposta retorna mensagem explícita de que GET não corrige mais automaticamente
- `backend/src/Controllers/TicketController.php`
  - removida a correção automática no `listTicketTypes`
  - adicionado `legacyCommercialTicketTypeBackfillRequired(...)` para detecção sem mutação
  - quando detectar base comercial legada incompleta (lotes sem tipo comercial padrão), o endpoint responde `409` com diagnóstico claro e instrução de regularização por fluxo explícito de escrita

### Resultado prático
- GET voltou a ser GET nos dois fluxos críticos mapeados.
- Deixou de acontecer silenciosamente:
  - geração/backfill de `qr_token` na listagem de assignments
  - criação/backfill de tipo comercial na listagem de tipos de ingresso
- Quando a base estiver incompleta, o sistema agora expõe o problema em vez de ocultar via auto-correção em leitura.

### Validação executada
- `php -l backend/src/Controllers/WorkforceController.php`
- `php -l backend/src/Controllers/TicketController.php`
- revisão local de diff para confirmar remoção de escrita em GET e manutenção de diagnóstico explícito

### Fora de escopo nesta rodada
- Não foi criada nova arquitetura de readiness.
- Não foram alterados fluxos de escrita existentes que já podem regularizar base (`POST/PUT`).
- Não houve mistura com Meals, Analytics ou V4.

## Hardening — substituir auto-DDL em request por readiness explícita de ambiente

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** remover `CREATE/ALTER` de requests operacionais e falhar com diagnóstico claro quando schema obrigatório estiver ausente

### Diagnóstico atacado
- `backend/src/Controllers/WorkforceController.php`
  - `ensureWorkforceRoleSettingsTable(...)` era chamada em:
    - `GET /workforce/role-settings/{roleId}`
    - `PUT /workforce/role-settings/{roleId}`
  - a função executava `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` e `CREATE INDEX IF NOT EXISTS` durante request normal.
- `backend/src/Controllers/OrganizerSettingsController.php`
  - `ensureOrganizerSettingsTable(...)` era chamada em:
    - `GET /organizer-settings`
    - `PUT /organizer-settings`
    - `POST /organizer-settings/logo`
  - a função executava `CREATE TABLE IF NOT EXISTS` e `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` durante request normal.
- Risco operacional:
  - readiness de ambiente implícita e instável
  - drift de schema entre ambientes
  - efeito colateral estrutural oculto em rotas operacionais

### Hardening executado
- `backend/src/Controllers/WorkforceController.php`
  - `ensureWorkforceRoleSettingsTable(...)` deixou de executar DDL.
  - a função agora valida explicitamente:
    - existência da tabela `workforce_role_settings`
    - existência das colunas obrigatórias para uso operacional
  - quando faltar estrutura, responde `409` com mensagem de readiness e colunas ausentes.
  - `GET/PUT role-settings` deixaram de receber fallback estrutural implícito.
- `backend/src/Controllers/OrganizerSettingsController.php`
  - `ensureOrganizerSettingsTable(...)` deixou de executar DDL.
  - a função agora valida explicitamente:
    - existência da tabela `organizer_settings`
    - existência das colunas necessárias para configurações visuais e de mensageria
  - quando faltar estrutura, responde `409` com diagnóstico explícito de readiness.
  - adicionados helpers read-only de introspecção:
    - `organizerSettingsTableExists(...)`
    - `organizerSettingsColumnExists(...)`

### Resultado prático
- Requests operacionais não criam nem alteram schema.
- Ambientes incompletos passam a falhar com diagnóstico claro, sem auto-correção estrutural silenciosa.
- Contrato operacional fica mais previsível para auditoria e implantação.

### Validação executada
- `php -l backend/src/Controllers/WorkforceController.php`
- `php -l backend/src/Controllers/OrganizerSettingsController.php`
- `php -l backend/src/Controllers/OrganizerMessagingSettingsController.php`
- checagem local para confirmar ausência de `CREATE TABLE`/`ALTER TABLE` nesses fluxos

### Fora de escopo nesta rodada
- Não foi criada nova engine de migration.
- Não foi aberta arquitetura nova de readiness.
- Não houve mistura com Meals, Analytics ou V4.

## Hardening — endurecer compatibilidade de escopo legado com `organizer_id IS NULL`

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** reduzir ambiguidade de escopo legado/null nos fluxos críticos sem reescrever multitenancy

### Diagnóstico atacado
- Leituras e escritas de `ticket_types` ainda aceitavam `organizer_id IS NULL` como compatibilidade ampla em pontos críticos:
  - `backend/src/Controllers/TicketController.php`
    - emissão comercial (`POST /tickets`) validava tipo com `(organizer_id = ? OR organizer_id IS NULL)`
    - criação de lote (`POST /tickets/batches`) validava `ticket_type_id` com o mesmo fallback
    - listagem de tipos (`GET /tickets/types`) mantinha compatibilidade legada sem explicitar origem de escopo no payload
  - `backend/src/Controllers/EventController.php`
    - sincronização comercial do evento (`syncEventTicketTypes`, `syncEventBatches`) ainda podia atualizar/deletar ou vincular `ticket_types` legados com `organizer_id IS NULL`
- Risco operacional/contratual:
  - uso de tipo comercial fora de escopo explícito na emissão/vinculação
  - mutação de dados legados sem delimitação clara entre compatibilidade de leitura e escrita
  - drift de comportamento entre ambientes com legado diferente

### Decisão de hardening
- Compatibilidade legada/null fica aceitável somente para leitura controlada.
- Fluxos de escrita críticos deixam de aceitar `ticket_type` legado `NULL` como equivalente ao escopo do organizer.
- Quando o legado/null for detectado em operação de escrita, a resposta passa a ser diagnóstico explícito (`409`) com instrução de regularização de escopo.
- Deliberadamente fora nesta rodada:
  - não houve remodelagem de tenant/multitenancy
  - não houve limpeza massiva de legado
  - não houve mudança de arquitetura

### Patch executado
- `backend/src/Controllers/TicketController.php`
  - `storeTicket()` passou a validar `ticket_type` estritamente por `organizer_id = ?` (sem `OR organizer_id IS NULL`).
  - `createTicketBatch()` passou a validar `ticket_type_id` estritamente por `organizer_id = ?`.
  - novo helper `legacyNullScopedTicketTypeExists(...)` para detectar tipo legado `NULL` no evento do organizer.
  - quando tipo legado/null for usado em escrita, endpoints agora retornam `409` com diagnóstico explícito.
  - `GET /tickets/types` preserva compatibilidade de leitura, mas agora explicita origem no payload via `scope_origin` (`organizer` | `legacy_null`).
- `backend/src/Controllers/EventController.php`
  - `syncEventTicketTypes()` passou a:
    - bloquear edição de `ticket_type` legado `NULL` com `409` explícito
    - atualizar/deletar apenas tipos do organizer (`organizer_id = ?`)
    - preservar linhas legadas `NULL` sem mutação silenciosa
  - novo helper `eventHasLegacyNullScopedTicketType(...)` para diagnóstico de vínculo legado em lote.
  - `syncEventBatches()` passou a aceitar vínculo de tipo apenas com `organizer_id = ?`; se detectar tipo legado/null, responde `409` explícito.

### Resultado prático
- Escritas comerciais críticas deixam de “herdar” escopo global implícito por `organizer_id IS NULL`.
- Compatibilidade legada continua controlada em leitura, sem mascarar origem de escopo.
- Legado/null em caminho crítico de escrita passa a ser tratado como condição de regularização explícita, não como fallback silencioso.

### Validação executada
- `php -l backend/src/Controllers/TicketController.php`
- `php -l backend/src/Controllers/EventController.php`
- revisão dirigida de diff para confirmar:
  - remoção de `OR organizer_id IS NULL` em validações de escrita críticas
  - manutenção de compatibilidade de leitura com explicitação de `scope_origin`

### Limites preservados
- `backend/src/Services/DashboardDomainService.php` e `backend/src/Services/SalesReportService.php` mantêm compatibilidade de leitura com `organizer_id IS NULL` condicionada a `EXISTS` em `events` do organizer (sem mutação em request).
- `backend/src/Services/ProductService.php` mantém verificação defensiva de vínculo legado em vendas para evitar exclusão indevida; não houve alteração nessa rodada.

## Hardening — consolidação da trilha atual (PR 1 a PR 5)

- **Responsável:** Codex
- **Status:** Consolidado
- **Objetivo:** fechar leitura honesta do que foi realmente endurecido, o que ficou mitigado e quais riscos remanescentes ainda justificam execução

### Estado consolidado (resolvido de forma concreta)
- **PR 1 — contexto operacional POS/sync offline**
  - `event_id` implícito foi removido do frontend (`eventId` não nasce mais como `"1"`).
  - checkout/gravação sem evento válido passaram a bloquear com mensagem explícita.
  - backend de sync (`SyncController`) deixou de aceitar fallback implícito e passou a rejeitar `event_id` inválido com erro explícito.
- **PR 2 — sessão web atual**
  - sessão de auth deixou de persistir tokens em `localStorage` e passou a usar `sessionStorage` com migração controlada de legado.
  - refresh concorrente foi serializado no cliente (`single-flight`) para reduzir rotação paralela de token.
  - falhas de refresh passaram a limpar só sessão autenticada, sem `localStorage.clear()` global.
- **PR 3 — parada de mutações silenciosas em GET**
  - `GET /workforce/assignments` não faz mais backfill de QR token em leitura.
  - `GET /tickets/types` não faz mais correção automática de tipo comercial em leitura; responde com diagnóstico quando base legada exige regularização.
- **PR 4 — remoção de auto-DDL em request**
  - `WorkforceController` e `OrganizerSettingsController` deixaram de executar `CREATE/ALTER` em request operacional.
  - fluxos passaram a falhar com readiness explícita (`409`) quando migration obrigatória não estiver aplicada.
- **PR 5 — escopo legado `organizer_id IS NULL`**
  - escrita comercial crítica (emissão de ticket, vínculo de lote, sync comercial de evento) não aceita mais `ticket_type` legado `NULL` como equivalente de tenant.
  - quando legado/null entra em caminho de escrita, o contrato agora responde diagnóstico explícito (`409`).
  - leitura compatível foi preservada com explicitação de origem (`scope_origin`) em tipos comerciais.

### Estado consolidado (mitigado, não eliminado)
- **Sessão web:** risco caiu de `localStorage` para `sessionStorage`, mas tokens continuam controlados por JavaScript no cliente.
- **Escopo legado:** redução forte em fluxos críticos de escrita comercial, mas ainda existe compatibilidade legada controlada em leituras específicas.

### Limites remanescentes reais
- Ainda não houve prova viva E2E em ambiente real para toda a trilha; a validação executada foi majoritariamente por sintaxe/diff/leitura de fluxo.
- Sessão web ainda não é `HttpOnly` cookie-based; portanto risco de XSS não cai a zero.
- Compatibilidade legada com `organizer_id IS NULL` ainda existe em rotas de leitura fora do núcleo comercial (ex.: categorias de participante), mantendo potencial de drift semântico entre ambientes legados.
- POS offline mantém persistência local de payload operacional para sincronização; isso preserva funcionamento offline, mas mantém superfície de exposição local e risco de divergência entre filas (`localStorage` vs Dexie) até consolidação explícita.

### O que não deve ser reaberto agora
- Não reabrir PR 1 a PR 5 para refactor estrutural sem bug objetivo.
- Não reabrir mutação em GET, auto-DDL em request ou fallback implícito de `event_id`.
- Não misturar esta consolidação com Meals, Analytics ou V4.
- Não tratar compatibilidade legada de leitura como bug crítico automático quando ela está explicitamente condicionada e sem mutação.

### Próxima fila recomendada (curta e executável)
1. **Hardening de escopo legado fora do comercial crítico**
   - Alvo inicial: `ParticipantController::listCategories` e outros GETs com `organizer_id IS NULL` ainda amplos.
   - Justificativa: fechar drift de escopo remanescente sem remodelagem de tenant.
2. **Hardening do offline local do POS (persistência e fila)**
   - Delimitar e reduzir exposição de dados sensíveis no armazenamento local e unificar política entre `localStorage` e Dexie.
   - Justificativa: risco operacional/segurança ainda aberto no caminho offline.
3. **Rodada de validação viva dirigida da trilha de hardening**
   - Executar smoke operacional com banco/ambiente real para PR1–PR5 e registrar evidência de contrato.
   - Justificativa: reduzir incerteza residual que hoje está apenas em validação estática.

## Hardening — validação viva dirigida da trilha PR1–PR5

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** validar em ambiente real, no que o workspace sustenta hoje, os hardenings PR1–PR5 sem abrir refactor

### Ambiente efetivamente utilizado
- API local viva em `http://localhost:8080/api`:
  - `GET /api/ping` respondeu `200`
  - `GET /api/health` respondeu `200`
- Banco real acessível por `node + pg` com credenciais do `backend/.env`.
- Limite de ambiente preservado:
  - o PHP CLI local continua sem `pdo_pgsql`
  - portanto a validação viva de banco/fluxo não foi feita via `php`, e sim via API local + consultas read-only com `pg`

### Validação executada
- **Auth / sessão (PR 2)**
  - `POST /api/auth/login` com `admin@enjoyfun.com.br`
  - `GET /api/auth/me` com `access_token`
  - `POST /api/auth/refresh`
  - novo `GET /api/auth/me` com o novo `access_token`
  - execução runtime local do módulo `frontend/src/lib/session.js` com `window` mockado:
    - sessão legada em `localStorage` foi migrada para `sessionStorage`
    - `localStorage` ficou limpo após a migração
    - `persistSession()` continuou escrevendo apenas em `sessionStorage`
- **POS / sync offline (PR 1)**
  - `GET /api/bar/products` sem `event_id`
  - `GET /api/bar/products?event_id=1`
  - `POST /api/sync` com item offline sem `event_id`
  - `POST /api/sync` com item offline e `event_id=1`
- **GET sem mutação silenciosa (PR 3)**
  - tentativa dirigida de validação do `GET /api/workforce/assignments` comparando estado antes/depois no banco
  - tentativa dirigida de validação do `GET /api/tickets/types` em cenário legado comparando estado antes/depois no banco
- **Readiness explícita (PR 4)**
  - `GET /api/organizer-settings`
  - `GET /api/workforce/role-settings/20`
  - introspecção read-only no banco para confirmar presença das colunas obrigatórias de `organizer_settings` e `workforce_role_settings`
- **Escopo legado/null crítico (PR 5)**
  - busca read-only no banco por `ticket_types` com `organizer_id IS NULL` no organizer autenticado, para procurar cenário real reproduzível sem forçar mutação operacional

### Evidências objetivas observadas
- **PR 1**
  - `GET /api/bar/products` sem `event_id` respondeu `422` com mensagem:
    - `event_id é obrigatório para operações do POS.`
  - `GET /api/bar/products?event_id=1` respondeu `200` com catálogo real do setor `bar`.
  - `POST /api/sync` sem `event_id` respondeu `207` por item, com erro explícito:
    - `Evento inválido para sincronização offline.`
- **PR 2**
  - login respondeu `200`
  - `GET /auth/me` antes do refresh respondeu `200`
  - `POST /auth/refresh` respondeu `200` com novo `access_token` e novo `refresh_token`
  - `GET /auth/me` com o novo token respondeu `200`
  - runtime do módulo de sessão confirmou:
    - migração de legado para `sessionStorage`
    - limpeza do `localStorage`
    - persistência subsequente apenas em `sessionStorage`
- **PR 4**
  - `GET /api/organizer-settings` respondeu `200`
  - `GET /api/workforce/role-settings/20` respondeu `200`
  - introspecção de schema encontrou:
    - `11` colunas esperadas presentes em `organizer_settings`
    - `12` colunas esperadas presentes em `workforce_role_settings`

### O que passou
- O bloqueio explícito de contexto no POS sem `event_id` passou no runtime real.
- O catálogo do POS com `event_id` válido passou no runtime real.
- O fluxo de login + `me` + refresh + novo `me` passou no runtime real.
- O endurecimento do storage de sessão passou em runtime local do módulo frontend.
- As rotas de readiness explícita testadas passaram no ambiente atual com schema disponível.

### O que falhou ou ficou inconclusivo
- **Falhou**
  - `POST /api/sync` com `event_id` válido **não** avançou normalmente.
  - Em vez de chegar à validação de setor/regra de negócio, caiu em erro de banco ao inserir na `offline_queue`:
    - `null value in column "device_id" violates not-null constraint`
- **Inconclusivo por ausência de cenário real no ambiente**
  - `GET /api/workforce/assignments` sem mutação de QR:
    - não havia participantes sem `qr_token` no organizer autenticado para reproduzir o cenário
  - `GET /api/tickets/types` sem correção silenciosa de legado:
    - não foi encontrado evento com lotes sem `ticket_type_id` e sem `ticket_types` para reproduzir o cenário legado
  - escrita comercial rejeitando `ticket_type` legado/null:
    - não foi encontrado `ticket_type` com `organizer_id IS NULL` no organizer autenticado
    - e não foi executado POST mutante em dados reais apenas para forçar cenário artificial
- **Inconclusivo por ambiente completo**
  - o caminho de falha `409` por schema ausente/readiness incompleta não pôde ser reproduzido sem degradar o ambiente real, o que não foi feito nesta rodada

### Bug real encontrado
- **Sync offline com `event_id` válido ainda falha antes da regra de negócio**
  - arquivo envolvido: `backend/src/Controllers/SyncController.php`
  - evidência viva:
    - requests de `POST /api/sync` com `event_id=1` responderam `207`, mas com erro de `offline_queue.device_id` nulo
  - causa provável mais forte pela leitura de código:
    - o controller usa placeholders PostgreSQL no estilo `$1..$7` em `PDO::prepare(...)`
    - isso é o principal candidato para o binding incorreto observado em runtime nesse fluxo
  - impacto:
    - o PR 1 validou corretamente o bloqueio sem `event_id`
    - mas o caminho normal do sync com `event_id` válido ainda não está operacionalmente fechado

## Hardening — correção do bug real no sync offline com `event_id` válido

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** restaurar o fluxo de `POST /api/sync` com `event_id` válido sem perder o hardening de contexto explícito

### Diagnóstico fechado
- A falha inicial de runtime em `POST /api/sync` com `event_id` válido acontecia antes da regra de negócio:
  - insert em `offline_queue` quebrando com `device_id` nulo
- A causa raiz principal confirmada foi **binding no controller**, não ausência real de `device_id` no contrato:
  - a query em `backend/src/Controllers/SyncController.php` ainda usava placeholders `$1..$7` via `PDO::prepare(...)`
  - o mesmo insert executado diretamente por `PDO + pdo_pgsql` funcionou normalmente quando reescrito com placeholders `?`
- Na revalidação após esse primeiro ajuste apareceu um segundo bug real do mesmo fluxo:
  - `500 There is no active transaction`
  - causa: `SyncController` já operava em transação, enquanto `SalesDomainService::processCheckout()` abria outra transação na mesma conexão
  - quando o domínio falhava, o rollback externo ainda mascarava o erro original

### Patch executado
- `backend/src/Controllers/SyncController.php`
  - substituídos placeholders `$1..$7` por `?` nas queries do `offline_queue`
  - `device_id` passou a ser normalizado localmente com `trim((string) ...)`
  - rollback no catch passou a ser defensivo (`if ($db->inTransaction())`)
- `backend/src/Services/SalesDomainService.php`
  - `processCheckout()` passou a respeitar transação externa já aberta
  - a service só abre/commita/rollbacka transação quando ela própria é dona da transação

### Validação executada
- Sintaxe
  - `php -l backend/src/Controllers/SyncController.php`
  - `php -l backend/src/Services/SalesDomainService.php`
- Prova técnica local
  - insert da `offline_queue` via `PDO + pdo_pgsql` executado fora da API funcionou com `device_id` preenchido
- Revalidação viva no backend local (`http://localhost:8080/api`)
  - `POST /api/sync` **sem `event_id`**
    - continua falhando corretamente com:
      - `Evento inválido para sincronização offline.`
  - `POST /api/sync` com `event_id=1` e setor inválido
    - passou a responder erro de negócio coerente:
      - `Setor inválido para sincronização offline.`
  - `POST /api/sync` com `event_id=1`, setor válido e payload ainda inconsistente
    - passou a responder erro de negócio coerente:
      - `Inconsistência de valores: O total enviado difere do cálculo seguro no servidor.`
- Resultado da revalidação
  - o fluxo com `event_id` válido deixou de quebrar por `device_id` nulo
  - o fluxo deixou de quebrar com `There is no active transaction`
  - o controller voltou a propagar erros reais de negócio do domínio

### Limite preservado
- Nesta rodada não foi executada uma venda offline completamente bem-sucedida até `processed = 1`, para evitar mutação operacional desnecessária em dados reais.
- A evidência viva desta correção foi:
  - o caminho válido ultrapassar o ponto quebrado
  - e o backend responder com validações de negócio coerentes em vez de erro estrutural do sync

## Hardening — consolidação pós-correção do sync offline

- **Responsável:** Codex
- **Status:** Consolidado
- **Objetivo:** confirmar que o fluxo de sync offline ficou estruturalmente estável após a correção e separar bug técnico resolvido de erro de negócio esperado

### Validação executada
- Revisão dirigida do código corrigido:
  - `backend/src/Controllers/SyncController.php`
  - `backend/src/Services/SalesDomainService.php`
- Revalidação viva no backend local `http://localhost:8080/api` com autenticação real:
  - item sem `event_id`
  - item com `event_id=1` e `X-Device-ID` válido, mas setor inválido
  - item com `event_id=1`, `X-Device-ID` válido e payload inconsistente
- Checagem read-only no banco após os testes:
  - busca na `offline_queue` pelos `offline_id` usados na rodada
  - busca em `sales.offline_id` pelos mesmos ids

### O que ficou confirmado
- O fluxo estrutural corrigido permaneceu estável:
  - `POST /api/sync` com `event_id` válido não quebra mais por `device_id` nulo
  - o erro técnico `There is no active transaction` não reapareceu
  - o controller voltou a propagar erros reais do domínio
- A fila/auditoria não sofreu regressão nos cenários falhos testados:
  - não ficaram linhas residuais em `offline_queue`
  - não ficaram vendas residuais em `sales`
- O hardening de contexto explícito foi preservado:
  - item sem `event_id` continua falhando de forma clara

### Erro de negócio esperado confirmado
- Item sem `event_id`
  - `Evento inválido para sincronização offline.`
- Item com `event_id` válido e setor inválido
  - `Setor inválido para sincronização offline.`
- Item com `event_id` válido e payload inconsistente
  - `Inconsistência de valores: O total enviado difere do cálculo seguro no servidor.`

### Limites remanescentes
- Nesta rodada não foi executado um caso de sync offline totalmente bem-sucedido com `processed = 1`, para evitar baixa/venda real desnecessária em dados do ambiente.
- Portanto, o que ficou comprovado foi:
  - estabilidade estrutural do sync corrigido
  - ausência de regressão em transação/fila
  - preservação dos erros de negócio
- Ainda falta, se desejado em rodada própria, uma prova viva controlada de sucesso completo do replay offline em ambiente seguro.

## Hardening — checkpoint de fechamento do bloco atual

- **Responsável:** Codex
- **Status:** Consolidado
- **Objetivo:** fechar o bloco atual de hardening com leitura objetiva do que já está comprovado, do que ficou mitigado e do que ainda depende de validação viva positiva

### Estado consolidado do bloco atual
- **Resolvido com evidência de código e validação dirigida**
  - contexto operacional do POS/sync offline sem `event_id` implícito
  - sessão web atual sem persistência de `access_token` e `refresh_token` em `localStorage`
  - parada das mutações silenciosas nos GETs críticos priorizados
  - remoção de auto-DDL em request nos fluxos priorizados, com readiness explícita em vez de compatibilidade estrutural automática
  - endurecimento de escrita comercial crítica para não aceitar `ticket_type` legado com `organizer_id IS NULL` como se fosse escopo válido do organizer
  - correção estrutural do sync offline com `event_id` válido:
    - sem quebra por `device_id` nulo
    - sem mascaramento por transação aninhada
- **Mitigado, mas não eliminado estruturalmente**
  - sessão web continua token-based no JavaScript, agora restrita a `sessionStorage`, reduzindo exposição prática mas sem virar auth `HttpOnly`
  - compatibilidade legado/null continua aceita em alguns caminhos de leitura não críticos, de forma mais delimitada, mas ainda existente no sistema atual

### Limites remanescentes reais
- Ainda falta prova viva positiva de um replay offline completo bem-sucedido até `processed = 1` em ambiente seguro.
- A trilha ainda não teve reprodução viva do caminho de falha de readiness por schema ausente, porque o ambiente validado estava estruturalmente completo.
- A rejeição viva de escopo legado/null em escrita comercial crítica ficou endurecida por código, mas ainda não teve cenário real reproduzível com base legada compatível no ambiente validado.

### O que ainda depende de validação viva positiva
- **Sync offline**
  - comprovar um caso completo de sucesso com `event_id` válido, `device_id` válido e payload consistente
- **Readiness explícita**
  - comprovar resposta diagnóstica em ambiente realmente incompleto, sem forçar degradação artificial do ambiente principal
- **Escopo legado/null**
  - comprovar em cenário real que escrita comercial crítica rejeita registro legado/null quando ele de fato existir na base

### Próxima frente recomendada
- **1. Validação viva positiva controlada dos caminhos ainda sem prova de sucesso**
  - é a menor continuação correta para fechar o bloco com evidência prática, sem abrir nova frente técnica
- **2. Hardening do armazenamento/local persistence do POS offline**
  - segue como risco real remanescente de operação local e já apareceu como ponto frágil após o endurecimento de contexto e sync
- **3. Endurecimento pontual do legado/null fora da escrita comercial crítica**
  - apenas nos fluxos de leitura operacional onde a compatibilidade ainda puder gerar ambiguidade real de escopo

## Hardening — endurecer compatibilidade de escopo legado/null fora do comercial crítico

- **Responsável:** Codex
- **Status:** Executado
- **Objetivo:** reduzir ambiguidade de escopo legado/null fora do comercial crítico sem reabrir multitenancy ampla

### Diagnóstico fechado
- O principal ponto perigoso encontrado fora do comercial crítico estava em `GET /participants/categories`:
  - a leitura retornava `participant_categories` com `organizer_id = ? OR organizer_id IS NULL`
  - porém os fluxos operacionais de escrita que consomem essa lista (`POST /participants`, `PUT /participants/:id`, `POST /participants/import`) já validam **somente** categorias do organizer
  - isso deixava a UI apta a exibir opções legadas/globais que a própria escrita rejeita depois
- Compatibilidade ainda aceitável por enquanto:
  - leituras auxiliares de dashboard/report que aceitam `organizer_id IS NULL` apenas quando condicionadas por vínculo do `event_id` ao organizer
  - checagens referenciais como a de `ProductService`, onde o legado/null ainda é usado apenas para não ignorar venda antiga ligada ao mesmo contexto operacional
- Portanto, o menor endurecimento correto nesta rodada era alinhar o endpoint de categorias com o contrato real de escrita, sem reabrir os serviços de leitura já condicionados por evento

### Patch executado
- `backend/src/Controllers/ParticipantController.php`
  - `listCategories()` passou a listar apenas categorias com `organizer_id = ?`
  - as categorias retornadas agora carregam `scope_origin = organizer`
  - quando não houver categorias do organizer, mas existir legado global `organizer_id IS NULL`, o endpoint responde `409` explícito em vez de mascarar com fallback legado
  - quando não houver nem categorias do organizer nem legado global, o endpoint responde `422` explícito
- `frontend/src/pages/ParticipantsTabs/AddParticipantModal.jsx`
  - removido o fallback hardcoded de categorias
  - o modal passou a exibir diagnóstico explícito quando a rota não retorna categorias válidas
  - a seleção e o submit ficam bloqueados quando não há categoria válida do organizer

### Validação executada
- Sintaxe
  - `php -l backend/src/Controllers/ParticipantController.php`
- Frontend
  - `npx eslint src/pages/ParticipantsTabs/AddParticipantModal.jsx`
- Observação de lint
  - `npm run lint` do frontend continua falhando por erros preexistentes fora do escopo desta rodada; o arquivo alterado passou isoladamente

### Estado operacional resultante
- Ficou mais explícito/seguro:
  - a leitura de categorias usada pela operação não promete mais categorias legado/null que a escrita não aceita
  - a UI deixa de mascarar ausência de base com opções hardcoded
- Permanece como compatibilidade aceitável por enquanto:
  - leituras auxiliares com legado/null condicionado por evento, onde o escopo ainda é controlado e não alimenta escrita ambígua direta

## Workforce — Correção da propagação indevida do gerente na lista importada

- **Responsável:** Codex
- **Status:** Executado
- **Data:** 2026-03-12
- **Frente:** Workforce (operacional)
- **Escopo:** backend exclusivo — `backend/src/Controllers/WorkforceController.php`
- **Guardrail:** não misturado com Meals, Analytics, V4 ou hardening geral

### Problema atacado

Depois que o gerente era configurado em um cargo (ex: "Gerente de Bar"), ao importar uma lista de membros **estando dentro desse cargo**, todos os importados ficavam com o cargo do gerente.

### Causa raiz identificada

- `importWorkforce()` possui um guard `managerialRedirect` que deve redirecionar importados para um cargo operacional quando o cargo selecionado é gerencial.
- Esse guard só ativava quando `resolveRoleCostBucket()` retornava `'managerial'` — o que exige que a tabela `workforce_role_settings` tenha uma entry explícita com `cost_bucket = 'managerial'` para aquele cargo.
- Quando o cargo gerencial foi criado sem passar pelo fluxo de configuração de custo (tabela sem entry, ou `cost_bucket` não salvo), `resolveRoleCostBucket()` retornava `'operational'` por fallback de `normalizeCostBucket('')`.
- Resultado: o guard não disparava e o `$defaultRoleId` permanecia como o ID do cargo gerencial. Todos os importados ficavam nesse cargo.

### Patch executado

- `backend/src/Controllers/WorkforceController.php`
  - Guard `managerialRedirect` na função `importWorkforce()` (linha 732) passou a usar verificação dupla:
    - `$isManagerialByBucket`: checa se `cost_bucket = 'managerial'` está salvo no banco (lógica anterior preservada)
    - `$isManagerialByName`: infere via `inferCostBucketFromRoleName()` — já existente no mesmo arquivo — se o nome do cargo contém indicadores gerenciais ("gerente", "diretor", "coordenador", "supervisor", "lider", "chefe", "gestor", "manager")
  - O redirect agora dispara se **qualquer uma** das duas condições for verdadeira.
  - Nenhuma outra função foi alterada.
  - Comportamento preservado: cargos operacionais sem indicadores gerenciais no nome continuam importando normalmente para o cargo correto.

### Validação executada
- `php -l backend/src/Controllers/WorkforceController.php` → `No syntax errors detected`
- Revisão visual das linhas 732-740 após o patch
- Confirmação de que `inferCostBucketFromRoleName()` já existia (linha 1146) com os mesmos indicadores usados também no frontend

### Cenários cobertos pelo fix

| Cargo | cost_bucket salvo | Antes do fix | Depois do fix |
|---|---|---|---|
| "Gerente de Bar" | ausente/vazio | Todos importados viram gerente ❌ | Redirect → "Equipe BAR" ✅ |
| "Gerente de Bar" | `managerial` | Redirect funciona ✅ | Redirect funciona ✅ |
| "Operador de Bar" | qualquer | Importados no cargo correto ✅ | Comportamento preservado ✅ |
| "Diretor de Food" | ausente/vazio | Todos importados viram diretor ❌ | Redirect → "Equipe FOOD" ✅ |

### Limites desta rodada
- Não houve validação viva contra banco real (ambiente sem PostgreSQL acessível via PHP local).
- Não foi aberto redesign do Workforce.
- Não foram tocados Meals, Analytics, V4 ou hardening geral.
- O cargo gerencial continua existindo e com seus membros próprios — apenas a importação em lote ficou protegida da contaminação.
