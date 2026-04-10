# Authentication Strategy

## Estado Atual

O runtime atual de autenticacao ja opera com **JWT RS256** no helper `backend/src/Helpers/JWT.php`.

- O access token e assinado pela chave privada e validado pela chave publica.
- As chaves podem vir de `private.pem` / `public.pem` ou de `JWT_PRIVATE_KEY` / `JWT_PUBLIC_KEY`.
- O helper valida `alg`, `typ`, assinatura, `iss`, `exp` e, quando presentes, `nbf`, `aud` e `jti`.

## Claims Contratuais

Os tokens de acesso carregam, no minimo:

- `sub`
- `name`
- `email`
- `role`
- `roles`
- `sector`
- `organizer_id`
- `iss`
- `iat`
- `nbf`
- `exp`
- `jti`

`organizer_id` continua sendo a chave principal de isolamento multi-tenant no runtime da aplicacao.

## Refresh e Sessao

- O access token pode trafegar por cookie HttpOnly ou por body, conforme configuracao do ambiente.
- Refresh tokens continuam opacos e persistidos como hash em `refresh_tokens`.
- O frontend usa `sessionStorage` para o estado de sessao e faz refresh automatico em `401`.

## Segredos Correlatos

`JWT_SECRET` **nao** foi removido do sistema.

Ele ainda participa de:

- OTP
- HMAC offline
- cifragem auxiliar
- fallback criptografico em servicos legados

Isso significa que a postura atual de auth e hibrida:

- `RS256` para access token
- `JWT_SECRET` para subsistemas auxiliares

## RLS no Runtime

O isolamento multi-tenant autenticado depende de `Database::activateTenantScope()` em `backend/config/Database.php`.

- o runtime abre uma conexao separada com `DB_USER_APP` e `DB_PASS_APP`
- essa conexao faz `SET app.current_organizer_id` por request autenticada
- se a ativacao falhar, a request falha em modo fail-closed
- a conexao superuser permanece separada para operacoes administrativas explicitas

Esse desenho reduz o risco de uma request autenticada operar fora do escopo tenant esperado por erro de ambiente.

## Documentos Historicos

- `docs/adr_auth_jwt_strategy_v1.md`
- `docs/plano_migracao_jwt_assimetrico_v1.md`

Esses documentos permanecem uteis para rastreabilidade, mas nao devem ser usados como retrato fiel do runtime atual sem esta atualizacao.
