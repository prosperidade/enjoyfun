# Auditorias - indice consolidado

## Objetivo

Definir quais documentos representam o estado vivo do sistema em `2026-04-09`, quais sao historicos e quais ja entraram em fila de arquivamento.

Este arquivo e a porta de entrada unica para auditorias e prontidao operacional.

## Fonte viva atual

| Arquivo | Papel |
|---|---|
| `README.md` | Contrato de stack, setup e governanca do repositorio |
| `docs/runbook_local.md` | Bootstrap local, smoke minimo e criterios de readiness |
| `docs/auth_strategy.md` | Estado atual de autenticacao |
| `docs/auditoria_prontidao_operacional_2026_04_09.md` | Parecer atual de seguranca, operacionalidade e escala |
| `docs/inventario_documental_e_artefatos_2026_04_09.md` | Inventario de documentacao viva, historica e artefatos candidatos a arquivo |
| `backend/.env.example` | Contrato atual de variaveis de ambiente |

## Documentos historicos relevantes

| Arquivo | Observacao |
|---|---|
| `docs/archive/root_legacy/auditoria+backlogexecutavel.md` | Backlog tematico da frente anterior; nao e retrato integral da prontidao atual |
| `docs/diagnostico.md` | Diagnostico anterior, hoje superado em varios pontos |
| `docs/adr_auth_jwt_strategy_v1.md` | ADR historico de migracao JWT |
| `docs/plano_migracao_jwt_assimetrico_v1.md` | Plano historico de rollout JWT |
| `docs/progresso*.md` | Trilha historica de execucao e investigacao |
| `docs/archive/root_legacy/` | Snapshots historicos uteis para comparacao, nao para operacao corrente |

## Arquivamento executado nesta rodada

Foi executado nesta rodada:

- limpeza da raiz do repositorio, mantendo apenas `README.md` e `CLAUDE.md`
- movimentacao das auditorias e analises historicas para `docs/archive/root_legacy/`
- movimentacao de SQL legado avulso para `database/archive/legacy_sql/`
- remocao dos artefatos gerados `frontend/vite.config.js.timestamp-*`
- bloqueio de recorrencia desses artefatos no `.gitignore`

Os proximos candidatos seguem detalhados em `docs/inventario_documental_e_artefatos_2026_04_09.md`.

## Regra de manutencao daqui para frente

1. Novas auditorias devem nascer em `docs/`, nunca na raiz do projeto.
2. O README e o runbook precisam ser atualizados na mesma rodada sempre que o runtime mudar.
3. Documentos historicos devem receber aviso explicito quando deixarem de refletir o estado real.
4. Artefatos gerados nao devem voltar a ser versionados sem justificativa operacional.
