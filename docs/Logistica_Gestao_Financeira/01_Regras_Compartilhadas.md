# 01 — Regras Compartilhadas

> Regras transversais obrigatórias para os módulos novos no EnjoyFun.

---

## 1. Isolamento por organizer

`organizer_id` sempre vem do JWT no backend.

### Regras
- nunca aceitar `organizer_id` via URL
- nunca aceitar `organizer_id` via query string
- nunca aceitar `organizer_id` via body
- toda query e toda escrita filtra por `organizer_id`

---

## 2. Escopo por evento

`event_id` é obrigatório sempre que a operação for contextual ao evento.

### Como o escopo funciona
- em listagens e consultas: `event_id` via query string
- em criação/atualização: `event_id` via body quando aplicável
- em leituras de registro existente: o backend valida o `event_id` vinculado ao próprio registro

### Regra de desenho
Não documentar URL longa aninhada como padrão principal.

**Correto:**
- `/api/artists/bookings?event_id=10`
- `/api/event-finance/payables?event_id=10`

**Não usar como padrão principal:**
- `/api/events/10/artists/...`
- `/api/events/10/event-finance/...`

---

## 3. Padrão de rota

Toda API nova fica em `/api`.

### Regra fechada
- usar `/api`
- não usar `/api/v1`

---

## 4. Envelope de resposta

O padrão oficial de resposta continua o já usado no sistema:
- `success`
- `data`
- `message`
- `meta` opcional

### Exemplos

```json
{
  "success": true,
  "data": {
    "id": 123
  },
  "message": "Registro criado com sucesso."
}
```

```json
{
  "success": true,
  "data": [
    { "id": 1 },
    { "id": 2 }
  ],
  "message": "Lista carregada com sucesso.",
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 120
  }
}
```

```json
{
  "success": false,
  "data": null,
  "message": "event_id é obrigatório."
}
```

---

## 5. Backend e frontend

### Frontend
- React em `.jsx`
- não padronizar `.tsx` para estes módulos

### Backend
- padrão principal: `Controller + Helpers`
- `Services` só para:
  - motor reutilizável de cálculo
  - integração real com infraestrutura externa
  - fluxo transacional mais complexo que não cabe limpo em controller/helper

---

## 6. Banco de dados

### Convenções obrigatórias
- tabelas em `snake_case`
- nomes no plural
- PK numérica
- usar `SERIAL`, `IDENTITY` ou `BIGINT` quando fizer sentido
- UUID apenas onde já existe ou quando for identificador público/técnico realmente necessário

### Dinheiro
Campos monetários devem usar `NUMERIC`, nunca `FLOAT` ou `DOUBLE`.

Padrão recomendado:
- `NUMERIC(14,2)` para valores operacionais comuns
- ampliar precisão apenas se o domínio exigir claramente

---

## 7. Status, cancelamento e exclusão

Não existe regra de “soft delete em tudo”.

### Usar status / inativação / cancelamento quando houver rastreabilidade relevante
Aplicável para:
- vínculo operacional relevante
- histórico financeiro
- alertas e estados relevantes para auditoria

Campos recomendados conforme o caso:
- `status`
- `is_active`
- `cancelled_at`
- `cancellation_reason`

### Delete físico pode ser usado
Permitido para detalhe descartável, desde que não quebre:
- rastreabilidade
- conciliação
- histórico operacional importante
- integridade financeira

---

## 8. Regras de status por domínio

### Contas a pagar
Status calculado pelo backend, nunca confiado ao cliente.

Ordem de precedência sugerida:
1. `cancelled`
2. `paid`
3. `partial`
4. `overdue`
5. `pending`

### Pagamentos
- `posted` para pagamento válido
- `reversed` para pagamento estornado

### Alertas operacionais
- `open`
- `acknowledged`
- `resolved`
- `dismissed`

---

## 9. Importação em lote

Toda importação deve seguir o fluxo:
1. upload do arquivo
2. preview/parse/validação
3. exibição de erros e impactos
4. confirmação explícita
5. aplicação definitiva

### Regra obrigatória
Nunca inserir em lote sem preview + confirmação.

### Estrutura mínima
Cada domínio de importação terá:
- tabela de batches
- tabela de rows
- status do batch
- status por linha
- relatório de erro por linha

---

## 10. Limite do roteador atual

O roteador atual trabalha por primeiro segmento e, na prática, favorece desenho curto de rota.

### Consequência de documentação
Devemos privilegiar:
- recurso raiz curto
- subrecurso curto
- `event_id` em query/body

Não documentar árvore longa e profundamente aninhada como padrão principal.

---

## 11. Auditoria mínima recomendada

Para registros principais, manter ao menos:
- `created_at`
- `updated_at`
- `created_by` quando o domínio já usa esse padrão
- `updated_by` quando o domínio já usa esse padrão

---

## 12. Regra de compatibilidade futura

Qualquer documento novo deve herdar estas decisões sem reinterpretar:
- `/api`
- `Controller + Helpers`
- React `.jsx`
- PK numérica
- envelope `success/data/message/meta`
- escopo por `event_id` fora de URL longa
