---
name: markdown-docs
description: >
  Documentação técnica EnjoyFun: ADRs, runbooks, progresso, auditorias.
  Use ao criar ou editar docs em docs/. Trigger: ADR, runbook, progresso,
  documentação, auditoria, changelog, docs/.
---

# Markdown Docs — EnjoyFun

## Tipos de Documento

### ADR (Architecture Decision Record)
Arquivo: `docs/adr_{tema}_v{N}.md`
```markdown
# ADR: {Título}
## Status: Aceito | Proposto | Obsoleto
## Contexto
## Decisão
## Consequências
## Alternativas Consideradas
```

### Progresso (log de passada)
Arquivo: `docs/progresso{N}.md`
```markdown
# Atualização de YYYY-MM-DD — {descrição curta}

### Registro obrigatório desta passada
- **Responsável:** `{trilha}`
- **Status:** `Entregue | Em progresso`
- **Escopo:** `backend`, `frontend`, `mobile`, `documentação`
- **Arquivos principais tocados:** lista
- **Próxima ação sugerida:** próximo corte natural
- **Bloqueios / dependências:** lista ou "nenhum"

### Escopo fechado
- item 1
- item 2

### Validação executada
- `php -l ...`
- `bash tests/...`
```

### Runbook
Arquivo: `docs/runbook_{contexto}.md`
- Passos reproduzíveis por qualquer dev
- Comandos copiáveis (blocos de código)
- Seção de troubleshooting

## Regras
1. Todo documento tem data no cabeçalho
2. Progresso é append-only — nunca editar passadas anteriores
3. Próximo número de progresso = último + 1
4. ADRs versionados (v1, v2) — não editar versão publicada
