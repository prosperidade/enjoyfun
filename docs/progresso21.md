# Progresso 21 - Inventario de paginacao operacional

**Data:** 2026-04-09
**Sprint:** Prontidao operacional - escalabilidade de listagens
**Autor:** Codex

---

## Resumo executivo

Esta passada nao implementou paginacao ainda.

Ela fechou o inventario objetivo das listagens do sistema para decidir onde vale mexer agora e onde seria so churn sem ganho operacional.

Conclusao direta:

- sim, precisamos de paginacao real alem de `participants`, `tickets` e `cards`
- `parking`, financeiro, historico de mensagens, `workforce assignments`, `meals`, `organizer-files` e `payment_charges` entram no mesmo pacote
- o modulo de artistas ja esta em bom estado e nao deve entrar nessa sprint
- convidados tambem ja possuem paginacao real e nao precisam ser refeitos

---

## Evidencias lidas

- `backend/src/Controllers/ParticipantController.php`
- `backend/src/Controllers/TicketController.php`
- `backend/src/Controllers/CardController.php`
- `backend/src/Controllers/ParkingController.php`
- `backend/src/Controllers/MessagingController.php`
- `backend/src/Services/MessagingDeliveryService.php`
- `backend/src/Controllers/EventFinancePayableController.php`
- `backend/src/Controllers/EventFinancePaymentController.php`
- `backend/src/Controllers/EventFinanceAttachmentController.php`
- `backend/src/Controllers/EventFinanceImportController.php`
- `backend/src/Controllers/OrganizerFileController.php`
- `backend/src/Controllers/MealController.php`
- `backend/src/Helpers/WorkforceAssignmentsManagerHelper.php`
- `backend/src/Services/PaymentGatewayService.php`
- `backend/src/Helpers/ArtistCatalogBookingHelper.php`
- `backend/src/Controllers/GuestController.php`
- `backend/src/Helpers/Response.php`
- `frontend/src/pages/Tickets.jsx`
- `frontend/src/pages/Cards.jsx`
- `frontend/src/pages/Parking.jsx`
- `frontend/src/pages/Messaging.jsx`
- `frontend/src/pages/EventFinancePayables.jsx`
- `frontend/src/components/Pagination.jsx`

Consultas diretas ao PostgreSQL local confirmaram a cardinalidade operacional atual:

- `event_participants = 437`
- `workforce_assignments = 381`
- `financial_import_rows = 361`
- `tickets = 160`
- `card_transactions = 84`
- `participant_meals = 59`
- `parking_records = 26`
- `message_deliveries = 10`
- `artists = 2`
- `event_artists = 2`

---

## Classificacao

### Entram no pacote unico agora

- `participants`
- `tickets`
- `cards`
- `cards/{id}/transactions`
- `parking`
- `workforce assignments`
- `event-finance/payables`
- `event-finance/payments`
- `event-finance/attachments`
- `event-finance/import/{batch}`
- `messaging/history`
- `organizer-files`
- `payment_charges`
- `meals`

Motivo:

- hoje sao endpoints com `fetchAll()` ou apenas `LIMIT` sem `page/total`
- eles crescem por evento e sao superfices operacionais, nao tabelas de configuracao
- o frontend atual ainda trata a maioria deles como array completo, entao a migracao precisa ser coordenada de ponta a ponta

### Ficam para uma segunda onda

- `users`
- `suppliers`
- `supplier contracts`
- `ai_agent_executions`
- memorias e reports de IA

Motivo:

- sao relevantes, mas nao entram no corte minimo para apresentar operacao de evento com menos risco nos proximos 20 dias

### Nao entram agora

- artistas
- convidados
- `events`
- `ticket_types`
- `ticket_batches`
- `participant_categories`
- `event_cost_categories`
- `event_cost_centers`
- `event_days`
- `event_shifts`
- `budgets`

Motivo:

- artistas e convidados ja tem paginacao real
- o restante e configuracao ou tabela naturalmente pequena no contexto operacional atual

---

## Decisao tecnica

O pacote unico deve padronizar o backend em um unico contrato:

- `data`: itens da pagina atual
- `meta.total`
- `meta.page`
- `meta.per_page`
- `meta.total_pages`

Esse formato ja existe em `backend/src/Helpers/Response.php`.

Evitar:

- manter endpoints novos em array puro
- criar mais um formato de paginacao diferente de artistas, convidados e `Response::paginated`
- paginar no frontend com carga completa no backend

---

## Proximo passo recomendado

Implementar a frente de paginacao em uma unica rodada, por camadas:

1. backend compartilhado:
   - helper de normalizacao `page/per_page`
   - helper de resposta paginada unico
2. dominio operacional principal:
   - `participants`
   - `tickets`
   - `cards`
   - `parking`
   - `messaging`
3. dominio financeiro e anexos:
   - `payables`
   - `payments`
   - `attachments`
   - `import rows`
   - `payment_charges`
4. apoio operacional:
   - `workforce assignments`
   - `meals`
   - `organizer-files`
5. frontend:
   - adaptar telas para ler `meta`
   - ligar `frontend/src/components/Pagination.jsx`
   - manter compatibilidade minima com filtros existentes

Para o prazo de apresentacao em 20 dias, essa e a sprint certa. Reescrever artistas ou paginar tabelas pequenas agora seria desvio.
