# SQL Legado Arquivado

Esta pasta concentra scripts SQL avulsos que nao fazem parte do fluxo canonico de governanca do banco.

Nao usar estes arquivos como:

- baseline oficial
- migration oficial
- contrato vivo do schema

O fluxo atual de banco continua centrado em:

- `database/schema_current.sql`
- migrations versionadas `database/0xx_*.sql`
- `database/migrations_applied.log`
- `database/dump_history.log`
- `docs/runbook_local.md`
