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
| Meals | Homologado e encerrado funcionalmente | residual não bloqueante consolidado neste arquivo |
| POS / Bar / Food / Shop / Cartão Digital | Frente em fechamento com núcleo funcional endurecido | rollout final de banco, smoke operacional e fechamento documental |
| Integrações transversais / Mensageria | Frente postergada fora do foco atual | segredos legados, webhook forte e retry/replay |

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

## 3.2 POS / Bar / Food / Shop / Cartao Digital

### Estado

- Frente quase encerrada.
- Relatorios, catalogo, IA operacional, contrato canonico de `card_id`, fluxo offline novo e trilha de auditoria cashless ja foram endurecidos no codigo e registrados em `docs/progresso9.md`.

### Pedencias remanescentes

1. Rollout final de banco para cashless/offline
- Risco: o codigo ja opera de forma mais rigida, mas o banco ainda pode aceitar estados indevidos sem as constraints/indices finais.
- Consequencia: inconsistencias silenciosas de saldo, fila offline ou trilha de auditoria sob carga.
- Mitigacao:
  - aplicar `database/025_cashless_offline_hardening.sql`
  - registrar a execucao em `database/migrations_applied.log`
- Criterio de aceite: constraints e indices novos presentes no banco real.

2. Smoke operacional ponta a ponta do POS
- Risco: regressao localizada no runtime real mesmo com sintaxe e build ok.
- Consequencia: falha de recarga, checkout ou reconcile offline so no uso real do operador.
- Mitigacao:
  - reiniciar o backend local/ambiente para evitar runtime stale
  - validar `POST /cards/resolve`
  - validar recarga de cartao
  - validar checkout online
  - validar venda offline seguida de `POST /sync`
- Criterio de aceite: fluxo cashless e offline concluido sem erro funcional e sem residuos indevidos na fila.

3. Fechamento documental da auditoria do POS
- Risco: backlog historico continuar acusando achados ja mortos e reabrir frente desnecessariamente.
- Consequencia: nova rodada gastar tempo em problema que ja foi corrigido.
- Mitigacao:
  - atualizar `auditoriaPOS.md`
  - reduzir o residual do POS ao que realmente nao foi executado ainda
- Criterio de aceite: auditoria do POS coerente com o codigo e com `docs/progresso9.md`.

---

## 4. Ordem recomendada de ataque

1. Dashboard
- iniciar a nova rodada pela superfície principal de operação
- revisar indicadores, consultas, alertas e telemetria
- seguir dali para os módulos encadeados pelo uso real

2. Fechar o POS
- aplicar a `025`
- executar a smoke curta de cashless/offline
- encerrar a auditoria documental do modulo

3. Pendências transversais postergadas
- reabrir mensageria apenas com decisão explícita
- tratar webhook forte, backfill de segredos e retry/replay como frente separada

---

## 5. Fontes oficiais deste consolidado

- `docs/progresso6.md`
- `docs/progresso7.md`
- `docs/progresso9.md`


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
- Atualizar `docs/progresso9.md` quando houver novo fechamento residual para evitar diagnostico historico divergente.
- Avaliar refactor futuro para reduzir duplicacao residual entre controller e service sem abrir risco de regressao agora.

## Integracoes Transversais / Mensageria

### P0

- Nenhuma pendencia operacional critica ativa nesta frente porque a mensageria foi congelada fora do foco atual.

### P1

- Executar backfill explicito dos segredos legados ainda persistidos em claro fora do fluxo quente.
- Implementar assinatura e validacao forte de webhook por provider de mensageria.
- Criar retry administrativo e replay de falhas de mensageria sem depender de reprocessamento manual ad hoc.

### Observacoes

- Essas pendencias ficam postergadas ate reabertura explicita da frente de mensageria.
- A proxima rodada priorizada do produto passa a comecar pelo dashboard, nao por integracoes de mensageria.

## Observacoes Meals

- Este bloco abaixo reflete somente o contexto residual especifico da auditoria de `Meals`.
- `database/015_participant_meals_operational_day_reconciliation.sql` ja foi aplicada no banco local.
- `database/016_participant_meals_outside_operational_day_review.sql` e somente leitura; antes da `017` ele evidenciou os `8` legados fora de janela e, apos a quarentena, passou a retornar `0` linhas.
- `database/017_participant_meals_outside_operational_day_quarantine.sql` ja foi aplicada no banco local.
- A auditoria de `outside_operational_day` ficou zerada apos a `017`.
- O check `meal_without_shift_assignment_when_shifted` foi alinhado ao dominio atual e tambem ficou zerado.
