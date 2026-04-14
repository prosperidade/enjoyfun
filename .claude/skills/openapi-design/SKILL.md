---
name: openapi-design
description: >
  Design e documentação de APIs REST da EnjoyFun. Use ao criar endpoints,
  documentar APIs, ou definir contratos entre backend e frontend.
  Trigger: API, endpoint, rota, contrato, OpenAPI, REST, request, response.
---

# OpenAPI Design — EnjoyFun

## Padrão de Endpoint
```
{MÉTODO} /api/{domínio}/{recurso}
```

### Convenções
- Recursos em inglês, plural (`/events`, `/tickets`, `/participants`)
- IDs no path (`/events/{id}`)
- Filtros em query string (`?status=active&page=1`)
- Ações especiais: verbo no path (`/tickets/{id}/validate`)

## Padrão de Response
```json
{
  "success": true,
  "data": { ... },
  "meta": { "page": 1, "total": 42 }
}
```

### Erros
```json
{
  "success": false,
  "error": "Descrição em português para o usuário",
  "code": "TICKET_ALREADY_USED"
}
```

### HTTP Status
- `200` — sucesso
- `201` — criado
- `400` — input inválido
- `401` — não autenticado
- `403` — sem permissão (organizer errado)
- `404` — recurso não encontrado
- `409` — conflito (ticket já usado, saldo insuficiente)
- `429` — rate limit
- `500` — erro interno

## Headers Obrigatórios
- Request: `Authorization: Bearer {jwt}`, `Content-Type: application/json`
- Response: `X-Request-Id`, `X-RateLimit-Remaining`

## Regras
1. Todo endpoint autenticado via `AuthMiddleware`
2. `organizer_id` extraído do JWT — nunca aceitar do body
3. Endpoints de lista DEVEM ter paginação (`page`, `per_page`)
4. Endpoints de IA: prefixo `/api/ai/` (`/api/ai/ask`, `/api/ai/approve`)
5. Documentar contratos em `docs/contratos_minimos_api.md`
