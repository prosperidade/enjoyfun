# Regras de Negócio e Validações — Módulos 1 e 2

> Stack: Node.js / TypeScript  
> Organização sugerida: `src/modules/logistics/rules/` e `src/modules/financial/rules/`

---

## Módulo 1 — Logística Operacional de Artistas

---

### R1.01 — Isolamento por organizer

Toda query deve incluir `WHERE organizer_id = :organizerId`.  
O `organizer_id` é sempre extraído do JWT, nunca aceito no body/params.

```typescript
// Exemplo de guard no service
async findArtist(artistId: string, organizerId: string) {
  const artist = await db.artists.findFirst({
    where: { id: artistId, organizer_id: organizerId }
  });
  if (!artist) throw new NotFoundException('ARTIST_NOT_FOUND');
  return artist;
}
```

---

### R1.02 — Documento único por organizer

`document_number` do artista deve ser único por `organizer_id`.

```typescript
const conflict = await db.artists.findFirst({
  where: {
    organizer_id,
    document_number: dto.document_number,
    id: { not: artistId } // ignora o próprio registro no PATCH
  }
});
if (conflict) throw new ConflictException('ARTIST_DOCUMENT_CONFLICT');
```

---

### R1.03 — Um artista por evento (sem duplicata)

Um artista não pode ser vinculado duas vezes ao mesmo evento.

```typescript
const exists = await db.event_artists.findFirst({
  where: { event_id, artist_id, organizer_id }
});
if (exists) throw new ConflictException('ARTIST_ALREADY_IN_EVENT');
```

---

### R1.04 — Logística: apenas 1 por vínculo

`artist_logistics` tem relação 1:1 com `event_artists`.  
`POST` deve verificar existência; se já existe, retornar `409 LOGISTICS_ALREADY_EXISTS`.

---

### R1.05 — Timeline: apenas 1 por vínculo

`artist_operational_timeline` tem relação 1:1 com `event_artists`.  
`POST` deve verificar existência; se já existe, retornar `409 TIMELINE_ALREADY_EXISTS`.

---

### R1.06 — `performance_start_datetime` obrigatório antes de criar timeline

Antes de criar a timeline, `event_artists.performance_start_datetime` deve estar preenchido.

```typescript
const eventArtist = await this.findEventArtist(eventArtistId, organizerId);
if (!eventArtist.performance_start_datetime) {
  throw new UnprocessableEntityException('PERFORMANCE_TIME_REQUIRED');
}
```

---

### R1.07 — `performance_end_datetime` calculado automaticamente

Se não enviado, calcula com base em `performance_start_datetime + performance_duration_min`.

```typescript
if (!dto.performance_end_datetime && eventArtist.performance_duration_min) {
  dto.performance_end_datetime = addMinutes(
    dto.performance_start_datetime,
    eventArtist.performance_duration_min
  );
}
```

---

### R1.08 — `planned_eta_minutes` calculado no servidor

Nunca aceito do cliente. Calculado como:

```typescript
planned_eta_minutes = eta_minutes_peak + safety_buffer_minutes;
```

---

### R1.09 — `total_amount` calculado no servidor

Em `artist_logistics_items`:

```typescript
total_amount = quantity * unit_amount;
```

---

### R1.10 — Item já pago não pode ser removido

```typescript
if (item.status === 'paid') {
  throw new ConflictException('ITEM_ALREADY_PAID');
}
```

---

### R1.11 — Artista com eventos ativos não pode ser inativado

```typescript
const activeEvents = await db.event_artists.count({
  where: { artist_id, status: { in: ['confirmed', 'pending'] } }
});
if (activeEvents > 0) throw new ConflictException('ARTIST_HAS_ACTIVE_EVENTS');
```

---

### R1.12 — Cartão: saldo não pode ser negativo

Ao registrar `consume`, verificar saldo disponível:

```typescript
const balance = card.credit_amount - card.consumed_amount;
if (dto.amount > balance) {
  throw new UnprocessableEntityException('INSUFFICIENT_BALANCE', {
    available: balance,
    requested: dto.amount
  });
}
```

---

### R1.13 — Cartão bloqueado ou cancelado não aceita transações

```typescript
if (card.status !== 'active') {
  throw new ConflictException('CARD_NOT_ACTIVE', { status: card.status });
}
```

---

### R1.14 — Crédito não pode ser aumentado em cartão bloqueado

```typescript
if (dto.credit_amount > card.credit_amount && card.status === 'blocked') {
  throw new ConflictException('CARD_IS_BLOCKED');
}
```

---

### R1.15 — Importação: token de preview expira em 15 minutos

```typescript
const PREVIEW_TOKEN_TTL_MINUTES = 15;
// Armazenar em Redis ou tabela temporária com TTL
```

---

### R1.16 — Importação: preview nunca persiste dados

A rota `/imports/preview` executa todo o pipeline de parse e validação em memória ou transação revertida. Nenhum dado é salvo.

---

### R1.17 — Importação: linhas inválidas com `skip_invalid_rows: false` abortam toda a importação

```typescript
if (!dto.skip_invalid_rows && batch.invalid_rows > 0) {
  throw new UnprocessableEntityException('IMPORT_HAS_INVALID_ROWS', {
    invalid_count: batch.invalid_rows
  });
}
```

---

### R1.18 — Arquivo: tamanho máximo 50 MB, tipos permitidos

```typescript
const MAX_SIZE_BYTES = 50 * 1024 * 1024;
const ALLOWED_MIME_TYPES = [
  'application/pdf',
  'image/jpeg',
  'image/png',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];
```

---

## Módulo 2 — Gestão Financeira Operacional do Evento

---

### R2.01 — Isolamento por organizer

Mesma regra de R1.01. `organizer_id` sempre do JWT.

---

### R2.02 — Código de categoria único por organizer

`code` da categoria deve ser único por `organizer_id`.

```typescript
const conflict = await db.event_cost_categories.findFirst({
  where: { organizer_id, code: dto.code, id: { not: categoryId } }
});
if (conflict) throw new ConflictException('CATEGORY_CODE_CONFLICT');
```

---

### R2.03 — Código de centro de custo único por evento

`code` do centro de custo deve ser único por `event_id`.

---

### R2.04 — Todo payable requer `category_id` e `cost_center_id`

Campos obrigatórios. Ambos devem pertencer ao mesmo `organizer_id`.

```typescript
const category = await db.event_cost_categories.findFirst({
  where: { id: dto.category_id, organizer_id }
});
if (!category) throw new UnprocessableEntityException('INVALID_CATEGORY_OR_COST_CENTER');
```

---

### R2.05 — `source_type` determina campos obrigatórios adicionais

| source_type | Campo obrigatório |
|---|---|
| `supplier` | `supplier_id` |
| `artist` | `artist_id` |
| `logistics` | `artist_id` ou `source_id` |
| `internal` | nenhum adicional |

```typescript
if (dto.source_type === 'supplier' && !dto.supplier_id) {
  throw new UnprocessableEntityException('VALIDATION_ERROR', {
    fields: { supplier_id: 'Obrigatório para source_type supplier.' }
  });
}
```

---

### R2.06 — Pagamento não pode exceder saldo devedor

```typescript
const remaining = payable.amount - payable.paid_amount;
if (dto.amount > remaining) {
  throw new UnprocessableEntityException('PAYMENT_EXCEEDS_BALANCE', {
    available: remaining,
    requested: dto.amount
  });
}
```

---

### R2.07 — Status do payable calculado automaticamente

Nunca definido diretamente pelo cliente. Calculado após cada operação:

```typescript
function calculatePayableStatus(payable: Payable): PayableStatus {
  if (payable.paid_amount >= payable.amount) return 'paid';
  if (payable.paid_amount > 0) return 'partial';
  if (new Date(payable.due_date) < new Date()) return 'overdue';
  return 'pending';
}
```

---

### R2.08 — Payable pago não pode ter `amount` editado

```typescript
if (payable.status === 'paid' && dto.amount !== undefined) {
  throw new ConflictException('CANNOT_EDIT_PAID_PAYABLE_AMOUNT');
}
```

---

### R2.09 — Payable com pagamentos não pode ser cancelado diretamente

```typescript
const paymentsCount = await db.event_payments.count({
  where: { payable_id }
});
if (paymentsCount > 0) throw new ConflictException('PAYABLE_HAS_PAYMENTS');
```

---

### R2.10 — Cancelamento exige justificativa

```typescript
if (!dto.reason || dto.reason.trim().length < 5) {
  throw new UnprocessableEntityException('CANCELLATION_REASON_REQUIRED');
}
```

---

### R2.11 — Estorno reverte `paid_amount` e recalcula status

```typescript
async reversePayment(paymentId: string, organizerId: string) {
  const payment = await this.findPayment(paymentId, organizerId);
  await db.$transaction([
    db.event_payments.delete({ where: { id: paymentId } }),
    db.event_payables.update({
      where: { id: payment.payable_id },
      data: {
        paid_amount: { decrement: payment.amount },
        paid_at: null
      }
    })
  ]);
  // recalcular status após transação
}
```

---

### R2.12 — `remaining_amount` calculado no servidor

```typescript
remaining_amount = payable.amount - payable.paid_amount;
```

Nunca aceito do cliente. Sempre retornado nas responses.

---

### R2.13 — Alerta de estouro de centro de custo

Ao criar ou atualizar um payable, verificar `budget_limit` do centro de custo:

```typescript
const center = await db.event_cost_centers.findFirst({ where: { id: dto.cost_center_id } });
if (center.budget_limit) {
  const committed = await db.event_payables.aggregate({
    where: { cost_center_id: center.id, status: { notIn: ['cancelled'] } },
    _sum: { amount: true }
  });
  const newTotal = (committed._sum.amount ?? 0) + dto.amount;
  if (newTotal > center.budget_limit) {
    // não bloqueia, mas retorna warning na response
    response.warnings = [{
      code: 'BUDGET_LIMIT_EXCEEDED',
      message: `Centro de custo "${center.name}" ultrapassará o teto: R$ ${newTotal.toFixed(2)} / R$ ${center.budget_limit.toFixed(2)}`
    }];
  }
}
```

---

### R2.14 — Orçamento: apenas 1 por evento

`event_budget` tem relação 1:1 com `events`. `POST` retorna `409 BUDGET_ALREADY_EXISTS` se já existir.

---

### R2.15 — Categoria inativa não aceita novos lançamentos

```typescript
if (!category.is_active) {
  throw new UnprocessableEntityException('CATEGORY_IS_INACTIVE');
}
```

---

### R2.16 — Categoria em uso não pode ser inativada

```typescript
const usage = await db.event_payables.count({
  where: { category_id, status: { notIn: ['cancelled'] } }
});
if (usage > 0) throw new ConflictException('CATEGORY_IN_USE');
```

---

### R2.17 — Fornecedor com CNPJ/CPF único por organizer

```typescript
const conflict = await db.suppliers.findFirst({
  where: { organizer_id, document_number: dto.document_number, id: { not: supplierId } }
});
if (conflict) throw new ConflictException('SUPPLIER_DOCUMENT_CONFLICT');
```

---

### R2.18 — Fornecedor com pendências não pode ser inativado

```typescript
const pending = await db.event_payables.count({
  where: { supplier_id, status: { in: ['pending', 'partial', 'overdue'] } }
});
if (pending > 0) throw new ConflictException('SUPPLIER_HAS_PENDING_PAYABLES');
```

---

### R2.19 — Importação: mesmas regras de R1.15, R1.16, R1.17

Token de preview com TTL de 15 min. Preview nunca persiste. `skip_invalid_rows` controla comportamento em caso de linhas inválidas.

---

### R2.20 — Exportação: evento deve pertencer ao organizer

```typescript
const event = await db.events.findFirst({ where: { id: eventId, organizer_id } });
if (!event) throw new NotFoundException('EVENT_NOT_FOUND');
```

---

## Validações de campos — referência rápida

### Artistas

| Campo | Regra |
|---|---|
| `stage_name` | required, max 200 chars |
| `legal_name` | required, max 200 chars |
| `document_type` | required, enum: cpf \| cnpj \| passport |
| `document_number` | required, único por organizer |
| `email` | format email válido |
| `phone` | formato E.164 ou nacional |
| `cache_amount` | decimal, min: 0 |
| `performance_duration_min` | int, min: 1, max: 600 |

### Logística

| Campo | Regra |
|---|---|
| `arrival_datetime` | required, ISO 8601 |
| `performance_start_datetime` | required, ISO 8601 |
| `performance_end_datetime` | obrigatório, deve ser > `performance_start_datetime` |
| `next_departure_deadline` | deve ser > `performance_end_datetime` |
| `eta_minutes_base` | required, int, min: 1 |
| `safety_buffer_minutes` | required, int, min: 0 |
| `transport_mode` | enum: car \| van \| helicopter \| motorcycle \| other |

### Financeiro

| Campo | Regra |
|---|---|
| `amount` | required, decimal, min: 0.01 |
| `due_date` | required, YYYY-MM-DD, não pode ser passado no POST |
| `payment_date` | required, YYYY-MM-DD |
| `payment_method` | required, enum: pix \| ted \| boleto \| cash \| credit_card \| other |
| `category_id` | required, deve existir e estar ativa |
| `cost_center_id` | required, deve existir no evento |
| `document_number` (fornecedor) | CPF (11 dígitos) ou CNPJ (14 dígitos), validar dígitos verificadores |
| `pix_key_type` | enum: cpf \| cnpj \| email \| phone \| random |

---

## Validação de CPF e CNPJ (TypeScript)

```typescript
export function validateCPF(cpf: string): boolean {
  const clean = cpf.replace(/\D/g, '');
  if (clean.length !== 11 || /^(\d)\1+$/.test(clean)) return false;
  let sum = 0;
  for (let i = 0; i < 9; i++) sum += parseInt(clean[i]) * (10 - i);
  let remainder = (sum * 10) % 11;
  if (remainder === 10 || remainder === 11) remainder = 0;
  if (remainder !== parseInt(clean[9])) return false;
  sum = 0;
  for (let i = 0; i < 10; i++) sum += parseInt(clean[i]) * (11 - i);
  remainder = (sum * 10) % 11;
  if (remainder === 10 || remainder === 11) remainder = 0;
  return remainder === parseInt(clean[10]);
}

export function validateCNPJ(cnpj: string): boolean {
  const clean = cnpj.replace(/\D/g, '');
  if (clean.length !== 14 || /^(\d)\1+$/.test(clean)) return false;
  const calc = (digits: string, weights: number[]) =>
    digits.split('').reduce((sum, d, i) => sum + parseInt(d) * weights[i], 0);
  const w1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  const w2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  const r1 = calc(clean.slice(0, 12), w1) % 11;
  const d1 = r1 < 2 ? 0 : 11 - r1;
  if (d1 !== parseInt(clean[12])) return false;
  const r2 = calc(clean.slice(0, 13), w2) % 11;
  const d2 = r2 < 2 ? 0 : 11 - r2;
  return d2 === parseInt(clean[13]);
}
```

---

## Transações de banco de dados — pontos críticos

Usar transações (`db.$transaction`) obrigatoriamente em:

| Operação | Motivo |
|---|---|
| Registrar pagamento | Atualiza `paid_amount` no payable + insere em `event_payments` |
| Estornar pagamento | Remove pagamento + decrementa `paid_amount` |
| Transação de cartão | Atualiza `consumed_amount` no cartão + insere em `artist_card_transactions` |
| Confirmar importação | Insere múltiplos registros + atualiza batch status |
| Cancelar payable | Atualiza status + registra justificativa |

---

*Regras de Negócio e Validações — Módulos 1 e 2 · EnjoyFun · Node.js / TypeScript*
