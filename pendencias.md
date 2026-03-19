# Pedências do Sistema

## 0. Objetivo deste arquivo

- Este arquivo concentra apenas o que sobra **depois** da homologação funcional e dos ajustes principais de auditoria.
- O histórico detalhado de implementação continua nos arquivos `docs/progresso*.md`.
- A regra a partir desta rodada é:
  - homologou um módulo
  - executou os ajustes principais da auditoria
  - o que ainda restar entra aqui como `pedência`
- enquanto os ajustes principais de auditoria não forem executados, o módulo continua sendo tratado no `progresso` próprio

---

## 1. Regra de uso

- Não usar este arquivo para contar história completa da entrega.
- Não duplicar aqui tudo que já está encerrado.
- Registrar apenas:
  - pendência real
  - risco
  - consequência
  - mitigação
  - critério de aceite
- Quando a pendência for resolvida:
  - remover da lista aberta
  - registrar o fechamento no `progresso` do módulo correspondente

---

## 2. Estado consolidado atual

| Módulo | Estado funcional | O que sobra |
| --- | --- | --- |
| Workforce / Participants Hub | Homologado e encerrado funcionalmente | governança de release e observabilidade |
| Meals | Homologado funcionalmente no evento piloto | ainda segue em `docs/progresso7.md` até concluir os ajustes da auditoria |

---

## 3. Pedências abertas

## 3.1 Workforce / Participants Hub

### Estado

- Encerrado funcionalmente.
- Base principal registrada em `docs/progresso6.md`.

### Pedências remanescentes

1. Testes de contrato de API
- Risco: regressão silenciosa em rotas críticas de participants/workforce/sync.
- Consequência: quebra em produção sem sinal prévio.
- Mitigação: suíte mínima de contrato para:
  - roles
  - event roles
  - assignments
  - sync
  - delete de participante
- Critério de aceite: endpoints críticos validados automaticamente em CI/local.

2. SLO e telemetria operacional
- Risco: o módulo funcionar, mas operar sem visibilidade de falha, latência ou degradação.
- Consequência: incidente difícil de detectar cedo.
- Mitigação:
  - métricas de erro por endpoint crítico
  - taxa de falha de sync
  - contagem de snapshot offline inválido
- Critério de aceite: painel mínimo de saúde operacional definido.

3. Playbook de incidente de Workforce
- Risco: diante de inconsistência futura, cada correção voltar a ser improvisada.
- Consequência: tempo alto de diagnóstico e chance de correção manual errada.
- Mitigação:
  - playbook de reconstrução de árvore
  - playbook de saneamento de assignments
  - playbook de reconciliação de liderança
- Critério de aceite: procedimento documentado e reproduzível.

---

## 4. Ordem recomendada de ataque

1. Workforce
- testes de contrato
- telemetria
- playbook

2. Meals
- só migrar para este arquivo depois de concluir os ajustes da auditoria em `docs/progresso7.md`

---

## 5. Fontes oficiais deste consolidado

- `docs/progresso6.md`
- `docs/progresso7.md`


# Pendencias

## Meals

### P0

- Nenhuma pendencia operacional critica aberta no fechamento atual de `Meals`.

### P1

- Aplicar a `database/014_participant_meals_domain_hardening.sql` em janela controlada, somente depois de confirmar base limpa e validar constraints pendentes.
- Validar em ambiente real as constraints adicionadas com rollout compativel para legado.
- Criar testes de contrato/minimos para:
  - `GET /meals/balance`
  - `GET /meals`
  - `POST /meals`
  - `GET /meals/services`
  - `POST /sync` com payload de `meal`
- Adicionar telemetria operacional do modulo:
  - latencia por endpoint
  - taxa de falha do sync
  - taxa de rejeicao por ACL, cota e ambiguidade operacional
  - backlog offline por dispositivo

### P2

- Executar teste de carga do fluxo `Meals` com volume operacional mais proximo do evento real.
- Revisar paginacao real do historico, hoje ainda limitada por `cap` fixo.
- Evoluir a reconciliacao offline para um fluxo mais completo de DLQ/revisao/exportacao, se isso virar necessidade operacional.
- Atualizar `docs/progresso7.md` com uma nota curta de fechamento para evitar diagnostico historico divergente.
- Avaliar refactor futuro para reduzir duplicacao residual entre controller e service sem abrir risco de regressao agora.

## Observacoes

- Escopo acima reflete somente o que ficou pendente da auditoria de `Meals`.
- `database/015_participant_meals_operational_day_reconciliation.sql` ja foi aplicada no banco local.
- `database/016_participant_meals_outside_operational_day_review.sql` e somente leitura; antes da `017` ele evidenciou os `8` legados fora de janela e, apos a quarentena, passou a retornar `0` linhas.
- `database/017_participant_meals_outside_operational_day_quarantine.sql` ja foi aplicada no banco local.
- A auditoria de `outside_operational_day` ficou zerada apos a `017`.
- O check `meal_without_shift_assignment_when_shifted` foi alinhado ao dominio atual e tambem ficou zerado.
