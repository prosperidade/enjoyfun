## 0. Escopo oficial de hoje

- Frente ativa do dia: `Workforce audit` com fechamento dos achados criticos ainda abertos no codigo.
- Objetivo: transformar a auditoria de `Workforce / Participants Hub` em backlog executavel, separar o que ja foi fechado do que continua aberto e iniciar a correcao pela trilha mais critica e objetiva.
- Fontes desta rodada:
  - `auditoriaworkforce.md`
  - `docs/qa/aceite_workforce.md`
  - `docs/qa/workforce_incident_playbook.md`
  - leitura do estado atual do repositorio

---

## 1. Estado de partida

- A auditoria principal de `Workforce` descreve um escopo mais amplo do que a frente que ja fechamos no modulo.
- Do bloco especificamente operacional de `Workforce`, ja existe entrega concreta no repositorio:
  - runner versionado de contratos HTTP
  - telemetria minima via `audit_log`
  - `GET /health/workforce`
  - playbook de incidente
  - UI com diagnostico avancado isolado do fluxo comum do organizador
  - filtro para nao contaminar a saude com trafego sintetico legado

- Ao mesmo tempo, os achados estruturais e de seguranca da auditoria ainda permanecem majoritariamente abertos:
  - segredo de banco hardcoded
  - segredos sensiveis em texto claro
  - OTP persistido em claro
  - falso sucesso em OTP
  - `POST /sync` parcial ainda com `success=true`
  - mensageria sem historico real
  - check-in/check-out sem idempotencia/concorrencia robusta
  - IA com provider/SSL inconsistentes

---

## 2. O que da auditoria ja foi efetivamente fechado

### Bloco concluido

- `Workforce`:
  - contratos minimos de `tree-status`, `roles`, `event-roles`, `managers`, `assignments`
  - validacao rastreavel de `POST /sync`
  - ciclo efemero de `create -> delete participant`

- observabilidade operacional:
  - telemetria backend por endpoint critico
  - sinais de snapshot offline no frontend
  - endpoint `GET /health/workforce`
  - exclusao de trafego sintetico do runner na leitura de saude

- operacao e incidente:
  - `docs/qa/workforce_incident_playbook.md`
  - diagnostico avancado isolado da operacao comum do organizador

### Observacao importante

- Esse fechamento nao encerra a auditoria inteira de `auditoriaworkforce.md`.
- Ele encerra apenas a frente operacional de `Workforce` que dependia de contrato, health, playbook e UI.

---

## 3. O que continua aberto apos o fechamento operacional

### P0 — Critico

- remover segredo hardcoded do banco no backend e nos scripts CLI
- parar de manter segredos sensiveis em texto claro no banco
- hash de OTP e endurecimento do fluxo de validacao
- impedir falso sucesso no envio de OTP
- revisar o contrato de `POST /sync` parcial
- remover `CURLOPT_SSL_VERIFYPEER=false` e corrigir o eixo real de provider na IA

### P1 — Alto

- idempotencia e transacao de `check-in/check-out`
- historico real e webhook util para mensageria
- trilha operacional de integracoes externas
- estrategia canonica para `Participants Hub`

### P2 — Medio

- remover dependencia de `$GLOBALS['year']` no template OTP
- versionamento/historico de `workforce_assignments`
- dashboards de reconciliacao mais amplos

---

## 4. Ordem da rodada

1. segredos e bootstrap de banco
2. OTP e mensageria silenciosa
3. `sync` parcial e reconciliação offline
4. check-in/check-out idempotente
5. consolidacao documental do que ficou concluido e do backlog remanescente

---

## 5. Primeira etapa iniciada agora

### Etapa 1 — Segredo hardcoded do banco

Escopo imediato desta etapa:

- `backend/config/Database.php`
- `backend/scripts/audit_meals.php`
- `backend/scripts/sync_event_operational_calendar.php`

Objetivo:

- remover defaults sensiveis do codigo
- exigir `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS`
- falhar com mensagem operacional controlada quando a configuracao estiver ausente

Status desta etapa:

- concluida no codigo versionado nesta rodada

---

## 6. Criterio de aceite da etapa atual

- nenhum arquivo versionado permanece com senha hardcoded do banco
- backend continua lendo `DB_*` do ambiente local
- `php -l` passa nos arquivos alterados
- scripts CLI continuam funcionando com `backend/.env`

---

## 7. Execucao realizada hoje

- `docs/progresso9.md`
  - nota da rodada criada com consolidacao da auditoria de `Workforce`
  - backlog separado entre fechado, aberto e ordem real de execucao

- `backend/config/Database.php`
  - removidos defaults hardcoded de host, banco, usuario e senha
  - bootstrap passou a carregar `backend/.env` diretamente quando necessario
  - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` passaram a ser obrigatorios
  - erro de bootstrap passou a falhar com mensagem operacional controlada, sem expor segredo ao cliente

- `backend/scripts/audit_meals.php`
  - removidos defaults hardcoded de conexao
  - script agora exige `DB_*` do ambiente carregado via `backend/.env`

- `backend/scripts/sync_event_operational_calendar.php`
  - removidos defaults hardcoded de conexao
  - script agora exige `DB_*` do ambiente carregado via `backend/.env`

- `backend/src/Controllers/AuthController.php`
  - OTP passou a ser persistido em hash HMAC em vez de texto claro
  - validacao do OTP passou a aceitar leitura compatível com registros legados ainda em claro
  - falha de provider deixou de responder falso sucesso; o endpoint agora retorna erro recuperavel
  - fallback mock de OTP ficou restrito a `APP_ENV=development`
  - resposta de envio passou a expor `channel` e `delivery_status`

- `backend/src/Services/EmailService.php`
  - removida dependencia de `$GLOBALS['year']` no template OTP
  - envio manual tambem passou a calcular o ano localmente

- `backend/src/Controllers/MessagingController.php`
  - removido acoplamento residual com `$GLOBALS['year']` no fluxo de envio manual

- `backend/src/Controllers/SyncController.php`
  - resposta parcial do `/sync` passou a expor `status = partial_failure`
  - resposta de sucesso completo passou a expor `status = success`

- `frontend/src/hooks/useNetwork.js`
  - a fila offline passou a reconhecer `status = partial_failure` explicitamente

- `frontend/src/pages/MealsControl.jsx`
  - sincronizacao offline de `Meals` passou a reconhecer `status = partial_failure` explicitamente

- `backend/src/Helpers/ParticipantPresenceHelper.php`
  - novo write-path compartilhado para presenca de participantes
  - check-in/check-out passou a usar `pg_advisory_xact_lock` por participante
  - acao repetida fora de ordem passou a falhar de forma explicita
  - reconciliacao de `event_participants.status` passou a ser derivada da ultima acao gravada

- `backend/src/Controllers/ParticipantCheckinController.php`
  - fluxo manual passou a rodar em transacao
  - busca do participante ficou travada com `FOR UPDATE`
  - `check-out` passou a devolver o participante para `expected`

- `backend/src/Controllers/ScannerController.php`
  - scanner de equipe passou a reaproveitar o mesmo write-path transacional
  - validacao duplicada deixou de depender de leitura solta antes do insert

- `backend/src/Services/DashboardDomainService.php`
  - KPI de presenca deixou de considerar qualquer `check-in` historico como “presente agora”
  - presenca passou a derivar da ultima acao registrada, com fallback legado para `status = present`

- `backend/src/Services/AnalyticalDashboardService.php`
  - painel analitico de presenca passou a usar a ultima acao do participante

- `backend/src/Controllers/OrganizerFinanceController.php`
  - custo operacional por setor deixou de tratar `check-in` antigo como presenca atual permanente

- `backend/src/Services/MealsDomainService.php`
  - `consumed_at` com `Z`/offset deixou de ser aceito silenciosamente sem contexto de timezone
  - o backend passou a exigir `operational_timezone` explicita para converter payload com offset
  - quando a timezone operacional e fornecida, a normalizacao passou a usar `DateTimeImmutable`
  - o dominio passou a resolver `event_timezone` direto do evento antes de normalizar `consumed_at`
  - payloads com `operational_timezone` divergente da timezone canonica do evento passaram a falhar explicitamente

- `backend/src/Controllers/MealController.php`
  - rota de baixa passou a aceitar `operational_timezone` opcional no payload

- `backend/src/Controllers/SyncController.php`
  - sincronizacao offline de Meals passou a repassar `operational_timezone` quando o payload trouxer esse campo

- `database/020_events_event_timezone.sql`
  - migration versionada criada para introduzir `events.event_timezone` como fonte canonica da timezone operacional
  - valores em branco legados passaram a ser normalizados para `NULL` na virada do schema

- `backend/src/Controllers/EventController.php`
  - `list/details/create/update` passaram a ler e persistir `event_timezone` quando o schema suportar a coluna
  - `starts_at` e `ends_at` passaram a ser normalizados com conversao segura quando chegarem em ISO 8601 com offset
  - create/update passaram a exigir timezone IANA explicita no write-path novo quando a coluna existir

- `frontend/src/pages/Events.jsx`
  - formulario de evento passou a capturar `event_timezone` explicita do dominio
  - novos eventos passam a sugerir a timezone do navegador apenas como preenchimento inicial

- `backend/src/Controllers/AIController.php`
  - removido bypass inseguro de TLS na chamada OpenAI
  - erro de certificado passou a falhar explicitamente
  - billing passou a ser associado ao `event_id` e setor reais vindos do contexto analitico

- `backend/src/Services/SalesReportService.php`
  - contexto analitico passou a incluir `event_id` e `organizer_id` para fechar telemetria da IA

- `backend/src/Services/GeminiService.php`
  - billing residual deixou de gravar `event_id = 1` fixo
  - assinatura passou a aceitar contexto opcional real de evento/organizador/usuario

- `database/018_messaging_outbox_and_history.sql`
  - migration versionada criada para historico real de mensageria e captura de webhook

- `backend/src/Services/MessagingDeliveryService.php`
  - trilha persistente de disparos criada com `message_deliveries`
  - webhook passou a ser persistido em `messaging_webhook_events`
  - correlacao por `provider_message_id` e telefone passou a permitir atualizacao de status

- `backend/src/Controllers/MessagingController.php`
  - `/messaging/history` deixou de retornar array vazio e passou a ler historico real
  - envios manuais e bulk passaram a gravar `queued -> sent/failed`
  - webhook deixou de ser placeholder e passou a persistir evento recebido
  - envio manual de e-mail deixou de expor erro bruto do provedor ao cliente

- `backend/src/Services/SecretCryptoService.php`
  - servico versionado criado para cifrar e decifrar segredos sensiveis fora do banco
  - chave passou a sair de segredo de ambiente em vez de fallback hardcoded

- `backend/src/Services/OrganizerMessagingConfigService.php`
  - leitura e escrita de `organizer_settings` passaram a ser centralizadas com cifragem transparente de `resend_api_key`, `wa_api_url`, `wa_token` e `wa_instance`
  - leitura legada em claro passou a ser aceita e regravada cifrada no primeiro acesso

- `backend/src/Controllers/OrganizerMessagingSettingsController.php`
  - configuracao oficial de canais deixou de devolver segredos reais ao frontend
  - persistencia passou a gravar apenas valores cifrados para os campos sensiveis

- `backend/src/Controllers/AuthController.php`
  - OTP passou a consumir credenciais de mensageria descriptografadas via servico central

- `backend/src/Controllers/AIController.php`
  - `/ai/insight` passou a respeitar `organizer_ai_config.provider` entre `openai` e `gemini`
  - `system_prompt` do organizador passou a compor o prompt efetivo do insight
  - resposta passou a expor `provider` e `model` reais usados na inferencia

- `backend/src/Controllers/OrganizerAIConfigController.php`
  - default e normalizacao de provider passaram a ser coerentes com o contrato real do backend
  - leitura/escrita passou a escolher a configuracao mais recente por `organizer_id`

- `database/019_participant_checkins_presence_hardening.sql`
  - migration versionada criada para endurecer `participant_checkins` com `event_day_id`, `event_shift_id`, `source_channel`, `operator_user_id` e `idempotency_key`
  - indice dedicado da ultima acao por participante e indice operacional por turno passaram a ficar versionados

- `backend/src/Helpers/ParticipantPresenceHelper.php`
  - write-path de presenca passou a detectar schema expandido de forma compatível
  - check-in/check-out passou a persistir `event_day_id` e `event_shift_id` quando houver janela operacional resolvida
  - idempotencia passou a ser persistida por chave deterministica quando houver turno resolvido

- `backend/src/Controllers/ParticipantCheckinController.php`
  - fluxo manual passou a carregar a janela operacional preferencial do assignment antes de gravar presenca
  - endpoint passou a aceitar `idempotency_key` opcional e devolver `event_day_id` e `event_shift_id` resolvidos

- `backend/src/Controllers/ScannerController.php`
  - scanner de equipe passou a gravar presenca com contexto operacional de turno e canal de origem

- `frontend/src/pages/SettingsTabs/AIConfigTab.jsx`
  - default visual do provider passou a refletir o contrato atual do backend

## 8. Validacao da etapa atual

- `php -l backend/config/Database.php` passou
- `php -l backend/scripts/audit_meals.php` passou
- `php -l backend/scripts/sync_event_operational_calendar.php` passou
- `php -l backend/src/Controllers/AuthController.php` passou
- `php -l backend/src/Services/EmailService.php` passou
- `php -l backend/src/Controllers/MessagingController.php` passou
- `php -l backend/src/Controllers/SyncController.php` passou
- `php -l backend/src/Helpers/ParticipantPresenceHelper.php` passou
- `php -l backend/src/Controllers/ParticipantCheckinController.php` passou
- `php -l backend/src/Controllers/ScannerController.php` passou
- `php -l backend/src/Services/DashboardDomainService.php` passou
- `php -l backend/src/Services/AnalyticalDashboardService.php` passou
- `php -l backend/src/Controllers/OrganizerFinanceController.php` passou
- `php -l backend/src/Services/MealsDomainService.php` passou
- `php -l backend/src/Controllers/MealController.php` passou
- `php -l backend/src/Controllers/SyncController.php` passou
- `php -l backend/src/Controllers/AIController.php` passou
- `php -l backend/src/Services/SecretCryptoService.php` passou
- `php -l backend/src/Services/OrganizerMessagingConfigService.php` passou
- `php -l backend/src/Services/SalesReportService.php` passou
- `php -l backend/src/Services/GeminiService.php` passou
- `php -l backend/src/Services/MessagingDeliveryService.php` passou
- `php -l backend/src/Controllers/MessagingController.php` passou
- `php -l backend/src/Controllers/OrganizerMessagingSettingsController.php` passou
- `php -l backend/src/Controllers/OrganizerAIConfigController.php` passou
- `php -l backend/src/Helpers/ParticipantPresenceHelper.php` passou
- `php -l backend/src/Controllers/ParticipantCheckinController.php` passou
- `php -l backend/src/Controllers/ScannerController.php` passou
- `php -l backend/src/Controllers/EventController.php` passou
- `php -l backend/src/Controllers/WorkforceController.php` passou
- busca por `070998` em `backend/` nao retornou ocorrencias
- teste local dos helpers de OTP confirmou hash de `64` caracteres e comparacao `match`
- `npm --prefix frontend run build` passou

Limitacao local desta rodada:

- a validacao de conexao real via PHP CLI ficou bloqueada porque este runtime nao possui `pdo_pgsql`
- o proprio `audit_meals.php` confirmou o mesmo bloqueio de extensao
- as migrations `021` a `024` foram aplicadas no banco real via `database/apply_migration.bat` e registradas em `database/migrations_applied.log`
- a smoke SQL de fechamento confirmou:
  - remocao de `uq_workforce_assignments_participant_sector`
  - presenca dos indices `uq_workforce_assignments_identity_shifted`, `uq_workforce_assignments_identity_unshifted` e `uq_event_participants_qr_token`
  - zero duplicidades atuais de `event_participants.qr_token`
  - `10` linhas com janela externa materializada em `workforce_member_settings`

---

## 9. Estado apos esta passada

### Registro de direcao fora da frente atual

- A direcao oficial de IA multiagentes foi congelada em `docs/adr_ai_multiagentes_strategy_v1.md`.
- A decisao separa explicitamente duas camadas:
  - uma UI propria de agentes, onde o organizador escolhe provider/API e quais agentes quer rodar
  - um bot contextual nativo da EnjoyFun, embutido nas interfaces operacionais para leitura de dados e suporte guiado
- Essa direcao foi registrada agora para nao se perder, mas continua fora do escopo executavel desta rodada.

### Fechado nesta rodada

- segredo hardcoded do banco no backend versionado
- hash de OTP
- fim do falso sucesso no envio de OTP
- fim da dependencia de `$GLOBALS['year']` no fluxo OTP/manual
- status explicito de falha parcial em `/sync` sem quebrar consumidores atuais
- serializacao transacional de `check-in/check-out` no backend atual
- reconciliacao de presenca atual por ultima acao gravada em vez de `check-in` historico solto
- fim da aceitacao silenciosa de `consumed_at` com offset sem contexto operacional de timezone
- fim do bypass TLS no proxy OpenAI e fim do `event_id = 1` fixo no billing residual de IA
- historico real de mensageria e captura minima de webhook no backend atual
- cifragem transparente de `resend_api_key`, `wa_api_url`, `wa_token` e `wa_instance` em `organizer_settings`
- leitura legada de credenciais de mensageria em claro com regravacao cifrada no primeiro acesso
- `/organizer-messaging-settings` e `/messaging/config` sem retorno de segredo bruto ao frontend
- `/ai/insight` alinhado ao `organizer_ai_config` com provider real (`openai` ou `gemini`) e `system_prompt` do organizador
- default de provider na configuracao de IA alinhado com o contrato atual do backend
- endurecimento residual de presenca com janela operacional e idempotencia persistida em `participant_checkins`
- indice dedicado para leitura da ultima acao de presenca por participante
- timezone canonica de Meals ancorada em `events.event_timezone`
- write-path de eventos passou a normalizar timestamps com offset para o calendario operacional do proprio evento
- trilha de migrations de Meals linearizada com a renumeracao da antiga `012_event_meal_services_model.sql` para `021_event_meal_services_alignment.sql`
- identidade de `workforce_assignments` endurecida para o eixo `participant + role + sector + shift`, encerrando a sobrescrita silenciosa por `participant + sector`
- `event_participants.qr_token` endurecido com unicidade/index dedicado para lookup publico e scanner
- `valid_days` do QR externo passou a gerar janela calendárica explicita em `workforce_member_settings`, sem depender apenas de `max_shifts_event`
- write-path critico de `Meals` agora falha explicitamente quando o schema obrigatorio de servico/idempotencia/janela externa nao estiver materializado

### Pendencias postergadas

- mensageria saiu da frente ativa nesta fase; o residual fica congelado como pendencia transversal ate reabertura explicita
- backfill explicito dos segredos legados ja persistidos em claro fora do fluxo quente
- assinatura/validacao forte de webhook por provider de mensageria
- retry administrativo e replay de falhas de mensageria

### Proxima etapa recomendada

- iniciar a nova rodada pelo dashboard
  - revisar indicadores, consultas, alertas e telemetria a partir da superficie operacional principal
  - seguir a partir dali corrigindo e melhorando os modulos encadeados pelo uso real

---

## 10. Rodada POS / Bar / Food / Shop / Cartao Digital

### Escopo desta passada

- base de confronto: `auditoriaPOS.md`
- objetivo: endurecer o POS para volume alto de cartoes, reduzir falha silenciosa em relatorios/catalogo, estabilizar o fluxo offline e fechar a ambiguidade do checkout cashless

### Fechado nesta passada

- a camada de relatorios do POS deixou de resetar a experiencia visual a cada troca de aba:
  - ultimo snapshot valido passou a ser mantido durante refresh
  - estados `loading`, `error` e `stale` ficaram explicitos
  - o polling ficou restrito a aba de relatorios e pagina visivel
  - a aba de relatorios passou a permanecer montada apos a primeira abertura
- erros operacionais de catalogo e contexto de evento deixaram de ficar invisiveis no frontend do POS
- o contrato de checkout cashless foi canonizado para `card_id`:
  - `bar`, `food` e `shop` deixaram de cobrar por aliases ambiguos no hot path
  - o frontend resolve referencia escaneada para `card_id` antes do checkout online
  - o offline novo aceita apenas `card_id` canonico ja resolvido
- foi criado endpoint explicito de resolucao de cartao para compatibilidade controlada:
  - `POST /cards/resolve` converte referencia legada para `card_id` canonico
  - `Cards.jsx` passou a operar com `card_id` como identificador principal
- a fila offline do POS foi endurecida:
  - novos registros recebem metadados de versao e tipo de referencia do cartao
  - a compatibilidade legada ficou isolada na migracao da fila antiga
  - o reconcile backend continua convertendo referencias legadas antigas apenas no momento da sincronizacao
- o motor de carteira foi reforcado para trilha e auditoria:
  - debito e recarga passaram a compartilhar o mesmo servico transacional
  - `card_transactions` passa a receber contexto util de auditoria quando o schema suporta isso (`event_id`, `sale_id`, `offline_id`, `user_id`, `payment_method`, `is_offline`)
  - o checkout anexa a transacao cashless a venda criada, melhorando reconcile e investigacao posterior
- cada terminal/browser passou a enviar `X-Device-ID` estavel, encerrando a trilha generica `browser_pos` no sync offline
- a IA operacional do POS foi endurecida nesta frente:
  - `/ai/insight` passou a aceitar o conjunto real de roles do POS
  - o proxy ganhou resolucao de `CA bundle` e mensagem operacional mais clara para falha TLS por certificado/relogio do ambiente
- foi criada a migration `database/025_cashless_offline_hardening.sql` e o baseline `database/schema_current.sql` foi alinhado com:
  - constraints de saldo/transacao cashless
  - constraints de status/tipo da `offline_queue`
  - indices para `card_transactions`, `digital_cards`, `offline_queue` e `sales`

### Validacoes executadas

- `php -l backend/src/Controllers/AIController.php` passou
- `php -l backend/src/Services/WalletSecurityService.php` passou
- `php -l backend/src/Services/SalesDomainService.php` passou
- `php -l backend/src/Controllers/CardController.php` passou
- `php -l backend/src/Controllers/BarController.php` passou
- `php -l backend/src/Controllers/FoodController.php` passou
- `php -l backend/src/Controllers/ShopController.php` passou
- `php -l backend/src/Controllers/SyncController.php` passou
- `npm --prefix frontend run build` passou

### Fechamento executado em 2026-03-20 para encerrar a frente do POS

1. `database/025_cashless_offline_hardening.sql` foi aplicada no banco real via `database/apply_migration.bat` e registrada em `database/migrations_applied.log`
2. foi executado smoke operacional com backend reiniciado em `http://localhost:8080/api`:
   - `POST /cards/resolve` `200`
   - recarga `200`
   - checkout online `200` com venda `245`
   - venda offline sincronizada por `POST /sync` `200` com venda `246`
   - trilha confirmada em `offline_queue` (`21071`) e `card_transactions` (`130`, `131`, `132`)
   - saldo conferido ponta a ponta: `3308.00 -> 3309.00 -> 3297.00 -> 3292.00`
3. `auditoriaPOS.md` foi atualizada para remover riscos que ja morreram e deixar apenas o residual real
4. o backend recebeu ajuste de CORS para aceitar `X-Device-ID` e `X-Operational-Test`, destravando o login/browser local em `localhost:3001`

### Fechamento complementar em 2026-03-20 para cartao por evento

- foi criada e aplicada a migration `database/026_event_scoped_card_assignments.sql`
- o backend passou a vincular emissao manual, listagem, extrato, recarga e resolucao de cartao ao `event_id`
- `WalletSecurityService` passou a rejeitar validacao e transacao cashless quando o cartao ativo nao pertence ao evento selecionado
- o POS passou a enviar `event_id` na resolucao do cartao e a exigir validacao online previa para offline no evento atual
- `Cards.jsx` passou a emitir e recarregar apenas com evento explicito
- smoke da API local confirmou o isolamento:
  - cartao emitido no evento `7` apareceu em `GET /cards?event_id=7`
  - o mesmo cartao nao apareceu em `GET /cards?event_id=1`
  - `POST /cards/resolve` no evento `7` retornou `200`
  - `POST /cards/resolve` no evento `1` retornou `404`
  - recarga no evento `7` retornou `200`
  - recarga no evento `1` retornou `404`

### Fechamento complementar em 2026-03-20 para cashless do cliente por evento

- foi criada e aplicada a migration `database/027_expand_otp_code_storage.sql` para ampliar `otp_codes.code` de `varchar(10)` para `varchar(128)` e destravar o OTP com hash
- `OrganizerMessagingConfigService` passou a ignorar placeholders como `***redacted***` e `(Configurado)` para nao tratar segredo mascarado como credencial real
- `AuthController` passou a aceitar autenticacao do cliente por `event_id` ou `event_slug`, resolvendo o `organizer_id` a partir do evento
- `CustomerController` foi refeito para expor contexto publico do evento e limitar `balance`, `transactions`, `tickets` e `recharge` ao `event_id`
- a UI do cliente (`/app/:slug`, dashboard e recarga) passou a resolver o evento pelo slug e enviar `event_id` explicitamente em toda chamada cashless
- smoke local validado com o mesmo cliente (`phone=11999992727`, `user_id=41`) em dois eventos do organizador `2`:
  - `POST /auth/request-code` no evento `1` retornou `200` com envio `mocked`
  - `POST /auth/verify-code` no evento `1` criou o cliente `41`
  - `GET /customer/balance?event_id=1` retornou `card_id=null` antes da recarga
  - `POST /customer/recharge` no evento `1` criou a carteira `ab4f963b-b868-470d-b100-5cd0131cbebf`
  - `GET /customer/transactions?event_id=1` retornou apenas a recarga pendente `id=140`
  - `GET /customer/balance?event_id=7` retornou `card_id=null` e `transactions=[]` antes da recarga do segundo evento
  - `POST /auth/request-code` e `POST /auth/verify-code` no evento `7` retornaram `200` reutilizando o mesmo cliente `41`
  - `POST /customer/recharge` no evento `7` criou a carteira `5e9d5b81-538e-4630-b8b6-dd03b94d2159`
  - `GET /customer/transactions?event_id=7` retornou apenas a recarga pendente `id=141`
  - `event_card_assignments` confirmou dois vinculos ativos para o mesmo cliente, um em `event_id=1` e outro em `event_id=7`, com `card_id` distintos

### Proxima etapa apos o fechamento do POS

- voltar para o plano ja definido de iniciar a nova rodada pelo dashboard
- deixar fora desta frente:
  - IA multiagentes e governanca forte de custo por tenant
  - consumidor real de outbox/mensageria
  - dominio explicito de franquias

### Registro separado — cartoes em massa no Workforce

- o desenho, o plano faseado de implementacao e o registro operacional desta frente foram desacoplados para `docs/cardsemassa.md`
- a primeira passada tecnica ficou ancorada na migration `database/028_workforce_bulk_card_issuance_foundation.sql`, preparando `event_card_assignments`, `card_issue_batches` e `card_issue_batch_items` sem alterar os contratos atuais de `/cards`, POS e customer cashless

---

## 11. Leitura consolidada da analise de 2026-03-22

### Documento-base usado nesta consolidacao

- `analise_enjoyfun_2026_03_22.md`
- `README.md`
- `CLAUDE.md`
- `docs/auditoriaPOS.md`

### O que continua valido no diagnostico

- o risco de sessao em `sessionStorage` continua real e a migracao para cookie `HttpOnly` permanece como frente de hardening
- `WorkforceController.php` continua grande demais e segue como principal debito arquitetural do dominio operacional
- a telemetria HTTP ja existe, mas a cobertura ainda nao e ampla o bastante para PDV, tickets, cards, meals e parking em toda a superficie
- listagens grandes continuam pedindo paginação real para evitar custo alto em eventos com massa maior
- smoke E2E operacional ainda segue como criterio obrigatorio para:
  - cashless + sync offline
  - emissao em massa de cartoes

### O que ja ficou superado pelo estado atual do repositorio

- `README.md` e `CLAUDE.md` ja nao estao mais no estado antigo citado pela analise; ambos foram atualizados em `2026-03-22`
- a estrategia oficial de `Auth` ja esta consolidada em `HS256`; a leitura antiga de `RS256` como estado atual ficou obsoleta
- `docs/progresso9.md` ja cobre as migrations `026`, `027` e `028`, e o residual de cartoes em massa foi desacoplado para `docs/cardsemassa.md`
- o alerta sobre `.env` versionado nao se sustenta mais como leitura do Git atual:
  - `backend/.env` pode existir localmente
  - mas esta ignorado por `.gitignore`
  - portanto o risco atual e de gestao local de segredo, nao de versionamento no repositório
- `database/README.md` deixou de ser a referencia de governanca; o contrato atual passou a morar no `README.md` da raiz

### Plano executivo curto a partir desta leitura

#### P0 — validar operacao real antes de abrir frente nova

- fechar smoke operacional de `cashless + sync offline`
- fechar smoke operacional de emissao em massa de cartoes
- registrar ambos em `docs/qa/` com criterio reproduzivel
- resumir os resultados no proprio `docs/progresso9.md`

#### P1 — fechar debito operacional/documental de alto valor

- criar `docs/runbook_local.md`
- fechar `docs/auditoriaPOS.md` contra o estado ja consolidado no POS
- revisar `pendencias.md` para retirar itens ja mortos e destacar apenas o residual vivo
- revisar listagens criticas e definir contrato padrao de paginação

#### P2 — endurecimento tecnico de banco e observabilidade

- separar a proxima rodada de banco em migrations pequenas e dedicadas
- ampliar a telemetria de endpoints criticos sem misturar isso com refactor estrutural
- mapear quais endpoints de listagem entram primeiro em paginação obrigatoria

#### P3 — refactor arquitetural controlado

- iniciar extracao progressiva de `WorkforceController.php`
- evitar reescrita ampla; priorizar servicos pequenos em volta dos fluxos mais criticos
- deixar frentes V4 fora do caminho enquanto ainda houver smoke e hardening operacional pendentes

### Outline tecnico recomendado para as proximas migrations

#### Migration `029_payment_gateways_hardening.sql`

Objetivo:

- fechar o residual conhecido da migration `006_financial_hardening.sql` sem misturar com outras frentes

Escopo sugerido:

- adicionar `organizer_payment_gateways.is_primary`
- adicionar `organizer_payment_gateways.environment`
- criar backfill minimo e seguro para os registros existentes
- adicionar guardas para impedir mais de um gateway principal por organizador, se o schema atual ainda nao sustentar isso

Criterio de aceite:

- `schema_current.sql` passa a refletir o contrato financeiro que o backend ja documenta
- leitura/escrita de gateways deixa de depender de ambiguidade documental sobre esses campos

#### Migration `030_operational_indexes.sql`

Objetivo:

- atacar performance/observabilidade de forma isolada, sem misturar regra de negocio nova

Escopo sugerido:

- revisar `offline_queue` para indice composto orientado ao reconcile operacional
- revisar `audit_log` para leitura por tempo/organizer em paineis e auditoria
- revisar `participant_meals` para consultas quentes do dominio operacional
- validar por leitura real do `schema_current.sql` quais indices ja existem antes de criar qualquer duplicata

Criterio de aceite:

- migration idempotente
- nenhum indice duplicado do baseline atual
- ganho direto em consultas quentes e trilhas operacionais mais frequentes

### Ordem recomendada de execucao

1. smoke `cashless + sync offline`
2. smoke `cartoes em massa`
3. `docs/runbook_local.md`
4. `029_payment_gateways_hardening.sql`
5. `030_operational_indexes.sql`
6. extracao progressiva de `WorkforceController.php`

---

## 12. Consolidacao da auditoriaCodex e plano de ataque unificado

### Documento-base adicional desta rodada

- `auditoriaCodex.md`

### Achados da auditoriaCodex validados no codigo atual

- o webhook de mensageria ainda aceita `provider` e `organizer_id` vindos de `query/body`, sem assinatura forte nem autenticacao criptografica
- `MessagingDeliveryService` ainda cria schema em runtime com `CREATE TABLE IF NOT EXISTS`, o que confirma governanca incompleta da frente
- o login ainda grava detalhes excessivos no log tecnico e remove todos os `refresh_tokens` do usuario a cada novo login
- `parking_records.organizer_id` existe no schema, mas o fluxo de `registerEntry()` nao popula a coluna
- `offline_queue` continua sem `organizer_id` denormalizado, embora o escopo operacional atual seja protegido via `event_id`

### Leitura consolidada do risco real agora

#### Criticos de integridade e operacao

- webhook de mensageria sem autenticacao forte
- drift entre schema baseline, migrations e bootstrap DDL em runtime
- logging de autenticacao com detalhe sensivel acima do necessario

#### Altos de sustentacao

- `WorkforceController.php` continua como maior debito estrutural do backend
- sessao em `sessionStorage` segue como hardening incompleto
- listagens sem paginação formal ainda podem degradar eventos maiores
- telemetria existe, mas ainda nao cobre a superficie operacional critica inteira

#### Medios de governanca e release

- teste de gateway ainda e validacao local, nao conectividade real
- lint/tooling do frontend ainda nao e sinal de release confiavel
- bundle web segue pesado e pede code splitting real por modulo
- falta suite consistente de smoke/contrato para segurar refactor de backend

### Frentes definidas do ataque

#### Frente 1 — integridade de mensageria

Objetivo:

- impedir webhook forjado e encerrar a dependencia de DDL em runtime na frente de mensageria

Escopo:

- exigir assinatura HMAC por provider/canal
- parar de aceitar `organizer_id` vindo do cliente
- resolver tenant apenas por instancia/credencial registrada
- persistir evento bruto, mas so aplicar mudanca em delivery apos validacao
- mover schema da mensageria para trilha formal de migration/baseline
- remover bootstrap DDL do `MessagingDeliveryService`

Criterio de aceite:

- webhook sem assinatura valida retorna `401/403`
- `organizer_id` nao e mais aceito de `query/body`
- ambiente sobe sem criar tabela via chamada HTTP

#### Frente 2 — hardening de auth e sessao

Objetivo:

- reduzir superficie de vazamento operacional e preparar modelo de sessao mais saudavel

Escopo:

- remover logs detalhados demais do login
- trocar logging de auth para motivo generico + correlation id
- desenhar refresh token por sessao/dispositivo
- manter cookie `HttpOnly` como destino da trilha de sessao

Criterio de aceite:

- login nao expõe metadados sensiveis em log
- modelo de refresh passa a suportar mais de uma sessao sem derrubar tudo
- plano de migracao para cookie fica documentado antes de implementacao

#### Frente 3 — governanca de banco e migrations

Objetivo:

- tirar o banco do modo semi-manual e fechar os drifts conhecidos primeiro

Escopo:

- `029_payment_gateways_hardening.sql`
- `030_operational_indexes.sql`
- formalizar a frente de mensageria no baseline em vez de runtime DDL
- decidir destino de `parking_records.organizer_id`
- decidir se `offline_queue` recebe `organizer_id` e `user_id` denormalizados
- desenhar introducao futura de `schema_migrations`

Criterio de aceite:

- nenhum comportamento critico depende de coluna “talvez exista”
- baseline e migrations refletem o contrato vivo da aplicacao
- tabela criada em runtime deixa de existir como mecanismo operacional

#### Frente 4 — QA, smoke e contratos

Objetivo:

- travar comportamento atual antes de mexer pesado no backend

Escopo:

- smoke autenticado de `cashless + sync offline`
- smoke autenticado de `cartoes em massa`
- smoke basico de mensageria
- smoke multi-tenant minimo para tickets, parking, cards e participants
- primeiro pacote de testes de contrato dos endpoints mais criticos

Criterio de aceite:

- cada frente critica tem roteiro reproduzivel em `docs/qa/`
- refactor estrutural nao anda sem smoke minimo verde

#### Frente 5 — modularizacao segura do `WorkforceController.php`

Objetivo:

- reduzir o risco do arquivo sem quebrar contratos HTTP nem reescrever o dominio

Estrategia obrigatoria:

- modularizacao incremental
- sem “big bang refactor”
- sem troca de rotas na primeira passada
- sem misturar refactor com mudanca de regra de negocio

Fases:

1. congelar contrato atual com smoke minimo por familia de endpoint
2. extrair helpers puros de schema/setor/identidade
3. extrair `card issuance`
4. extrair `role/member settings`
5. extrair `assignments/managers`
6. extrair `roles/event-roles`
7. extrair `importWorkforce`
8. extrair `tree-status/backfill/sanitize` por ultimo

Servicos alvo:

- `WorkforceSchemaSupport`
- `WorkforceSectorSupport`
- `WorkforceIdentitySupport`
- `WorkforceSettingsService`
- `WorkforceAssignmentService`
- `WorkforceManagerService`
- `WorkforceRoleService`
- `WorkforceEventRoleService`
- `WorkforceImportService`
- `WorkforceTreeService`

Criterio de aceite:

- `dispatch()` permanece estavel
- payloads/respostas nao mudam na primeira rodada
- controller cai progressivamente para uma borda HTTP fina

#### Frente 6 — telemetria, paginação e observabilidade

Objetivo:

- melhorar leitura operacional sem abrir uma reescrita de arquitetura

Escopo:

- ampliar `resolveCriticalEndpointLabel` para PDV, tickets, cards, meals e parking
- padronizar correlation id nos fluxos criticos
- definir paginação obrigatoria nas listagens grandes
- priorizar `tickets`, `participants`, `guests`, `workforce/assignments`

Criterio de aceite:

- endpoints quentes entram na telemetria
- listagens grandes deixam de depender de retorno “traga tudo”

#### Frente 7 — release safety do frontend

Objetivo:

- tirar a frente web do modo “build passa, mas release ainda e frágil”

Escopo:

- corrigir lint/tooling quebrado
- iniciar code splitting real por modulo
- medir bundle por rota critica
- separar dashboard, participants, POS, messaging e IA em chunks dedicados

Criterio de aceite:

- lint roda sem erro espurio de tooling
- build continua verde
- bundle principal cai e deixa de concentrar toda a aplicacao

### Frentes fora do ataque imediato

- `Agents Hub`
- `Embedded Support Bot`
- `RS256 pleno`
- `franquias`
- `logistica de artistas`
- `controle de custos do evento`
- `WAF`, `Vault` e demais itens V4

Regra:

- essas frentes nao entram antes de fechar integridade, baseline, smoke e modularizacao minima do operacional atual

### Ordem unica de execucao recomendada

#### Onda 0 — travar o estado atual

1. registrar smoke que falta em `docs/qa/`
2. fechar `runbook_local`
3. limpar documentacao residual que ainda conflita com o estado real

#### Onda 1 — risco critico primeiro

4. webhook de mensageria
5. remocao de DDL runtime da mensageria
6. logging de auth

#### Onda 2 — banco e governanca

7. `029_payment_gateways_hardening.sql`
8. `030_operational_indexes.sql`
9. decisao sobre `parking_records.organizer_id`
10. decisao sobre enriquecimento de `offline_queue`

#### Onda 3 — contratos e observabilidade

11. telemetria expandida
12. paginação das listagens quentes
13. smoke multi-tenant + contratos minimos

#### Onda 4 — refactor estrutural controlado

14. `WorkforceController.php` por extracao incremental
15. backend bootstrap/roteamento como frente posterior, nao misturada com Workforce

#### Onda 5 — release safety e expansao

16. lint/tooling do frontend
17. code splitting
18. so depois disso reabrir frentes de produto novo

### Decisao pratica desta rodada

- o ataque principal nao sera por novas features
- o ataque principal sera por:
  - integridade de mensageria
  - governanca de banco
  - smoke/contrato
  - modularizacao segura do `WorkforceController.php`

### Proxima acao recomendada apos este registro

1. fechar `docs/runbook_local.md`
2. abrir implementacao da Frente 1 — webhook de mensageria
3. em paralelo, preparar a `029_payment_gateways_hardening.sql`

---

## 13. Onda 0 — organizacao documental executada

### O que foi materializado

- criado `docs/runbook_local.md` como runbook minimo de bootstrap e smoke local
- criado `docs/auditorias.md` como indice unico das auditorias e da politica de retencao
- `README.md` foi atualizado para apontar para `docs/runbook_local.md` e `docs/auditorias.md`
- `CLAUDE.md` foi atualizado para tratar `docs/auditorias.md` como entrada unica das auditorias

### Decisao de organizacao adotada

- o estado vivo da operacao passa a ser lido de:
  - `docs/progresso9.md`
  - `README.md`
  - `CLAUDE.md`
  - `docs/auditorias.md`
- auditorias detalhadas passam a ser tratadas como snapshot historico
- a limpeza de arquivos deve priorizar primeiro duplicatas e docs substituidos, sem apagar snapshot tecnico antes da rodada de arquivamento

### Lista de exclusao segura mapeada nesta onda

#### Excluir agora

- `auditoriaPOS.md`
- `auditoriafinalworkforcemeals.md`
- `auditoriamelas.md`
- `auditoriaworkforce.md`
- `database/README.md`
- `frontend/README.md`

#### Excluir depois de conferencia final

- `analise_enjoyfun_2026_03_22.md`
- `auditoriaCodex.md`

### O que fica preservado por enquanto

- `docs/auditoriaPOS.md`
- `docs/auditoriaworkforce.md`
- `docs/auditoriamelas.md`
- `docs/auditoriafinalworkforcemeals.md`

Motivo:

- ainda servem como trilha historica detalhada
- o indice consolidado agora esta em `docs/auditorias.md`, mas a rodada de arquivamento formal ainda nao aconteceu

### Proxima acao apos a Onda 0

1. revisar `pendencias.md`
2. abrir a Frente 1 — webhook de mensageria
3. preparar `029_payment_gateways_hardening.sql`

---

## 14. Triagem final de docs e handoff para `progresso10`

### Decisao consolidada desta limpeza

- todos os `docs/progresso*.md` permanecem no repo como trilha de pesquisa
- `docs/diagnostico.md` fica como diagnostico tecnico unico mantido no repositorio
- auditorias antigas detalhadas deixam de ser referencia operacional primaria
- os arquivos antigos marcados na triagem seguem vivos apenas no arquivo externo do projeto

### Lista aprovada para mover ao arquivo externo

- `docs/auditoriaPOS.md`
- `docs/auditoriaworkforce.md`
- `docs/auditoriamelas.md`
- `docs/auditoriafinalworkforcemeals.md`
- `docs/diagnostico_sistema.md`
- `docs/security_audit_enjoyfun_v2.md`
- `docs/EnjoyFun_Blueprint_V5.md`
- `docs/enjoyfun_blueprint_dashboard_v_1.md`
- `docs/enjoyfun_backlog_oficial_v_1.md`
- `docs/enjoyfun_arquitetura_modulos_servicos_v_1.md`
- `docs/enjoyfun_kpis_formulas_oficiais_v_1.md`
- `docs/enjoyfun_modelagem_oficial_banco_v_1.md`
- `docs/enjoyfun_mvps_oficiais_v_1.md`
- `docs/enjoyfun_orientacao_operacional_a_partir_de_hoje.md`
- `docs/enjoyfun_plano_execucao_fase_1_v_1.md`
- `docs/enjoyfun_prompts_oficiais_codex_v_1.md`
- `docs/enjoyfun_roadmap_implementacao_v_1.md`
- `docs/enjoyfun_sprint_1_v_1.md`
- `docs/enjoyfun_tenant_settings_hub_v_1.md`

### Estrutura documental que fica viva no repo

- `README.md`
- `CLAUDE.md`
- `docs/auditorias.md`
- `docs/runbook_local.md`
- `docs/diagnostico.md`
- `docs/cardsemassa.md`
- `docs/progresso*.md`
- `docs/adr_*.md`
- `docs/qa/`
- specs/documentos de dominio ainda uteis

### Handoff oficial

- `docs/progresso9.md` fica encerrado como fechamento da rodada anterior
- a continuidade das mudancas passa a ser registrada em `docs/progresso10.md`
