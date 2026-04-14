---
name: changelog
description: >
  Manutenção do CHANGELOG.md por sprint da EnjoyFun. Use ao finalizar
  uma sprint, preparar release notes, ou documentar mudanças.
  Trigger: changelog, release notes, sprint finalizada, versão, release.
---

# Changelog — EnjoyFun

## Formato (Keep a Changelog)
```markdown
# Changelog

## [Sprint 4] — 2026-04-29
### Adicionado
- Bounded loop v2 no AIOrchestratorService
- 12 migrations (069–086) com RLS

### Alterado
- AIContextBuilder refatorado de 2600 para 500 linhas

### Corrigido
- RLS ativado no runtime PHP (Database.php conecta como app_user)

### Segurança
- Credenciais rotacionadas (DB_PASS, JWT_SECRET)
- pg_hba.conf alterado para scram-sha-256

### Removido
- Legacy path sem bounded loop (feature flag removida)
```

## Regras
- Uma seção por sprint
- Categorias: Adicionado, Alterado, Corrigido, Segurança, Removido
- Em português
- Referências a tickets quando houver (`Refs: S2-03`)
- Atualizar ao final de cada sprint, não a cada commit
