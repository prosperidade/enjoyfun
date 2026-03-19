# Database — Governança de Schema

## Baseline oficial

| Arquivo | Papel | Estado real |
|---|---|---|
| `schema_current.sql` | **Baseline canônico** | Snapshot oficial versionado do schema atual. Nesta rodada foi materializado a partir de `schema_dump_20260313.sql`. |
| `schema_dump_YYYYMMDD.sql` | Histórico por data | Dump datado do banco real para auditoria e diff histórico. |
| `schema_real.sql` | Histórico legado | Mantido apenas para consulta histórica. Não usar como baseline nem como fonte de verdade. |

## Estado das migrations frente ao baseline atual

| Migration | Leitura objetiva |
|---|---|
| `001` a `005` | Efeitos refletidos em `schema_current.sql`. |
| `006_financial_hardening.sql` | **Não refletida integralmente** no baseline atual para `organizer_payment_gateways.is_primary` / `environment`. Não foi absorvida pela `009`. |
| `007_workforce_costs_meals_model.sql` | Refletida para `meal_unit_cost` e `workforce_role_settings`; o drift manual `leader_*` ficou separado na `009`. |
| `008_tickets_commercial_model.sql` | Refletida em `schema_current.sql`. |
| `009_manual_schema_sync.sql` | Reduzida ao escopo seguro: apenas `leader_name`, `leader_cpf` e `leader_phone` em `workforce_role_settings`. Não foi aplicada nesta rodada. |
| `012_meal_services_redesign.sql` | Migration oficial da primeira fase de meal services. |
| `021_event_meal_services_alignment.sql` | Renumeracao oficial da antiga `012_event_meal_services_model.sql`, preservando trilha linear a partir da auditoria final de `Meals + Workforce`. |
| `022` a `024` | Fechamento estrutural da auditoria final: identidade de assignments, endurecimento de `event_participants.qr_token` e janela explicita do QR externo. |

## Logs operacionais

| Arquivo | Papel |
|---|---|
| `dump_history.log` | Registro append-only de dumps gerados localmente. |
| `migrations_applied.log` | Registro append-only das migrations aplicadas via operação manual ou `apply_migration.bat`. |

Estes logs sao operacionais. Eles nao substituem `schema_current.sql` como baseline.

Importante: a antiga migration `012_event_meal_services_model.sql` foi renumerada para `021_event_meal_services_alignment.sql` para eliminar a duplicidade historica de prefixo `012`. O `migrations_applied.log` continua sendo um registro operacional do que foi executado em cada ambiente, nao uma declaracao de baseline completo.

## Fluxo diario leve

1. Gerar dump do banco real:
   ```bat
   cmd /c "database\dump_schema.bat"
   ```
2. Revisar o diff:
   ```bat
   git diff -- database/schema_current.sql
   ```
3. Se o diff revelar mudanca estrutural sem migration correspondente, criar uma migration **minima e dedicada**.
4. Commitar juntos:
   - `database/schema_current.sql`
   - `database/schema_dump_YYYYMMDD.sql`
   - migration nova, se houver
   - logs operacionais, se foram alterados
5. Se alguma migration for aplicada localmente, registrar em `database/migrations_applied.log` ou usar:
   ```bat
   cmd /c "database\apply_migration.bat database\NNN_nome.sql"
   ```

## Guardrails

1. `schema_current.sql` e o baseline oficial; nao editar manualmente.
2. `schema_real.sql` saiu de cena como baseline; usar apenas para historico pontual.
3. Nao espelhar tabelas legadas inteiras em migrations de sync manual se o baseline ja as captura.
4. Nao registrar falso fechamento: se uma migration nao foi aplicada, documentar como pendente.
5. `schema_migrations` ainda nao faz parte do baseline oficial; o registro operacional atual fica em `migrations_applied.log`.
