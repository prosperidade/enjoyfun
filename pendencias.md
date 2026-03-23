# Pendências do Sistema

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
| Mensageria / integrações transversais | Funcional com risco crítico aberto | webhook forte, integridade de tenant e fim do DDL em runtime |
| Banco / governança de schema | Operacional, mas ainda semi-manual | drift conhecido, migrations pendentes e decisões de modelagem |
| QA / smoke / contratos | Parcial | smokes operacionais e contratos mínimos ainda pendentes |
| Workforce / Participants Hub | Homologado funcionalmente | observabilidade, testes e modularização do controller |
| Meals | Homologado funcionalmente | residual controlado e rollout de migration específica |
| Frontend release safety | Build funcional | lint/tooling e code splitting |

---

## 3. Pedências abertas

## 3.1 Mensageria / Integridade de Webhook

### Estado

- Frente reaberta como prioridade operacional.
- Base decisória registrada em `docs/progresso9.md` e `docs/progresso10.md`.

### Pedências remanescentes

1. Assinatura forte de webhook
- Risco: qualquer origem forja eventos de mensageria.
- Consequência: histórico poluído, delivery incoerente, risco de fraude operacional.
- Mitigação:
  - exigir assinatura/HMAC por provider
  - rejeitar `organizer_id` vindo do cliente
  - resolver tenant apenas por credencial/instância registrada
- Critério de aceite: webhook inválido retorna `401/403` e não altera delivery.

2. Remoção de DDL em runtime
- Risco: a API altera schema na primeira chamada e ambientes divergem silenciosamente.
- Consequência: homologação e produção deixam de ser previsíveis.
- Mitigação:
  - retirar `ensureSchema()` como bootstrap operacional
  - materializar tudo via migrations/baseline
- Critério de aceite: mensageria não cria tabela via HTTP.

3. Retry/replay operacional
- Risco: falha de provider exige correção manual improvisada.
- Consequência: operação lenta e histórico inconsistente.
- Mitigação:
  - definir fluxo de retry administrativo
  - definir replay controlado por provider
- Critério de aceite: procedimento mínimo documentado e reproduzível.

---

## 3.2 Banco / Governança de Schema

### Estado

- Baseline operacional definido em `schema_current.sql`, porém ainda com drift e decisões abertas.

### Pedencias remanescentes

1. Migration `029_payment_gateways_hardening.sql`
- Risco: backend convive com colunas financeiras “talvez existam”.
- Consequência: comportamento diferente por ambiente.
- Mitigação:
  - materializar `is_primary`
  - materializar `environment`
  - aplicar backfill mínimo seguro
- Critério de aceite: contrato financeiro refletido no baseline.

2. Migration `030_operational_indexes.sql`
- Risco: consultas quentes e trilhas operacionais seguem sem o melhor suporte de índice.
- Consequência: custo alto sob carga e observabilidade pobre.
- Mitigação:
  - revisar `offline_queue`
  - revisar `audit_log`
  - revisar `participant_meals`
- Critério de aceite: migration idempotente, sem índice duplicado.

3. Decisão de modelagem pendente
- Risco: colunas existentes sem uso consistente geram drift semântico.
- Consequência: bugs silenciosos em BI/exportação e investigação.
- Mitigação:
  - decidir destino de `parking_records.organizer_id`
  - decidir enriquecimento de `offline_queue` com `organizer_id` e `user_id`
- Critério de aceite: decisão documentada e refletida no schema.

---

## 3.3 QA / Smoke / Contratos

### Estado

- A base funcional existe e o roteiro mínimo já foi versionado em `docs/qa/`, mas a execução viva ainda está pendente.

### Pedências remanescentes

1. Smoke `cashless + sync offline`
- Risco: regressão operacional só aparecer no uso real.
- Consequência: fila offline, recarga ou checkout falham sem aviso prévio.
- Mitigação:
  - executar `docs/qa/smoke_operacional_core.md`
- Critério de aceite: fluxo ponta a ponta concluído sem resíduo indevido.

2. Smoke emissão em massa de cartões
- Risco: preview e emissão divergirem do comportamento real de gravação.
- Consequência: time confiar em fluxo que não persiste no banco.
- Mitigação:
  - executar `docs/qa/smoke_operacional_core.md`
- Critério de aceite: lote emitido aparece corretamente no histórico.

3. Contratos mínimos de API
- Risco: refactor quebrar endpoint crítico sem sinal precoce.
- Consequência: regressão silenciosa em operação.
- Mitigação:
  - runner `backend/scripts/workforce_contract_check.mjs`
  - matriz versionada em `docs/qa/contratos_minimos_api.md`
- Critério de aceite: suíte mínima reproduzível localmente.

---

## 3.4 Workforce / Participants Hub

### Estado

- Encerrado funcionalmente, mas com débito estrutural alto em `WorkforceController.php`.

### Pedências remanescentes

1. Modularização segura do `WorkforceController.php`
- Risco: qualquer mudança local gerar regressão em múltiplos fluxos.
- Consequência: custo alto de manutenção e revisão.
- Mitigação:
  - extração incremental por famílias de endpoint
  - manter `dispatch()` estável
- Critério de aceite: controller progressivamente reduzido a borda HTTP.

2. Testes de contrato da frente
- Risco: extração quebrar payload/resposta.
- Consequência: regressão silenciosa em produção.
- Mitigação:
  - validar roles
  - event roles
  - assignments
  - card issuance
  - sync
- Critério de aceite: primeira suíte mínima verde antes do refactor pesado.

3. SLO e telemetria operacional
- Risco: módulo funcionar sem visibilidade real de falha/degradação.
- Consequência: incidente difícil de detectar cedo.
- Mitigação:
  - métricas por endpoint crítico
  - taxa de falha de sync
  - snapshot offline inválido
- Critério de aceite: painel mínimo de saúde operacional definido.

---

## 3.5 Meals

### Estado

- Homologado funcionalmente.

### Pedências remanescentes

1. Aplicar `database/014_participant_meals_domain_hardening.sql` em janela controlada
- Risco: rollout incompatível com legado.
- Consequência: quebra operacional em base antiga.
- Mitigação: validar base limpa antes da aplicação.
- Critério de aceite: constraints aplicadas e smoke de Meals preservado.

2. Testes mínimos do domínio
- Risco: regressão fina em `balance/history/services/sync`.
- Consequência: quebra sem aviso em operação real.
- Mitigação: validar contratos essenciais de `Meals`.
- Critério de aceite: suíte mínima reproduzível localmente.

---

## 3.6 Frontend release safety

### Estado

- Build funcional, mas pipeline ainda não é sinal robusto de release.

### Pedências remanescentes

1. Corrigir lint/tooling
- Risco: release sair com percepção falsa de saúde.
- Consequência: regressão escapar do pipeline.
- Mitigação: estabilizar `npm run lint`.
- Critério de aceite: lint executa sem erro espúrio de tooling.

2. Code splitting
- Risco: bundle grande demais para operação real.
- Consequência: piora de LCP/TTI e manutenção mais difícil.
- Mitigação:
  - separar dashboard
  - POS
  - participants
  - messaging
  - IA
- Critério de aceite: redução do chunk principal e divisão por módulos.

---

## 4. Ordem recomendada de ataque

1. Mensageria / webhook forte
2. Banco / migrations `029` e `030`
3. Smoke e contratos mínimos
4. Modularização do `WorkforceController.php`
5. Frontend release safety

---

## 5. Fontes oficiais deste consolidado

- `docs/progresso9.md`
- `docs/progresso10.md`
- `README.md`
- `CLAUDE.md`
