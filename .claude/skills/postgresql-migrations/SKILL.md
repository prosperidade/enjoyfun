---
name: postgresql-migrations
description: >
  Padrões de migrations PostgreSQL para EnjoyFun. Use ao criar migrations,
  alterar schema, adicionar tabelas, RLS, indexes, pgvector, ou pgcrypto.
  Trigger: migration, SQL, schema, tabela, RLS, index, pgvector, ALTER, CREATE TABLE.
---

# PostgreSQL Migrations — EnjoyFun

## Formato
- Arquivo: `database/migrations/NNN_descricao.sql`
- NNN = próximo número sequencial (consultar `migrations_applied.log`)
- Uma migration = uma mudança lógica

## Template
```sql
-- Migration NNN: descricao
-- Data: YYYY-MM-DD
-- Autor: {trilha}

BEGIN;

-- 1. Criação/alteração
CREATE TABLE IF NOT EXISTS nome_tabela (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL REFERENCES organizers(id),
    -- colunas...
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- 2. RLS obrigatório
ALTER TABLE nome_tabela ENABLE ROW LEVEL SECURITY;
CREATE POLICY nome_tabela_tenant ON nome_tabela
    USING (organizer_id = current_setting('app.current_organizer_id')::INTEGER);

-- 3. Indexes
CREATE INDEX idx_nome_tabela_organizer ON nome_tabela(organizer_id);

-- 4. Grants
GRANT SELECT, INSERT, UPDATE, DELETE ON nome_tabela TO app_user;
GRANT USAGE, SELECT ON SEQUENCE nome_tabela_id_seq TO app_user;

COMMIT;
```

## Regras
1. SEMPRE `BEGIN/COMMIT` — transacional
2. SEMPRE RLS com policy `organizer_id`
3. SEMPRE `IF NOT EXISTS` / `IF EXISTS` para idempotência
4. NUNCA `DROP TABLE` sem backup — usar `ALTER TABLE` para mudanças
5. NUNCA `CASCADE` em produção sem aprovação explícita
6. Indexes compostos com `organizer_id` como primeiro campo
7. `TIMESTAMPTZ` (não `TIMESTAMP`) para datas
8. `GRANT` explícito para `app_user`
9. Aplicar: `bash scripts/apply_migrations.sh enjoyfun postgres 127.0.0.1 5432`

## pgvector (Sprint 5)
```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE TABLE embeddings (
    id SERIAL PRIMARY KEY,
    organizer_id INTEGER NOT NULL,
    content_hash TEXT NOT NULL,
    embedding vector(1536),
    metadata JSONB
);
CREATE INDEX idx_embeddings_ivfflat ON embeddings
    USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
```

## pgcrypto
- Dados sensíveis: `pgp_sym_encrypt(data, key)`
- API keys: via `SecretCryptoService` no PHP
