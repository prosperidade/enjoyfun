## Resumo

- objetivo da mudanca:
- risco principal:
- impacto operacional:

## Checklist obrigatorio

- [ ] `auth` e ACL revisados para as rotas/acoes afetadas
- [ ] `organizer scope` validado para leitura e escrita
- [ ] `audit trail` confirmado ou explicitamente justificado quando nao se aplica
- [ ] validacao de payload revisada no backend e no frontend quando houver contrato compartilhado
- [ ] idempotencia, deduplicacao ou protecao contra dupla execucao avaliadas quando houver escrita/sync/processamento assíncrono
- [ ] migrations/baseline avaliados; se houve DDL, usei `database/apply_migration.bat` e/ou `database/dump_schema.bat`
- [ ] impacto em `runbook` avaliado; atualizei `docs/runbook_local.md` se a rotina operacional mudou
- [ ] resultado registrado em `docs/progresso18.md`

## Validacao executada

- [ ] `node scripts/ci/check_database_governance.mjs` quando a mudanca tocar em banco, baseline, release ou runbook
- [ ] lint/check local relevante executado
- [ ] smoke manual dos fluxos afetados executado ou justificado

## Rollback e observabilidade

- rollback previsto:
- sinais para monitorar apos deploy:
- dependencias ou bloqueios:
