# 23 — Financeiro do Evento · Fluxos de Tela

> Fluxos funcionais e estrutura de telas do Módulo 2.

---

## 1. Dashboard / resumo

### Objetivo
Consolidar visão financeira do evento.

### Entradas
- `event_id`
- filtros de período

### Cards mínimos
- previsto
- comprometido
- pago
- pendente
- vencido
- saldo livre

### Blocos mínimos
- por categoria
- por centro de custo
- por artista
- contas vencidas

---

## 2. Tela de contas a pagar

### Filtros
- `event_id`
- status
- categoria
- centro de custo
- fornecedor
- período

### Colunas mínimas
- descrição
- fornecedor/origem
- categoria
- centro
- vencimento
- valor
- pago
- restante
- status

### Ações
- criar
- editar
- cancelar
- registrar pagamento
- anexar documento

---

## 3. Tela de detalhe da conta

### Blocos
- resumo da conta
- pagamentos lançados
- anexos
- histórico de status

### Ações
- pagar parcialmente
- pagar total
- cancelar
- anexar comprovante

---

## 4. Tela de pagamentos

### Colunas mínimas
- conta
- data
- valor
- método
- referência
- status

### Ações
- criar
- estornar

---

## 5. Tela de fornecedores e contratos

### Blocos
- cadastro do fornecedor
- dados bancários/PIX
- contratos por evento

### Ações
- cadastrar fornecedor
- editar fornecedor
- criar contrato
- anexar contrato

---

## 6. Tela de orçamento

### Blocos
- cabeçalho do orçamento
- linhas por categoria e centro
- variação entre previsto e realizado

### Ações
- criar linha
- editar linha
- remover linha descartável

---

## 7. Fluxo de importação

### Passo 1
Selecionar tipo de importação.

### Passo 2
Upload do arquivo e preview.

### Passo 3
Exibir:
- válidas
- inválidas
- erros por linha
- impacto previsto

### Passo 4
Confirmar aplicação.

---

## 8. Fluxo de exportação

### Tipos
- contas a pagar
- pagamentos
- custo por artista
- fechamento completo

### Resultado
Geração de arquivo para download a partir dos filtros informados.

---

## 9. Diretriz de implementação frontend

- React `.jsx`
- aderência ao padrão visual atual do EnjoyFun
- sem abrir convenção paralela de componentes
- integração com API usando envelope padrão `success/data/message/meta`
