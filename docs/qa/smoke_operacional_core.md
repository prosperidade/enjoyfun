# Smoke Operacional Core

## Objetivo

Congelar um roteiro curto e reproduzível para os fluxos que ainda bloqueiam refactor seguro no backend atual.

## Escopo desta rodada

1. `cashless + sync offline`
2. emissão em massa de cartões
3. mensageria básica
4. isolamento multi-tenant mínimo

## Pré-requisitos

- backend acessível em `/api`
- frontend acessível com sessão válida de `admin` ou `organizer`
- migrations `029` a `032` aplicadas no ambiente que será usado
- pelo menos:
  - 1 evento operacional com produtos e cartões
  - 1 participante elegível para emissão em massa
  - 1 organizador com settings de mensageria configuráveis

## Evidências mínimas

Registrar ao final de cada smoke:

- horário da execução
- `event_id`
- usuário executor
- status HTTP dos endpoints principais
- id/uuid dos artefatos criados
- confirmação visual na UI quando aplicável

---

## 1. Smoke `cashless + sync offline`

### Objetivo

Validar que resolução de cartão, saldo, venda offline e reconcile continuam coerentes.

### Sequência

1. Resolver o cartão no evento certo:
   - `POST /cards/resolve`
   - esperado: `200`
   - esperado: retorno com `card_id` canônico
2. Conferir o cartão na listagem do evento:
   - `GET /cards?event_id={event_id}`
   - esperado: cartão presente no evento correto
3. Fazer recarga online:
   - operação pela UI de cartões
   - esperado: saldo e extrato atualizados
4. Fazer checkout online no POS:
   - esperado: débito no extrato do cartão
5. Simular venda offline:
   - desconectar rede no cliente POS
   - salvar venda offline com `event_id`, `card_id` e `offline_id`
   - esperado: item entra na fila local
6. Reconciliar:
   - restaurar rede
   - `POST /sync`
   - esperado: `200` ou `207` apenas se houver falha parcial intencional
7. Confirmar persistência:
   - `offline_queue` com linha do `offline_id`
   - transação refletida em `card_transactions`
   - saldo final coerente

### Critério de aceite

- `card_id` resolvido canonicamente
- venda offline reconciliada sem duplicidade
- `offline_queue` auditável
- saldo final coerente entre UI, `/cards` e extrato

---

## 2. Smoke de emissão em massa de cartões

### Objetivo

Garantir que `preview` e `issue` continuam distinguíveis e que o lote realmente aparece em histórico.

### Sequência

1. Selecionar participantes elegíveis no `Workforce Ops`
2. Gerar preview:
   - `POST /workforce/card-issuance/preview`
   - esperado: `200`
   - esperado: `can_issue=true` quando houver elegíveis
3. Confirmar emissão:
   - `POST /workforce/card-issuance/issue`
   - esperado: `200`
   - esperado: `batch_id`, `summary.issued_count`, `items`
4. Conferir histórico:
   - `GET /cards?event_id={event_id}`
   - esperado: novos cartões presentes no evento
5. Conferir batch:
   - `card_issue_batches`
   - `card_issue_batch_items`
6. Conferir saldo inicial:
   - wallet/extrato do participante
   - esperado: crédito inicial aplicado quando configurado

### Critério de aceite

- `preview` não grava nada
- `issue` grava lote, itens e cartões
- cartões emitidos aparecem em `Cartão Digital`
- histórico e saldo inicial ficam coerentes

> Base complementar desta frente: `docs/cardsemassa.md`

---

## 3. Smoke de mensageria básica

### Objetivo

Validar o caminho mínimo de settings + captura segura de webhook.

### Sequência

1. Abrir a aba de canais/settings
2. Salvar credenciais mínimas:
   - `POST /organizer-messaging-settings`
   - esperado: `200`
3. Validar leitura pública:
   - `GET /messaging/config`
   - esperado: não expor segredo bruto
4. Enviar fluxo que gere delivery:
   - OTP ou mensagem operacional, conforme ambiente
5. Validar histórico:
   - `message_deliveries`
   - esperado: delivery criado
6. Testar webhook autenticado:
   - provider/instância compatível
   - esperado: atualização do delivery apenas com segredo válido
7. Testar webhook inválido:
   - esperado: `401/403`
   - esperado: sem mutação de delivery

### Critério de aceite

- settings persistem sem devolver segredo cru ao frontend
- webhook válido reconcilia delivery
- webhook inválido não altera histórico

---

## 4. Smoke multi-tenant mínimo

### Objetivo

Impedir regressão de escopo entre organizadores/eventos nos módulos mais sensíveis.

### Sequência

1. `GET /cards?event_id={evento_a}`
   - cartão do `evento_b` não pode aparecer
2. `POST /cards/resolve` com `event_id` errado
   - esperado: `404` ou erro de escopo
3. `GET /participants?event_id={evento_a}`
   - esperado: sem participantes de outro tenant
4. `GET /parking?event_id={evento_a}`
   - esperado: sem registros de outro tenant
5. `GET /tickets?event_id={evento_a}`
   - esperado: sem ingressos de outro tenant
6. `POST /sync` com payload de evento fora do escopo do operador
   - esperado: `403`

### Critério de aceite

- nenhum módulo crítico cruza dados de tenant/evento
- falha de escopo é explícita, não silenciosa

---

## Fechamento da rodada

Só considerar esta frente concluída quando:

- os 4 smokes forem executados
- os artefatos/evidências forem anotados em `docs/progresso10.md`
- qualquer desvio encontrado virar correção ou pendência explícita
