# Progresso 2

## Continuidade de Registro

- **Responsável:** Codex
- **Status:** Ativo
- **Observação:** a partir desta etapa, os próximos registros de progresso devem ser feitos neste arquivo (`docs/progresso2.md`).
- **Diretriz consolidada:** todos os novos registros de progresso, diagnóstico e fechamento operacional devem ser lançados em `docs/progresso2.md`, deixando `docs/progresso1.md` apenas como histórico das etapas anteriores.

## Ajuste Operacional v2.1 (backfill ticket type no evento UBUNTU)

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** banco local / compatibilidade evento legado

### Diagnóstico confirmado
- O evento `UBUNTU` já possuía `2 lotes` e `2 comissários`, mas estava com `0 ticket_types`.
- A venda rápida depende obrigatoriamente de pelo menos um `ticket_type`, por isso o bloqueio persistia.

### Ação executada
- Criado o tipo padrão `Ingresso Comercial` para o evento `UBUNTU`.
- Os 2 lotes existentes do evento foram vinculados a esse tipo.

### Estado final conferido
- `ticket_types` do evento `UBUNTU`: `1`
- `LOTE PROMO` vinculado ao tipo `Ingresso Comercial`
- `LOTE 1` vinculado ao tipo `Ingresso Comercial`

## Dashboard Fase 4 — Expansão Controlada de KPIs Oficiais

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** dashboard backend + dashboard frontend

### KPIs adicionados com base real
- `remaining_balance` entrou no dashboard executivo como `Saldo Remanescente`, usando a base híbrida atual de `digital_cards.balance`.
- `offline_terminals_count` entrou no dashboard operacional como `Terminais Offline Reais`, baseado em `offline_queue` com `status = pending`.
- `critical_stock_products` entrou no dashboard operacional com:
  - card de contagem de produtos críticos
  - painel listando os produtos abaixo ou no limite mínimo
- `participants_present` entrou no dashboard executivo, consolidando:
  - convidados com status de presença válido
  - participantes com `status = present` ou histórico de `participant_checkins`
- `participants_by_category` entrou no dashboard executivo em painel próprio, combinando:
  - `guests` como categoria `guest`
  - `event_participants` agrupados por `participant_categories.type`

### KPIs que não entraram nesta fase
- `pre_event_recharges`
- `onsite_recharges`

### Motivo do bloqueio
- A base de `card_transactions` existe, mas a persistência de recargas no estado atual do projeto não grava `event_id` de forma consistente nos fluxos de recarga.
- Sem vínculo confiável com `events.starts_at` / `events.ends_at`, a separação entre recarga antecipada e recarga no evento ficaria semanticamente insegura.
- Decisão adotada: não exibir KPI fake nem parcial no dashboard.

### Ajuste estrutural aplicado
- A camada semântica criada na Fase 3 foi mantida.
- Os novos indicadores passaram por:
  - `DashboardDomainService.php` para coleta bruta
  - `MetricsDefinitionService.php` para definição semântica
  - `DashboardService.php` para montagem do payload compatível
- O frontend do dashboard foi expandido sem quebrar a hierarquia:
  - executivo
  - operacional
  - auxiliar

### Validação executada
- `php -l` em:
  - `backend/src/Services/DashboardDomainService.php`
  - `backend/src/Services/DashboardService.php`
  - `backend/src/Services/MetricsDefinitionService.php`
  - `backend/src/Controllers/AdminController.php`
- `eslint` em:
  - `frontend/src/pages/Dashboard.jsx`
  - `frontend/src/modules/dashboard/*.jsx`
A. Resumo executivo do domínio POS atual
O domínio POS está funcional, porém em estado de transição arquitetural: frontend já centralizado em um único POS.jsx (reutilizado por Bar/Food/Shop), enquanto backend ainda mantém três controllers com muita duplicação e regras parcialmente centralizadas em SalesDomainService. Isso confirma o cenário documentado oficialmente (“frontend centralizado / backend repetido”). 
Na prática, o domínio cobre: catálogo, estoque, checkout cashless, vendas recentes, relatório (faturamento + mix + timeline), IA contextual por setor e fluxo offline; mas com fragilidades relevantes de consistência entre frontend/backend e entre online/offline. 

B. O que já está bom

Centralização de UX no frontend: Bar/Food/Shop usam o mesmo componente base com fixedSector, reduzindo divergência visual/operacional de UI. F:frontend/src/pages/Food.jsx†L1-L5】

Contrato de relatório preservado no POS (report.total_revenue, report.total_items, sales_chart, mix_chart) e consumo consistente no frontend. 

Checkout com recomputo anti-fraude no backend: total recalculado via preço de banco e validação de divergência com total enviado. 

Uso de lock transacional em carteira (FOR UPDATE) para reduzir risco de double spending cashless. 

Evolução recente dos relatórios por setor com fallback de setor por products.sector/sales.sector e fallback de escopo por evento-organizer, alinhada com o progresso registrado. 

C. O que está duplicado ou mal dividido

Controllers Bar/Food/Shop duplicam muita lógica de CRUD, relatórios e insights; variam só em setor literal (bar|food|shop) e diferenças pontuais de resposta (jsonSuccess vs echo json_encode). 

SalesDomainService extraiu apenas checkout; listagem de vendas, mix, timeline e insights ainda estão triplicados nos controllers. 

Isso conflita com direção oficial de evoluir para Sales Engine com services compartilhados (ProductService, CheckoutService, SalesReportService). 

D. O que está frágil no frontend

POS.jsx está sobrecarregado: concentra UI + estado + rede + offline + checkout + relatórios + IA em um único arquivo grande. 

Mistura de dois mecanismos offline em paralelo:

fila local por localStorage (offline_sales_${sector}),

Dexie offlineQueue via useNetwork.
Isso aumenta risco de divergência de sincronização. 

Inconsistência de contrato de sync no frontend: syncQueue envia {records:q}, mas SyncController lê $body['items']. Isso pode gerar “sincronização fantasma” (toast de falha / nada processado). 

Polling fixo de 30s sem proteção de concorrência de requisições pode causar sobreposição de requests em redes lentas. 

eventId inicial hardcoded "1" antes de carregar eventos; em tenants sem evento 1, pode gerar telas inicialmente vazias/erro silencioso. 

O estado _recentSales é carregado mas não usado visualmente, indicando acoplamento/resíduo de implementação. 

E. O que está frágil no backend

Isolamento inconsistente em produtos (Bar):

updateProduct não filtra por setor (só id + organizer_id), podendo alterar produto de outro setor do mesmo organizer. 

deleteProduct não filtra organizer nem setor (apenas id), risco grave de deleção indevida cross-tenant/cross-setor. 

Stock checkout sem guarda de concorrência suficiente: UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? sem condição stock_qty >= ? e sem validação de linhas afetadas, permitindo saldo negativo sob corrida. 

Processo offline legado quebrado por schema atual: SyncController::processSale tenta inserir sales.card_id, mas essa coluna não existe em sales no schema atual. 

Sync cashless offline depende de digital_cards.card_token fixo, mas o schema real mostrado não inclui card_token; risco alto de falha em ambiente padrão. 

SalesDomainService usa WalletSecurityService com cardToken tratado como id::text na query; se frontend enviar token/link QR (não UUID), falha. 

F. O que pode estar quebrando os gráficos

Sem preenchimento de buckets vazios na timeline de Bar/Food/Shop (DATE_TRUNC só retorna horas com venda), causando gráficos “pulsando/sumindo” ao trocar filtro. 

Filtro temporal é janela relativa a NOW(); em baixa movimentação, pequenas mudanças de janela removem pontos e geram instabilidade perceptiva. 

total_revenue vem de SUM(si.subtotal) setorial, enquanto recent_sales mostra s.total_amount da venda completa; para vendas multi-setor, os números podem aparentar incoerência ao usuário. 

Frontend depende estritamente de report.sales_chart/mix_chart; qualquer retorno parcial do backend zera visual com “Sem dados”, sem fallback de robustez. 

G. Problemas de KPIs e semântica

KPIs exibidos no POS hoje: total_revenue, total_items, sales_chart, mix_chart (e estoques em tela de estoque). 

Drift semântico importante na IA: em requestGeminiInsight, total_revenue e total_items são agregados sem filtro de setor, enquanto top_products e stock_levels são filtrados por setor. Isso pode produzir insight contraditório (“contexto misturado”). 

Nomenclatura “BI & IA” no POS mistura camada analítica e assistente operacional no mesmo bloco, sem separação de propósito. 

Segundo KPI oficial, timeline/setor e estoque crítico são KPIs operacionais válidos, mas a implementação atual não formaliza nomenclatura oficial no payload (ex.: sales_timeline_by_sector, critical_stock_products). 

H. Problemas no cadastro e gestão de produtos

Fragilidade de isolamento no Bar já citada (update/delete). 

getProductIcon depende de heurstica por nome textual (“vodka”, “pizza”, etc.); isso é frágil para padronização visual e internacionalização. 

Divergência de default de low_stock_threshold (5 em Bar/Food vs 3 em Shop) sem convenção explícita no domínio. 

Validação de payload de produto é mínima (sem saneamento robusto de negativos/NaN no backend), com risco de dados ruins. 

I. Problemas no checkout e cashless

Risco de estoque negativo em concorrência (sem guarda atômica no update no checkout online). 

Offline e online usam pipelines diferentes: online passa por SalesDomainService + WalletSecurityService; offline passa por SyncController::processSale legado (com incompatibilidades de schema/contrato). 

Inconsistência de chave de cartão no offline: POS salva qr_token, mas sync lê card_token; pode perder débito no replay. 

Auditoria do checkout central existe no fluxo online (AuditService::log/logFailure), mas fluxo offline do SyncController não segue o mesmo padrão de auditoria. 

J. Problemas na IA por setor

Fluxo atual em 2 etapas:

POS chama /{sector}/insights para montar contexto;

POS chama /ai/insight com contexto + pergunta. 

Problema: contexto de receita/itens não está setorial nos controllers setoriais (query em sales sem setor), gerando IA potencialmente “fora do setor”. 

Acoplamento analítico+IA no mesmo componente de tela aumenta complexidade e risco de regressão UX/latência. 

GeminiService está importado nos controllers setoriais, mas fluxo efetivo usa AIController (OpenAI), sinal de camada antiga coexistindo/deriva técnica. 

K. Bugs reais identificados

Bug crítico de sync contrato: frontend envia /sync com records, backend espera items. 

Bug crítico de schema no sync: SyncController insere sales.card_id (coluna inexistente no schema real). 

Bug de segurança/isolamento em Bar delete: delete por id sem organizer/sector. 

Bug de isolamento em Bar update: update sem filtro de setor. 

Bug potencial de caixa/estoque: checkout sem guarda stock_qty >= quantity. 

Bug semântico de IA setorial: contexto mistura total global do evento com top produtos setoriais. 

L. Fragilidades arquiteturais

Domínio não está no estágio-alvo do Sales Engine recomendado (ainda muito controller-centric e duplicado). 

Frontend POS monolítico dificulta evolução segura (checkout, relatórios e IA no mesmo arquivo). 

Padrões de resposta backend heterogêneos (jsonSuccess vs echo json_encode), aumentando custo de manutenção e teste de contrato. 

Dois mecanismos offline e dois formatos de payload convivendo sem governança única. 

M. Próxima PR ideal para esse domínio
Prioridade 1 (obrigatória antes de crescer):

Corrigir hard bugs de sync (records/items, qr_token/card_token, insert em sales compatível com schema atual). 

Blindar isolamento Bar update/delete por organizer_id + setor + id. 

Hardening de estoque no checkout transacional (stock_qty >= qty + validação de rows afetadas). 

Prioridade 2 (consolidação):

Extrair relatório setorial para service comum (SalesReportService) e reduzir triplicação Bar/Food/Shop. 

Unificar padrão de resposta API e contratos de payload. 

Prioridade 3 (frontend estrutural):

Fatiar POS.jsx em módulos: PosCheckoutPanel, StockManager, PosReportsPanel, PosAiAssistant, hooks de dados/offline. 

Prioridade 4 (analytics/BI fora do POS):

Mover progressivamente blocos analíticos pesados para dashboard operacional/BI dedicado, mantendo POS focado em operação de caixa. 

N. Lista objetiva de arquivos que deverão ser mexidos na próxima fase

frontend/src/pages/POS.jsx

frontend/src/hooks/useNetwork.js

frontend/src/lib/db.js

backend/src/Controllers/BarController.php

backend/src/Controllers/FoodController.php

backend/src/Controllers/ShopController.php

backend/src/Services/SalesDomainService.php

backend/src/Controllers/SyncController.php

backend/src/Services/WalletSecurityService.php

(eventual padronização IA) backend/src/Controllers/AIController.php


✅ nl -ba docs/progresso.md | sed -n '1,260p'
✅ nl -ba docs/progresso1.md | sed -n '1,260p'
✅ nl -ba docs/enjoyfun_arquitetura_modulos_servicos_v_1.md | sed -n '1,260p'
✅ nl -ba docs/enjoyfun_kpis_formulas_oficiais_v_1.md | sed -n '1,260p'
✅ nl -ba docs/enjoyfun_mvps_oficiais_v_1.md | sed -n '1,220p'
✅ nl -ba docs/enjoyfun_naming_padroes_projeto_v_1.md | sed -n '1,240p'
✅ nl -ba frontend/src/pages/POS.jsx | sed -n '1,1060p'
✅ nl -ba backend/src/Controllers/BarController.php | sed -n '1,406p'
✅ nl -ba backend/src/Controllers/FoodController.php | sed -n '1,405p'
✅ nl -ba backend/src/Controllers/ShopController.php | sed -n '1,337p'
✅ nl -ba backend/src/Services/SalesDomainService.php | sed -n '1,260p'
✅ nl -ba backend/src/Controllers/SyncController.php | sed -n '1,220p'
✅ nl -ba backend/src/Services/WalletSecurityService.php | sed -n '1,150p'
✅ nl -ba database/schema_real.sql | sed -n '184,2310p'
A. Resumo executivo do domínio POS atual
O domínio POS está funcional, porém em estado de transição arquitetural: frontend já centralizado em um único POS.jsx (reutilizado por Bar/Food/Shop), enquanto backend ainda mantém três controllers com muita duplicação e regras parcialmente centralizadas em SalesDomainService. Isso confirma o cenário documentado oficialmente (“frontend centralizado / backend repetido”). 
Na prática, o domínio cobre: catálogo, estoque, checkout cashless, vendas recentes, relatório (faturamento + mix + timeline), IA contextual por setor e fluxo offline; mas com fragilidades relevantes de consistência entre frontend/backend e entre online/offline. 

B. O que já está bom

Centralização de UX no frontend: Bar/Food/Shop usam o mesmo componente base com fixedSector, reduzindo divergência visual/operacional de UI. F:frontend/src/pages/Food.jsx†L1-L5】

Contrato de relatório preservado no POS (report.total_revenue, report.total_items, sales_chart, mix_chart) e consumo consistente no frontend. 

Checkout com recomputo anti-fraude no backend: total recalculado via preço de banco e validação de divergência com total enviado. 

Uso de lock transacional em carteira (FOR UPDATE) para reduzir risco de double spending cashless. 

Evolução recente dos relatórios por setor com fallback de setor por products.sector/sales.sector e fallback de escopo por evento-organizer, alinhada com o progresso registrado. 

C. O que está duplicado ou mal dividido

Controllers Bar/Food/Shop duplicam muita lógica de CRUD, relatórios e insights; variam só em setor literal (bar|food|shop) e diferenças pontuais de resposta (jsonSuccess vs echo json_encode). 

SalesDomainService extraiu apenas checkout; listagem de vendas, mix, timeline e insights ainda estão triplicados nos controllers. 

Isso conflita com direção oficial de evoluir para Sales Engine com services compartilhados (ProductService, CheckoutService, SalesReportService). 

D. O que está frágil no frontend

POS.jsx está sobrecarregado: concentra UI + estado + rede + offline + checkout + relatórios + IA em um único arquivo grande. 

Mistura de dois mecanismos offline em paralelo:

fila local por localStorage (offline_sales_${sector}),

Dexie offlineQueue via useNetwork.
Isso aumenta risco de divergência de sincronização. 

Inconsistência de contrato de sync no frontend: syncQueue envia {records:q}, mas SyncController lê $body['items']. Isso pode gerar “sincronização fantasma” (toast de falha / nada processado). 

Polling fixo de 30s sem proteção de concorrência de requisições pode causar sobreposição de requests em redes lentas. 

eventId inicial hardcoded "1" antes de carregar eventos; em tenants sem evento 1, pode gerar telas inicialmente vazias/erro silencioso. 

O estado _recentSales é carregado mas não usado visualmente, indicando acoplamento/resíduo de implementação. 

E. O que está frágil no backend

Isolamento inconsistente em produtos (Bar):

updateProduct não filtra por setor (só id + organizer_id), podendo alterar produto de outro setor do mesmo organizer. 

deleteProduct não filtra organizer nem setor (apenas id), risco grave de deleção indevida cross-tenant/cross-setor. 

Stock checkout sem guarda de concorrência suficiente: UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? sem condição stock_qty >= ? e sem validação de linhas afetadas, permitindo saldo negativo sob corrida. 

Processo offline legado quebrado por schema atual: SyncController::processSale tenta inserir sales.card_id, mas essa coluna não existe em sales no schema atual. 

Sync cashless offline depende de digital_cards.card_token fixo, mas o schema real mostrado não inclui card_token; risco alto de falha em ambiente padrão. 

SalesDomainService usa WalletSecurityService com cardToken tratado como id::text na query; se frontend enviar token/link QR (não UUID), falha. 

F. O que pode estar quebrando os gráficos

Sem preenchimento de buckets vazios na timeline de Bar/Food/Shop (DATE_TRUNC só retorna horas com venda), causando gráficos “pulsando/sumindo” ao trocar filtro. 

Filtro temporal é janela relativa a NOW(); em baixa movimentação, pequenas mudanças de janela removem pontos e geram instabilidade perceptiva. 

total_revenue vem de SUM(si.subtotal) setorial, enquanto recent_sales mostra s.total_amount da venda completa; para vendas multi-setor, os números podem aparentar incoerência ao usuário. 

Frontend depende estritamente de report.sales_chart/mix_chart; qualquer retorno parcial do backend zera visual com “Sem dados”, sem fallback de robustez. 

G. Problemas de KPIs e semântica

KPIs exibidos no POS hoje: total_revenue, total_items, sales_chart, mix_chart (e estoques em tela de estoque). 

Drift semântico importante na IA: em requestGeminiInsight, total_revenue e total_items são agregados sem filtro de setor, enquanto top_products e stock_levels são filtrados por setor. Isso pode produzir insight contraditório (“contexto misturado”). 

Nomenclatura “BI & IA” no POS mistura camada analítica e assistente operacional no mesmo bloco, sem separação de propósito. 

Segundo KPI oficial, timeline/setor e estoque crítico são KPIs operacionais válidos, mas a implementação atual não formaliza nomenclatura oficial no payload (ex.: sales_timeline_by_sector, critical_stock_products). 

H. Problemas no cadastro e gestão de produtos

Fragilidade de isolamento no Bar já citada (update/delete). 

getProductIcon depende de heurstica por nome textual (“vodka”, “pizza”, etc.); isso é frágil para padronização visual e internacionalização. 

Divergência de default de low_stock_threshold (5 em Bar/Food vs 3 em Shop) sem convenção explícita no domínio. 

Validação de payload de produto é mínima (sem saneamento robusto de negativos/NaN no backend), com risco de dados ruins. 

I. Problemas no checkout e cashless

Risco de estoque negativo em concorrência (sem guarda atômica no update no checkout online). 

Offline e online usam pipelines diferentes: online passa por SalesDomainService + WalletSecurityService; offline passa por SyncController::processSale legado (com incompatibilidades de schema/contrato). 

Inconsistência de chave de cartão no offline: POS salva qr_token, mas sync lê card_token; pode perder débito no replay. 

Auditoria do checkout central existe no fluxo online (AuditService::log/logFailure), mas fluxo offline do SyncController não segue o mesmo padrão de auditoria. 

J. Problemas na IA por setor

Fluxo atual em 2 etapas:

POS chama /{sector}/insights para montar contexto;

POS chama /ai/insight com contexto + pergunta. 

Problema: contexto de receita/itens não está setorial nos controllers setoriais (query em sales sem setor), gerando IA potencialmente “fora do setor”. 

Acoplamento analítico+IA no mesmo componente de tela aumenta complexidade e risco de regressão UX/latência. 

GeminiService está importado nos controllers setoriais, mas fluxo efetivo usa AIController (OpenAI), sinal de camada antiga coexistindo/deriva técnica. 

K. Bugs reais identificados

Bug crítico de sync contrato: frontend envia /sync com records, backend espera items. 

Bug crítico de schema no sync: SyncController insere sales.card_id (coluna inexistente no schema real). 

Bug de segurança/isolamento em Bar delete: delete por id sem organizer/sector. 

Bug de isolamento em Bar update: update sem filtro de setor. 

Bug potencial de caixa/estoque: checkout sem guarda stock_qty >= quantity. 

Bug semântico de IA setorial: contexto mistura total global do evento com top produtos setoriais. 

L. Fragilidades arquiteturais

Domínio não está no estágio-alvo do Sales Engine recomendado (ainda muito controller-centric e duplicado). 

Frontend POS monolítico dificulta evolução segura (checkout, relatórios e IA no mesmo arquivo). 

Padrões de resposta backend heterogêneos (jsonSuccess vs echo json_encode), aumentando custo de manutenção e teste de contrato. 

Dois mecanismos offline e dois formatos de payload convivendo sem governança única. 

M. Próxima PR ideal para esse domínio
Prioridade 1 (obrigatória antes de crescer):

Corrigir hard bugs de sync (records/items, qr_token/card_token, insert em sales compatível com schema atual). 

Blindar isolamento Bar update/delete por organizer_id + setor + id. 

Hardening de estoque no checkout transacional (stock_qty >= qty + validação de rows afetadas). 

Prioridade 2 (consolidação):

Extrair relatório setorial para service comum (SalesReportService) e reduzir triplicação Bar/Food/Shop. 

Unificar padrão de resposta API e contratos de payload. 

Prioridade 3 (frontend estrutural):

Fatiar POS.jsx em módulos: PosCheckoutPanel, StockManager, PosReportsPanel, PosAiAssistant, hooks de dados/offline. 

Prioridade 4 (analytics/BI fora do POS):

Mover progressivamente blocos analíticos pesados para dashboard operacional/BI dedicado, mantendo POS focado em operação de caixa. 

N. Lista objetiva de arquivos que deverão ser mexidos na próxima fase

frontend/src/pages/POS.jsx

frontend/src/hooks/useNetwork.js

frontend/src/lib/db.js

backend/src/Controllers/BarController.php

backend/src/Controllers/FoodController.php

backend/src/Controllers/ShopController.php

backend/src/Services/SalesDomainService.php

backend/src/Controllers/SyncController.php

backend/src/Services/WalletSecurityService.php

(eventual padronização IA) backend/src/Controllers/AIController.php


✅ nl -ba docs/progresso.md | sed -n '1,260p'
✅ nl -ba docs/progresso1.md | sed -n '1,260p'
✅ nl -ba docs/enjoyfun_arquitetura_modulos_servicos_v_1.md | sed -n '1,260p'
✅ nl -ba docs/enjoyfun_kpis_formulas_oficiais_v_1.md | sed -n '1,260p'
✅ nl -ba docs/enjoyfun_mvps_oficiais_v_1.md | sed -n '1,220p'
✅ nl -ba docs/enjoyfun_naming_padroes_projeto_v_1.md | sed -n '1,240p'
✅ nl -ba frontend/src/pages/POS.jsx | sed -n '1,1060p'
✅ nl -ba backend/src/Controllers/BarController.php | sed -n '1,406p'
✅ nl -ba backend/src/Controllers/FoodController.php | sed -n '1,405p'
✅ nl -ba backend/src/Controllers/ShopController.php | sed -n '1,337p'
✅ nl -ba backend/src/Services/SalesDomainService.php | sed -n '1,260p'
✅ nl -ba backend/src/Controllers/SyncController.php | sed -n '1,220p'
✅ nl -ba backend/src/Services/WalletSecurityService.php | sed -n '1,150p'
✅ nl -ba database/schema_real.sql | sed -n '184,2310p'
## POS — Plano Oficial de Correção por Fases

- **Responsável:** Planejamento conjunto
- **Status:** Planejado
- **Escopo:** frontend / backend / operação / analytics / cashless
- **Próxima ação sugerida:** iniciar a Fase 1 com hardening crítico do POS
- **Bloqueios / dependências:** depende de execução em fases, sem misturar refatoração estrutural com correção crítica

### Ordem oficial das fases

#### Fase 1 — Hardening crítico
- corrigir sync offline
- corrigir isolamento inseguro em update/delete de produtos
- blindar estoque no checkout

#### Fase 2 — Estabilização analítica
- estabilizar gráficos e relatórios
- corrigir semântica setorial do contexto da IA
- reduzir inconsistências entre cards, mix e timeline

#### Fase 3 — Consolidação backend do Sales Engine
- extrair relatórios e CRUD repetidos para services compartilhados
- reduzir duplicação entre Bar/Food/Shop
- padronizar respostas do backend

#### Fase 4 — Refatoração estrutural do frontend POS
- modularizar POS.jsx
- separar checkout, estoque, relatórios e IA
- isolar hooks de dados e offline

## POS Fase 1 — PR 1 — Bloco B: Isolamento e segurança de produto no Bar

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** hardening cirúrgico de `updateProduct` e `deleteProduct` no domínio Bar
- **Arquivos tocados:**
  - `backend/src/Controllers/BarController.php`
- **Próxima ação sugerida:** executar a PR 2 da Fase 1 do POS, focada em blindagem transacional de estoque no checkout compartilhado
- **Bloqueios / dependências:** sem bloqueio para esta PR; a sequência segura continua sendo `Bloco C -> Bloco A`

### Resumo objetivo do que foi ajustado
- `updateProduct` passou a validar escopo por `id + organizer_id + setor do Bar`, usando a regra segura do domínio (`sector = 'bar' OR sector IS NULL`) para manter compatibilidade com legados.
- `updateProduct` não retorna mais sucesso silencioso quando o produto não existe ou está fora do escopo.
- `deleteProduct` passou a exigir `organizer_id` e escopo de setor do Bar antes de qualquer exclusão.
- `deleteProduct` agora bloqueia explicitamente a exclusão quando o produto possui vendas vinculadas, em vez de depender de comportamento implícito do banco.
- `deleteProduct` não retorna mais sucesso silencioso quando nada foi removido.

### Diferenciação de falhas aplicada
- `404`: produto do Bar não encontrado ou fora do escopo do organizer autenticado.
- `409`: exclusão bloqueada por vínculo com vendas.
- `500`: erro inesperado de execução.

### Validação executada
- `php -l backend/src/Controllers/BarController.php`

## POS Fase 1 — PR 2 — Bloco C: Blindagem de estoque no checkout

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** hardening transacional da baixa de estoque no checkout online canônico
- **Arquivos tocados:**
  - `backend/src/Services/SalesDomainService.php`
- **Próxima ação sugerida:** executar a PR 3 da Fase 1 do POS, focada em sync offline e alinhamento do replay com o pipeline online
- **Bloqueios / dependências:** sem bloqueio para esta PR; a próxima dependência crítica é alinhar o fluxo offline ao contrato e ao schema atual

### Resumo objetivo do que foi ajustado
- O checkout passou a resolver os itens de forma segura antes da venda, validando `product_id`, `quantity`, `event_id`, `organizer_id` e setor coerente com a operação.
- A baixa de estoque agora acontece com guarda atômica no próprio SQL, exigindo:
  - `id`
  - `event_id`
  - `organizer_id`
  - setor coerente
  - `stock_qty >= quantidade`
- O checkout passou a validar `rowCount()` após cada baixa.
- Quando a baixa de qualquer item falha, a transação inteira é abortada com erro operacional claro.
- A ordem de processamento foi endurecida para baixar estoque antes de inserir `sale_items`, reduzindo risco de resíduo intermediário dentro da transação.

### Falha operacional aplicada
- `409`: `Estoque insuficiente para o produto: {nome}.`
- A falha reverte a transação inteira, impedindo:
  - venda parcial
  - `sale_items` parcial
  - débito definitivo de carteira

### Validação executada
- `php -l backend/src/Services/SalesDomainService.php`

## POS Fase 1 — PR 3 — Bloco A: Sync offline

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** normalização do replay offline sobre o pipeline online canônico, sem redesign da arquitetura de fila
- **Arquivos tocados:**
  - `frontend/src/pages/POS.jsx`
  - `frontend/src/hooks/useNetwork.js`
  - `backend/src/Controllers/SyncController.php`
  - `backend/src/Services/SalesDomainService.php`
  - `backend/src/Services/WalletSecurityService.php`
- **Próxima ação sugerida:** encerrar a Fase 1 do POS e seguir para a Fase 2, focada em estabilização analítica sem misturar com refactor estrutural
- **Bloqueios / dependências:** o hardening crítico foi fechado; a próxima frente depende de validação operacional em reconnect e fila parcial

### Resumo objetivo do que foi ajustado
- O contrato canônico do `/sync` passou a ser `items[]`.
- `records[]` ficou aceito apenas como compatibilidade transitória de entrada no backend.
- A chave canônica da carteira foi consolidada como `card_id`, resolvida contra `digital_cards.id::text`.
- `qr_token`, `card_token` e `customer_id` ficaram tratados apenas como aliases transitórios de entrada.
- O replay offline deixou de depender do fluxo legado que tentava usar `sales.card_id` e `digital_cards.card_token`.
- O `/sync` passou a normalizar o payload e reaproveitar o `SalesDomainService::processCheckout(...)`, herdando a mesma validação material do checkout online.
- A idempotência passou a ser protegida por `offline_queue.offline_id` e, no service, também por `sales.offline_id` quando presente.
- O frontend deixou de descartar a fila inteira em sincronização parcial: agora remove apenas `processed_ids` e preserva os itens que falharam.

### Contrato canônico adotado
- Envelope oficial: `items[]`
- Chave oficial da carteira: `card_id`
- Padrão real da carteira no backend: `digital_cards.id::text`

### Validação executada
- `php -l backend/src/Controllers/SyncController.php`
- `php -l backend/src/Services/SalesDomainService.php`
- `php -l backend/src/Services/WalletSecurityService.php`
- tentativa de `eslint` no frontend bloqueada porque o projeto não possui `eslint.config.*` compatível com ESLint 9 no estado atual

## POS Fase 2 — PR 1 — Bloco B: Relatorios e KPIs do POS

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** alinhamento semântico do relatório setorial do POS, sem tocar em timeline/buckets, IA, sync ou checkout
- **Arquivos tocados:**
  - `frontend/src/pages/POS.jsx`
  - `backend/src/Controllers/BarController.php`
  - `backend/src/Controllers/FoodController.php`
  - `backend/src/Controllers/ShopController.php`
- **Próxima ação sugerida:** executar a PR 2 da Fase 2 do POS, focada exclusivamente em estabilidade de gráficos e timeline
- **Bloqueios / dependências:** sem bloqueio funcional; a próxima etapa depende apenas de validar visualmente a coerência do relatório setorial em Bar/Food/Shop

### Resumo objetivo do que foi ajustado
- `recent_sales` deixou de expor `total_amount` e `total_items` da venda inteira como se fossem do setor atual.
- Os endpoints setoriais passaram a devolver `recent_sales.total_amount` e `recent_sales.total_items` no mesmo recorte setorial já usado por:
  - `report.total_revenue`
  - `report.total_items`
  - `mix_chart`
  - `sales_chart`
- Para compatibilidade operacional e auditoria, os endpoints também passaram a expor:
  - `sale_total_amount`
  - `sale_total_items`
  como referência explícita da venda completa.
- `recent_sales` passou a listar apenas vendas `completed`, alinhando a lista ao mesmo universo dos KPIs do relatório.
- A UI do POS passou a deixar claro que os cards, a timeline e o mix referem-se ao setor atual.

### Semântica consolidada nesta PR
- `report.total_revenue`: faturamento do setor atual
- `report.total_items`: itens vendidos no setor atual
- `recent_sales.total_amount`: valor do setor dentro da venda
- `recent_sales.total_items`: quantidade do setor dentro da venda
- `recent_sales.sale_total_amount`: valor total bruto da venda completa
- `recent_sales.sale_total_items`: quantidade total da venda completa

### Validação executada
- `php -l backend/src/Controllers/BarController.php`
- `php -l backend/src/Controllers/FoodController.php`
- `php -l backend/src/Controllers/ShopController.php`

- validação manual do trecho alterado em `frontend/src/pages/POS.jsx`
- tentativa de `eslint` no frontend não executada nesta etapa pelo mesmo bloqueio já conhecido de ausência de `eslint.config.*` compatível com ESLint 9

## POS Fase 2 — PR 2 — Bloco A: Graficos e timeline

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** estabilização de `sales_chart` e resiliência visual do relatório do POS, sem tocar em IA, sync, checkout ou estoque
- **Arquivos tocados:**
  - `frontend/src/pages/POS.jsx`
  - `backend/src/Controllers/BarController.php`
  - `backend/src/Controllers/FoodController.php`
  - `backend/src/Controllers/ShopController.php`
- **Próxima ação sugerida:** executar a PR 3 da Fase 2 do POS, focada exclusivamente no contexto setorial da IA
- **Bloqueios / dependências:** sem bloqueio técnico; a próxima etapa depende apenas de validar o contexto analítico setorial enviado para `/ai/insight`

### Resumo objetivo do que foi ajustado
- Os 3 endpoints setoriais passaram a devolver `sales_chart` com série contínua no filtro selecionado.
- Buckets sem venda agora entram com `revenue = 0`, evitando buracos na timeline.
- A lógica de timeline foi padronizada entre Bar/Food/Shop:
  - `1h`
  - `5h`
  - `24h`
  - `total`
- No filtro `total`, quando a série atravessa mais de um dia, o rótulo do bucket passou a incluir data e hora para evitar repetição ambígua no eixo X.
- `POS.jsx` passou a ignorar respostas antigas fora de ordem em `loadRecentSales()`, reduzindo sobrescrita errática em troca rápida de filtro e polling.
- `POS.jsx` deixou de zerar `reportData` por resposta intermediária sem `report`, reduzindo flicker desnecessário.

### Shape consolidado do `sales_chart`
- Série contínua
- Bucket horário
- Campos:
  - `time`
  - `bucket_at`
  - `revenue`

### Validação executada
- `php -l backend/src/Controllers/BarController.php`
- `php -l backend/src/Controllers/FoodController.php`
- `php -l backend/src/Controllers/ShopController.php`

- validação manual do trecho alterado em `frontend/src/pages/POS.jsx`
- tentativa de `eslint` no frontend não executada nesta etapa pelo mesmo bloqueio já conhecido de ausência de `eslint.config.*` compatível com ESLint 9

## POS Fase 2 — PR 3 — Bloco C: IA setorial

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** alinhamento do contexto enviado para `/ai/insight` ao mesmo recorte setorial do POS, sem tocar em sync, checkout, estoque ou gráficos
- **Arquivos tocados:**
  - `backend/src/Controllers/BarController.php`
  - `backend/src/Controllers/FoodController.php`
  - `backend/src/Controllers/ShopController.php`
  - `backend/src/Controllers/AIController.php`
- **Próxima ação sugerida:** encerrar a Fase 2 do POS com validação operacional cruzada de Bar/Food/Shop e então preparar o desenho da Fase 3 de consolidação backend do Sales Engine
- **Bloqueios / dependências:** sem bloqueio técnico imediato; a etapa seguinte depende apenas de validar em evento real que os insights mudaram junto com o setor e com o filtro temporal

### Resumo objetivo do que foi ajustado
- `requestGeminiInsight()` em Bar/Food/Shop deixou de usar `sales.total_amount` global do evento para montar `total_revenue` da IA.
- `total_revenue` passou a ser calculado por soma de `sale_items.subtotal` apenas do setor atual.
- `total_items` passou a ser calculado apenas com itens do setor atual.
- `top_products` foi alinhado ao mesmo escopo setorial e ao mesmo fallback de `organizer_id` já usado nos relatórios setoriais.
- O shape do contexto foi preservado:
  - `total_revenue`
  - `total_items`
  - `top_products`
  - `stock_levels`
  - `time_filter`
  - `sector`
- `AIController.php` recebeu apenas um reforço semântico mínimo no prompt para explicitar o `sector` já enviado pelo contexto, sem redesign do fluxo de IA.

### Semântica consolidada nesta PR
- A IA do Bar passa a receber apenas contexto do Bar.
- A IA do Food passa a receber apenas contexto do Food.
- A IA do Shop passa a receber apenas contexto do Shop.
- `total_revenue`, `total_items`, `top_products` e `stock_levels` agora pertencem ao mesmo universo operacional do setor atual.

### Validação executada
- `php -l backend/src/Controllers/BarController.php`
- `php -l backend/src/Controllers/FoodController.php`
- `php -l backend/src/Controllers/ShopController.php`
- `php -l backend/src/Controllers/AIController.php`

## POS Fase 3 — PR 1 — Bloco A: Relatorios setoriais

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** extração da lógica repetida de relatórios setoriais e do contexto setorial da IA para um service compartilhado, sem tocar em CRUD de produtos, checkout, sync ou frontend
- **Arquivos tocados:**
  - `backend/src/Controllers/BarController.php`
  - `backend/src/Controllers/FoodController.php`
  - `backend/src/Controllers/ShopController.php`
  - `backend/src/Services/SalesReportService.php`
- **Próxima ação sugerida:** executar a PR 2 da Fase 3 do POS, criando `ProductService` e unificando o CRUD/catalogo com o hardening correto usado como baseline no Bar
- **Bloqueios / dependências:** sem bloqueio técnico imediato; a próxima etapa depende apenas de validar que o payload de relatórios e o contexto de IA continuam equivalentes nos 3 setores

### Resumo objetivo do que foi ajustado
- Foi criado `SalesReportService.php` como fonte comum de leitura analítica/setorial do POS.
- O novo service centraliza:
  - `recent_sales`
  - `report.total_revenue`
  - `report.total_items`
  - `report.sales_chart`
  - `report.mix_chart`
  - contexto setorial usado em `/insights`
- `BarController.php`, `FoodController.php` e `ShopController.php` deixaram de montar inline a lógica pesada de relatório e passaram a delegar para o service comum.
- Os helpers locais de timeline que existiam dentro de Food/Shop/Bar foram removidos dos controllers, porque a série passou a ser construída no service.
- O shape estabilizado na Fase 2 foi preservado para manter compatibilidade total com `POS.jsx`.

### Compatibilidade preservada
- `recent_sales` continua saindo no mesmo contrato esperado pelo POS.
- `report` continua saindo com:
  - `total_revenue`
  - `total_items`
  - `sales_chart`
  - `mix_chart`
- O contexto da IA continua saindo com:
  - `total_revenue`
  - `total_items`
  - `top_products`
  - `stock_levels`
  - `time_filter`
  - `sector`
- A semântica setorial consolidada na Fase 2 foi mantida.

### Validação executada
- `php -l backend/src/Services/SalesReportService.php`
- `php -l backend/src/Controllers/BarController.php`
- `php -l backend/src/Controllers/FoodController.php`
- `php -l backend/src/Controllers/ShopController.php`

## POS Fase 3 — PR 2 — Bloco B: CRUD e catalogo de produtos

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** extração e consolidação do CRUD/catalogo de produtos em `ProductService`, sem tocar em checkout, sync, gráficos, IA ou frontend
- **Arquivos tocados:**
  - `backend/src/Controllers/BarController.php`
  - `backend/src/Controllers/FoodController.php`
  - `backend/src/Controllers/ShopController.php`
  - `backend/src/Services/ProductService.php`
- **Próxima ação sugerida:** executar a PR 3 da Fase 3 do POS, focada em padronização de respostas e afinamento final dos controllers
- **Bloqueios / dependências:** sem bloqueio técnico imediato; a etapa seguinte depende apenas de validar manualmente o CRUD dos 3 setores e os cenários de erro 404/409

### Resumo objetivo do que foi ajustado
- Foi criado `ProductService.php` como fonte comum do catálogo setorial do POS.
- O novo service centraliza:
  - listagem por setor
  - criação por setor
  - atualização por setor
  - exclusão por setor
  - bloqueio de duplicidade por `name + event_id + organizer_id + setor`
  - validação de escopo por `id + organizer_id + setor`
  - bloqueio de exclusão com vendas vinculadas
- `BarController.php`, `FoodController.php` e `ShopController.php` deixaram de carregar inline a lógica pesada de CRUD de produtos.
- Food e Shop foram elevados ao mesmo baseline de segurança do Bar:
  - update fora do escopo falha explicitamente
  - delete fora do escopo falha explicitamente
  - delete com venda vinculada falha com `409`
  - não há mais sucesso silencioso com `0 rows`
- A compatibilidade do frontend foi preservada:
  - payload de listagem continua em `data`
  - criação continua retornando `id`
  - update/delete continuam respondendo sucesso sem exigir mudança no `POS.jsx`

### Hardening consolidado nesta PR
- Baseline de segurança do Bar passou a ser a referência comum.
- `bar` preserva a compatibilidade legada de escopo com `sector = 'bar' OR sector IS NULL`.
- `food` e `shop` passaram a respeitar escopo explícito por organizer e setor.
- O bloqueio por vínculo com vendas foi unificado no service, em vez de ficar disperso ou ausente nos controllers.

### Validação executada
- `php -l backend/src/Services/ProductService.php`
- `php -l backend/src/Controllers/BarController.php`
- `php -l backend/src/Controllers/FoodController.php`
- `php -l backend/src/Controllers/ShopController.php`

## POS Fase 3 — PR 3 — Bloco C + D: Padronizacao de respostas e afinamento final dos controllers

- **Responsável:** Codex
- **Status:** Entregue
- **Escopo:** padronização de respostas HTTP e afinamento final de `BarController.php`, `FoodController.php` e `ShopController.php`, sem tocar em frontend, sync ou semântica de checkout
- **Arquivos tocados:**
  - `backend/src/Controllers/BarController.php`
  - `backend/src/Controllers/FoodController.php`
  - `backend/src/Controllers/ShopController.php`
- **Próxima ação sugerida:** encerrar a Fase 3 com validação operacional cruzada dos três setores e, se aprovado, seguir para o desenho da Fase 4 de refatoração estrutural do `POS.jsx`
- **Bloqueios / dependências:** sem bloqueio técnico imediato; a dependência restante é validar manualmente o contrato dos endpoints de produto, relatório, checkout e insights após a convergência de respostas

### Resumo objetivo do que foi ajustado
- `BarController.php` e `FoodController.php` foram alinhados ao padrão de resposta já usado em `ShopController.php`, reduzindo drift entre `jsonSuccess/jsonError` e `echo json_encode`.
- Os endpoints setoriais passaram a responder sucesso e erro de forma mais previsível, preservando `data`, `message` e `success` no shape já esperado pelo POS.
- Os controllers ficaram mais finos:
  - identificam o setor
  - leem parâmetros e payload
  - delegam para `ProductService`, `SalesReportService` e `SalesDomainService`
  - traduzem o resultado para resposta HTTP consistente
- Helpers locais de `notFound()` que ficaram obsoletos após a consolidação foram removidos de Bar e Food.
- `CheckoutService.php` não foi criado nesta PR, porque o checkout já delega de forma suficientemente fina para `SalesDomainService` e criar um adapter agora só aumentaria superfície sem ganho real de segurança.

### Compatibilidade operacional preservada
- O frontend continua consumindo os mesmos contratos de:
  - listagem de produtos
  - criação/atualização/exclusão
  - relatório setorial
  - checkout
  - contexto de insights
- O pipeline endurecido de checkout da Fase 1 permaneceu intocado.
- A semântica setorial consolidada na Fase 2 permaneceu intocada.

### Validação executada
- `php -l backend/src/Controllers/BarController.php`
- `php -l backend/src/Controllers/FoodController.php`
- `php -l backend/src/Controllers/ShopController.php`

## POS Fase 3 — Fechamento Oficial

- **Responsável:** Codex
- **Status:** Concluída
- **Escopo:** fechamento formal da consolidação backend do Sales Engine no POS
- **Base de fechamento:** consolidação das PRs 1, 2 e 3 da Fase 3 sem reabrir implementação
- **Próxima ação sugerida:** iniciar o registro da Fase 4 em `docs/progresso3.md`, sem executar a fase neste fechamento
- **Bloqueios / dependências:** sem bloqueio técnico novo; permanece apenas a validação operacional final cruzada dos três setores

### Consolidação oficial da fase
- A Fase 3 foi concluída com a extração da leitura analítica setorial para `SalesReportService`.
- A Fase 3 foi concluída com a extração do CRUD e catálogo setorial para `ProductService`.
- `BarController.php`, `FoodController.php` e `ShopController.php` foram mantidos como controllers de transição, porém agora delegando o núcleo repetido para services compartilhados.
- A duplicação estrutural entre Bar, Food e Shop foi reduzida no backend sem romper os contratos já consumidos pelo POS.
- As respostas HTTP dos endpoints setoriais ficaram mais consistentes e previsíveis no shape operacional já esperado pelo frontend.

### Consolidado das 3 PRs entregues
- PR 1 consolidou `SalesReportService` como fonte comum de relatórios setoriais e do contexto setorial de insights.
- PR 2 consolidou `ProductService` como fonte comum de catálogo e CRUD setorial, levando Food e Shop ao mesmo baseline de segurança aplicado no Bar.
- PR 3 consolidou a padronização de respostas e o afinamento final dos controllers, deixando a camada HTTP mais fina, mais homogênea e mais previsível.

### Critérios de aceite da fase
- Relatórios setoriais repetidos deixaram de ficar espalhados inline nos três controllers.
- CRUD e catálogo de produtos deixaram de ficar espalhados inline nos três controllers.
- Os controllers setoriais passaram a atuar principalmente como camada de entrada, delegação e resposta.
- O backend do POS passou a operar com services compartilhados para leitura analítica e catálogo setorial.
- O contrato esperado pelo frontend do POS foi preservado durante a consolidação.

### Estado final consolidado
- A Fase 3 fica oficialmente concluída com o backend do POS consolidado em services compartilhados e controllers afinados.
- `SalesReportService` passa a ser a base comum de relatórios e contexto setorial.
- `ProductService` passa a ser a base comum de catálogo e CRUD setorial.
- `SalesDomainService` permanece como núcleo transacional compartilhado do checkout já endurecido nas fases anteriores.

### Escopo preservado explicitamente
- Sem mexer em dashboard.
- Sem expandir escopo além do POS.
- Sem criar regra nova de negócio.
- Checkout e sync permanecem como endurecimentos já entregues nas fases anteriores, sem redesign nesta fase de consolidação.

### Pendência remanescente de fechamento operacional
- Permanece apenas a validação operacional final cruzada de Bar, Food e Shop.
- Essa validação remanescente cobre conferência manual de produto, relatório, checkout e insights após a convergência das respostas.
- Eventuais drifts observados nesta conferência devem ser tratados primeiro como validação final de convergência, e não como abertura automática de nova frente técnica neste fechamento.

### Transição formal
- O ciclo documental da Fase 3 se encerra neste arquivo `docs/progresso2.md`.
- Os próximos registros referentes à Fase 4 do POS devem passar a ser feitos em `docs/progresso3.md`.

## POS — Fechamento operacional pós-Fase 4

- **Responsável:** Codex
- **Status:** Entregue e congelado
- **Escopo:** registro consolidado dos hotfixes operacionais executados após a estabilização estrutural do POS, sem abrir nova fase de refatoração
- **Diretriz atualizada:** as correções já resolvidas nesta frente permanecem registradas em `docs/progresso2.md`; `docs/progresso3.md` fica reservado apenas para frentes novas abertas hoje

### Correções consolidadas neste fechamento
- O fluxo de Tickets deixou de manter duas fontes de verdade entre filtros da tela e modal de venda rápida.
- A `Venda Rápida` passou a usar diretamente o filtro atual de:
  - evento
  - lote
  - comissário
- A troca de evento no fluxo de Tickets passou a invalidar corretamente lote/comissário incompatíveis com o novo contexto.
- O botão de scanner em Tickets voltou para o lado de `Venda Rápida`.
- O acesso ao scanner a partir de Tickets passou a abrir diretamente o contexto de `portaria`, sem cair na escolha genérica de setores.
- O scanner de validação voltou a aceitar operação rápida com foco estável no input manual.
- O scanner passou a ter botão visível de saída, retornando o operador para `/tickets`.
- O checkout compartilhado voltou a resolver corretamente os services globais de auditoria e carteira.
- A resolução da carteira foi endurecida para aceitar:
  - `digital_cards.id::text`
  - `card_token` quando existir
  - fallback compatível por `user_id` em fluxos legados com `customer_id`
- O loop visual da sincronização offline no frontend foi interrompido com trava de reentrada e sincronização inicial estável.
- O filtro `1h` da timeline setorial passou a usar o relógio do banco, alinhando a série do gráfico com os filtros temporais já usados em cards e listagens.

### Arquivos alcançados neste ciclo operacional
- `frontend/src/pages/Tickets.jsx`
- `frontend/src/pages/Operations/Scanner.jsx`
- `frontend/src/hooks/useNetwork.js`
- `backend/src/Services/SalesDomainService.php`
- `backend/src/Services/WalletSecurityService.php`
- `backend/src/Services/SalesReportService.php`

### Estado operacional consolidado
- Compras voltaram a ser registradas em `bar`, `food` e `shop`.
- Os gráficos setoriais voltaram a ser alimentados pela cadeia real de vendas.
- O replay offline voltou a atravessar a cadeia principal de checkout.
- O scanner da portaria voltou ao fluxo correto de validação.
- O fluxo de Tickets voltou a operar sem modal redundante na `Venda Rápida`.

### Congelamento desta frente
- O sistema tocado nesta sequência fica congelado neste estado como baseline operacional validado.
- A próxima fase deve partir deste baseline, sem reabrir estes hotfixes como refactor amplo.
- Eventual regressão daqui em diante deve ser tratada como bug pontual sobre baseline congelado, e não como reabertura automática da frente anterior.
