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
