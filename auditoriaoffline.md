# Auditoria Completa — Tickets + Scanner + Operação Offline

**Data:** 2026-03-26  
**Escopo:** fluxos de ingressos, scanner operacional, sincronização offline, segurança, usabilidade, arquitetura e desenho técnico.  
**Fontes analisadas:** `README.md`, `backend/src/Controllers/ScannerController.php`, `backend/src/Controllers/TicketController.php`, `backend/src/Controllers/SyncController.php`, `frontend/src/pages/Operations/Scanner.jsx`, `frontend/src/pages/Tickets.jsx`, `frontend/src/modules/pos/hooks/usePosOfflineSync.js`, migrations de hardening offline.

---

## 1) Resumo executivo

Estado atual: a base já tem fundamentos fortes (multi-tenant, controle por `organizer_id`, auditoria, idempotência de sync, transações), porém o **scanner offline ainda está parcial**: hoje ele usa cache local para catálogos (eventos/setores), mas **não possui fila offline de validações para replay confiável** quando a internet volta.

### Diagnóstico geral
- **Arquitetura:** boa base para evoluir; falta consolidar “offline-first” no scanner e padronizar observabilidade por módulo.
- **Segurança:** existem pontos críticos de exposição de segredo e escopo que precisam correção imediata.
- **Usabilidade:** UX funcional, mas com espaço para reduzir fricção operacional (modo degradado real, feedback de contingência e reconciliação visual).
- **Risco operacional:** moderado/alto para portaria em cenários com internet instável (risco de fila física e retrabalho).

---

## 2) Arquitetura e desenho atual (as-is)

### Backend
- `ScannerController` recebe leitura e tenta validar guest/participant por token, com auditoria de sucesso/falha.
- `TicketController` valida ingressos dinâmicos e suporta emissão/listagem comercial.
- `SyncController` processa lote offline (`items`), deduplica por `offline_id` e aplica regras por tipo (`sale`/`meal`).
- `offline_queue` e constraints de hardening já existem no banco.

### Frontend
- Tela de scanner com câmera + fallback manual de código.
- Cache local para eventos/setores no scanner.
- Tickets com cache local básico para listagem.
- POS tem hook dedicado para fila offline de vendas (`Dexie`), mas scanner não.

### Lacuna estrutural principal
Há dois níveis de offline diferentes:
1. **POS offline (mais maduro):** fila local + sync.
2. **Scanner offline (parcial):** apenas cache de catálogo, sem persistência transacional de validações.

Isso cria assimetria de confiabilidade entre módulos críticos do evento.

---

## 3) Fragilidades críticas (prioridade P0)

## P0-1 — Exposição de segredo sensível no dump do scanner
**Achado:** o endpoint de dump offline inclui `totp_secret` de ingressos no payload retornado ao cliente.  
**Risco:** vazamento de segredo que viabiliza fraude de token dinâmico.

**Solução recomendada (imediata):**
- remover `totp_secret` da carga offline;
- usar assinatura server-side (token curto assinado com expiração) para cenários de contingência;
- rotação de segredos comprometidos em rollout de correção.

## P0-2 — Escopo de setor no scanner sem filtro explícito por evento
**Achado:** a verificação de permissão por setor consulta `workforce_assignments` por `participant_id + sector` sem filtro explícito por evento/organizador.  
**Risco:** autorização indevida por vínculo histórico em evento distinto.

**Solução recomendada:**
- incluir `event_id` e `organizer_id` na regra de autorização;
- criar índice composto para consulta (`participant_id, event_id, sector_normalized`).

## P0-3 — Resolver de organizer para admin com fallback ambíguo
**Achado:** em alguns controladores, admin pode cair em fallback por `id` quando `organizer_id` não está presente.  
**Risco:** ambiguidade de tenant e validações inconsistentes.

**Solução recomendada:**
- eliminar fallback por `id`;
- exigir `organizer_id` explícito para qualquer operação tenant-scoped;
- retornar 403/422 quando contexto estiver incompleto.

## P0-4 — Scanner sem fila offline transacional
**Achado:** scanner depende de API online na validação principal; sem internet, há modo degradado parcial (catálogo) mas sem reconciliação automática de leituras.  
**Risco:** paralisação de portaria/setor ou operação paralela manual sem trilha íntegra.

**Solução recomendada:**
- implementar `scanner_offline_queue` local + endpoint de replay idempotente;
- assinar payload de autorização offline com TTL curto;
- UI de reconciliação e conflito pós-sync.

---

## 4) Bugs silenciosos / dívida técnica

1. **Cache de tickets sem escopo por evento/organizador/usuário no frontend.** Pode exibir dados antigos fora de contexto após troca de evento ou sessão.
2. **Sem TTL explícito em caches críticos do scanner.** Dados podem ficar obsoletos e induzir decisão errada.
3. **Observabilidade fragmentada.** Erros de sync são logados, mas faltam métricas estruturadas (taxa de deduplicação, conflito, replay).
4. **Assimetrias de nomenclatura e contrato (`type` vs `payload_type`, `data` vs `payload`).** Aumenta risco de regressão silenciosa.
5. **Sem política formal de circuito degradado no scanner (SLO operacional).** Equipe pode improvisar em campo.

---

## 5) Melhorias que podem e devem ser executadas (usabilidade + operação)

### UX operacional (alto impacto)
- Banner persistente “modo contingência” com contador de itens pendentes de sincronização.
- Tela de reconciliação pós-evento (sucesso, deduplicado, conflito, negado).
- Feedback sonoro/visual distinto por status (aprovado, já utilizado, bloqueado, sem conectividade).
- Atalho de troca rápida de setor e “último setor usado”.
- Ação de “exportar contingência” (CSV assinado) para auditoria de campo.

### Produto / processo
- Playbook padrão de portaria offline (quando cair internet).
- Checklist de pré-abertura do evento (saúde do cache, validade de credenciais offline, teste de sync).
- Definição de SLO para reconciliação (ex.: 99% em até 5 min após retorno da conexão).

---

## 6) Plano de ativação real do scanner offline

## Fase 1 (48–72h) — Contenção de risco
- Remover segredos (`totp_secret`) dos dumps.
- Corrigir escopo de autorização por evento/organizador.
- Bloquear contextos ambíguos de tenant.
- Instrumentar logs estruturados mínimos no scanner/sync.

## Fase 2 (1 sprint) — Offline confiável
- Implementar fila offline do scanner no frontend (IndexedDB).
- Endpoint de replay idempotente no backend (`offline_id` + assinatura + janela temporal).
- Estratégia de resolução de conflitos (já utilizado, token inválido, setor inválido).

## Fase 3 (1 sprint) — Observabilidade + governança
- Dashboard operacional de reconciliação por evento/dispositivo/setor.
- Alarmes de taxa de falha e backlog de sync.
- Testes de caos de conectividade (2G/sem rede/intermitência).

---

## 7) Backlog em tickets (pronto para execução)

| ID | Prioridade | Título | Descrição | Critério de aceite |
|---|---|---|---|---|
| OFF-001 | P0 | Remover `totp_secret` do dump scanner | Sanitizar payload offline e revisar consumers | Nenhum endpoint retorna segredo TOTP; testes de contrato atualizados |
| OFF-002 | P0 | Hardening de escopo no scanner | Validar setor por `participant_id + event_id + organizer_id` | Tentativa cross-event/cross-tenant bloqueada com 403 |
| OFF-003 | P0 | Contexto de tenant explícito | Eliminar fallback ambíguo de organizer em operações scoped | Requests sem `organizer_id` válido retornam 403/422 |
| OFF-004 | P0 | Fila offline scanner (frontend) | Persistir leituras offline e replay automático | Leituras offline sincronizam ao reconectar com dedupe |
| OFF-005 | P0 | Replay scanner idempotente (backend) | Endpoint com `offline_id` único e assinatura/TTL | Reenvio do mesmo item não duplica validação |
| OFF-006 | P1 | Reconciliação visual | Tela com status: sincronizado/deduplicado/conflito | Operador consegue filtrar/exportar conflitos |
| OFF-007 | P1 | TTL para cache scanner | Definir validade por tipo de catálogo | Cache vencido exige refresh antes da operação |
| OFF-008 | P1 | Escopo do cache tickets | Chave de cache por organizer/event/user | Troca de sessão não reaproveita dado indevido |
| OFF-009 | P1 | Métricas estruturadas de sync | Emitir contadores e latência por tipo/setor/dispositivo | Dashboard com taxa de sucesso e backlog |
| OFF-010 | P1 | Playbook de contingência | Documento operacional para portaria/setores | Equipe executa simulação com checklist aprovado |
| OFF-011 | P2 | Contratos unificados de payload | Padronizar `payload_type/payload` sem aliases legados | APIs aceitam contrato único versionado |
| OFF-012 | P2 | Testes de conectividade degradada | Cenários automatizados de intermitência | Pipeline cobre sync sob falha de rede |

---

## 8) Diagnóstico final

A EnjoyFun já está em uma base sólida de backend transacional e multi-tenant, mas o scanner ainda não está em nível “offline-first” completo. O principal gargalo não é ausência total de tecnologia, e sim **inconsistência entre módulos críticos** (POS avançado x scanner parcial).

Com execução do backlog P0/P1 acima, o sistema passa de “offline parcial” para “operação resiliente”, reduzindo risco de fraude, risco de vazamento e risco de paralisação na entrada do evento.

---

## 9) Próximos passos recomendados (ordem objetiva)

1. Executar OFF-001, OFF-002, OFF-003 imediatamente.
2. Abrir feature branch técnica para OFF-004 e OFF-005 com contrato versionado.
3. Entregar reconciliação visual (OFF-006) junto de métricas (OFF-009).
4. Validar em simulação de evento real com perda intermitente de internet.

