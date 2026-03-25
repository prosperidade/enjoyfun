# 12 — Logística de Artistas · API

> Contrato de API do Módulo 1 no padrão do roteador atual.

---

## 1. Base

Base oficial: `/api`

Recurso raiz: `/api/artists`

Envelope oficial:
- `success`
- `data`
- `message`
- `meta` opcional

---

## 2. Endpoints principais

### 2.1 Artistas
- `GET /api/artists`
- `POST /api/artists`
- `GET /api/artists/{id}`
- `PUT /api/artists/{id}`
- `PATCH /api/artists/{id}`

### 2.2 Bookings
- `GET /api/artists/bookings?event_id={event_id}`
- `POST /api/artists/bookings`
- `GET /api/artists/bookings/{id}`
- `PUT /api/artists/bookings/{id}`
- `PATCH /api/artists/bookings/{id}`
- `POST /api/artists/bookings/{id}/cancel`

### 2.3 Logistics
- `GET /api/artists/logistics?event_id={event_id}`
- `POST /api/artists/logistics`
- `GET /api/artists/logistics/{id}`
- `PUT /api/artists/logistics/{id}`
- `PATCH /api/artists/logistics/{id}`

### 2.4 Logistics items
- `GET /api/artists/logistics-items?event_id={event_id}`
- `POST /api/artists/logistics-items`
- `GET /api/artists/logistics-items/{id}`
- `PUT /api/artists/logistics-items/{id}`
- `PATCH /api/artists/logistics-items/{id}`
- `DELETE /api/artists/logistics-items/{id}`

### 2.5 Timelines
- `GET /api/artists/timelines?event_id={event_id}`
- `POST /api/artists/timelines`
- `GET /api/artists/timelines/{id}`
- `PUT /api/artists/timelines/{id}`
- `PATCH /api/artists/timelines/{id}`
- `POST /api/artists/timelines/{id}/recalculate`

### 2.6 Transfers
- `GET /api/artists/transfers?event_id={event_id}`
- `POST /api/artists/transfers`
- `GET /api/artists/transfers/{id}`
- `PUT /api/artists/transfers/{id}`
- `PATCH /api/artists/transfers/{id}`
- `DELETE /api/artists/transfers/{id}`

### 2.7 Alerts
- `GET /api/artists/alerts?event_id={event_id}`
- `GET /api/artists/alerts/{id}`
- `PATCH /api/artists/alerts/{id}`
- `POST /api/artists/alerts/{id}/acknowledge`
- `POST /api/artists/alerts/{id}/resolve`
- `POST /api/artists/alerts/recalculate`

### 2.8 Team
- `GET /api/artists/team?event_id={event_id}`
- `POST /api/artists/team`
- `GET /api/artists/team/{id}`
- `PUT /api/artists/team/{id}`
- `PATCH /api/artists/team/{id}`
- `DELETE /api/artists/team/{id}`

### 2.9 Files
- `GET /api/artists/files?event_id={event_id}`
- `POST /api/artists/files`
- `GET /api/artists/files/{id}`
- `DELETE /api/artists/files/{id}`

### 2.10 Imports
- `POST /api/artists/imports/preview`
- `POST /api/artists/imports/confirm`
- `GET /api/artists/imports/{id}`

---

## 3. Regras de payload

### 3.1 Criação de booking
Campos mínimos esperados:
- `event_id`
- `artist_id`

Campos usuais:
- `performance_date`
- `performance_start_at`
- `performance_duration_minutes`
- `soundcheck_at`
- `stage_name`
- `cache_amount`
- `notes`

### 3.2 Criação de logística
Campos mínimos esperados:
- `event_id`
- `event_artist_id`

### 3.3 Criação de item de logística
Campos mínimos esperados:
- `event_id`
- `event_artist_id`
- `item_type`
- `description`

### 3.4 Criação de timeline
Campos mínimos esperados:
- `event_id`
- `event_artist_id`

### 3.5 Criação de transfer
Campos mínimos esperados:
- `event_id`
- `event_artist_id`
- `route_code`
- `origin_label`
- `destination_label`
- `eta_base_minutes`

### 3.6 Criação de membro de equipe
Campos mínimos esperados:
- `event_id`
- `event_artist_id`
- `full_name`

---

## 4. Regras de validação

- `organizer_id` vem do JWT
- `event_id` obrigatório quando a operação é contextual ao evento
- `status` de alerta é controlado pelo backend
- `total_amount` de item de logística é calculado pelo backend
- severidade de alerta é calculada pelo backend

---

## 5. Exemplos de resposta

```json
{
  "success": true,
  "data": {
    "id": 501,
    "event_id": 88,
    "artist_id": 17
  },
  "message": "Booking criado com sucesso."
}
```

```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "severity": "red",
      "title": "Chegada após horário de palco"
    }
  ],
  "message": "Alertas carregados com sucesso.",
  "meta": {
    "total": 1
  }
}
```
