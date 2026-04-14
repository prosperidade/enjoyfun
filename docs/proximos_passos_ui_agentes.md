# Proximos Passos — UI + Agentes

**Data:** 2026-04-13
**Decisao:** Estabilizar UI primeiro, agentes depois (evitar retrabalho).

---

## Fase 1: Auditoria e Correção de UI (PROXIMA)

Testar cada tela, anotar bugs/inconsistencias visuais e de dados. Corrigir o sistema base antes de calibrar agentes.

### Telas a auditar:

| Tela | O que verificar |
|------|----------------|
| **Dashboard** | Cards de custos (falta card de pessoal por setor), gráficos, KPIs batendo com dados reais |
| **POS (Bar/Food/Shop)** | Vendas, estoque, produtos, filtros temporais |
| **Tickets** | Lotes, batches vs tickets diretos, status (paid/used/valid) |
| **Parking** | Fluxo, capacidade, bip pendente |
| **Workforce** | Custos por setor, liderança, preenchimento, turnos |
| **Artists** | Timeline, contratos, logística, alertas |
| **Finance** | Orçamento, comprometido, pago, contas vencidas |
| **Documents** | Upload, parsing, indexação, RAG |
| **Settings** | Branding, gateways, AI providers, WhatsApp |

### Para cada tela, anotar:
1. Dados que aparecem na UI
2. Dados que DEVERIAM aparecer mas não aparecem
3. Dados errados ou inconsistentes
4. Cards/componentes faltando
5. Bugs visuais (layout, responsividade, overflow)

---

## Fase 2: Calibração de Agentes (DEPOIS DA UI)

Depois que a UI estiver estável, calibrar cada agente:

| Agente | Surface | Status atual |
|--------|---------|-------------|
| management | dashboard | 80% — custos batem, falta card de pessoal por setor |
| bar | bar | 70% — vendas OK, time_filter OK, falta estoque detalhado |
| marketing | tickets | 60% — ingressos OK com fallback, falta detalhamento por lote |
| logistics | parking, workforce | 60% — parking OK, workforce incompleto |
| artists | artists | 50% — summary OK, falta detalhamento de timeline/riders |
| documents | documents | 40% — listagem OK, RAG parcial |
| platform_guide | platform_guide | 90% — tools todas funcionando |
| contracting | finance | 30% — finance summary OK, falta orçamento detalhado |
| data_analyst | analytics | 20% — tools de comparação sem dados suficientes |
| content | — | 10% — sem tools de domínio reais |
| media | — | 10% — sem tools de domínio reais |
| feedback | — | 0% — sem tools |

---

## Conquistas da Sessão de Hoje (referência)

- Surface-locked routing (cada tela vai pro agente certo)
- Pre-execution de tools (dashboard chama 5 tools automaticamente)
- FinanceWorkforceCostService como fonte única de verdade
- 8/8 surfaces funcionando com dados reais
- 30+ commits pushados
- Todas as migrations aplicadas (063-086)
- Redis + MemPalace operacionais via Docker
- 12 findings diagnosticados e corrigidos
