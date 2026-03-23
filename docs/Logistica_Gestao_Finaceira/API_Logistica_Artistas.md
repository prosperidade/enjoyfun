# API — Módulo 1: Logística Operacional de Artistas

> Stack: Node.js / TypeScript  
> Base URL: `/api/v1`  
> Autenticação: Bearer JWT em todos os endpoints  
> Multi-tenant: `organizer_id` extraído do JWT (nunca enviado pelo cliente)  
> Convenção: todos os timestamps em ISO 8601 UTC

---

## Índice

1. [Artistas](#1-artistas)
2. [Vínculo Artista × Evento](#2-vínculo-artista--evento)
3. [Logística Operacional](#3-logística-operacional)
4. [Itens de Logística](#4-itens-de-logística)
5. [Linha do Tempo / Janela Apertada](#5-linha-do-tempo--janela-apertada)
6. [Estimativas de Deslocamento](#6-estimativas-de-deslocamento)
7. [Alertas Operacionais](#7-alertas-operacionais)
8. [Equipe do Artista](#8-equipe-do-artista)
9. [Cartões de Consumação](#9-cartões-de-consumação)
10. [Transações de Cartão](#10-transações-de-cartão)
11. [Arquivos e Anexos](#11-arquivos-e-anexos)
12. [Importação CSV / XLSX](#12-importação-csv--xlsx)

---

## 1. Artistas

### `GET /artists`
Lista todos os artistas do organizer.

**Query params:**
```
search?: string          — busca por stage_name ou legal_name
genre?: string
is_active?: boolean
page?: number (default: 1)
limit?: number (default: 20)
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "stage_name": "Artista XYZ",
      "legal_name": "João da Silva",
      "document_type": "cpf",
      "document_number": "***.***.***-**",
      "phone": "+55 11 99999-9999",
      "email": "contato@artistaxyz.com",
      "manager_name": "Maria Santos",
      "manager_email": "maria@mgmt.com",
      "nationality": "BR",
      "genre": "sertanejo",
      "created_at": "2025-01-01T00:00:00Z"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 20,
    "total": 150,
    "total_pages": 8
  }
}
```

---

### `POST /artists`
Cria um novo artista.

**Request body:**
```json
{
  "stage_name": "Artista XYZ",           // required
  "legal_name": "João da Silva",          // required
  "document_type": "cpf",                 // required: cpf | cnpj | passport
  "document_number": "123.456.789-00",    // required
  "phone": "+55 11 99999-9999",
  "email": "contato@artistaxyz.com",
  "manager_name": "Maria Santos",
  "manager_phone": "+55 11 98888-8888",
  "manager_email": "maria@mgmt.com",
  "nationality": "BR",
  "genre": "sertanejo",
  "notes": "Observações gerais"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "stage_name": "Artista XYZ",
    ...
    "created_at": "2025-01-01T00:00:00Z"
  }
}
```

**Erros:**
```json
// 409 — documento já cadastrado
{ "error": "ARTIST_DOCUMENT_CONFLICT", "message": "Já existe um artista com este documento." }

// 422 — validação
{ "error": "VALIDATION_ERROR", "fields": { "stage_name": "Campo obrigatório." } }
```

---

### `GET /artists/:artistId`
Retorna um artista pelo ID.

**Response 200:** objeto completo do artista.

**Erros:**
```json
// 404
{ "error": "ARTIST_NOT_FOUND" }
```

---

### `PATCH /artists/:artistId`
Atualiza campos do artista (partial update).

**Request body:** qualquer subconjunto dos campos de `POST /artists`.

**Response 200:** objeto atualizado.

---

### `DELETE /artists/:artistId`
Inativa o artista (soft delete).

**Response 204:** sem body.

**Erros:**
```json
// 409 — artista vinculado a evento ativo
{ "error": "ARTIST_HAS_ACTIVE_EVENTS", "message": "Remova o artista dos eventos ativos antes de inativar." }
```

---

## 2. Vínculo Artista × Evento

### `GET /events/:eventId/artists`
Lista todos os artistas do evento com dados consolidados.

**Query params:**
```
stage?: string
status?: confirmed | pending | cancelled
performance_date?: string (YYYY-MM-DD)
alert_severity?: green | yellow | orange | red | gray
page?: number
limit?: number
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",                        // event_artist id
      "event_id": "uuid",
      "artist_id": "uuid",
      "artist": {
        "id": "uuid",
        "stage_name": "Artista XYZ",
        "manager_name": "Maria Santos",
        "manager_phone": "+55 11 98888-8888"
      },
      "performance_date": "2025-06-15",
      "performance_time": "22:00",
      "performance_duration_min": 60,
      "soundcheck_time": "18:00",
      "stage": "Palco Principal",
      "status": "confirmed",
      "cache_amount": 15000.00,
      "currency": "BRL",
      "payment_status": "pending",
      "operational_alert": {
        "severity": "orange",
        "color_status": "orange",
        "message": "Buffer de chegada menor que 15 minutos"
      },
      "logistics_status": "complete",
      "consumption_total": 500.00
    }
  ],
  "meta": { "page": 1, "limit": 20, "total": 12 }
}
```

---

### `POST /events/:eventId/artists`
Vincula um artista ao evento.

**Request body:**
```json
{
  "artist_id": "uuid",                    // required
  "performance_date": "2025-06-15",       // required
  "performance_time": "22:00",            // required
  "performance_duration_min": 60,
  "soundcheck_time": "18:00",
  "dressing_room_ready_time": "19:00",
  "stage": "Palco Principal",
  "status": "confirmed",
  "cache_amount": 15000.00,
  "currency": "BRL",
  "payment_status": "pending",
  "contract_number": "CT-2025-001",
  "notes": ""
}
```

**Response 201:** objeto criado.

**Erros:**
```json
// 409 — artista já vinculado ao evento
{ "error": "ARTIST_ALREADY_IN_EVENT" }
```

---

### `GET /events/:eventId/artists/:eventArtistId`
Retorna o detalhe completo do artista no evento, incluindo logística, alertas e custos consolidados.

**Response 200:**
```json
{
  "data": {
    "event_artist": { ...campos do vínculo },
    "artist": { ...dados do artista },
    "logistics": { ...artist_logistics },
    "operational_timeline": { ...timeline },
    "alerts": [ ...alertas ativos ],
    "cards": [ ...cartões emitidos ],
    "files": [ ...arquivos ],
    "cost_summary": {
      "cache_amount": 15000.00,
      "logistics_total": 3200.00,
      "consumption_total": 500.00,
      "grand_total": 18700.00
    }
  }
}
```

---

### `PATCH /events/:eventId/artists/:eventArtistId`
Atualiza dados do vínculo (horário, status, cachê etc.).

**Request body:** subconjunto dos campos de `POST /events/:eventId/artists`.

**Response 200:** objeto atualizado.

---

### `DELETE /events/:eventId/artists/:eventArtistId`
Remove o artista do evento.

**Response 204.**

**Erros:**
```json
// 409 — já há pagamentos registrados
{ "error": "ARTIST_HAS_PAYMENTS", "message": "Cancele os pagamentos antes de remover o artista do evento." }
```

---

## 3. Logística Operacional

### `GET /events/:eventId/artists/:eventArtistId/logistics`
Retorna o bloco de logística geral do artista no evento.

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "event_id": "uuid",
    "artist_id": "uuid",
    "arrival_date": "2025-06-15",
    "arrival_time": "16:00",
    "departure_date": "2025-06-16",
    "departure_time": "00:30",
    "hotel_name": "Hotel Grand",
    "hotel_address": "Rua das Flores, 100 — São Paulo, SP",
    "hotel_checkin": "2025-06-15T16:30:00Z",
    "hotel_checkout": "2025-06-16T12:00:00Z",
    "rooming_notes": "Suite dupla, andar alto",
    "dressing_room_notes": "Precisa de espelho iluminado",
    "hospitality_notes": "Rider: 2 caixas de água, frutas",
    "local_transport_notes": "Van para 6 pessoas",
    "airport_transfer_notes": "Transfer do GRU às 16h"
  }
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/logistics`
Cria o bloco de logística (apenas 1 por artista × evento).

**Request body:**
```json
{
  "arrival_date": "2025-06-15",           // required
  "arrival_time": "16:00",               // required
  "departure_date": "2025-06-16",
  "departure_time": "00:30",
  "hotel_name": "Hotel Grand",
  "hotel_address": "Rua das Flores, 100 — São Paulo, SP",
  "hotel_checkin": "2025-06-15T16:30:00Z",
  "hotel_checkout": "2025-06-16T12:00:00Z",
  "rooming_notes": "",
  "dressing_room_notes": "",
  "hospitality_notes": "",
  "local_transport_notes": "",
  "airport_transfer_notes": ""
}
```

**Response 201:** objeto criado.

**Erros:**
```json
// 409 — logística já existe para este vínculo
{ "error": "LOGISTICS_ALREADY_EXISTS", "message": "Use PATCH para atualizar a logística existente." }
```

---

### `PATCH /events/:eventId/artists/:eventArtistId/logistics`
Atualiza o bloco de logística.

**Request body:** subconjunto dos campos de POST.

**Response 200:** objeto atualizado.

---

## 4. Itens de Logística

### `GET /events/:eventId/artists/:eventArtistId/logistics/items`
Lista os itens detalhados de logística do artista.

**Query params:**
```
logistics_type?: airfare | bus | hotel | transfer | local_transport | dressing_room | hospitality | rider | other
status?: pending | paid | cancelled
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "logistics_type": "airfare",
      "supplier_id": "uuid",
      "supplier_name": "Gol Linhas Aéreas",
      "description": "Voo GRU → VCP — 15/06 16:00",
      "quantity": 1,
      "unit_amount": 850.00,
      "total_amount": 850.00,
      "due_date": "2025-06-01",
      "paid_at": null,
      "status": "pending"
    }
  ],
  "summary": {
    "total_items": 5,
    "total_amount": 4200.00,
    "total_paid": 850.00,
    "total_pending": 3350.00
  }
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/logistics/items`
Adiciona um item de logística.

**Request body:**
```json
{
  "logistics_type": "airfare",            // required
  "supplier_id": "uuid",
  "description": "Voo GRU → VCP — 15/06 16:00",  // required
  "quantity": 1,                          // required, min: 1
  "unit_amount": 850.00,                  // required, min: 0
  "due_date": "2025-06-01",
  "notes": ""
}
```

> `total_amount` é calculado automaticamente: `quantity × unit_amount`

**Response 201:** objeto criado com `total_amount`.

---

### `PATCH /events/:eventId/artists/:eventArtistId/logistics/items/:itemId`
Atualiza um item.

**Response 200:** objeto atualizado.

---

### `DELETE /events/:eventId/artists/:eventArtistId/logistics/items/:itemId`
Remove um item de logística.

**Response 204.**

**Erros:**
```json
// 409 — item já pago
{ "error": "ITEM_ALREADY_PAID", "message": "Não é possível remover um item já pago." }
```

---

## 5. Linha do Tempo / Janela Apertada

### `GET /events/:eventId/artists/:eventArtistId/timeline`
Retorna a linha do tempo operacional com janelas calculadas e alertas.

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "previous_commitment_type": "event",
    "previous_commitment_label": "Festival ABC — São Paulo",
    "previous_city": "São Paulo, SP",
    "arrival_mode": "airplane",
    "arrival_airport": "GRU",
    "arrival_datetime": "2025-06-15T18:10:00Z",
    "hotel_checkin_datetime": "2025-06-15T19:00:00Z",
    "venue_arrival_datetime": "2025-06-15T20:30:00Z",
    "soundcheck_datetime": "2025-06-15T21:00:00Z",
    "dressing_room_ready_datetime": "2025-06-15T21:30:00Z",
    "performance_start_datetime": "2025-06-15T22:00:00Z",
    "performance_end_datetime": "2025-06-15T23:00:00Z",
    "venue_departure_datetime": "2025-06-15T23:20:00Z",
    "next_commitment_type": "airport",
    "next_commitment_label": "Voo para Rio de Janeiro",
    "next_city": "Rio de Janeiro, RJ",
    "next_destination": "Aeroporto GIG",
    "next_departure_deadline": "2025-06-16T00:10:00Z",
    "notes": "",
    "calculated_windows": {
      "arrival_to_soundcheck_minutes": 110,
      "arrival_to_performance_minutes": 230,
      "performance_end_to_departure_minutes": 20,
      "departure_to_next_deadline_minutes": 50
    },
    "risk_summary": {
      "arrival_risk": "green",
      "departure_risk": "orange",
      "overall_severity": "orange"
    }
  }
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/timeline`
Cria a linha do tempo (apenas 1 por artista × evento). Dispara cálculo automático de alertas.

**Request body:**
```json
{
  "previous_commitment_type": "event",       // required: event | hotel | airport | other
  "previous_commitment_label": "Festival ABC",
  "previous_city": "São Paulo, SP",
  "arrival_mode": "airplane",                // required: airplane | bus | car | helicopter | other
  "arrival_airport": "GRU",
  "arrival_datetime": "2025-06-15T18:10:00Z",  // required
  "hotel_checkin_datetime": "2025-06-15T19:00:00Z",
  "venue_arrival_datetime": "2025-06-15T20:30:00Z",
  "soundcheck_datetime": "2025-06-15T21:00:00Z",
  "dressing_room_ready_datetime": "2025-06-15T21:30:00Z",
  "performance_start_datetime": "2025-06-15T22:00:00Z",  // required
  "performance_end_datetime": "2025-06-15T23:00:00Z",    // required
  "venue_departure_datetime": "2025-06-15T23:20:00Z",
  "next_commitment_type": "airport",
  "next_commitment_label": "Voo para Rio de Janeiro",
  "next_city": "Rio de Janeiro, RJ",
  "next_destination": "Aeroporto GIG",
  "next_departure_deadline": "2025-06-16T00:10:00Z",
  "notes": ""
}
```

**Response 201:** objeto criado + alertas gerados automaticamente em `alerts[]`.

---

### `PATCH /events/:eventId/artists/:eventArtistId/timeline`
Atualiza a linha do tempo. Recalcula alertas automaticamente.

**Request body:** subconjunto dos campos de POST.

**Response 200:** timeline atualizada + alertas recalculados.

---

### `GET /events/:eventId/timeline`
Visão consolidada da timeline de TODOS os artistas do evento.

**Response 200:**
```json
{
  "data": [
    {
      "artist_name": "Artista XYZ",
      "stage": "Palco Principal",
      "performance_start": "2025-06-15T22:00:00Z",
      "arrival_datetime": "2025-06-15T18:10:00Z",
      "venue_departure_datetime": "2025-06-15T23:20:00Z",
      "next_departure_deadline": "2025-06-16T00:10:00Z",
      "overall_severity": "orange",
      "active_alerts_count": 1
    }
  ]
}
```

---

## 6. Estimativas de Deslocamento

### `GET /events/:eventId/artists/:eventArtistId/transfers`
Lista as estimativas de deslocamento do artista.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "route_type": "airport_to_venue",
      "origin_label": "Aeroporto GRU",
      "destination_label": "Arena XYZ",
      "distance_km": 35.5,
      "eta_minutes_base": 45,
      "eta_minutes_peak": 80,
      "safety_buffer_minutes": 15,
      "planned_eta_minutes": 95,
      "transport_mode": "car"
    }
  ]
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/transfers`
Adiciona uma estimativa de deslocamento. Recalcula alertas da timeline.

**Request body:**
```json
{
  "route_type": "airport_to_venue",       // required
  "origin_label": "Aeroporto GRU",        // required
  "destination_label": "Arena XYZ",       // required
  "distance_km": 35.5,
  "eta_minutes_base": 45,                 // required
  "eta_minutes_peak": 80,
  "safety_buffer_minutes": 15,            // required
  "transport_mode": "car",               // required: car | van | helicopter | motorcycle | other
  "notes": ""
}
```

> `planned_eta_minutes` calculado como `eta_minutes_peak + safety_buffer_minutes`.

**Response 201:** objeto criado.

---

### `PATCH /events/:eventId/artists/:eventArtistId/transfers/:transferId`
Atualiza uma estimativa. Recalcula alertas.

**Response 200:** objeto atualizado.

---

### `DELETE /events/:eventId/artists/:eventArtistId/transfers/:transferId`
Remove uma estimativa.

**Response 204.**

---

## 7. Alertas Operacionais

### `GET /events/:eventId/alerts`
Lista todos os alertas operacionais do evento, ordenados por severidade.

**Query params:**
```
severity?: low | medium | high | critical
color_status?: green | yellow | orange | red | gray
artist_id?: uuid
is_resolved?: boolean
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "artist_id": "uuid",
      "artist_name": "Artista XYZ",
      "alert_type": "tight_departure",
      "severity": "high",
      "color_status": "orange",
      "message": "Artista tem apenas 50 minutos entre o fim do show e o horário limite de saída para o aeroporto.",
      "recommended_action": "Considerar transfer especial direto do backstage. Verificar possibilidade de remarcar saída.",
      "is_resolved": false,
      "resolved_by": null,
      "resolved_at": null,
      "created_at": "2025-06-10T10:00:00Z"
    }
  ],
  "summary": {
    "total": 5,
    "critical": 1,
    "high": 2,
    "medium": 2,
    "resolved": 1
  }
}
```

---

### `GET /events/:eventId/artists/:eventArtistId/alerts`
Lista alertas de um artista específico.

**Response 200:** mesmo formato, filtrado por artista.

---

### `PATCH /events/:eventId/alerts/:alertId/resolve`
Marca um alerta como resolvido.

**Request body:**
```json
{
  "resolution_notes": "Transfer especial agendado para 23h via produção."
}
```

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "is_resolved": true,
    "resolved_by": "uuid-do-usuario",
    "resolved_at": "2025-06-15T14:00:00Z",
    "resolution_notes": "Transfer especial agendado para 23h via produção."
  }
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/alerts/recalculate`
Força o recálculo de todos os alertas do artista com base na timeline e transfers atuais.

**Response 200:**
```json
{
  "data": {
    "alerts_created": 2,
    "alerts_resolved": 1,
    "alerts": [ ...lista atualizada ]
  }
}
```

---

## 8. Equipe do Artista

### `GET /events/:eventId/artists/:eventArtistId/team`
Lista os membros da equipe do artista no evento.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "name": "Carlos Produtor",
      "role": "produtor",
      "document_number": "123.456.789-00",
      "phone": "+55 11 97777-7777",
      "needs_hotel": true,
      "needs_transfer": true,
      "card_id": "uuid"
    }
  ]
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/team`
Adiciona membro à equipe.

**Request body:**
```json
{
  "name": "Carlos Produtor",              // required
  "role": "produtor",                     // required
  "document_number": "123.456.789-00",
  "phone": "+55 11 97777-7777",
  "needs_hotel": true,
  "needs_transfer": true,
  "notes": ""
}
```

**Response 201:** objeto criado.

---

### `PATCH /events/:eventId/artists/:eventArtistId/team/:memberId`
Atualiza membro da equipe.

**Response 200:** objeto atualizado.

---

### `DELETE /events/:eventId/artists/:eventArtistId/team/:memberId`
Remove membro da equipe.

**Response 204.**

---

## 9. Cartões de Consumação

### `GET /events/:eventId/artists/:eventArtistId/cards`
Lista todos os cartões emitidos para o artista e equipe.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "beneficiary_name": "Artista XYZ",
      "beneficiary_role": "artista",
      "team_member_id": null,
      "card_type": "consumacao",
      "card_number": "CARD-2025-0042",
      "qr_token": "qr_abc123xyz",
      "credit_amount": 500.00,
      "consumed_amount": 120.00,
      "balance": 380.00,
      "status": "active",
      "issued_at": "2025-06-15T10:00:00Z",
      "expires_at": "2025-06-16T06:00:00Z"
    }
  ],
  "summary": {
    "total_cards": 4,
    "total_credit": 1200.00,
    "total_consumed": 320.00,
    "total_balance": 880.00
  }
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/cards`
Emite um novo cartão.

**Request body:**
```json
{
  "beneficiary_name": "Artista XYZ",      // required
  "beneficiary_role": "artista",          // required
  "team_member_id": null,
  "card_type": "consumacao",              // required: consumacao | refeicao | backstage
  "credit_amount": 500.00,               // required, min: 0.01
  "expires_at": "2025-06-16T06:00:00Z"
}
```

> `card_number` e `qr_token` gerados automaticamente.

**Response 201:** objeto criado com `card_number` e `qr_token`.

---

### `GET /events/:eventId/cards`
Visão de TODOS os cartões do evento.

**Query params:**
```
card_type?: consumacao | refeicao | backstage
status?: active | blocked | cancelled | expired
artist_id?: uuid
```

**Response 200:** lista paginada de cartões.

---

### `PATCH /events/:eventId/artists/:eventArtistId/cards/:cardId`
Atualiza o cartão (ajustar crédito, bloquear, cancelar).

**Request body:**
```json
{
  "status": "blocked",           // active | blocked | cancelled
  "credit_amount": 600.00,      // novo limite (apenas se status active)
  "notes": "Bloqueio solicitado pela produção"
}
```

**Response 200:** objeto atualizado.

**Erros:**
```json
// 409 — não pode aumentar crédito de cartão bloqueado
{ "error": "CARD_IS_BLOCKED" }
```

---

## 10. Transações de Cartão

### `GET /events/:eventId/artists/:eventArtistId/cards/:cardId/transactions`
Lista o extrato do cartão.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "transaction_type": "consume",
      "amount": 45.00,
      "reference": "Bar Backstage — POS 03",
      "notes": "",
      "created_at": "2025-06-15T22:45:00Z"
    }
  ],
  "summary": {
    "credit_issued": 500.00,
    "total_consumed": 120.00,
    "total_adjusted": 0.00,
    "balance": 380.00
  }
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/cards/:cardId/transactions`
Registra uma transação manual (consume, adjust, cancel).

**Request body:**
```json
{
  "transaction_type": "consume",         // required: issue | consume | adjust | cancel
  "amount": 45.00,                       // required, min: 0.01
  "reference": "Bar Backstage — POS 03",
  "notes": ""
}
```

**Response 201:** transação registrada + `balance` atualizado no cartão.

**Erros:**
```json
// 422 — saldo insuficiente
{ "error": "INSUFFICIENT_BALANCE", "message": "Saldo disponível: R$ 30,00. Valor solicitado: R$ 45,00." }

// 409 — cartão bloqueado ou cancelado
{ "error": "CARD_NOT_ACTIVE", "message": "Cartão com status: blocked." }
```

---

## 11. Arquivos e Anexos

### `GET /events/:eventId/artists/:eventArtistId/files`
Lista todos os arquivos do artista no evento.

**Query params:**
```
file_type?: contract | rider | rooming_list | ticket | voucher | invoice | photo_id | other
```

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "file_type": "rider",
      "file_name": "rider_tecnico_artistaxyz.pdf",
      "file_url": "https://storage.enjoyfun.com.br/...",
      "mime_type": "application/pdf",
      "size_bytes": 204800,
      "uploaded_by": "uuid",
      "notes": "",
      "created_at": "2025-06-01T10:00:00Z"
    }
  ]
}
```

---

### `POST /events/:eventId/artists/:eventArtistId/files`
Faz upload de um arquivo (multipart/form-data).

**Request:** `multipart/form-data`
```
file: File                              // required
file_type: rider                        // required
notes: string
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "file_name": "rider_tecnico.pdf",
    "file_url": "https://storage.enjoyfun.com.br/...",
    "mime_type": "application/pdf",
    "size_bytes": 204800,
    "created_at": "2025-06-01T10:00:00Z"
  }
}
```

**Erros:**
```json
// 413 — arquivo muito grande
{ "error": "FILE_TOO_LARGE", "message": "Tamanho máximo: 50MB." }

// 415 — tipo não permitido
{ "error": "UNSUPPORTED_FILE_TYPE", "message": "Tipos permitidos: pdf, jpg, png, docx, xlsx." }
```

---

### `DELETE /events/:eventId/artists/:eventArtistId/files/:fileId`
Remove um arquivo.

**Response 204.**

---

## 12. Importação CSV / XLSX

### `GET /events/:eventId/imports`
Lista os lotes de importação do evento.

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "file_name": "artistas_evento_junho.csv",
      "import_type": "artists",
      "status": "done",
      "total_rows": 12,
      "success_rows": 11,
      "failed_rows": 1,
      "created_by": "uuid",
      "created_at": "2025-06-01T09:00:00Z"
    }
  ]
}
```

---

### `POST /events/:eventId/imports/preview`
Faz upload e retorna preview com validação, SEM persistir dados.

**Request:** `multipart/form-data`
```
file: File                              // required (.csv ou .xlsx)
import_type: artists                    // required: artists | logistics | timeline | cards | team
```

**Response 200:**
```json
{
  "data": {
    "batch_preview_token": "token_temporario_15min",
    "import_type": "artists",
    "total_rows": 12,
    "valid_rows": 11,
    "invalid_rows": 1,
    "rows": [
      {
        "row_number": 1,
        "status": "valid",
        "parsed_data": { "stage_name": "Artista A", ... },
        "warnings": []
      },
      {
        "row_number": 7,
        "status": "invalid",
        "parsed_data": { "stage_name": "Artista B", "document_number": "" },
        "errors": ["document_number: campo obrigatório"]
      }
    ]
  }
}
```

---

### `POST /events/:eventId/imports/confirm`
Confirma e processa a importação após o preview.

**Request body:**
```json
{
  "batch_preview_token": "token_temporario_15min",  // required
  "skip_invalid_rows": true    // se false, aborta se houver qualquer linha inválida
}
```

**Response 202:**
```json
{
  "data": {
    "batch_id": "uuid",
    "status": "processing",
    "message": "Importação iniciada. Acompanhe pelo batch_id."
  }
}
```

---

### `GET /events/:eventId/imports/:batchId`
Consulta o status e resultado de um lote.

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "status": "done",
    "total_rows": 12,
    "success_rows": 11,
    "failed_rows": 1,
    "rows": [
      { "row_number": 7, "status": "failed", "error_message": "document_number inválido" }
    ]
  }
}
```

---

## Códigos de erro globais

| Código | HTTP | Descrição |
|---|---|---|
| `UNAUTHORIZED` | 401 | Token inválido ou expirado |
| `FORBIDDEN` | 403 | Sem permissão para este recurso |
| `NOT_FOUND` | 404 | Recurso não encontrado |
| `VALIDATION_ERROR` | 422 | Dados inválidos com detalhes por campo |
| `CONFLICT` | 409 | Violação de regra de negócio |
| `FILE_TOO_LARGE` | 413 | Upload excede limite |
| `INTERNAL_ERROR` | 500 | Erro interno do servidor |

---

## Formato padrão de erro

```json
{
  "error": "CODIGO_DO_ERRO",
  "message": "Descrição legível para o desenvolvedor.",
  "fields": {
    "campo": "Mensagem de erro do campo específico."
  }
}
```

---

*API Módulo 1 — Logística Operacional de Artistas · EnjoyFun · Node.js / TypeScript*
