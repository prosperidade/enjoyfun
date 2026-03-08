# EnjoyFun — MVPs Oficiais v1

## Objetivo
Definir oficialmente os MVPs iniciais das frentes mais importantes da EnjoyFun, para que a equipe saiba exatamente:
- o que entra primeiro
- o que não entra ainda
- o que gera valor imediato
- o que é fundação para a fase seguinte

Este documento transforma visão e backlog em escopos mínimos viáveis claros.

---

## 1. Regras dos MVPs

1. MVP não é versão incompleta por descuido; é versão mínima por estratégia.
2. Cada MVP deve entregar valor real.
3. Cada MVP deve evitar retrabalho estrutural.
4. Tudo que não for essencial para validação e operação fica para a próxima fase.
5. O MVP precisa ser simples para o usuário e forte na base.

---

## 2. MVP oficial do Dashboard Executivo

## Objetivo
Entregar ao organizador uma visão consolidada e imediata do evento como negócio.

## O que entra
- Receita total do evento
- Receita por setor
- Tickets vendidos
- Créditos em float
- Recargas antecipadas
- Recargas no evento
- Saldo remanescente
- Estacionamento consolidado
- Participantes por categoria

## Estrutura mínima
### Bloco 1 — KPIs principais
- receita total
- tickets vendidos
- float
- participantes presentes

### Bloco 2 — Receita por setor
- bilheteria
- bar
- food
- shop
- estacionamento

### Bloco 3 — Cashless
- recarga antecipada
- recarga no evento
- saldo remanescente

### Bloco 4 — Participantes e presença
- convidados
- artistas
- DJs
- staff
- permutas
- praça de alimentação

### Bloco 5 — Estacionamento
- vendidos antecipados
- vendidos na porta
- dentro agora
- saídas

## O que não entra ainda
- comparativo entre eventos
- comissários
- lotes avançados
- benchmark
- alertas executivos avançados

## Resultado esperado
O organizador consegue bater o olho e entender o evento em menos de um minuto.

---

## 3. MVP oficial do Dashboard Operacional

## Objetivo
Entregar à operação um painel útil durante o evento.

## O que entra
- Timeline de vendas por setor
- Estoque crítico
- Presentes vs ausentes da equipe
- Refeições consumidas
- Carros dentro agora
- Terminais offline

## Estrutura mínima
### Bloco 1 — Situação ao vivo
- receita da última hora
- receita do dia
- vendas por setor

### Bloco 2 — Timeline
- filtro de 1h
- 5h
- 24h
- por setor

### Bloco 3 — Estoque
- produtos abaixo do mínimo
- produtos com maior giro

### Bloco 4 — Workforce
- total previsto
- total presente
- ausentes

### Bloco 5 — Refeições
- previstas
- consumidas
- restantes

### Bloco 6 — Operação técnica
- terminais offline
- sync pendente

## O que não entra ainda
- alertas automáticos inteligentes
- produtividade por operador
- heatmaps avançados
- previsão de ruptura

## Resultado esperado
A coordenação consegue agir rápido e priorizar o que está travando a operação.

---

## 4. MVP oficial do Tenant Settings Hub

## Objetivo
Dar ao organizador um hub simples e poderoso para configurar seu ambiente.

## O que entra
### Branding
- app_name
- logo
- cores
- favicon
- support_email
- support_whatsapp

### Channels
- Resend
- Z-API
- Evolution
- status ativo/inativo
- teste de conexão

### AI Config
- provider
- api key
- modelo
- agentes ativos
- contexto base
- teste de conexão

### Financeiro
- cadastro de gateway
- ativar/desativar
- definir gateway principal
- teste de conexão
- comissão da EnjoyFun visível como regra do tenant

## O que não entra ainda
- automações complexas por canal
- múltiplos fluxos condicionais de agentes
- dashboards financeiros avançados
- conciliação premium

## Resultado esperado
O organizador configura marca, canais, IA e financeiro sem depender de suporte técnico para tudo.

---

## 5. MVP oficial do Financeiro

## Objetivo
Permitir que o organizador conecte seu gateway de pagamento e opere no próprio tenant.

## O que entra
- cadastro de gateway por organizador
- providers prioritários:
  - Mercado Pago
  - PagSeguro
  - Asaas
  - Pagar.me
  - InfinityPay
- status ativo/inativo
- gateway principal
- ambiente
- teste de conexão
- base para comissão da EnjoyFun

## O que não entra ainda
- conciliação detalhada
- split avançado por múltiplos recebedores
- DRE do evento
- fechamento financeiro completo
- relatórios premium de repasse

## Resultado esperado
O organizador consegue operar pagamentos com sua própria conta dentro da estrutura da EnjoyFun.

---

## 6. MVP oficial do Participants Hub

## Objetivo
Preparar a transição de Guest para uma base unificada de participantes.

## O que entra
- categorias iniciais de participantes
- base unificada inicial (`event_participants`)
- manutenção do Guest atual em paralelo
- filtros por categoria
- contagem por categoria

## O que não entra ainda
- migração total de Guests
- regras completas de workforce
- relatórios profundos de presença

## Resultado esperado
A EnjoyFun para de crescer apenas em cima de Guest e ganha a base para o domínio de pessoas do evento.

---

## 7. MVP oficial do Workforce Ops

## Objetivo
Entregar o primeiro núcleo real de operação de equipes.

## O que entra
- importação CSV de staff
- cargo
- setor
- turno
- validade por dia
- QR code
- check-in
- check-out

## O que não entra ainda
- lógica completa de escala inteligente
- produtividade avançada
- cruzamentos analíticos complexos
- regras sofisticadas de exceção

## Resultado esperado
A equipe operacional já consegue ser controlada por turno e presença, sem gambiarra.

---

## 8. MVP oficial do Meals Control

## Objetivo
Entregar controle mínimo viável de alimentação de equipe.

## O que entra
- número de refeições por dia
- vínculo com participante
- consumo por QR
- saldo restante por dia/turno
- visão operacional simples

## O que não entra ainda
- regras avançadas por exceção
- integrações profundas com estoque de alimentação
- relatórios financeiros completos de refeição

## Resultado esperado
A alimentação do staff deixa de ser manual e começa a ser rastreável.

---

## 9. MVP oficial do Sales/POS Engine

## Objetivo
Começar a consolidar o motor de vendas por setor.

## O que entra
- services compartilhados para catálogo
- services compartilhados para checkout
- services compartilhados para relatórios
- setor como parâmetro comum

## O que não entra ainda
- unificação total de todas as rotas
- reescrita completa dos controllers
- engine avançado por terminal/shift

## Resultado esperado
A EnjoyFun começa a sair da duplicação de Bar/Food/Shop sem travar o produto.

---

## 10. Ordem oficial recomendada dos MVPs

### Primeiro
- Tenant Settings Hub MVP
- Financeiro MVP
- Dashboard Executivo MVP

### Segundo
- Dashboard Operacional MVP
- Participants Hub MVP
- Workforce Ops MVP

### Terceiro
- Meals Control MVP
- Sales/POS Engine MVP

---

## 11. O que não pode ser confundido com MVP

1. Não usar MVP como desculpa para deixar segurança fraca.
2. Não usar MVP para misturar domínios errados.
3. Não deixar settings genérico sem separação clara.
4. Não jogar workforce dentro do Guest por pressa.
5. Não lançar dashboard bonito com métrica mal definida.

---

## 12. Resultado esperado

Com estes MVPs oficiais, a EnjoyFun consegue começar a entregar valor real e visível, mantendo coerência com sua visão premium de plataforma white label para organizadores de eventos.

