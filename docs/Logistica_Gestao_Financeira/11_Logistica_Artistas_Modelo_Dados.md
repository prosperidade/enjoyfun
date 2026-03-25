# 11 — Logística de Artistas · Modelo de Dados

> Modelo de dados final do Módulo 1.

---

## 1. Convenções do módulo

- tabelas em `snake_case`
- nomes no plural
- PK numérica
- colunas monetárias em `NUMERIC(14,2)` quando houver custo
- `organizer_id` sempre presente nas tabelas próprias do domínio
- `event_id` presente nas tabelas contextuais do evento

---

## 2. Tabelas finais

### 2.1 `artists`
Cadastro mestre do artista.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `stage_name` VARCHAR(200) NOT NULL
- `legal_name` VARCHAR(200) NULL
- `document_number` VARCHAR(30) NULL
- `artist_type` VARCHAR(50) NULL
- `default_contact_name` VARCHAR(150) NULL
- `default_contact_phone` VARCHAR(40) NULL
- `notes` TEXT NULL
- `is_active` BOOLEAN NOT NULL DEFAULT true
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- índice em `(organizer_id, stage_name)`
- unicidade opcional por organizer quando o domínio exigir deduplicação forte

### 2.2 `event_artists`
Vínculo do artista com o evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `artist_id` BIGINT NOT NULL FK -> `artists.id`
- `booking_status` VARCHAR(30) NOT NULL DEFAULT 'pending'
- `performance_date` DATE NULL
- `performance_start_at` TIMESTAMP NULL
- `performance_duration_minutes` INTEGER NULL
- `soundcheck_at` TIMESTAMP NULL
- `stage_name` VARCHAR(150) NULL
- `cache_amount` NUMERIC(14,2) NULL
- `notes` TEXT NULL
- `cancelled_at` TIMESTAMP NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- UNIQUE `(event_id, artist_id)`
- CHECK `performance_duration_minutes IS NULL OR performance_duration_minutes >= 0`
- CHECK `cache_amount IS NULL OR cache_amount >= 0`

### 2.3 `artist_logistics`
Cabeçalho operacional da logística do artista no evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `event_artist_id` BIGINT NOT NULL FK -> `event_artists.id`
- `arrival_origin` VARCHAR(200) NULL
- `arrival_mode` VARCHAR(50) NULL
- `arrival_reference` VARCHAR(120) NULL
- `arrival_at` TIMESTAMP NULL
- `hotel_name` VARCHAR(200) NULL
- `hotel_address` VARCHAR(300) NULL
- `hotel_check_in_at` TIMESTAMP NULL
- `hotel_check_out_at` TIMESTAMP NULL
- `venue_arrival_at` TIMESTAMP NULL
- `departure_destination` VARCHAR(200) NULL
- `departure_mode` VARCHAR(50) NULL
- `departure_reference` VARCHAR(120) NULL
- `departure_at` TIMESTAMP NULL
- `hospitality_notes` TEXT NULL
- `transport_notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- UNIQUE `(event_artist_id)` quando houver um cabeçalho único por booking

### 2.4 `artist_logistics_items`
Itens detalhados de logística e custo operacional.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `event_artist_id` BIGINT NOT NULL FK -> `event_artists.id`
- `artist_logistics_id` BIGINT NULL FK -> `artist_logistics.id`
- `item_type` VARCHAR(50) NOT NULL
- `description` VARCHAR(255) NOT NULL
- `quantity` NUMERIC(12,2) NOT NULL DEFAULT 1
- `unit_amount` NUMERIC(14,2) NULL
- `total_amount` NUMERIC(14,2) NULL
- `currency_code` VARCHAR(10) NULL
- `supplier_name` VARCHAR(200) NULL
- `notes` TEXT NULL
- `status` VARCHAR(30) NOT NULL DEFAULT 'pending'
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- CHECK `quantity > 0`
- CHECK `unit_amount IS NULL OR unit_amount >= 0`
- CHECK `total_amount IS NULL OR total_amount >= 0`
- `total_amount` calculado no backend como `quantity * unit_amount` quando houver preço unitário

### 2.5 `artist_operational_timelines`
Linha do tempo operacional do booking.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `event_artist_id` BIGINT NOT NULL FK -> `event_artists.id`
- `landing_at` TIMESTAMP NULL
- `airport_out_at` TIMESTAMP NULL
- `hotel_arrival_at` TIMESTAMP NULL
- `venue_arrival_at` TIMESTAMP NULL
- `soundcheck_at` TIMESTAMP NULL
- `show_start_at` TIMESTAMP NULL
- `show_end_at` TIMESTAMP NULL
- `venue_exit_at` TIMESTAMP NULL
- `next_departure_deadline_at` TIMESTAMP NULL
- `timeline_status` VARCHAR(30) NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- UNIQUE `(event_artist_id)`
- `show_end_at` pode ser calculado de `show_start_at + performance_duration_minutes`

### 2.6 `artist_transfer_estimations`
Estimativas de deslocamento entre trechos.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `event_artist_id` BIGINT NOT NULL FK -> `event_artists.id`
- `route_code` VARCHAR(50) NOT NULL
- `origin_label` VARCHAR(150) NOT NULL
- `destination_label` VARCHAR(150) NOT NULL
- `eta_base_minutes` INTEGER NOT NULL
- `eta_peak_minutes` INTEGER NULL
- `buffer_minutes` INTEGER NOT NULL DEFAULT 0
- `planned_eta_minutes` INTEGER NOT NULL
- `notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- CHECK `eta_base_minutes >= 0`
- CHECK `eta_peak_minutes IS NULL OR eta_peak_minutes >= 0`
- CHECK `buffer_minutes >= 0`
- `planned_eta_minutes` = `COALESCE(eta_peak_minutes, eta_base_minutes) + buffer_minutes`

### 2.7 `artist_operational_alerts`
Alertas de conflito e risco operacional.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `event_artist_id` BIGINT NOT NULL FK -> `event_artists.id`
- `timeline_id` BIGINT NULL FK -> `artist_operational_timelines.id`
- `alert_type` VARCHAR(50) NOT NULL
- `severity` VARCHAR(20) NOT NULL
- `status` VARCHAR(20) NOT NULL DEFAULT 'open'
- `title` VARCHAR(200) NOT NULL
- `message` TEXT NOT NULL
- `recommended_action` TEXT NULL
- `triggered_at` TIMESTAMP NOT NULL
- `resolved_at` TIMESTAMP NULL
- `resolution_notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

Constraints:
- CHECK `severity IN ('green','yellow','orange','red','gray')`
- CHECK `status IN ('open','acknowledged','resolved','dismissed')`

### 2.8 `artist_team_members`
Equipe vinculada ao artista no evento.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `event_artist_id` BIGINT NOT NULL FK -> `event_artists.id`
- `full_name` VARCHAR(180) NOT NULL
- `role_name` VARCHAR(120) NULL
- `document_number` VARCHAR(40) NULL
- `phone` VARCHAR(40) NULL
- `needs_hotel` BOOLEAN NOT NULL DEFAULT false
- `needs_transfer` BOOLEAN NOT NULL DEFAULT false
- `notes` TEXT NULL
- `is_active` BOOLEAN NOT NULL DEFAULT true
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

### 2.9 `artist_files`
Arquivos operacionais do artista.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NOT NULL
- `event_artist_id` BIGINT NOT NULL FK -> `event_artists.id`
- `file_type` VARCHAR(50) NOT NULL
- `original_name` VARCHAR(255) NOT NULL
- `storage_path` VARCHAR(500) NOT NULL
- `mime_type` VARCHAR(120) NULL
- `file_size_bytes` BIGINT NULL
- `notes` TEXT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

### 2.10 `artist_import_batches`
Controle do lote de importação do módulo.

Campos principais:
- `id` BIGINT PK
- `organizer_id` BIGINT NOT NULL
- `event_id` BIGINT NULL
- `import_type` VARCHAR(50) NOT NULL
- `source_filename` VARCHAR(255) NOT NULL
- `status` VARCHAR(30) NOT NULL
- `preview_payload` JSON NULL
- `error_summary` JSON NULL
- `confirmed_at` TIMESTAMP NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

### 2.11 `artist_import_rows`
Linhas processadas do lote.

Campos principais:
- `id` BIGINT PK
- `batch_id` BIGINT NOT NULL FK -> `artist_import_batches.id`
- `row_number` INTEGER NOT NULL
- `row_status` VARCHAR(30) NOT NULL
- `raw_payload` JSON NOT NULL
- `normalized_payload` JSON NULL
- `error_messages` JSON NULL
- `created_record_id` BIGINT NULL
- `created_at` TIMESTAMP NOT NULL
- `updated_at` TIMESTAMP NOT NULL

---

## 3. Cálculos de timeline e alerta

### 3.1 Cálculo de fim de show
Se houver `show_start_at` e duração do booking:
- `show_end_at = show_start_at + performance_duration_minutes`

### 3.2 Cálculo de ETA planejado
- `planned_eta_minutes = COALESCE(eta_peak_minutes, eta_base_minutes) + buffer_minutes`

### 3.3 Janela chegada → soundcheck
Regra de referência:
- `landing_at + planned_eta(chegada ao venue) <= soundcheck_at`

### 3.4 Janela chegada → show
Regra de referência:
- `landing_at + planned_eta(chegada ao venue) <= show_start_at`

### 3.5 Janela saída → próximo compromisso
Regra de referência:
- `show_end_at + planned_eta(saída) <= next_departure_deadline_at`

### 3.6 Severidade sugerida
- `gray`: dados insuficientes
- `green`: margem confortável
- `yellow`: atenção
- `orange`: janela apertada
- `red`: conflito crítico ou inviabilidade

A parametrização exata pode viver em helper/motor reutilizável, mas a escala oficial é essa.

---

## 4. Reuso de cartões existentes

### Regra explícita
Não criar:
- `artist_cards`
- `artist_card_transactions`

### Reuso obrigatório
Usar infraestrutura existente:
- `digital_cards`
- `card_transactions`
- `event_card_assignments`

### Ajuste permitido
Se faltar vínculo com:
- `event_artist_id`
- `artist_team_member_id`

então o ajuste deve ser feito em `event_card_assignments` ou camada equivalente de vínculo, sem abrir um segundo motor de cartões.
