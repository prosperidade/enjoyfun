---
name: review-pr
description: >
  Cross-check de PRs entre trilhas do EnjoyFun (Backend, Mobile, Codex).
  Use ao revisar PRs, fazer code review, ou antes de merge em qualquer branch.
  Trigger: review, PR, pull request, merge, code review.
---

# Review PR — EnjoyFun

## Checklist Obrigatório

### Segurança
- [ ] `organizer_id` vem do JWT, nunca de input externo
- [ ] Novas tabelas têm RLS habilitado
- [ ] Sem credenciais hardcoded ou expostas
- [ ] `AuditService::log()` em ações sensíveis
- [ ] Input sanitizado (SQL injection, XSS, prompt injection)

### Arquitetura
- [ ] Naming segue convenções (ver skill `emas-architecture`)
- [ ] Services não acessam `$_GET/$_POST` diretamente — controllers fazem isso
- [ ] Migration tem prefixo numérico sequencial correto
- [ ] Feature flag para mudanças comportamentais

### IA (se toca services de IA)
- [ ] Bounded loop respeitado (max 3 steps)
- [ ] Tools write nunca auto-executadas
- [ ] `tool_results` não inflam `tool_calls_json` persistido
- [ ] Compatibilidade com 3 providers (OpenAI/Gemini/Claude)

### Frontend (se toca React)
- [ ] Componentes PascalCase
- [ ] Hooks com prefixo `use`
- [ ] Sem state global desnecessário
- [ ] Loading/error states tratados

### Mobile (se toca React Native)
- [ ] TypeScript strict
- [ ] Offline-first considerado
- [ ] Sem dependência de rede para fluxos críticos

### Cross-Trilha
- [ ] PR do backend não quebra contratos de API que o frontend consome
- [ ] Migration não tem side-effect em queries existentes
- [ ] Progresso documentado em `docs/progresso{N}.md`
