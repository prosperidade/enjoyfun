# Auditoria completa — Meals + Workforce

Data: 2026-03-19

## Status de fechamento

Atualizacao de 2026-03-22:

- os cinco achados estruturais desta auditoria foram fechados em codigo e migrations versionadas;
- o fechamento material no banco ainda depende apenas de aplicar `021` a `024` no ambiente real;
- este documento permanece como snapshot dos achados originais; o estado corrente de execucao foi consolidado em `docs/progresso9.md`.

## Escopo e método

Esta auditoria foi feita por **leitura estática** do código, schema e migrations, com foco em:

- backend PHP dos domínios `Meals` e `Workforce`;
- schema e migrations em `database/`;
- integrações e consumidores internos que dependem desses domínios;
- bugs silenciosos, deriva de banco, riscos operacionais e inconsistências de contrato.

### Limitação importante

Não foi possível executar auditoria dinâmica no banco porque o repositório **não possui `backend/.env`** no workspace atual e o bootstrap do PDO falha sem `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS`. Portanto, o diagnóstico abaixo é **estático**, mas fundamentado no código-fonte, nas migrations versionadas, no `schema_current.sql` e nos logs operacionais versionados.

---

## Resumo executivo

Os módulos `Meals` e `Workforce` já estão em um estágio melhor do que um CRUD simples: há resolução de contexto operacional, lock transacional em pontos críticos, leitura de árvore/event-role, role/member settings e proteções para consumo por refeição. Mesmo assim, encontrei **cinco grupos de risco estruturais**:

1. **Drift de banco e migrations ainda é o principal problema sistêmico.** O código já opera assumindo estruturas que não estão coerentemente refletidas no log de migrations aplicadas e ainda existe duplicidade de numeração de migration.
2. **Há comportamento silenciosamente degradado por feature detection de schema.** O sistema muda de semântica conforme tabela/coluna existe ou não, sobretudo em Meals.
3. **O modelo de assignment do Workforce continua sujeito a sobrescrita silenciosa.** A identidade prática do assignment ainda é `participant_id + sector`, o que conflita com turnos, árvore por evento e cenários de multi-assignment.
4. **O fluxo de QR externo do Meals tem inconsistência semântica.** O parâmetro `valid_days` vira `max_shifts_event`, mas não há enforcement de validade por dia no próprio Meals.
5. **Existem riscos de contrato/performance em consumidores públicos e internos.** Alguns fluxos dependem de `qr_token` sem índice/unicidade em `event_participants`, e outros consumidores leem contratos parcialmente divergentes conforme readiness do schema.

---

## Mapa do domínio auditado

## 1. Backend principal

### Meals
- `backend/src/Controllers/MealController.php`
  - `GET /meals/balance`
  - `GET /meals/services`
  - `GET /meals`
  - `POST /meals`
  - `POST /meals/standalone-qrs`
  - `POST /meals/external-qr`
  - `PUT /meals/services`
- `backend/src/Services/MealsDomainService.php`
  - resolve contexto operacional;
  - resolve serviço de refeição por janela/ID/código;
  - aplica trava por participante/dia;
  - resolve baseline de `meals_per_day` via member settings / event roles / role settings;
  - persiste `participant_meals`.

### Workforce
- `backend/src/Controllers/WorkforceController.php`
  - roles, event-roles, assignments, tree status, tree backfill/sanitize, member settings, role settings, imports.
- `backend/src/Helpers/WorkforceEventRoleHelper.php`
  - compõe SQL de configuração operacional e readiness da árvore por evento.

## 2. Banco e baseline

Arquivos críticos:
- `database/schema_current.sql` → baseline canônico atual;
- `database/schema_real.sql` → histórico legado, explicitamente fora do papel de baseline;
- migrations `003` a `020` com impacto direto em Workforce/Meals;
- `database/migrations_applied.log` → registro operacional manual versionado.

## 3. Consumidores e integrações dependentes

### Consumidores internos
- `frontend/src/pages/MealsControl.jsx`
- `frontend/src/pages/ParticipantsTabs/WorkforceOpsTab.jsx`
- `frontend/src/pages/ParticipantsTabs/WorkforceRoleSettingsModal.jsx`
- `frontend/src/pages/ParticipantsTabs/WorkforceMemberSettingsModal.jsx`
- `backend/src/Controllers/ScannerController.php`
- `backend/src/Controllers/OrganizerFinanceController.php`
- `backend/src/Controllers/GuestController.php`
- `backend/src/Controllers/ParticipantCheckinController.php`

### Diagnóstico de dependência
- `Meals` consome `workforce_assignments`, `workforce_member_settings`, `workforce_role_settings` e opcionalmente `workforce_event_roles`.
- `Workforce` alimenta scanner, ticket público da equipe, financeiro operacional e fallback do Meals Control.
- O acoplamento real é alto: qualquer drift em `assignments`, `member_settings`, `role_settings`, `event_roles` ou `event_days/event_shifts` muda semântica de vários consumidores ao mesmo tempo.

---

## Achados prioritários

## Achado A — Drift de migrations continua crítico
**Severidade:** Crítica

### Evidências
- Existem **duas migrations com prefixo `012`**:
  - `012_event_meal_services_model.sql`
  - `012_meal_services_redesign.sql`
- O log versionado de migrations aplicadas registra apenas:
  - `011_participant_meals_hardening.sql`
  - `012_meal_services_redesign.sql`
  - `013_participants_workforce_integrity.sql`
- Ao mesmo tempo, o `schema_current.sql` já contém estrutura de fases posteriores, incluindo:
  - `workforce_event_roles`;
  - colunas `public_id`, `event_role_id`, `root_manager_event_role_id` em `workforce_assignments`;
  - endurecimentos adicionais de `participant_meals`.

### Impacto
Esse cenário cria quatro riscos operacionais:
1. **reaplicação errada** em ambientes novos ou restaurações;
2. **falsa sensação de baseline convergente**;
3. **diferença entre dump canônico e trilha operacional**;
4. **debug difícil**, porque o código tenta ser compatível com múltiplos estados de schema ao mesmo tempo.

### Sintoma provável em produção
- endpoints funcionando em um ambiente e degradando silenciosamente em outro;
- migrations “idempotentes” mascarando gaps reais;
- troubleshooting baseado em `schema_current.sql` enquanto o log operacional conta outra história.

### Solução recomendada
- eliminar a duplicidade de prefixo `012` e deixar **uma única trilha histórica oficial**;
- registrar explicitamente no log as migrations já materializadas no `schema_current.sql` ou adotar `schema_migrations` real no banco;
- bloquear deploy se existir divergência entre baseline canônico, dump gerado e trilha operacional.

---

## Achado B — Meals ainda opera com semântica variável conforme o schema disponível
**Severidade:** Alta

### Evidências
O domínio usa `tableExists`/`columnExists` em pontos críticos para alterar comportamento:
- leitura de `balance` depende de `workforce_role_settings`, `workforce_member_settings` e `organizer_financial_settings.meal_unit_cost`;
- `registerOperationalMealByReference()` só grava `meal_service_id`, `unit_cost_applied` e `offline_request_id` se as colunas existirem;
- joins em `workforce_event_roles` só entram se o readiness estiver completo.

### Impacto
Isso evita crash, mas cria **degradação silenciosa**:
- um ambiente pode registrar refeição sem granularidade por serviço;
- outro pode perder idempotência offline;
- outro pode calcular custo sem `meal_unit_cost` e seguir respondendo “normalmente”.

### Por que é perigoso
O write-path crítico do Meals passa a ser **schema-dependent em runtime**, em vez de ser claramente bloqueado quando o ambiente está incompleto.

### Solução recomendada
- manter feature detection apenas em **read-path legado**;
- em **write-path** (`POST /meals`, atualização de services, QR externo), mudar para **fail-fast com readiness explícito**;
- criar uma checagem única de readiness de Meals e expor isso em healthcheck/CI.

---

## Achado C — Identidade do assignment no Workforce ainda é frágil e causa sobrescrita silenciosa
**Severidade:** Alta

### Evidências
- O `schema_current.sql` mantém `UNIQUE (participant_id, sector)` em `workforce_assignments`.
- `createAssignment()` procura assignment existente por `participant_id + sector` e, se existir, faz `UPDATE`, inclusive trocando:
  - `role_id`
  - `event_shift_id`
  - `manager_user_id`
  - `event_role_id`
  - `root_manager_event_role_id`
- Esse comportamento continua presente mesmo após a introdução de árvore por evento e vínculo por turno.

### Impacto
Isso cria um bug silencioso importante:
- um membro com múltiplos turnos no mesmo setor não consegue ter identidade estável por assignment;
- uma nova alocação pode **sobrescrever** a anterior em vez de criar nova linha;
- histórico operacional, custos, liderança e fallback do Meals passam a refletir “o assignment mais novo”, não necessariamente “o assignment correto”.

### Efeito colateral nos consumidores
- `GET /workforce/assignments` pode parecer “coerente”, mas na prática já devolve linha consolidada/sobrescrita;
- `GET /meals/balance` e `POST /meals` passam a depender de um recorte de assignment que pode ter sido regravado;
- scanner, financeiro e ticket público da equipe herdam esse contexto alterado.

### Solução recomendada
Escolher uma das duas estratégias e fechar semanticamente o domínio:

#### Opção 1 — assignment é histórico/versionado
Adicionar:
- `valid_from`
- `valid_to`
- `change_reason`
- `changed_by`
- unicidade operacional por janela (`participant_id + event_shift_id + event_role_id`, por exemplo, conforme regra final)

#### Opção 2 — assignment é snapshot único por posição operacional
Se esse for o desenho desejado, então:
- remover ambiguidade do frontend e dos relatórios;
- formalizar que não existe histórico nativo;
- versionar em tabela paralela de auditoria antes de cada update.

Hoje o sistema está no pior ponto intermediário: **parece aceitar multi-assignment, mas a identidade persistida continua estreita demais**.

---

## Achado D — `valid_days` do QR externo não é validade real de Meals
**Severidade:** Alta

### Evidências
- `POST /meals/external-qr` recebe `valid_days`.
- Esse valor é persistido como `max_shifts_event` em `workforce_member_settings`.
- O fluxo de registro de refeição usa `meals_per_day`, serviço, turno e dia operacional, mas **não faz enforcement de expiração por número de dias consumidos**.
- `max_shifts_event` é fortemente reutilizado por check-in/presença e projeção financeira, não como validade calendárica do QR no Meals.

### Impacto
O contrato da API/UI sugere “válido por X dias”, mas a implementação efetiva hoje significa algo mais próximo de:
- limite de turnos/check-ins em alguns consumidores;
- parâmetro exibido na UI;
- dado de configuração reaproveitado, não uma validade robusta por dia operacional.

Em outras palavras: há risco de **promessa funcional divergente da execução real**.

### Solução recomendada
Para QR externo, separar conceitos:
- `valid_days` ou `valid_until_date` para elegibilidade calendárica do Meals;
- `max_shifts_event` para presença/check-in;
- `meals_per_day` para cota diária.

Se quiser preservar compatibilidade:
1. manter `max_shifts_event` como legado;
2. criar colunas novas (`valid_from`, `valid_until`, `allowed_days_count` ou tabela própria de external_meal_qrs`);
3. endurecer `registerMeal()` para rejeitar consumo fora da janela autorizada.

---

## Achado E — Leitura pública da equipe depende de `qr_token` sem unicidade/index em `event_participants`
**Severidade:** Média/Alta

### Evidências
- O fluxo público de `GuestController` busca `event_participants` por `ep.qr_token = ? LIMIT 1`.
- O `schema_current.sql` mostra índice/unique para `tickets.qr_token`, `guests.qr_code_token` e `parking_records.qr_token`, mas **não** para `event_participants.qr_token`.

### Impacto
Riscos combinados:
1. **performance** pior em lookup público/scanner conforme a base cresce;
2. **colisão lógica** caso haja duplicidade rara de token em import/migração/correção manual;
3. comportamento público não determinístico em caso de duplicidade, pois há `LIMIT 1` sem blindagem de unicidade.

### Solução recomendada
- criar índice em `event_participants(qr_token)`;
- idealmente criar unicidade se a regra de negócio permitir;
- antes disso, auditar duplicidades existentes e regenerar tokens inválidos.

---

## Achado F — Scanner, Financeiro e Guest dependem fortemente de readiness híbrido do Workforce
**Severidade:** Média

### Evidências
Consumidores relevantes:
- `ScannerController` resolve participante usando assignment preferencial + config operacional;
- `OrganizerFinanceController` combina `workforce_assignments`, `workforce_member_settings` e opcionalmente `workforce_event_roles`;
- `GuestController` monta ticket público da equipe com role/sector/meals/payment/source.

Todos esses fluxos dependem de combinações parciais de:
- `workforce_assignments`
- `workforce_member_settings`
- `workforce_role_settings`
- `workforce_event_roles`

### Impacto
Quando o schema ou o backfill estrutural está incompleto:
- scanner pode operar, mas com source/config diferente do esperado;
- financeiro pode projetar com base parcial;
- ticket público pode expor role/sector/settings_source diferentes do que a operação imagina.

### Diagnóstico
Não é um bug pontual: é uma **dependência transversal ainda pouco explicitada**. O sistema já tenta ser resiliente, mas o contrato entre Workforce estrutural, Workforce legado e Meals/Scanner/Finance ainda não está completamente fechado.

### Solução recomendada
- publicar um **contrato de readiness unificado** para Workforce:
  - `assignments_ready`
  - `member_settings_ready`
  - `role_settings_ready`
  - `event_roles_ready`
  - `tree_binding_backfilled`
- usar esse contrato para colorir UI, healthcheck, troubleshooting e bloqueio seletivo de features.

---

## Banco de dados — diagnóstico objetivo

## 1. O que está bom
- `participant_meals` já recebeu endurecimento relevante;
- `workforce_assignments` já possui colunas de binding estrutural (`public_id`, `event_role_id`, `root_manager_event_role_id`) no baseline canônico;
- `workforce_member_settings` e `workforce_role_settings` existem no baseline atual;
- há FKs e índices úteis para boa parte da operação.

## 2. O que ainda está inconsistente
- duplicidade histórica de migration `012`;
- `migrations_applied.log` não reflete todo o estado materializado no `schema_current.sql`;
- `schema_real.sql` ainda existe e continua aparecendo em documentação histórica, o que confunde troubleshooting quando alguém olha o arquivo errado;
- falta blindagem formal de lookup/uniquidade para `event_participants.qr_token`.

## 3. O que eu faria agora
1. Congelar `schema_current.sql` como única fonte oficial para incidentes presentes.
2. Resolver a trilha de migrations antes de abrir novas features em Meals/Workforce.
3. Criar migration corretiva para QR token de `event_participants`.
4. Revisar o modelo de unicidade de `workforce_assignments` antes de expandir mais o uso de event roles.

---

## Bugs silenciosos catalogados

### 1. Sobrescrita silenciosa de assignment
Novo vínculo no mesmo setor tende a atualizar linha existente em vez de preservar histórico/contexto.

### 2. Degradação silenciosa por schema incompleto
O sistema responde com sucesso, porém com semântica diferente conforme colunas/tabelas existam.

### 3. `valid_days` com semântica enganosa
Contrato de API/UI sugere validade por dias; implementação usa `max_shifts_event`.

### 4. Lookup público por token sem blindagem estrutural suficiente
Risco de lookup não determinístico/performance ruim em `event_participants`.

---

## Integrações e consumidores — mapa de impacto

## Meals consome Workforce
- baseline de cota (`meals_per_day`);
- elegibilidade por assignment no recorte de dia/turno/setor;
- event role quando árvore por evento está pronta.

## Workforce abastece
- scanner operacional;
- ticket público da equipe;
- projeção financeira do organizador;
- fallback da tela Meals Control quando ainda não há saldo diário robusto.

## Consequência prática
`Meals` e `Workforce` não são módulos independentes. Hoje eles formam um **subdomínio operacional único** com quatro eixos compartilhados:
- assignment;
- presença/turno;
- cota de refeição;
- projeção/custo.

Por isso, drift de banco ou semântica incompleta em Workforce invariavelmente reaparece em Meals.

---

## Plano de correção recomendado

## Fase 0 — estabilização imediata
1. Resolver duplicidade da migration `012`.
2. Formalizar qual é a trilha real de migrations aplicadas.
3. Criar índice em `event_participants.qr_token` e auditar duplicidades.
4. Documentar `valid_days` como comportamento legado até a correção estrutural.

## Fase 1 — fechamento semântico
1. Definir identidade oficial de `workforce_assignments`.
2. Decidir se assignment é histórico/versionado ou snapshot único.
3. Separar validade calendárica de QR externo de `max_shifts_event`.
4. Mover write-path de Meals para readiness explícito/fail-fast.

## Fase 2 — observabilidade e saúde operacional
1. Criar readiness consolidado de Workforce/Meals.
2. Expor healthcheck com detalhes de schema + backfill estrutural.
3. Adicionar auditoria automática para:
   - assignments sobrescritos;
   - QR duplicado;
   - participantes com config ambígua;
   - ambientes com schema parcial.

---

## Priorização executiva

### Corrigir nesta semana
- duplicidade de migration `012`;
- trilha de migrations/log operacional;
- índice/diagnóstico de `event_participants.qr_token`;
- documentação honesta de `valid_days`.

### Corrigir no próximo ciclo
- redesenho da identidade de assignment;
- readiness fail-fast de Meals write-path;
- validade real de QR externo.

### Monitorar continuamente
- ambientes rodando com schema parcial;
- divergência entre tree/event-role e assignments legados;
- consumidores que ainda dependem de fallback silencioso.

---

## Conclusão

O maior risco atual de `Meals` e `Workforce` não é um único endpoint quebrado; é a combinação de:
- **schema drift**,
- **contratos semânticos ainda híbridos**,
- **assignment identity frágil**,
- **fallbacks silenciosos em runtime**.

A boa notícia é que a base já contém vários componentes corretos: locks, FKs, event roles, role/member settings e materialização do schema canônico. O próximo passo não é “reescrever tudo”; é **fechar semanticamente o que já existe**, remover ambiguidades de banco e tornar os caminhos críticos menos dependentes de compatibilidade implícita.
