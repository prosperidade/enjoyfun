# Auditoria técnica completa — POS / Bar / Food / Shop / Cartão Digital / IA / Franquias

_Data da auditoria:_ 2026-03-19  
_Última atualização operacional:_ 2026-03-20  
_Base inspecionada:_ código local em `c:/Users/Administrador/Desktop/enjoyfun` + validação real em `http://localhost:8080/api` e PostgreSQL `enjoyfun`

## 1. Resumo executivo

Esta auditoria confirma que a base atual da EnjoyFun já tem **boa evolução arquitetural no frontend do POS** e **hardening importante no backend comercial**. A rodada operacional de 2026-03-20 fechou parte relevante do residual do POS, mas ainda restam **riscos reais** em cinco frentes:

1. **Governança de banco e migrations**: o caminho crítico do POS cashless/offline ficou alinhado com a aplicação real da `025_cashless_offline_hardening.sql`, porém o versionamento do schema continua manual (`migrations_applied.log`) e o snapshot histórico (`schema_real.sql`) ainda diverge do baseline oficial.
2. **POS / relatórios / gráficos**: a frente melhorou materialmente com `loading/error/stale`, snapshot de relatório e manutenção da aba de relatórios montada após a primeira abertura, mas o container ainda concentra responsabilidades demais e continua sensível a invalidação por troca de evento/contexto.
3. **Cashless / cartão digital**: checkout e sync já operam com `card_id` canônico e o smoke passou ponta a ponta, mas a base ainda mantém compatibilidade dinâmica para `card_token` e nem toda a trilha online/offline está uniformemente correlacionada.
4. **Consumidores / outbox / processamento assíncrono**: existe persistência para `offline_queue` e para mensageria (`message_deliveries`, `messaging_webhook_events`), porém **não há um consumidor/background worker explícito** no repositório para outbox de mensageria; o projeto está mais próximo de “registro síncrono com trilha” do que de uma arquitetura real com consumidores desacoplados.
5. **Franquias**: não encontrei um módulo real de franquias/franqueados; o que existe hoje é uma base **white-label multi-tenant por organizer**, não uma camada explícita de franquia, máster-franquia, unidade, repasse, governança ou ACL hierárquica entre rede e operação local.

## 2. Escopo real encontrado

### 2.1 POS / setores
- Frontend do POS unificado em `frontend/src/pages/POS.jsx`, reutilizado por `Bar.jsx`, `Food.jsx` e `Shop.jsx`.
- Backend segregado por controller setorial (`BarController.php`, `FoodController.php`, `ShopController.php`) com parte do comportamento extraído para `ProductService`, `SalesDomainService` e `SalesReportService`.

### 2.2 Cashless / cartão digital
- Domínio principal em `CardController.php` e `WalletSecurityService.php`.
- Débito em checkout delegado para `SalesDomainService::processCheckout()`.

### 2.3 Analytics / gráficos
- POS: `usePosReports`, `SalesTimelineChart`, `ProductMixChart`.
- Dashboard analítico: `AnalyticalDashboard.jsx` e componentes `Analytics*`.

### 2.4 Banco / migrations
- Baseline atual declarado em `database/schema_current.sql`.
- Snapshot histórico legado em `database/schema_real.sql`.
- Registro operacional manual em `database/migrations_applied.log`.

### 2.5 Consumidores / filas
- Fila offline local com Dexie em `frontend/src/lib/db.js`.
- Reconcile backend em `backend/src/Controllers/SyncController.php`.
- Outbox/history de mensageria em `database/018_messaging_outbox_and_history.sql` e `MessagingDeliveryService.php`.

## 3. Auditoria por domínio

## 3.1 POS — Bar / Food / Shop

### Estado atual
**Ponto forte:** o frontend finalmente saiu do modelo triplicado e opera a partir de um container comum (`POS.jsx`). Isso reduz drift visual e simplifica manutenção de UX.

**Ponto forte:** o backend também melhorou em relação ao estágio mais antigo, porque:
- CRUD de produtos foi centralizado em `ProductService`.
- Checkout foi consolidado em `SalesDomainService`.
- Relatórios foram centralizados em `SalesReportService`.

**Ponto forte:** a frente de relatórios já não está no mesmo estado da auditoria inicial:
- `usePosReports` agora expõe `loadingReports`, `reportError`, `reportStale` e `lastReportUpdatedAt`.
- o hook preserva o último snapshot útil enquanto atualiza em background (`reportSnapshotRef`).
- a árvore de relatórios permanece montada depois da primeira abertura (`hasOpenedReports` + `ReportsPanel isActive`), reduzindo remount desnecessário.

**Ponto forte:** o fluxo browser local deixou de morrer no preflight de auth/POS, porque o backend agora aceita `X-Device-ID` e `X-Operational-Test` no CORS, alinhado ao client padrão do frontend.

### Inconsistências remanescentes
1. **Ainda existe segmentação por controller sem abstração final de domínio setorial.**  
   Os três controllers seguem muito parecidos e continuam exigindo manutenção paralela para rotas, autorização, mensagens e contrato HTTP.

2. **O POS depende de `eventId` válido em vários pontos e reage zerando listas/relatórios.**  
   Quando `eventId` não está resolvido, `usePosCatalog` limpa produtos e `usePosReports` limpa estado analítico. Isso é correto para segurança de contexto, mas gera percepção de “piscada” ou degradação visual quando o operador troca evento/aba rapidamente.

3. **A tela mistura muitos papéis em um único container (`POS.jsx`).**  
   O componente principal ainda coordena:
   - catálogo,
   - carrinho,
   - checkout,
   - sync offline,
   - relatórios,
   - IA,
   - estoque.

   Isso não é bug funcional por si só, mas amplia superfície para regressões silenciosas.

### Riscos operacionais residuais
1. **Os estados de erro melhoraram, mas o diagnóstico ainda é desigual entre leitura e escrita.**  
   Catálogo e relatórios já exibem erros estruturados (`catalogError`, `eventsError`, `reportError`), porém fluxos como checkout, CRUD de produto e parte da IA ainda misturam toast genérico com `console.error`.

2. **O polling de relatórios continua fixo, mesmo mais controlado.**  
   `usePosReports` hoje atualiza a cada 45 segundos e apenas quando a aba está ativa/visível. Isso é melhor que o estágio anterior, mas ainda pode gerar refresh perceptível em operação longa.

3. **A estabilização visual existe, mas ainda não é uma camada dedicada.**  
   O snapshot em memória reduziu a sensação de “gráfico desmontando”, porém a tela ainda depende diretamente de `sales_chart` e `mix_chart` vindos da API, sem adapter/cache mais formal.

### Diagnóstico do problema “os gráficos degradam a cada seção aberta”

Pelo código atual, **o cenário “degrada a cada seção aberta” já não descreve bem o estado real**. O que permanece é uma combinação de fatores que ainda pode gerar instabilidade visual pontual:

1. **A primeira abertura da aba ainda inicia carga e montagem do bloco de relatórios.**  
   Depois disso a árvore permanece viva, mas a entrada inicial continua sendo um momento de churn.

2. **Troca de evento invalida snapshot e datasets.**  
   Isso é correto para integridade de contexto, porém ainda causa “piscada” quando o operador alterna rápido entre eventos ou cai temporariamente em `eventId` inválido.

3. **Os gráficos continuam acoplados diretamente ao payload da API.**  
   Se o backend responder vazio, parcial ou com bucketização diferente, a UI ainda redesenha imediatamente.

4. **Recharts continua sem uma camada explícita de memoização de dataset.**  
   O custo de redraw caiu com a montagem persistente, mas não foi eliminado por completo.

### Causa-raiz mais provável
A degradação residual percebida vem principalmente de **invalidação por troca de contexto + polling + acoplamento direto entre payload assíncrono e desenho**, e não de um bug matemático isolado no gráfico.

### Soluções recomendadas
**Curto prazo**
1. Criar um `ReportStateAdapter` para desacoplar API e visualização.
2. Aplicar memoização explícita nos datasets derivados dos gráficos.
3. Introduzir cache leve por `event_id + sector + filter`, além do snapshot em memória já existente.
4. Harmonizar mensagens operacionais entre leitura, checkout e IA.

**Médio prazo**
1. Separar o POS em containers menores: `PosSalesContainer`, `PosInventoryContainer`, `PosInsightsContainer`.
2. Reduzir duplicação entre `BarController`, `FoodController` e `ShopController`.
3. Evoluir para observabilidade de UX operacional além de `console.error` e toast.

## 3.2 Banco de dados / schema / migrations

### Achados confirmados
1. **O projeto já documenta que `schema_current.sql` é o baseline oficial e que `schema_real.sql` é apenas histórico.**
2. **`schema_real.sql` continua divergindo materialmente do baseline atual.**  
   Isso hoje precisa ser lido como histórico legado, não como verdade operacional da frente do POS.
3. **A colisão histórica de numeração `012_*` já não existe no estado atual do diretório.**  
   A renumeração para `021_event_meal_services_alignment.sql` removeu esse ponto específico de ambiguidade.
4. **A migration `025_cashless_offline_hardening.sql` foi aplicada no banco real em 2026-03-20.**  
   O banco passou a materializar constraints de saldo/transação cashless, constraints de `offline_queue` e índices críticos de `card_transactions`, `digital_cards`, `offline_queue` e `sales`.
5. **O `migrations_applied.log` continua manual e parcial.**  
   Ele registra apenas parte do histórico recente e não substitui uma tabela transacional de versionamento.

### Riscos
1. **Novos ambientes ainda podem subir com schema divergente se a operação confiar só em log manual.**
2. **`schema_real.sql` ainda pode induzir diagnóstico errado se for tratado como baseline vivo.**
3. **Compatibilidade defensiva no backend (`columnExists`) pode virar muleta permanente.**
4. **Bugs silenciosos continuam aparecendo como “funciona em um tenant / quebra em outro” enquanto a governança de schema não for transacional.**

### Diagnóstico
Para a frente do POS, o drift crítico imediato caiu bastante após a `025`. Para a plataforma como um todo, o modelo de governança de schema ainda está em **modo de reconciliação manual**, não em regime robusto de evolução controlada.

### Soluções recomendadas
1. Congelar `schema_current.sql` como única verdade operacional.
2. Criar uma tabela real de versionamento (`schema_migrations`).
3. Rodar auditoria automática de drift entre:
   - baseline esperado,
   - banco real,
   - colunas consultadas com `columnExists`.
4. Tratar `schema_real.sql` como histórico arquivado, não como artefato operacional.
5. Definir política: compatibilidade dinâmica é temporária e precisa de prazo para remoção.

## 3.3 Cartão digital / cashless

### Estado atual
O fluxo de cartão digital está mais maduro que no estágio inicial:
- `CardController` já filtra listagem e transações por `organizer_id`.
- `WalletSecurityService` faz lock pessimista (`FOR UPDATE`) e valida saldo.
- `SalesDomainService` reaproveita o serviço de carteira para débito no checkout.
- `POST /cards/resolve` já devolve `card_id` canônico para o frontend do POS.
- checkout online e `POST /sync` exigem `card_id` UUID no caminho operacional.
- a validação real de 2026-03-20 passou com a sequência:
  - `POST /cards/resolve` `200`
  - recarga `200`
  - checkout online `200`
  - venda offline via `POST /sync` `200`
  - saldo rastreado de `3308.00 -> 3309.00 -> 3297.00 -> 3292.00`

### Inconsistências / fragilidades
1. **A compatibilidade legada para `card_token` ainda depende de introspecção de schema em runtime.**  
   `columnExists()` continua sendo usado para presentation/compatibilidade. Isso é aceitável como transição, ruim como desenho permanente.

2. **A resposta ainda expõe `card_token` como alias de apresentação mesmo quando o ambiente só materializa `id`.**  
   Operacionalmente é seguro, mas semanticamente continua ambíguo.

3. **A trilha de correlação online/offline ainda não é uniforme.**  
   No smoke real, o checkout online criou a venda `245` com débito e saldo corretos, mas sem persistir o `offline_id` enviado pelo frontend; já a venda offline sincronizada criou a venda `246` com `offline_id` e `offline_queue` plenamente rastreáveis.

### Bugs silenciosos possíveis
1. Tenants legados com `card_token` ainda seguem um contrato um pouco diferente dos tenants já 100% canônicos.
2. Se o frontend voltar a tentar vender offline com referência não canônica, o sync vai rejeitar corretamente, mas o operador sentirá a falha na reconciliação.
3. Lacunas de correlação entre venda online e trilha offline podem dificultar investigação posterior mesmo com `card_transactions` endurecida.

### Soluções recomendadas
1. Manter **`card_id` UUID como contrato único operacional**.
2. Tratar `card_token` apenas como compatibilidade legada via endpoint de resolução.
3. Propagar o identificador de correlação enviado pelo frontend também no checkout online.
4. Remover `columnExists()` do fluxo crítico assim que os tenants legados forem aposentados.

## 3.4 IA

### Estado atual
- O POS pede contexto setorial ao backend e envia a pergunta para `/ai/insight`.
- `AIBillingService` registra uso de IA com `organizer_id`, `event_id`, tokens e custo estimado.

### Fragilidades
1. **Billing de IA é best effort e silencioso.**  
   Se falhar, só gera `error_log`; o fluxo principal continua.
2. **Não há pipeline analítico de custo/limite por tenant claramente aplicado em runtime.**
3. **A IA do POS ainda depende de contexto montado sob demanda, sem snapshot persistido.**

### Risco real
A camada de IA hoje é boa para UX e rastreio básico, mas ainda não parece pronta para governança forte de custo, cota, SLA e explainability.

### Soluções recomendadas
1. Criar política de limites por organizer.
2. Expor dashboard de consumo por tenant/evento/agente.
3. Registrar falhas de billing/IA em trilha operacional própria, e não só em `error_log`.

## 3.5 Consumidores / filas / assíncrono

### Achados confirmados
1. **Existe fila offline local no frontend (`Dexie`) e trilha de reconciliação no backend (`offline_queue`).**
2. **Existe persistência de mensageria (`message_deliveries`, `messaging_webhook_events`).**
3. **Não encontrei um worker/consumer explícito no repositório para processar outbox de mensageria de forma assíncrona desacoplada.**

### Diagnóstico
O sistema já tem **dados e tabelas de trilha**, mas ainda não uma arquitetura completa de **consumidores reais** para mensageria/outbox. Na prática:
- o sync offline está modelado como reconciliação síncrona de lote;
- a mensageria registra histórico e webhook, mas não mostra um processamento assíncrono dedicado tipo worker/queue consumer.

### Riscos
1. Retry e backoff ficam implícitos ou espalhados.
2. Falhas de provider podem ser reprocessadas sem política central.
3. Operação de mensageria ainda depende demais do caminho síncrono da aplicação.

### Soluções recomendadas
1. Introduzir um consumer real para outbox de mensageria.
2. Separar estados `queued`, `processing`, `sent`, `failed`, `dead_letter`.
3. Criar estratégia de retry/backoff por provider.
4. Expor painel operacional de filas.

## 3.6 Franquias

### Achado principal
**Não encontrei um domínio explícito de franquias.**

O que existe hoje é:
- multi-tenant por `organizer_id`;
- `organizer_settings` para branding/configuração;
- alguma base white-label.

Isso **não equivale** a um módulo de franquias.

### O que está faltando para dizer que existe “franquias”
1. Entidade de rede / franqueadora.
2. Entidade de unidade / franqueado.
3. ACL hierárquica entre matriz e operação local.
4. Repasse/comissão por rede/unidade.
5. Compartilhamento controlado de catálogo, branding e playbooks.
6. Dashboards agregados por rede e dashboards locais por unidade.

### Conclusão sobre esse ponto
Se a expectativa de negócio é operar como **franquia**, o projeto ainda está em **estágio white-label multi-tenant**, não em arquitetura de franquias.

## 4. Desenho recomendado de evolução

## 4.1 Desenho alvo para POS e gráficos

### Camadas sugeridas
1. **Sector API Adapter**  
   Normaliza respostas de `bar/food/shop`.

2. **Report Cache Layer**  
   Chave: `sector:event_id:filter`.

3. **Chart ViewModel**  
   Entrega ao gráfico apenas:
   - `status`
   - `series`
   - `lastUpdatedAt`
   - `isStale`
   - `error`

4. **Chart Components puros**  
   Sem request, sem timer, sem regra de fallback.

### Benefícios
- elimina degradação perceptiva;
- reduz redraw desnecessário;
- melhora debug;
- separa “dados” de “desenho”.

## 4.2 Desenho alvo para banco e migrations

1. `schema_current.sql` como baseline único.
2. migrations numeradas sem colisão.
3. `schema_migrations` transacional.
4. CI com verificação de drift.
5. remoção programada de `columnExists` dos fluxos críticos.

## 4.3 Desenho alvo para cashless

1. `card_id` UUID como contrato único e irreversível no operacional.
2. endpoint separado para localizar cartão por QR/token e devolver `card_id` canônico.
3. checkout e sync só aceitam `card_id`.
4. auditoria específica de resolução de carteira, colisões de identificador e correlação online/offline.

## 4.4 Desenho alvo para consumidores

1. outbox persistente;
2. worker dedicado;
3. retry/backoff;
4. dead-letter queue;
5. painel de observabilidade.

## 4.5 Desenho alvo para franquias

### Modelo mínimo
- `franchise_networks`
- `franchise_units`
- `franchise_users`
- `franchise_catalog_policies`
- `franchise_financial_rules`
- `franchise_reports`

### Regra de acesso
- rede vê consolidado;
- unidade vê apenas sua operação;
- organizer local continua existindo, mas subordinado a uma estrutura superior opcional.

## 5. Priorização prática

### P0 — precisa entrar antes de novas features grandes
1. Resolver drift de migrations / baseline.
2. Estabilizar camada de relatórios do POS para parar a degradação visual.
3. Encerrar o residual de compatibilidade dinâmica do cashless (`card_token` / introspecção / correlação online-offline).
4. Criar observabilidade real para erros silenciosos de POS/relatórios/sync.

### P1 — sequência natural
1. Extrair `ReportStateAdapter`.
2. Reduzir compatibilidade dinâmica de schema.
3. Criar consumer real para mensageria/outbox.
4. Formalizar limites/custos de IA por tenant.

### P2 — produto / expansão
1. Definir se haverá realmente módulo de franquias.
2. Se sim, modelar rede/unidade/ACL/financeiro antes da UI.

## 6. Conclusão final

A plataforma está **mais madura do que uma base improvisada**, especialmente em POS, sync offline e cashless, e a rodada operacional de 2026-03-20 fechou uma parte importante do residual do módulo. Ainda assim, ela não está “limpa” o bastante para escalar sem atrito operacional. O principal diagnóstico agora é:

- **o POS já funciona e os relatórios melhoraram, mas o desenho de estado ainda pode degradar a experiência visual em trocas de contexto**;
- **o banco já sustenta a frente atual do POS, mas ainda opera com governança de schema frágil**;
- **o cashless está funcional, endurecido e validado em smoke real, porém ainda carrega compatibilidade legada demais**;
- **os consumidores assíncronos ainda não estão completos como arquitetura**;
- **franquias ainda não existem como domínio real**.

Se eu tivesse que resumir em uma frase: **a frente do POS já está em condição real de fechamento operacional, mas a plataforma ainda precisa de consolidação de schema, observabilidade e remoção de legado antes de expandir sem controle**.
