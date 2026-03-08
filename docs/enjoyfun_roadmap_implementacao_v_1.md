# EnjoyFun — Roadmap de Implementação v1

## Objetivo
Transformar a direção oficial e o backlog da EnjoyFun em uma agenda real de execução, incluindo a nova frente financeira: configuração de gateways de pagamento por organizador, com operação direta no ambiente do tenant e comissão da EnjoyFun embutida.

---

## 1. Novo pilar oficial do produto: Financial Layer

A EnjoyFun passa a ter oficialmente uma nova camada do produto:

### Financial Layer
Responsável por:
- configuração de gateways de pagamento por organizador
- operação financeira direta do tenant
- split/comissão da EnjoyFun
- billing da plataforma
- conciliação e trilha financeira
- base para cobrança de ingressos, estacionamento, recargas e outros produtos

### Gateways prioritários
- Mercado Pago
- PagSeguro
- Asaas
- Pagar.me
- InfinityPay

### Regra oficial
O organizador deve ter uma interface simples e direta para conectar seu gateway, inserindo:
- credenciais
- tokens
- chaves
- ambiente (sandbox/produção, quando aplicável)
- dados da conta

A operação deve rodar diretamente com as credenciais do organizador, mantendo a comissão da EnjoyFun embutida no fluxo.

---

## 2. Visão de implementação por frentes

O roadmap passa a ser dividido em 7 frentes principais:

1. Segurança e base técnica
2. White label
3. Channels
4. AI Config
5. Financial Layer
6. Participants + Workforce
7. Dashboards + Analytics

---

## 3. Fase 1 — Fundação e fortalecimento da base

### Objetivo
Remover riscos críticos, congelar arquitetura e preparar o terreno para os módulos estruturantes.

### Entregas

#### 3.1 Autenticação e segurança
- padronizar JWT
- remover fallback de segredo frágil
- alinhar middleware, helper e auditoria

#### 3.2 Multi-tenant
- revisar organizer_id em rotas críticas
- blindar CRUDs sensíveis
- padronizar escopo por tenant

#### 3.3 Arquitetura oficial
- oficializar as camadas do produto
- oficializar Participants Hub
- oficializar os 3 dashboards
- oficializar Financial Layer

#### 3.4 Schema oficial
- consolidar schema principal
- incluir o que hoje está paralelo
- preparar novas tabelas mínimas

#### 3.5 Métricas oficiais
- definir KPIs-base
- documentar fórmulas
- mapear origem dos dados

### Resultado esperado
Base segura, clara e pronta para evolução sem retrabalho estrutural.

---

## 4. Fase 2 — Estrutura do tenant (branding, canais, IA e financeiro)

### Objetivo
Entregar o ambiente configurável real do organizador.

### Entregas

## 4.1 Branding Settings v1
- app_name
- logo
- cores
- favicon
- suporte
- identidade visual consolidada no frontend

## 4.2 Organizer Channels v1
- configuração de Resend
- configuração de Z-API
- configuração de Evolution
- teste de conexão
- status ativo/inativo
- armazenamento seguro das credenciais

## 4.3 Organizer AI Config v1
- provider de IA
- api key própria
- modelo
- agentes ativos
- contexto do organizador
- contexto do evento
- teste de conexão

## 4.4 Financial Layer v1

### Escopo
Criar a interface financeira do organizador para configurar gateways de pagamento.

### Funcionalidades mínimas
- tela de configuração financeira dentro do tenant
- cadastro de gateway
- inserção de tokens/credenciais
- ativação/desativação
- teste de conexão
- escolha do gateway principal
- registro de ambiente (quando aplicável)

### Gateways prioritários
- Mercado Pago
- PagSeguro
- Asaas
- Pagar.me
- InfinityPay

### Regras de produto
- operação com a conta do organizador
- comissão da EnjoyFun embutida no fluxo
- futura conciliação por gateway
- futura visualização de taxas e repasses

### Dados necessários
Nova modelagem mínima:

#### organizer_payment_gateways
- id
- organizer_id
- provider
- credentials_encrypted
- config_json
- is_active
- is_primary
- environment
- created_at
- updated_at

#### organizer_financial_settings
- organizer_id
- commission_rate
- settlement_preferences
- payout_info
- created_at
- updated_at

### Resultado esperado
Cada organizador consegue configurar e operar seus pagamentos com seu próprio gateway, dentro da estrutura white label da plataforma.

---

## 5. Fase 3 — Operação central do evento

### Objetivo
Melhorar a operação real do evento com base no novo desenho do produto.

### Entregas

## 5.1 Dashboard Executivo v1
- receita total
- receita por setor
- tickets vendidos
- float
- recargas
- saldo remanescente
- estacionamento
- totais por categoria

## 5.2 Dashboard Operacional v1
- timeline por setor
- estoque crítico
- presentes/ausentes
- refeições
- terminais offline
- alertas operacionais

## 5.3 Participants Hub v1
- base inicial unificada
- categorias de participante
- transição controlada do Guest atual

## 5.4 Workforce Ops v1
- importação CSV de staff
- cargo
- setor
- turno
- validade por dia
- QR code
- entrada e saída

## 5.5 Meals Control v1
- refeições por dia/turno
- consumo por QR
- saldo restante

### Resultado esperado
A operação deixa de ser só cadastro e passa a ter controle real de participantes, equipes e execução.

---

## 6. Fase 4 — Consolidação do motor de vendas

### Objetivo
Reduzir duplicação e fortalecer o núcleo transacional.

### Entregas

## 6.1 Refatoração Bar/Food/Shop
- extrair regras comuns para services
- padronizar contratos de API
- manter compatibilidade de rotas

## 6.2 Sales/POS Engine v1
- setor como parâmetro
- catálogo por setor
- checkout por setor
- estoque por setor
- relatórios por setor

## 6.3 Financeiro operacional v2
- amarração dos gateways com:
  - tickets
  - estacionamento
  - recargas
  - futuras compras web/PWA
- registro de transações por provider
- base para conciliação

### Resultado esperado
Motor transacional mais robusto, coerente e pronto para crescer com menos retrabalho.

---

## 7. Fase 5 — Premiumização

### Objetivo
Transformar a EnjoyFun em plataforma premium de inteligência operacional.

### Entregas

## 7.1 Dashboard Analítico v1
- lote
- comissário
- curva de vendas
- mix de produtos
- ticket médio
- saldo remanescente
- produtividade por operador
- comparação entre eventos

## 7.2 Dashboard Snapshots
- materialização de KPIs
- histórico por evento/dia/turno
- performance estável

## 7.3 Alertas operacionais
- estoque crítico
- terminal offline
- queda abrupta de vendas
- staff ausente
- fila fora do normal

## 7.4 Agentes operacionais
- copiloto do organizador
- resumo executivo automático
- recomendações de ação
- leitura de dados operacionais

## 7.5 Financeiro premium
- conciliação por gateway
- taxa/comissão detalhada
- visão de repasse
- fechamento financeiro do evento
- DRE simplificada por evento

### Resultado esperado
A EnjoyFun deixa de ser só uma operação bem feita e vira uma plataforma de comando e inteligência.

---

## 8. Dependências principais

### Segurança e base
Tudo depende de:
- autenticação correta
- multi-tenant consistente
- schema oficial consolidado

### Tenant config
Channels, AI Config e Financial Layer dependem de:
- modelagem por organizador
- armazenamento seguro de credenciais
- tela de settings segmentada

### Dashboards
Dependem de:
- KPIs definidos
- fontes de dados estáveis
- modelagem mínima ajustada

### Participants e Workforce
Dependem de:
- categorias e estrutura inicial de participantes
- evento multi-dia
- turnos

### Financeiro avançado
Depende de:
- gateways básicos conectados
- transações vinculadas a providers
- estrutura de conciliação

---

## 9. Ordem oficial recomendada

### Etapa 1
- segurança
- auth
- tenant isolation
- auditoria
- arquitetura oficial
- schema oficial
- KPIs oficiais

### Etapa 2
- branding
- channels
- AI config
- financial layer v1

### Etapa 3
- dashboard executivo
- dashboard operacional
- participants hub
- workforce ops
- meals control

### Etapa 4
- sales engine
- refatoração dos setores
- integração financeira operacional

### Etapa 5
- dashboard analítico
- snapshots
- alertas
- agentes operacionais
- financeiro premium

---

## 10. O que não pode ficar de fora a partir de agora

1. Interface financeira do organizador.
2. Gateways por tenant com comissão da EnjoyFun embutida.
3. Separação entre Branding, Channels, AI Config e Financeiro.
4. Planejamento multi-dia e workforce.
5. Dashboards separados por persona.

---

## 11. Resultado esperado do roadmap

Ao seguir este roadmap, a EnjoyFun passa a ter:
- base segura e escalável
- tenant realmente configurável
- canais e IA por organizador
- gateways financeiros por organizador
- operação forte de evento
- dashboards por decisão
- caminho claro para se tornar uma plataforma premium white label de eventos.

