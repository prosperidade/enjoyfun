# Auditorias — índice consolidado

## Objetivo

Centralizar a leitura das auditorias técnicas e separar:

- o que é **fonte viva de operação**
- o que é **snapshot histórico**
- o que já pode entrar em **fila de exclusão/arquivamento**

Este arquivo passa a ser a porta de entrada única para auditorias do projeto.

## Fonte viva atual

| Arquivo | Papel |
|---|---|
| `docs/progresso10.md` | Diário ativo da rodada corrente |
| `docs/progresso9.md` | Fechamento da rodada anterior e base de decisão |
| `README.md` | Contrato de setup, stack e governança de schema |
| `CLAUDE.md` | Estado arquitetural operacional para IA/Codex |
| `docs/runbook_local.md` | Bootstrap local e smoke mínimo operacional |
| `docs/diagnostico.md` | Diagnóstico técnico único mantido no repo |
| `docs/cardsemassa.md` | Registro específico da frente de cartões em massa |
| `analise_enjoyfun_2026_03_22.md` | Análise ampla do estado real, riscos e roadmap |
| `auditoriaCodex.md` | Auditoria técnica consolidada por risco/ação |

## Arquivos aprovados para mover ao arquivo externo

Estes arquivos ainda podem ficar disponíveis temporariamente no workspace, mas **saem do conjunto operacional do repositório**:

- `docs/auditoriaPOS.md`
- `docs/auditoriaworkforce.md`
- `docs/auditoriamelas.md`
- `docs/auditoriafinalworkforcemeals.md`
- `docs/diagnostico_sistema.md`
- `docs/security_audit_enjoyfun_v2.md`
- `docs/EnjoyFun_Blueprint_V5.md`
- `docs/enjoyfun_blueprint_dashboard_v_1.md`
- `docs/enjoyfun_backlog_oficial_v_1.md`
- `docs/enjoyfun_arquitetura_modulos_servicos_v_1.md`
- `docs/enjoyfun_kpis_formulas_oficiais_v_1.md`
- `docs/enjoyfun_modelagem_oficial_banco_v_1.md`
- `docs/enjoyfun_mvps_oficiais_v_1.md`
- `docs/enjoyfun_orientacao_operacional_a_partir_de_hoje.md`
- `docs/enjoyfun_plano_execucao_fase_1_v_1.md`
- `docs/enjoyfun_prompts_oficiais_codex_v_1.md`
- `docs/enjoyfun_roadmap_implementacao_v_1.md`
- `docs/enjoyfun_sprint_1_v_1.md`
- `docs/enjoyfun_tenant_settings_hub_v_1.md`

## Análises-base complementares da rodada atual

| Arquivo | Situação |
|---|---|
| `analise_enjoyfun_2026_03_22.md` | Mantida como base complementar de leitura, além do resumo em `docs/progresso9.md` |
| `auditoriaCodex.md` | Mantida como base complementar de leitura, além do resumo em `docs/progresso9.md` |

## Regra de organização adotada

1. `docs/auditorias.md` vira o índice único das auditorias.
2. O estado vivo da operação não deve depender de snapshots antigos.
3. O estado vivo da operação deve partir de:
   - `docs/progresso10.md`
   - `docs/progresso9.md`
   - `README.md`
   - `CLAUDE.md`
   - `analise_enjoyfun_2026_03_22.md`
   - `auditoriaCodex.md`
4. Toda nova auditoria relevante deve ser:
   - registrada primeiro no arquivo próprio
   - resumida depois em `docs/progresso10.md`
   - catalogada aqui

## Observação sobre os `progresso*.md`

Todos os `docs/progresso*.md` permanecem no repositório como trilha histórica de pesquisa.
