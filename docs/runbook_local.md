# Runbook local — EnjoyFun

## Objetivo

Padronizar o bootstrap local mínimo do projeto e o primeiro smoke operacional.

## Pré-requisitos

- PHP `8.2+`
- PostgreSQL `18+`
- Node.js `18+`
- extensão `pdo_pgsql` habilitada

## Arquivos de referência

- `README.md`
- `CLAUDE.md`
- `docs/auditorias.md`
- `docs/progresso9.md`

## Bootstrap local

### 1. Backend

1. Criar `backend/.env` a partir de `backend/.env.example`
2. Preencher:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_DATABASE`
   - `DB_USER`
   - `DB_PASS`
   - `JWT_SECRET` com pelo menos `32` caracteres
3. Subir o banco
4. Aplicar o baseline:

```bash
psql enjoyfun < database/schema_current.sql
```

5. Aplicar migrations pendentes conforme necessário
6. Subir a API:

```bash
cd backend
php -d opcache.enable=0 -d opcache.enable_cli=0 -S localhost:8000 -t public router_dev.php
```

### 2. Frontend

```bash
cd frontend
npm install
npm run dev
```

## Verificações rápidas

### API

- `GET /api/health`
- `POST /api/auth/login`
- `GET /api/events`

### Workforce / cartões

- preview de emissão em massa
- emissão confirmada
- conferência em `GET /cards?event_id={id}`

### Cashless / cliente

- `POST /auth/request-code`
- `POST /auth/verify-code`
- `GET /customer/balance?event_id={id}`

## Smoke mínimo da rodada

Antes de mexer em frentes críticas, validar pelo menos:

1. `cashless + sync offline`
2. emissão em massa de cartões
3. listagem de tickets/participants/parking no tenant correto

Referências vivas:

- `docs/qa/smoke_operacional_core.md`
- `docs/qa/contratos_minimos_api.md`

## Regras locais

- não usar `schema_real.sql` como baseline
- não versionar `backend/.env`
- não abrir frente nova antes de registrar o resultado no `docs/progresso9.md`

## Quando algo divergir

1. conferir `README.md`
2. conferir `CLAUDE.md`
3. conferir `docs/auditorias.md`
4. registrar a divergência em `docs/progresso9.md`
