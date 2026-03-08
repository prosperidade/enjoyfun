# EnjoyFun — Backlog Oficial v1

## Objetivo
Transformar a direção oficial da EnjoyFun em um plano de execução prático, priorizado e conectado com a realidade atual do projeto.

---

## 1. Regras deste backlog

1. P0 = obrigatório agora, porque reduz risco estrutural ou evita retrabalho grande.
2. P1 = próximo ciclo, porque consolida o produto principal.
3. P2 = evolução premium, depois da base estar forte.
4. Nenhuma feature nova deve furar a fila se aumentar acoplamento ou confusão estrutural.
5. Sempre priorizar o que fortalece o modelo white label multi-tenant.

---

## 2. P0 — Prioridade máxima

## 2.1 Segurança e autenticação

### P0.1 — Padronizar JWT e estratégia de autenticação
**Objetivo:** eliminar inconsistência entre a estratégia declarada e a implementada.

**Problema atual:** há desalinhamento entre middleware, helper JWT e expectativa da arquitetura.

**Ações:**
- revisar `AuthMiddleware.php`
- revisar `JWT.php`
- remover fallback hardcoded de segredo
- padronizar a estratégia oficial de assinatura
- garantir payload consistente para auditoria e autorização

**Resultado esperado:** autenticação previsível, segura e coerente.

**Dependência:** nenhuma.

---

### P0.2 — Revisar tenant isolation em rotas críticas
**Objetivo:** garantir que 100% das rotas críticas filtrem por `organizer_id`.

**Ações:**
- revisar listagem, edição, remoção e leitura por ID em:
  - tickets
  - parking
  - guests
  - products
  - sales
  - cards
  - users
- revisar deletes sem filtro por organizer
- revisar updates sem escopo de organizador

**Resultado esperado:** zero vazamento entre tenants.

**Dependência:** P0.1.

---

### P0.3 — Alinhar auditoria
**Objetivo:** garantir trilha correta de usuário, tenant e ação.

**Ações:**
- alinhar payload entre auth e `AuditService`
- garantir `user_id`, `user_email`, `organizer_id` e `event_id` sempre que aplicável
- revisar eventos críticos de checkout, check-in, edição e remoção

**Resultado esperado:** auditoria confiável e útil.

**Dependência:** P0.1.

---

## 2.2 Arquitetura de produto

### P0.4 — Congelar a arquitetura oficial
**Objetivo:** impedir crescimento torto do sistema.

**Ações:**
- oficializar os módulos:
  - Core Event Ops
  - White Label Layer
  - Channels Layer
  - AI Layer
  - Participants Layer
  - Analytics & Control Layer
- oficializar 3 dashboards
- oficializar Participants Hub
- oficializar separação entre Branding, Channels e AI Config

**Resultado esperado:** equipe trabalhando em cima da mesma visão.

**Dependência:** nenhuma.

---

### P0.5 — Parar crescimento errado de Guest
**Objetivo:** evitar misturar Guest com Workforce sem modelagem adequada.

**Ações:**
- definir Guest como submódulo de Participants Hub
- definir Workforce Ops como módulo irmão
- não adicionar lógica de turnos/refeições dentro do Guest atual sem base nova

**Resultado esperado:** menos retrabalho estrutural.

**Dependência:** P0.4.

---

## 2.3 Base de dados e modelagem mínima

### P0.6 — Consolidar schema oficial
**Objetivo:** sair da divergência entre `schema_real.sql` e scripts paralelos.

**Ações:**
- unificar o que já é oficial no banco
- garantir presença e versionamento correto de `guests`
- revisar colunas já existentes em `organizer_settings`
- documentar o schema oficial corrente

**Resultado esperado:** fonte única de verdade do banco.

**Dependência:** nenhuma.

---

### P0.7 — Definir novas tabelas mínimas
**Objetivo:** preparar a próxima fase sem explodir o projeto.

**Criar agora:**
- `organizer_channels`
- `organizer_ai_config`
- `participant_categories`
- `event_days`
- `event_shifts`

**Resultado esperado:** base preparada para channels, IA e eventos multi-dia.

**Dependência:** P0.4 e P0.6.

---

## 2.4 Produto e interface

### P0.8 — Definir dashboards por persona
**Objetivo:** impedir que o dashboard continue genérico.

**Ações:**
- definir escopo do Dashboard Executivo
- definir escopo do Dashboard Operacional
- definir escopo do Dashboard Analítico
- mapear métricas e fontes de dados para cada um

**Resultado esperado:** produto orientado à decisão.

**Dependência:** P0.4.

---

### P0.9 — Definir motor único de métricas
**Objetivo:** evitar KPIs calculados de formas diferentes.

**Ações:**
- definir conceito oficial de:
  - receita bruta
  - receita líquida
  - float
  - saldo remanescente
  - no-show
  - check-in
  - presença
  - consumo staff
- documentar origem e fórmula de cada KPI

**Resultado esperado:** dashboard confiável.

**Dependência:** P0.8.

---

## 3. P1 — Próximo ciclo

## 3.1 White label e configuração por organizador

### P1.1 — Fortalecer Organizer Settings
**Objetivo:** consolidar branding real por organizador.

**Ações:**
- revisar `OrganizerSettingsController`
- separar visualmente no frontend:
  - branding
  - canais
  - IA
- suportar:
  - app_name
  - logo
  - cores
  - favicon
  - suporte

**Resultado esperado:** base white label sólida.

**Dependência:** P0.4.

---

### P1.2 — Implementar Organizer Channels
**Objetivo:** suportar canais por tenant.

**Ações:**
- CRUD de canais por organizador
- suporte inicial para:
  - Resend
  - Z-API
  - Evolution
- teste de conexão
- status ativo/inativo
- armazenamento seguro de credenciais

**Resultado esperado:** comunicação própria por organizador.

**Dependência:** P0.7.

---

### P1.3 — Implementar Organizer AI Config
**Objetivo:** suportar IA por tenant.

**Ações:**
- CRUD de config de IA
- provider
- api key
- modelo
- agentes ativos
- contexto do organizador e do evento
- teste de conexão

**Resultado esperado:** IA própria por organizador.

**Dependência:** P0.7.

---

## 3.2 Dashboards

### P1.4 — Entregar Dashboard Executivo v1
**Objetivo:** entregar visão gerencial real.

**Widgets mínimos:**
- receita total
- receita por setor
- tickets vendidos
- float
- recargas
- saldo remanescente
- estacionamento
- totais de participantes por categoria

**Dependência:** P0.8 e P0.9.

---

### P1.5 — Entregar Dashboard Operacional v1
**Objetivo:** entregar visão de evento acontecendo.

**Widgets mínimos:**
- timeline por setor
- estoque crítico
- terminais offline
- presentes/ausentes
- refeições consumidas
- carros dentro/fora
- alertas operacionais

**Dependência:** P0.8 e P0.9.

---

## 3.3 Participants Hub

### P1.6 — Criar estrutura inicial do Participants Hub
**Objetivo:** preparar base unificada sem quebrar Guest atual.

**Ações:**
- definir camada de participantes por categoria
- manter Guest funcionando como módulo legado temporário
- iniciar nova base para categories e evento multi-dia

**Dependência:** P0.5 e P0.7.

---

### P1.7 — Entregar Workforce Ops v1
**Objetivo:** iniciar controle real de equipes.

**Escopo mínimo:**
- importação CSV de staff
- cargo
- setor
- turno
- validade por dia
- QR code
- entrada e saída

**Dependência:** P1.6.

---

### P1.8 — Entregar Meals Control v1
**Objetivo:** controlar alimentação de staff.

**Escopo mínimo:**
- número de refeições por dia
- consumo por QR
- saldo restante por dia/turno
- painel operacional de refeições

**Dependência:** P1.7.

---

## 3.4 Operação de vendas

### P1.9 — Refatorar Bar/Food/Shop para base comum
**Objetivo:** reduzir duplicação sem travar o produto.

**Ações:**
- mapear lógica comum entre controllers
- extrair para services compartilhados
- padronizar contrato dos endpoints
- manter rotas compatíveis na transição

**Resultado esperado:** menos retrabalho e menos divergência.

**Dependência:** P0.2 e P0.3.

---

### P1.10 — Iniciar Sales/POS Engine
**Objetivo:** transformar setor em parâmetro, não em controller duplicado.

**Escopo:**
- catálogo por setor
- checkout por setor
- relatórios por setor
- estoque por setor

**Dependência:** P1.9.

---

## 4. P2 — Evolução premium

## 4.1 Analytics avançado

### P2.1 — Dashboard Analítico v1
**Objetivo:** pós-evento orientado à melhoria.

**Escopo:**
- lote
- comissário
- curva de vendas
- mix de produtos
- ticket médio
- produtividade por operador
- comparativo entre eventos

**Dependência:** P1.4, P1.5 e evolução de modelagem.

---

### P2.2 — Dashboard Snapshots
**Objetivo:** performance e histórico confiável.

**Escopo:**
- materialização de KPIs
- histórico por evento/dia/turno
- queries rápidas para dashboards

**Dependência:** P2.1.

---

## 4.2 Inteligência e automação

### P2.3 — Alertas operacionais
**Objetivo:** criar sistema de atenção automática.

**Escopo:**
- estoque crítico
- terminal offline
- queda abrupta de vendas
- fila acima do normal
- staff ausente
- refeição excedida

**Dependência:** P1.5.

---

### P2.4 — Agentes operacionais
**Objetivo:** usar IA para apoiar gestão em tempo real.

**Escopo:**
- recomendação de ação
- resumo de operação
- leitura de KPIs
- resposta por WhatsApp/painel

**Dependência:** P1.3 e P2.3.

---

### P2.5 — Benchmark e inteligência comparativa
**Objetivo:** transformar a plataforma em referência para organizadores.

**Escopo:**
- comparação entre eventos
- comparação entre edições
- insights de performance
- padrões de consumo

**Dependência:** P2.1 e P2.2.

---

## 5. Ordem oficial de execução

### Etapa 1 — Fundação
- P0.1
- P0.2
- P0.3
- P0.4
- P0.5
- P0.6
- P0.7
- P0.8
- P0.9

### Etapa 2 — Núcleo do novo produto
- P1.1
- P1.2
- P1.3
- P1.4
- P1.5
- P1.6
- P1.7
- P1.8

### Etapa 3 — Consolidação operacional
- P1.9
- P1.10

### Etapa 4 — Premiumização
- P2.1
- P2.2
- P2.3
- P2.4
- P2.5

---

## 6. O que não fazer agora

- não criar telas novas soltas sem arquitetura definida
- não colocar workforce inteiro dentro do Guest atual
- não escalar IA sem separar config por organizador
- não criar dashboard analítico antes de métricas padronizadas
- não seguir duplicando lógica de setores no backend

---

## 7. Resultado esperado deste backlog

Ao seguir este backlog, a EnjoyFun sai de uma base funcional promissora e vira uma plataforma sólida, organizada e pronta para crescer como produto white label premium para organizadores de eventos.

