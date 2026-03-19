# Auditoria técnica completa — POS / Bar / Food / Shop / Cartão Digital / IA / Franquias

_Data da auditoria:_ 2026-03-19  
_Base inspecionada:_ código local em `/workspace/enjoyfun`

## 1. Resumo executivo

Esta auditoria confirma que a base atual da EnjoyFun já tem **boa evolução arquitetural no frontend do POS** e **hardening importante no backend comercial**, mas ainda mantém **riscos estruturais e operacionais relevantes** em cinco frentes:

1. **Drift de banco e migrations**: o baseline oficial (`schema_current.sql`) e o snapshot histórico (`schema_real.sql`) divergem materialmente, e o diretório de migrations já contém **numeração duplicada** (`012_*`), o que aumenta risco de aplicação fora de ordem.
2. **POS / relatórios / gráficos**: a UI do POS foi centralizada em `POS.jsx`, porém os gráficos e relatórios ainda dependem fortemente de refresh assíncrono, polling fixo e reconstrução frequente do estado; isso explica a sensação de que “os gráficos degradam a cada seção aberta”.
3. **Cashless / cartão digital**: o fluxo atual está mais robusto que versões anteriores, mas ainda depende de **resolução dinâmica de schema** e de lookup flexível (`id`, `card_token`, `user_id`), o que é prático para compatibilidade mas perigoso para previsibilidade operacional.
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

### Bugs silenciosos / riscos operacionais
1. **Erros de carga de relatórios e catálogo são majoritariamente silenciosos na UI.**  
   Em vários fluxos o erro vai apenas para `console.error`, sem estado de erro estruturado para o operador. Resultado: a tela parece “sem dados” ou “instável”, mas o operador não recebe causa clara.

2. **Polling fixo de 30 segundos em relatórios do POS.**  
   `usePosReports` dispara `loadRecentSales()` no mount e mantém `setInterval` de 30s. Isso não é grave isoladamente, mas em navegação repetida entre seções pode gerar sensação de atualização abrupta, especialmente quando o backend devolve séries vazias, buckets diferentes ou dados ainda incompletos.

3. **Ausência de cache visual de último relatório estável.**  
   O hook usa `setReportData(null)` quando o evento é inválido e não mantém um estado explícito de “último snapshot válido”. O efeito percebido é de gráfico desmontando/remontando em vez de transição suave.

### Diagnóstico do problema “os gráficos degradam a cada seção aberta”

Pelo código atual, **não encontrei um único defeito fatal isolado**; o que existe é uma combinação de desenho que favorece degradação visual:

1. **Cada troca de aba em `POS.jsx` desmonta e remonta o bloco de relatórios.**  
   A aba `reports` é renderizada condicionalmente. Ao sair dela, os componentes de gráfico deixam de existir; ao entrar novamente, eles são recriados do zero.

2. **O hook de relatórios reinicia ciclo de polling e carga.**  
   `usePosReports` recarrega dados em cada ciclo de vida útil do container e atualiza periodicamente.

3. **Os gráficos dependem diretamente de `reportData.sales_chart` e `reportData.mix_chart` sem camada intermediária de estabilização.**  
   Se o backend responder vazio, parcial ou com bucketização diferente, a UI troca imediatamente para “Sem dados históricos” ou redesenha a série inteira.

4. **Os gráficos usam Recharts em modo responsivo, mas sem estratégia explícita de memoização/estabilização.**  
   Isso aumenta custo de redraw quando o container é reaberto, redimensionado ou remountado.

### Causa-raiz mais provável
A degradação percebida vem principalmente de **remount + polling + ausência de cache visual estável + resposta assíncrona acoplada diretamente ao desenho**, e não de um bug matemático no gráfico em si.

### Soluções recomendadas
**Curto prazo**
1. Manter `reportData` anterior enquanto uma nova leitura estiver carregando.
2. Adicionar `loading`, `error` e `stale` states explícitos no `usePosReports`.
3. Evitar trocar para “Sem dados” antes de concluir a nova requisição.
4. Aplicar `useMemo` nos datasets derivados dos gráficos.
5. Considerar persistir a aba de relatórios montada, escondendo via CSS em vez de desmontar toda a árvore.

**Médio prazo**
1. Criar um `ReportStateAdapter` entre API e componentes visuais.
2. Separar o POS em containers menores: `PosSalesContainer`, `PosInventoryContainer`, `PosInsightsContainer`.
3. Introduzir cache por `event_id + sector + filter` com TTL curto.

## 3.2 Banco de dados / schema / migrations

### Achados confirmados
1. **O projeto já documenta que `schema_current.sql` é o baseline oficial e que `schema_real.sql` é apenas histórico.**
2. **Mesmo assim, os dois arquivos divergem materialmente.**  
   O diff mostra presença no `schema_current.sql` de estruturas que não estão no `schema_real.sql`, incluindo trechos de `commissaries`, `event_meal_services`, `event_timezone`, endurecimento de `participant_meals` e outros blocos.
3. **A numeração de migrations já está inconsistente.**  
   Existem dois arquivos `012_*`:
   - `012_event_meal_services_model.sql`
   - `012_meal_services_redesign.sql`
4. **O `migrations_applied.log` é manual e parcial.**  
   Ele registra apenas parte do histórico recente e não substitui uma tabela transacional de versionamento.

### Riscos
1. **Ambiente sobe com schema diferente do esperado pelo código.**
2. **Migrations podem ser aplicadas em ordem errada ou puladas.**
3. **Compatibilidade defensiva no backend (`columnExists`) vira muleta permanente.**
4. **Bugs silenciosos aparecem como “funciona em um tenant / quebra em outro”.**

### Diagnóstico
Hoje a base está operável, mas o modelo de governança de schema ainda está em **modo de reconciliação manual**, não em regime robusto de evolução controlada.

### Soluções recomendadas
1. Congelar `schema_current.sql` como única verdade operacional.
2. Renumerar migrations duplicadas antes de ampliar o backlog.
3. Criar uma tabela real de versionamento (`schema_migrations`).
4. Rodar auditoria automática de drift entre:
   - baseline esperado,
   - banco real,
   - colunas consultadas com `columnExists`.
5. Definir política: compatibilidade dinâmica é temporária e precisa de prazo para remoção.

## 3.3 Cartão digital / cashless

### Estado atual
O fluxo de cartão digital está mais maduro que no estágio inicial:
- `CardController` já filtra listagem e transações por `organizer_id`.
- `WalletSecurityService` faz lock pessimista (`FOR UPDATE`) e valida saldo.
- `SalesDomainService` reaproveita o serviço de carteira para débito no checkout.

### Inconsistências / fragilidades
1. **Resolução do cartão é permissiva demais.**  
   O serviço tenta resolver por:
   - `digital_cards.id::text`,
   - `card_token` se a coluna existir,
   - `user_id` se a referência for numérica.

   Isso melhora compatibilidade, mas mistura semânticas diferentes de identidade.

2. **O sistema ainda depende de introspecção de schema em runtime.**  
   `columnExists()` é usado em fluxo crítico de checkout/carteira. Isso é bom como transição, ruim como desenho permanente.

3. **O frontend do POS chama o identificador de `cardToken`, mas envia `card_id`.**  
   O backend aceitou aliases múltiplos para amortecer divergência, porém o contrato ainda não está elegantemente normalizado.

### Bugs silenciosos possíveis
1. Checkout funciona em um tenant com `card_token`, mas em outro só por `id`.
2. Operador pode digitar um número e acionar lookup por `user_id`, alterando sem perceber a semântica da cobrança.
3. Drift de schema pode não explodir imediatamente, apenas mudar o caminho de resolução do cartão.

### Soluções recomendadas
1. Eleger **um identificador canônico** para checkout (`card_id` UUID).
2. Tratar `card_token` apenas como compatibilidade legada com flag de depreciação.
3. Remover lookup por `user_id` do checkout operacional, ou isolá-lo em fluxo explícito “cobrar por usuário”.
4. Criar contrato frontend/backend único: `card_id` sempre UUID.

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

1. `card_id` UUID como contrato único.
2. endpoint separado para localizar cartão por QR/token e devolver `card_id` canônico.
3. checkout só aceita `card_id`.
4. auditoria específica de resolução de carteira e colisões de identificador.

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
3. Canonizar o contrato de cartão digital (`card_id`).
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

A plataforma está **mais madura do que uma base improvisada**, especialmente em POS, sync offline e cashless, mas ainda não está “limpa” o bastante para escalar sem atrito operacional. O principal diagnóstico é:

- **o POS já funciona, mas o desenho de estado dos relatórios ainda degrada a experiência visual**;
- **o banco já sustenta muita coisa, mas ainda opera com governança de schema frágil**;
- **o cashless está funcional, porém excessivamente tolerante a drift**;
- **os consumidores assíncronos ainda não estão completos como arquitetura**;
- **franquias ainda não existem como domínio real**.

Se eu tivesse que resumir em uma frase: **a base está pronta para hardening sério e consolidação arquitetural, mas ainda não para expansão desordenada de novos módulos**.
