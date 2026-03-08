# EnjoyFun — Modelagem Oficial do Banco v1

## Objetivo
Definir a espinha dorsal da base de dados da EnjoyFun, separando:
- o que permanece do modelo atual
- o que precisa ser corrigido
- o que deve entrar agora
- o que entra em fases seguintes

A modelagem parte da visão oficial do produto: SaaS white label multi-tenant para organizadores de eventos, com operação completa, canais por tenant, IA por tenant, financeiro por tenant, PWA/WhatsApp e dashboards por persona.

---

## 1. Princípios da modelagem

1. Toda entidade operacional relevante deve respeitar `organizer_id`.
2. `organizer_id` nunca deve ser confiado a partir do body da requisição.
3. O banco deve refletir separação clara entre:
   - núcleo operacional
   - white label
   - canais
   - IA
   - financeiro
   - participantes
   - analytics
4. Eventos multi-dia devem ser entidade nativa.
5. Participantes devem evoluir para uma estrutura unificada.
6. O banco deve servir operação em tempo real e também analytics.
7. O modelo deve reduzir retrabalho e duplicação futura.

---

## 2. Tabelas atuais que permanecem

Estas tabelas continuam sendo parte do núcleo do sistema.

### 2.1 Eventos e usuários
- `events`
- `users`
- `roles`
- `user_roles`
- `refresh_tokens`
- `audit_log`
- `ai_usage_logs`

### 2.2 Ingressos
- `ticket_types`
- `tickets`

### 2.3 Vendas e operação
- `products`
- `sales`
- `sale_items`
- `vendors`
- `offline_queue`

### 2.4 Cashless
- `digital_cards`
- `card_transactions`

### 2.5 Estacionamento
- `parking_records`

### 2.6 White label atual
- `organizer_settings`

### 2.7 Guest atual
- `guests`

---

## 3. Tabelas atuais que precisam ser fortalecidas

## 3.1 `events`
### Manter
- id
- organizer_id
- nome, slug, descrição
- datas principais
- status
- venue

### Melhorar
- reforçar índices por `organizer_id`, `status`, `starts_at`
- tratar multi-dia com apoio de tabelas auxiliares, não apenas `starts_at`/`ends_at`

---

## 3.2 `users`
### Manter
- identidade básica do usuário
- organizer_id
- role e sector enquanto houver transição

### Melhorar
- evitar depender apenas de `role` dentro da tabela quando RBAC crescer
- preparar caminho para permissões mais granulares

---

## 3.3 `products`
### Manter
- event_id
- organizer_id
- name
- price
- stock_qty
- sector
- low_stock_threshold

### Melhorar
- reforçar papel de `sector` como dimensão oficial
- preparar futura relação com terminal, operador e shift

---

## 3.4 `sales`
### Manter
- event_id
- organizer_id
- total_amount
- status
- offline_id
- vendor_id
- sector
- operator_id

### Melhorar
- incluir futuramente `payment_provider`
- incluir `payment_channel`
- incluir `source` (online, offline, synced)
- incluir `shift_id` e `pos_terminal_id`

---

## 3.5 `digital_cards` e `card_transactions`
### Manter
Base atual é válida.

### Melhorar
- reforçar ledger de transação
- preparar conciliação e referência transacional com gateways

---

## 3.6 `organizer_settings`
### Manter
Como tabela central de branding.

### Melhorar
Reduzir acúmulo de responsabilidades. Ela não deve concentrar branding, canais, IA e financeiro tudo junto.

---

## 3.7 `guests`
### Manter
Enquanto módulo Guest atual segue funcionando.

### Melhorar
Não expandir para workforce. Ela deve ser vista como estrutura transitória dentro da evolução para Participants Hub.

---

## 4. Novas tabelas que entram agora (fase estrutural)

Estas tabelas devem ser introduzidas na fase atual ou imediatamente seguinte.

## 4.1 White label e tenant config

### `organizer_channels`
Responsável pelos canais do organizador.

Campos sugeridos:
- `id`
- `organizer_id`
- `provider` — ex.: resend, zapi, evolution
- `credentials_encrypted`
- `config_json`
- `is_active`
- `is_primary`
- `webhook_url`
- `created_at`
- `updated_at`

### Objetivo
Separar canais de `organizer_settings`.

---

### `organizer_ai_config`
Responsável pela configuração de IA do organizador.

Campos sugeridos:
- `organizer_id` (PK ou unique)
- `provider`
- `api_key_encrypted`
- `model_name`
- `agents_enabled`
- `context_text`
- `limits_json`
- `created_at`
- `updated_at`

### Objetivo
Separar IA de branding e canais.

---

### `organizer_payment_gateways`
Responsável pelos gateways do organizador.

Campos sugeridos:
- `id`
- `organizer_id`
- `provider` — mercado_pago, pagseguro, asaas, pagarme, infinitypay
- `credentials_encrypted`
- `config_json`
- `is_active`
- `is_primary`
- `environment`
- `created_at`
- `updated_at`

### Objetivo
Dar ao organizador uma infraestrutura própria de gateway dentro do tenant.

---

### `organizer_financial_settings`
Responsável pelas preferências financeiras do tenant.

Campos sugeridos:
- `organizer_id`
- `commission_rate`
- `settlement_preferences`
- `payout_info`
- `created_at`
- `updated_at`

### Objetivo
Separar a política financeira do tenant da configuração visual ou operacional.

---

## 4.2 Eventos multi-dia e turnos

### `event_days`
Campos sugeridos:
- `id`
- `event_id`
- `day_number`
- `label`
- `starts_at`
- `ends_at`
- `created_at`

### Objetivo
Modelar festivais e eventos longos como entidade nativa.

---

### `event_shifts`
Campos sugeridos:
- `id`
- `event_id`
- `event_day_id`
- `name`
- `sector`
- `starts_at`
- `ends_at`
- `is_active`
- `created_at`

### Objetivo
Dar suporte a turnos operacionais.

---

## 4.3 Participants Hub (base inicial)

### `participant_categories`
Catálogo oficial das categorias.

Campos sugeridos:
- `id`
- `code`
- `name`
- `description`
- `is_workforce`
- `created_at`

Categorias iniciais sugeridas:
- guest
- artist
- dj
- staff
- permuta
- food_staff
- production
- parking
- vendor_staff

---

### `event_participants`
Base unificada de pessoas vinculadas ao evento.

Campos sugeridos:
- `id`
- `organizer_id`
- `event_id`
- `category_id`
- `name`
- `email`
- `phone`
- `document`
- `status`
- `qr_code_token`
- `metadata`
- `created_at`
- `updated_at`

### Objetivo
Permitir que Guest e Workforce evoluam para uma base comum.

---

### `workforce_assignments`
Relação de participante com cargo, setor e turno.

Campos sugeridos:
- `id`
- `participant_id`
- `role_name`
- `sector`
- `shift_id`
- `meal_allowance_per_day`
- `valid_from`
- `valid_until`
- `metadata`
- `created_at`
- `updated_at`

### Objetivo
Sustentar Workforce Ops sem poluir Guest.

---

### `participant_checkins`
Entradas e saídas de participantes.

Campos sugeridos:
- `id`
- `participant_id`
- `event_id`
- `shift_id`
- `check_type` — in / out
- `gate`
- `checked_at`
- `operator_id`
- `metadata`

### Objetivo
Rastrear presença e permanência.

---

### `participant_meals`
Controle de refeições.

Campos sugeridos:
- `id`
- `participant_id`
- `event_id`
- `event_day_id`
- `shift_id`
- `meal_type`
- `consumed_at`
- `operator_id`
- `metadata`

### Objetivo
Controlar alimentação por pessoa/dia/turno.

---

## 5. Tabelas que entram depois (fase premium)

## 5.1 Analytics

### `dashboard_snapshots`
Materialização de métricas para performance.

Campos sugeridos:
- `id`
- `organizer_id`
- `event_id`
- `snapshot_type`
- `snapshot_key`
- `payload_json`
- `captured_at`

---

### `alerts`
Sistema de alertas operacionais.

Campos sugeridos:
- `id`
- `organizer_id`
- `event_id`
- `alert_type`
- `severity`
- `status`
- `payload_json`
- `created_at`
- `resolved_at`

---

### `ai_runs`
Rastreamento de execuções de agentes.

Campos sugeridos:
- `id`
- `organizer_id`
- `event_id`
- `agent_name`
- `provider`
- `input_summary`
- `output_summary`
- `tokens_used`
- `estimated_cost`
- `created_at`

---

## 5.2 Comercial e performance

### `ticket_batches`
Para análise por lote.

### `commissaries`
Para controle e análise por comissário.

### `ticket_commissions`
Para rastrear comissões por venda ou referência.

---

## 5.3 Operação física

### `pos_terminals`
Para gestão de terminais.

### `parking_gates`
Para gestão de entradas/saídas do estacionamento.

---

## 6. Relações oficiais entre as camadas

### Tenant
- `users` → organizador
- `organizer_settings`
- `organizer_channels`
- `organizer_ai_config`
- `organizer_payment_gateways`
- `organizer_financial_settings`

### Evento
- `events`
- `event_days`
- `event_shifts`
- `tickets`
- `sales`
- `parking_records`
- `event_participants`

### Participantes
- `participant_categories`
- `event_participants`
- `workforce_assignments`
- `participant_checkins`
- `participant_meals`

### Operação de vendas
- `products`
- `sales`
- `sale_items`
- `digital_cards`
- `card_transactions`

### Analytics
- `dashboard_snapshots`
- `alerts`
- `ai_runs`

---

## 7. Índices prioritários

Além dos índices já existentes, recomenda-se priorizar:

### Índices por tenant e evento
- `events (organizer_id, starts_at)`
- `tickets (organizer_id, event_id, status)`
- `sales (organizer_id, event_id, created_at)`
- `products (organizer_id, event_id, sector)`
- `parking_records (organizer_id, event_id, status)`
- `event_participants (organizer_id, event_id, category_id, status)`

### Índices operacionais
- `participant_checkins (event_id, checked_at)`
- `participant_meals (event_id, consumed_at)`
- `workforce_assignments (shift_id, sector)`
- `organizer_payment_gateways (organizer_id, provider, is_active)`

---

## 8. Migração recomendada

## Etapa 1
- consolidar schema atual
- introduzir `organizer_channels`
- introduzir `organizer_ai_config`
- introduzir `organizer_payment_gateways`
- introduzir `organizer_financial_settings`
- introduzir `event_days`
- introduzir `event_shifts`

## Etapa 2
- introduzir `participant_categories`
- introduzir `event_participants`
- introduzir `workforce_assignments`
- introduzir `participant_checkins`
- introduzir `participant_meals`
- manter `guests` funcionando em paralelo na transição

## Etapa 3
- introduzir `dashboard_snapshots`
- introduzir `alerts`
- introduzir `ai_runs`
- introduzir tabelas de lote/comissário/terminal

---

## 9. O que não fazer

1. Não colocar tudo novo dentro de `organizer_settings`.
2. Não tentar resolver Workforce só com a tabela `guests`.
3. Não criar evento multi-dia apenas com lógica de frontend.
4. Não criar dashboards analíticos grandes sem preparar snapshots.
5. Não crescer a camada financeira sem separar gateway por tenant.

---

## 10. Resultado esperado

Ao seguir esta modelagem oficial, a EnjoyFun terá uma base de dados preparada para:
- white label real por organizador
- canais próprios por tenant
- IA por tenant
- gateways por tenant
- eventos multi-dia
- participants hub
- workforce ops
- dashboards por persona
- analytics e inteligência operacional em fases

