# Auditoria técnica — Workforce, banco de dados, integrações e bugs silenciosos

Data: 2026-03-19

## Escopo auditado
- Workforce Ops e Participants Hub.
- Banco de dados operacional e credenciais.
- Fluxos silenciosos de erro.
- Consumidores internos (frontend/PDV/offline) e integrações externas (OpenAI, Gemini, Resend, WhatsApp, gateways).

## Resumo executivo
O produto já avançou bastante no desenho de Workforce, Meals e multi-evento, mas a base ainda mistura **boa modelagem de domínio** com **fragilidades de segurança, observabilidade e consistência operacional**.

### Prioridade imediata
1. Remover segredos hardcoded e parar de persistir credenciais sensíveis em claro.
2. Fechar bugs silenciosos de OTP, sync offline e mensageria.
3. Tornar check-in/check-out e consumo operacional idempotentes e transacionais.
4. Reduzir drift entre a especificação oficial do Participants Hub e o schema real.
5. Implantar trilha de auditoria real para integrações externas e consumidores offline.

---

## 1. Banco de dados e segredos

### Achado 1 — senha do banco está hardcoded no backend
**Evidência**
- `Database.php` ainda define host, usuário e senha default em código, incluindo a senha `070998`. Isso significa que um vazamento do repositório já compromete o ambiente onde esse segredo for reutilizado.

**Consequências**
- Risco direto de acesso indevido ao PostgreSQL.
- Tendência de reaproveitar a mesma senha em homologação/produção.
- Dificulta rotação segura de credenciais e auditoria de incidentes.

**Solução**
- Remover imediatamente defaults sensíveis do código.
- Exigir `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` via `.env`/secret manager.
- Rotacionar a senha atual e revisar logs, dumps e pipelines que possam ter copiado esse valor.

**Cuidados**
- Fazer rotação coordenada com cron/jobs/importadores.
- Garantir fallback explícito: se faltar variável, a API deve falhar no bootstrap com mensagem operacional interna, sem publicar detalhes ao cliente.

### Achado 2 — credenciais de gateway, Resend e WhatsApp estão armazenadas em texto claro
**Evidência**
- O schema mantém `credentials jsonb` em `organizer_payment_gateways`.
- O schema também guarda `resend_api_key`, `wa_token`, `wa_api_url` e `wa_instance` em `organizer_settings`.
- O serviço de gateway persiste os segredos com `json_encode($storedCredentials)` diretamente no banco.
- O fluxo de autenticação OTP lê essas credenciais diretamente da tabela para envio por e-mail e WhatsApp.

**Consequências**
- Qualquer leitura indevida do banco expõe tokens de produção.
- Dump de banco passa a ser incidente de credenciais, não apenas de dados.
- Dificulta segregação entre acesso analítico e acesso operacional.

**Solução**
- Migrar segredos para criptografia em repouso com envelope encryption ou `pgcrypto` com chave fora do banco.
- Separar metadados públicos dos segredos: tabela de configuração visível + cofre/secret store para chaves.
- Mascarar valores também no backend de auditoria/logs e proibir logs de payloads contendo credenciais.

**Cuidados**
- Planejar migração compatível com leitura dupla: campo legado + campo cifrado até completar backfill.
- Revisar políticas de backup, restore e acesso de suporte.

### Achado 3 — OTP fica persistido em claro no banco
**Evidência**
- O fluxo `requestAccessCode()` apaga OTPs anteriores e insere o novo código em `otp_codes` sem hash.
- O schema confirma que `otp_codes.code` é uma coluna textual simples.

**Consequências**
- Um operador com acesso de leitura ao banco consegue autenticar qualquer usuário durante a janela de validade.
- A tabela vira vetor de fraude interna e dificulta compliance.

**Solução**
- Persistir apenas hash do OTP (`sha256` + salt/pepper) e comparar hash na validação.
- Adicionar rate limit por `identifier` + `organizer_id` + IP.
- Registrar tentativas e bloqueios progressivos.

**Cuidados**
- Manter expiração curta e limpeza periódica.
- Não logar OTP em fallback/mocks em ambientes que não sejam estritamente locais.

---

## 2. Drift de modelagem no Workforce / Participants Hub

### Achado 4 — a especificação oficial pede `event_participants` unificado, mas o schema real ainda está fragmentado
**Evidência**
- O documento oficial define `event_participants` como entidade base com `organizer_id`, `name`, `email`, `phone`, `document`, `status`, `qr_code_token` e `metadata`.
- O schema real de `event_participants` tem apenas `event_id`, `person_id`, `category_id`, `status`, `qr_token`, `created_at` e `updated_at`.

**Consequências**
- O domínio depende de joins com `people`, `events`, `participant_categories` e tabelas operacionais para montar uma “pessoa do evento”.
- Aumenta a chance de inconsistência entre cadastro, QR, check-in, meals e workforce import.
- Consumidores externos e relatórios precisam conhecer detalhes internos demais do modelo.

**Solução**
- Definir uma estratégia oficial de convergência:
  - **Opção A:** manter `people` + `event_participants`, mas publicar uma view/materialized view canônica para consumidores.
  - **Opção B:** migrar de fato para `event_participants` mais rico, com colunas denormalizadas controladas.
- Criar contrato canônico do Participants Hub para APIs, exports e analytics.

**Cuidados**
- Não quebrar QR tokens já emitidos.
- Fazer backfill auditável e versionar consumidores que dependem do shape atual.

### Achado 5 — check-in/check-out ainda não está protegido contra corrida e duplicidade operacional
**Evidência**
- `ParticipantCheckinController` conta quantos `check-in` existem e depois insere um novo registro sem lock transacional ou chave de idempotência.
- O status do participante é atualizado para `present` apenas no check-in; o check-out não faz reconciliação de estado.

**Consequências**
- Dois scanners simultâneos podem furar o limite de turnos.
- Um usuário pode ficar eternamente como `present` mesmo após check-out.
- KPIs de presença, atraso, permanência e refeições por turno ficam contaminados.

**Solução**
- Colocar o fluxo em transação com lock do participante ou chave única por janela operacional.
- Criar regra explícita de idempotência por `participant_id + event_day_id + event_shift_id + action`.
- Ajustar status derivado por último evento ou por visão consolidada, não por update simples em `event_participants`.

**Cuidados**
- Se houver operação offline, a idempotência precisa existir também no servidor para replay de lotes.
- Confirmar se `check-out` fora de ordem será permitido ou bloqueado.

### Achado 6 — importação de Workforce está forte na árvore/event role, mas fraca em reconciliação de histórico
**Evidência**
- O import usa `participant_id + sector` para localizar assignment existente e decide entre inserir, reatribuir ou manter.
- O schema de `workforce_assignments` não traz validade temporal, versionamento nem trilha nativa de motivo de mudança.

**Consequências**
- Mudanças de liderança/cargo podem sobrescrever contexto sem preservar histórico operacional fino.
- Custos e presença podem ser atribuídos ao assignment “mais novo”, apagando a narrativa do que aconteceu no evento.

**Solução**
- Introduzir histórico de assignment (`valid_from`, `valid_to`, `change_reason`, `changed_by`).
- Tratar import como reconciliação versionada, não só upsert.
- Gerar relatório de diff antes de aplicar lote grande.

**Cuidados**
- Não perder compatibilidade com o frontend atual.
- Definir claramente quando a troca é correção cadastral versus mudança operacional válida.

---

## 3. Bugs silenciosos e observabilidade

### Achado 7 — OTP pode falhar no provedor e ainda assim responder “Código enviado com sucesso”
**Evidência**
- No fluxo de e-mail, se `sendOTP()` falha, o sistema apenas faz `error_log`.
- No fluxo de WhatsApp, falha HTTP também só gera log.
- Em ambos os casos o endpoint encerra com `jsonSuccess(['success' => true], 'Código enviado com sucesso.')`.

**Consequências**
- Usuário não recebe código, mas a UI acredita que o envio ocorreu.
- Suporte perde rastreabilidade e o problema aparece como “usuário digitou errado”.
- A taxa real de entrega fica invisível.

**Solução**
- Converter falha de envio em estado explícito (`queued`, `sent`, `failed`).
- Criar tabela/outbox de entregas OTP com provider, payload mascarado, status, erro, tempo de resposta e correlation id.
- Só responder sucesso final quando houver confirmação mínima do provider; caso contrário, devolver erro recuperável.

**Cuidados**
- Se optar por fila assíncrona, a UI precisa refletir “pedido recebido” e não “entregue”.
- Em fallback local/mock, exigir `APP_ENV=development`.

### Achado 8 — sync offline retorna `success: true` mesmo em parcial com falhas
**Evidência**
- O controller processa item a item, acumula erros e, se houver falhas, retorna HTTP `207` com corpo contendo `'success' => true`.
- O comentário do próprio código diz “Logar silenciosamente e continuar os próximos”.

**Consequências**
- Consumidores que verificam apenas `success === true` podem marcar o lote como reconciliado.
- Perde-se rastreabilidade de itens órfãos e replays podem virar duplicidade operacional.
- Financeiro, estoque e meals podem divergir entre PDV e backoffice.

**Solução**
- Mudar contrato para `success: false` em parcial, ou criar `status: partial_failure` explícito.
- Persistir lote e itens em tabela de sync com estado por item (`received`, `processed`, `failed`, `deduplicated`).
- Expor endpoint de reconciliação/replay administrativo.

**Cuidados**
- Revisar frontend e consumidores offline antes de mudar o contrato.
- Criar feature flag se houver apps já publicados.

### Achado 9 — mensageria não tem histórico real nem webhook útil
**Evidência**
- `/api/messaging/history` retorna array vazio “por enquanto”.
- O webhook atual responde apenas `webhook recebido`, sem persistência nem validação.

**Consequências**
- Não existe trilha de entrega, leitura, rejeição ou erro por destinatário.
- Não há como fechar o ciclo de observabilidade das integrações.
- Troubleshooting vira análise manual de log externo.

**Solução**
- Criar `message_deliveries` / `integration_events` com correlation id, provider, payload mascarado e status.
- Validar assinatura de webhook e persistir os eventos recebidos.
- Expor histórico por organizador/evento/campanha.

**Cuidados**
- Separar dados operacionais de conteúdo sensível da mensagem.
- Definir retenção de histórico e LGPD.

### Achado 10 — `EmailService` depende de `$GLOBALS['year']` no template OTP
**Evidência**
- O footer do HTML OTP usa `{$GLOBALS['year']}` diretamente.
- O e-mail manual já usa fallback seguro com `date('Y')`, mas o OTP não.

**Consequências**
- Em chamadas futuras fora do fluxo atual, o template pode sair com footer vazio ou gerar warning.
- É bug pequeno, mas típico de fragilidade silenciosa em serviço compartilhado.

**Solução**
- Eliminar dependência de estado global; calcular o ano dentro do próprio serviço.
- Padronizar todos os serviços de integração para serem puros e previsíveis.

**Cuidados**
- Revisar outros usos de `$GLOBALS` e singletons com estado implícito.

---

## 4. Integrações externas

### Achado 11 — integração de IA está inconsistente e com risco operacional
**Evidência**
- `AIController` fala em “Google Gemini”, mas usa `OPENAI_API_KEY`, endpoint de `chat/completions` e desabilita `CURLOPT_SSL_VERIFYPEER`.
- `GeminiService` retorna erro em texto puro em vez de exceção/objeto estruturado e grava billing sempre com `event_id = 1`.

**Consequências**
- Telemetria de IA fica errada para todos os eventos.
- Erros podem vazar para usuário como texto “quase sucesso”, sem tipagem clara.
- Desligar verificação SSL abre risco real de MITM em ambientes mal configurados.

**Solução**
- Separar claramente `OpenAIService` e `GeminiService`, cada um com contrato próprio.
- Remover `CURLOPT_SSL_VERIFYPEER => false` e corrigir cadeia de certificados do ambiente.
- Tornar billing contextual: `event_id`, `organizer_id`, `user_id`, modelo, custo estimado, request id.
- Retornar objetos estruturados de erro e sucesso.

**Cuidados**
- Fazer rollout com observabilidade de timeout, taxa de erro e custo por tenant.
- Não misturar nome de provider, chave e endpoint no mesmo controller.

### Achado 12 — integrações de WhatsApp e e-mail são síncronas e sem fila de retry
**Evidência**
- Os envios são feitos diretamente por cURL dentro da requisição HTTP do usuário.
- Não há outbox, retry exponencial, dead-letter nem timeout diferenciado por provider.

**Consequências**
- Picos de latência do provider impactam a experiência do painel.
- Retries manuais do usuário podem duplicar disparos.
- Falhas transitórias viram perda definitiva de mensagem.

**Solução**
- Adotar outbox + worker assíncrono.
- Definir política de retry por provider e tipo de mensagem.
- Persistir idempotency key por envio.

**Cuidados**
- Campanhas em massa e OTP têm requisitos diferentes; separar pipelines.
- Garantir limitação por tenant para evitar abuso e custo descontrolado.

---

## 5. Consumidores internos e impacto sistêmico

### Consumidores mais expostos hoje
1. **PDVs offline / sync** — maior risco de divergência silenciosa.
2. **Scanner / check-in operacional** — risco de corrida e contagem inflada.
3. **Workforce import** — risco de reatribuição sem histórico.
4. **Painel de mensageria** — risco de falso positivo de entrega.
5. **Dashboards de IA e billing** — risco de telemetria incorreta.

### Impactos cruzados
- Workforce errado contamina meals, custos por setor, presença e payroll operacional.
- Sync parcial contamina financeiro, estoque e auditoria.
- Segredos em claro ampliam o blast radius de qualquer incidente de banco.
- Falta de histórico de integração impede provar o que foi enviado, recebido ou rejeitado.

---

## 6. Plano de ação recomendado

### Fase 0 — 24/48 horas
- Rotacionar senha do banco e remover default hardcoded.
- Bloquear novos segredos em claro e mapear onde cada token está armazenado.
- Corrigir OTP para não retornar sucesso falso em falha do provider.
- Classificar sync parcial como erro operacional explícito.
- Remover `SSL_VERIFYPEER=false`.

### Fase 1 — 3 a 7 dias
- Criar tabelas de outbox / delivery / integration_events.
- Hash de OTP + rate limit + trilha de tentativas.
- Idempotência para check-in/check-out e replay offline.
- Telemetria canônica de IA e mensageria por tenant/evento.

### Fase 2 — 1 a 2 sprints
- Fechar a convergência do Participants Hub.
- Versionar assignments de workforce.
- Cifrar credenciais antigas e fazer backfill completo.
- Criar dashboards de reconciliação: sync, OTP, mensageria, IA, workforce imports.

---

## 7. Ordem de criticidade

### Crítico
- Senha hardcoded do banco.
- Segredos sensíveis em texto claro.
- OTP armazenado em claro.
- SSL desabilitado na integração de IA.
- Sync parcial com `success=true`.

### Alto
- Check-in sem proteção contra corrida.
- Mensageria sem histórico/outbox.
- Telemetria de IA com `event_id=1` fixo.
- Drift entre schema e especificação de Participants Hub.

### Médio
- Dependência de `$GLOBALS['year']`.
- Importação de workforce sem histórico versionado.

---

## Conclusão
A base do produto está **mais madura em regra de negócio do que em confiabilidade operacional**. O maior risco hoje não é só “um bug visível”, e sim o conjunto de **falhas silenciosas** que deixam painel, scanners, PDVs e integrações acreditando que tudo deu certo quando o estado real já divergiu.

Se eu fosse priorizar como owner técnico, eu atacaria nesta ordem:
1. segredos e banco,
2. OTP/sync/check-in,
3. observabilidade de integrações,
4. convergência do modelo canônico de participantes/workforce.
