# Plano de Migracao JWT Assimetrico v1

## Objetivo

Definir o rollout operacional da migracao de access token de `HS256` para assinatura assimetrica, com preferencia por `EdDSA` e fallback para `RS256`, sem quebra de contrato para frontend ou controllers.

## Escopo

- access token JWT emitido por `POST /auth/login` e `POST /auth/refresh`
- verificacao em `requireAuth()` e `optionalAuth()`
- validacao de compatibilidade dos fluxos:
  - `POST /auth/login`
  - `POST /auth/refresh`
  - `GET /auth/me`

## Fora de escopo

- refresh token
- remocao de `JWT_SECRET` do ambiente
- migracao de OTP, cifragem auxiliar ou credenciais de gateways

## Dependencias reais do runtime atual

### Emissao atual

`AuthController::issueTokens()` emite:

- `sub`
- `name`
- `email`
- `roles`
- `role`
- `sector`
- `organizer_id`
- `iat`
- `exp`

### Consumo atual

`AuthMiddleware::requireAuth()` reconstrui usuario autenticado com:

- `sub`
- `name`
- `email`
- `role` ou `roles[0]`
- `sector`
- `organizer_id`

### Dependencias colaterais de `JWT_SECRET`

- `AuthController::resolveOtpSecret()`
- `SecretCryptoService`
- `PaymentGatewayService`

Conclusao operacional:

- a migracao do access token nao pode incluir a retirada imediata de `JWT_SECRET`
- o backend precisa continuar emitindo `role`, `roles`, `sector`, `name` e `email` na janela de compatibilidade

## Contrato alvo do token v2

### Header

- `alg`
- `typ = JWT`
- `kid`

### Claims obrigatorias

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

## Proposta de configuracao

### Variaveis de ambiente novas

- `JWT_SIGNING_MODE`
- `JWT_ACCEPTED_ALGS`
- `JWT_ACTIVE_KID`
- `JWT_ISSUER`
- `JWT_AUDIENCE`
- `JWT_CLOCK_SKEW_SECONDS`
- `JWT_PRIVATE_KEY_PATH`
- `JWT_PRIVATE_KEY_PASSPHRASE`
- `JWT_PUBLIC_KEYS_PATH`

### Variaveis que permanecem

- `JWT_SECRET`
- `JWT_EXPIRY`
- `JWT_REFRESH`
- `AUTH_ACCESS_COOKIE_MODE`
- `AUTH_ACCESS_COOKIE_NAME`
- `AUTH_REFRESH_COOKIE_NAME`

## Fases de rollout

### Fase 0 - preparacao

Entregas:

- ADR revisada
- plano de rollout publicado
- inventario de ambientes que validam token
- decisao final por ambiente:
  - `EdDSA`
  - ou `RS256`

Checklist:

- confirmar suporte de biblioteca/extension para assinatura assimetrica
- confirmar como a chave publica sera distribuida
- confirmar se existem consumidores externos do token alem da API principal

### Fase 1 - dual verify sem trocar emissao

Objetivo:

- backend passa a aceitar mais de um algoritmo no decode
- tokens novos ainda podem continuar saindo em `HS256`

Entregas tecnicas esperadas:

- helper JWT com resolucao por `kid`
- validacao de `iss` e `aud`
- emissao de `jti` e `authv`
- logs tecnicos com:
  - `alg`
  - `kid`
  - causa de decode failure

Go/no-go:

- `POST /auth/login` continua `200`
- `POST /auth/refresh` continua `200`
- `GET /auth/me` continua `200`
- cookie mode continua funcional

### Fase 2 - emissao assimetrica

Objetivo:

- login e refresh passam a emitir token com assinatura assimetrica
- `HS256` fica apenas em aceite temporario

Checklist operacional:

1. publicar chave publica nova
2. distribuir chave privada ao emissor
3. promover `JWT_ACTIVE_KID`
4. habilitar emissao assimetrica no ambiente canary
5. validar:
   - login
   - refresh
   - me
   - rotacao de refresh token
   - transporte por cookie e por body

### Fase 3 - drenagem

Objetivo:

- aguardar expirar todo access token `HS256` ainda valido

Janela minima:

- `JWT_EXPIRY`
- mais clock skew
- mais margem de deploy

### Fase 4 - corte do HS256

Objetivo:

- remover `HS256` da lista de aceite do access token

Pre-condicoes:

- nenhum emissor restante usando `HS256`
- sem falha anormal no canary
- sem decode error por `kid` ausente nos tokens novos

## Rotacao de chaves

### Procedimento

1. gerar nova chave
2. cadastrar novo `kid`
3. publicar chave publica
4. distribuir chave privada apenas aos emissores
5. tornar o novo `kid` ativo para assinatura
6. manter o `kid` anterior em verify-only
7. remover o `kid` anterior apos a drenagem

### Regras

- nunca reutilizar `kid`
- nunca sobrescrever a chave ativa sem preservar a anterior durante a drenagem
- nunca armazenar chave privada no repositorio

## Distribuicao segura

### Homologacao/local

- arquivo local fora do git ou secret do host

### Producao

- chave privada apenas nos processos que emitem token
- chave publica em todos os processos que validam token
- preferir configuracao local/secret manager em vez de dependencia remota em runtime na primeira versao

## Telemetria minima antes do corte

- contagem de tokens verificados por `alg`
- contagem de tokens verificados por `kid`
- falhas por:
  - assinatura invalida
  - `kid` ausente
  - `iss` invalido
  - `aud` invalido
  - expiracao

## Rollback

Rollback rapido permitido:

- voltar `JWT_SIGNING_MODE=hs256`
- manter aceite dos algoritmos novos para nao quebrar tokens emitidos durante canary

Rollback proibido:

- remover chave publica do algoritmo novo antes da expiracao dos tokens que ja foram emitidos

## Validacao minima por ambiente

### Smoke funcional

1. `POST /auth/login`
2. `POST /auth/refresh`
3. `GET /auth/me`
4. rota autenticada multi-tenant com `organizer_id` sensivel
5. fluxo com `AUTH_ACCESS_COOKIE_MODE=1`
6. fluxo com transporte por body

### Checklist de claims

- `sub`
- `role`
- `organizer_id`
- `iat`
- `exp`
- `iss`
- `aud`
- `jti`
- `authv`
- `name`
- `email`
- `roles`
- `sector`
