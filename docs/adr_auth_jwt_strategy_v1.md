# ADR - Estrategia Oficial de JWT/Auth v1

> Atualizacao de 2026-04-09: este ADR permanece **historico**. O runtime atual ja opera com `RS256` em `backend/src/Helpers/JWT.php`. Use `docs/auth_strategy.md` e `README.md` como fonte viva.

## Status

Aceita - 2026-03-08
Revisada para rollout assimetrico - 2026-04-01

## Contexto

- O runtime de autenticacao em producao continua operando com `HS256`, via `backend/src/Helpers/JWT.php`, usando apenas `JWT_SECRET`.
- O helper atual:
  - emite `alg=HS256` e `typ=JWT`
  - nao materializa `kid`
  - nao valida `iss`, `aud`, `nbf` ou `jti`
  - valida apenas assinatura HMAC, `typ` e `exp`
- O emissor real de access token em `backend/src/Controllers/AuthController.php` depende hoje destas claims:
  - `sub`
  - `name`
  - `email`
  - `roles`
  - `role`
  - `sector`
  - `organizer_id`
  - `iat`
  - `exp`
- O middleware `backend/src/Middleware/AuthMiddleware.php` reconstrui o usuario autenticado a partir de:
  - `sub`
  - `name`
  - `email`
  - `role` ou `roles[0]`
  - `sector`
  - `organizer_id`
- O `refresh_token` atual nao e JWT e nao participa da discussao de algoritmo: ele continua opaco, aleatorio e persistido como hash SHA-256 em banco.
- O hardening desejado pela auditoria e sair de assinatura simetrica unica para um modelo assimetrico com janela de compatibilidade controlada.
- Existe dependencia colateral de `JWT_SECRET` fora do access token:
  - `AuthController::resolveOtpSecret()`
  - `SecretCryptoService`
  - `PaymentGatewayService`
  Isso impede tratar a retirada de `JWT_SECRET` como parte do mesmo corte da migracao do JWT.

## Problema

Hoje a seguranca do access token depende de um unico segredo compartilhado por todos os emissores e verificadores. Isso aumenta blast radius operacional, dificulta rotacao segura por `kid` e mantem drift entre o alvo de hardening desejado e o runtime real.

## Decisao

1. O runtime oficial imediato continua `HS256 hardenizado` ate a camada de compatibilidade assimetrica estar pronta.
2. O destino oficial do access token passa a ser **assinatura assimetrica**, com preferencia por `EdDSA` (`Ed25519`) quando o ambiente suportar isso em todos os nos verificadores.
3. `RS256` fica definido como fallback operacional aceitavel para ambientes em que `Ed25519` nao estiver disponivel ou nao puder ser validado com seguranca no stack real.
4. A migracao sera feita sem big-bang:
   - primeiro aceitar multiplos algoritmos de forma controlada
   - depois emitir tokens assimetricos
   - so por ultimo encerrar aceite de `HS256`
5. O contrato de payload para frontend e controllers nao muda nesta frente:
   - `name`
   - `email`
   - `role`
   - `roles`
   - `sector`
   - `organizer_id`
   permanecem emitidos durante toda a janela de compatibilidade
6. `refresh_token` continua opaco e fora do JWT rollout.
7. A retirada ou substituicao de `JWT_SECRET` em OTP/cifragem fica explicitamente fora deste corte; isso exige frente propria depois do cutover do access token.

## Contrato atual congelado

### Header exigido hoje

- `alg = HS256`
- `typ = JWT`

### Claims obrigatorias que o runtime realmente consome hoje

- `sub`
- `role`
- `organizer_id`
- `iat`
- `exp`

### Claims de compatibilidade que precisam continuar existindo no v2

- `name`
- `email`
- `roles`
- `sector`

## Contrato alvo do access token v2

### Header obrigatorio

- `alg`
- `typ = JWT`
- `kid`

### Claims obrigatorias do access token v2

- `sub`
- `role`
- `organizer_id`
- `iat`
- `exp`
- `iss`
- `aud`
- `jti`
- `authv`

### Claims mantidas por compatibilidade

- `name`
- `email`
- `roles`
- `sector`

### Regras do contrato alvo

1. `sub` continua sendo o `id` numerico do usuario autenticado.
2. `organizer_id` continua soberano para isolamento multi-tenant.
3. `role` continua sendo a claim primaria de autorizacao do backend.
4. `roles` permanece apenas para compatibilidade com clientes legados.
5. `authv` identifica a versao do contrato de autenticacao emitido.
6. `kid` passa a ser obrigatorio para qualquer token assimetrico.
7. `iss` e `aud` passam a ser validados no decode antes do aceite final.

## Fase de compatibilidade obrigatoria

### Fase 0 - congelar contrato e observabilidade

Objetivo: documentar o que existe hoje e preparar o rollout sem alterar o algoritmo.

Entradas obrigatorias:

- claims realmente consumidas por `requireAuth`
- dependencias colaterais de `JWT_SECRET`
- matriz de ambientes e suporte real a `Ed25519` / `OpenSSL`

Saidas obrigatorias:

- ADR revisada
- plano de rollout publicado
- checklist de validacao por ambiente

### Fase 1 - helper dual-stack

Objetivo: permitir verificacao por mais de um algoritmo, sem trocar emissao padrao ainda.

Mudancas esperadas no codigo quando esta fase for implementada:

- `backend/src/Helpers/JWT.php`
  - aceitar `kid`
  - resolver chave por `kid`
  - suportar `HS256`, `RS256` e `EdDSA` via configuracao
  - validar `iss`, `aud` e `jti`
- `backend/src/Controllers/AuthController.php`
  - emitir `kid`
  - emitir `iss`, `aud`, `jti` e `authv`
- `backend/src/Middleware/AuthMiddleware.php`
  - manter shape do usuario retornado, sem mudar contrato dos controllers

Flags/config esperadas:

- `JWT_SIGNING_MODE=hs256|rs256|eddsa`
- `JWT_ACCEPTED_ALGS=HS256,RS256,EdDSA`
- `JWT_ACTIVE_KID=<kid>`
- `JWT_ISSUER=<issuer>`
- `JWT_AUDIENCE=<audience>`
- `JWT_CLOCK_SKEW_SECONDS=<n>`
- `JWT_PUBLIC_KEYS_PATH` ou equivalente
- `JWT_PRIVATE_KEY_PATH` ou equivalente

### Fase 2 - emissao assimetrica com aceite legado

Objetivo: passar a emitir access token assimetrico, mas ainda aceitar `HS256`.

Regras:

- login e refresh passam a emitir novo token com `kid`
- `HS256` fica somente em verificacao
- `HS256` para de ser emitido
- `refresh_token` nao muda

### Fase 3 - drenagem de tokens HS256

Objetivo: esperar a expiracao natural de todo access token legado.

Regra minima:

- manter aceite de `HS256` por pelo menos:
  - `JWT_EXPIRY`
  - margem de clock skew
  - janela de deploy/rollback

Como o `refresh_token` e opaco, nao existe motivo para manter `HS256` alem da vida maxima do access token ja emitido.

### Fase 4 - aposentadoria do HS256 para access token

Objetivo: encerrar verificacao de `HS256`.

Pre-condicoes:

- nenhum emissor restante produzindo `HS256`
- metricas de decode sem erro sistemico
- canary e smoke completos em login, refresh e `/auth/me`

## Rotacao e distribuicao segura de chaves

### Principios

1. chave privada nunca entra em repositorio
2. chave privada nao e persistida em banco
3. verificadores precisam receber apenas a parte publica quando o algoritmo for assimetrico
4. cada chave ativa precisa de `kid` estavel
5. sempre manter pelo menos:
   - chave ativa de assinatura
   - chave anterior em modo verify-only durante a janela de drenagem

### Distribuicao segura

- ambientes locais e homologacao podem usar arquivo fora do git ou secret manager do host
- producao deve receber:
  - chave privada apenas nos nos emissores
  - chave publica em todos os nos que validam token
- o aplicativo nao deve depender de fetch remoto de chave em runtime na primeira versao; preferir carga local/configurada para reduzir superficie operacional

### Rotacao

1. publicar nova chave publica
2. distribuir nova chave privada ao emissor
3. promover novo `kid` como ativo para assinatura
4. manter `kid` anterior apenas para verificacao durante a drenagem
5. remover `kid` anterior so depois da expiracao total da janela

## Escolha entre EdDSA e RS256

### EdDSA preferido quando

- todos os ambientes relevantes suportarem `Ed25519` de forma auditavel
- a cadeia de deploy suportar distribuicao de chave publica/privada sem adaptacoes exoticas
- validadores auxiliares, CLIs e integrações conseguirem verificar `EdDSA`

### RS256 aceito como fallback quando

- existir restricao de runtime ou hospedagem que inviabilize `Ed25519`
- o stack precisar priorizar compatibilidade operacional imediata com bibliotecas ja disponiveis

### Decisao pratica desta ADR

- alvo preferido: `EdDSA`
- fallback oficial: `RS256`
- escolha final por ambiente deve ser validada antes da implementacao da Fase 2

## Riscos conhecidos

- hoje o helper de JWT nao carrega `kid`, logo qualquer migracao direta seria big-bang e insegura
- `optionalAuth()` devolve payload cru e pode mascarar diferenca de shape se a migracao alterar claims sem cuidado
- `JWT_SECRET` tem reuso fora de JWT; remover esse segredo cedo demais quebra OTP e cifragem auxiliar
- a rodada de autenticacao nao pode quebrar:
  - `POST /auth/login`
  - `POST /auth/refresh`
  - `GET /auth/me`
  - transporte por cookie `HttpOnly` quando `AUTH_ACCESS_COOKIE_MODE` estiver ativo

## Nao objetivos desta frente

- trocar algoritmo imediatamente
- substituir `refresh_token` por JWT
- remover `JWT_SECRET` do ambiente
- mudar payload retornado ao frontend

## Consequencias

- o sistema ganha um plano de migracao realista, com corte de compatibilidade explicito
- a futura implementacao assimetrica deixa de depender de suposicao vaga sobre `RS256`
- o rollout fica desacoplado da limpeza futura de dependencias colaterais de `JWT_SECRET`
