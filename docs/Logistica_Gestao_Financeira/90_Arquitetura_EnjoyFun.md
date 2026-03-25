# 90 — Arquitetura EnjoyFun

> Encaixe técnico dos módulos no projeto real do EnjoyFun.

---

## 1. Stack oficial

| Camada | Padrão fechado |
|---|---|
| Backend | PHP do projeto atual |
| Frontend | React `.jsx` |
| Banco | PostgreSQL |
| Auth | JWT já existente |
| API | REST JSON em `/api` |

---

## 2. Diretriz de backend

### Padrão principal
- `Controller + Helpers`

### Quando usar `Services`
Somente para:
- motor reutilizável de cálculo
- integração real
- fluxo transacional mais complexo

---

## 3. Organização sugerida

```text
src/
  Controllers/
    ArtistController.php
    ArtistBookingController.php
    ArtistLogisticsController.php
    ArtistTimelineController.php
    ArtistAlertController.php
    ArtistTeamController.php
    ArtistFileController.php
    ArtistImportController.php

    EventFinanceCategoryController.php
    EventFinanceCostCenterController.php
    EventFinanceBudgetController.php
    EventFinanceSupplierController.php
    EventFinancePayableController.php
    EventFinancePaymentController.php
    EventFinanceAttachmentController.php
    EventFinanceSummaryController.php
    EventFinanceImportController.php
    EventFinanceExportController.php

  Helpers/
    ArtistTimelineHelper.php
    ArtistAlertHelper.php
    EventFinanceStatusHelper.php
    EventFinanceBudgetHelper.php
    ImportPreviewHelper.php

  Services/
    CardAssignmentService.php
    FinancialExportService.php
```

---

## 4. Roteamento

### Regra principal
O roteador atual trabalha por primeiro segmento e favorece desenho curto.

### Consequência
Documentar e implementar recursos assim:
- `/api/artists/...`
- `/api/event-finance/...`

Não usar `/api/v1`.
Não usar URL longa aninhada como padrão principal.

---

## 5. Banco

### Convenções
- `snake_case`
- plural
- PK numérica
- `NUMERIC` para dinheiro

### Identificadores
UUID só onde o sistema já exigir ou quando houver motivo técnico/público claro.

---

## 6. Reuso técnico existente

### Cartões
Reusar:
- `digital_cards`
- `card_transactions`
- `event_card_assignments`

### Financeiro do organizer
Reusar apenas infraestrutura geral do organizer quando útil.
Não usar `OrganizerFinanceController` como domínio do ledger operacional do evento.

---

## 7. Frontend

### Diretriz
Novas telas em React `.jsx`, aderentes ao padrão já usado no frontend atual.

### Referência de alinhamento
`WorkforceOpsTab.jsx` é a referência de estrutura e aderência ao projeto.

---

## 8. Resposta da API

Envelope oficial:
- `success`
- `data`
- `message`
- `meta` opcional

---

## 9. Regra final de arquitetura

Qualquer implementação futura desses módulos deve respeitar esta base e não reabrir:
- `/api`
- `.jsx`
- `Controller + Helpers`
- PK numérica
- rotas curtas compatíveis com o roteador atual
