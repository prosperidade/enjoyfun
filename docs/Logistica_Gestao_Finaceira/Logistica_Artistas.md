# Módulo 1 — Logística Operacional de Artistas

> Módulo independente focado na gestão operacional completa do artista no evento: chegada, deslocamentos, hospitalidade, janela de tempo, alertas de conflito, cartões de consumação e arquivos.

---

## 1. Escopo do módulo

Este módulo controla tudo que envolve o artista **operacionalmente** dentro de um evento:

- Cadastro do artista e vínculo com o evento
- Agenda operacional (data, horário, palco, duração)
- Logística de chegada e saída (voo, hotel, transfer)
- **Janela apertada**: cálculo automático de tempo entre chegada, soundcheck, show e próximo destino
- **Alertas de conflito** por nível de risco (verde / amarelo / laranja / vermelho)
- Rider, hospitalidade e camarim
- Equipe do artista
- Cartões de consumação (emissão, saldo, transações)
- Arquivos e anexos (contrato, rider, passagem, voucher)
- Importação em lote via CSV / XLSX

---

## 2. Entidades principais

```
Evento
  └── Artista
        ├── Apresentação (booking)
        ├── Logística operacional
        │     ├── Itens de logística (voo, hotel, transfer, rider)
        │     └── Linha do tempo operacional (janela apertada)
        ├── Estimativas de deslocamento
        ├── Alertas operacionais
        ├── Equipe do artista
        ├── Cartões de consumação
        │     └── Transações do cartão
        └── Arquivos / Anexos
```

---

## 3. Banco de dados

### 3.1 `artists` — Cadastro do artista

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | Multi-tenant |
| stage_name | varchar | Nome artístico |
| legal_name | varchar | Nome legal / razão social |
| document_type | enum | cpf, cnpj, passport |
| document_number | varchar | |
| phone | varchar | |
| email | varchar | |
| manager_name | varchar | Nome do manager/assessor |
| manager_phone | varchar | |
| manager_email | varchar | |
| nationality | varchar | **Sugestão: útil para passaporte e visto** |
| genre | varchar | **Sugestão: segmentação/filtro** |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.2 `event_artists` — Vínculo artista × evento

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| performance_date | date | |
| performance_time | time | Horário de início |
| performance_duration_min | int | **Sugestão: duração em minutos** |
| stage | varchar | Palco / área |
| status | enum | confirmed, pending, cancelled |
| cache_amount | decimal | Valor do cachê |
| currency | varchar | BRL, USD, EUR |
| payment_status | enum | pending, partial, paid |
| contract_number | varchar | |
| soundcheck_time | time | **Sugestão: horário de passagem de som** |
| dressing_room_ready_time | time | **Sugestão: horário de abertura de camarim** |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.3 `artist_logistics` — Bloco operacional geral

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| arrival_date | date | |
| arrival_time | time | |
| departure_date | date | |
| departure_time | time | |
| hotel_name | varchar | |
| hotel_address | varchar | **Sugestão: endereço completo** |
| hotel_checkin | datetime | |
| hotel_checkout | datetime | |
| rooming_notes | text | |
| dressing_room_notes | text | |
| hospitality_notes | text | |
| local_transport_notes | text | |
| airport_transfer_notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.4 `artist_logistics_items` — Itens detalhados de logística

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| logistics_id | uuid FK | FK para artist_logistics |
| logistics_type | enum | airfare, bus, hotel, transfer, local_transport, dressing_room, hospitality, rider, other |
| supplier_id | uuid FK nullable | Fornecedor do item |
| description | varchar | |
| quantity | int | |
| unit_amount | decimal | |
| total_amount | decimal | |
| due_date | date | |
| paid_at | datetime | |
| status | enum | pending, paid, cancelled |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.5 `artist_operational_timeline` — Janela apertada

> Coração do submódulo de logística apertada. Registra cada ponto de controle da jornada do artista.

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| previous_commitment_type | enum | event, hotel, airport, other |
| previous_commitment_label | varchar | Ex: "Festival X em São Paulo" |
| previous_city | varchar | |
| arrival_mode | enum | airplane, bus, car, helicopter, other |
| arrival_airport | varchar | Código IATA, ex: GRU |
| arrival_datetime | datetime | Horário de pouso / chegada na cidade |
| hotel_checkin_datetime | datetime | |
| venue_arrival_datetime | datetime | Chegada no local do evento |
| soundcheck_datetime | datetime | |
| dressing_room_ready_datetime | datetime | |
| performance_start_datetime | datetime | |
| performance_end_datetime | datetime | |
| venue_departure_datetime | datetime | Saída do evento |
| next_commitment_type | enum | event, hotel, airport, home, other |
| next_commitment_label | varchar | Ex: "Festival Y no Rio" |
| next_city | varchar | |
| next_destination | varchar | Ex: "Aeroporto SDU" |
| next_departure_deadline | datetime | Horário limite para sair do evento |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.6 `artist_transfer_estimations` — Estimativas de deslocamento

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| route_type | enum | airport_to_hotel, airport_to_venue, hotel_to_venue, venue_to_airport, venue_to_next_event, hotel_to_next_event, custom |
| origin_label | varchar | |
| destination_label | varchar | |
| distance_km | decimal | |
| eta_minutes_base | int | Tempo sem trânsito |
| eta_minutes_peak | int | Tempo com trânsito / horário de pico |
| safety_buffer_minutes | int | Margem operacional |
| planned_eta_minutes | int | Tempo total planejado (base + buffer) |
| transport_mode | enum | **Sugestão: car, van, helicopter, motorcycle** |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.7 `artist_operational_alerts` — Alertas automáticos

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| alert_type | enum | tight_arrival, tight_departure, soundcheck_conflict, stage_conflict, transfer_risk, insufficient_data |
| severity | enum | low, medium, high, critical |
| color_status | enum | **Sugestão: green, yellow, orange, red, gray** |
| message | text | Mensagem descritiva gerada pelo sistema |
| recommended_action | text | Ação sugerida automaticamente |
| is_resolved | boolean | |
| resolved_by | uuid FK | |
| resolved_at | datetime | |
| created_at | timestamp | |

**Regras de cálculo automático de severidade:**

```
verde   → buffer > 30 min em todos os pontos
amarelo → buffer entre 15–30 min em algum ponto
laranja → buffer < 15 min em algum ponto
vermelho→ chegada prevista após horário do palco
cinza   → dados insuficientes para calcular
```

---

### 3.8 `artist_team_members` — Equipe do artista *(sugestão)*

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| name | varchar | |
| role | varchar | Ex: músico, roadie, assessora, segurança |
| document_number | varchar | |
| phone | varchar | |
| needs_hotel | boolean | |
| needs_transfer | boolean | |
| notes | text | |
| created_at | timestamp | |

---

### 3.9 `artist_benefits` — Benefícios do artista

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| benefit_type | enum | consumption_card, meal_credit, backstage_credit, guest_list, parking, other |
| amount | decimal | |
| quantity | int | |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.10 `artist_cards` — Cartões emitidos

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| team_member_id | uuid FK nullable | Se emitido para membro da equipe |
| beneficiary_name | varchar | |
| beneficiary_role | varchar | |
| card_type | enum | consumacao, refeicao, backstage |
| card_number | varchar | |
| qr_token | varchar | |
| credit_amount | decimal | Limite carregado |
| consumed_amount | decimal | Total consumido |
| status | enum | active, blocked, cancelled, expired |
| issued_at | datetime | |
| expires_at | datetime | |
| notes | text | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

### 3.11 `artist_card_transactions` — Transações do cartão

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_card_id | uuid FK | |
| transaction_type | enum | issue, consume, adjust, cancel |
| amount | decimal | |
| reference | varchar | Ponto de venda / POS |
| notes | text | |
| created_at | timestamp | |

---

### 3.12 `artist_files` — Arquivos e anexos

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| artist_id | uuid FK | |
| file_type | enum | contract, rider, rooming_list, ticket, voucher, invoice, photo_id, other |
| file_name | varchar | |
| file_url | varchar | URL do storage |
| mime_type | varchar | |
| size_bytes | int | |
| uploaded_by | uuid FK | |
| notes | text | |
| created_at | timestamp | |

---

### 3.13 `artist_import_batches` — Controle de importações CSV/XLSX

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| organizer_id | uuid FK | |
| event_id | uuid FK | |
| file_name | varchar | |
| import_type | enum | artists, logistics, cache, cards, timeline, team |
| status | enum | pending, processing, done, failed |
| total_rows | int | |
| success_rows | int | |
| failed_rows | int | |
| created_by | uuid FK | |
| created_at | timestamp | |

### 3.14 `artist_import_rows` — Linhas da importação

| Campo | Tipo | Descrição |
|---|---|---|
| id | uuid PK | |
| batch_id | uuid FK | |
| row_number | int | |
| raw_payload_json | jsonb | Dados brutos da linha |
| resolved_entity_id | uuid nullable | ID criado/atualizado |
| status | enum | pending, success, failed, skipped |
| error_message | text | |
| created_at | timestamp | |

---

## 4. UI / UX

### 4.1 Abas do módulo

```
[ Lista de Artistas ]  [ Agenda ]  [ Logística ]  [ Janela Operacional ]  [ Cartões ]  [ Arquivos ]  [ Importação ]
```

---

### 4.2 Tela: Lista de artistas do evento

**Colunas:**

| Artista | Palco | Horário | Soundcheck | Status Logística | Janela | Consumação | Ações |
|---|---|---|---|---|---|---|---|

**Coluna Janela** exibe badge colorido:
- 🟢 Verde — confortável
- 🟡 Amarelo — atenção
- 🟠 Laranja — apertado
- 🔴 Vermelho — crítico
- ⚪ Cinza — dados insuficientes

**Ações por linha:** Ver detalhe · Editar · Anexar arquivo · Emitir cartão · Ver alertas

---

### 4.3 Tela: Detalhe do artista

Blocos em abas ou seções expansíveis:

1. **Dados gerais** — nome, contato, manager, documentos
2. **Apresentação** — data, horário, palco, duração, soundcheck
3. **Linha do tempo operacional** — timeline visual com pontos de controle
4. **Alertas** — lista de alertas com severity badge e ação recomendada
5. **Logística** — voo, hotel, transfer, camarim, rider
6. **Equipe** — membros, funções, necessidades
7. **Cartões / Consumação** — emitir, ver saldo, bloquear
8. **Arquivos** — upload e listagem de documentos
9. **Histórico / Observações**

---

### 4.4 Tela: Linha do tempo operacional (Timeline visual)

```
[Origem anterior] → [Pouso] → [Hotel] → [Venue] → [Soundcheck] → [PALCO] → [Saída] → [Próximo destino]
      ETA: 50min ↑        ETA: 20min ↑       ETA: 15min ↑                     Limite: 23:30
                                                          ⚠️ BUFFER: 8 min → LARANJA
```

---

### 4.5 Tela: Alertas logísticos (visão geral do evento)

| Artista | Show | Chegada prevista | Saída limite | Próximo destino | Risco | Recomendação |
|---|---|---|---|---|---|---|

---

### 4.6 Fluxo de cartão de consumação

```
1. Selecionar artista ou membro da equipe
2. Definir tipo de cartão (consumação / refeição / backstage)
3. Definir valor / limite
4. Gerar número + QR token
5. Ativar cartão
6. Acompanhar saldo em tempo real
7. Bloquear / cancelar se necessário
```

---

### 4.7 Fluxo de importação CSV / XLSX

```
1. Selecionar tipo de importação (artistas, logística, timeline, cartões, equipe)
2. Upload do arquivo CSV ou XLSX
3. Preview das linhas com validação em tempo real
4. Indicação de erros por linha (campo inválido, artista não encontrado, etc.)
5. Confirmação e processamento
6. Relatório de resultado: X linhas importadas · Y falhas
```

**Campos do CSV de logística apertada:**

```
artista, voo_origem, aeroporto_chegada, horario_pouso, hotel, horario_checkin,
horario_soundcheck, horario_show, duracao_show, proximo_destino,
horario_limite_saida, aeroporto_saida, observacoes
```

---

## 5. Regras de negócio

1. **Evento é o filtro principal** — tudo isolado por `event_id` + `organizer_id`
2. **Artista pode ter vários custos logísticos** no mesmo evento
3. **Cache é separado da logística** — não misturar nas tabelas
4. **Consumação é benefício**, não pagamento financeiro
5. **Cartão pode ser emitido para artista ou para membro da equipe**
6. **Importação CSV nunca insere sem preview e validação prévia**
7. **Alerta de janela apertada** é calculado automaticamente ao salvar/atualizar a timeline
8. **Dados insuficientes** geram alerta cinza, não bloqueiam o cadastro
9. **Buffer mínimo recomendado**: 30 min entre chegada operacional e início do show
10. **Alertas resolvidos** devem registrar quem resolveu e quando

---

## 6. Integrações previstas

| Integração | Descrição |
|---|---|
| Módulo Financeiro (Módulo 2) | Custos logísticos do artista alimentam o financeiro do evento |
| Cartões / POS | QR token integrado com sistema de consumação no bar/backstage |
| Storage de arquivos | Upload de contratos, riders, passagens |
| Exportação CSV/XLSX | Relatório operacional de artistas por evento |
| **Sugestão: Google Maps / Waze API** | Calcular ETA real entre pontos (aeroporto → venue) |
| **Sugestão: Notificações push/email** | Alertar equipe de produção quando janela virar vermelho |

---

## 7. Ordem de implementação sugerida

| Fase | Entrega |
|---|---|
| **Fase 1** | `artists` + `event_artists` · listagem e detalhe básico |
| **Fase 2** | `artist_logistics` + `artist_logistics_items` · agenda e transfer |
| **Fase 3** | `artist_operational_timeline` + `artist_transfer_estimations` · janela apertada |
| **Fase 4** | `artist_operational_alerts` · cálculo automático de risco + badges |
| **Fase 5** | `artist_team_members` + hospitalidade + rider |
| **Fase 6** | `artist_cards` + `artist_card_transactions` · consumação |
| **Fase 7** | `artist_files` · upload e gestão de documentos |
| **Fase 8** | `artist_import_batches` + `artist_import_rows` · importação CSV/XLSX |

---

## 8. Sugestões adicionais não previstas no documento original

1. **Campo `nationality` no artista** — necessário para controle de passaporte e vistos internacionais
2. **Campo `genre` no artista** — facilita filtros e segmentação em eventos com múltiplos palcos
3. **Campo `performance_duration_min`** — essencial para calcular horário de fim e margem de saída
4. **Campo `transport_mode` no transfer** — diferenciar carro, van, helicóptero (impacta ETA)
5. **`team_member_id` no cartão** — cartão pode ser emitido diretamente para membro da equipe
6. **Tela de alertas centralizados** — visão de todos os artistas em risco em uma única tela
7. **Timeline visual interativa** — representação gráfica da jornada do artista com cores por status
8. **Integração com API de mapas** — calcular ETA dinâmico entre pontos reais (aeroporto ↔ venue)
9. **Notificações automáticas** — alertar coordenação quando janela virar laranja ou vermelho
10. **Histórico de alterações** — log de quem alterou horário de voo/show e quando

---

*Módulo 1 — Logística Operacional de Artistas · EnjoyFun Blueprint*
