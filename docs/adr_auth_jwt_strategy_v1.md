# ADR — Estratégia Oficial de JWT/Auth v1

## Status
Aceita — 2026-03-08

## Contexto
- Havia divergência entre documentação interna e implementação real:
  - Documentação citava RS256.
  - Código operacional do helper JWT já estava em HS256 (HMAC com `JWT_SECRET`).
- Foi identificado bypass hardcoded de login em produção (`AuthController::login`), com risco crítico.
- O frontend já depende do fluxo atual (`/auth/login`, `/auth/refresh`, `/auth/me`) e não pode sofrer quebra.

## Decisão
Adotar **HS256 hardenizado como estratégia oficial imediata** de autenticação.

### Regras oficiais desta decisão
1. Assinatura JWT com HS256 usando `JWT_SECRET` obrigatório e forte.
2. Sem bypass hardcoded de credenciais no login.
3. Claims mínimas obrigatórias em operação:
   - `sub`, `role`, `organizer_id`, `iat`, `exp`
4. `refresh_token` continua opaco e armazenado como hash SHA-256 em banco.
5. Middleware (`requireAuth`) e payload continuam compatíveis com frontend/backend atual.

## Justificativa
- Menor risco de regressão no sistema em produção.
- Remove risco imediato de segurança (bypass).
- Mantém compatibilidade com tokens e clientes atuais.
- Cria base estável para futura migração planejada para RS256 sem “big-bang”.

## Mudanças implementadas nesta frente
- Remoção total do bypass hardcoded no login.
- Alinhamento textual/comentários do Auth Middleware e Auth Controller para HS256.
- Hardening no helper JWT:
  - validação de `alg` e `typ`
  - validação de claim `exp`
  - exigência de `JWT_SECRET` com mínimo de 32 caracteres.
- Reforço no refresh:
  - falha auditável para refresh inválido/expirado
  - bloqueio de refresh para usuário inativo.

## Compatibilidade e transição
- Fluxo atual preservado:
  - `POST /auth/login`
  - `POST /auth/refresh`
  - `GET /auth/me`
- Não houve mudança de contrato de payload para frontend.
- Tokens já emitidos por HS256 permanecem válidos até expiração natural.

## Plano de migração futura para RS256 (quando priorizado)
1. Introduzir suporte dual (`kid`) aceitando HS256+RS256 por janela controlada.
2. Emitir novos tokens em RS256.
3. Encerrar aceitação de HS256 após expiração total da janela.
4. Rotacionar chaves e registrar versionamento de chave ativa.

