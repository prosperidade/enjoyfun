# Prompt para próximo chat — Auditoria UI EnjoyFun

Cole isso no início do próximo chat:

---

Leia o CLAUDE.md, docs/progresso27.md e docs/proximos_passos_ui_agentes.md antes de qualquer ação.

## Contexto

Acabamos de fazer uma sessão intensa de 30+ commits onde:
- Corrigimos toda a arquitetura de agentes IA (EMAS): routing, tools, prompts, pre-execution
- Todos os 8 agentes (13 no total) funcionam com dados reais
- Dashboard chama 5 tools em paralelo (KPI, vendas, tickets, workforce, finance)
- FinanceWorkforceCostService é a fonte única de verdade para custos de equipe
- Redis (porta 6380) e MemPalace (porta 3100) rodando via docker-compose.services.yml
- Backend roda em PHP 8.4 (NÃO 8.5 que tem bug) na porta 8080
- Frontend roda na porta 3003 com proxy para 8080

## Decisão arquitetural

**UI primeiro, agentes depois.** Não mexer mais nos agentes IA até que todas as UIs estejam estáveis. Cada correção de UI pode mudar os dados que os agentes consultam — calibrar agentes antes é retrabalho.

## Tarefa agora

Auditoria completa de cada tela do frontend. Para cada tela:

1. Ler o componente React e entender o que ele renderiza
2. Verificar quais endpoints da API ele chama
3. Verificar se os dados retornados batem com o que está no banco
4. Identificar bugs visuais, dados errados, cards faltando, cálculos inconsistentes
5. Listar tudo num diagnóstico antes de corrigir

### Ordem sugerida (por impacto):
1. Dashboard.jsx — cards de KPIs, gráficos, custos (falta card de pessoal por setor)
2. POS.jsx — vendas, estoque, filtros
3. Tickets.jsx — lotes, vendas, status
4. Parking.jsx — fluxo, capacidade
5. ParticipantsHub / WorkforceOpsTab — equipe, custos, turnos
6. ArtistsCatalog / ArtistDetail — timeline, contratos
7. OrganizerFiles — upload, parsing, RAG
8. Settings — branding, gateways, AI providers

### Regras
- Logar como admin@enjoyfun.com.br (organizer_id=2, tem 3 eventos com dados)
- Evento principal para teste: EnjoyFun 2026 (event_id=1, 110 vendas, R$106k, 97 tickets, 125 workforce)
- Backend: /c/php84/php.exe -S 127.0.0.1:8080 -t public (dentro de backend/)
- NÃO mexer em agentes IA (AIPromptCatalogService, AIOrchestratorService, AIIntentRouterService) nesta fase
- Commitar cada fix separadamente com mensagem descritiva
