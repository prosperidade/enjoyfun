---
name: git-worktree
description: >
  Git worktrees para trabalho paralelo em trilhas EnjoyFun (Backend, Mobile, Codex).
  Use ao configurar branches paralelos, isolar trabalho de trilhas, ou
  evitar conflitos entre frentes. Trigger: worktree, branch paralelo, trilha, isolamento.
---

# Git Worktree — EnjoyFun

## Conceito
Worktrees permitem ter múltiplas branches checked out simultaneamente em diretórios separados — zero conflito entre trilhas.

## Setup Recomendado
```bash
# Na raiz do repo principal
git worktree add ../enjoyfun-backend feat/backend-sprint2
git worktree add ../enjoyfun-mobile feat/mobile-sprint2
git worktree add ../enjoyfun-codex feat/codex-sprint2
```

## Estrutura Resultante
```
projetos/
├── enjoyfun/              # main (coordenador)
├── enjoyfun-backend/      # feat/backend-sprint2
├── enjoyfun-mobile/       # feat/mobile-sprint2
└── enjoyfun-codex/        # feat/codex-sprint2
```

## Comandos
```bash
git worktree list                    # listar worktrees
git worktree add <path> <branch>     # criar
git worktree remove <path>           # remover (após merge)
```

## Regras EnjoyFun
- Cada trilha (Backend, Mobile, Codex) opera em worktree próprio
- Merge sempre via PR com review (skill `review-pr`)
- Conflitos resolvidos na branch de destino, não na worktree
- `docs/progresso{N}.md` commitado na branch da trilha responsável
- Worktree removido após merge da feature
