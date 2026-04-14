---
name: commit-conventions
description: >
  Commits atômicos e padronizados para o projeto EnjoyFun. Use sempre que for
  fazer commit, preparar staged changes, ou escrever mensagens de commit.
  Trigger: commit, git add, staged changes, mensagem de commit.
---

# Commit Conventions — EnjoyFun

## Formato
```
<tipo>(<escopo>): <descrição curta>

<corpo opcional — o que e por quê>

Refs: <ticket>
```

## Tipos
- `feat`: nova funcionalidade
- `fix`: correção de bug
- `refactor`: refactor sem mudar comportamento
- `migration`: nova migration SQL
- `docs`: documentação (ADR, progresso, runbook)
- `test`: smoke test, e2e, load test
- `chore`: config, CI/CD, Docker, deps
- `security`: hardening, rotação de credenciais, RLS

## Escopos (domínios EnjoyFun)
`auth`, `events`, `tickets`, `sales`, `cashless`, `parking`, `participants`, `workforce`, `artists`, `finance`, `dashboard`, `ai`, `settings`, `notifications`, `mobile`, `infra`

## Regras
1. Um commit = uma mudança lógica (atômico)
2. Descrição em inglês, imperativo, max 72 chars
3. Corpo explica **por quê**, não **o quê**
4. Migration sempre em commit separado do código que a consome
5. Nunca commitar `.env`, credenciais, ou `node_modules`
6. Se o commit toca IA, incluir qual service foi alterado no corpo

## Exemplos
```
feat(ai): add bounded loop synthesis step

When provider returns only tool_calls, orchestrator now makes
a second pass with tool_results to produce a final answer.
Max 1 extra roundtrip, no recursion.

Refs: S2-03
```

```
migration(artists): create artist_logistics table

069_create_artist_logistics.sql — RLS enabled,
organizer_id policy applied.

Refs: S3-01
```
