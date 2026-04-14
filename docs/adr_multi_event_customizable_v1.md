# ADR: Arquitetura Multi-Evento Customizavel

**Status:** Proposto
**Data:** 2026-04-14
**Autor:** Claude + Andre (EnjoyFun)

---

## Contexto

A EnjoyFun precisa suportar eventos de todos os tipos (festival, casamento, corporativo, formatura, feira, esportivo, teatro, rodeio, etc). Hoje o sistema ja tem:

- **6 templates de evento** (migration 063) com `default_modules` JSONB
- **EventTemplateSelector** com cards visuais no frontend
- **17 AI skills** mapeadas por tipo de evento
- **Formulario de evento** com campos base + ticket types + batches + commissaries

O que falta: permitir que o organizador **customize os modulos** do seu evento alem do template pre-selecionado. Modelo: 1 UI unificada onde clicar em "Casamento" pre-seleciona modulos relevantes, mas o organizador pode adicionar/remover livremente.

---

## Decisao

### Modelo Customizavel com Modulos Arrastáveis

Em vez de 12 formularios separados por tipo, adotamos:

1. **Selecao de tipo** (cards existentes) = pre-set de modulos
2. **Customizacao** = organizador adiciona/remove modulos do container
3. **Cada modulo** = secao colapsavel com campos especificos
4. **Persistencia** = `events.modules_enabled` JSONB salva os modulos ativos

### Modulos Disponiveis

| Modulo | Chave | Descricao | Tabelas necessarias |
|--------|-------|-----------|---------------------|
| Palcos / Areas | `stages` | Palcos, salas, auditorios | `event_stages` (NOVA) |
| Setores | `sectors` | Pista, VIP, Camarote, etc | `event_sectors` (NOVA) |
| PDV Distribuido | `pdv_points` | Bares, lojas por palco | `event_pdv_points` (NOVA) |
| Estacionamento Config | `parking_config` | Precos, vagas por tipo | `event_parking_config` (NOVA) |
| Mapa de Mesas | `seating` | Mesas com drag-and-drop | `event_tables` (NOVA) |
| Convites / RSVP | `invitations` | Lista de convidados com RSVP | Estende `event_participants` |
| Expositores / Stands | `exhibitors` | Empresas expositoras | `event_exhibitors` (NOVA) |
| Agenda / Sessoes | `sessions` | Palestras, workshops | `event_sessions` (NOVA) |
| Certificados | `certificates` | Emissao por participacao | `event_certificates` (NOVA) |
| Cerimonial / Timeline | `ceremony` | Sequencia de momentos | Estende `event_days` |
| Fornecedores | `vendors` | Gestao de fornecedores | Tabela `suppliers` ja existe |
| Lineup de Artistas | `artists` | Atracoes do evento | Tabelas `artists`/`event_artists` ja existem |
| Cashless / Cartoes | `cashless` | Cartao digital | Tabelas `digital_cards` ja existem |
| Ingressos | `tickets` | Tipos, lotes, comissarios | Tabelas `tickets`/`ticket_types` ja existem |
| Equipe / Workforce | `workforce` | Gestao de equipe | Tabelas `workforce_*` ja existem |
| Refeicoes | `meals` | Controle de refeicoes | Tabelas `participant_meals`/`event_meal_services` ja existem |
| Financeiro | `finance` | Orcamento, contas a pagar | Tabelas `event_budgets`/`event_payables` ja existem |
| Estacionamento | `parking` | Controle de veiculos | Tabela `parking_records` ja existe |
| Localizacao | `location` | GPS, mapa, venue info | Campos novos em `events` |
| Mapas / Uploads | `maps` | Mapa 3D, mapa de assentos, planta | Campos novos em `events` |

### Pre-sets por Tipo de Evento

Quando o organizador seleciona um tipo, os modulos sao pre-ativados:

| Tipo | Card | Icone | Modulos pre-ativados |
|------|------|-------|---------------------|
| Festival de Musica | `festival` | Music | stages, sectors, pdv_points, parking_config, artists, cashless, tickets, workforce, meals, finance, parking, location, maps |
| Show Avulso | `show` | Mic | stages, artists, cashless, tickets, pdv_points, finance, parking, location |
| Corporativo / Conferencia | `corporate` | Building2 | sessions, certificates, sectors, workforce, meals, finance, tickets, location |
| Formatura | `graduation` | GraduationCap | seating, invitations, ceremony, certificates, vendors, finance, location |
| Casamento | `wedding` | Heart | seating, invitations, ceremony, vendors, finance, location, maps |
| Esportivo (Estadio) | `sports_stadium` | Trophy | sectors, parking_config, tickets, cashless, parking, finance, location, maps |
| Feira / Exposicao | `expo` | Store | exhibitors, sessions, sectors, tickets, finance, location, maps |
| Congresso / Palestra | `congress` | BookOpen | sessions, certificates, sectors, tickets, workforce, meals, finance, location |
| Teatro / Auditorio | `theater` | Drama | seating, sectors, tickets, finance, location |
| Ginasio (Esportes Indoor) | `sports_gym` | Dumbbell | sectors, seating, tickets, cashless, parking, finance, location |
| Rodeio / Agro | `rodeo` | Flame | stages, pdv_points, parking_config, artists, cashless, tickets, workforce, meals, finance, parking, location |
| + Criar Novo Tipo | `custom` | Puzzle | Nenhum pre-ativado — organizador monta do zero e da nome ao tipo |

### Card "+ Criar Novo Tipo" (Custom)

O organizador pode criar seu proprio tipo de evento. Ao clicar:
1. Abre input para **nome do tipo** (ex: "Leilao Beneficente", "Retiro Espiritual")
2. Abre input para **descricao curta**
3. Mostra grid de modulos disponiveis — organizador marca os que precisa
4. Salva como template custom do organizador (tabela `event_templates` com `organizer_id`)
5. Proxima vez que criar evento, o tipo custom aparece nos cards com badge "Personalizado"

Isso ja esta parcialmente implementado no `EventTemplateSelector` (secao de custom templates). O ADR estende para incluir a criacao inline.

---

## Schema — Novas Tabelas e Campos

### Migration 089: Campos novos em `events`

```sql
ALTER TABLE events ADD COLUMN IF NOT EXISTS event_type VARCHAR(50);
ALTER TABLE events ADD COLUMN IF NOT EXISTS modules_enabled JSONB DEFAULT '[]';
ALTER TABLE events ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8);
ALTER TABLE events ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8);
ALTER TABLE events ADD COLUMN IF NOT EXISTS city VARCHAR(100);
ALTER TABLE events ADD COLUMN IF NOT EXISTS state VARCHAR(50);
ALTER TABLE events ADD COLUMN IF NOT EXISTS country VARCHAR(50) DEFAULT 'BR';
ALTER TABLE events ADD COLUMN IF NOT EXISTS zip_code VARCHAR(20);
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue_type VARCHAR(20) DEFAULT 'outdoor';
ALTER TABLE events ADD COLUMN IF NOT EXISTS age_rating VARCHAR(20);
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_3d_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_image_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_seating_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_parking_url TEXT;
```

### Migration 090: `event_stages`

```sql
CREATE TABLE IF NOT EXISTS event_stages (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    name VARCHAR(200) NOT NULL,
    stage_type VARCHAR(50) DEFAULT 'main',
    capacity INTEGER,
    location_description TEXT,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_event_stages_event ON event_stages(event_id);
```

### Migration 091: `event_sectors`

```sql
CREATE TABLE IF NOT EXISTS event_sectors (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    name VARCHAR(200) NOT NULL,
    sector_type VARCHAR(50),
    capacity INTEGER,
    price_modifier DECIMAL(10,2) DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_event_sectors_event ON event_sectors(event_id);
```

### Migration 092: `event_parking_config`

```sql
CREATE TABLE IF NOT EXISTS event_parking_config (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    vehicle_type VARCHAR(20) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_spots INTEGER NOT NULL DEFAULT 0,
    vip_spots INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_event_parking_config_event ON event_parking_config(event_id);
```

### Migration 093: `event_pdv_points`

```sql
CREATE TABLE IF NOT EXISTS event_pdv_points (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    name VARCHAR(200) NOT NULL,
    pdv_type VARCHAR(20) NOT NULL DEFAULT 'bar',
    stage_id INTEGER REFERENCES event_stages(id),
    location_description TEXT,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_event_pdv_points_event ON event_pdv_points(event_id);
```

### Migration 094: `event_tables` (mesas)

```sql
CREATE TABLE IF NOT EXISTS event_tables (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    table_number INTEGER NOT NULL,
    table_name VARCHAR(100),
    table_type VARCHAR(20) DEFAULT 'round',
    capacity INTEGER NOT NULL DEFAULT 8,
    section VARCHAR(100),
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_event_tables_event ON event_tables(event_id);
```

### Migration 095: `event_sessions`

```sql
CREATE TABLE IF NOT EXISTS event_sessions (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    stage_id INTEGER REFERENCES event_stages(id),
    title VARCHAR(500) NOT NULL,
    description TEXT,
    session_type VARCHAR(50) DEFAULT 'talk',
    speaker_name VARCHAR(200),
    speaker_bio TEXT,
    starts_at TIMESTAMP NOT NULL,
    ends_at TIMESTAMP NOT NULL,
    max_capacity INTEGER,
    requires_registration BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_event_sessions_event ON event_sessions(event_id);
```

### Migration 096: `event_exhibitors`

```sql
CREATE TABLE IF NOT EXISTS event_exhibitors (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    company_name VARCHAR(300) NOT NULL,
    cnpj VARCHAR(20),
    contact_name VARCHAR(200),
    contact_email VARCHAR(200),
    contact_phone VARCHAR(30),
    stand_number VARCHAR(50),
    stand_type VARCHAR(50) DEFAULT 'standard',
    stand_size_m2 DECIMAL(8,2),
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_event_exhibitors_event ON event_exhibitors(event_id);
```

### Migration 097: `event_certificates`

```sql
CREATE TABLE IF NOT EXISTS event_certificates (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    organizer_id INTEGER NOT NULL REFERENCES users(id),
    participant_name VARCHAR(200) NOT NULL,
    participant_email VARCHAR(200),
    certificate_type VARCHAR(50) DEFAULT 'participation',
    hours INTEGER,
    session_id INTEGER REFERENCES event_sessions(id),
    issued_at TIMESTAMP DEFAULT NOW(),
    validation_code VARCHAR(50) UNIQUE
);
CREATE INDEX idx_event_certificates_event ON event_certificates(event_id);
```

### Migration 098: Estender `event_participants` para RSVP

```sql
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS rsvp_status VARCHAR(20);
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS meal_choice VARCHAR(50);
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS dietary_restrictions TEXT;
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS table_id INTEGER REFERENCES event_tables(id);
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS seat_number INTEGER;
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS plus_one_name VARCHAR(200);
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS guest_side VARCHAR(20);
ALTER TABLE event_participants ADD COLUMN IF NOT EXISTS invited_by VARCHAR(200);
```

---

## Frontend — UI Customizavel

### Fluxo do Organizador

```
1. Clica "Novo Evento"
2. Ve cards de tipo (EventTemplateSelector — ja existe)
3. Seleciona tipo (ex: "Casamento")
   → modules_enabled pre-populado com preset do tipo
4. Formulario abre com:
   a) Campos base (nome, local, datas — ja existe)
   b) Secao "Modulos do Evento" = lista de toggles/chips
      Cada modulo ativo vira uma secao colapsavel abaixo
5. Organizador pode:
   - Desativar modulos pre-selecionados
   - Ativar modulos extras (ex: adicionar "Cashless" no casamento)
6. Cada modulo ativo mostra seu formulario inline (colapsavel)
7. Salva tudo de uma vez
```

### Componente EventModulesSelector (NOVO)

Grid de chips/toggles — cada modulo e um botao on/off:

```
[x] Palcos    [x] Setores    [ ] PDV Distrib.   [ ] Estacionamento
[x] Mesas     [x] Convites   [ ] Expositores    [ ] Sessoes
[ ] Certif.   [x] Cerimonial [x] Fornecedores   [x] Financeiro
[ ] Artistas  [ ] Cashless   [x] Ingressos      [ ] Equipe
```

Chips ativos = modulos que aparecem como secoes colapsaveis abaixo.

### Componentes de Modulo (1 por modulo)

Cada modulo ativo renderiza sua secao. Exemplos:

- **StagesModuleSection**: CRUD de palcos (nome, tipo, capacidade)
- **SectorsModuleSection**: CRUD de setores (nome, tipo, capacidade, preco)
- **ParkingConfigModuleSection**: Config por tipo de veiculo (preco, vagas)
- **SeatingModuleSection**: CRUD de mesas (numero, tipo, capacidade)
- **SessionsModuleSection**: CRUD de sessoes (titulo, palestrante, horario, sala)
- **ExhibitorsModuleSection**: CRUD de expositores (empresa, stand, contato)
- **LocationModuleSection**: Endereco, GPS, tipo de local, mapas (uploads)
- **InvitationsModuleSection**: Importar convidados, RSVP tracking

Modulos que ja existem no sistema (artistas, tickets, workforce, etc.) nao precisam de formulario aqui — apenas o toggle que ativa/desativa a feature no evento.

---

## Endpoints Novos

```
# Stages
GET/POST        /events/:id/stages
PUT/DELETE       /events/:id/stages/:stageId

# Sectors
GET/POST        /events/:id/sectors
PUT/DELETE       /events/:id/sectors/:sectorId

# Parking Config
GET/POST        /events/:id/parking-config
PUT              /events/:id/parking-config/:configId

# PDV Points
GET/POST        /events/:id/pdv-points
PUT/DELETE       /events/:id/pdv-points/:pointId

# Tables (mesas)
GET/POST        /events/:id/tables
PUT/DELETE       /events/:id/tables/:tableId

# Sessions
GET/POST        /events/:id/sessions
PUT/DELETE       /events/:id/sessions/:sessionId

# Exhibitors
GET/POST        /events/:id/exhibitors
PUT/DELETE       /events/:id/exhibitors/:exhibitorId

# Certificates
GET/POST        /events/:id/certificates
GET              /certificates/validate/:code
```

---

## Plano de Execucao

### Sprint 1: Foundation (migrations + campos events)
- Migration 089 (campos events)
- Migration 090-093 (stages, sectors, parking_config, pdv_points)
- Backend: EventService aceita `event_type` e `modules_enabled`
- Frontend: EventModulesSelector no formulario de evento

### Sprint 2: Modulos de Festival
- StagesModuleSection + endpoint CRUD
- SectorsModuleSection + endpoint CRUD
- ParkingConfigModuleSection + endpoint CRUD
- PdvPointsModuleSection + endpoint CRUD
- LocationModuleSection (campos events)

### Sprint 3: Modulos de Casamento/Formatura
- Migration 094-095 (tables, sessions)
- Migration 098 (RSVP em event_participants)
- SeatingModuleSection + endpoint CRUD
- InvitationsModuleSection (estende participants)
- CeremonyModuleSection (estende event_days)

### Sprint 4: Modulos de Feira/Corporativo
- Migration 096-097 (exhibitors, certificates)
- ExhibitorsModuleSection + endpoint CRUD
- SessionsModuleSection + endpoint CRUD
- CertificatesModuleSection + endpoint CRUD

### Sprint 5: Integracao com app mobile
- Alimentar LineupBlock com event_stages
- Alimentar MapBlock com coordenadas + mapas
- Alimentar blocos de sessoes/agenda
- Novo bloco: SeatingMapBlock

---

## Riscos e Mitigacao

| Risco | Mitigacao |
|-------|----------|
| Complexidade do formulario | Modulos colapsaveis, so abre o que ativou |
| Performance com muitos modulos | Lazy-load de secoes, queries por modulo |
| Conflito com tabelas existentes | Tabelas novas usam INTEGER FK pra events.id, mesmo padrao |
| Modulo ativo sem migration aplicada | Mesmo padrao de resilience: checar coluna/tabela antes de usar |
| Organizador confuso com muitas opcoes | Pre-sets por tipo simplificam; customizado e opt-in |

---

## O que NAO muda

- Tabelas existentes (events, tickets, products, sales, etc.) — intactas
- IDs INTEGER em todo o sistema — sem UUID
- Fluxo de criacao existente — estendido, nao substituido
- Agentes IA — nao tocados nesta ADR (ja mapeados na migration 063)
- App mobile — consome dados novos via blocos existentes

---

**Decisao:** Modelo customizavel com modulos arrastáveis, templates como pre-sets, tabelas novas com INTEGER FK, formulario unificado com secoes colapsáveis.
