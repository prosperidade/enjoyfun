# 91 — Integrações Aproveitadas

> Registro explícito do que os módulos novos reaproveitam e do que não reaproveitam.

---

## 1. Cartões e consumação

### Reaproveita
- `digital_cards`
- `card_transactions`
- `event_card_assignments`

### Não cria
- `artist_cards`
- `artist_card_transactions`

### Regra
Se faltar vínculo com artista/equipe, ajustar `event_card_assignments` ou camada equivalente.

---

## 2. Financeiro do organizer

### Reaproveita
- infraestrutura de organizer, quando aplicável
- settings/gateways do organizer como base técnica

### Não reaproveita como ledger operacional do evento
- `OrganizerFinanceController`
- contas a pagar/pagamentos do organizer como domínio do evento

### Regra
O módulo novo de financeiro do evento vive em `/event-finance` com tabelas próprias.

---

## 3. Autenticação e contexto

### Reaproveita
- JWT existente
- contexto de organizer
- convenções atuais de resposta da API

---

## 4. Frontend

### Reaproveita
- padrão visual já existente
- abordagem em React `.jsx`
- referência estrutural de `WorkforceOpsTab.jsx`
