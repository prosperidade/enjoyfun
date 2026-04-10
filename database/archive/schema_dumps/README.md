# Historical Schema Dumps

Este diretorio guarda snapshots de schema que nao participam mais do fluxo
ativo de governanca nem do replay suportado atual.

Estado atual:

- `database/schema_dump_20260331.sql` permanece na raiz de `database/` porque
  e o seed vivo do replay suportado em `database/drift_replay_manifest.json`.
- `schema_dump_20260316.sql` e `schema_dump_20260401.sql` foram movidos para
  arquivo historico para reduzir ruido no diretorio principal.

Observacao:

- documentos historicos antigos podem citar os caminhos originais na raiz de
  `database/`; isso faz parte da trilha de auditoria, nao do contrato vivo.
