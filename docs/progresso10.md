## 0. Handoff oficial da nova rodada

- Este arquivo passa a ser o diário ativo a partir da triagem documental consolidada em `docs/progresso9.md`.
- As decisões herdadas vêm de:
  - `docs/progresso9.md` — `12. Consolidacao da auditoriaCodex e plano de ataque unificado`
  - `docs/progresso9.md` — `13. Onda 0 — organizacao documental executada`
  - `docs/progresso9.md` — `14. Triagem final de docs e handoff para progresso10`

## 1. Estado herdado

- o ataque principal continua focado em:
  - integridade de mensageria
  - governanca de banco
  - smoke/contrato
  - modularizacao segura do `WorkforceController.php`
- `docs/progresso9.md` fica congelado como fechamento da rodada anterior
- todos os `docs/progresso*.md` anteriores permanecem como fonte de pesquisa

## 2. Estado documental desta nova rodada

- `docs/auditorias.md` e o indice unico das auditorias
- `docs/runbook_local.md` e o runbook vivo
- `docs/diagnostico.md` e o diagnostico tecnico unico mantido no repo
- os documentos marcados para mover ao arquivo externo nao sao mais referencia operacional primaria

## 3. Frentes abertas agora

### Frente 1 — webhook e integridade de mensageria

- autenticar webhook
- remover `organizer_id` vindo do cliente
- eliminar bootstrap DDL em runtime

### Frente 2 — governanca de banco

- preparar `029_payment_gateways_hardening.sql`
- preparar `030_operational_indexes.sql`
- fechar drifts de schema ainda vivos

### Frente 3 — QA e contratos

- smoke de `cashless + sync offline`
- smoke de emissão em massa de cartões
- contratos mínimos dos endpoints críticos

### Frente 4 — modularização do `WorkforceController.php`

- seguir a extração incremental já definida em `docs/progresso9.md`

## 4. Regra desta rodada

- toda mudança aplicada a partir daqui deve ser registrada em `docs/progresso10.md`
- nenhum documento antigo volta a ser referência viva sem antes ser reavaliado

---

## 5. Primeira passada desta rodada — alinhamento pós-triagem

### O que foi feito

- `README.md` deixou de apontar para docs antigos que já saíram da trilha operacional viva
- `CLAUDE.md` deixou de tratar fechamento de auditoria antiga como frente ativa
- `pendencias.md` foi reescrito para refletir as frentes reais desta rodada:
  - mensageria / webhook
  - banco / migrations
  - smoke / contratos
  - modularização do `WorkforceController.php`
  - release safety do frontend

### Observação importante

- a triagem documental já está consolidada no contrato do repo
- porém alguns arquivos antigos ainda podem continuar fisicamente no workspace até você terminar de movê-los para a pasta externa
- isso não muda a regra operacional:
  - eles não são mais referência viva
  - a leitura atual passa por `README.md`, `CLAUDE.md`, `docs/auditorias.md`, `docs/progresso10.md` e `pendencias.md`

### Próxima passada recomendada

1. Frente 1 — webhook de mensageria
2. em paralelo, preparar `029_payment_gateways_hardening.sql`

---

## 6. Frente 1 — primeira execução real na mensageria

### O que foi aplicado

- `MessagingController.php` deixou de aceitar `organizer_id` vindo do cliente no webhook.
- o tenant do webhook agora é resolvido apenas por instância registrada.
- o webhook passou a exigir segredo compartilhado:
  - headers aceitos: `x-webhook-secret`, `x-enjoyfun-webhook-secret`, `x-wa-webhook-secret`, `apikey`, `authorization`
  - fallback transitório por query: `?secret=...` ou `?token=...`
  - assinatura HMAC também é aceita por `x-signature`, `x-webhook-signature` e `x-hub-signature-256`
- `MessagingDeliveryService.php` deixou de criar tabela/índice em runtime:
  - agora só valida readiness
  - se faltar schema, a API responde erro operacional claro
- o reconcile do webhook por telefone deixou de atualizar múltiplos deliveries:
  - agora, sem `provider_message_id`, atualiza apenas o delivery WhatsApp mais recente ainda pendente
- `EmailService.php` deixou de registrar payload bruto, destinatário e resposta completa do provedor no log
- `public/index.php` passou a preservar o raw body para validação de assinatura

### Observação operacional

- o webhook pode usar:
  - `wa_webhook_secret` dedicado por organizador
  - `wa_token` já cadastrado no organizador
  - ou segredo global por ambiente via `MESSAGING_WEBHOOK_SECRET` / `WA_WEBHOOK_SECRET`
- a migration aberta nesta passada foi `database/031_messaging_webhook_secret.sql`
- o service ficou compatível com rollout:
  - lê a coluna nova quando ela existir
  - mantém compatibilidade enquanto a migration ainda não tiver sido aplicada

### O que ainda sobra nesta frente

- concluir a aplicação da `database/031_messaging_webhook_secret.sql` nos ambientes ativos
- desenhar retry/replay administrativo da mensageria

### Segunda passada — settings e segredo dedicado

- `OrganizerMessagingConfigService.php` passou a suportar `wa_webhook_secret` criptografado.
- o endpoint `/organizer-messaging-settings` agora usa payload próprio de settings, sem depender do payload público enxuto.
- a tela `ChannelsTab.jsx` ganhou o campo `Segredo do Webhook`.
- o save do settings agora falha com mensagem clara se a migration da nova coluna ainda não tiver sido aplicada.

---

## 7. Frente de auditoria seguinte — auth e sessão

### Leitura-base usada nesta passada

- o arquivo `docs/security_audit_enjoyfun_v2.md` já não está mais no repo
- a frente foi retomada a partir do consolidado vivo em `docs/progresso9.md`
- `analise_enjoyfun_2026_03_22.md` e `auditoriaCodex.md` passam a ser base complementar viva desta rodada

### Primeira passada aplicada

- `AuthController.php` deixou de gravar no log técnico:
  - existência do usuário
  - `password_verify`
  - tamanho do hash
- falha de login e refresh inválido agora usam motivo genérico + `correlation_id`
- o login deixou de apagar todos os `refresh_tokens` do usuário a cada nova sessão
- isso devolve compatibilidade com múltiplas sessões simultâneas usando o modelo atual de rotação por token
- `frontend/src/lib/api.js` passou a enviar `X-Device-ID` também no refresh explícito do interceptor

### O que ainda sobra nesta frente

- aplicar `database/032_refresh_tokens_session_tracking.sql` nos ambientes ativos
- decidir o rollout operacional de `AUTH_ACCESS_COOKIE_MODE`
- desenhar a camada final de CSRF/anti-replay antes de ligar cookie de acesso por padrão

### Segunda passada — modelagem de refresh token por sessão

- foi aberta a migration `database/032_refresh_tokens_session_tracking.sql`
- `refresh_tokens` passa a ter trilha para:
  - `session_id`
  - `device_id`
  - `user_agent`
  - `ip_address`
  - `last_used_at`
  - `revoked_at`
- `AuthController.php` ficou compatível com rollout:
  - usa os campos novos quando a migration existir
  - mantém fallback para o schema antigo enquanto a migration não for aplicada
- a rotação do refresh agora revoga o token antigo por `id` quando o schema novo existir, em vez de depender de deleção simples como modelo único

### Terceira passada — trilha inicial para cookie `HttpOnly`

- o backend passou a emitir `refresh_token` preferencialmente via cookie `HttpOnly`:
  - nome configurável por `AUTH_REFRESH_COOKIE_NAME`
  - controle via `AUTH_REFRESH_COOKIE_MODE`
  - `SameSite` configurável por `AUTH_COOKIE_SAMESITE`
- `refresh` e `logout` agora aceitam o token pelo body ou pelo cookie
- o frontend passou a operar em modo `cookie-first` para refresh:
  - `session.js` registra `refresh_transport`
  - `api.js` envia `withCredentials: true`
  - o interceptor já tenta refresh por cookie quando o transporte da sessão for `cookie`
- nesta passada o `access_token` ainda continua em `sessionStorage`
- a migração completa para cookie `HttpOnly` do access token continua como etapa seguinte e precisa vir junto com desenho de CSRF/anti-replay

### Quarta passada — rollout compatível para access cookie

- o backend passou a suportar `access_token` também por cookie `HttpOnly`, controlado por `AUTH_ACCESS_COOKIE_MODE`
- o nome do cookie de acesso ficou configurável por `AUTH_ACCESS_COOKIE_NAME`
- `AuthMiddleware.php` agora aceita autenticação por header `Bearer` ou por cookie de acesso, preservando compatibilidade com o contrato antigo
- o frontend passou a registrar também `access_transport` e já consegue operar sem gravar `access_token` em `sessionStorage` quando o transporte vier como `cookie`
- `AuthContext.jsx` e o interceptor do `api.js` passaram a bootstrapar sessão e reexecutar requests considerando:
  - `access cookie`
  - `refresh cookie`
  - ou transporte legado pelo body
- nesta passada o rollout ficou seguro por default:
  - `AUTH_ACCESS_COOKIE_MODE` nasce desligado por padrão
  - o modo antigo continua íntegro até a ativação explícita no ambiente

### Quinta passada — redução de exposição no OTP mock

- os logs de OTP mock em desenvolvimento deixaram de registrar destino completo por padrão
- o identificador agora é mascarado no log técnico
- o código OTP só volta a aparecer em log se `AUTH_LOG_OTP_MOCK_CODE=1`

---

## 8. Frente seguinte — governança de banco e migrations

### Decisão aplicada nesta passada

- o residual financeiro da `006` deixa de ficar “implícito” no repo e passa a ter migration dedicada de reconciliação
- `parking_records.organizer_id` permanece no modelo e passa a ser efetivamente preenchido no fluxo de portaria
- `offline_queue` ganha contexto denormalizado mínimo para reconcile e investigação:
  - `organizer_id`
  - `user_id`
- a trilha foi aberta sem quebra de rollout:
  - controllers usam detecção de coluna
  - a aplicação continua compatível enquanto `029` e `030` ainda não estiverem aplicadas

### O que foi aberto

- `database/029_payment_gateways_hardening.sql`
  - fecha o residual da `006`
  - adiciona `is_primary` e `environment`
  - normaliza provider/ambiente
  - deduplica provider por organizador
  - garante um único gateway principal por organizador
  - cria `ux_payment_gateways_org_provider`, `ux_payment_gateways_org_primary` e `ux_financial_settings_organizer`
- `database/030_operational_context_hardening.sql`
  - adiciona `organizer_id` e `user_id` em `offline_queue`
  - faz backfill de `offline_queue.organizer_id`
  - faz backfill de `parking_records.organizer_id`
  - cria índices compostos para reconcile e listagem operacional

### Código adaptado

- `ParkingController.php` voltou a persistir `organizer_id` em `parking_records` quando a coluna existir
- `SyncController.php` passou a gravar `organizer_id` e `user_id` em `offline_queue` quando as colunas existirem
- ambos ficaram compatíveis com rollout parcial por introspecção de schema

### Baseline e documentação

- `database/schema_current.sql` foi alinhado com as novas colunas, checks e índices
- `README.md` e `CLAUDE.md` deixaram de afirmar que a `006` permanece parcialmente refletida no baseline

### O que ainda sobra nesta frente

- aplicar `029` e `030` nos ambientes ativos
- decidir, numa passada separada, o índice composto faltante de `audit_log`
- avaliar numa passada futura se `parking_records.organizer_id` pode virar `NOT NULL` após rollout completo

---

## 9. Frente seguinte — QA, smoke e contratos

### Decisão aplicada nesta passada

- esta frente não vai começar por automação artificial de fluxos mutáveis
- o pacote mínimo foi dividido em:
  - runner automatizado para contratos estáveis
  - smoke manual controlado para fluxos com efeito operacional real

### Artefatos abertos

- `docs/qa/smoke_operacional_core.md`
  - smoke de `cashless + sync offline`
  - smoke de emissão em massa de cartões
  - smoke básico de mensageria
  - smoke multi-tenant mínimo
- `docs/qa/contratos_minimos_api.md`
  - define o pacote mínimo de contratos vivos desta rodada
  - ancora o runner atual `backend/scripts/workforce_contract_check.mjs`
  - define o gate para não avançar em refactor sem contrato + smoke

### Documentação alinhada

- `docs/runbook_local.md` passou a apontar explicitamente para os artefatos de QA vivos
- `pendencias.md` deixou de tratar a frente como “sem roteiro” e passou a tratá-la como “roteiro versionado, execução pendente”

### O que ainda sobra nesta frente

- executar o smoke real nos ambientes locais/ativos
- registrar evidências objetivas em `docs/progresso10.md`
- decidir numa passada futura quais contratos adicionais merecem runner automatizado além de `workforce_contract_check.mjs`

---

## 10. Primeira execução real do smoke — 22/03/2026

### Ambiente efetivamente usado

- API viva encontrada em `http://localhost:8080/api`
- o runner local automatizado foi executado contra essa base viva

### Smoke automatizado executado

- `node backend/scripts/workforce_contract_check.mjs`
- resultado:
  - autenticação `PASS`
  - `GET /events` `PASS`
  - `GET /workforce/tree-status` `PASS`
  - `GET /workforce/roles` `PASS`
  - `GET /workforce/event-roles` `PASS`
  - `GET /workforce/managers` `PASS`
  - `GET /workforce/assignments` `PASS`
  - `POST /sync` com falha rastreável `PASS`
  - `POST /participants` `PASS`
  - `DELETE /participants/:id` `PASS`
- evento resolvido pelo runner:
  - `event_id=7`
  - `aldeia da trasnformação`

### Smoke de cartões por escopo de evento

- `GET /cards?event_id=7` retornou `11` cartões
- `GET /cards?event_id=1` retornou `1` cartão
- cartão usado para prova de escopo:
  - `6a1b8f7d-9bc7-4474-95fe-32f4813e73bd`
  - titular retornado: `Yuri Gagarin`
- `POST /cards/resolve` com esse cartão no `event_id=7` retornou `200`
- `POST /cards/resolve` com o mesmo cartão no `event_id=1` retornou `404`
- leitura operacional:
  - o isolamento por `event_id` em `/cards` e `/cards/resolve` permaneceu íntegro nesta base

### Smoke de leitura da mensageria

- `GET /messaging/config` retornou apenas:
  - `email_sender`
  - `wa_configured`
  - `email_configured`
  - `webhook_configured`
  - `configured`
- não houve vazamento de:
  - `wa_token`
  - `wa_webhook_secret`
  - `resend_api_key`
  - `wa_api_url`
  - `wa_instance`

### Achado real do smoke

- a API viva em `:8080` ainda responde `POST /auth/login` no contrato antigo:
  - retorna `access_token`
  - retorna `refresh_token`
  - retorna `expires_in`
  - não retorna `access_transport`
  - não retorna `refresh_transport`
- isso indica drift entre:
  - o código atual do repositório
  - e a instância viva usada no smoke

### Contraprova local do código atual

- foi levantada uma instância isolada em `http://localhost:8001/api`
- `GET /health` respondeu `200`
- `POST /auth/login` falhou com `503` e mensagem:
  - `Configuracao de banco indisponivel.`
- leitura operacional:
  - o código atual precisa de ambiente DB configurado para validar o novo contrato de auth em runtime
  - a validação de cookie/auth não pode ser considerada concluída apenas com a API viva em `:8080`

### Fechamento desta passada

- o runner de contrato base está verde
- o smoke de escopo de cartões está verde
- a leitura pública de mensageria está verde
- a trilha de auth/sessão ficou com bloqueio operacional de ambiente:
  - falta validar em runtime a instância realmente alinhada ao código atual

### Smoke real — `cashless + sync offline`

- evento usado:
  - `event_id=7`
- cartão usado:
  - `f1a86e50-0a5e-454c-9c43-ec632d371532`
  - titular exibido: `Cartão Avulso`
  - saldo antes: `1748`
- produto usado no bar:
  - `product_id=134`
  - `Agua`
  - `R$ 8,00`

#### Checkout online

- `POST /bar/checkout` retornou sucesso
- venda criada:
  - `sale_id=254`
- saldo após checkout online:
  - `1740`

#### Replay offline

- `offline_id=smoke-sync-1774219043764`
- `device_id=smoke-cashless-sync-20260322`
- `POST /sync` retornou:
  - `status=success`
  - `processed=1`
  - `processed_new=1`
  - `failed=0`

#### Evidência persistida

- saldo final do cartão:
  - `1732`
- transações do cartão:
  - antes: `2`
  - depois: `4`
- `sales`:
  - `255|8.00|completed|smoke-sync-1774219043764|bar|true`
- `card_transactions`:
  - `154|debit|8.00|smoke-sync-1774219043764|7|2|cashless|true`
- `offline_queue`:
  - `smoke-sync-1774219043764|synced|sale|7|smoke-cashless-sync-20260322`

#### Leitura operacional desta prova

- checkout online e replay offline ficaram coerentes
- o débito offline entrou com:
  - `offline_id`
  - `event_id=7`
  - `user_id=2`
  - `payment_method=cashless`
  - `is_offline=true`
- o saldo final bateu exatamente com:
  - `1748 - 8 - 8 = 1732`

#### Drift detectado na base viva

- a consulta de `offline_queue.organizer_id` e `offline_queue.user_id` falhou na base viva
- isso confirma em runtime que a migration `030_operational_context_hardening.sql` ainda não foi aplicada nesse ambiente
- a prova mínima de replay ficou verde, mas o enriquecimento novo de auditoria ainda não está ativo na base usada pelo smoke

### Smoke real — emissão em massa de cartões

- evento usado:
  - `event_id=7`
- lote emitido:
  - participantes `811`, `838`, `812`
  - saldo inicial por cartão: `R$ 10,00`
  - `source_module=workforce_bulk`

#### Preview

- `POST /workforce/card-issuance/preview` retornou:
  - `eligible_count=3`
  - `can_issue=true`
  - `already_has_active_card_count=0`
  - `error_count=0`
  - `estimated_initial_credit_total=30`

#### Issue

- `POST /workforce/card-issuance/issue` retornou:
  - `batch_id=4`
  - `issued_count=3`
  - `failed_count=0`
  - `skipped_count=0`
  - `applied_initial_credit_total=30`
- `idempotency_key` usada:
  - `smoke-mass-1774219334886`

#### Cartões emitidos

- `Ana Maria`
  - `participant_id=811`
  - `card_id=0a703a5a-718f-49e5-ab14-f8befc27e6fc`
- `Babi Xavier`
  - `participant_id=838`
  - `card_id=61e8375c-5987-44dc-b705-beb1484431cd`
- `Benedita Casé`
  - `participant_id=812`
  - `card_id=fb38e2f4-c9a7-4ed1-a5d0-6a4ab289aed6`

#### Evidência de histórico

- `GET /cards?event_id=7`
  - antes do issue: `11`
  - depois do issue: `14`
- os 3 cartões novos apareceram em `/cards` com:
  - `event_id=7`
  - `status=active`
  - `balance=10`
- `GET /cards/{card_id}/transactions?event_id=7` para cada um retornou crédito inicial com descrição:
  - `Carga inicial na emissao em massa`

#### Evidência de lote

- `card_issue_batches`
  - `4|7|workforce_bulk|3|3|3|0|0|2|smoke-mass-1774219334886`
- `card_issue_batch_items`
  - `9|4|811|issued||0a703a5a-718f-49e5-ab14-f8befc27e6fc`
  - `10|4|838|issued||61e8375c-5987-44dc-b705-beb1484431cd`
  - `11|4|812|issued||fb38e2f4-c9a7-4ed1-a5d0-6a4ab289aed6`

#### Prova de `preview` side-effect free

- foi executado preview isolado para `727`, `728`, `729`
- resultado:
  - `eligible_count=3`
  - `can_issue=true`
- `GET /cards?event_id=7`
  - antes do preview isolado: `14`
  - depois do preview isolado: `14`

#### Prova de idempotência

- o mesmo `POST /workforce/card-issuance/issue` foi reenviado com a mesma `idempotency_key`
- retorno:
  - `batch_id=4`
  - `replayed=true`
- leitura operacional:
  - o lote foi reaproveitado
  - nenhum cartão duplicado foi criado

---

## 10. Frente de mensageria — smoke básico e correções de runtime

### Correções aplicadas nesta passada

- `OrganizerMessagingSettingsController.php` deixou de depender de funções globais genéricas:
  - o controller agora usa dispatcher isolado e helpers prefixados
- `OrganizerSettingsController.php` também foi isolado:
  - dispatcher próprio
  - helpers prefixados para reduzir colisão global
- `MessagingDeliveryService.php` teve correção no reconcile do webhook:
  - o `UPDATE` de status deixou de reutilizar o mesmo placeholder SQL em contextos ambíguos no PostgreSQL
  - isso eliminou o erro `SQLSTATE[42P08] Ambiguous parameter` reproduzido no caminho do webhook

### Smoke que passou

- `GET /organizer-messaging-settings`
  - voltou a responder `200`
  - payload:
    - `email_sender=onboarding@resend.dev`
    - `wa_configured=false`
    - `email_configured=false`
    - `webhook_configured=false`
- `POST /organizer-messaging-settings`
  - voltou a responder `200`
  - save mínimo com `email_sender` concluído sem erro
- `GET /messaging/config`
  - respondeu `200`
  - confirmou payload público enxuto:
    - `email_sender`
    - `wa_configured`
    - `email_configured`
    - `webhook_configured`
    - `configured`
- webhook inválido com configuração temporária de smoke:
  - `POST /messaging/webhook`
  - header `x-webhook-secret=wrong-secret`
  - retorno: `401`
  - sem mutação no delivery de teste
  - sem novo evento processado antes do webhook válido

### Achado estrutural no ambiente

- `organizer_settings` do `organizer_id=2` ainda carrega `resend_api_key='***redacted***'`
- por isso:
  - `email_configured=false`
  - `POST /messaging/email` retorna `503`
  - a rota entende corretamente que não há segredo utilizável
- também não havia nenhuma configuração ativa de WhatsApp para nenhum organizador na base viva antes do smoke controlado

### Reproduções do webhook

#### Repro CLI do backend atual

- foi feita reprodução direta no PHP CLI com:
  - `wa_token=smoke-wa-token-cli`
  - `wa_instance=smoke-instance-cli`
  - delivery de teste `correlation_id=smoke-msg-cli`
  - `provider_message_id=smoke-provider-cli`
- resultado após a correção:
  - `event_id=4`
  - `updated_deliveries=1`
  - `organizer_id=2`
- o delivery foi atualizado para:
  - `status=delivered`
  - `delivered_at` preenchido
  - `response_payload` persistido com o payload sanitizado do webhook

#### Repro HTTP em `http://localhost:8080/api`

- mesmo após restart do servidor local, o webhook válido no runtime de `:8080` continuou divergindo
- cenário de smoke:
  - `wa_token=smoke-wa-token-http2`
  - `wa_instance=smoke-instance-http2`
  - delivery `correlation_id=smoke-msg-http2`
  - `provider_message_id=smoke-provider-http2`
- retorno HTTP do webhook válido:
  - `500`
  - `correlation_id=93d71b819ea086ac`
- efeito persistido:
  - `messaging_webhook_events` recebeu o evento
  - `message_deliveries` não foi atualizado
  - o delivery permaneceu em `status=sent`

### Leitura operacional desta passada

- a frente de mensageria avançou materialmente:
  - settings já não estão quebrados pelo fatal de controller
  - leitura pública continua sem vazamento
  - webhook inválido já bloqueia corretamente com `401`
  - a correção do reconcile já funciona no backend atual, comprovada por repro CLI
- o bloqueio restante ficou isolado:
  - ainda existe drift entre o código atual e o comportamento do runtime HTTP em `:8080`
  - esse drift agora está reduzido ao caminho final do webhook válido no servidor vivo local

### Estado final do ambiente após o smoke

- a configuração temporária de WhatsApp do `organizer_id=2` foi restaurada para vazio:
  - `wa_api_url=''`
  - `wa_token=''`
  - `wa_instance=''`
- os registros de smoke em `message_deliveries` e `messaging_webhook_events` foram mantidos como evidência técnica da auditoria

### Fechamento do drift do servidor `:8080`

- o drift não estava mais no código de mensageria
- a causa real passou a ser o runtime local do servidor embutido PHP:
  - o listener em `:8080` continuava servindo bootstrap stale de `public/index.php`
  - isso mascarava correções já aplicadas no repo
- a contraprova foi objetiva:
  - `include backend/public/index.php` no CLI respondeu `version=2.0-debug-index`
  - o `GET /api/ping` do servidor embutido continuava respondendo `version=2.0`
- a estabilização local ficou feita assim:
  - criar `backend/router_dev.php`
  - subir o backend com `OPcache` desligado
  - usar o router dedicado do servidor embutido em vez de depender diretamente do bootstrap

### Comando operacional validado

```bash
cd backend
php -d opcache.enable=0 -d opcache.enable_cli=0 -S localhost:8080 -t public router_dev.php
```

### Revalidação final do webhook HTTP

- `GET /api/ping`
  - voltou a responder `version=2.0`
- cenário final de smoke:
  - `wa_token=smoke-wa-token-closed`
  - `wa_instance=smoke-instance-closed`
  - delivery `correlation_id=smoke-msg-closed`
  - `provider_message_id=smoke-provider-closed`
- webhook válido:
  - `POST /messaging/webhook`
  - retorno: `200`
  - payload:
    - `event_id=12`
    - `updated_deliveries=1`
    - `organizer_id=2`
- evidência persistida:
  - `message_deliveries`
    - `status=delivered`
    - `delivered_at` preenchido
    - `response_payload` persistido
  - `messaging_webhook_events`
    - `processed_at` preenchido

### Estado final desta frente

- o webhook HTTP ficou fechado no runtime local `:8080`
- a causa residual era de bootstrap/OPcache local, não de regra de negócio da mensageria
- `README.md` e `docs/runbook_local.md` foram alinhados para subir a API local sem reabrir esse drift

---

## 11. Frente 4 — modularização segura do `WorkforceController.php`

### Primeira passada aplicada

- foi aberta a fase segura de extração de helpers puros
- o contrato HTTP do controller foi preservado:
  - `dispatch()` continua no próprio `WorkforceController.php`
  - nenhuma rota foi renomeada
  - nenhum payload foi alterado

### Extração executada

- novo helper criado:
  - `backend/src/Helpers/WorkforceControllerSupport.php`
- a primeira extração tirou do controller os helpers de:
  - resolução de `organizer_id`
  - gate/normalização de emissão em massa de cartões
  - normalização e inferência de setor
  - helpers de cargos/setores
  - resolução de categoria padrão
  - busca de pessoa/participante por identidade
  - introspecção de schema com `columnExists()` e `tableExists()`

### Resultado estrutural

- o `WorkforceController.php` caiu para `4447` linhas no estado atual do repo
- a linha de base documental em `CLAUDE.md` foi atualizada para refletir esse novo tamanho
- o helper novo concentra agora a superfície de utilidades mais estável da frente

### Validação desta passada

- `php -l backend/src/Helpers/WorkforceControllerSupport.php`
- `php -l backend/src/Controllers/WorkforceController.php`
- `node backend/scripts/workforce_contract_check.mjs`
  - suíte concluída com `OK`
  - contratos mínimos de `tree-status`, `roles`, `event-roles`, `managers`, `assignments`, `sync` e `participants` preservados

### Leitura operacional desta passada

- a extração foi cirúrgica:
  - sem tocar no roteamento
  - sem trocar assinatura de endpoints
  - sem reabrir bugs funcionais já homologados
- esta passada fecha a primeira etapa real da Fase 1 definida em `docs/progresso9.md`:
  - reduzir volume do controller
  - isolar helpers estáveis
  - preparar a próxima extração com risco menor

### Próximo corte recomendado

- seguir para os helpers de assignment/identidade que ainda ficaram no próprio controller
- depois disso:
  - extrair a família de `card issuance`
  - manter o runner de contrato verde a cada passada

## 2026-03-22 — Workforce Fase 1, passada 2: assignment / identity

### Escopo

- segunda extração segura do `WorkforceController`
- alvo: helpers de assignment / identidade ainda mantidos dentro do controller
- premissa mantida:
  - `dispatch()` intacto
  - contratos HTTP intactos
  - sem mudança de regra de negócio

### Extração aplicada

- novo helper criado:
  - `backend/src/Helpers/WorkforceAssignmentIdentityHelper.php`
- o `backend/src/Controllers/WorkforceController.php` passou a carregar esse helper na borda do arquivo
- saíram do controller e foram consolidadas no helper as funções:
  - `workforceNormalizeAssignmentIdentitySector`
  - `workforceFindExistingAssignment`
  - `fetchLeaderParticipantBindingContext`
  - `fetchLeaderUserBindingContext`
  - `findLeaderUserBindingByIdentity`
  - `findLeaderParticipantBindingByIdentity`
  - `findPersonIdByIdentity`
  - `ensureLeadershipParticipantFromIdentity`

### Resultado estrutural

- o `WorkforceController.php` caiu de `4447` para `4137` linhas
- a redução desta passada foi de `310` linhas
- o controller segue funcional, mas com menos acoplamento no trecho de assignments e vínculos por identidade

### Validação desta passada

- `php -l backend/src/Helpers/WorkforceAssignmentIdentityHelper.php`
- `php -l backend/src/Controllers/WorkforceController.php`
- `node backend/scripts/workforce_contract_check.mjs`
  - suíte concluída com `OK`
  - contratos mínimos de `tree-status`, `roles`, `event-roles`, `managers`, `assignments`, `sync` e `participants` preservados

### Leitura operacional

- a extração continua incremental e segura:
  - sem alteração de rotas
  - sem alteração de payload
  - sem quebra da trilha já homologada no ambiente local `:8080`
- esta passada fecha o segundo bloco da Fase 1 definido em `docs/progresso9.md`
- a próxima extração segura continua sendo a família de `card issuance`
