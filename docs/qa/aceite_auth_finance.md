# Aceite Operacional — Auth + Financial Layer v1.1

## Objetivo
Padronizar a validação manual (reproduzível no Git) dos fluxos endurecidos de **Auth & Security v1.1** e **Financial Layer v1.1**.

## Artefato versionado
- Coleção Postman: `docs/qa/enjoyfun_auth_finance_aceite.postman_collection.json`

## Referência de estratégia Auth/JWT (estado real do repositório)
- `docs/adr_auth_jwt_strategy_v1.md`: ADR oficial da estratégia atual de Auth/JWT.
- `docs/auth_strategy.md`: resumo operacional da estratégia vigente.
- Nesta frente, a validação de Auth está ancorada no contrato dos endpoints implementados (`/auth/login`, `/auth/refresh`, `/auth/me`).

## Escopo de gateways desta frente
Gateways suportados e documentados neste aceite:
1. `mercadopago` (**provider padrão da coleção**)
2. `pagseguro`
3. `asaas`
4. `pagarme`

> Fora de escopo desta frente: qualquer gateway não listado acima.

## Pré-requisitos
1. Backend rodando com rota base `/api`.
2. Usuário válido para autenticação (perfil `admin` ou `organizer` para fluxo financeiro).
3. Banco com migrations recentes aplicadas para camada financeira.
4. Definir variáveis da coleção:
   - `base_url` (ex.: `http://localhost:8000/api`)
   - `auth_email`
   - `auth_password`
   - `gateway_provider` (`mercadopago`, `pagseguro`, `asaas`, `pagarme`)
   - `gateway_access_token`
   - `gateway_api_key`

## Campos mínimos por provider (contrato de teste de conexão)
- `mercadopago` → `access_token`
- `pagseguro` → `access_token`
- `asaas` → `api_key`
- `pagarme` → `api_key`

## Ajuste de credenciais no body
A coleção envia `credentials.access_token` e `credentials.api_key` no create/edit.
- Para `mercadopago` / `pagseguro`: preencher `gateway_access_token`.
- Para `asaas` / `pagarme`: preencher `gateway_api_key`.

## Ordem recomendada de execução (coleção)
1. Auth - Login válido
2. Auth - Login inválido
3. Auth - Refresh válido
4. Auth - Refresh inválido
5. Auth - /auth/me
6. Finance - Listar gateways
7. Finance - Criar gateway
8. Finance - Editar gateway
9. Finance - Inativar gateway
10. Finance - Ativar gateway
11. Finance - Definir principal
12. Finance - Testar conexão (gateway por id)

---

## Checklist de aceite — Auth
- [ ] `POST /auth/login` com credenciais válidas retorna `200`, `success=true` e `data` com `access_token` + `refresh_token`.
- [ ] `POST /auth/login` com senha inválida retorna `401` e `success=false`.
- [ ] `POST /auth/refresh` com `refresh_token` válido retorna `200`, `success=true` e renova tokens em `data`.
- [ ] `POST /auth/refresh` com token inválido retorna `401` e `success=false`.
- [ ] `GET /auth/me` com bearer válido retorna `200`, `success=true` e dados do usuário em `data`.

## Checklist de aceite — Finance
- [ ] `GET /organizer-finance/gateways` retorna `200`, `success=true` e `data` (lista).
- [ ] `POST /organizer-finance/gateways` retorna `201`, `success=true` e `data` do gateway criado.
- [ ] `PUT /organizer-finance/gateways/{id}` retorna `200`, `success=true` e `data` atualizado.
- [ ] `PATCH /organizer-finance/gateways/{id}/active` (false/true) retorna `200`, `success=true` e `data`.
- [ ] `PATCH /organizer-finance/gateways/{id}/primary` retorna `200`, `success=true` e `data`.
- [ ] `POST /organizer-finance/gateways/{id}/test` retorna `200` quando credencial mínima do provider foi preenchida; se não, retorna `422`.

---

## Lacunas pendentes (sem mascarar)
1. Os documentos de Auth existem, mas precisam permanecer alinhados ao código operacional em HS256.
2. A execução real depende de credenciais válidas do tenant para cada provider.
3. Este aceite valida configuração e conectividade mockada; não cobre checkout/transação financeira real.
