---
name: security-audit
description: >
  Hardening e auditoria de segurança do EnjoyFun. Use ao revisar segurança,
  implementar hardening, rotacionar credenciais, ou verificar OWASP Top 10.
  Trigger: segurança, security, hardening, OWASP, credenciais, rotação, vulnerabilidade, scan.
---

# Security Audit — EnjoyFun

## Checklist OWASP Top 10 Adaptado

### A01 — Broken Access Control
- [ ] RLS ativo em todas as tabelas com `organizer_id`
- [ ] `organizer_id` extraído do JWT, nunca de input
- [ ] Super Admin isolado de dados de organizers
- [ ] Endpoints de write verificam ownership

### A02 — Cryptographic Failures
- [ ] Senhas: `password_hash()` / `password_verify()` (bcrypt)
- [ ] API keys: `pgp_sym_encrypt` via `SecretCryptoService`
- [ ] JWT: HS256 com secret ≥256-bit (RS256 no roadmap)
- [ ] `.env` nunca no git, credenciais nunca hardcoded

### A03 — Injection
- [ ] SQL: prepared statements SEMPRE (`$pdo->prepare()`)
- [ ] XSS: output encoding no frontend
- [ ] Prompt injection: `AIPromptSanitizer` ativo
- [ ] Command injection: sem `exec()/shell_exec()` com user input

### A05 — Security Misconfiguration
- [ ] `pg_hba.conf`: `scram-sha-256` em produção (não `trust`)
- [ ] CORS restrito a domínios conhecidos
- [ ] Headers: `X-Content-Type-Options`, `X-Frame-Options`, `Strict-Transport-Security`
- [ ] Error messages: sem stack traces em produção

### A07 — Auth Failures
- [ ] Rate limiting em `/auth/login` (DB-based via `AIRateLimitService` pattern)
- [ ] Token expiration configurado
- [ ] Logout invalida token

### Rotação de Credenciais
```bash
# Gerar nova DB password
openssl rand -base64 18
# Gerar novo JWT secret
openssl rand -hex 32
# Gerar novo OTP pepper
openssl rand -hex 16
```

### Scan Automatizado
```bash
bash tests/security_scan.sh
```
