# Setup do Banco de Dados — EnjoyFun

> Stack: Node.js / TypeScript · Prisma ORM · PostgreSQL

---

## 1. Pré-requisitos

```bash
node >= 18
npm >= 9
PostgreSQL >= 14 (local ou RDS / Supabase / Railway)
```

---

## 2. Instalar dependências

```bash
npm install prisma @prisma/client
npm install -D prisma
```

---

## 3. Configurar variável de ambiente

Criar `.env` na raiz do projeto:

```env
DATABASE_URL="postgresql://usuario:senha@localhost:5432/enjoyfun_dev?schema=public"
```

---

## 4. Copiar o schema

Salvar o arquivo `schema.prisma` em:

```
prisma/schema.prisma
```

---

## 5. Rodar a migration inicial

```bash
npx prisma migrate dev --name init_logistics_financial
```

Isso irá:
- Criar todas as tabelas dos Módulos 1 e 2
- Criar todos os enums
- Criar todos os índices
- Gerar o Prisma Client atualizado

---

## 6. Gerar o client (se necessário separado)

```bash
npx prisma generate
```

---

## 7. Visualizar o banco no Prisma Studio

```bash
npx prisma studio
```

Abre em `http://localhost:5555` com interface visual de todas as tabelas.

---

## 8. Comandos úteis no dia a dia

| Comando | Quando usar |
|---|---|
| `npx prisma migrate dev --name nome_da_alteracao` | Após alterar o schema |
| `npx prisma migrate deploy` | Deploy em produção (CI/CD) |
| `npx prisma migrate reset` | Resetar banco local (apaga tudo e recria) |
| `npx prisma db pull` | Sincronizar schema com banco existente |
| `npx prisma generate` | Após alterar schema sem migrar |
| `npx prisma studio` | Interface visual do banco |
| `npx prisma migrate status` | Ver status das migrations |

---

## 9. Checklist de tabelas criadas

### Módulo 1 — Logística de Artistas

- [ ] `artists`
- [ ] `event_artists`
- [ ] `artist_logistics`
- [ ] `artist_logistics_items`
- [ ] `artist_operational_timelines`
- [ ] `artist_transfer_estimations`
- [ ] `artist_operational_alerts`
- [ ] `artist_team_members`
- [ ] `artist_benefits`
- [ ] `artist_cards`
- [ ] `artist_card_transactions`
- [ ] `artist_files`
- [ ] `artist_import_batches`
- [ ] `artist_import_rows`

### Módulo 2 — Gestão Financeira

- [ ] `event_cost_categories`
- [ ] `event_cost_centers`
- [ ] `suppliers`
- [ ] `supplier_contracts`
- [ ] `event_budgets`
- [ ] `event_budget_lines`
- [ ] `event_payables`
- [ ] `event_payments`
- [ ] `payment_attachments`
- [ ] `financial_import_batches`
- [ ] `financial_import_rows`

**Total: 25 tabelas**

---

## 10. Observações importantes

- `organizer_id` não tem FK no schema — é resolvido via JWT no backend. Isso é intencional para permitir multi-tenancy sem acoplamento de FK com a tabela de organizadores.
- `remaining_amount` em `EventPayable` é armazenado (não apenas calculado) para facilitar queries de consolidação sem joins pesados. Deve ser atualizado via service sempre que `paid_amount` mudar.
- `consumed_amount` em `ArtistCard` segue a mesma lógica — atualizado a cada transação via service.
- Todos os campos monetários usam `Decimal` com precisão `(14, 2)` — nunca `Float` para valores financeiros.
- Timestamps de negócio (`paid_at`, `resolved_at`, `cancelled_at`) são separados de `created_at` / `updated_at` para rastreabilidade.

---

*Setup Schema Prisma · EnjoyFun · Módulos 1 e 2*
